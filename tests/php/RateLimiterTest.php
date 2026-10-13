<?php

require_once PROJECT_ROOT . '/src/includes/RateLimiter.php';

final class RateLimiterTest extends RepoTestCase
{
	protected function setUp(): void
	{
		$this->truncate('rate_limits');
	}

	public function testAtomicHitReturnsTheStoredCounterWithoutAReadRace(): void
	{
		$first = RateLimiter::hit('test:atomic', 'auth');
		$second = RateLimiter::hit('test:atomic', 'auth');

		$this->assertTrue($first['allowed']);
		$this->assertSame(10, $first['limit']);
		$this->assertSame(9, $first['remaining']);
		$this->assertSame(8, $second['remaining']);
		$this->assertSame($first['reset'], $second['reset']);

		$table = Database::table('rate_limits');
		$this->assertSame(
			2,
			(int) Database::getInstance()
				->query("SELECT `hits` FROM `{$table}` WHERE `bucket` = 'test:atomic|auth'")
				->fetchColumn()
		);
	}

	public function testAlignedWindowRollsAnOldRowBackToOne(): void
	{
		RateLimiter::hit('test:roll', 'auth');
		$table = Database::table('rate_limits');
		Database::getInstance()
			->exec("UPDATE `{$table}` SET `window_start` = `window_start` - 600, `hits` = 10");

		$result = RateLimiter::hit('test:roll', 'auth');

		$this->assertTrue($result['allowed']);
		$this->assertSame(9, $result['remaining']);
		$this->assertGreaterThan(time(), $result['reset']);
	}

	/** Failure-only limits look before they count. */
	public function testExceededPeeksWithoutCountingAndTripsAtTheLimit(): void
	{
		$this->assertFalse(RateLimiter::exceeded('acct:42', 'login_account'));
		$this->assertFalse(RateLimiter::exceeded('acct:42', 'login_account'), 'looking costs nothing');
		for ($i = 0; $i < 9; $i++) {
			RateLimiter::hit('acct:42', 'login_account');
		}
		$this->assertFalse(RateLimiter::exceeded('acct:42', 'login_account'));
		RateLimiter::hit('acct:42', 'login_account');
		$this->assertTrue(RateLimiter::exceeded('acct:42', 'login_account'));
		$this->assertFalse(RateLimiter::exceeded('acct:43', 'login_account'), 'per account');

		// A row left over from an earlier window does not count.
		$table = Database::table('rate_limits');
		Database::getInstance()->exec("UPDATE `{$table}` SET `window_start` = `window_start` - 900");
		$this->assertFalse(RateLimiter::exceeded('acct:42', 'login_account'));
		$this->assertGreaterThan(0, RateLimiter::secondsLeft('login_account'));
	}

	/**
	 * Credential-checking actions are counted per action and per network, so a sign-up form
	 * checking a username as it is typed never spends the budget a sign-in needs, and an
	 * IPv6 /64 is one client.
	 */
	public function testApiBucketsSeparateAuthActionsAndGroupIpv6Networks(): void
	{
		$this->assertSame('ip:203.0.113.9|user_login', RateLimiter::bucketFor('203.0.113.9', 'auth', 'user_login'));
		$this->assertNotSame(
			RateLimiter::bucketFor('203.0.113.9', 'auth', 'user_login'),
			RateLimiter::bucketFor('203.0.113.9', 'auth', 'user_2fa_login')
		);
		$this->assertSame('ip:203.0.113.9', RateLimiter::bucketFor('203.0.113.9', 'read', 'user_files'));
		$this->assertSame(
			RateLimiter::bucketFor('2001:db8:5:6::1', 'auth', 'user_login'),
			RateLimiter::bucketFor('2001:db8:5:6:dead:beef::2', 'auth', 'user_login')
		);
	}
}
