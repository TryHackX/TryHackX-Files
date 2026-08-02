<?php

final class FileQuarantineTest extends RepoTestCase
{
	private array $ids = [];

	protected function setUp(): void
	{
		$this->truncate('file_quarantine', 'file_deletion_queue', 'reports', 'collection_files', 'files');
		Database::setSetting('file_quarantine_days', '2');
		Database::invalidateSettingsCache();
	}

	protected function tearDown(): void
	{
		putenv('FILEHOST_TEST_QUARANTINE_FAIL_STAGE');
		Database::setSetting('file_quarantine_days', '0');
		Database::invalidateSettingsCache();
		foreach ($this->ids as $id) {
			$this->removeTree(UPLOADS_DIR . '/' . $id);
			$this->removeTree(UPLOADS_DIR . '/' . $id . '.restore-partial');
			$this->removeTree(DATA_DIR . '/file-quarantine/' . $id);
			$this->removeTree(DATA_DIR . '/file-quarantine/' . $id . '.partial');
			@unlink(DATA_DIR . '/thumbs/' . $id . '.jpg');
		}
	}

	private function removeTree(string $path): void
	{
		if (is_file($path) || is_link($path)) {
			@unlink($path);
			return;
		}
		if (!is_dir($path)) {
			return;
		}
		foreach (scandir($path) ?: [] as $entry) {
			if ($entry !== '.' && $entry !== '..') {
				$this->removeTree($path . '/' . $entry);
			}
		}
		@rmdir($path);
	}

	private function fixture(string $suffix): string
	{
		$id = 'q_' . $suffix . '_' . substr(hash('sha256', uniqid('', true)), 0, 12);
		$this->ids[] = $id;
		$directory = UPLOADS_DIR . '/' . $id;
		mkdir($directory, 0777, true);
		file_put_contents($directory . '/payload.bin', 'recoverable-bytes');
		$this->insertFile($id, null, [
			'original_name' => 'payload.bin',
			'size' => 17,
			'downloads' => 7,
		]);
		return $id;
	}

	public function testDeleteRestoreAndExplicitPurgeAreRecoverableAndIdempotent(): void
	{
		$id = $this->fixture('roundtrip');
		$thumbDir = DATA_DIR . '/thumbs';
		if (!is_dir($thumbDir)) {
			mkdir($thumbDir, 0777, true);
		}
		file_put_contents($thumbDir . '/' . $id . '.jpg', 'thumbnail');

		$this->assertTrue(FileManager::deleteFileAdmin($id));
		$this->assertNull(FileManager::getFile($id));
		$this->assertDirectoryDoesNotExist(UPLOADS_DIR . '/' . $id);
		$row = FileQuarantine::find($id);
		$this->assertNotNull($row);
		$this->assertSame('quarantined', $row['state']);
		$this->assertSame('admin_delete', $row['reason']);
		$this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $row['checksum']);
		$this->assertFileExists(DATA_DIR . '/file-quarantine/' . $id . '/manifest.json');

		$this->assertTrue(FileQuarantine::restore($id));
		$this->assertTrue(FileQuarantine::restore($id), 'A completed restore must be idempotent.');
		$file = FileManager::getFile($id);
		$this->assertNotNull($file);
		$this->assertSame(7, $file['downloads']);
		$this->assertSame('recoverable-bytes', file_get_contents($file['path']));
		$this->assertFileExists(DATA_DIR . '/thumbs/' . $id . '.jpg');
		$this->assertSame('restored', FileQuarantine::find($id)['state']);

