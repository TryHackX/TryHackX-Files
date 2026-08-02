<?php

/**
 * FileRepository: per-file options, password protection, the race-safe one-time "burn",
 * a user's own files and their aggregate stats.
 */
final class FileRepositoryTest extends RepoTestCase
{
	protected function setUp(): void
	{
		$this->truncate('files', 'users');
		$stmt = Database::getInstance()->prepare(
			'INSERT INTO `' . Database::table('users') . '`
			 (`id`,`username`,`email`,`password_hash`,`role`,`is_active`,`created_at`)
			 VALUES (?,?,?,?,?,?,?)'
		);
		$stmt->execute([5, 'file-owner-5', 'file5@example.test', 'x', 'user', 1, time()]);
		$stmt->execute([6, 'file-owner-6', 'file6@example.test', 'x', 'user', 1, time()]);
	}

	public function testPasswordProtection(): void
	{
		$this->insertFile('f_pw');
		$this->assertFalse(Database::fileIsProtected('f_pw'));

		$this->assertTrue((bool) Database::setFilePassword('f_pw', 'Secret123'));
		$this->assertTrue(Database::fileIsProtected('f_pw'));
		$this->assertTrue(Database::verifyFilePassword('f_pw', 'Secret123'));
		$this->assertFalse(Database::verifyFilePassword('f_pw', 'wrong'));
		$this->assertFalse(Database::verifyFilePassword('f_pw', str_repeat('x', 1025)));

		Database::setFilePassword('f_pw', null, true); // clear
		$this->assertFalse(Database::fileIsProtected('f_pw'));
	}

	public function testSetOptionsScopedToOwner(): void
	{
		$this->insertFile('f_opt', 5);
		// Wrong owner → no rows updated.
		$this->assertFalse(Database::setFileOptions('f_opt', 999, time() + 3600, 3, null, false, false));
		// Correct owner → applied.
		$this->assertTrue(Database::setFileOptions('f_opt', 5, time() + 3600, 3, 'Passw0rd', false, false));
		$this->assertTrue(Database::fileIsProtected('f_opt'));
	}

	public function testOneTimeBurnIsSingleUse(): void
	{
		$this->insertFile('f_one', 5);
		Database::setFileOptions('f_one', 5, null, null, null, false, true); // arm one-time

		$this->assertFalse(Database::oneTimeConsumed('f_one'));
		$this->assertTrue(Database::claimOneTime('f_one'));   // first claim wins
		$this->assertFalse(Database::claimOneTime('f_one'));  // already burned
		$this->assertTrue(Database::oneTimeConsumed('f_one'));
	}

	public function testClaimOneTimeIsNoopForNormalFile(): void
	{
		$this->insertFile('f_normal');
		$this->assertTrue(Database::claimOneTime('f_normal')); // nothing to burn → allowed
		$this->assertTrue(Database::claimOneTime('f_normal'));
	}

	public function testAllPhpReadModesShareExpiryPasswordCapAndOneTimePolicy(): void
	{
		$this->insertFile('f_policy', 5, ['downloads' => 2]);
		Database::setFileOptions(
			'f_policy',
			5,
			time() + 3600,
			3,
			'Secret123',
			false,
			true
		);

		$denied = FileManager::authorizeFileRead('f_policy', 'preview', false);
		$this->assertFalse($denied['allowed']);
		$this->assertSame(403, $denied['status']);

		$preview = FileManager::authorizeFileRead('f_policy', 'preview', true);
		$this->assertTrue($preview['allowed']);
		$this->assertFalse(Database::oneTimeConsumed('f_policy'));

		$download = FileManager::authorizeFileRead('f_policy', 'download', true);
		$this->assertTrue($download['allowed']);
		$this->assertTrue(Database::oneTimeConsumed('f_policy'));

		$afterBurn = FileManager::authorizeFileRead('f_policy', 'preview', true);
		$this->assertFalse($afterBurn['allowed']);
		$this->assertSame(410, $afterBurn['status']);
	}

	public function testReadPolicyRefusesExpiredAndSpentFiles(): void
	{
		$this->insertFile('f_expired', 5);
		Database::setFileOptions('f_expired', 5, time() - 1, null, null);
		$this->assertSame(
			410,
			FileManager::authorizeFileRead('f_expired', 'preview', true)['status']
		);

		$this->insertFile('f_spent', 5, ['downloads' => 4]);
		Database::setFileOptions('f_spent', 5, null, 4, null);
		$this->assertSame(
			410,
			FileManager::authorizeFileRead('f_spent', 'preview', true)['status']
		);
	}

	public function testUserFilesAndStats(): void
	{
		$this->insertFile('f_a', 5, ['size' => 100, 'downloads' => 2]);
		$this->insertFile('f_b', 5, ['size' => 250, 'downloads' => 3]);
		$this->insertFile('f_c', 6, ['size' => 999]); // another user

		$this->assertCount(2, Database::getUserFiles(5));
		$this->assertSame('f_a', Database::getUserFileById(5, 'f_a')['id']);
		$this->assertNull(Database::getUserFileById(5, 'f_c')); // not owner

		$stats = Database::getUserStats(5);
		$this->assertSame(2, $stats['files_count']);
		$this->assertSame(350.0, $stats['total_size']);
		$this->assertSame(5, $stats['total_downloads']);
	}
}
