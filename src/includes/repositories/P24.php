<?php
/**
 * Przelewy24 REST v1 client.
 *
 * The provider uses HTTP Basic authentication (POS id + API key) and SHA-384 signatures over
 * JSON objects whose key order is part of the protocol. All public methods accept/return the
 * provider-neutral shapes used by the existing payment ledger.
 */
final class P24
{
	private const HOST_SANDBOX = 'https://sandbox.przelewy24.pl';
	private const HOST_LIVE = 'https://secure.przelewy24.pl';
	private const CONNECT_TIMEOUT = 5;
	private const TIMEOUT = 15;

	/** Test-only transport seam. Production leaves this null. */
	private static $transport = null;

	public static function setTransport(?callable $transport): void
	{
		self::$transport = $transport;
	}

	public static function config(): array
	{
		$v = PaymentPlugins::values('przelewy24', true);
		$merchantId = trim((string) ($v['merchant_id'] ?? ''));
		$posId = trim((string) ($v['pos_id'] ?? '')) ?: $merchantId;
		return [
			'merchant_id' => $merchantId,
			'pos_id' => $posId,
			'crc' => trim((string) ($v['crc'] ?? '')),
			'api_key' => trim((string) ($v['api_key'] ?? '')),
			'currency' => strtoupper(trim((string) ($v['currency'] ?? ''))) ?: 'PLN',
			'sandbox' => strtolower(trim((string) ($v['environment'] ?? 'sandbox'))) !== 'production',
		];
	}

	public static function isConfigured(): bool
	{
		$c = self::config();
		return ctype_digit($c['merchant_id'])
			&& ctype_digit($c['pos_id'])
			&& $c['crc'] !== ''
			&& $c['api_key'] !== '';
	}

	public static function testAccess(): bool
	{
		$res = self::request('/api/v1/testAccess', 'GET');
		$data = json_decode((string) $res['body'], true) ?: [];
		return ($res['status'] ?? 0) === 200
			&& (!array_key_exists('responseCode', $data) || (int) $data['responseCode'] === 0)
			&& ($data['data'] ?? false) === true;
	}

	/** @return array{success:bool,redirectUri?:string,token?:string,error?:string} */
	public static function createOrder(array $order): array
	{
		if (!self::isConfigured()) {
			return ['success' => false, 'error' => 'unconfigured'];
		}
		$c = self::config();
		$amount = (int) ($order['amount_minor'] ?? 0);
		$currency = strtoupper((string) ($order['currency'] ?? $c['currency']));
		$sessionId = (string) ($order['ext_order_id'] ?? '');
		if ($amount <= 0 || $sessionId === '' || !preg_match('/^[A-Z]{3}$/', $currency)) {
			return ['success' => false, 'error' => 'invalid_order'];
		}

		$payload = [
			'merchantId' => (int) $c['merchant_id'],
			'posId' => (int) $c['pos_id'],
			'sessionId' => mb_substr($sessionId, 0, 100),
			'amount' => $amount,
			'currency' => $currency,
			'description' => mb_substr((string) ($order['description'] ?? ''), 0, 1024),
			'email' => mb_substr((string) ($order['buyer_email'] ?? ''), 0, 50),
			'client' => mb_substr((string) ($order['buyer_name'] ?? ''), 0, 40),
			'country' => self::country((string) ($order['country'] ?? 'PL')),
			'language' => self::language((string) ($order['language'] ?? 'pl')),
			'urlReturn' => mb_substr((string) ($order['continue_url'] ?? ''), 0, 250),
			'urlStatus' => mb_substr((string) ($order['notify_url'] ?? ''), 0, 250),
			// The published schema calls this field timeLimit (0 means no limit). Its
			// `required` list currently contains the obsolete name `ttl`, so follow the
			// documented request samples and field definition rather than that schema typo.
			'timeLimit' => 0,
		];
		$payload['sign'] = self::checksum([
			'sessionId' => $payload['sessionId'],
			'merchantId' => $payload['merchantId'],
			'amount' => $payload['amount'],
			'currency' => $payload['currency'],
			'crc' => $c['crc'],
		]);

		$res = self::request('/api/v1/transaction/register', 'POST', $payload);
		$data = json_decode((string) $res['body'], true) ?: [];
		$token = trim((string) ($data['data']['token'] ?? ''));
		if (($res['status'] ?? 0) === 200 && (int) ($data['responseCode'] ?? -1) === 0 && $token !== '') {
			return [
				'success' => true,
				'token' => $token,
				'redirectUri' => self::host() . '/trnRequest/' . rawurlencode($token),
			];
		}
		self::log('register_failed', self::errorDetail($res, $data));
		return ['success' => false, 'error' => self::errorCode($res, $data)];
	}

