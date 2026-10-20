<?php

/**
 * A file the Python upload server deletes after its last allowed download must be as
 * recoverable as one PHP deletes.
 *
 * upload_server.py (delete_file_after_limit) writes the quarantine row itself, in the shape
 * FileQuarantine::enqueue() uses; tests/python/test_upload_server.py pins its manifest to
 * tests/fixtures/sidecar-quarantine.json. This test writes that very row with the sidecar's
 * statements and restores the file from it through PHP's own deletion worker and restore.
 */
final class SidecarQuarantineContractTest extends RepoTestCase
{
	/** @var array<string, mixed> */
	private array $fixture = [];

	protected function setUp(): void
	{
		$this->truncate(
			'file_quarantine', 'file_deletion_queue', 'reports', 'collection_files', 'collections', 'files'
		);
		$raw = file_get_contents(PROJECT_ROOT . '/tests/fixtures/sidecar-quarantine.json');
		$this->fixture = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
	}

	protected function tearDown(): void
	{
		$id = (string) ($this->fixture['file_row']['id'] ?? '');
		if ($id !== '') {
			$this->removeTree(UPLOADS_DIR . '/' . $id);
			$this->removeTree(UPLOADS_DIR . '/' . $id . '.restore-partial');
			$this->removeTree(DATA_DIR . '/file-quarantine/' . $id);
			$this->removeTree(DATA_DIR . '/file-quarantine/' . $id . '.partial');
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

	public function testAFileTheSidecarDeletedAfterItsLimitIsRestoredFromItsManifest(): void
	{
		$row = $this->fixture['file_row'];
		$id = (string) $row['id'];
		$membership = $this->fixture['memberships'][0];
		$pdo = Database::getInstance();

		$this->insertCollection((string) $membership['collection_id'], 'Contract');
		$this->insertFile($id, null, $row);
		$pdo->prepare(
			'INSERT INTO `' . Database::table('collection_files') . '` (`collection_id`, `file_id`, `position`) VALUES (?, ?, ?)'
		)->execute([$membership['collection_id'], $id, $membership['position']]);
		mkdir(UPLOADS_DIR . '/' . $id, 0777, true);
		file_put_contents(UPLOADS_DIR . '/' . $id . '/payload.bin', 'recoverable-bytes');

		// delete_file_after_limit(): the same statements, in the same order, in one transaction.
		$now = time();
		$pdo->beginTransaction();
		$pdo->prepare(
			'INSERT INTO `' . Database::table('file_quarantine') . '`
			 (`file_id`, `manifest_json`, `reason`, `actor_type`, `actor_id`, `size`, `checksum`, `state`,
			  `quarantine_until`, `attempts`, `next_attempt_at`, `last_error`, `created_at`, `updated_at`)
			 VALUES (?, ?, ?, ?, ?, ?, NULL, \'pending\', ?, 0, 0, NULL, ?, ?)'
		)->execute([
			$id, $this->fixture['manifest_json'], 'download_limit', 'system', null, (int) $row['size'],
			$now + 2 * 86400, $now, $now,
		]);
		$pdo->prepare('DELETE FROM `' . Database::table('collection_files') . '` WHERE `file_id` = ?')
			->execute([$id]);
		$pdo->prepare('DELETE FROM `' . Database::table('reports') . '` WHERE `file_id` = ?')->execute([$id]);
		$pdo->prepare(
			'INSERT INTO `' . Database::table('file_deletion_queue') . '`
			 (`file_id`, `attempts`, `next_attempt_at`, `last_error`, `created_at`) VALUES (?, 0, 0, NULL, ?)'
		)->execute([$id, $now]);
		$pdo->prepare('DELETE FROM `' . Database::table('files') . '` WHERE `id` = ?')->execute([$id]);
		$pdo->commit();

		$result = FileManager::processDeletionQueue(10);
		$this->assertSame(1, $result['quarantined'], 'the bytes were copied to quarantine, not purged');
		$this->assertDirectoryDoesNotExist(UPLOADS_DIR . '/' . $id);
		$this->assertSame('quarantined', FileQuarantine::find($id)['state'] ?? null);

		$this->assertTrue(FileQuarantine::restore($id));
		$stmt = $pdo->prepare('SELECT * FROM `' . Database::table('files') . '` WHERE `id` = ?');
		$stmt->execute([$id]);
		$file = $stmt->fetch(PDO::FETCH_ASSOC);
		$this->assertIsArray($file);
		$this->assertSame($row['original_name'], $file['original_name']);
		$this->assertSame($row['uploaded_ip'], $file['uploaded_ip']);
		$this->assertSame(3, (int) $file['downloads']);
		$this->assertSame(3, (int) $file['max_downloads']);
		$this->assertSame('delete', $file['on_limit_action']);
		$this->assertSame('recoverable-bytes', file_get_contents(UPLOADS_DIR . '/' . $id . '/payload.bin'));
		$position = $pdo->prepare(
			'SELECT `position` FROM `' . Database::table('collection_files') . '` WHERE `collection_id` = ? AND `file_id` = ?'
		);
		$position->execute([$membership['collection_id'], $id]);
		$this->assertSame(2, (int) $position->fetchColumn(), 'the collection membership comes back too');
	}
}
