<?php

/**
 * RecoveryCodeRepository: single-use 2FA fallback codes — issued as a batch, spendable once,
 * and tolerant of how the user retypes them.
 */
final class RecoveryCodeRepositoryTest extends RepoTestCase
{
	private int $userId;

	protected function setUp(): void
	{
		$this->truncate('totp_recovery_codes', 'users');
		$pdo = Database::getInstance();
		$pdo->prepare("INSERT INTO `" . Database::table('users') . "` (username,email,password_hash,role,is_active,created_at) VALUES (?,?,?,?,1,?)")
			->execute(['rcuser', 'rc@u.pl', 'x', 'user', time()]);
		$this->userId = (int) $pdo->lastInsertId();
	}

	public function testRegenerateIssuesABatch(): void
	{
		$codes = Database::regenerateRecoveryCodes($this->userId);
		$this->assertCount(RecoveryCodeRepository::BATCH_SIZE, $codes);
		$this->assertSame(RecoveryCodeRepository::BATCH_SIZE, Database::countRecoveryCodes($this->userId));
		$this->assertSame(count($codes), count(array_unique($codes)), 'codes must not repeat');
	}

	public function testCodesAreStoredHashedNotInPlaintext(): void
	{
		$codes = Database::regenerateRecoveryCodes($this->userId);
		$stored = Database::getInstance()
			->query("SELECT `code_hash` FROM `" . Database::table('totp_recovery_codes') . "`")
			->fetchAll(PDO::FETCH_COLUMN);

		foreach ($stored as $hash) {
			$this->assertNotContains($hash, $codes, 'a readable code must never reach the table');
		}
		$this->assertStringStartsWith('$2y$', $stored[0]);
	}

	public function testConsumeSpendsACodeExactlyOnce(): void
	{
		$codes = Database::regenerateRecoveryCodes($this->userId);

		$this->assertTrue(Database::consumeRecoveryCode($this->userId, $codes[0]));
		$this->assertSame(RecoveryCodeRepository::BATCH_SIZE - 1, Database::countRecoveryCodes($this->userId));

		// The same code must not work a second time.
		$this->assertFalse(Database::consumeRecoveryCode($this->userId, $codes[0]));
		$this->assertSame(RecoveryCodeRepository::BATCH_SIZE - 1, Database::countRecoveryCodes($this->userId));
	}

	public function testConsumeAcceptsSloppyFormatting(): void
	{
		$codes = Database::regenerateRecoveryCodes($this->userId);
		// Lower case, dash swapped for a space, padded — all the same code to a human.
		$sloppy = '  ' . strtolower(str_replace('-', ' ', $codes[0])) . '  ';
		$this->assertTrue(Database::consumeRecoveryCode($this->userId, $sloppy));
	}

	public function testConsumeRejectsUnknownCode(): void
	{
		Database::regenerateRecoveryCodes($this->userId);
		$this->assertFalse(Database::consumeRecoveryCode($this->userId, 'AAAA-BBBB'));
		$this->assertFalse(Database::consumeRecoveryCode($this->userId, ''));
	}

	public function testCodesAreScopedToTheirUser(): void
	{
		$codes = Database::regenerateRecoveryCodes($this->userId);

		$pdo = Database::getInstance();
		$pdo->prepare("INSERT INTO `" . Database::table('users') . "` (username,email,password_hash,role,is_active,created_at) VALUES (?,?,?,?,1,?)")
			->execute(['other', 'o@u.pl', 'x', 'user', time()]);
		$otherId = (int) $pdo->lastInsertId();

		$this->assertFalse(Database::consumeRecoveryCode($otherId, $codes[0]));
	}

	public function testRegenerateInvalidatesThePreviousBatch(): void
	{
		$old = Database::regenerateRecoveryCodes($this->userId);
		$new = Database::regenerateRecoveryCodes($this->userId);

		$this->assertSame(RecoveryCodeRepository::BATCH_SIZE, Database::countRecoveryCodes($this->userId));
		$this->assertFalse(Database::consumeRecoveryCode($this->userId, $old[0]), 'old codes must stop working');
		$this->assertTrue(Database::consumeRecoveryCode($this->userId, $new[0]));
	}

	public function testClearRemovesEverything(): void
	{
		Database::regenerateRecoveryCodes($this->userId);
		Database::clearRecoveryCodes($this->userId);
		$this->assertSame(0, Database::countRecoveryCodes($this->userId));
	}
}
