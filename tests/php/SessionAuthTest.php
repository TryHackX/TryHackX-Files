<?php

final class SessionAuthTest extends RepoTestCase
{
	protected function setUp(): void
	{
		$_SESSION = [];
		$this->truncate('api_keys', 'upload_tokens', 'download_tokens', 'recovery_tokens', 'users');
		Database::invalidateSettingsCache();
	}

	private function user(): array
	{
		$this->assertTrue(
			Database::registerUser('sessionuser', 'session@example.test', 'Passw0rd!')['success']
		);
		return Database::getUserByEmailOrUsername('sessionuser');
	}

	public function testEstablishedSessionIsReadBackFromAuthoritativeUserRow(): void
	{
		$user = $this->user();
		SessionAuth::establish($user);

		$current = SessionAuth::current();
		$this->assertNotNull($current);
		$this->assertSame((int) $user['id'], $current['id']);
		$this->assertSame('user', $_SESSION['user_role']);
		$this->assertFalse($_SESSION['is_admin']);
		$this->assertSame((int) $user['session_version'], $_SESSION['auth_version']);
	}

	public function testSecurityVersionChangeInvalidatesAnAlreadyOpenBrowserSession(): void
	{
		$user = $this->user();
		SessionAuth::establish($user);

		$this->assertTrue(
			Database::adminUpdateUser((int) $user['id'], ['role' => 'moderator'])['success']
		);
		$this->assertNull(SessionAuth::current());
		$this->assertArrayNotHasKey('user_id', $_SESSION);
		$this->assertArrayNotHasKey('is_admin', $_SESSION);
	}

	public function testInactiveAccountIsRejectedEvenWithMatchingVersion(): void
	{
		$user = $this->user();
		SessionAuth::establish($user);
		$pdo = Database::getInstance();
		$pdo->prepare("UPDATE `" . Database::table('users') . "` SET `is_active` = 0 WHERE `id` = ?")
			->execute([(int) $user['id']]);

		$this->assertNull(SessionAuth::current());
		$this->assertArrayNotHasKey('user_id', $_SESSION);
	}

	public function testRoleFlagsAreSynchronizedFromDatabaseRatherThanTrustedFromSession(): void
	{
		$user = $this->user();
		SessionAuth::establish($user);
		$_SESSION['is_admin'] = true;

		$current = SessionAuth::current();
		$this->assertNotNull($current);
		$this->assertFalse($current['is_admin']);
		$this->assertFalse($_SESSION['is_admin']);
		$this->assertSame('user', $_SESSION['user_role']);
	}

	/**
	 * A database that cannot answer makes the request anonymous — and nothing more. It used to
	 * tear the session down and delete the device's "stay signed in" token, so a database
	 * restart signed out everyone who loaded a page during it.
	 */
	public function testAnUnanswerableDatabaseDoesNotDestroyTheSessionOrTheDeviceToken(): void
	{
		$user = $this->user();
		$this->truncate('remember_tokens');
		Database::setSetting('login_remember_enabled', '1');
		$cookie = RememberTokenRepository::issue((int) $user['id'], 86400, '203.0.113.9', 'Test/1.0');
		$this->assertNotSame('', $cookie);
		$_COOKIE[RememberTokenRepository::COOKIE] = $cookie;
		SessionAuth::establish($user);
		$_SESSION['is_admin'] = true; // a stale privilege flag must not survive the outage

		// A connection whose `users` table does not exist: every lookup fails.
		$instance = new ReflectionProperty(Database::class, 'instance');
		$working = $instance->getValue();
		$broken = new PDO(
			'mysql:host=' . DB_HOST . ';dbname=information_schema;charset=utf8mb4',
			DB_USER,
			DB_PASS,
			[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
		);
		try {
			$instance->setValue(null, $broken);
			$this->assertNull(SessionAuth::current());
			$this->assertArrayNotHasKey('user_id', $_SESSION, 'nothing in this request may use it');
			$this->assertArrayNotHasKey('is_admin', $_SESSION);
		} finally {
			$instance->setValue(null, $working);
			unset($_COOKIE[RememberTokenRepository::COOKIE]);
		}

		$tokens = (int) Database::getInstance()->query(
			"SELECT COUNT(*) FROM `" . Database::table('remember_tokens') . "`"
		)->fetchColumn();
		$this->assertSame(1, $tokens, 'the device token is still there for the next request');
	}
}
