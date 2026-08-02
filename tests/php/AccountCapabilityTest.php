<?php

final class AccountCapabilityTest extends RepoTestCase
{
	protected function setUp(): void
	{
		$this->truncate(
			'api_keys',
			'upload_tokens',
			'download_tokens',
			'recovery_tokens',
			'email_reservations',
			'users'
		);
	}

	private function insertUser(
		int $id,
		string $email,
		int $active,
		array $extra = []
	): void {
		$columns = [
			'id' => $id,
			'username' => 'cap-user-' . $id,
			'email' => $email,
			'password_hash' => password_hash('Passw0rd!', PASSWORD_DEFAULT),
			'role' => 'user',
			'is_active' => $active,
			'session_version' => 1,
			'created_at' => time() - 3600,
		] + $extra;
		$sql = 'INSERT INTO `' . Database::table('users') . '` (`'
			. implode('`,`', array_keys($columns)) . '`) VALUES ('
			. implode(',', array_fill(0, count($columns), '?')) . ')';
		Database::getInstance()->prepare($sql)->execute(array_values($columns));
	}

	public function testActivationTokenIsHashedExpiringAndSingleUse(): void
	{
		$token = bin2hex(random_bytes(32));
		$this->insertUser(101, 'activate@example.test', 0, [
			'activation_token' => hash('sha256', $token),
			'activation_expires_at' => time() + 300,
		]);

		$this->assertTrue(Database::verifyUserByToken($token)['success']);
		$this->assertFalse(Database::verifyUserByToken($token)['success']);
		$row = Database::getInstance()->query(
			'SELECT `is_active`,`activation_token`,`activation_expires_at`,`session_version` '
			. 'FROM `' . Database::table('users') . '` WHERE `id` = 101'
		)->fetch(PDO::FETCH_ASSOC);
		$this->assertSame(1, (int) $row['is_active']);
		$this->assertNull($row['activation_token']);
		$this->assertNull($row['activation_expires_at']);
		$this->assertSame(2, (int) $row['session_version']);
	}

	public function testExpiredActivationTokenCannotBeClaimed(): void
	{
		$token = bin2hex(random_bytes(32));
		$this->insertUser(102, 'expired@example.test', 0, [
			'activation_token' => hash('sha256', $token),
			'activation_expires_at' => time() - 1,
		]);
		$this->assertFalse(Database::verifyUserByToken($token)['success']);
	}

	public function testEmailChangeClaimsOnceAndRevokesCredentials(): void
	{
		$token = bin2hex(random_bytes(32));
		$this->insertUser(103, 'old@example.test', 1, [
			'pending_email' => 'new@example.test',
			'email_change_token' => hash('sha256', $token),
			'email_change_expires_at' => time() + 300,
		]);
		Database::getInstance()->prepare(
			'INSERT INTO `' . Database::table('api_keys') . '`
			 (`user_id`,`key_hash`,`key_prefix`,`label`,`created_at`) VALUES (?,?,?,?,?)'
		)->execute([103, hash('sha256', 'old-key'), 'old-key', 'old', time()]);

		$result = Database::confirmEmailChange($token);
		$this->assertTrue($result['success']);
		$this->assertSame('new@example.test', $result['email']);
		$this->assertFalse(Database::confirmEmailChange($token)['success']);

		$row = Database::getInstance()->query(
			'SELECT `email`,`pending_email`,`email_change_token`,'
			. '`email_change_expires_at`,`session_version` FROM `'
			. Database::table('users') . '` WHERE `id` = 103'
		)->fetch(PDO::FETCH_ASSOC);
		$this->assertSame('new@example.test', $row['email']);
		$this->assertNull($row['pending_email']);
		$this->assertNull($row['email_change_token']);
		$this->assertNull($row['email_change_expires_at']);
		$this->assertSame(2, (int) $row['session_version']);
		$this->assertSame(
			0,
			(int) Database::getInstance()->query(
				'SELECT COUNT(*) FROM `' . Database::table('api_keys') . '` WHERE `user_id` = 103'
			)->fetchColumn()
		);
	}
}
