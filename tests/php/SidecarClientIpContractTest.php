<?php

use PHPUnit\Framework\TestCase;

require_once PROJECT_ROOT . '/src/includes/ClientIp.php';

/**
 * The client-address contract the Python upload server mirrors.
 *
 * tests/python/test_upload_server.py reads the same fixture: PHP binds every download and
 * upload token to the address it resolves, and both sides count a client under the same
 * throttle key, so a case that PHP answers differently from upload_server.py is a broken
 * token or a split budget in production.
 */
final class SidecarClientIpContractTest extends TestCase
{
	/** @return array<string, mixed> */
	private static function vectors(): array
	{
		$raw = file_get_contents(PROJECT_ROOT . '/tests/fixtures/client-ip.json');
		return json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
	}

	public function testThrottleKeysMatchTheSharedVectors(): void
	{
		$vectors = self::vectors()['throttle_keys'];
		$this->assertNotEmpty($vectors);
		foreach ($vectors as [$ip, $key]) {
			$this->assertSame($key, filehostThrottleKey($ip), "throttle key of '{$ip}'");
		}
	}

	public function testClientAddressResolutionMatchesTheSharedVectors(): void
	{
		$cases = self::vectors()['resolve'];
		$this->assertNotEmpty($cases);
		foreach ($cases as $case) {
			$this->assertSame(
				$case['expected'],
				filehostResolveClientIp($case['server'], $case['trusted'], $case['header']),
				$case['case']
			);
		}
	}
}
