<?php

require_once dirname(__DIR__, 2) . '/src/includes/api/ApiSupport.php';

/**
 * Persistent sign-in, and the second gate in front of the panel.
 *
 * Two credentials with different meanings live here. A remember token proves possession of a
 * device and may be weeks old; a password proves knowledge and was just typed. Everything
 * below exists to keep those apart — the first must never be accepted where the second is
 * required, and neither may outlive the account state it was issued against.
 */
final class RememberTokenTest extends RepoTestCase
{
	private int $userId = 0;

	protected function setUp(): void
	{
		$this->truncate('remember_tokens');
		Database::setSetting('login_remember_enabled', '1');
		Database::setSetting('login_remember_max_days', 30);
		Database::setSetting('panel_reauth_minutes', 30);
		Database::setSetting('panel_reauth_scope', 'staff');

		$name = 'remember' . bin2hex(random_bytes(4));
		$registered = Database::registerUser($name, $name . '@example.test', 'Str0ng!pass');
		$this->assertTrue($registered['success'], (string) ($registered['error'] ?? ''));
		$this->userId = (int) Database::getUserByEmailOrUsername($name)['id'];
		// Registration may leave the account inactive; the credential lifecycle is the subject
		// here, not the activation flow.
		Database::getInstance()->exec(
			"UPDATE `" . Database::table('users') . "` SET `is_active` = 1 WHERE `id` = " . $this->userId
		);
		$_SESSION = [];
		$_COOKIE = [];
	}

	protected function tearDown(): void
	{
		$_SESSION = [];
		$_COOKIE = [];
	}

	private function rows(): array
	{
		return Database::getInstance()->query(
			"SELECT * FROM `" . Database::table('remember_tokens') . "` ORDER BY `id`"
		)->fetchAll(PDO::FETCH_ASSOC);
	}

	public function testTheAdministratorsCeilingBindsEveryRequestedDuration(): void
	{
		Database::setSetting('login_remember_max_days', 7);

		$this->assertSame(0, RememberTokenRepository::resolveLifetime(0), 'session-only stays session-only');
		$this->assertSame(1800, RememberTokenRepository::resolveLifetime(1800));
		$this->assertSame(604800, RememberTokenRepository::resolveLifetime(2592000), '30 days is capped at 7');
		$this->assertSame(604800, RememberTokenRepository::resolveLifetime(-1), '"as long as allowed" is the cap');

		Database::setSetting('login_remember_enabled', '0');
		$this->assertSame(0, RememberTokenRepository::resolveLifetime(-1), 'nothing is granted when it is off');
	}

	public function testOnlyHashesAreStoredAndTheSecretRotatesOnEveryUse(): void
	{
		$cookie = RememberTokenRepository::issue($this->userId, 86400, '203.0.113.9', 'Test/1.0');
		$this->assertMatchesRegularExpression('/\A[a-f0-9]{64}:[a-f0-9]{64}\z/D', $cookie);
		[$series, $secret] = explode(':', $cookie);

		$row = $this->rows()[0];
		$this->assertSame(hash('sha256', $series), $row['series'], 'the series is stored hashed');
		$this->assertSame(hash('sha256', $secret), $row['token_hash'], 'so is the secret');
		$this->assertStringNotContainsString($secret, json_encode($row), 'nothing replayable is kept');

		[$userId, $rotated, $expiresAt] = RememberTokenRepository::consume($cookie, '203.0.113.9', 'Test/1.0');
		$this->assertSame($this->userId, $userId);
		$this->assertNotSame($cookie, $rotated, 'the secret must not survive a use');
		$this->assertSame($series, explode(':', $rotated)[0], 'the device keeps its series');
		$this->assertSame((int) $row['expires_at'], $expiresAt, 'using it does not extend the deadline');
		$this->assertCount(1, $this->rows(), 'rotation replaces, it does not accumulate');

		[$again] = RememberTokenRepository::consume($rotated, '203.0.113.9', 'Test/1.0');
		$this->assertSame($this->userId, $again, 'the replacement works exactly once too');
	}

