<?php

/**
 * Przelewy24 REST protocol tests.
 *
 * The transport is replaced with an in-memory callback: these tests validate the exact
 * request/signature contract without merchant credentials or access to the P24 sandbox.
 */
final class P24Test extends RepoTestCase
{
	private const MERCHANT_ID = 123456;
	private const POS_ID = 654321;
	private const CRC = 'test-crc-secret';
	private const API_KEY = 'test-api-key';

	protected function setUp(): void
	{
		PaymentPlugins::save('przelewy24', [
			'merchant_id' => (string) self::MERCHANT_ID,
			'pos_id' => (string) self::POS_ID,
			'crc' => self::CRC,
			'api_key' => self::API_KEY,
			'currency' => 'PLN',
			'environment' => 'sandbox',
		]);
		Database::invalidateSettingsCache();
	}

	protected function tearDown(): void
	{
		P24::setTransport(null);
	}

	public function testCreatesSignedOrderWithBasicAuthentication(): void
	{
		$captured = [];
		P24::setTransport(static function (
			string $url,
			string $method,
			string $body,
			array $headers
		) use (&$captured): array {
			$captured = compact('url', 'method', 'body', 'headers');
			return [
				'status' => 200,
				'body' => json_encode(['data' => ['token' => 'p24-test-token'], 'responseCode' => 0]),
			];
		});

		$result = P24::createOrder([
			'ext_order_id' => 'fh-session-1',
			'amount_minor' => 1999,
			'currency' => 'PLN',
			'description' => 'Premium plan',
			'buyer_email' => 'buyer@example.test',
			'buyer_name' => 'Test Buyer',
			'language' => 'pl',
			'continue_url' => 'https://example.test/return',
			'notify_url' => 'https://example.test/api.php?action=p24_notify',
		]);

		$this->assertTrue($result['success']);
		$this->assertSame('p24-test-token', $result['token']);
		$this->assertSame(
			'https://sandbox.przelewy24.pl/trnRequest/p24-test-token',
			$result['redirectUri']
		);
		$this->assertSame('https://sandbox.przelewy24.pl/api/v1/transaction/register', $captured['url']);
		$this->assertSame('POST', $captured['method']);
		$this->assertContains(
			'Authorization: Basic ' . base64_encode(self::POS_ID . ':' . self::API_KEY),
			$captured['headers']
		);

		$payload = json_decode($captured['body'], true);
		$this->assertSame(self::MERCHANT_ID, $payload['merchantId']);
		$this->assertSame(self::POS_ID, $payload['posId']);
		$this->assertSame(1999, $payload['amount']);
		$this->assertSame('PL', $payload['country']);
		$this->assertSame(0, $payload['timeLimit']);
		$this->assertArrayNotHasKey('ttl', $payload);
		$this->assertSame(
			$this->sign([
				'sessionId' => 'fh-session-1',
				'merchantId' => self::MERCHANT_ID,
				'amount' => 1999,
				'currency' => 'PLN',
				'crc' => self::CRC,
			]),
			$payload['sign']
		);
	}

	public function testAccessAcceptsTheOfficialBooleanResponseShape(): void
	{
		$captured = [];
		P24::setTransport(static function (
			string $url,
			string $method,
			string $body,
			array $headers
		) use (&$captured): array {
			$captured = compact('url', 'method', 'body', 'headers');
			return ['status' => 200, 'body' => json_encode(['data' => true, 'error' => ''])];
		});

		$this->assertTrue(P24::testAccess());
		$this->assertSame('GET', $captured['method']);
		$this->assertStringEndsWith('/api/v1/testAccess', $captured['url']);
		$this->assertSame('', $captured['body']);
	}

	public function testAcceptsOnlyAnUntamperedTransactionNotificationForThisMerchant(): void
	{
		$notification = [
			'merchantId' => self::MERCHANT_ID,
			'posId' => self::POS_ID,
			'sessionId' => 'fh-session-2',
			'amount' => 4200,
			'originAmount' => 4200,
			'currency' => 'PLN',
			'orderId' => 987654321,
			'methodId' => 154,
			'statement' => 'P24-TEST',
		];
		$notification['sign'] = $this->sign($notification + ['crc' => self::CRC]);

		$this->assertTrue(P24::verifyNotification($notification));

		$tampered = $notification;
		$tampered['amount'] = 1;
		$this->assertFalse(P24::verifyNotification($tampered));

		$wrongMerchant = $notification;
		$wrongMerchant['merchantId']++;
		$this->assertFalse(P24::verifyNotification($wrongMerchant));
	}

