<?php
require_once __DIR__ . '/../ClientIp.php';

/**
 * BanRepository (Faza 5 · #2).
 *
 * IP / username / email blacklist with optional expiry. Extracted from the Database
 * god-object; the matching Database::* methods delegate here. Expired entries are
 * cleaned lazily on read (single row in details(), bulk before listing) — these are
 * admin/moderation paths, not the upload/download hot paths.
 *
 * Address bans follow the throttling key (filehostThrottleKey): an IPv4 address is banned
 * as itself, an IPv6 one as its /64 network. A client handed a /64 picks any of 2^64
 * addresses, and privacy extensions rotate them daily anyway, so banning the exact address
 * stopped only the address used at the time of the ban.
 */
final class BanRepository
{
	/**
	 * The value an address ban is stored under: IPv4 (also IPv4-mapped IPv6) as the dotted
	 * address, any other IPv6 address as its /64 network key ("2001:db8:1:2::/64"), which an
	 * administrator may also type directly. Anything that is not an address is left as typed.
	 */
	public static function normaliseIpValue(string $value): string
	{
		$value = trim($value);
		if (preg_match('#\A(.+)/64\z#', $value, $match) === 1
			&& filter_var($match[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
			return filehostThrottleKey($match[1]);
		}
		if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
			return filehostThrottleKey($value);
		}
		return $value;
	}

	public static function add(string $type, string $value, ?int $expiresAt = null, string $reason = ''): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		if ($type === 'ip') {
			$value = self::normaliseIpValue($value);
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

	/**
	 * The ban that applies to $value, or null. For an address that is a ban of the exact
	 * address (rows written before bans were stored per network) or of its /64.
	 */
	public static function details(string $type, string $value): ?array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return null;
		}
		$table = Database::table('blacklists');
		$values = [$value];
		if ($type === 'ip') {
			$values[] = filehostThrottleKey(trim($value));
			$values = array_values(array_unique($values));
		}
		try {
			$stmt = $pdo->prepare(
				"SELECT * FROM `{$table}` WHERE `type` = ? AND `value` IN ("
				. implode(',', array_fill(0, count($values), '?')) . ")"
			);
			$stmt->execute(array_merge([$type], $values));
			foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
				// Expired? auto-clean this row and treat it as not banned.
				if ($row['expires_at'] && $row['expires_at'] < time()) {
					$pdo->prepare("DELETE FROM `{$table}` WHERE `id` = ?")->execute([$row['id']]);
					continue;
				}
				return $row;
			}
			return null;
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