	/** Validate the signed transaction result sent to urlStatus. */
	public static function verifyNotification(array $body): bool
	{
		$c = self::config();
		if (!self::isConfigured() || trim((string) ($body['sign'] ?? '')) === '') {
			return false;
		}
		if ((int) ($body['merchantId'] ?? 0) !== (int) $c['merchant_id']
			|| (int) ($body['posId'] ?? 0) !== (int) $c['pos_id']) {
			return false;
		}
		$expected = self::checksum([
			'merchantId' => (int) ($body['merchantId'] ?? 0),
			'posId' => (int) ($body['posId'] ?? 0),
			'sessionId' => (string) ($body['sessionId'] ?? ''),
			'amount' => (int) ($body['amount'] ?? 0),
			'originAmount' => (int) ($body['originAmount'] ?? 0),
			'currency' => (string) ($body['currency'] ?? ''),
			'orderId' => (int) ($body['orderId'] ?? 0),
			'methodId' => (int) ($body['methodId'] ?? 0),
			'statement' => (string) ($body['statement'] ?? ''),
			'crc' => $c['crc'],
		]);
		return hash_equals($expected, strtolower((string) $body['sign']));
	}

	/** Mandatory merchant verification performed after a valid notification or paid poll. */
	public static function verifyTransaction(
		string $sessionId,
		int $orderId,
		int $amountMinor,
		string $currency
	): bool {
		$c = self::config();
		if (!self::isConfigured() || $sessionId === '' || $orderId < 1 || $amountMinor < 1) {
			return false;
		}
		$currency = strtoupper($currency);
		$payload = [
			'merchantId' => (int) $c['merchant_id'],
			'posId' => (int) $c['pos_id'],
			'sessionId' => $sessionId,
			'amount' => $amountMinor,
			'currency' => $currency,
			'orderId' => $orderId,
		];
		$payload['sign'] = self::checksum([
			'sessionId' => $sessionId,
			'orderId' => $orderId,
			'amount' => $amountMinor,
			'currency' => $currency,
			'crc' => $c['crc'],
		]);
		$res = self::request('/api/v1/transaction/verify', 'PUT', $payload);
		$data = json_decode((string) $res['body'], true) ?: [];
		$ok = ($res['status'] ?? 0) === 200
			&& (int) ($data['responseCode'] ?? -1) === 0
			&& strtolower((string) ($data['data']['status'] ?? '')) === 'success';
		if (!$ok) {
			self::log('verify_failed', $sessionId . ': ' . self::errorDetail($res, $data));
		}
		return $ok;
	}

	/** @return array|null Provider status shape normalized for PaymentReconciler. */
	public static function orderStatus(string $sessionId): ?array
	{
		if (!self::isConfigured() || $sessionId === '') {
			return null;
		}
		$res = self::request('/api/v1/transaction/by/sessionId/' . rawurlencode($sessionId), 'GET');
		$data = json_decode((string) $res['body'], true) ?: [];
		$order = $data['data'] ?? null;
		if (($res['status'] ?? 0) !== 200 || (int) ($data['responseCode'] ?? -1) !== 0 || !is_array($order)) {
			self::log('status_failed', $sessionId . ': ' . self::errorDetail($res, $data));
			return null;
		}
		return [
			'status' => (int) ($order['status'] ?? 0),
			'orderId' => (int) ($order['orderId'] ?? 0),
			'sessionId' => (string) ($order['sessionId'] ?? $sessionId),
			'amount' => (int) ($order['amount'] ?? 0),
			'currency' => strtoupper((string) ($order['currency'] ?? '')),
		];
	}

