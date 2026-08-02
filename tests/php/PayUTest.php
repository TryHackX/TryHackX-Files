<?php

/**
 * PayU notification signature (pt 10).
 *
 * The check that stands between "PayU says this order is paid" and "anyone who found the
 * notification URL says this order is paid", so it gets tested on its own — no network, no
 * database, just the header, the body and the key.
 */
final class PayUTest extends RepoTestCase
{
	private const KEY = 'b6ca15b0d1020e8094d9b5f8d163db54';
	private const BODY = '{"order":{"extOrderId":"fh-1","status":"COMPLETED","totalAmount":"1900"}}';

	protected function setUp(): void
	{
		// The key is read from the plugin settings, so put a known one there.
		PaymentPlugins::save('payu', [
			'pos_id' => '300746',
			'client_id' => '300746',
			'client_secret' => 'secret',
			'md5' => self::KEY,
			'currency' => 'PLN',
			'environment' => 'sandbox',
		]);
		Database::invalidateSettingsCache();
	}

	private function header(string $signature, string $algorithm = 'MD5'): string
	{
		return "sender=checkout;signature={$signature};algorithm={$algorithm};content=DOCUMENT";
	}

	public function testAcceptsCorrectlySignedNotification(): void
	{
		$sig = md5(self::BODY . self::KEY);
		$this->assertTrue(PayU::verifyNotification(self::BODY, $this->header($sig)));
	}

	public function testRejectsWrongSignature(): void
	{
		$this->assertFalse(PayU::verifyNotification(self::BODY, $this->header(md5('nonsense'))));
	}

	public function testRejectsTamperedBody(): void
	{
		// The amount is the field worth tampering with, so tamper with the amount.
		$sig = md5(self::BODY . self::KEY);
		$tampered = str_replace('1900', '1', self::BODY);
		$this->assertFalse(PayU::verifyNotification($tampered, $this->header($sig)));
	}

	public function testRejectsMissingSignature(): void
	{
		$this->assertFalse(PayU::verifyNotification(self::BODY, ''));
		$this->assertFalse(PayU::verifyNotification(self::BODY, 'sender=checkout;algorithm=MD5'));
		$this->assertFalse(PayU::verifyNotification('', $this->header(md5(self::KEY))));
	}

	public function testHonoursTheDeclaredAlgorithm(): void
	{
		$sig = hash('sha256', self::BODY . self::KEY);
		$this->assertTrue(PayU::verifyNotification(self::BODY, $this->header($sig, 'SHA-256')));
		// The same digest must not pass while claiming to be MD5.
		$this->assertFalse(PayU::verifyNotification(self::BODY, $this->header($sig, 'MD5')));
	}

	public function testRejectsUnknownAlgorithm(): void
	{
		$sig = md5(self::BODY . self::KEY);
		$this->assertFalse(PayU::verifyNotification(self::BODY, $this->header($sig, 'ROT13')));
	}
}
