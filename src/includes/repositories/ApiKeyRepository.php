<?php
/**
 * ApiKeyRepository (Faza 5 · #2).
 *
 * Per-user API keys (Faza 3.3) for programmatic / ShareX uploads. Only a SHA-256
 * hash of the key is stored (keys are high-entropy, so a fast deterministic hash is
 * enough and lets the upload server look a key up by hashing what the client presents).
 * Revoking = deleting the row. Extracted from the Database god-object; the matching
 * Database::* methods delegate here.
 */
final class ApiKeyRepository
{
	public static function create(int $userId, string $keyHash, string $prefix, string $label): int|false
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$table = Database::table('api_keys');
		try {
			$stmt = $pdo->prepare("INSERT INTO `{$table}` (`user_id`, `key_hash`, `key_prefix`, `label`, `created_at`) VALUES (?, ?, ?, ?, ?)");
			$stmt->execute([$userId, $keyHash, $prefix, $label, time()]);
			return (int) $pdo->lastInsertId();
		} catch (PDOException $e) {
			return false;
		}
	}

	public static function forUser(int $userId): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return [];
		}
		$table = Database::table('api_keys');
		try {
			$stmt = $pdo->prepare("SELECT `id`, `key_prefix`, `label`, `created_at`, `last_used_at` FROM `{$table}` WHERE `user_id` = ? ORDER BY `created_at` DESC");
			$stmt->execute([$userId]);
			return $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (PDOException $e) {
			return [];
		}
	}

	/** Count a user's keys — used to cap how many they can create. */
	public static function countForUser(int $userId): int
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return 0;
		}
		$table = Database::table('api_keys');
		try {
			$stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `user_id` = ?");
			$stmt->execute([$userId]);
			return (int) $stmt->fetchColumn();
		} catch (PDOException $e) {
			return 0;
		}
	}

	public static function revoke(int $id, int $userId): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$table = Database::table('api_keys');
		try {
			$stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE `id` = ? AND `user_id` = ?");
			$stmt->execute([$id, $userId]);
			return $stmt->rowCount() > 0;
		} catch (PDOException $e) {
			return false;
		}
	}

	/**
	 * Resolve an API key (raw, or "Bearer <key>") to its owner's user_id, stamping
	 * last_used_at. Only the SHA-256 is stored, so we hash the presented key and look it up.
	 * Mirrors the upload server's resolve_api_key. Returns null for a missing/unknown key.
	 */
	public static function resolve(string $raw): ?int
	{
		return self::resolveIdentity($raw)['user_id'] ?? null;
	}

	/**
	 * Resolve both the credential and its owner so each key gets an independent rate bucket.
	 *
	 * @return array{id:int,user_id:int}|null
	 */
	public static function resolveIdentity(string $raw): ?array
	{
		$key = trim($raw);
		if (stripos($key, 'bearer ') === 0) {
			$key = trim(substr($key, 7));
		}
		if ($key === '') {
			return null;
		}
		$pdo = Database::getInstance();
		if (!$pdo) {
			return null;
		}
		$table = Database::table('api_keys');
		$users = Database::table('users');
		try {
			$hash = hash('sha256', $key);
			$stmt = $pdo->prepare(
				"SELECT k.`id`, k.`user_id`
				 FROM `{$table}` k
				 INNER JOIN `{$users}` u ON u.`id` = k.`user_id`
				 WHERE k.`key_hash` = ? AND u.`is_active` = 1"
			);
			$stmt->execute([$hash]);
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			if (!$row) {
				return null;
			}
			$now = time();
			$upd = $pdo->prepare(
				"UPDATE `{$table}` SET `last_used_at` = ?
				 WHERE `id` = ? AND (`last_used_at` IS NULL OR `last_used_at` < ?)"
			);
			$upd->execute([$now, (int) $row['id'], $now - 60]);
			return ['id' => (int) $row['id'], 'user_id' => (int) $row['user_id']];
		} catch (PDOException $e) {
			return null;
		}
	}
}
