<?php
/**
 * AuditService (Faza 5 · #2).
 *
 * Append-only admin audit trail. Extracted from the Database god-object;
 * Database::logAudit / getAuditLog delegate here. Logging must never break the
 * primary action, so all failures are swallowed.
 */
final class AuditService
{
	public static function log(string $action, string $details = '', ?int $userId = null, ?string $username = null): void
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return;
		}
		if ($userId === null && isset($_SESSION['user_id'])) {
			$userId = (int) $_SESSION['user_id'];
			$username = $username ?? ($_SESSION['user_name'] ?? null);
		}
		$table = Database::table('audit_log');
		// getClientIP() belongs to the web bootstrap (src/config.php). Cron jobs load only
		// Database.php, so calling it unguarded raised an *Error* — which the PDOException
		// catch below does not stop — and killed the whole run the first time anything logged.
		$ip = function_exists('getClientIP') ? getClientIP() : 'cli';
		try {
			$stmt = $pdo->prepare("INSERT INTO `{$table}` (`user_id`, `username`, `action`, `details`, `ip_address`, `created_at`) VALUES (?, ?, ?, ?, ?, ?)");
			$stmt->execute([$userId, $username, $action, mb_substr($details, 0, 512), $ip, time()]);
		} catch (Throwable $e) {
			// Audit logging must never break the primary action.
		}
		// Opportunistic retention pruning (B7). Must never break the primary action.
		try {
			self::autoPrune();
		} catch (Throwable $e) {
		}
	}

	/**
	 * Delete audit entries older than $days days. 0/negative = keep forever. Returns rows removed.
	 */
	public static function prune(int $days): int
	{
		if ($days <= 0) {
			return 0;
		}
		$pdo = Database::getInstance();
		if (!$pdo) {
			return 0;
		}
		$table = Database::table('audit_log');
		try {
			$stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE `created_at` < ?");
			$stmt->execute([time() - $days * 86400]);
			return $stmt->rowCount();
		} catch (PDOException $e) {
			return 0;
		}
	}

	/**
	 * Run retention pruning at most once per day (there is no cron on this box, so audit
	 * writes drive it). Retention comes from the `audit_retention_days` setting (default 30;
	 * 0 disables). A `audit_last_prune_day` marker throttles it to one DELETE per day.
	 */
	private static function autoPrune(): void
	{
		$retention = (int) Database::getSetting('audit_retention_days', 30);
		if ($retention <= 0) {
			return;
		}
		$today = (int) floor(time() / 86400);
		if ((int) Database::getSetting('audit_last_prune_day', 0) >= $today) {
			return;
		}
		Database::setSetting('audit_last_prune_day', $today);
		self::prune($retention);
	}

	public static function getLog(int $page = 1, int $perPage = 30): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return ['entries' => [], 'total' => 0];
		}
		$table = Database::table('audit_log');
		$offset = ($page - 1) * $perPage;
		try {
			$total = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
			$stmt = $pdo->prepare("SELECT * FROM `{$table}` ORDER BY `created_at` DESC LIMIT ? OFFSET ?");
			$stmt->execute([$perPage, $offset]);
			return ['entries' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
		} catch (PDOException $e) {
			return ['entries' => [], 'total' => 0];
		}
	}
}
