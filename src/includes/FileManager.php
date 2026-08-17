<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/StorageManifest.php';
require_once __DIR__ . '/FileQuarantine.php';

class FileManager
{
	private const INLINE_PREVIEW_MIME_TYPES = [
		'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif',
		'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/flac', 'audio/aac',
		'video/mp4', 'video/webm', 'video/ogg',
		'application/pdf', 'text/plain',
	];

	private const EMBED_IMAGE_MIME_TYPES = [
		'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif',
	];

	private static function table(string $name): string
	{
		$prefix = defined('DB_PREFIX') ? DB_PREFIX : '';
		return $prefix . $name;
	}

	/**
	 * File identifiers are capability/resource names, never paths.
	 *
	 * Older installations and tests also use "_" and "-", so keep those characters while
	 * rejecting every separator, dot and control character. Keeping the validation here gives
	 * controllers, repositories and filesystem cleanup one contract.
	 */
	public static function isValidFileId(string $id): bool
	{
		return preg_match('/\A[A-Za-z0-9_-]{1,32}\z/D', $id) === 1;
	}

	private static function normaliseMime(string $mime): string
	{
		return strtolower(trim(explode(';', $mime, 2)[0]));
	}

	public static function isInlinePreviewAllowed(string $mime): bool
	{
		return in_array(self::normaliseMime($mime), self::INLINE_PREVIEW_MIME_TYPES, true);
	}

	public static function isEmbeddableImage(string $mime): bool
	{
		return in_array(self::normaliseMime($mime), self::EMBED_IMAGE_MIME_TYPES, true);
	}

	/**
	 * Parse one RFC 7233 byte range. Multi-range requests are deliberately ignored (200)
	 * because this streamer does not emit multipart/byteranges.
	 *
	 * @return array{status:int,start:int,end:int}
	 */
	public static function parseByteRange(?string $header, int $size): array
	{
		$full = ['status' => 200, 'start' => 0, 'end' => max(0, $size - 1)];
		$header = trim((string) $header);
		if ($header === '' || str_contains($header, ',')) {
			return $full;
		}
		if ($size < 1
			|| preg_match('/\Abytes=(\d*)-(\d*)\z/D', $header, $matches) !== 1
			|| ($matches[1] === '' && $matches[2] === '')) {
			return ['status' => 416, 'start' => 0, 'end' => max(0, $size - 1)];
		}

		if ($matches[1] === '') {
			$suffix = (int) $matches[2];
			if ($suffix < 1) {
				return ['status' => 416, 'start' => 0, 'end' => $size - 1];
			}
			return [
				'status' => 206,
				'start' => max(0, $size - $suffix),
				'end' => $size - 1,
			];
		}

		$start = (int) $matches[1];
		$end = $matches[2] === '' ? $size - 1 : min((int) $matches[2], $size - 1);
		if ($start >= $size || $start > $end) {
			return ['status' => 416, 'start' => 0, 'end' => $size - 1];
		}
		return ['status' => 206, 'start' => $start, 'end' => $end];
	}

	/**
	 * Single policy gate for every PHP byte-serving mode.
	 *
	 * @return array{allowed:bool,status:int,message:string}
	 */
	public static function authorizeFileRead(
		string $id,
		string $mode,
		bool $passwordAuthorized = false
	): array {
		if (!in_array($mode, ['download', 'preview', 'embed'], true)
			|| !self::isValidFileId($id)) {
			return ['allowed' => false, 'status' => 400, 'message' => 'Invalid file request'];
		}

		$state = Database::getFileSharingState($id);
		if ($state === []) {
			return ['allowed' => false, 'status' => 503, 'message' => 'File policy unavailable'];
		}
		if (empty($state['found'])) {
			return ['allowed' => false, 'status' => 404, 'message' => 'File not found'];
		}
		if (!empty($state['expires_at']) && (int) $state['expires_at'] < time()) {
			return ['allowed' => false, 'status' => 410, 'message' => 'This link has expired'];
		}
		if (!empty($state['max_downloads'])
			&& (int) $state['downloads'] >= (int) $state['max_downloads']) {
			return ['allowed' => false, 'status' => 410, 'message' => 'Download limit reached'];
		}
		if (!empty($state['one_time']) && $state['consumed_at'] !== null) {
			return ['allowed' => false, 'status' => 410, 'message' => 'This one-time link has already been used'];
		}
		if (!empty($state['password_hash']) && !$passwordAuthorized) {
			return ['allowed' => false, 'status' => 403, 'message' => 'Password required'];
		}
		if ($mode !== 'preview' && !Database::claimOneTime($id)) {
			return ['allowed' => false, 'status' => 410, 'message' => 'This one-time link has already been used'];
		}

		return ['allowed' => true, 'status' => 200, 'message' => ''];
	}

	/** Return a canonical child path or null when it escapes (including through a symlink). */
	private static function canonicalChild(string $base, string $candidate): ?string
	{
		$baseReal = realpath($base);
		$candidateReal = realpath($candidate);
		if ($baseReal === false || $candidateReal === false) {
			return null;
		}

		$normalise = static function (string $path): string {
			$path = rtrim(str_replace('\\', '/', $path), '/');
			return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
		};
		$baseNormal = $normalise($baseReal);
		$candidateNormal = $normalise($candidateReal);

		if ($candidateNormal === $baseNormal || !str_starts_with($candidateNormal, $baseNormal . '/')) {
			return null;
		}
		return $candidateReal;
	}

	/**
	 * Remove a file's stored bytes and its cached thumbnail.
	 *
	 * Every delete path needs both, and several of them used to drop only the upload
	 * directory — leaving orphaned JPEGs in data/thumbs/ that nothing would ever clean up
	 * (and which a later file reusing the id would have served as its own preview). One
	 * helper so a new delete path cannot forget half the job.
	 */
	public static function purgeFileArtifacts(string $id): bool
	{
		if (!self::isValidFileId($id)) {
			return false;
		}

		$ok = true;
		$dirCandidate = UPLOADS_DIR . '/' . $id;
		if (is_link($dirCandidate)) {
			$ok = @unlink($dirCandidate) && $ok;
		} elseif (is_dir($dirCandidate)) {
			$dir = self::canonicalChild(UPLOADS_DIR, $dirCandidate);
			if ($dir === null) {
				return false;
			}
			$entries = scandir($dir);
			if (is_array($entries)) {
				foreach ($entries as $entry) {
					if ($entry === '.' || $entry === '..') {
						continue;
					}
					$path = $dir . DIRECTORY_SEPARATOR . $entry;
					// Upload directories contain files only. Unlink a symlink itself, never
					// recursively follow an unexpected directory.
					if (is_file($path) || is_link($path)) {
						$ok = @unlink($path) && $ok;
					} elseif (is_dir($path)) {
						$ok = false;
					}
				}
			} else {
				$ok = false;
			}
			$ok = @rmdir($dir) && $ok;
		} elseif (file_exists($dirCandidate)) {
			$ok = false;
		}

		$thumbs = DATA_DIR . '/thumbs';
		$thumbCandidate = $thumbs . '/' . $id . '.jpg';
		if (is_link($thumbCandidate)) {
			$ok = @unlink($thumbCandidate) && $ok;
		} elseif (is_file($thumbCandidate)) {
			$thumb = self::canonicalChild($thumbs, $thumbCandidate);
			if ($thumb === null) {
				return false;
			}
			$ok = @unlink($thumb) && $ok;
		} elseif (file_exists($thumbCandidate)) {
			$ok = false;
		}

		return $ok
			&& !file_exists($dirCandidate) && !is_link($dirCandidate)
			&& !file_exists($thumbCandidate) && !is_link($thumbCandidate);
	}

