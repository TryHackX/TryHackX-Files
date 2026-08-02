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
}
