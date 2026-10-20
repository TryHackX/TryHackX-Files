<?php

/**
 * security_events rows are read only within their window, so housekeeping removes the ones
 * nobody touched for a day (or for the configured window, if that is longer). They used to be
 * reset only when the same address came back, leaving one row per address that ever failed.
 */
final class SecurityEventsCleanupTest extends RepoTestCase
{
	private string $originalWindow = '60';

	protected function setUp(): void
	{
		$this->truncate('security_events');
		$this->originalWindow = (string) Database::getSetting('recaptcha_security_window', '60');
	}

	protected function tearDown(): void
	{
		Database::setSetting('recaptcha_security_window', $this->originalWindow);
		$this->truncate('security_events');
	}

	private function event(string $key, string $type, int $age): void
	{
		Database::getInstance()->prepare(
			"INSERT INTO `" . Database::table('security_events') . "`
			 (`ip_address`, `event_type`, `counter`, `last_updated_at`) VALUES (?, ?, 3, ?)"
		)->execute([$key, $type, time() - $age]);
	}

	/** @return list<string> */
	private function remaining(): array
	{
		return Database::getInstance()->query(
			"SELECT CONCAT(`ip_address`, '|', `event_type`) FROM `" . Database::table('security_events') . "`
			 ORDER BY 1"
		)->fetchAll(PDO::FETCH_COLUMN);
	}

	public function testRowsUntouchedForADayAreRemoved(): void
	{
		Database::setSetting('recaptcha_security_window', '60');
		$this->event('203.0.113.5', 'login_fail', 2 * 86400);
		$this->event('2001:db8:1:2::/64', 'totp_fail', 86400 + 60);
		$this->event('user:7', 'totp_last_step', 90 * 86400);
		$this->event('198.51.100.7', 'login_fail', 3600);
		$this->event('user:8', 'totp_last_step', 30);

		$deleted = Database::cleanupExpired();

		$this->assertSame(3, $deleted['security_events']);
		$this->assertSame(['198.51.100.7|login_fail', 'user:8|totp_last_step'], $this->remaining());
	}

	public function testALongerConfiguredWindowIsKept(): void
	{
		// Three days, in minutes: a row two days old is still inside it.
		Database::setSetting('recaptcha_security_window', (string) (3 * 1440));
		$this->event('203.0.113.5', 'login_fail', 2 * 86400);
		$this->event('203.0.113.6', 'login_fail', 4 * 86400);

		Database::cleanupExpired();

		$this->assertSame(['203.0.113.5|login_fail'], $this->remaining());
	}
}