	public function testPerformsMandatorySignedTransactionVerification(): void
	{
		$captured = [];
		P24::setTransport(static function (
			string $url,
			string $method,
			string $body,
			array $headers
		) use (&$captured): array {
			$captured = compact('url', 'method', 'body', 'headers');
			return [
				'status' => 200,
				'body' => json_encode(['data' => ['status' => 'success'], 'responseCode' => 0]),
			];
		});

		$this->assertTrue(P24::verifyTransaction('fh-session-3', 445566, 2500, 'pln'));
		$this->assertSame('PUT', $captured['method']);
		$this->assertStringEndsWith('/api/v1/transaction/verify', $captured['url']);
		$payload = json_decode($captured['body'], true);
		$this->assertSame(
			$this->sign([
				'sessionId' => 'fh-session-3',
				'orderId' => 445566,
				'amount' => 2500,
				'currency' => 'PLN',
				'crc' => self::CRC,
			]),
			$payload['sign']
		);
	}

	public function testNormalizesProviderStatusForTheReconciler(): void
	{
		P24::setTransport(static fn(): array => [
			'status' => 200,
			'body' => json_encode([
				'responseCode' => 0,
				'data' => [
					'status' => 2,
					'orderId' => 445566,
					'sessionId' => 'fh-session-4',
					'amount' => 3100,
					'currency' => 'PLN',
				],
			]),
		]);

		$this->assertSame([
			'status' => 2,
			'orderId' => 445566,
			'sessionId' => 'fh-session-4',
			'amount' => 3100,
			'currency' => 'PLN',
		], P24::orderStatus('fh-session-4'));
	}

	public function testRefundRemainsPendingUntilTheSignedCallback(): void
	{
		$captured = [];
		P24::setTransport(static function (
			string $url,
			string $method,
			string $body,
			array $headers
		) use (&$captured): array {
			$captured = compact('url', 'method', 'body', 'headers');
			return [
				'status' => 201,
				'body' => json_encode([
					'responseCode' => 0,
					'data' => [['orderId' => 445566, 'status' => true]],
				]),
			];
		});

		$result = P24::refund(
			445566,
			'fh-session-5',
			3100,
			str_repeat('x', 80),
			'https://example.test/api.php?action=p24_refund_notify',
			'request-123',
			'refund-uuid-123'
		);
		$this->assertSame([
			'success' => true,
			'requestId' => 'request-123',
			'refundsUuid' => 'refund-uuid-123',
			'status' => 'accepted',
		], $result);
		$this->assertSame('POST', $captured['method']);
		$this->assertStringEndsWith('/api/v1/transaction/refund', $captured['url']);
		$payload = json_decode($captured['body'], true);
		$this->assertSame('request-123', $payload['requestId']);
		$this->assertSame('refund-uuid-123', $payload['refundsUuid']);
		$this->assertSame(35, mb_strlen($payload['refunds'][0]['description']));
	}

	public function testAcceptsOnlyAnUntamperedRefundNotification(): void
	{
		$notification = [
			'orderId' => 445566,
			'sessionId' => 'fh-session-5',
			'refundsUuid' => 'refund-uuid-123',
			'merchantId' => self::MERCHANT_ID,
			'amount' => 3100,
			'currency' => 'PLN',
			'status' => 0,
		];
		$notification['sign'] = $this->sign($notification + ['crc' => self::CRC]);

		$this->assertTrue(P24::verifyRefundNotification($notification));

		$tampered = $notification;
		$tampered['status'] = 1;
		$this->assertFalse(P24::verifyRefundNotification($tampered));
	}

	public function testRefundSignaturePreservesTheProviderCurrencyBytes(): void
	{
		$notification = [
			'orderId' => 445566,
			'sessionId' => 'fh-session-lowercase',
			'refundsUuid' => 'refund-uuid-lowercase',
			'merchantId' => self::MERCHANT_ID,
			'amount' => 3100,
			'currency' => 'pln',
			'status' => 0,
		];
		$notification['sign'] = $this->sign($notification + ['crc' => self::CRC]);

		$this->assertTrue(P24::verifyRefundNotification($notification));
	}

	private function sign(array $orderedFields): string
	{
		return hash(
			'sha384',
			json_encode($orderedFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
		);
	}
}