	/**
	 * Request a refund. A successful response only means "accepted"; the signed callback is
	 * authoritative and is what moves the ledger to REFUNDED.
	 *
	 * @return array{success:bool,requestId?:string,refundsUuid?:string,status?:string,error?:string}
	 */
	public static function refund(
		int $orderId,
		string $sessionId,
		int $amountMinor,
		string $description,
		string $urlStatus,
		?string $requestId = null,
		?string $refundsUuid = null
	): array {
		if (!self::isConfigured() || $orderId < 1 || $sessionId === '' || $amountMinor < 1) {
			return ['success' => false, 'error' => 'invalid_refund'];
		}
		$requestId = $requestId ?: bin2hex(random_bytes(16));
		$refundsUuid = $refundsUuid ?: bin2hex(random_bytes(16));
		$payload = [
			'requestId' => $requestId,
			'refunds' => [[
				'orderId' => $orderId,
				'sessionId' => $sessionId,
				'amount' => $amountMinor,
				'description' => mb_substr($description !== '' ? $description : 'Refund', 0, 35),
			]],
			'refundsUuid' => $refundsUuid,
			'urlStatus' => mb_substr($urlStatus, 0, 250),
		];
		$res = self::request('/api/v1/transaction/refund', 'POST', $payload);
		$data = json_decode((string) $res['body'], true) ?: [];
		$item = is_array($data['data'][0] ?? null) ? $data['data'][0] : [];
		if (($res['status'] ?? 0) === 201
			&& (int) ($data['responseCode'] ?? -1) === 0
			&& ($item['status'] ?? false) === true) {
			return [
				'success' => true,
				'requestId' => $requestId,
				'refundsUuid' => $refundsUuid,
				'status' => 'accepted',
			];
		}
		self::log('refund_failed', $sessionId . ': ' . self::errorDetail($res, $data));
		return ['success' => false, 'error' => self::errorCode($res, $data)];
	}

	public static function verifyRefundNotification(array $body): bool
	{
		$c = self::config();
		if (!self::isConfigured() || trim((string) ($body['sign'] ?? '')) === '') {
			return false;
		}
		if ((int) ($body['merchantId'] ?? 0) !== (int) $c['merchant_id']) {
			return false;
		}
		$expected = self::checksum([
			'orderId' => (int) ($body['orderId'] ?? 0),
			'sessionId' => (string) ($body['sessionId'] ?? ''),
			'refundsUuid' => (string) ($body['refundsUuid'] ?? ''),
			'merchantId' => (int) ($body['merchantId'] ?? 0),
			'amount' => (int) ($body['amount'] ?? 0),
			'currency' => (string) ($body['currency'] ?? ''),
			'status' => (int) ($body['status'] ?? -1),
			'crc' => $c['crc'],
		]);
		return hash_equals($expected, strtolower((string) $body['sign']));
	}

	/** Stable fingerprint over exactly the signed transaction-notification fields. */
	public static function notificationEventId(array $body): string
	{
		$fields = [
			'merchantId' => (int) ($body['merchantId'] ?? 0),
			'posId' => (int) ($body['posId'] ?? 0),
			'sessionId' => (string) ($body['sessionId'] ?? ''),
			'amount' => (int) ($body['amount'] ?? 0),
			'originAmount' => (int) ($body['originAmount'] ?? 0),
			'currency' => (string) ($body['currency'] ?? ''),
			'orderId' => (int) ($body['orderId'] ?? 0),
			'methodId' => (int) ($body['methodId'] ?? 0),
			'statement' => (string) ($body['statement'] ?? ''),
			'sign' => (string) ($body['sign'] ?? ''),
		];
		return hash('sha256', self::json($fields));
	}