	public function testReplayingAnOldSecretDestroysEveryTokenForTheAccount(): void
	{
		$laptop = RememberTokenRepository::issue($this->userId, 86400, '203.0.113.9', 'Laptop/1.0');
		$phone = RememberTokenRepository::issue($this->userId, 86400, '203.0.113.10', 'Phone/1.0');
		$this->assertCount(2, $this->rows());

		[, $rotated] = RememberTokenRepository::consume($laptop, '203.0.113.9', 'Laptop/1.0');
		$this->assertNotSame('', $rotated);

		// The stolen copy still carries the previous secret.
		[$userId, $reissued] = RememberTokenRepository::consume($laptop, '198.51.100.7', 'Thief/1.0');
		$this->assertSame(0, $userId, 'a replayed cookie authenticates nobody');
		$this->assertSame('', $reissued);
		$this->assertSame([], $this->rows(), 'and neither party keeps a token');

		[$phoneUser] = RememberTokenRepository::consume($phone, '203.0.113.10', 'Phone/1.0');
		$this->assertSame(0, $phoneUser, 'the whole family goes, not just the copied device');

		$audit = Database::getInstance()->query(
			"SELECT COUNT(*) FROM `" . Database::table('audit_log') . "`
			 WHERE `action` = 'remember_token_reuse'"
		)->fetchColumn();
		$this->assertSame(1, (int) $audit, 'a theft leaves a trace');
	}

	public function testAnExpiredTokenIsRefusedAndRemoved(): void
	{
		$cookie = RememberTokenRepository::issue($this->userId, 86400, '203.0.113.9', 'Test/1.0');
		Database::getInstance()->exec(
			"UPDATE `" . Database::table('remember_tokens') . "` SET `expires_at` = " . (time() - 1)
		);

		[$userId] = RememberTokenRepository::consume($cookie, '203.0.113.9', 'Test/1.0');
		$this->assertSame(0, $userId);
		$this->assertSame([], $this->rows());
	}

	public function testAMalformedCookieIsRejectedWithoutTouchingTheTable(): void
	{
		RememberTokenRepository::issue($this->userId, 86400, '203.0.113.9', 'Test/1.0');

		foreach (['', 'nonsense', str_repeat('a', 64), str_repeat('z', 64) . ':' . str_repeat('z', 64)] as $bad) {
			[$userId] = RememberTokenRepository::consume($bad, '203.0.113.9', 'Test/1.0');
			$this->assertSame(0, $userId, "accepted a malformed cookie: {$bad}");
		}
		$this->assertCount(1, $this->rows(), 'a bad cookie must not delete a good token');
	}

	public function testChangingTheCredentialSignsEveryDeviceOut(): void
	{
		RememberTokenRepository::issue($this->userId, 86400, '203.0.113.9', 'Laptop/1.0');
		RememberTokenRepository::issue($this->userId, 86400, '203.0.113.10', 'Phone/1.0');
		$this->assertCount(2, $this->rows());

		// The same path a password change, a 2FA change or a deactivation takes.
		$this->assertTrue(UserRepository::invalidateAccess($this->userId));

		$this->assertSame([], $this->rows(), 'a token outliving the password would be a bypass');
	}

	public function testDeletingTheAccountTakesItsTokensWithIt(): void
	{
		RememberTokenRepository::issue($this->userId, 86400, '203.0.113.9', 'Laptop/1.0');
		$this->assertCount(1, $this->rows());

		$this->assertTrue(Database::deleteUser($this->userId));

		$this->assertSame([], $this->rows(), 'the foreign key has to carry this, not the caller');
	}

	public function testTheDeviceListShowsLiveTokensAndRevocationClearsThem(): void
	{
		RememberTokenRepository::issue($this->userId, 86400, '203.0.113.9', 'Laptop/1.0');
		RememberTokenRepository::issue($this->userId, 86400, '203.0.113.10', 'Phone/1.0');

		$devices = RememberTokenRepository::devices($this->userId);
		$this->assertCount(2, $devices);
		$this->assertSame(
			['Phone/1.0', 'Laptop/1.0'],
			array_column($devices, 'user_agent'),
			'most recently used first'
		);
		foreach ($devices as $device) {
			$this->assertArrayNotHasKey('token_hash', $device, 'the list must not expose the secret');
			$this->assertArrayNotHasKey('series', $device);
		}

		$this->assertSame(2, RememberTokenRepository::forgetUser($this->userId));
		$this->assertSame([], RememberTokenRepository::devices($this->userId));
	}