	/** Add one idempotent physical deletion job inside the caller's transaction. */
	private static function enqueuePhysicalDeletion(
		PDO $pdo,
		string $id,
		string $reason = 'system_cleanup',
		string $actorType = 'system',
		?string $actorId = null
	): void
	{
		FileQuarantine::enqueue($pdo, $id, $reason, $actorType, $actorId);
		$queue = self::table('file_deletion_queue');
		$stmt = $pdo->prepare(
			"INSERT INTO `{$queue}`
			 (`file_id`, `attempts`, `next_attempt_at`, `last_error`, `created_at`)
			 VALUES (?, 0, 0, NULL, ?)
			 ON DUPLICATE KEY UPDATE `next_attempt_at` = LEAST(`next_attempt_at`, VALUES(`next_attempt_at`))"
		);
		$stmt->execute([$id, time()]);
	}

	/**
	 * Remove relational metadata and the locked file row, while durably queuing its bytes.
	 * The caller owns the transaction and must have selected the file FOR UPDATE.
	 */
	private static function deleteLockedFile(
		PDO $pdo,
		string $id,
		string $reason,
		string $actorType,
		?string $actorId = null
	): bool
	{
		self::enqueuePhysicalDeletion($pdo, $id, $reason, $actorType, $actorId);
		$pdo->prepare(
			"DELETE FROM `" . self::table('collection_files') . "` WHERE `file_id` = ?"
		)->execute([$id]);
		$pdo->prepare(
			"DELETE FROM `" . self::table('reports') . "` WHERE `file_id` = ?"
		)->execute([$id]);
		$delete = $pdo->prepare("DELETE FROM `" . self::table('files') . "` WHERE `id` = ?");
		$delete->execute([$id]);
		return $delete->rowCount() === 1;
	}

	/** Queue and remove a bounded set of rows in one transaction. */
	private static function deleteFileIds(
		array $ids,
		?int $ownerId = null,
		string $reason = 'system_cleanup',
		string $actorType = 'system',
		?string $actorId = null
	): int
	{
		$ids = array_values(array_unique(array_filter(
			$ids,
			static fn($id): bool => is_string($id) && self::isValidFileId($id)
		)));
		if ($ids === []) {
			return 0;
		}

		$pdo = Database::getInstance();
		if (!$pdo) {
			return 0;
		}
		$files = self::table('files');
		try {
			$pdo->beginTransaction();
			$selected = [];
			foreach (array_chunk($ids, 500) as $chunk) {
				$in = implode(',', array_fill(0, count($chunk), '?'));
				$params = $chunk;
				$ownerSql = '';
				if ($ownerId !== null) {
					$ownerSql = ' AND `user_id` = ?';
					$params[] = $ownerId;
				}
				$stmt = $pdo->prepare(
					"SELECT `id` FROM `{$files}` WHERE `id` IN ({$in}){$ownerSql} FOR UPDATE"
				);
				$stmt->execute($params);
				$selected = array_merge($selected, $stmt->fetchAll(PDO::FETCH_COLUMN));
			}
			if ($selected === []) {
				$pdo->rollBack();
				return 0;
			}

			foreach ($selected as $id) {
				self::enqueuePhysicalDeletion(
					$pdo,
					(string) $id,
					$reason,
					$actorType,
					$actorId
				);
			}
			$deleted = 0;
			foreach (array_chunk($selected, 500) as $chunk) {
				$in = implode(',', array_fill(0, count($chunk), '?'));
				$pdo->prepare(
					"DELETE FROM `" . self::table('collection_files') . "` WHERE `file_id` IN ({$in})"
				)->execute($chunk);
				$pdo->prepare(
					"DELETE FROM `" . self::table('reports') . "` WHERE `file_id` IN ({$in})"
				)->execute($chunk);
				$delete = $pdo->prepare("DELETE FROM `{$files}` WHERE `id` IN ({$in})");
				$delete->execute($chunk);
				$deleted += $delete->rowCount();
			}
			if ($deleted !== count($selected)) {
				throw new RuntimeException('A locked file batch changed during deletion.');
			}
			$pdo->commit();
			self::processDeletionQueue(min(500, $deleted));
			return $deleted;
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			error_log('File batch deletion failed: ' . $e->getMessage());
			return 0;
		}
	}

