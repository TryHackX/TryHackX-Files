<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/includes/InstallerSecurity.php';

final class InstallerSecurityTest extends TestCase
{
	public function testInstallerUsesExternalScriptsUnderStrictScriptCsp(): void
	{
		$root = dirname(__DIR__, 2);
		$page = file_get_contents($root . '/public/install.php');
		$script = file_get_contents($root . '/public/assets/js/install.js');

		$this->assertIsString($page);
		$this->assertIsString($script);
		$this->assertStringContainsString("script-src 'self'; script-src-attr 'none'", $page);
		$this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $page);
		$this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $page);
		$this->assertStringContainsString('assets/js/install.js', $page);
		$this->assertStringNotContainsString('.innerHTML', $script);
	}

	public function testAnyInstalledStateIsFailClosed(): void
	{
		$this->assertSame('open', filehostInstallerState(false, false));
		$this->assertSame('configured', filehostInstallerState(true, false));
		$this->assertSame('locked', filehostInstallerState(false, true));
		$this->assertSame('configured', filehostInstallerState(true, true));
	}

	public function testBootstrapSecretRejectsWeakAndPlaceholderValues(): void
	{
		$this->assertFalse(filehostInstallerSecretIsStrong('short'));
		$this->assertFalse(filehostInstallerSecretIsStrong('replace-with-at-least-32-random-characters'));
		$this->assertFalse(filehostInstallerSecretIsStrong(str_repeat('password', 5)));
		$this->assertFalse(filehostInstallerSecretIsStrong(str_repeat('a', 64)));
		$this->assertTrue(filehostInstallerSecretIsStrong(str_repeat('0123456789abcdef', 4)));
	}

	public function testInstallerIpAllowlistSupportsExactAddressesAndCidrs(): void
	{
		$this->assertTrue(filehostInstallerIpIsAllowed('127.0.0.1', ''));
		$this->assertTrue(filehostInstallerIpIsAllowed('::1', ''));
		$this->assertTrue(filehostInstallerIpIsAllowed('192.0.2.25', '192.0.2.25'));
		$this->assertTrue(filehostInstallerIpIsAllowed('172.18.5.9', '172.16.0.0/12'));
		$this->assertTrue(filehostInstallerIpIsAllowed('2001:db8::5', '2001:db8::/32'));
		$this->assertFalse(filehostInstallerIpIsAllowed('198.51.100.7', '192.0.2.0/24'));
		$this->assertFalse(filehostInstallerIpIsAllowed('172.18.5.9', '172.16.0.0/99'));
	}

	public function testFreshInstallBlocksBrowserActiveDocumentFormats(): void
	{
		$blocked = filehostInstallerDefaultBlockedExtensions();
		foreach (['html', 'htm', 'xhtml', 'svg', 'shtml', 'xml'] as $extension) {
			$this->assertContains($extension, $blocked);
		}
	}

	public function testFreshInstallRunsAndVerifiesTheCurrentMigrationChain(): void
	{
		$page = file_get_contents(dirname(__DIR__, 2) . '/public/install.php');
		$this->assertIsString($page);
		$start = strpos($page, 'function handleCreateTables(): never');
		$end = strpos($page, 'function handleDefaultSettings(): never');
		$this->assertNotFalse($start);
		$this->assertNotFalse($end);
		$handler = substr($page, $start, $end - $start);

		$this->assertStringContainsString('Database::migrate();', $handler);
		$this->assertStringContainsString('Database::CURRENT_SCHEMA_VERSION', $handler);
		$this->assertStringContainsString("Database::getSetting('schema_ready', '0')", $handler);
	}
}