	public static function refundEventId(array $body): string
	{
		return hash('sha256', self::json([
			'orderId' => (int) ($body['orderId'] ?? 0),
			'sessionId' => (string) ($body['sessionId'] ?? ''),
			'refundsUuid' => (string) ($body['refundsUuid'] ?? ''),
			'merchantId' => (int) ($body['merchantId'] ?? 0),
			'amount' => (int) ($body['amount'] ?? 0),
			'currency' => (string) ($body['currency'] ?? ''),
			'status' => (int) ($body['status'] ?? -1),
			'sign' => (string) ($body['sign'] ?? ''),
		]));
	}

	private static function language(string $language): string
	{
		$language = strtolower(substr($language, 0, 2));
		return in_array($language, ['bg', 'cs', 'de', 'en', 'es', 'fr', 'hr', 'hu', 'it', 'nl', 'pl', 'pt', 'se', 'sk', 'ro'], true)
			? $language : 'en';
	}

	private static function country(string $country): string
	{
		$country = strtoupper(trim($country));
		return preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : 'PL';
	}

	private static function checksum(array $orderedFields): string
	{
		return hash('sha384', self::json($orderedFields));
	}

	private static function json(array $value): string
	{
		$json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		return $json === false ? '' : $json;
	}

	private static function host(): string
	{
		return self::config()['sandbox'] ? self::HOST_SANDBOX : self::HOST_LIVE;
	}

	private static function request(string $path, string $method, ?array $payload = null): array
	{
		$c = self::config();
		$url = self::host() . $path;
		$body = $payload === null ? '' : self::json($payload);
		$headers = [
			'Accept: application/json',
			'Authorization: Basic ' . base64_encode($c['pos_id'] . ':' . $c['api_key']),
		];
		if ($payload !== null) {
			$headers[] = 'Content-Type: application/json';
		}
		if (self::$transport !== null) {
			$result = call_user_func(self::$transport, $url, $method, $body, $headers);
			return is_array($result) ? $result : ['status' => 0, 'body' => ''];
		}
		if (function_exists('curl_init')) {
			$ch = curl_init($url);
			curl_setopt_array($ch, [
				CURLOPT_CUSTOMREQUEST => $method,
				CURLOPT_POSTFIELDS => $payload === null ? null : $body,
				CURLOPT_HTTPHEADER => $headers,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
				CURLOPT_TIMEOUT => self::TIMEOUT,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
			]);
			$out = curl_exec($ch);
			$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
			if ($out === false) {
				self::log('transport_error', curl_error($ch));
				$out = '';
			}
			return ['status' => $status, 'body' => (string) $out];
		}
		$ctx = stream_context_create(['http' => [
			'method' => $method,
			'header' => implode("\r\n", $headers),
			'content' => $body,
			'timeout' => self::TIMEOUT,
			'follow_location' => 0,
			'ignore_errors' => true,
		]]);
		// Load-bearing, do not delete — see the note in PayU::request(). Declaring this before
		// the call is what keeps PHP 8.5 from deprecating the implicit variable at compile time.
		$http_response_header = [];
		$out = @file_get_contents($url, false, $ctx);
		$status = 0;
		foreach ($http_response_header as $line) {
			if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
				$status = (int) $m[1];
			}
		}
		return ['status' => $status, 'body' => (string) $out];
	}

	private static function errorCode(array $response, array $data): string
	{
		$error = $data['error'] ?? $data['data']['error'] ?? null;
		if (is_array($error)) {
			$error = $error[0]['message'] ?? 'provider_error';
		}
		$error = trim((string) $error);
		return $error !== '' ? mb_substr($error, 0, 120) : 'http_' . (int) ($response['status'] ?? 0);
	}

	private static function errorDetail(array $response, array $data): string
	{
		return 'HTTP ' . (int) ($response['status'] ?? 0) . ' ' . self::errorCode($response, $data);
	}

	private static function log(string $event, string $detail): void
	{
		Database::logAudit('p24_' . $event, mb_substr($detail, 0, 500));
	}
}
