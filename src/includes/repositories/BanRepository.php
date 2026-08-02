<?php
/**
 * BanRepository (Faza 5 · #2).
 *
 * IP / username / email blacklist with optional expiry. Extracted from the Database
 * god-object; the matching Database::* methods delegate here. Expired entries are
 * cleaned lazily on read (single row in details(), bulk before listing) — these are
 * admin/moderation paths, not the upload/download hot paths.
 */
final class BanRepository
{
	public static function add(string $type, string $value, ?int $expiresAt = null, string $reason = ''): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$table = Database::table('blacklists');
		try {
			$stmt = $pdo->prepare("INSERT INTO `{$table}` (`type`, `value`, `created_at`, `expires_at`, `reason`) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE `expires_at`=VALUES(`expires_at`), `reason`=VALUES(`reason`), `created_at`=VALUES(`created_at`)");
			return $stmt->execute([$type, $value, time(), $expiresAt, $reason]);
		} catch (PDOException $e) {
			return false;
		}
	}

	public static function remove(int $id): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$table = Database::table('blacklists');
		try {
			$stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE `id` = ?");
			return $stmt->execute([$id]);
		} catch (PDOException $e) {
			return false;
		}
	}

	public static function isBanned(string $type, string $value): bool
	{
		return self::details($type, $value) !== null;
	}

	public static function details(string $type, string $value): ?array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return null;
		}
		$table = Database::table('blacklists');
		try {
			$stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE `type` = ? AND `value` = ?");
			$stmt->execute([$type, $value]);
			$row = $stmt->fetch();

			if (!$row) {
				return null;
			}

			// Expired? auto-clean this row and treat as not banned.
			if ($row['expires_at'] && $row['expires_at'] < time()) {
				$pdo->prepare("DELETE FROM `{$table}` WHERE `id` = ?")->execute([$row['id']]);
				return null;
			}

			return $row;
		} catch (PDOException $e) {
			return null;
		}
	}

	public static function list(int $page = 1, int $limit = 20): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return ['bans' => [], 'total' => 0];
		}

		$table = Database::table('blacklists');
		$offset = ($page - 1) * $limit;

		try {
			// Auto-clean expired before listing.
			$pdo->exec("DELETE FROM `{$table}` WHERE `expires_at` IS NOT NULL AND `expires_at` < " . time());

			$total = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();

			$stmt = $pdo->prepare("SELECT * FROM `{$table}` ORDER BY `created_at` DESC LIMIT :limit OFFSET :offset");
			$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
			$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
			$stmt->execute();

			return ['bans' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
		} catch (PDOException $e) {
			return ['bans' => [], 'total' => 0];
		}
	}
}
