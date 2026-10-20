<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/includes/CliGuard.php';

/**
 * Maintenance scripts run as root leave files the services cannot read (a restored upload, a
 * repaired manifest, a quarantine copy). The guard refuses root and names the account to use;
 * the decision is tested here with injected user ids.
 */
final class CliGuardTest extends TestCase
{
	public function testRootIsRefusedWithTheCommandForTheStorageOwner(): void
	{
		[$arguments, $refusal] = CliGuard::evaluate(['scripts/file-quarantine.php', 'restore', 'AbC123'], 0, 'filehost');

		$this->assertSame(['scripts/file-quarantine.php', 'restore', 'AbC123'], $arguments);
		$this->assertNotNull($refusal);
		$this->assertStringStartsWith('file-quarantine.php refuses to run as root', $refusal);
		$this->assertStringContainsString('  sudo -u filehost php scripts/file-quarantine.php restore AbC123', $refusal);
		$this->assertStringContainsString('--allow-root', $refusal);
	}

	public function testTheServiceAccountAndOtherUsersRunNormally(): void
	{
		$this->assertNull(CliGuard::evaluate(['scripts/cleanup.php'], 33, null)[1], 'www-data');
		$this->assertNull(CliGuard::evaluate(['scripts/cleanup.php'], 1000, null)[1], 'any other account');
		$this->assertNull(CliGuard::evaluate(['scripts/cleanup.php'], null, null)[1], 'no POSIX user ids (Windows)');
	}

	public function testAllowRootLetsADeliberateRunThroughAndIsNeverPassedOn(): void
	{
		foreach ([0, 33, null] as $euid) {
			[$arguments, $refusal] = CliGuard::evaluate(
				['scripts/file-quarantine.php', '--allow-root', 'purge', 'AbC123', '--force'],
				$euid,
				'filehost'
			);
			$this->assertNull($refusal, 'uid ' . var_export($euid, true));
			$this->assertSame(['scripts/file-quarantine.php', 'purge', 'AbC123', '--force'], $arguments);
		}
	}

	public function testAReadOnlyModeStaysAvailableToRoot(): void
	{
		$this->assertNull(
			CliGuard::evaluate(['scripts/check-storage-integrity.php', '--json'], 0, 'www-data', '--repair-missing')[1]
		);
		$refusal = CliGuard::evaluate(
			['scripts/check-storage-integrity.php', '--repair-missing', '--json'],
			0,
			'www-data',
			'--repair-missing'
		)[1];
		$this->assertNotNull($refusal);
		$this->assertStringContainsString('sudo -u www-data php scripts/check-storage-integrity.php --repair-missing --json', $refusal);
	}

	public function testTheSuggestedCommandIsSafeToPaste(): void
	{
		$refusal = (string) CliGuard::evaluate(['/var/www/my files/scripts/cleanup.php', "it's"], 0, '#33')[1];
		$this->assertStringContainsString("sudo -u '#33' php '/var/www/my files/scripts/cleanup.php' 'it'\\''s'", $refusal);

		foreach ([null, '', 'root'] as $unknown) {
			$refusal = (string) CliGuard::evaluate(['scripts/cleanup.php'], 0, $unknown)[1];
			$this->assertStringContainsString("sudo -u '<service account>' php scripts/cleanup.php", $refusal);
		}
	}

	public function testRefuseRootTakesTheOverrideOutOfTheScriptArguments(): void
	{
		$saved = [$_SERVER['argv'] ?? null, $_SERVER['argc'] ?? null];
		$_SERVER['argv'] = ['scripts/file-quarantine.php', '--allow-root', 'restore', 'AbC123'];
		try {
			// --allow-root lets even root through, so this returns whoever runs the suite.
			CliGuard::refuseRoot();
			$this->assertSame(['scripts/file-quarantine.php', 'restore', 'AbC123'], $_SERVER['argv']);
			$this->assertSame(3, $_SERVER['argc']);
		} finally {
			[$_SERVER['argv'], $_SERVER['argc']] = $saved;
		}
	}

	public function testTheServiceAccountIsWhoOwnsTheUploads(): void
	{
		if (!function_exists('posix_geteuid')) {
			$this->assertNull(CliGuard::serviceAccount(), 'without user ids there is nobody to name');
			return;
		}
		if (posix_geteuid() === 0) {
			$this->markTestSkipped('the suite runs as root, so the test uploads directory belongs to root');
		}
		$self = posix_getpwuid(posix_geteuid());
		$this->assertIsArray($self);
		// The bootstrap creates UPLOADS_DIR for this run, so it belongs to the account running it.
		$this->assertSame($self['name'], CliGuard::serviceAccount());
	}
}