	/**
	 * The emergency button spares the device pressing it.
	 *
	 * Whoever clicks has lost a laptop and is holding a phone. Signing the phone out too would
	 * cost a password prompt on the one device that is definitely not the problem, so the
	 * current series survives and everything else goes.
	 */
	public function testSigningOtherDevicesOutKeepsTheOneAsking(): void
	{
		$here = RememberTokenRepository::issue($this->userId, 86400, '203.0.113.9', 'Phone/1.0');
		RememberTokenRepository::issue($this->userId, 86400, '198.51.100.7', 'LostLaptop/1.0');
		RememberTokenRepository::issue($this->userId, 86400, '198.51.100.8', 'OldTablet/1.0');

		$listed = RememberTokenRepository::devices($this->userId, $here);
		$this->assertCount(3, $listed);
		$current = array_values(array_filter($listed, static fn(array $row): bool => $row['current']));
		$this->assertCount(1, $current, 'exactly one row is this browser');
		$this->assertSame('Phone/1.0', $current[0]['user_agent']);
		foreach ($listed as $row) {
			$this->assertArrayNotHasKey('series', $row, 'the list must not leak the series');
		}

		$this->assertSame(2, RememberTokenRepository::forgetOthers($this->userId, $here));

		$left = $this->rows();
		$this->assertCount(1, $left);
		$this->assertSame('Phone/1.0', $left[0]['user_agent']);
		[$userId] = RememberTokenRepository::consume($here, '203.0.113.9', 'Phone/1.0');
		$this->assertSame($this->userId, $userId, 'and it still works');
	}

	/** A session-only sign-in has nothing to spare, so the button clears everything. */
	public function testWithoutACurrentTokenEverythingIsRevoked(): void
	{
		RememberTokenRepository::issue($this->userId, 86400, '198.51.100.7', 'LostLaptop/1.0');
		RememberTokenRepository::issue($this->userId, 86400, '198.51.100.8', 'OldTablet/1.0');

		$this->assertSame(2, RememberTokenRepository::forgetOthers($this->userId, ''));
		$this->assertSame([], $this->rows());
	}

	public function testTurningTheFeatureOffCanRevokeEveryOutstandingToken(): void
	{
		RememberTokenRepository::issue($this->userId, 86400, '203.0.113.9', 'Laptop/1.0');
		$this->assertSame(1, RememberTokenRepository::forgetAll());
		$this->assertSame([], $this->rows());
	}

	public function testTheOldestDevicesFallOffPastTheCeiling(): void
	{
		for ($index = 0; $index < 12; $index++) {
			RememberTokenRepository::issue($this->userId, 86400, '203.0.113.9', 'Device' . $index);
			// The trim keeps the most recently used, so the ordering has to be distinguishable.
			Database::getInstance()->exec(
				"UPDATE `" . Database::table('remember_tokens') . "`
				 SET `last_used_at` = " . (time() - 100 + $index) . " WHERE `user_agent` = 'Device{$index}'"
			);
		}
		RememberTokenRepository::prune($this->userId);

		$agents = array_column($this->rows(), 'user_agent');
		$this->assertCount(10, $agents, 'a device list is not an append-only log');
		$this->assertNotContains('Device0', $agents);
		$this->assertContains('Device11', $agents);
	}

	public function testThePanelGateAppliesToStaffAndExpiresWhenIdle(): void
	{
		$admin = ['id' => 1, 'role' => 'admin'];
		$plain = ['id' => 2, 'role' => 'user'];

		$this->assertTrue(panelReauthApplies($admin));
		$this->assertFalse(panelReauthApplies($plain), 'the default scope is staff only');

		Database::setSetting('panel_reauth_scope', 'all');
		$this->assertTrue(panelReauthApplies($plain));
		Database::setSetting('panel_reauth_scope', 'staff');

		$_SESSION['panel_auth_at'] = time();
		$this->assertTrue(panelAuthorizationValid($admin));

		$_SESSION['panel_auth_at'] = time() - panelReauthWindow() - 1;
		$this->assertFalse(panelAuthorizationValid($admin), 'an idle window has to close');
		$this->assertTrue(panelAuthorizationValid($plain), 'without ever touching a plain user');

		// Working in the panel pushes it forward again.
		touchPanelAuthorization();
		$this->assertTrue(panelAuthorizationValid($admin));
	}

	public function testASessionWithNoPasswordBehindItNeverOpensThePanel(): void
	{
		$admin = ['id' => 1, 'role' => 'admin'];

		// Exactly the state SessionAuth leaves behind after restoring from a cookie: signed in,
		// with no record of a credential check.
		$_SESSION = ['user_id' => 1, 'restored_from_cookie_at' => time()];

		$this->assertFalse(panelAuthorizationValid($admin));
		$this->assertFalse(hasRecentAuthentication(), 'nor may it satisfy a destructive action');
	}

	public function testTheGateCanBeTurnedOffEntirely(): void
	{
		Database::setSetting('panel_reauth_minutes', 0);
		$_SESSION = [];

		$this->assertSame(0, panelReauthWindow());
		$this->assertFalse(panelReauthApplies(['id' => 1, 'role' => 'admin']));
		$this->assertTrue(panelAuthorizationValid(['id' => 1, 'role' => 'admin']));
	}
}
