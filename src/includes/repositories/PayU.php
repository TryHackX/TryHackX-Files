<?php
/**
 * PayU REST client (pt 10) — the first *real* payment integration in this project.
 *
 * Everything else under premium is provider-agnostic on purpose: a plan carries a link or a
 * block of HTML, and fulfilment happens through the token-authenticated `premium_activate`
 * endpoint. That works for a Stripe Payment Link or a PayPal.me address, and it does not work
 * for PayU, because PayU has no link to paste: the shop must register the order server-side
 * (OAuth + signed JSON) and only then send the buyer to the URL that call hands back.
 *
 * So this class is the missing half, and only that half:
 *   - `accessToken()`  — OAuth client_credentials, cached for the life of the token;
 *   - `createOrder()`  — POST /api/v2_1/orders, returning where to send the buyer;
 *   - `verifyNotification()` — the `OpenPayu-Signature` check on the callback.
 *
 * It does not decide anything about who gets what: `PaymentController` maps a verified,
 * completed notification back to a row in `payments` and calls `PlanRepository::grant()`, the
 * same fulfilment path the admin's "grant by hand" button uses.
 *
 * Amounts are integers in the currency's minor unit (grosze, stotinki, cents) — PayU's own
 * convention, and the only safe one. No float ever touches a price here.
 *
 * Sandbox: https://developers.payu.com/europe/pl/docs/testing/sandbox/ — the public test POSs
 * are per-currency (300746 is PLN, 404815 is BGN, 491057 is RON), so the plan's currency has
 * to match the POS or PayU rejects the order.
 */
final class PayU
{
	private const HOST_SANDBOX = 'https://secure.snd.payu.com';
	private const HOST_LIVE    = 'https://secure.payu.com';

	/** Transport timeouts: a checkout the buyer is waiting on must fail fast, not hang. */
	private const CONNECT_TIMEOUT = 5;
	private const TIMEOUT = 15;

	/** Credentials as configured in Ustawienia → Premium → wtyczki płatności. */
	public static function config(): array
	{
		$v = PaymentPlugins::values('payu', true);
		$posId = trim((string) ($v['pos_id'] ?? ''));
		return [
			'pos_id' => $posId,
			// The OAuth client_id is the POS id for a standard POS; kept as its own field
			// because PayU does issue separate ones (OAuth "private" clients), and because
			// leaving it implicit is how the two get pasted into each other's boxes.
			'client_id' => trim((string) ($v['client_id'] ?? '')) ?: $posId,
			'client_secret' => trim((string) ($v['client_secret'] ?? '')),
			'md5' => trim((string) ($v['md5'] ?? '')),
			'currency' => strtoupper(trim((string) ($v['currency'] ?? ''))) ?: 'PLN',
			'sandbox' => strtolower(trim((string) ($v['environment'] ?? 'sandbox'))) !== 'production',
		];
	}

	public static function isConfigured(): bool
	{
		$c = self::config();
		return $c['pos_id'] !== '' && $c['client_secret'] !== '' && $c['md5'] !== '';
	}

	private static function host(): string
	{
		return self::config()['sandbox'] ? self::HOST_SANDBOX : self::HOST_LIVE;
	}

