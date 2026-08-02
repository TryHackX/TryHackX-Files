<?php

/**
 * RegistrationGuard: the rules that stop one person becoming fifty accounts (pkt C).
 */
final class RegistrationGuardTest extends RepoTestCase
{
	protected function setUp(): void
	{
		$this->truncate('email_reservations', 'users');
		Database::getInstance()->prepare(
			'INSERT INTO `' . Database::table('users') . '`
			 (`id`,`username`,`email`,`password_hash`,`role`,`is_active`,`created_at`)
			 VALUES (1,?,?,?,?,?,?)'
		)->execute(['reservation-owner', 'reservation-owner@example.test', 'x', 'user', 1, time()]);
		// Every rule off — each test switches on the one it is about.
		Database::setSetting('email_domain_mode', 'off');
		Database::setSetting('email_domain_list', '');
		Database::setSetting('reg_ip_limit', 0);
		Database::setSetting('reg_ip_window_days', 90);
		Database::setSetting('email_release_days', 0);
	}

	public function testDomainRulesAreOffByDefault(): void
	{
		$this->assertNull(RegistrationGuard::checkDomain('someone@anywhere.test'));
	}

	public function testAllowListAdmitsOnlyListedDomainsAndTheirSubdomains(): void
	{
		Database::setSetting('email_domain_mode', 'whitelist');
		Database::setSetting('email_domain_list', "example.com, firma.pl");

		$this->assertNull(RegistrationGuard::checkDomain('a@example.com'));
		$this->assertNull(RegistrationGuard::checkDomain('a@mail.example.com')); // sub-domain counts
		$this->assertNull(RegistrationGuard::checkDomain('a@firma.pl'));
		$this->assertNotNull(RegistrationGuard::checkDomain('a@gmail.com'));
		// A near-miss must not sneak through on a suffix match.
		$this->assertNotNull(RegistrationGuard::checkDomain('a@notexample.com'));
	}

	public function testBlockListRefusesListedDomains(): void
	{
		Database::setSetting('email_domain_mode', 'blacklist');
		Database::setSetting('email_domain_list', "throwaway.test\nmailinator.com");

		$this->assertNotNull(RegistrationGuard::checkDomain('a@throwaway.test'));
		$this->assertNotNull(RegistrationGuard::checkDomain('a@sub.throwaway.test'));
		$this->assertNull(RegistrationGuard::checkDomain('a@example.com'));
	}

	/** An empty list must not lock everyone out of an install that only set the mode. */
	public function testEmptyListLeavesTheRuleInactive(): void
	{
		Database::setSetting('email_domain_mode', 'whitelist');
		Database::setSetting('email_domain_list', '   ');
		$this->assertNull(RegistrationGuard::checkDomain('a@anywhere.test'));
	}

	public function testAccountsPerIpAreCapped(): void
	{
		Database::setSetting('reg_ip_limit', 2);

		$pdo = Database::getInstance();
		$ins = $pdo->prepare('INSERT INTO `' . Database::table('users')
			. '` (`username`, `email`, `password_hash`, `created_at`, `is_active`, `registered_ip`) VALUES (?, ?, ?, ?, 1, ?)');
		$ins->execute(['ipa', 'ipa@example.com', 'x', time(), '203.0.113.7']);
		$this->assertNull(RegistrationGuard::checkIp('203.0.113.7'));   // 1 of 2

		$ins->execute(['ipb', 'ipb@example.com', 'x', time(), '203.0.113.7']);
		$this->assertNotNull(RegistrationGuard::checkIp('203.0.113.7')); // 2 of 2 — full
		$this->assertNull(RegistrationGuard::checkIp('203.0.113.8'));    // a different IP is fine
	}

	/** Accounts older than the window stop counting, so the cap is a rate, not a lifetime total. */
	public function testOldAccountsFallOutOfTheWindow(): void
	{
		Database::setSetting('reg_ip_limit', 1);
		Database::setSetting('reg_ip_window_days', 30);

		Database::getInstance()->prepare('INSERT INTO `' . Database::table('users')
			. '` (`username`, `email`, `password_hash`, `created_at`, `is_active`, `registered_ip`) VALUES (?, ?, ?, ?, 1, ?)')
			->execute(['old', 'old@example.com', 'x', time() - (60 * 86400), '203.0.113.9']);

		$this->assertNull(RegistrationGuard::checkIp('203.0.113.9'));
	}

	/**
	 * The loop this closes: register with A, move the account to B, register again with A.
	 */
	public function testReleasedAddressIsHeldForTheConfiguredPeriod(): void
	{
		Database::setSetting('email_release_days', 30);

		$this->assertNull(RegistrationGuard::checkReserved('recycled@example.com'));
		RegistrationGuard::reserve('recycled@example.com', 1);
		$this->assertNotNull(RegistrationGuard::checkReserved('recycled@example.com'));
		// Case does not matter — addresses are held lower-cased.
		$this->assertNotNull(RegistrationGuard::checkReserved('Recycled@Example.com'));

		// Once the hold has passed the address is free again.
		Database::getInstance()->prepare('UPDATE `' . Database::table('email_reservations')
			. '` SET `released_at` = ? WHERE `email` = ?')
			->execute([time() - (40 * 86400), 'recycled@example.com']);
		$this->assertNull(RegistrationGuard::checkReserved('recycled@example.com'));
	}

	public function testReservationIsSkippedWhenTheHoldIsZero(): void
	{
		Database::setSetting('email_release_days', 0);
		RegistrationGuard::reserve('free@example.com', 1);
		$this->assertNull(RegistrationGuard::checkReserved('free@example.com'));
	}

	/** The guards are wired into registration itself, not just available to it. */
	public function testRegistrationRefusesABlockedDomain(): void
	{
		Database::setSetting('email_domain_mode', 'blacklist');
		Database::setSetting('email_domain_list', 'blocked.test');

		$res = Database::registerUser('guarded1', 'someone@blocked.test', 'Str0ng!pass');
		$this->assertFalse($res['success']);

		$ok = Database::registerUser('guarded2', 'someone@allowed.test', 'Str0ng!pass');
		$this->assertTrue($ok['success'], $ok['error'] ?? '');
	}
}