		$this->assertTrue(FileQuarantine::purge($id));
		$this->assertNull(FileQuarantine::find($id));
		$this->assertNotNull(FileManager::getFile($id), 'Purging the audit bundle must not delete a restored live file.');
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('captureFailureStages')]
	public function testCaptureRetriesAfterEveryPublicationBoundary(string $stage): void
	{
		$id = $this->fixture($stage);
		putenv("FILEHOST_TEST_QUARANTINE_FAIL_STAGE={$stage}");

		$this->assertTrue(FileManager::deleteFileAdmin($id));
		$this->assertDirectoryExists(
			UPLOADS_DIR . '/' . $id,
			'The live bytes must remain until quarantine publication succeeds.'
		);
		$this->assertNotNull(FileQuarantine::find($id));
		$queue = Database::getInstance()->prepare(
			"SELECT `file_id` FROM `" . Database::table('file_deletion_queue') . "`
			 WHERE `file_id` = ?"
		);
		$queue->execute([$id]);
		$this->assertSame($id, $queue->fetchColumn());

		putenv('FILEHOST_TEST_QUARANTINE_FAIL_STAGE');
		Database::getInstance()->prepare(
			"UPDATE `" . Database::table('file_deletion_queue') . "`
			 SET `next_attempt_at` = 0 WHERE `file_id` = ?"
		)->execute([$id]);
		$result = FileManager::processDeletionQueue(1);
		$this->assertSame(1, $result['quarantined']);
		$this->assertSame(0, $result['failed']);
		$this->assertDirectoryDoesNotExist(UPLOADS_DIR . '/' . $id);
		$this->assertSame('quarantined', FileQuarantine::find($id)['state']);
	}

	public static function captureFailureStages(): array
	{
		return [['copy'], ['manifest'], ['publish']];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('restoreFailureStages')]
	public function testRestoreRollsBackAfterEveryPublicationBoundary(string $stage): void
	{
		$id = $this->fixture($stage);
		$this->assertTrue(FileManager::deleteFileAdmin($id));
		putenv("FILEHOST_TEST_QUARANTINE_FAIL_STAGE={$stage}");

		$this->assertFalse(FileQuarantine::restore($id));
		$this->assertNull(FileManager::getFile($id));
		$this->assertDirectoryDoesNotExist(UPLOADS_DIR . '/' . $id);
		$this->assertSame('quarantined', FileQuarantine::find($id)['state']);

		putenv('FILEHOST_TEST_QUARANTINE_FAIL_STAGE');
		$this->assertTrue(FileQuarantine::restore($id));
		$this->assertNotNull(FileManager::getFile($id));
	}

	public static function restoreFailureStages(): array
	{
		return [['restore_copy'], ['restore_metadata']];
	}

	public function testRestoreRefusesToOverwriteDifferentLiveBytes(): void
	{
		$id = $this->fixture('collision');
		$this->assertTrue(FileManager::deleteFileAdmin($id));

		mkdir(UPLOADS_DIR . '/' . $id, 0777, true);
		file_put_contents(UPLOADS_DIR . '/' . $id . '/different.bin', 'different');
		$this->assertFalse(FileQuarantine::restore($id));
		$this->assertNull(FileManager::getFile($id));
		$this->assertSame(
			'different',
			file_get_contents(UPLOADS_DIR . '/' . $id . '/different.bin')
		);
	}

	public function testExpiryWorkerPermanentlyPurgesOnlyDueBundles(): void
	{
		$id = $this->fixture('expiry');
		$this->assertTrue(FileManager::deleteFileAdmin($id));
		Database::getInstance()->prepare(
			"UPDATE `" . Database::table('file_quarantine') . "`
			 SET `quarantine_until` = 0 WHERE `file_id` = ?"
		)->execute([$id]);

		$result = FileQuarantine::purgeExpired(10);
		$this->assertSame(
			['processed' => 1, 'purged' => 1, 'failed' => 0],
			$result
		);
		$this->assertNull(FileQuarantine::find($id));
		$this->assertDirectoryDoesNotExist(DATA_DIR . '/file-quarantine/' . $id);
	}

	public function testAccountDeletionBypassesQuarantine(): void
	{
		$ownerName = 'q-owner-' . bin2hex(random_bytes(4));
		$this->assertTrue(Database::registerUser(
			$ownerName,
			$ownerName . '@example.test',
			'Str0ng!pass'
		)['success']);
		$name = 'q-user-' . bin2hex(random_bytes(4));
		$registered = Database::registerUser($name, $name . '@example.test', 'Str0ng!pass');
		$this->assertTrue($registered['success']);
		$userQuery = Database::getInstance()->prepare(
			"SELECT `id` FROM `" . Database::table('users') . "` WHERE `username` = ?"
		);
		$userQuery->execute([$name]);
		$userId = (int) $userQuery->fetchColumn();
		$id = $this->fixture('account');
		Database::getInstance()->prepare(
			"UPDATE `" . Database::table('files') . "` SET `user_id` = ? WHERE `id` = ?"
		)->execute([$userId, $id]);

		$this->assertTrue(UserRepository::delete($userId));
		FileManager::processDeletionQueue(10);
		$this->assertNull(FileQuarantine::find($id));
		$this->assertDirectoryDoesNotExist(UPLOADS_DIR . '/' . $id);
	}
}