	/**
	 * An OAuth access token, cached in the settings table until shortly before it expires.
	 *
	 * PayU's tokens last ~12 hours and the endpoint is rate-limited, so fetching one per
	 * checkout would be both slow and rude. The cache is keyed to nothing but the credentials
	 * in use; changing them writes new values, and the stale token simply stops verifying.
	 */
	public static function accessToken(bool $force = false): ?string
	{
		$c = self::config();
		$configHash = hash('sha256', json_encode([
			$c['pos_id'],
			$c['client_id'],
			$c['client_secret'],
			$c['sandbox'],
		], JSON_UNESCAPED_SLASHES));
		$cached = (string) Database::getSecretSetting('payu_access_token', '');
		$expires = (int) Database::getSetting('payu_token_expires', 0);
		$cachedHash = (string) Database::getSetting('payu_token_config_hash', '');
		if (!$force && $cached !== '' && hash_equals($configHash, $cachedHash)
			&& $expires > time() + 60) {
			return $cached;
		}

		if ($c['client_id'] === '' || $c['client_secret'] === '') {
			return null;
		}

		$res = self::request(
			self::host() . '/pl/standard/user/oauth/authorize',
			'POST',
			http_build_query([
				'grant_type' => 'client_credentials',
				'client_id' => $c['client_id'],
				'client_secret' => $c['client_secret'],
			]),
			['Content-Type: application/x-www-form-urlencoded']
		);

		$data = json_decode((string) $res['body'], true);
		if (($res['status'] ?? 0) !== 200 || empty($data['access_token'])) {
			self::log('oauth_failed', 'HTTP ' . ($res['status'] ?? 0) . ' ' . substr((string) $res['body'], 0, 200));
			return null;
		}

		$token = (string) $data['access_token'];
		Database::setSecretSetting('payu_access_token', $token);
		Database::setSetting('payu_token_expires', time() + max(60, (int) ($data['expires_in'] ?? 3600)));
		Database::setSetting('payu_token_config_hash', $configHash);
		return $token;
	}

	/**
	 * Register an order and get the URL to send the buyer to.
	 *
	 * `extOrderId` is our own `payments.ext_order_id` — it is what the notification carries
	 * back, and the only thing that lets us tie a payment to a plan and a person. PayU treats
	 * it as unique per POS, so it is generated fresh per attempt.
	 *
	 * @return array{success: bool, redirectUri?: string, orderId?: string, error?: string}
	 */
	public static function createOrder(array $order): array
	{
		$token = self::accessToken();
		if ($token === null) {
			return ['success' => false, 'error' => 'oauth'];
		}
		$c = self::config();

		$amount = (int) $order['amount_minor'];
		$payload = [
			'notifyUrl' => (string) $order['notify_url'],
			'continueUrl' => (string) $order['continue_url'],
			'customerIp' => (string) $order['customer_ip'],
			'merchantPosId' => $c['pos_id'],
			'description' => mb_substr((string) $order['description'], 0, 255),
			'currencyCode' => (string) $order['currency'],
			'totalAmount' => (string) $amount,
			'extOrderId' => (string) $order['ext_order_id'],
			'buyer' => [
				'email' => (string) $order['buyer_email'],
				'firstName' => (string) ($order['buyer_name'] ?? ''),
				'lastName' => (string) ($order['buyer_name'] ?? ''),
				'language' => (string) ($order['language'] ?? 'pl'),
			],
			'products' => [[
				'name' => mb_substr((string) $order['description'], 0, 255),
				'unitPrice' => (string) $amount,
				'quantity' => '1',
			]],
		];

		$res = self::request(
			self::host() . '/api/v2_1/orders',
			'POST',
			json_encode($payload, JSON_UNESCAPED_UNICODE),
			['Content-Type: application/json', 'Authorization: Bearer ' . $token],
			false // 302 is the success case here — never follow it
		);

		$data = json_decode((string) $res['body'], true) ?: [];
		$code = (string) ($data['status']['statusCode'] ?? '');

		// PayU answers a successful create with 302 (and the body still carries the JSON).
		if (in_array($res['status'] ?? 0, [200, 201, 302], true) && $code === 'SUCCESS' && !empty($data['redirectUri'])) {
			return [
				'success' => true,
				'redirectUri' => (string) $data['redirectUri'],
				'orderId' => (string) ($data['orderId'] ?? ''),
			];
		}

		// An expired cached token shows up as 401; one retry with a fresh one, then give up.
		if (($res['status'] ?? 0) === 401 && empty($order['_retried'])) {
			self::accessToken(true);
			$order['_retried'] = true;
			return self::createOrder($order);
		}

		self::log('order_failed', 'HTTP ' . ($res['status'] ?? 0) . ' ' . substr((string) $res['body'], 0, 300));
		return [
			'success' => false,
			'error' => $data['status']['codeLiteral'] ?? ('http_' . ($res['status'] ?? 0)),
		];
	}

