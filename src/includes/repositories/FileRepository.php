<?php
/**
 * FileRepository (Faza 5 · #2).
 *
 * The stored `files` records themselves: per-file sharing options (expiry, download cap,
 * one-time, password), the race-safe one-time "burn", password checks, a user's own file
 * listings, and their aggregate stats. Extracted from the Database god-object; the matching
 * Database::* methods delegate here. Physical file bytes stay with FileManager — this owns
 * only the DB rows. Writes scoped by $ownerId let users manage only their own files.
 */
final class FileRepository
{
	/**
	 * Set per-file sharing options. When $ownerId is provided the update only applies
	 * to files owned by that user (so users can only manage their own files).
	 */
	public static function setOptions(string $fileId, ?int $ownerId, ?int $expiresAt, ?int $maxDownloads, ?string $password, bool $clearPassword = false, bool $oneTime = false, string $onLimitAction = 'keep'): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$table = Database::table('files');
		// What happens once the download cap is spent: keep the file (it simply stops serving)
		// or delete it. Anything unrecognised falls back to the non-destructive option.
		$onLimit = $onLimitAction === 'delete' ? 'delete' : 'keep';
		$sets = ['`expires_at` = ?', '`max_downloads` = ?', '`one_time` = ?', '`on_limit_action` = ?'];
		$params = [$expiresAt ?: null, $maxDownloads ?: null, $oneTime ? 1 : 0, $onLimit];

		// (Re-)arming a one-time link clears any prior "burned" state so the share works
		// afresh; turning it off leaves consumed_at untouched (it is ignored when one_time = 0).
		if ($oneTime) {
			$sets[] = '`consumed_at` = NULL';
		}

		if ($clearPassword) {
			$sets[] = '`password_hash` = NULL';
		} elseif ($password !== null && $password !== '') {
			$sets[] = '`password_hash` = ?';
			$params[] = password_hash($password, PASSWORD_DEFAULT);
		}

		$where = '`id` = ?';
		$params[] = $fileId;
		if ($ownerId !== null) {
			$where .= ' AND `user_id` = ?';
			$params[] = $ownerId;
		}

