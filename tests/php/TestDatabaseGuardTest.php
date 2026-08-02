<?php

use PHPUnit\Framework\TestCase;

final class TestDatabaseGuardTest extends TestCase
{
	private const NONCE = '0123456789abcdef01234567';

	/** @return array<string, string> */
	private function validEnvironment(): array
	{
		return [
			'ALLOW_DESTRUCTIVE_TEST_DB' => 'YES',
			'TEST_DB_NONCE' => self::NONCE,
			'TEST_DB_HOST' => '127.0.0.1',
			'TEST_DB_ALLOWED_HOSTS' => '',
			'TEST_DB_USER' => 'filehost_test_runner',
			'TEST_DB_PASS' => 'secret',
			'TEST_DB_NAME' => 'filehost_ci_test_' . self::NONCE,
		];
	}

	public function testAcceptsExplicitEphemeralLoopbackDatabase(): void
	{
		$result = TestDatabaseGuard::validate($this->validEnvironment(), DATA_DIR . '/missing.php');

		$this->assertSame('filehost_ci_test_' . self::NONCE, $result['name']);
		$this->assertSame(self::NONCE, $result['nonce']);
		$this->assertSame('127.0.0.1', $result['host']);
	}

	public function testRefusesWithoutExactDestructiveAcknowledgement(): void
	{
		$environment = $this->validEnvironment();
		$environment['ALLOW_DESTRUCTIVE_TEST_DB'] = 'yes';

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('ALLOW_DESTRUCTIVE_TEST_DB=YES');
		TestDatabaseGuard::validate($environment, DATA_DIR . '/missing.php');
	}

	public function testRefusesANameWhoseNonceDoesNotMatch(): void
	{
		$environment = $this->validEnvironment();
		$environment['TEST_DB_NAME'] = 'filehost_ci_test_aaaaaaaaaaaaaaaa';

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('_test_<TEST_DB_NONCE>');
		TestDatabaseGuard::validate($environment, DATA_DIR . '/missing.php');
	}

	public function testRefusesNonAllowlistedHostAndRejectsWildcards(): void
	{
		$environment = $this->validEnvironment();
		$environment['TEST_DB_HOST'] = 'db.internal';

		try {
			TestDatabaseGuard::validate($environment, DATA_DIR . '/missing.php');
			$this->fail('A non-allowlisted host was accepted.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('not allowlisted', $e->getMessage());
		}

		$environment['TEST_DB_ALLOWED_HOSTS'] = '*.internal';
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('exact hosts only');
		TestDatabaseGuard::validate($environment, DATA_DIR . '/missing.php');
	}

	public function testAcceptsAnExplicitExactHostAllowlist(): void
	{
		$environment = $this->validEnvironment();
		$environment['TEST_DB_HOST'] = 'db.internal';
		$environment['TEST_DB_ALLOWED_HOSTS'] = 'db.internal, db-replica.internal';

		$result = TestDatabaseGuard::validate($environment, DATA_DIR . '/missing.php');
		$this->assertSame('db.internal', $result['host']);
	}

	public function testReadsLiteralProductionConfigWithoutExecutingItAndRejectsItsDatabase(): void
	{
		$config = DATA_DIR . '/guard-production-config.php';
		$marker = DATA_DIR . '/must-not-exist';
		file_put_contents(
			$config,
			"<?php\n"
			. "file_put_contents(" . var_export($marker, true) . ", 'executed');\n"
			. "define('DB_HOST', '127.0.0.1');\n"
			. "define('DB_USER', 'production_user');\n"
			. "define('DB_NAME', 'filehost_ci_test_" . self::NONCE . "');\n"
		);

		try {
			$parsed = TestDatabaseGuard::readLiteralDatabaseConfig($config);
			$this->assertSame('127.0.0.1', $parsed['DB_HOST']);
			$this->assertSame('production_user', $parsed['DB_USER']);
			$this->assertFileDoesNotExist($marker);

			$this->expectException(RuntimeException::class);
			$this->expectExceptionMessage('production database');
			TestDatabaseGuard::validate($this->validEnvironment(), $config);
		} finally {
			@unlink($config);
			@unlink($marker);
		}
	}
}
