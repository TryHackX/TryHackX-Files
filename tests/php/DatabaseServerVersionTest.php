<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/includes/DatabaseServerVersion.php';

final class DatabaseServerVersionTest extends TestCase
{
	/** @return array<string, array{string}> */
	public static function supportedServers(): array
	{
		return [
			'MySQL 8.4' => ['8.4.9'],
			'MySQL 8.0.1 is the floor' => ['8.0.1'],
			'MySQL 9' => ['9.1.0'],
			'Percona suffix' => ['8.0.35-27'],
			'MariaDB 11.8 as production reports it' => ['11.8.3-MariaDB-ubu2404'],
			'MariaDB 10.6 is the floor' => ['10.6.0-MariaDB'],
			'MariaDB 10.11 Debian' => ['10.11.14-MariaDB-0+deb12u2'],
			'MariaDB with the replication prefix' => ['5.5.5-10.11.6-MariaDB-log'],
			'series without a patch number' => ['8.0.mysql_aurora.3.04'],
			'unrecognisable strings are not refused' => ['some-proxy'],
			'empty string is not refused' => [''],
		];
	}

	/** @return array<string, array{string, string}> */
	public static function unsupportedServers(): array
	{
		return [
			'MySQL 5.7' => ['5.7.44', 'MySQL 5.7.44'],
			'MySQL 8.0.0' => ['8.0.0-dmr', 'MySQL 8.0.0'],
			'MariaDB 10.5' => ['10.5.23-MariaDB-0+deb11u1', 'MariaDB 10.5.23'],
			'MariaDB 10.4' => ['10.4.32-MariaDB', 'MariaDB 10.4.32'],
			'MariaDB 10.5 behind the replication prefix' => ['5.5.5-10.5.29-MariaDB', 'MariaDB 10.5.29'],
			'MariaDB 5.5' => ['5.5.68-MariaDB', 'MariaDB 5.5.68'],
		];
	}

	#[DataProvider('supportedServers')]
	public function testSupportedServersAreAccepted(string $reported): void
	{
		$this->assertNull(filehostDatabaseServerVersionProblem($reported));
	}

	#[DataProvider('unsupportedServers')]
	public function testServersWithoutSkipLockedAreRefusedWithTheirVersion(string $reported, string $expected): void
	{
		$problem = filehostDatabaseServerVersionProblem($reported);

		$this->assertNotNull($problem);
		$this->assertStringContainsString($expected, $problem);
		$this->assertStringContainsString('SKIP LOCKED', $problem);
	}

	public function testTheFloorsMatchTheStatementTheOutboxRuns(): void
	{
		$source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/includes/repositories/MailService.php');

		$this->assertStringContainsString('FOR UPDATE SKIP LOCKED', $source);
		$this->assertSame('8.0.1', FILEHOST_MIN_MYSQL_VERSION);
		$this->assertSame('10.6.0', FILEHOST_MIN_MARIADB_VERSION);
	}
}
