<?php

require_once __DIR__ . '/Database.php';

/**
 * Recoverable side of file deletion.
 *
 * Relational metadata is captured in the same transaction that removes the live file row.
 * The existing deletion outbox then copies and verifies the bytes before deleting the live
 * artifact. A retention value of zero preserves the historical immediate-purge behaviour.
 */
final class FileQuarantine
{
	private const MANIFEST_VERSION = 1;
	private const RESTORED_AUDIT_DAYS = 30;

	private static function table(): string
	{
		return Database::table('file_quarantine');
	}

	public static function retentionDays(): int
	{
		return max(0, min(3650, (int) Database::getSetting('file_quarantine_days', 0)));
	}

	public static function enabled(): bool
	{
		return self::retentionDays() > 0;
	}

	private static function normalisePath(string $path): string
	{
		$path = rtrim(str_replace('\\', '/', $path), '/');
		return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
	}

	private static function root(): string
	{
		$root = defined('FILE_QUARANTINE_PATH')
			? trim((string) FILE_QUARANTINE_PATH)
			: DATA_DIR . '/file-quarantine';
		if ($root === '') {
			throw new RuntimeException('File quarantine path is empty.');
		}
		if (preg_match('#^(//|/|[A-Za-z]:[/\\\\])#', $root) !== 1) {
			$root = PROJECT_ROOT . '/' . ltrim($root, '/\\');
		}

		if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
			throw new RuntimeException('Could not create the file quarantine directory.');
		}
		$rootReal = realpath($root);
		$uploadsReal = realpath(UPLOADS_DIR);
		$publicReal = defined('PROJECT_ROOT') ? realpath(PROJECT_ROOT . '/public') : false;
		if ($rootReal === false || $uploadsReal === false) {
			throw new RuntimeException('Could not resolve the quarantine storage boundary.');
		}
		$normalRoot = self::normalisePath($rootReal);
		$normalUploads = self::normalisePath($uploadsReal);
		$overlapsUploads = $normalRoot === $normalUploads
			|| str_starts_with($normalRoot, $normalUploads . '/')
			|| str_starts_with($normalUploads, $normalRoot . '/');
		$overlapsPublic = false;
		if ($publicReal !== false) {
			$normalPublic = self::normalisePath($publicReal);
			$overlapsPublic = $normalRoot === $normalPublic
				|| str_starts_with($normalRoot, $normalPublic . '/')
				|| str_starts_with($normalPublic, $normalRoot . '/');
		}
		if ($overlapsUploads || $overlapsPublic) {
			throw new RuntimeException(
				'File quarantine must be outside live uploads and the public document root.'
			);
		}
		@chmod($root, 0700);
		return rtrim($rootReal, '/\\');
	}

	private static function path(string $id): string
	{
		if (!FileManager::isValidFileId($id)) {
			throw new InvalidArgumentException('Invalid quarantine file id.');
		}
		return self::root() . DIRECTORY_SEPARATOR . $id;
	}

	/**
	 * Capture restore metadata in the caller's deletion transaction.
	 */
	public static function enqueue(
		PDO $pdo,
		string $id,
		string $reason,
		string $actorType,
		?string $actorId = null
	): bool {
		$days = self::retentionDays();
		if ($days < 1) {
			return false;
		}

		$files = Database::table('files');
		$memberships = Database::table('collection_files');
		$fileQuery = $pdo->prepare("SELECT * FROM `{$files}` WHERE `id` = ? FOR UPDATE");
		$fileQuery->execute([$id]);
		$file = $fileQuery->fetch(PDO::FETCH_ASSOC);
		if (!$file) {
			throw new RuntimeException('Cannot quarantine a missing file row.');
		}
		$collectionQuery = $pdo->prepare(
			"SELECT `collection_id`, `position` FROM `{$memberships}`
			 WHERE `file_id` = ? ORDER BY `collection_id`, `position`"
		);
		$collectionQuery->execute([$id]);

		$reason = preg_replace('/[^a-z0-9_.-]/i', '_', trim($reason)) ?: 'unspecified';
		$actorType = preg_replace('/[^a-z0-9_.-]/i', '_', trim($actorType)) ?: 'system';
		$actorId = $actorId === null ? null : substr(trim($actorId), 0, 64);
		$now = time();
		$manifest = json_encode([
			'version' => self::MANIFEST_VERSION,
			'file' => $file,
			'collections' => $collectionQuery->fetchAll(PDO::FETCH_ASSOC),
			'deleted_at' => $now,
			'reason' => $reason,
			'actor' => ['type' => $actorType, 'id' => $actorId],
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

		$stmt = $pdo->prepare(
			"INSERT INTO `" . self::table() . "`
			 (`file_id`, `manifest_json`, `reason`, `actor_type`, `actor_id`, `size`,
			  `checksum`, `state`, `quarantine_until`, `attempts`, `next_attempt_at`,
			  `last_error`, `created_at`, `updated_at`)
			 VALUES (?, ?, ?, ?, ?, ?, NULL, 'pending', ?, 0, 0, NULL, ?, ?)
			 ON DUPLICATE KEY UPDATE
			  `manifest_json` = VALUES(`manifest_json`), `reason` = VALUES(`reason`),
			  `actor_type` = VALUES(`actor_type`), `actor_id` = VALUES(`actor_id`),
			  `size` = VALUES(`size`), `checksum` = NULL, `state` = 'pending',
			  `quarantine_until` = VALUES(`quarantine_until`), `attempts` = 0,
			  `next_attempt_at` = 0, `last_error` = NULL,
			  `created_at` = VALUES(`created_at`), `updated_at` = VALUES(`updated_at`)"
		);
		$stmt->execute([
			$id,
			$manifest,
			$reason,
			$actorType,
			$actorId,
			(int) ($file['size'] ?? 0),
			$now + ($days * 86400),
			$now,
			$now,
		]);
		return true;
	}

	/** @return array<string, mixed>|null */
	public static function find(string $id): ?array
	{
		$pdo = Database::getInstance();
		if (!$pdo || !FileManager::isValidFileId($id)) {
			return null;
		}
		$stmt = $pdo->prepare("SELECT * FROM `" . self::table() . "` WHERE `file_id` = ?");
		$stmt->execute([$id]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row ?: null;
	}

	private static function removeTree(string $path): bool
	{
		if (is_link($path) || is_file($path)) {
			return @unlink($path);
		}
		if (!is_dir($path)) {
			return true;
		}
		$entries = scandir($path);
		if (!is_array($entries)) {
			return false;
		}
		$ok = true;
		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			$ok = self::removeTree($path . DIRECTORY_SEPARATOR . $entry) && $ok;
		}
		return @rmdir($path) && $ok;
	}

	private static function copyDirectory(string $source, string $target): void
	{
		if (!is_dir($source) || is_link($source)) {
			throw new RuntimeException('The live upload directory is missing or unsafe.');
		}
		if (!mkdir($target, 0700, true) && !is_dir($target)) {
			throw new RuntimeException('Could not create a quarantine staging directory.');
		}
		$entries = scandir($source);
		if (!is_array($entries)) {
			throw new RuntimeException('Could not enumerate the live upload directory.');
		}
		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			$from = $source . DIRECTORY_SEPARATOR . $entry;
			$to = $target . DIRECTORY_SEPARATOR . $entry;
			if (!is_file($from) || is_link($from)) {
				throw new RuntimeException('Upload storage contains an unsupported entry.');
			}
			if (!copy($from, $to)) {
				throw new RuntimeException('Could not copy an upload artifact to quarantine.');
			}
			@chmod($to, 0600);
		}
	}

	/**
	 * Publish a fully prepared directory.
	 *
	 * Windows may briefly retain a handle after checksum/copy operations (for example while
	 * Defender scans a new file). Keep the operation atomic, but tolerate that short-lived
	 * sharing violation instead of turning a valid quarantine operation into a failed job.
	 */
	private static function publishDirectory(string $source, string $target): bool
	{
		for ($attempt = 0; $attempt < 6; $attempt++) {
			clearstatcache(true, $source);
			clearstatcache(true, $target);
			if (!is_dir($source) || file_exists($target) || is_link($target)) {
				return false;
			}
			if (@rename($source, $target)) {
				return true;
			}
			if ($attempt < 5) {
				usleep(25000 * ($attempt + 1));
			}
		}
		return false;
	}

	private static function directoryChecksum(string $directory): string
	{
		$entries = scandir($directory);
		if (!is_array($entries)) {
			throw new RuntimeException('Could not checksum the quarantine directory.');
		}
		$files = [];
		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			$path = $directory . DIRECTORY_SEPARATOR . $entry;
			if (!is_file($path) || is_link($path)) {
				throw new RuntimeException('Quarantine contains an unsupported entry.');
			}
			$files[] = $entry;
		}
		sort($files, SORT_STRING);
		$hash = hash_init('sha256');
		foreach ($files as $entry) {
			$path = $directory . DIRECTORY_SEPARATOR . $entry;
			hash_update($hash, $entry . "\0" . filesize($path) . "\0" . hash_file('sha256', $path) . "\0");
		}
		return hash_final($hash);
	}

	private static function injectFailure(string $stage): void
	{
		if (getenv('FILEHOST_TEST_QUARANTINE_FAIL_STAGE') === $stage) {
			throw new RuntimeException("Injected quarantine failure after {$stage}.");
		}
	}

	private static function deletionLockName(): string
	{
		return 'fh:delete:'
			. sha1((defined('DB_NAME') ? DB_NAME : '') . ':'
				. Database::table('file_deletion_queue'));
	}

	private static function acquireDeletionLock(PDO $pdo): bool
	{
		$stmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
		$stmt->execute([self::deletionLockName()]);
		return (int) $stmt->fetchColumn() === 1;
	}

	private static function releaseDeletionLock(PDO $pdo): void
	{
		try {
			$stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
			$stmt->execute([self::deletionLockName()]);
		} catch (Throwable $e) {
			// MySQL also releases the named lock when this connection closes.
		}
	}

	/**
	 * Copy and verify bytes. The live artifact is deliberately left in place; the deletion
	 * worker removes it only after this method durably publishes state=quarantined.
	 */
	public static function captureArtifacts(string $id): bool
	{
		$row = self::find($id);
		if (!$row) {
			return false;
		}
		if ((string) $row['state'] === 'quarantined') {
			return true;
		}
		if ((string) $row['state'] !== 'pending') {
			return false;
		}

		$final = self::path($id);
		$partial = $final . '.partial';
		$payload = $final . DIRECTORY_SEPARATOR . 'payload';
		try {
			if (is_dir($final)) {
				$checksum = self::directoryChecksum($payload);
			} else {
				self::removeTree($partial);
				if (!mkdir($partial, 0700, true) && !is_dir($partial)) {
					throw new RuntimeException('Could not create quarantine staging.');
				}
				self::copyDirectory(
					UPLOADS_DIR . DIRECTORY_SEPARATOR . $id,
					$partial . DIRECTORY_SEPARATOR . 'payload'
				);
				self::injectFailure('copy');

				$thumb = DATA_DIR . DIRECTORY_SEPARATOR . 'thumbs'
					. DIRECTORY_SEPARATOR . $id . '.jpg';
				if (is_file($thumb) && !is_link($thumb)
					&& !copy($thumb, $partial . DIRECTORY_SEPARATOR . 'thumbnail.jpg')) {
					throw new RuntimeException('Could not copy the cached thumbnail.');
				}
				$checksum = self::directoryChecksum(
					$partial . DIRECTORY_SEPARATOR . 'payload'
				);
				$diskManifest = json_encode([
					'version' => self::MANIFEST_VERSION,
					'file_id' => $id,
					'size' => (int) $row['size'],
					'checksum' => $checksum,
					'reason' => (string) $row['reason'],
					'actor' => [
						'type' => (string) $row['actor_type'],
						'id' => $row['actor_id'],
					],
					'created_at' => (int) $row['created_at'],
					'quarantine_until' => (int) $row['quarantine_until'],
				], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
					| JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
				if (file_put_contents(
					$partial . DIRECTORY_SEPARATOR . 'manifest.json',
					$diskManifest,
					LOCK_EX
				) === false) {
					throw new RuntimeException('Could not write the quarantine manifest.');
				}
				self::injectFailure('manifest');
				if (!self::publishDirectory($partial, $final)) {
					throw new RuntimeException('Could not publish the quarantine artifact.');
				}
			}

			$pdo = Database::getInstance();
			if (!$pdo) {
				throw new RuntimeException('Database unavailable while publishing quarantine.');
			}
			$update = $pdo->prepare(
				"UPDATE `" . self::table() . "`
				 SET `state` = 'quarantined', `checksum` = ?, `attempts` = 0,
				  `next_attempt_at` = `quarantine_until`, `last_error` = NULL,
				  `updated_at` = ?
				 WHERE `file_id` = ? AND `state` = 'pending'"
			);
			$update->execute([$checksum, time(), $id]);
			if ($update->rowCount() !== 1
				&& (self::find($id)['state'] ?? '') !== 'quarantined') {
				throw new RuntimeException('Quarantine state changed while publishing.');
			}
			self::injectFailure('publish');
			return true;
		} catch (Throwable $e) {
			self::recordFailure($id, $e->getMessage());
			error_log('File quarantine capture failed: ' . $e->getMessage());
			return false;
		}
	}

	private static function recordFailure(string $id, string $message): void
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return;
		}
		$stmt = $pdo->prepare(
			"UPDATE `" . self::table() . "`
			 SET `attempts` = `attempts` + 1, `next_attempt_at` = ?,
			  `last_error` = ?, `updated_at` = ? WHERE `file_id` = ?"
		);
		$stmt->execute([
			time() + 30,
			substr($message, 0, 1000),
			time(),
			$id,
		]);
	}

	private static function restorePayload(string $id, string $source, string $checksum): bool
	{
		$target = UPLOADS_DIR . DIRECTORY_SEPARATOR . $id;
		if (is_dir($target) && !is_link($target)) {
			return hash_equals($checksum, self::directoryChecksum($target));
		}
		if (file_exists($target) || is_link($target)) {
			return false;
		}
		$partial = $target . '.restore-partial';
		self::removeTree($partial);
		self::copyDirectory($source, $partial);
		if (!hash_equals($checksum, self::directoryChecksum($partial))) {
			self::removeTree($partial);
			return false;
		}
		if (!self::publishDirectory($partial, $target)) {
			self::removeTree($partial);
			return false;
		}
		return true;
	}

	/**
	 * Restore is idempotent. It refuses an occupied file ID unless both metadata state and
	 * content checksum prove this exact restore already completed.
	 */
	public static function restore(string $id): bool
	{
		if (!FileManager::isValidFileId($id)) {
			return false;
		}
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$table = self::table();
		$files = Database::table('files');
		$createdLiveArtifact = false;
		if (!self::acquireDeletionLock($pdo)) {
			return false;
		}
		try {
			$pdo->beginTransaction();
			$stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE `file_id` = ? FOR UPDATE");
			$stmt->execute([$id]);
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			if (!$row || !in_array((string) $row['state'], ['quarantined', 'restored'], true)) {
				$pdo->rollBack();
				return false;
			}

			$manifest = json_decode(
				(string) $row['manifest_json'],
				true,
				512,
				JSON_THROW_ON_ERROR
			);
			$file = $manifest['file'] ?? null;
			if (!is_array($file) || ($file['id'] ?? null) !== $id
				|| !is_string($row['checksum']) || strlen($row['checksum']) !== 64) {
				throw new RuntimeException('Quarantine restore manifest is invalid.');
			}

			$existing = $pdo->prepare("SELECT `id` FROM `{$files}` WHERE `id` = ? FOR UPDATE");
			$existing->execute([$id]);
			$alreadyExists = (bool) $existing->fetchColumn();
			if ($alreadyExists && (string) $row['state'] !== 'restored') {
				$pdo->rollBack();
				return false;
			}
			if ($alreadyExists) {
				$live = UPLOADS_DIR . DIRECTORY_SEPARATOR . $id;
				if (!is_dir($live) || is_link($live)) {
					$pdo->rollBack();
					return false;
				}
				$pdo->commit();
				return true;
			}

			$payload = self::path($id) . DIRECTORY_SEPARATOR . 'payload';
			$targetExisted = is_dir(UPLOADS_DIR . DIRECTORY_SEPARATOR . $id);
			if (!self::restorePayload($id, $payload, (string) $row['checksum'])) {
				throw new RuntimeException('Restore target is occupied or failed checksum validation.');
			}
			$createdLiveArtifact = !$targetExisted;
			self::injectFailure('restore_copy');
			if (!empty($file['user_id'])) {
				$user = $pdo->prepare(
					"SELECT `id` FROM `" . Database::table('users') . "` WHERE `id` = ?"
				);
				$user->execute([(int) $file['user_id']]);
				if (!$user->fetchColumn()) {
					$file['user_id'] = null;
				}
			}
			$columnsQuery = $pdo->query("SHOW COLUMNS FROM `{$files}`");
			$actualColumns = $columnsQuery->fetchAll(PDO::FETCH_COLUMN);
			$columns = array_values(array_filter(
				$actualColumns,
				static fn(string $column): bool => array_key_exists($column, $file)
			));
			if (!in_array('id', $columns, true)) {
				throw new RuntimeException('Restore manifest has no file identifier.');
			}
			$quoted = implode(', ', array_map(
				static fn(string $column): string => "`{$column}`",
				$columns
			));
			$insert = $pdo->prepare(
				"INSERT INTO `{$files}` ({$quoted}) VALUES ("
				. implode(', ', array_fill(0, count($columns), '?')) . ')'
			);
			$insert->execute(array_map(
				static fn(string $column) => $file[$column],
				$columns
			));

			$memberships = Database::table('collection_files');
			$collections = Database::table('collections');
			$restoreMembership = $pdo->prepare(
				"INSERT IGNORE INTO `{$memberships}` (`collection_id`, `file_id`, `position`)
				 SELECT `id`, ?, ? FROM `{$collections}` WHERE `id` = ?"
			);
			foreach (($manifest['collections'] ?? []) as $membership) {
				if (!is_array($membership) || empty($membership['collection_id'])) {
					continue;
				}
				$restoreMembership->execute([
					$id,
					(int) ($membership['position'] ?? 0),
					(string) $membership['collection_id'],
				]);
			}
			self::injectFailure('restore_metadata');

			$pdo->prepare(
				"DELETE FROM `" . Database::table('file_deletion_queue') . "` WHERE `file_id` = ?"
			)->execute([$id]);
			$pdo->prepare(
				"UPDATE `{$table}` SET `state` = 'restored', `last_error` = NULL,
				 `updated_at` = ? WHERE `file_id` = ?"
			)->execute([time(), $id]);
			$pdo->commit();

			$thumbSource = self::path($id) . DIRECTORY_SEPARATOR . 'thumbnail.jpg';
			if (is_file($thumbSource)) {
				$thumbDir = DATA_DIR . DIRECTORY_SEPARATOR . 'thumbs';
				if (!is_dir($thumbDir)) {
					@mkdir($thumbDir, 0700, true);
				}
				$thumbTarget = $thumbDir . DIRECTORY_SEPARATOR . $id . '.jpg';
				if (!file_exists($thumbTarget)) {
					@copy($thumbSource, $thumbTarget);
				}
			}
			return true;
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			if ($createdLiveArtifact) {
				self::removeTree(UPLOADS_DIR . DIRECTORY_SEPARATOR . $id);
			}
			self::recordFailure($id, $e->getMessage());
			error_log('File quarantine restore failed: ' . $e->getMessage());
			return false;
		} finally {
			self::releaseDeletionLock($pdo);
		}
	}

	/** Permanently purge expired entries and old restored audit records. */
	public static function purgeExpired(int $limit = 100): array
	{
		$result = ['processed' => 0, 'purged' => 0, 'failed' => 0];
		$pdo = Database::getInstance();
		if (!$pdo) {
			return $result;
		}
		if (!self::acquireDeletionLock($pdo)) {
			return $result;
		}
		$limit = max(1, min(500, $limit));
		try {
			$now = time();
			$stmt = $pdo->prepare(
				"SELECT `file_id`, `state` FROM `" . self::table() . "`
				 WHERE (`state` = 'quarantined' AND `quarantine_until` <= ?)
				    OR (`state` = 'restored' AND `updated_at` <= ?)
				 ORDER BY `updated_at` ASC LIMIT {$limit}"
			);
			$stmt->execute([$now, $now - (self::RESTORED_AUDIT_DAYS * 86400)]);
			$delete = $pdo->prepare(
				"DELETE FROM `" . self::table() . "` WHERE `file_id` = ? AND `state` = ?"
			);
			foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
				$result['processed']++;
				$id = (string) $row['file_id'];
				if (!self::removeTree(self::path($id))) {
					self::recordFailure($id, 'Could not permanently remove quarantine artifacts.');
					$result['failed']++;
					continue;
				}
				$delete->execute([$id, (string) $row['state']]);
				if ($delete->rowCount() === 1) {
					$result['purged']++;
				} else {
					$result['failed']++;
				}
			}
			return $result;
		} finally {
			self::releaseDeletionLock($pdo);
		}
	}

	/** Operator-only explicit purge; used by the CLI after an exact ID is supplied. */
	public static function purge(string $id): bool
	{
		$row = self::find($id);
		if (!$row || !in_array((string) $row['state'], ['quarantined', 'restored'], true)) {
			return false;
		}
		$pdo = Database::getInstance();
		if (!$pdo || !self::acquireDeletionLock($pdo)) {
			return false;
		}
		try {
			$row = self::find($id);
			if (!$row || !in_array((string) $row['state'], ['quarantined', 'restored'], true)) {
				return false;
			}
			if (!self::removeTree(self::path($id))) {
				self::recordFailure($id, 'Could not permanently remove quarantine artifacts.');
				return false;
			}
			$stmt = $pdo->prepare(
				"DELETE FROM `" . self::table() . "` WHERE `file_id` = ? AND `state` = ?"
			);
			$stmt->execute([$id, (string) $row['state']]);
			return $stmt->rowCount() === 1;
		} finally {
			self::releaseDeletionLock($pdo);
		}
	}
}