	/**
	 * Ask PayU what became of an order.
	 *
	 * The notification is the *fast* path, not the reliable one: it is an inbound HTTP call to
	 * our server, so a firewall, a private address or an hour of downtime is enough to lose it,
	 * and the buyer is left having paid for nothing. Polling asks the question from our side,
	 * where nothing has to be reachable — which is also what makes the whole flow testable on
	 * `localhost`, where PayU cannot possibly call back.
	 *
	 * @return array|null the order (status, totalAmount, currencyCode, extOrderId), or null
	 */
	public static function orderStatus(string $orderId): ?array
	{
		$token = self::accessToken();
		if ($token === null || $orderId === '') {
			return null;
		}

		$res = self::request(
			self::host() . '/api/v2_1/orders/' . rawurlencode($orderId),
			'GET',
			'',
			['Authorization: Bearer ' . $token]
		);

		if (($res['status'] ?? 0) === 401) {
			self::accessToken(true);
			$res = self::request(
				self::host() . '/api/v2_1/orders/' . rawurlencode($orderId),
				'GET',
				'',
				['Authorization: Bearer ' . self::accessToken()]
			);
		}

		$data = json_decode((string) $res['body'], true) ?: [];
		$order = $data['orders'][0] ?? null;
		if (!is_array($order)) {
			self::log('status_failed', $orderId . ': HTTP ' . ($res['status'] ?? 0) . ' ' . substr((string) $res['body'], 0, 200));
			return null;
		}
		return $order;
	}

	/**
	 * Collect the money on an order PayU is holding.
	 *
	 * A POS with "automatic collection" switched off parks paid orders at
	 * `WAITING_FOR_CONFIRMATION`: the buyer has paid, but the shop has to say it accepts the
	 * order before the funds move. Since the thing being sold here is granted automatically the
	 * moment the payment lands, leaving that hanging would mean handing over a plan for money
	 * we never took — so the capture happens first and the grant follows it.
	 */
	public static function captureOrder(string $orderId): bool
	{
		$token = self::accessToken();
		if ($token === null || $orderId === '') {
			return false;
		}

		$res = self::request(
			self::host() . '/api/v2_1/orders/' . rawurlencode($orderId) . '/status',
			'PUT',
			json_encode(['orderId' => $orderId, 'orderStatus' => 'COMPLETED']),
			['Content-Type: application/json', 'Authorization: Bearer ' . $token]
		);

		$data = json_decode((string) $res['body'], true) ?: [];
		$ok = ($data['status']['statusCode'] ?? '') === 'SUCCESS';
		if (!$ok) {
			self::log('capture_failed', $orderId . ': HTTP ' . ($res['status'] ?? 0) . ' ' . substr((string) $res['body'], 0, 200));
		}
		return $ok;
	}

	/**
	 * Refund a completed order, in full or (with `$amountMinor`) in part.
	 *
	 * POST /api/v2_1/orders/{orderId}/refunds — the shop-initiated give-back, used when a paid
	 * ad is rejected in review. PayU processes the refund asynchronously (the response carries
	 * `status: PENDING` for the refund itself); SUCCESS here means "accepted for processing",
	 * which for this project's purposes is the moment the money stops being ours.
	 *
	 * @return array{success: bool, refundId?: string, status?: string, error?: string}
	 */
	public static function refund(string $orderId, string $description, ?int $amountMinor = null): array
	{
		$token = self::accessToken();
		if ($token === null || $orderId === '') {
			return ['success' => false, 'error' => 'oauth'];
		}
		$refund = ['description' => mb_substr($description !== '' ? $description : 'Refund', 0, 255)];
		if ($amountMinor !== null && $amountMinor > 0) {
			$refund['amount'] = (string) $amountMinor;
		}
		$call = fn(string $bearer) => self::request(
			self::host() . '/api/v2_1/orders/' . rawurlencode($orderId) . '/refunds',
			'POST',
			json_encode(['refund' => $refund], JSON_UNESCAPED_UNICODE),
			['Content-Type: application/json', 'Authorization: Bearer ' . $bearer]
		);
		$res = $call($token);
		if (($res['status'] ?? 0) === 401) {
			// An expired cached token shows up as 401; one retry with a fresh one, then give up.
			$fresh = self::accessToken(true);
			if ($fresh !== null) {
				$res = $call($fresh);
			}
		}

		$data = json_decode((string) $res['body'], true) ?: [];
		$code = (string) ($data['status']['statusCode'] ?? '');
		if (in_array($res['status'] ?? 0, [200, 201], true) && $code === 'SUCCESS') {
			return [
				'success' => true,
				'refundId' => (string) ($data['refund']['refundId'] ?? ''),
				'status' => (string) ($data['refund']['status'] ?? ''),
			];
		}
		self::log('refund_failed', $orderId . ': HTTP ' . ($res['status'] ?? 0) . ' ' . substr((string) $res['body'], 0, 300));
		return [
			'success' => false,
			'error' => $data['status']['codeLiteral'] ?? ('http_' . ($res['status'] ?? 0)),
		];
	}

