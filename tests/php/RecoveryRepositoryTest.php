<?php

/**
 * RecoveryRepository: per-IP attempt throttle + short-lived single-user reset tokens.
 */
final class RecoveryRepositoryTest extends RepoTestCase
{
	protected function setUp(): void
	{
		$this->truncate('recovery_attempts', 'recovery_tokens', 'users');
		$stmt = Database::getInstance()->prepare(
			'INSERT INTO `' . Database::table('users') . '`
			 (`id`,`username`,`email`,`password_hash`,`role`,`is_active`,`created_at`)
			 VALUES (?,?,?,?,?,?,?)'
		);
		$stmt->execute([77, 'recovery-77', 'recovery77@example.test', 'x', 'user', 1, time()]);
		$stmt->execute([88, 'recovery-88', 'recovery88@example.test', 'x', 'user', 1, time()]);
	}

	public function testAttemptCounting(): void
	{
		$this->assertSame(0, Database::getRecoveryAttemptsCount('203.0.113.9', 48));
		Database::logRecoveryAttempt('203.0.113.9');
		Database::logRecoveryAttempt('203.0.113.9');
		$this->assertSame(2, Database::getRecoveryAttemptsCount('203.0.113.9', 48));
		$this->assertSame(0, Database::getRecoveryAttemptsCount('203.0.113.10', 48)); // different IP
	}

	/** Only the token's hash is stored, and the link it is mailed in works once. */
	public function testTokenIsStoredHashedAndResetsThePasswordOnce(): void
	{
		$token = (string) Database::createRecoveryToken(77);
		$this->assertNotSame('', $token);
		$stored = Database::getInstance()
			->query('SELECT `token` FROM `' . Database::table('recovery_tokens') . '`')
			->fetchColumn();
		$this->assertSame(hash('sha256', $token), $stored);
		$this->assertNotSame($token, $stored);

		$this->assertTrue(Database::consumeRecoveryTokenAndResetPassword($token, 'NewPass2!'));
		$this->assertFalse(Database::consumeRecoveryTokenAndResetPassword($token, 'OtherPass3!'));
	}

	public function testOnlyOneLiveTokenPerUser(): void
	{
		$first = (string) Database::createRecoveryToken(88);
		$second = (string) Database::createRecoveryToken(88); // replaces the first

		$this->assertFalse(Database::consumeRecoveryTokenAndResetPassword($first, 'NewPass2!'));
		$this->assertTrue(Database::consumeRecoveryTokenAndResetPassword($second, 'NewPass2!'));
	}

	public function testUnknownTokenResetsNothing(): void
	{
		$this->assertFalse(Database::consumeRecoveryTokenAndResetPassword('deadbeef', 'NewPass2!'));
	}

	public function testResetClaimsTokenOnceAndRevokesEveryCredential(): void
	{
		$pdo = Database::getInstance();
		$this->truncate('api_keys', 'download_tokens', 'upload_tokens', 'recovery_tokens', 'users');
		$pdo->prepare(
			'INSERT INTO `' . Database::table('users') . '`
			 (`id`,`username`,`email`,`password_hash`,`role`,`is_active`,`session_version`,`created_at`)
			 VALUES (?,?,?,?,?,?,?,?)'
		)->execute([
			91,
			'reset-user',
			'reset@example.test',
			password_hash('OldPass1!', PASSWORD_DEFAULT),
			'user',
			1,
			1,
			time(),
		]);
		$pdo->prepare(
			'INSERT INTO `' . Database::table('api_keys') . '`
			 (`user_id`,`key_hash`,`key_prefix`,`label`,`created_at`) VALUES (?,?,?,?,?)'
		)->execute([91, hash('sha256', 'api-key'), 'api-key', 'old', time()]);

		$token = Database::createRecoveryToken(91);
		$this->assertTrue(
			Database::consumeRecoveryTokenAndResetPassword($token, 'NewPass2!')
		);
		$this->assertFalse(
			Database::consumeRecoveryTokenAndResetPassword($token, 'OtherPass3!')
		);

		$user = $pdo->query(
			'SELECT `password_hash`,`session_version` FROM `'
			. Database::table('users') . '` WHERE `id` = 91'
		)->fetch(PDO::FETCH_ASSOC);
		$this->assertTrue(password_verify('NewPass2!', $user['password_hash']));
		$this->assertSame(2, (int) $user['session_version']);
		$this->assertSame(
			0,
			(int) $pdo->query(
				'SELECT COUNT(*) FROM `' . Database::table('api_keys') . '` WHERE `user_id` = 91'
			)->fetchColumn()
		);
		$this->assertSame(
			0,
			(int) $pdo->query(
				'SELECT COUNT(*) FROM `' . Database::table('recovery_tokens') . '` WHERE `user_id` = 91'
			)->fetchColumn()
		);
	}

	/** A link issued moments ago is left alone instead of being replaced by every request. */
	public function testRecentlyIssuedLinkIsRecognisedUntilItAgesOrExpires(): void
	{
		$this->assertFalse(RecoveryRepository::issuedWithin(77, 300));
		$this->assertNotNull(Database::createRecoveryToken(77));
		$this->assertTrue(RecoveryRepository::issuedWithin(77, 300));
		$this->assertFalse(RecoveryRepository::issuedWithin(88, 300), 'per account');

		$table = Database::table('recovery_tokens');
		Database::getInstance()->exec("UPDATE `{$table}` SET `created_at` = `created_at` - 600 WHERE `user_id` = 77");
		$this->assertFalse(RecoveryRepository::issuedWithin(77, 300), 'old enough to be re-sent');

		Database::getInstance()->exec(
			"UPDATE `{$table}` SET `created_at` = " . time() . ", `expires_at` = " . (time() - 1) . " WHERE `user_id` = 77"
		);
		$this->assertFalse(RecoveryRepository::issuedWithin(77, 300), 'an expired link does not count');
	}
}
