<?php

require_once PROJECT_ROOT . '/src/includes/api/ApiSupport.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebhookUrlSecurityTest extends TestCase
{
	public function testPublicAddressOnDefaultPortsIsAccepted(): void
	{
		$this->assertTrue(webhookUrlAllowed('https://8.8.8.8/hook'));
		$this->assertTrue(webhookUrlAllowed('http://1.1.1.1/hook?event=upload'));
	}

	#[DataProvider('unsafeUrlProvider')]
	public function testInternalAndAmbiguousDestinationsAreRejected(string $url): void
	{
		$this->assertFalse(webhookUrlAllowed($url));
	}

	public static function unsafeUrlProvider(): array
	{
		return [
			'loopback v4' => ['http://127.0.0.1/hook'],
			'loopback v6' => ['http://[::1]/hook'],
			'private' => ['https://10.0.0.1/hook'],
			'link local metadata' => ['http://169.254.169.254/latest/meta-data'],
			'unspecified' => ['http://0.0.0.0/hook'],
			'multicast' => ['http://224.0.0.1/hook'],
			'shared carrier range' => ['http://100.64.0.1/hook'],
			'non-default port' => ['https://8.8.8.8:8443/hook'],
			'embedded credentials' => ['https://user:pass@8.8.8.8/hook'],
			'non-http scheme' => ['file:///etc/passwd'],
		];
	}
}