	/**
	 * Verify the `OpenPayu-Signature` header against the raw notification body.
	 *
	 * The header looks like
	 *   `sender=checkout;signature=<hash>;algorithm=MD5;content=DOCUMENT`
	 * and the hash is over `<raw body><second MD5 key>`. The *raw* body matters: re-encoding
	 * the decoded JSON changes the bytes and the signature stops matching, which is why the
	 * caller passes `php://input` through untouched.
	 *
	 * Without this check the notification endpoint would be "anyone who knows the URL can grant
	 * themselves a paid plan", so a failure here is fatal to the request, never a warning.
	 */
	public static function verifyNotification(string $rawBody, string $signatureHeader): bool
	{
		$key = self::config()['md5'];
		if ($key === '' || $rawBody === '' || $signatureHeader === '') {
			return false;
		}

		$parts = [];
		foreach (explode(';', $signatureHeader) as $chunk) {
			$chunk = trim($chunk);
			if ($chunk === '' || strpos($chunk, '=') === false) {
				continue;
			}
			[$k, $v] = explode('=', $chunk, 2);
			$parts[strtolower(trim($k))] = trim($v);
		}

		$supplied = (string) ($parts['signature'] ?? '');
		if ($supplied === '') {
			return false;
		}

		$algorithm = strtoupper((string) ($parts['algorithm'] ?? 'MD5'));
		$expected = match ($algorithm) {
			'SHA-256', 'SHA256' => hash('sha256', $rawBody . $key),
			'SHA-1', 'SHA1' => sha1($rawBody . $key),
			'MD5' => md5($rawBody . $key),
			default => '',
		};

		return $expected !== '' && hash_equals($expected, strtolower($supplied));
	}

	/**
	 * One HTTP call. cURL when it is available, a stream context otherwise — the project has no
	 * Composer and no guaranteed extensions, and a shared host without cURL is not exotic.
	 *
	 * @return array{status: int, body: string}
	 */
	private static function request(string $url, string $method, string $body, array $headers, bool $follow = true): array
	{
		if (function_exists('curl_init')) {
			$ch = curl_init($url);
			curl_setopt_array($ch, [
				CURLOPT_CUSTOMREQUEST => $method,
				CURLOPT_POSTFIELDS => $body,
				CURLOPT_HTTPHEADER => $headers,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => $follow,
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
			curl_close($ch);
			return ['status' => $status, 'body' => (string) $out];
		}

		$ctx = stream_context_create(['http' => [
			'method' => $method,
			'header' => implode("\r\n", $headers),
			'content' => $body,
			'timeout' => self::TIMEOUT,
			'follow_location' => $follow ? 1 : 0,
			'ignore_errors' => true,
		]]);
		// Load-bearing, do not delete: PHP 8.5 deprecates the *implicit* locally scoped
		// `$http_response_header` and raises the notice when this file is compiled, whether or
		// not the stream branch ever runs. Declaring the variable first makes it an ordinary
		// local, which the HTTP wrapper still fills in, on every supported PHP version.
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

	/** Failures go to the audit log — a payment that did not happen must leave a trace. */
	private static function log(string $event, string $detail): void
	{
		Database::logAudit('payu_' . $event, $detail);
	}
}