	/**
	 * Retry durable filesystem deletion jobs. Missing artifacts count as success.
	 *
	 * @return array{processed:int,deleted:int,quarantined:int,failed:int}
	 */
	public static function processDeletionQueue(int $limit = 100): array
	{
		$result = ['processed' => 0, 'deleted' => 0, 'quarantined' => 0, 'failed' => 0];
		$limit = max(1, min(500, $limit));
		$pdo = Database::getInstance();
		if (!$pdo) {
			return $result;
		}

		$lockName = 'fh:delete:'
			. sha1((defined('DB_NAME') ? DB_NAME : '') . ':' . self::table('file_deletion_queue'));
		try {
			$lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
			$lock->execute([$lockName]);
		} catch (Throwable $e) {
			error_log('Could not acquire file deletion worker lock: ' . $e->getMessage());
			$result['failed']++;
			return $result;
		}
		if ((int) $lock->fetchColumn() !== 1) {
			return $result;
		}

		$queue = self::table('file_deletion_queue');
		try {
			$stmt = $pdo->prepare(
				"SELECT `file_id`, `attempts` FROM `{$queue}`
				 WHERE `next_attempt_at` <= ? ORDER BY `created_at` ASC LIMIT {$limit}"
			);
			$stmt->execute([time()]);
			$remove = $pdo->prepare("DELETE FROM `{$queue}` WHERE `file_id` = ?");
			$retry = $pdo->prepare(
				"UPDATE `{$queue}` SET `attempts` = `attempts` + 1,
				 `next_attempt_at` = ?, `last_error` = ? WHERE `file_id` = ?"
			);
			foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $job) {
				$id = (string) $job['file_id'];
				$result['processed']++;
				$quarantine = FileQuarantine::find($id);
				if ($quarantine !== null
					&& in_array((string) $quarantine['state'], ['pending', 'quarantined'], true)
					&& !FileQuarantine::captureArtifacts($id)) {
					$attempt = (int) $job['attempts'] + 1;
					$delay = min(86400, 30 * (2 ** min(11, $attempt - 1)));
					$retry->execute([
						time() + $delay,
						'Artifacts could not be copied and verified in quarantine.',
						$id,
					]);
					$result['failed']++;
					continue;
				}
				if (self::purgeFileArtifacts($id)) {
					$remove->execute([$id]);
					if ($quarantine !== null) {
						$result['quarantined']++;
					} else {
						$result['deleted']++;
					}
					continue;
				}

				$attempt = (int) $job['attempts'] + 1;
				$delay = min(86400, 30 * (2 ** min(11, $attempt - 1)));
				$retry->execute([
					time() + $delay,
					'Filesystem artifacts could not be fully removed.',
					$id,
				]);
				$result['failed']++;
			}
		} catch (Throwable $e) {
			error_log('File deletion worker failed: ' . $e->getMessage());
			$result['failed']++;
		} finally {
			try {
				$release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
				$release->execute([$lockName]);
			} catch (Throwable $e) {
			}
		}
		return $result;
	}

	public static function getFile(string $id): ?array
	{
		if (!self::isValidFileId($id)) {
			return null;
		}

		$pdo = Database::getInstance();
		if (!$pdo)
			return null;

		$table = self::table('files');

		try {
			$stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE `id` = ?");
			$stmt->execute([$id]);
			$file = $stmt->fetch();

			if (!$file)
				return null;

			$stored = StorageManifest::resolve(
				UPLOADS_DIR,
				$id,
				(string) $file['original_name'],
				(int) $file['size']
			);
			if ($stored === null)
				return null;

			$mimeType = self::normaliseMime((string) $file['mime_type']);
			$previewType = 'file';
			if (self::isEmbeddableImage($mimeType)) {
				$previewType = 'image';
			} elseif (in_array($mimeType, ['video/mp4', 'video/webm', 'video/ogg'], true)) {
				$previewType = 'video';
			} elseif (in_array($mimeType, ['audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/flac', 'audio/aac'], true)) {
				$previewType = 'audio';
			} elseif ($mimeType === 'application/pdf') {
				$previewType = 'pdf';
			} elseif ($mimeType === 'text/plain') {
				$previewType = 'text';
			}

			return [
				'id' => $file['id'],
				'name' => $file['original_name'],
				'mimeType' => $file['mime_type'],
				'size' => (int) $file['size'],
				'deleteToken' => $file['delete_token'],
				'uploadedAt' => (int) $file['uploaded_at'],
				'uploadedIP' => $file['uploaded_ip'],
				'downloads' => (int) $file['downloads'],
				'path' => $stored['path'],
				'storageSha256' => $stored['sha256'],
				'previewType' => $previewType
			];
		} catch (PDOException $e) {
			return null;
		}
	}

	public static function saveFile(array $data): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo)
			return false;

		$table = self::table('files');

		try {
			$stmt = $pdo->prepare("INSERT INTO `{$table}` (`id`, `original_name`, `mime_type`, `size`, `delete_token`, `uploaded_at`, `uploaded_ip`, `downloads`, `user_id`) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)");

			return $stmt->execute([
				$data['id'],
				$data['originalName'],
				$data['mimeType'] ?? 'application/octet-stream',
				$data['size'] ?? 0,
				$data['deleteToken'],
				$data['uploadedAt'] ?? time(),
				$data['uploadedIP'] ?? '',
				$data['userId'] ?? null
			]);
		} catch (PDOException $e) {
			error_log("Failed to save file: " . $e->getMessage());
			return false;
		}
	}

	public static function deleteFile(string $id, string $token): bool
	{
		if (!self::isValidFileId($id) || strlen($token) > InputLimits::SHORT_TEXT_MAX) {
			return false;
		}

		$pdo = Database::getInstance();
		if (!$pdo)
			return false;

		$table = self::table('files');

		try {
			$pdo->beginTransaction();
			$stmt = $pdo->prepare("SELECT `delete_token`, `original_name`, `mime_type`, `size`, `user_id` FROM `{$table}` WHERE `id` = ? FOR UPDATE");
			$stmt->execute([$id]);
			$file = $stmt->fetch(PDO::FETCH_ASSOC);

			if (!$file) {
				$pdo->rollBack();
				return false;
			}

			if (!password_verify($token, $file['delete_token'])) {
				$pdo->rollBack();
				return false;
			}

			// Notify the owner's webhooks (guests have no account, so no user_id → skip).
			if (!empty($file['user_id'])) {
				Database::enqueueWebhookEvent((int) $file['user_id'], 'delete', ['file' => [
					'id' => $id,
					'name' => $file['original_name'],
					'mime' => $file['mime_type'],
					'size' => (int) $file['size'],
				]]);
			}
			if (!self::deleteLockedFile($pdo, $id, 'delete_link', 'capability')) {
				throw new RuntimeException('The locked file row could not be deleted.');
			}
			$pdo->commit();
			self::processDeletionQueue(1);

			return true;
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			error_log('Token-authorized file deletion failed: ' . $e->getMessage());
			return false;
		}
	}

	public static function deleteFileAdmin(string $id): bool
	{
		if (!self::isValidFileId($id)) {
			return false;
		}

		$pdo = Database::getInstance();
		if (!$pdo)
			return false;

		$table = self::table('files');

		try {
			// Never let a caller turn a non-existent database id into a filesystem path.
			$pdo->beginTransaction();
			$exists = $pdo->prepare(
				"SELECT `user_id`, `original_name` FROM `{$table}` WHERE `id` = ? FOR UPDATE"
			);
			$exists->execute([$id]);
			$file = $exists->fetch(PDO::FETCH_ASSOC);
			if (!$file) {
				$pdo->rollBack();
				return false;
			}

			if (!self::deleteLockedFile($pdo, $id, 'admin_delete', 'admin')) {
				throw new RuntimeException('The locked file row could not be deleted.');
			}
			$pdo->commit();
			self::processDeletionQueue(1);
			$ownerId = (int) ($file['user_id'] ?? 0);
			if ($ownerId > 0) {
				Notifications::send($ownerId, 'file.removed', [
					'subject' => (string) ($file['original_name'] ?? $id),
					'group' => 'file.removed:' . $id,
					'link' => (defined('APP_URL') ? APP_URL : '') . '/panel.php?tab=files',
				]);
			}

			return true;
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			error_log('Administrative file deletion failed: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Delete one candidate only if the owner's CURRENT locked entitlement still requires it.
	 *
	 * StorageEnforcer builds an ordered candidate list outside a transaction. A plan grant or
	 * group edit may happen before a candidate is reached, so the user row, effective group,
	 * file row and current usage are locked and re-evaluated immediately before deletion.
	 */
	public static function deleteForStoragePolicy(string $id, int $userId): bool
	{
		if (!self::isValidFileId($id) || $userId < 1) {
			return false;
		}
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}

		$users = self::table('users');
		$groups = self::table('groups');
		$files = self::table('files');
		$settings = self::table('settings');
		try {
			$pdo->beginTransaction();
			$userQuery = $pdo->prepare(
				"SELECT `storage_limit`, `group_id`, `group_expires_at`, `role`
				 FROM `{$users}` WHERE `id` = ? FOR UPDATE"
			);
			$userQuery->execute([$userId]);
			$user = $userQuery->fetch(PDO::FETCH_ASSOC);
			if (!$user) {
				$pdo->rollBack();
				return false;
			}

			$groupId = isset($user['group_id']) ? (int) $user['group_id'] : 0;
			$groupExpires = isset($user['group_expires_at'])
				? (int) $user['group_expires_at']
				: 0;
			if ($groupId > 0 && $groupExpires > 0 && $groupExpires <= time()) {
				$groupId = 0;
			}
			if ($groupId > 0) {
				$groupQuery = $pdo->prepare(
					"SELECT `id`, `storage_quota_mb`, `max_file_size_mb`
					 FROM `{$groups}` WHERE `id` = ?
					   AND (`slug` IS NULL OR `slug` NOT IN ('guest', 'moderator'))
					 FOR UPDATE"
				);
				$groupQuery->execute([$groupId]);
			} else {
				$groupQuery = $pdo->query(
					"SELECT `id`, `storage_quota_mb`, `max_file_size_mb`
					 FROM `{$groups}`
					 WHERE `is_default` = 1
					   AND (`slug` IS NULL OR `slug` NOT IN ('guest', 'moderator'))
					 ORDER BY `id` ASC LIMIT 1 FOR UPDATE"
				);
			}
			$group = $groupQuery->fetch(PDO::FETCH_ASSOC);
			if (!$group && $groupId > 0) {
				$groupQuery = $pdo->query(
					"SELECT `id`, `storage_quota_mb`, `max_file_size_mb`
					 FROM `{$groups}`
					 WHERE `is_default` = 1
					   AND (`slug` IS NULL OR `slug` NOT IN ('guest', 'moderator'))
					 ORDER BY `id` ASC LIMIT 1 FOR UPDATE"
				);
				$group = $groupQuery->fetch(PDO::FETCH_ASSOC);
			}
			if (!$group) {
				throw new RuntimeException('Effective storage group is unavailable.');
			}
			if (($user['role'] ?? '') === 'moderator') {
				$staffQuery = $pdo->query(
					"SELECT `id`, `storage_quota_mb`, `max_file_size_mb`
					 FROM `{$groups}` WHERE `slug` = 'moderator' AND `is_system` = 1
					 LIMIT 1 FOR UPDATE"
				);
				$group = GroupRepository::mergeLimits(
					$group,
					$staffQuery->fetch(PDO::FETCH_ASSOC) ?: null
				);
			}

			$fileQuery = $pdo->prepare(
				"SELECT `id`, `size` FROM `{$files}`
				 WHERE `id` = ? AND `user_id` = ? FOR UPDATE"
			);
			$fileQuery->execute([$id, $userId]);
			$file = $fileQuery->fetch(PDO::FETCH_ASSOC);
			if (!$file) {
				$pdo->rollBack();
				return false;
			}

			$usageQuery = $pdo->prepare(
				"SELECT `id`, `size` FROM `{$files}` WHERE `user_id` = ? FOR UPDATE"
			);
			$usageQuery->execute([$userId]);
			$used = array_sum(array_map(
				static fn(array $row): int => (int) $row['size'],
				$usageQuery->fetchAll(PDO::FETCH_ASSOC)
			));

			$quota = (int) ($user['storage_limit'] ?? 0);
			if ($quota <= 0) {
				$quota = max(0, (int) ($group['storage_quota_mb'] ?? 0)) * 1024 * 1024;
			}
			$maxFile = max(0, (int) ($group['max_file_size_mb'] ?? 0)) * 1024 * 1024;
			if ($maxFile <= 0) {
				$settingQuery = $pdo->prepare(
					"SELECT `setting_value` FROM `{$settings}`
					 WHERE `setting_key` = 'system_max_file_size_mb' FOR UPDATE"
				);
				$settingQuery->execute();
				$maxFile = max(0, (int) ($settingQuery->fetchColumn() ?: 5120))
					* 1024 * 1024;
			}

			$mustDelete = ($maxFile > 0 && (int) $file['size'] > $maxFile)
				|| ($quota > 0 && $used > $quota);
			if (!$mustDelete) {
				$pdo->rollBack();
				return false;
			}
			if (!self::deleteLockedFile(
				$pdo,
				$id,
				'storage_policy',
				'system',
				(string) $userId
			)) {
				throw new RuntimeException('Storage policy candidate changed during deletion.');
			}
			$pdo->commit();
			self::processDeletionQueue(1);
			return true;
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			error_log('Conditional storage deletion failed: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Delete a file owned by $userId (no delete-token needed — the REST API proves ownership
	 * via the caller's API key). Returns false if the file isn't the user's. Also clears the
	 * thumbnail cache and collection links so nothing dangles.
	 */
	public static function deleteOwnedFile(string $id, int $userId): bool
	{
		if (!self::isValidFileId($id)) {
			return false;
		}

		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$table = self::table('files');
		try {
			$pdo->beginTransaction();
			$stmt = $pdo->prepare("SELECT `original_name`, `mime_type`, `size`, `user_id` FROM `{$table}` WHERE `id` = ? FOR UPDATE");
			$stmt->execute([$id]);
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			if (!$row || (int) $row['user_id'] !== $userId) {
				$pdo->rollBack();
				return false;
			}

			Database::enqueueWebhookEvent($userId, 'delete', ['file' => [
				'id' => $id,
				'name' => $row['original_name'],
				'mime' => $row['mime_type'],
				'size' => (int) $row['size'],
			]]);

			if (!self::deleteLockedFile(
				$pdo,
				$id,
				'owner_delete',
				'user',
				(string) $userId
			)) {
				throw new RuntimeException('The locked file row could not be deleted.');
			}
			$pdo->commit();
			self::processDeletionQueue(1);

			return true;
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			error_log('Owner file deletion failed: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Count one access against a file.
	 *
	 * @param bool $embedded whether this was an embed/hotlink rather than someone opening the
	 *                       download page — the counter treats them the same, the owner's bell
	 *                       does not (see Notifications::TYPES).
	 */
	public static function incrementDownloads(string $id, bool $embedded = false): void
	{
		$pdo = Database::getInstance();
		if (!$pdo)
			return;

		$table = self::table('files');

		try {
			$stmt = $pdo->prepare("UPDATE `{$table}` SET `downloads` = `downloads` + 1 WHERE `id` = ?");
			$stmt->execute([$id]);
		} catch (PDOException $e) {
		}

		self::notifyOwnerOfDownload($id, $embedded);
	}

	/**
	 * Tell the owner their file was fetched — stacked, so a link doing the rounds is one line
	 * that counts up rather than a hundred.
	 *
	 * Keyed on the file id rather than its name: a rename must not split the stack, and two
	 * files sharing a name must not share one. Guest uploads have no owner and no bell.
	 *
	 * Two things are deliberately quiet:
	 *   - **your own downloads.** Fetching your own file is not news about your file, and it is
	 *     the single most common way to make the bell useless;
	 *   - **embeds**, unless the owner asked for them. An image hotlinked into a forum post
	 *     fires on every page view by every reader, so it gets its own type that ships off.
	 */
	private static function notifyOwnerOfDownload(string $id, bool $embedded = false): void
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return;
		}
		try {
			// Two columns by primary key — getFile() would stat the directory and work out a
			// preview type, none of which this needs, on every single download.
			$stmt = $pdo->prepare("SELECT `user_id`, `original_name` FROM `" . self::table('files') . "` WHERE `id` = ?");
			$stmt->execute([$id]);
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
		} catch (PDOException $e) {
			return;
		}
		$ownerId = (int) ($row['user_id'] ?? 0);
		if (!$ownerId || $ownerId === (int) ($_SESSION['user_id'] ?? 0)) {
			return;
		}
		$type = $embedded ? 'file.embedded' : 'file.downloaded';
		Notifications::send($ownerId, $type, [
			'subject' => (string) ($row['original_name'] ?? $id),
			'link' => (defined('APP_URL') ? APP_URL : '') . '/download.php?id=' . $id,
			'group' => $type . ':' . $id,
		]);
	}

	/**
	 * Unified, filtered listing behind the admin file browser (pt 9).
	 *
	 * The single listing path: plain paging, free-text search and the advanced filter set all
	 * go through here (it replaced the separate getAllFiles()/searchFiles() pair). Callers pass
	 * an already-authorised $opts array — FileController drops any filter the session's group
	 * may not use *before* calling, so nothing here needs to re-check rights.
	 *
	 * Every filter is a bound parameter and $sort is whitelisted, so no part of $opts reaches
	 * the SQL as text.
	 *
	 * The one filter SQL cannot answer is `dead` (rows whose bytes are gone from disk): that
	 * needs a stat() per row, so it runs in PHP over the SQL-narrowed set, bounded by
	 * DEAD_SCAN_CAP rows. Narrow with the other filters first on a large install.
	 */
	private const DEAD_SCAN_CAP = 5000;

	/** Columns the browser may sort by → the SQL they map to. */
	private const SORT_COLUMNS = [
		'uploaded_at'   => 'f.`uploaded_at`',
		'size'          => 'f.`size`',
		'downloads'     => 'f.`downloads`',
		'original_name' => 'f.`original_name`',
		'owner'         => 'owner_name',
		'uploaded_ip'   => 'f.`uploaded_ip`',
	];

	public static function browse(array $opts): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return ['files' => [], 'total' => 0];
		}

		$files = self::table('files');
		$users = self::table('users');

		$page = max(1, (int) ($opts['page'] ?? 1));
		// Only guard against a nonsensical page size here; the request-facing clamp (a floor of
		// 5, so a caller can't page one row at a time) belongs to FileController::handleList,
		// and duplicating it here would silently override a caller that asked for less.
		$perPage = min(100, max(1, (int) ($opts['per_page'] ?? 20)));
		$sort = self::SORT_COLUMNS[$opts['sort'] ?? 'uploaded_at'] ?? self::SORT_COLUMNS['uploaded_at'];
		$order = strtoupper((string) ($opts['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
		$sortParts = [];
		foreach ((array) ($opts['sorts'] ?? []) as $column => $direction) {
			if (isset(self::SORT_COLUMNS[$column]) && count($sortParts) < 5) {
				$sortParts[] = self::SORT_COLUMNS[$column] . ' '
					. (strtoupper((string) $direction) === 'ASC' ? 'ASC' : 'DESC');
			}
		}
		if ($sortParts === []) {
			$sortParts[] = "{$sort} {$order}";
		}
		$sortParts[] = 'f.`id` ASC';
		$sortSql = implode(', ', $sortParts);

		$where = [];
		$params = [];
		$f = $opts['filters'] ?? [];

		// pt 8: a hard scope to one owner, applied before any filter and not expressible
		// through them — this is what lets "My files" run on the same engine as the all-files
		// browser without a caller ever being able to widen it back out.
		if (isset($opts['owner_id'])) {
			$where[] = 'f.`user_id` = ?';
			$params[] = (int) $opts['owner_id'];
		}

		// Name and id are the list's own columns and always searchable; the IP and the owner are
		// other people's data, so the caller says whether this session may reach them. Without
		// that, a group denied the IP column could still confirm an address by searching for it
		// and seeing which rows came back.
		$search = trim((string) ($opts['search'] ?? ''));
		if ($search !== '') {
			$like = '%' . $search . '%';
			$terms = ['f.`original_name` LIKE ?', 'f.`id` LIKE ?'];
			array_push($params, $like, $like);
			if (!empty($opts['search_ip'])) {
				$terms[] = 'f.`uploaded_ip` LIKE ?';
				$params[] = $like;
			}
			if (!empty($opts['search_owner'])) {
				$terms[] = 'u.`username` LIKE ?';
				$params[] = $like;
			}
			$where[] = '(' . implode(' OR ', $terms) . ')';
		}

		// --- ranges: each bound is independent, so "from only" / "to only" both work ---
		foreach ([
			['date_from', 'f.`uploaded_at` >= ?'],
			['date_to', 'f.`uploaded_at` <= ?'],
			['size_min', 'f.`size` >= ?'],
			['size_max', 'f.`size` <= ?'],
			['dl_min', 'f.`downloads` >= ?'],
			['dl_max', 'f.`downloads` <= ?'],
		] as [$key, $clause]) {
			if (isset($f[$key]) && $f[$key] !== '' && $f[$key] !== null) {
				$where[] = $clause;
				$params[] = (int) $f[$key];
			}
		}

		// --- owner: a set of user ids, where 0 stands for "uploaded by a guest" (user_id NULL) ---
		if (!empty($f['users']) && is_array($f['users'])) {
			$ids = array_values(array_unique(array_map('intval', $f['users'])));
			$guest = in_array(0, $ids, true);
			$real = array_values(array_filter($ids, fn($i) => $i > 0));
			$parts = [];
			if ($real) {
				$parts[] = 'f.`user_id` IN (' . implode(',', array_fill(0, count($real), '?')) . ')';
				$params = array_merge($params, $real);
			}
			if ($guest) {
				$parts[] = 'f.`user_id` IS NULL';
			}
			if ($parts) {
				$where[] = '(' . implode(' OR ', $parts) . ')';
			}
		}

		// --- IP list ---
		if (!empty($f['ips']) && is_array($f['ips'])) {
			$ips = array_values(array_filter(array_map('trim', $f['ips']), fn($v) => $v !== ''));
			if ($ips) {
				$where[] = 'f.`uploaded_ip` IN (' . implode(',', array_fill(0, count($ips), '?')) . ')';
				$params = array_merge($params, $ips);
			}
		}

		// --- extension / mime ---
		if (!empty($f['extensions']) && is_array($f['extensions'])) {
			$exts = array_values(array_filter(array_map(
				fn($e) => strtolower(ltrim(trim((string) $e), '.')),
				$f['extensions']
			), fn($e) => $e !== '' && preg_match('/^[a-z0-9]{1,10}$/', $e)));
			if ($exts) {
				$clauses = [];
				foreach ($exts as $e) {
					$clauses[] = 'f.`original_name` LIKE ?';
					$params[] = '%.' . $e;
				}
				$where[] = '(' . implode(' OR ', $clauses) . ')';
			}
		}
		if (!empty($f['mime'])) {
			$where[] = 'f.`mime_type` LIKE ?';
			$params[] = trim((string) $f['mime']) . '%';
		}

		// --- "inactive since": nothing downloaded for N days. A file that was never downloaded
		//     counts as inactive from the moment it was uploaded. ---
		if (!empty($f['inactive_days'])) {
			$cutoff = time() - ((int) $f['inactive_days'] * 86400);
			$logs = self::table('traffic_logs');
			$where[] = "(SELECT COALESCE(MAX(t.`created_at`), f.`uploaded_at`)
					FROM `{$logs}` t WHERE t.`file_id` = f.`id` AND t.`transfer_type` = 'download') < ?";
			$params[] = $cutoff;
		}

		// --- membership: only files that are (or are not) in some collection (pt 4). The
		//     "search files inside collections" scope of the filter modal. ---
		if (isset($f['in_collection']) && $f['in_collection'] !== '' && $f['in_collection'] !== null) {
			$links = self::table('collection_files');
			$exists = "EXISTS (SELECT 1 FROM `{$links}` cfx WHERE cfx.`file_id` = f.`id`)";
			$where[] = ((int) $f['in_collection'] === 0 ? 'NOT ' : '') . $exists;
		}

		// --- sharing state ---
		$sharing = (array) ($f['sharing'] ?? []);
		$shareClauses = [];
		$now = time();
		foreach ($sharing as $state) {
			switch ($state) {
				case 'password':  $shareClauses[] = 'f.`password_hash` IS NOT NULL'; break;
				case 'onetime':   $shareClauses[] = 'f.`one_time` = 1'; break;
				case 'burned':    $shareClauses[] = '(f.`one_time` = 1 AND f.`consumed_at` IS NOT NULL)'; break;
				case 'expiring':  $shareClauses[] = '(f.`expires_at` IS NOT NULL AND f.`expires_at` > ?)'; $params[] = $now; break;
				case 'expired':   $shareClauses[] = '(f.`expires_at` IS NOT NULL AND f.`expires_at` <= ?)'; $params[] = $now; break;
				case 'capped':    $shareClauses[] = 'f.`max_downloads` IS NOT NULL'; break;
				case 'public':
					$shareClauses[] = '(f.`password_hash` IS NULL AND f.`one_time` = 0 AND f.`expires_at` IS NULL AND f.`max_downloads` IS NULL)';
					break;
			}
		}
		if ($shareClauses) {
			$where[] = '(' . implode(' OR ', $shareClauses) . ')';
		}

		$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
		$join = "LEFT JOIN `{$users}` u ON u.`id` = f.`user_id`";
		$select = "SELECT f.*, u.`username` AS owner_name";
		$deadOnly = !empty($f['dead']);
		// pt 8: "My files" pages, sorts and searches in the browser over the account's whole
		// list, exactly as it did before it grew filters. So it asks for the filtered set whole
		// rather than a page of it — safe because the set is one account's uploads, not the
		// install's. `owner_id` is required, so this cannot be used to dump every file.
		$unpaged = !empty($opts['unpaged']) && isset($opts['owner_id']);
		$owned = !empty($opts['owner_fields']) && isset($opts['owner_id']);
		$shape = $owned
			? [self::class, 'rowToOwnedItem']
			: [self::class, 'rowToListItem'];

		try {
			if ($unpaged) {
				$stmt = $pdo->prepare("{$select} FROM `{$files}` f {$join} {$whereSql} ORDER BY {$sortSql} LIMIT " . self::DEAD_SCAN_CAP);
				$stmt->execute($params);
				$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
				if ($deadOnly) {
					$rows = array_values(array_filter($rows, fn($r) => self::isDead($r)));
				}
				$total = count($rows);
			} elseif ($deadOnly) {
				// Pull the SQL-narrowed set, then keep only rows whose bytes are actually gone.
				$stmt = $pdo->prepare("{$select} FROM `{$files}` f {$join} {$whereSql} ORDER BY {$sortSql} LIMIT " . self::DEAD_SCAN_CAP);
				$stmt->execute($params);
				$rows = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), fn($r) => self::isDead($r)));
				$total = count($rows);
				$rows = array_slice($rows, ($page - 1) * $perPage, $perPage);
			} else {
				$countStmt = $pdo->prepare("SELECT COUNT(*) FROM `{$files}` f {$join} {$whereSql}");
				$countStmt->execute($params);
				$total = (int) $countStmt->fetchColumn();

				$sql = "{$select} FROM `{$files}` f {$join} {$whereSql} ORDER BY {$sortSql} LIMIT ? OFFSET ?";
				$stmt = $pdo->prepare($sql);
				$bound = $params;
				$bound[] = $perPage;
				$bound[] = ($page - 1) * $perPage;
				foreach ($bound as $i => $v) {
					$stmt->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
				}
				$stmt->execute();
				$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
			}

			return ['files' => array_map($shape, $rows), 'total' => $total];
		} catch (PDOException $e) {
			return ['files' => [], 'total' => 0];
		}
	}

	/** True when a file row has no bytes behind it any more (deleted on disk, row left over). */
	private static function isDead(array $file): bool
	{
		return StorageManifest::resolve(
			UPLOADS_DIR,
			(string) ($file['id'] ?? ''),
			(string) ($file['original_name'] ?? ''),
			(int) ($file['size'] ?? -1)
		) === null;
	}

	/** Shape a `files` row (optionally joined with its owner) for the browser's JSON. */
	private static function rowToListItem(array $row): array
	{
		return [
			'id' => $row['id'],
			'name' => $row['original_name'],
			'mimeType' => $row['mime_type'],
			'size' => (int) $row['size'],
			'uploadedAt' => (int) $row['uploaded_at'],
			'uploadedIP' => $row['uploaded_ip'],
			'downloads' => (int) $row['downloads'],
			'userId' => isset($row['user_id']) && $row['user_id'] !== null ? (int) $row['user_id'] : null,
			'owner' => $row['owner_name'] ?? null,
			'hasPassword' => !empty($row['password_hash']),
			'oneTime' => !empty($row['one_time']),
			'consumed' => !empty($row['one_time']) && !empty($row['consumed_at']),
			'expiresAt' => isset($row['expires_at']) ? (int) $row['expires_at'] : 0,
			'maxDownloads' => isset($row['max_downloads']) ? (int) $row['max_downloads'] : 0,
		];
	}

	/**
	 * A row shaped for the file's *owner* (pt 8) — the "My files" list.
	 *
	 * Same fields the old `user_files` endpoint returned: no owner name or uploader IP (they
	 * are the reader), plus the sharing options the row's own controls need.
	 *
	 * No delete token. `delete_token` is stored as a bcrypt hash, so the only thing this
	 * could put in the JSON is the hash — which is not the token, cannot be turned back into
	 * it, and was rejected by `FileController::deleteTokenMatches()` when the panel echoed it
	 * back ("Nieprawidłowy token usuwania" on every own-file delete). A row that still holds a
	 * pre-bcrypt plaintext token would have leaked the live capability into the page instead.
	 * The panel does not need either: an own-file delete is authorised by the session.
	 */
	private static function rowToOwnedItem(array $row): array
	{
		return [
			'id' => $row['id'],
			'name' => $row['original_name'],
			'mime' => $row['mime_type'] ?? '',
			'size' => (int) $row['size'],
			'uploadedAt' => (int) $row['uploaded_at'],
			'downloads' => (int) $row['downloads'],
			'expiresAt' => isset($row['expires_at']) ? (int) $row['expires_at'] : 0,
			'maxDownloads' => isset($row['max_downloads']) ? (int) $row['max_downloads'] : 0,
			'hasPassword' => !empty($row['password_hash']),
			'oneTime' => !empty($row['one_time']),
			'consumed' => !empty($row['one_time']) && !empty($row['consumed_at']),
			'onLimitAction' => $row['on_limit_action'] ?? 'keep',
		];
	}

	/**
	 * Which extensions the given account's own uploads actually use (pt 8) — the chip list in
	 * the "My files" filter panel, so it offers what is there rather than every type the
	 * install has ever seen.
	 */
	public static function ownerFacets(int $userId, int $limit = 200): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return ['extensions' => []];
		}
		$files = self::table('files');
		try {
			$stmt = $pdo->prepare(
				"SELECT LOWER(SUBSTRING_INDEX(`original_name`, '.', -1)) AS ext, COUNT(*) AS cnt
				 FROM `{$files}`
				 WHERE `user_id` = ? AND `original_name` LIKE '%.%'
				 GROUP BY ext HAVING ext REGEXP '^[a-z0-9]{1,10}$'
				 ORDER BY cnt DESC LIMIT " . max(1, $limit)
			);
			$stmt->execute([$userId]);
			$out = [];
			foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
				$out[] = ['ext' => $r['ext'], 'count' => (int) $r['cnt']];
			}
			return ['extensions' => $out];
		} catch (PDOException $e) {
			return ['extensions' => []];
		}
	}

	/**
	 * Choice lists for the filter modal: who has uploaded, from which IPs, and which
	 * extensions actually occur — so the admin picks from real values instead of guessing.
	 */
	public static function browseFacets(int $limit = 200): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return ['users' => [], 'ips' => [], 'extensions' => []];
		}
		$files = self::table('files');
		$users = self::table('users');
		$out = ['users' => [], 'ips' => [], 'extensions' => []];

		try {
			// Owners that actually have files. A NULL user_id is a guest upload; a user_id with
			// no matching account is an upload whose owner has since been deleted — both are
			// worth filtering on, so both are offered.
			$stmt = $pdo->query("SELECT f.`user_id`, u.`username`, COUNT(*) AS cnt
				FROM `{$files}` f LEFT JOIN `{$users}` u ON u.`id` = f.`user_id`
				GROUP BY f.`user_id`, u.`username` ORDER BY cnt DESC LIMIT {$limit}");
			foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
				$out['users'][] = [
					'id' => $r['user_id'] !== null ? (int) $r['user_id'] : 0,
					'name' => $r['username'],
					'count' => (int) $r['cnt'],
				];
			}

			$stmt = $pdo->query("SELECT `uploaded_ip`, COUNT(*) AS cnt FROM `{$files}`
				WHERE `uploaded_ip` <> '' GROUP BY `uploaded_ip` ORDER BY cnt DESC LIMIT {$limit}");
			foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
				$out['ips'][] = ['ip' => $r['uploaded_ip'], 'count' => (int) $r['cnt']];
			}

			// Extensions are derived in PHP: SUBSTRING_INDEX would also match dots in the name.
			$stmt = $pdo->query("SELECT `original_name` FROM `{$files}` LIMIT 20000");
			$tally = [];
			foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
				$ext = strtolower((string) pathinfo((string) $name, PATHINFO_EXTENSION));
				if ($ext !== '' && preg_match('/^[a-z0-9]{1,10}$/', $ext)) {
					$tally[$ext] = ($tally[$ext] ?? 0) + 1;
				}
			}
			arsort($tally);
			foreach (array_slice($tally, 0, 60, true) as $ext => $cnt) {
				$out['extensions'][] = ['ext' => $ext, 'count' => $cnt];
			}
		} catch (PDOException $e) {
			// Partial facets are still useful — return whatever was gathered.
		}

		return $out;
	}

	public static function getStats(): array
	{
		$pdo = Database::getInstance();
		if (!$pdo)
			return ['total_files' => 0, 'total_size' => 0, 'total_downloads' => 0];

		$table = self::table('files');

		try {
			$stmt = $pdo->query("SELECT COUNT(*) as cnt, COALESCE(SUM(`size`), 0) as size, COALESCE(SUM(`downloads`), 0) as downloads FROM `{$table}`");
			$row = $stmt->fetch();

			return [
				'total_files' => (int) $row['cnt'],
				'total_size' => (int) $row['size'],
				'total_downloads' => (int) $row['downloads']
			];
		} catch (PDOException $e) {
			return ['total_files' => 0, 'total_size' => 0, 'total_downloads' => 0];
		}
	}

	/**
	 * Is anything set to expire by age at all? (pt 6)
	 *
	 * True when the installation default is set, or when any single group sets its own
	 * retention — a group may keep files for 30 days on an install whose global default is
	 * "never", and the panel's cleanup button has to appear in that case too.
	 */
	public static function autoDeleteConfigured(): bool
	{
		if ((int) Database::getSetting('auto_delete_days', 0) > 0) {
			return true;
		}
		foreach (Database::getGroups() as $g) {
			if ((int) ($g['auto_delete_days'] ?? 0) > 0) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Delete files past their retention (pt 6).
	 *
	 * Retention is a property of the *owner's group*, not of the installation: each group says
	 * how long it keeps files (0 = follow the Settings → Storage default, -1 = never), which is
	 * what makes "your files are kept forever" something a premium tier can actually sell.
	 * Files with no owner (guest uploads) follow the `guest` group.
	 *
	 * The clock starts at the later of the upload and `users.group_changed_at`. Without that,
	 * a lapsed subscription would delete years of uploads the moment the plan ended — the group
	 * changed, and every file was instantly "too old" under the new rules. Counting from the
	 * change gives the account the new group's full period to react.
	 */
	public static function deleteExpiredFiles(): int
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return 0;
		}

		$retention = GroupRepository::retentionMap();
		$periods = array_filter(array_merge(
			array_values($retention['groups']),
			[$retention['guest'], $retention['default']]
		));
		if (!$periods) {
			return 0; // nothing on this install expires by age
		}

		$files = self::table('files');
		$users = Database::table('users');
		$now = time();
		// Nothing can be expired before the shortest retention in play has elapsed, so the scan
		// starts there instead of walking every row in the table.
		$horizon = $now - (min($periods) * 86400);
		$cursor = (string) Database::getSetting('retention_delete_cursor', '');

		try {
			$stmt = $pdo->prepare(
				"SELECT f.`id`, f.`original_name`, f.`uploaded_at`, f.`user_id`, u.`group_id`, u.`group_changed_at`, u.`role`
				 FROM `{$files}` f
				 LEFT JOIN `{$users}` u ON u.`id` = f.`user_id`
				 WHERE f.`uploaded_at` < ? AND f.`id` > ?
				 ORDER BY f.`id` ASC LIMIT 1000"
			);
			$stmt->execute([$horizon, $cursor]);
			$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
			if ($rows === [] && $cursor !== '') {
				$cursor = '';
				$stmt->execute([$horizon, $cursor]);
				$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
			}
			if ($rows !== []) {
				Database::setSetting(
					'retention_delete_cursor',
					(string) $rows[array_key_last($rows)]['id']
				);
			}

			$expired = [];
			$byOwner = [];  // owner id → [count, one file name to put in the sentence]
			foreach ($rows as $row) {
				if ($row['user_id'] === null) {
					$days = $retention['guest'];                 // guest upload
				} elseif ($row['group_id'] === null) {
					$days = $retention['default'];               // account with no explicit group
				} else {
					// A group deleted since the file was uploaded leaves a dangling id; those
					// accounts resolve to the default group everywhere else, so do the same.
					$days = $retention['groups'][(int) $row['group_id']] ?? $retention['default'];
				}
				if (($row['role'] ?? '') === 'moderator') {
					$staffDays = $retention['moderator'];
					$days = ($days <= 0 || $staffDays <= 0) ? 0 : max($days, $staffDays);
				}
				if ($days <= 0) {
					continue;
				}
				$start = max((int) $row['uploaded_at'], (int) ($row['group_changed_at'] ?? 0));
				if ($start + ($days * 86400) <= $now) {
					$expired[] = $row['id'];
					$owner = (int) ($row['user_id'] ?? 0);
					if ($owner) {
						$byOwner[$owner] ??= ['n' => 0, 'name' => (string) $row['original_name']];
						$byOwner[$owner]['n']++;
					}
				}
			}

			if (!$expired) {
				return 0;
			}

			$removed = self::deleteFileIds($expired, null, 'retention', 'system');
			if ($removed === 0) {
				return 0;
			}

			// One line per owner, not per file: a sweep that clears out forty old uploads is one
			// piece of news. The whole batch shares a stack key so a second sweep the same day
			// adds to it rather than starting a second announcement.
			foreach ($byOwner as $ownerId => $info) {
				Notifications::send($ownerId, 'file.deleted', [
					'subject' => $info['name'],
					'by' => $info['n'],
					'group' => 'file.deleted:retention',
					'link' => (defined('APP_URL') ? APP_URL : '') . '/panel.php?tab=myfiles',
				]);
			}

			return $removed;
		} catch (PDOException $e) {
			return 0;
		}
	}

	/**
	 * Warn owners about uploads that retention is about to remove.
	 *
	 * The counterpart to deleteExpiredFiles(): same arithmetic, run a few days earlier, so
	 * "your files were deleted" is never the first anyone hears of it. Each file is warned about
	 * once ever — the notification row is its own record of that, which is what `once` means —
	 * so this is safe to run from a cron that fires every quarter hour.
	 *
	 * @param int $daysAhead how much notice to give
	 * @return int how many accounts were told
	 */
	public static function warnExpiringFiles(int $daysAhead = 3): int
	{
		$pdo = Database::getInstance();
		if (!$pdo || $daysAhead <= 0) {
			return 0;
		}

		$retention = GroupRepository::retentionMap();
		$periods = array_filter(array_merge(
			array_values($retention['groups']),
			[$retention['guest'], $retention['default']]
		));
		if (!$periods) {
			return 0;
		}

		$files = self::table('files');
		$users = Database::table('users');
		$now = time();
		$warn = $daysAhead * 86400;
		// Only rows already inside the shortest retention minus the notice period can possibly
		// be due, so the scan starts there rather than at the beginning of the table.
		$horizon = $now - ((min($periods) * 86400) - $warn);
		$cursor = (string) Database::getSetting('retention_warn_cursor', '');

		try {
			$stmt = $pdo->prepare(
				"SELECT f.`id`, f.`original_name`, f.`uploaded_at`, f.`user_id`, u.`group_id`, u.`group_changed_at`, u.`role`
				 FROM `{$files}` f
				 JOIN `{$users}` u ON u.`id` = f.`user_id`
				 WHERE f.`uploaded_at` < ? AND f.`id` > ?
				 ORDER BY f.`id` ASC LIMIT 1000"
			);
			$stmt->execute([$horizon, $cursor]);
			$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
			if ($rows === [] && $cursor !== '') {
				$cursor = '';
				$stmt->execute([$horizon, $cursor]);
				$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
			}
			if ($rows !== []) {
				Database::setSetting(
					'retention_warn_cursor',
					(string) $rows[array_key_last($rows)]['id']
				);
			}
		} catch (PDOException $e) {
			return 0;
		}

		$byOwner = [];
		foreach ($rows as $row) {
			$days = $row['group_id'] === null
				? $retention['default']
				: ($retention['groups'][(int) $row['group_id']] ?? $retention['default']);
			if (($row['role'] ?? '') === 'moderator') {
				$staffDays = $retention['moderator'];
				$days = ($days <= 0 || $staffDays <= 0) ? 0 : max($days, $staffDays);
			}
			if ($days <= 0) {
				continue;
			}
			$start = max((int) $row['uploaded_at'], (int) ($row['group_changed_at'] ?? 0));
			$dueAt = $start + ($days * 86400);
			// Inside the notice window but not gone yet. Anything already past due is the
			// deletion sweep's business, not a warning.
			if ($dueAt > $now && $dueAt - $now <= $warn) {
				$owner = (int) $row['user_id'];
				$byOwner[$owner] ??= ['n' => 0, 'name' => (string) $row['original_name'], 'due' => $dueAt];
				$byOwner[$owner]['n']++;
				$byOwner[$owner]['due'] = min($byOwner[$owner]['due'], $dueAt);
			}
		}

		foreach ($byOwner as $ownerId => $info) {
			Notifications::send($ownerId, 'file.expiring', [
				'subject' => $info['name'],
				'by' => $info['n'],
				'data' => ['date' => date('d.m.Y', $info['due'])],
				// Once per account per due date: re-running the sweep must not re-announce it,
				// and a genuinely new batch falling due later has a different key.
				'group' => 'file.expiring:' . date('Y-m-d', $info['due']),
				'once' => true,
				'link' => (defined('APP_URL') ? APP_URL : '') . '/panel.php?tab=myfiles',
			]);
		}
		return count($byOwner);
	}

	/**
	 * Stream a file to the client.
	 *
	 * @param bool      $count  Whether this access counts as a download (increments the
	 *                          counter and enforces the concurrent-download limit).
	 * @param bool|null $inline Content-Disposition: true = inline (render in browser),
	 *                          false = attachment (force download). Defaults to `!$count`,
	 *                          preserving the old behaviour (download→attachment, preview→inline).
	 *                          Embed/hotlink uses count=true + inline=true (counts, but renders).
	 */
	public static function streamFile(
		string $id,
		bool $count = true,
		?bool $inline = null,
		bool $passwordAuthorized = false
	): void
	{
		if ($inline === null) {
			$inline = !$count;
		}

		$file = self::getFile($id);

		if (!$file || !file_exists($file['path'])) {
			http_response_code(404);
			exit('File not found');
		}
		$range = self::parseByteRange($_SERVER['HTTP_RANGE'] ?? null, (int) $file['size']);
		if ($range['status'] === 416) {
			http_response_code(416);
			header('Accept-Ranges: bytes');
			header('Content-Range: bytes */' . (int) $file['size']);
			header('Content-Length: 0');
			exit;
		}

		// One-time links ("burn after reading"): any counted access (real download or
		// embed/hotlink) claims the link atomically. A second attempt — or a concurrent
		// one that lost the race — is refused. Previews ($count === false) never burn it.
		$mode = !$count ? 'preview' : ($inline ? 'embed' : 'download');
		$authorization = self::authorizeFileRead($id, $mode, $passwordAuthorized);
		if (!$authorization['allowed']) {
			http_response_code($authorization['status']);
			header('Cache-Control: no-store');
			exit($authorization['message']);
		}

		$ip = getClientIP();

		$user = getCurrentUser();
		$limitKey = $user ? 'concurrent_downloads_user' : 'concurrent_downloads_guest';
		$defaultLimit = $user ? 0 : 1; // Default: 0 (unlimited) for user, 1 for guest

		$limit = (int) Database::getSetting($limitKey, $defaultLimit);
		$activeId = null;

		if ($count && $limit > 0) {
			$current = Database::getConcurrentDownloads($ip);
			if ($current >= $limit) {
				http_response_code(429);
				die("Too many concurrent downloads. Limit is $limit.");
			}
			$activeId = Database::addActiveDownload($ip, $id);
		}

		// Ensure cleanup on shutdown/exit
		register_shutdown_function(function () use ($activeId) {
			if ($activeId) {
				Database::removeActiveDownload($activeId);
			}
		});

		try {
			// Previews ($count === false) must not count as downloads — only the actual
			// download path (here or the Python /download endpoint) and embed/hotlink increment.
			// `$count && $inline` is precisely the embed/hotlink case (see the docblock), which
			// the owner's notifications treat separately from a real download.
			if ($count) {
				self::incrementDownloads($id, $inline);
			}

			$filePath = $file['path'];
			$fileName = $file['name'];
			$mimeType = self::normaliseMime((string) $file['mimeType']);
			$fileSize = $file['size'];

			// Defence in depth: callers should reject unsupported previews before reaching the
			// stream, but a future path must not be able to inline HTML/SVG/XML by mistake.
			if ($inline && !self::isInlinePreviewAllowed($mimeType)) {
				$inline = false;
				$mimeType = 'application/octet-stream';
			}

			$start = $range['start'];
			$end = $range['end'];

			if ($range['status'] === 206) {
				http_response_code(206);
				header("Content-Range: bytes {$start}-{$end}/{$fileSize}");
			}

			$length = $end - $start + 1;

			header('X-Content-Type-Options: nosniff');
			header('Content-Type: ' . $mimeType);
			header('Content-Length: ' . $length);
			header('Accept-Ranges: bytes');

			$safeName = basename(str_replace('\\', '/', $fileName));
			$safeName = preg_replace('/[\x00-\x1f\x7f"\\\\]/', '_', $safeName) ?: 'download';
			$safeName = preg_replace('/[^\x20-\x7e]/', '_', $safeName) ?: 'download';
			$disposition = $inline ? 'inline' : 'attachment';
			header(
				"Content-Disposition: {$disposition}; filename=\"{$safeName}\"; filename*=UTF-8''"
				. rawurlencode($fileName)
			);
			if ($inline) {
				// Even if a browser/parser ever disagrees with our MIME allowlist, the resource
				// remains a unique-origin, scriptless sandbox rather than an app-origin document.
				header("Content-Security-Policy: sandbox; default-src 'none'; img-src data: blob:; media-src blob:; style-src 'unsafe-inline'");
			}

			$handle = fopen($filePath, 'rb');
			if ($start > 0) {
				fseek($handle, $start);
			}

			$bufferSize = 8192;
			$remaining = $length;

			while (!feof($handle) && $remaining > 0) {
				// Check connection status to abort if client disconnects
				if (connection_aborted()) {
					break;
				}
				$read = min($bufferSize, $remaining);
				echo fread($handle, $read);
				$remaining -= $read;
				flush();
			}

			fclose($handle);
		} finally {
			if ($activeId) {
				Database::removeActiveDownload($activeId);
			}
		}
		exit;
	}
	public static function deleteAllUserFiles(int $userId): int
	{
		$pdo = Database::getInstance();
		if (!$pdo)
			return 0;

		$table = self::table('files');
		try {
			$stmt = $pdo->prepare("SELECT `id` FROM `{$table}` WHERE `user_id` = ?");
			$stmt->execute([$userId]);
			return self::deleteFileIds(
				$stmt->fetchAll(PDO::FETCH_COLUMN),
				$userId,
				'owner_delete_all',
				'user',
				(string) $userId
			);
		} catch (PDOException $e) {
			return 0;
		}
	}

	public static function cleanupFiles(array $criteria): int
	{
		$preview = self::previewCleanup($criteria);
		if (!$preview['valid'] || $preview['ids'] === []) {
			return 0;
		}
		return self::deleteCleanupCandidates($preview['ids']);
	}

	/**
	 * Preview the exact bounded batch selected by destructive cleanup criteria.
	 *
	 * @return array{valid: bool, ids: string[], count: int, total_size: int}
	 */
	public static function previewCleanup(array $criteria): array
	{
		$pdo = Database::getInstance();
		if (!$pdo)
			return ['valid' => false, 'ids' => [], 'count' => 0, 'total_size' => 0];

		$table = self::table('files');
		$conditions = [];
		$params = [];

		// Filter by age (older than X days)
		if (isset($criteria['older_than_days']) && $criteria['older_than_days'] !== '') {
			$days = (int) $criteria['older_than_days'];
			if ($days <= 0) {
				return ['valid' => false, 'ids' => [], 'count' => 0, 'total_size' => 0];
			}
			$cutoff = time() - ($days * 86400);
			$conditions[] = "`uploaded_at` < ?";
			$params[] = $cutoff;
		}

		// Filter by size (larger than X MB)
		if (isset($criteria['larger_than_mb']) && $criteria['larger_than_mb'] !== '') {
			$mb = (float) $criteria['larger_than_mb'];
			if (!is_finite($mb) || $mb <= 0) {
				return ['valid' => false, 'ids' => [], 'count' => 0, 'total_size' => 0];
			}
			$bytes = $mb * 1024 * 1024;
			$conditions[] = "`size` > ?";
			$params[] = $bytes;
		}

		if (empty($conditions)) {
			return ['valid' => false, 'ids' => [], 'count' => 0, 'total_size' => 0];
		}

		$whereSQL = implode(' AND ', $conditions);

		try {
			$sql = "SELECT `id`, `size` FROM `{$table}`
				WHERE {$whereSQL} ORDER BY `uploaded_at` ASC, `id` ASC LIMIT 1000";
			$stmt = $pdo->prepare($sql);
			$stmt->execute($params);
			$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
			return [
				'valid' => true,
				'ids' => array_values(array_map('strval', array_column($rows, 'id'))),
				'count' => count($rows),
				'total_size' => array_sum(array_map(
					static fn(array $row): int => (int) $row['size'],
					$rows
				)),
			];
		} catch (PDOException $e) {
			error_log("Cleanup error: " . $e->getMessage());
			return ['valid' => false, 'ids' => [], 'count' => 0, 'total_size' => 0];
		}
	}

	/** Execute only the server-side candidate IDs saved during a confirmed preview. */
	public static function deleteCleanupCandidates(array $ids): int
	{
		return self::deleteFileIds($ids, null, 'admin_cleanup', 'admin');
	}
}