		try {
			$stmt = $pdo->prepare("UPDATE `{$table}` SET " . implode(', ', $sets) . " WHERE {$where}");
			$stmt->execute($params);
			if ($stmt->rowCount() > 0) {
				return true;
			}
			$checkParams = [$fileId];
			$checkWhere = '`id` = ?';
			if ($ownerId !== null) {
				$checkWhere .= ' AND `user_id` = ?';
				$checkParams[] = $ownerId;
			}
			$check = $pdo->prepare("SELECT 1 FROM `{$table}` WHERE {$checkWhere}");
			$check->execute($checkParams);
			return (bool) $check->fetchColumn();
		} catch (PDOException $e) {
			return false;
		}
	}

	/**
	 * Atomically "burn" a one-time link at the moment it is served.
	 *
	 * Returns true when the file may be delivered now:
	 *   - it is not a one-time link (nothing to burn), or
	 *   - it is a one-time link that this call just claimed (it was still fresh).
	 * Returns false only when it is a one-time link that has already been consumed
	 * (by a previous or a concurrent request).
	 *
	 * The claim is a single conditional UPDATE (`consumed_at IS NULL`), so two racing
	 * downloads can never both succeed: exactly one changes the row (rowCount 1), the
	 * other matches nothing (rowCount 0) and is refused. On a DB error we fail open, to
	 * match the rest of the download path (limits are advisory, not a hard gate).
	 */
	public static function claimOneTime(string $fileId): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$table = Database::table('files');
		try {
			$stmt = $pdo->prepare("SELECT `one_time`, `consumed_at` FROM `{$table}` WHERE `id` = ?");
			$stmt->execute([$fileId]);
			$row = $stmt->fetch();
			if (!$row || (int) $row['one_time'] !== 1) {
				return true; // not a one-time link — nothing to burn (common case, 1 query)
			}
			if ($row['consumed_at'] !== null) {
				return false; // already used up
			}
			// Race-safe burn: only the request that flips consumed_at from NULL wins.
			$upd = $pdo->prepare("UPDATE `{$table}` SET `consumed_at` = ? WHERE `id` = ? AND `one_time` = 1 AND `consumed_at` IS NULL");
			$upd->execute([time(), $fileId]);
			return $upd->rowCount() > 0;
		} catch (PDOException $e) {
			error_log('One-time file claim failed: ' . $e->getMessage());
			return false;
		}
	}

	/** True only when the file is a one-time link that has already been used up. */
	public static function oneTimeConsumed(string $fileId): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$table = Database::table('files');
		try {
			$stmt = $pdo->prepare("SELECT `one_time`, `consumed_at` FROM `{$table}` WHERE `id` = ?");
			$stmt->execute([$fileId]);
			$row = $stmt->fetch();
			return $row && (int) $row['one_time'] === 1 && $row['consumed_at'] !== null;
		} catch (PDOException $e) {
			return false;
		}
	}

	/**
	 * Set (or clear) only a file's password, leaving expiry/download-cap untouched.
	 * Ownership is verified by the caller (delete-token or session), so this method
	 * intentionally does not scope by user.
	 */
	public static function setPassword(string $fileId, ?string $password, bool $clear = false): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$table = Database::table('files');
		try {
			if ($clear) {
				$stmt = $pdo->prepare("UPDATE `{$table}` SET `password_hash` = NULL WHERE `id` = ?");
				return $stmt->execute([$fileId]);
			}
			$stmt = $pdo->prepare("UPDATE `{$table}` SET `password_hash` = ? WHERE `id` = ?");
			return $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $fileId]);
		} catch (PDOException $e) {
			return false;
		}
	}

	/**
	 * The share-limiting columns for one file, so a caller can refuse a download with a
	 * readable reason before handing off to the streaming endpoint (which can only answer
	 * with a bare 410). Returns an empty array when the file or the columns are absent.
	 */
	public static function sharingState(string $fileId): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return [];
		}
		try {
			$stmt = $pdo->prepare("SELECT `downloads`, `max_downloads`, `expires_at`,
				`on_limit_action`, `one_time`, `consumed_at`, `password_hash`
				FROM `" . Database::table('files') . "` WHERE `id` = ?");
			$stmt->execute([$fileId]);
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			if (!$row) {
				return ['found' => false];
			}
			$row['found'] = true;
			return $row;
		} catch (PDOException $e) {
			error_log('File sharing-state lookup failed: ' . $e->getMessage());
			return [];
		}
	}

	/** True if the file carries a password hash. */
	public static function isProtected(string $fileId): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$table = Database::table('files');
		try {
			$stmt = $pdo->prepare("SELECT `password_hash` FROM `{$table}` WHERE `id` = ?");
			$stmt->execute([$fileId]);
			$hash = $stmt->fetchColumn();
			return !empty($hash);
		} catch (PDOException $e) {
			return false;
		}
	}

	/** True when the file has no password, or the supplied one matches its hash. */
	public static function verifyPassword(string $fileId, string $password): bool
	{
		if (strlen($password) > InputLimits::PASSWORD_MAX) {
			return false;
		}
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$table = Database::table('files');
		try {
			$stmt = $pdo->prepare("SELECT `password_hash` FROM `{$table}` WHERE `id` = ?");
			$stmt->execute([$fileId]);
			$hash = $stmt->fetchColumn();
			if ($hash === false) {
				return false;
			}
			return $hash === null || $hash === '' || password_verify($password, (string) $hash);
		} catch (PDOException $e) {
			return false;
		}
	}

	/**
	 * True when $token is the file's delete token (pt 1).
	 *
	 * The delete token is handed out once, to whoever uploaded the file, and is what the upload
	 * page already uses to prove "this upload is mine" when setting or clearing its password. So
	 * it is also the right proof for "I may put my own just-uploaded file into a collection
	 * without retyping the password I set on it seconds ago" — knowledge of it is strictly
	 * stronger evidence than holding the account session, which is the case pt 1 closes.
	 */
	public static function verifyDeleteToken(string $fileId, string $token): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo || $token === '' || strlen($token) > InputLimits::SHORT_TEXT_MAX) {
			return false;
		}
		$table = Database::table('files');
		try {
			$stmt = $pdo->prepare("SELECT `delete_token` FROM `{$table}` WHERE `id` = ?");
			$stmt->execute([$fileId]);
			$hash = $stmt->fetchColumn();
			return !empty($hash) && password_verify($token, (string) $hash);
		} catch (PDOException $e) {
			return false;
		}
	}

	/** All of a user's files, newest first. */
	public static function forUser(int $userId): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return [];
		}
		$table = Database::table('files');
		try {
			$stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE `user_id` = ? ORDER BY `uploaded_at` DESC");
			$stmt->execute([$userId]);
			return $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (PDOException $e) {
			return [];
		}
	}

	/**
	 * Bounded, keyset-paginated projection for external APIs.
	 *
	 * @return array{files:array,next:?array{uploaded_at:int,id:string}}
	 */
	public static function pageForUser(
		int $userId,
		int $limit,
		?int $beforeUploadedAt = null,
		?string $beforeId = null
	): array {
		$pdo = Database::getInstance();
		if (!$pdo) {
			return ['files' => [], 'next' => null];
		}
		$limit = max(1, min(100, $limit));
		$table = Database::table('files');
		$where = '`user_id` = ?';
		$params = [$userId];
		if ($beforeUploadedAt !== null && $beforeId !== null && $beforeId !== '') {
			$where .= ' AND (`uploaded_at` < ? OR (`uploaded_at` = ? AND `id` < ?))';
			array_push($params, $beforeUploadedAt, $beforeUploadedAt, $beforeId);
		}
		try {
			$stmt = $pdo->prepare(
				"SELECT `id`, `original_name`, `mime_type`, `size`, `downloads`, `uploaded_at`,
				        `one_time`, `password_hash`, `expires_at`
				 FROM `{$table}` WHERE {$where}
				 ORDER BY `uploaded_at` DESC, `id` DESC LIMIT ?"
			);
			foreach ([...$params, $limit + 1] as $index => $value) {
				$stmt->bindValue(
					$index + 1,
					$value,
					is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
				);
			}
			$stmt->execute();
			$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
			$hasMore = count($rows) > $limit;
			if ($hasMore) {
				array_pop($rows);
			}
			$last = $hasMore ? end($rows) : false;
			return [
				'files' => $rows,
				'next' => $last ? [
					'uploaded_at' => (int) $last['uploaded_at'],
					'id' => (string) $last['id'],
				] : null,
			];
		} catch (PDOException $e) {
			return ['files' => [], 'next' => null];
		}
	}

	/** One of a user's files by id, or null if it isn't theirs. */
	public static function oneForUser(int $userId, string $fileId): ?array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return null;
		}
		$table = Database::table('files');
		try {
			$stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE `id` = ? AND `user_id` = ?");
			$stmt->execute([$fileId, $userId]);
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			return $row ?: null;
		} catch (PDOException $e) {
			return null;
		}
	}

	/** Delete all of a user's file rows (admin "clean files"; physical bytes handled by the caller). */
	public static function deleteAllForUser(int $userId): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$table = Database::table('files');
		try {
			$stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE `user_id` = ?");
			return $stmt->execute([$userId]);
		} catch (PDOException $e) {
			return false;
		}
	}

	/** Aggregate file stats for a user: count, total size, total downloads. */
	public static function statsForUser(int $userId): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return ['files_count' => 0, 'total_size' => 0, 'total_downloads' => 0];
		}
		$filesTable = Database::table('files');
		try {
			$stmt = $pdo->prepare("SELECT COUNT(*) as count, COALESCE(SUM(size), 0) as size, COALESCE(SUM(downloads), 0) as downloads FROM `{$filesTable}` WHERE `user_id` = ?");
			$stmt->execute([$userId]);
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			return [
				'files_count' => (int) ($row['count'] ?? 0),
				'total_size' => (float) ($row['size'] ?? 0),
				'total_downloads' => (int) ($row['downloads'] ?? 0)
			];
		} catch (PDOException $e) {
			return ['files_count' => 0, 'total_size' => 0, 'total_downloads' => 0];
		}
	}
}
