<?php

require_once PROJECT_ROOT . '/src/includes/RateLimiter.php';
require_once PROJECT_ROOT . '/src/includes/api/AuthController.php';

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

	/**
	 * Wrong sign-in passwords are counted for the account and for the account as seen from one
	 * network. A network that used up its guesses says nothing about any other network.
	 */
	public function testSignInFailuresFromOneNetworkLeaveOtherNetworksAlone(): void
	{
		$account = AuthController::loginAccountBucket(42);
		$attacker = AuthController::loginAccountBucket(42, filehostThrottleKey('203.0.113.7'));
		$owner = AuthController::loginAccountBucket(42, filehostThrottleKey('198.51.100.9'));
		$this->assertSame('acct:42', $account, 'the account-wide bucket keeps its name');
		$this->assertSame('acct:42|net:203.0.113.7', $attacker);
		$this->assertSame(
			AuthController::loginAccountBucket(42, filehostThrottleKey('2001:db8:1:2::5')),
			AuthController::loginAccountBucket(42, filehostThrottleKey('2001:db8:1:2:ffff::9')),
			'one IPv6 /64 is one network'
		);

		for ($i = 0; $i < 10; $i++) {
			RateLimiter::hit($account, 'login_account');
			RateLimiter::hit($attacker, 'login_account');
		}
		$this->assertTrue(RateLimiter::exceeded($account, 'login_account'));
		$this->assertTrue(RateLimiter::exceeded($attacker, 'login_account'));
		$this->assertFalse(RateLimiter::exceeded($owner, 'login_account'));
	}

	/**
	 * Nothing a stranger can cause ever refuses the right password: with a captcha configured
	 * every limit asks for it, and without one only the exhausted network is turned away.
	 */
	public function testTheSignInGateNeverLetsAStrangerLockTheAccount(): void
	{
		// [captcha configured, account limit, network limit, per-address captcha rule] => gate
		$cases = [
			[false, false, false, false, 'open'],
			[false, true, false, false, 'open'],
			[false, false, false, true, 'open'],
			[false, true, true, false, 'refuse'],
			[false, false, true, true, 'refuse'],
			[true, false, false, false, 'open'],
			[true, true, false, false, 'captcha'],
			[true, false, true, false, 'captcha'],
			[true, true, true, true, 'captcha'],
			[true, false, false, true, 'captcha'],
		];
		foreach ($cases as [$captcha, $account, $network, $address, $expected]) {
			$this->assertSame(
				$expected,
				AuthController::loginGate($captcha, $account, $network, $address),
				json_encode([$captcha, $account, $network, $address])
			);
		}
	}
}
