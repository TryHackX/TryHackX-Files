<?php
/**
 * ActiveDownloadRepository (Faza 5 · #2).
 *
 * Tracks in-progress downloads (Faza 2.1): concurrency limits and the admin's live
 * view / kill-switch. Extracted from the Database god-object; the matching Database::*
 * methods delegate here. Stale rows are purged by cron (cleanupExpired); the
 * concurrency count filters by timestamp so a dead download can't inflate it.
 */
final class ActiveDownloadRepository
{
	public static function add(string $ip, string $fileId): ?int
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return null;
		}
		$table = Database::table('active_downloads');
		try {
			$now = time();
			$stmt = $pdo->prepare(
				"INSERT INTO `{$table}`
				 (`ip_address`, `file_id`, `started_at`, `instance_id`, `heartbeat_at`)
				 VALUES (?, ?, ?, ?, ?)"
			);
			$stmt->execute([$ip, $fileId, $now, 'php-' . getmypid(), $now]);
			return (int) $pdo->lastInsertId();
		} catch (PDOException $e) {
			return null;
		}
	}

	public static function remove(int $id): void
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return;
		}
		$table = Database::table('active_downloads');
		try {
			$stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE `id` = ?");
			$stmt->execute([$id]);
		} catch (PDOException $e) {
		}
	}

	public static function concurrentFor(string $ip): int
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return 0;
		}
		$table = Database::table('active_downloads');
		// Ignore stale entries (> 4 h) so a dead download can't inflate the total.
		$staleTime = time() - (4 * 3600);
		try {
			$stmt = $pdo->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE `ip_address` = ? AND `heartbeat_at` > ?"
			);
			$stmt->execute([$ip, $staleTime]);
			return (int) $stmt->fetchColumn();
		} catch (PDOException $e) {
			return 0;
		}
	}

	public static function count(): int
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return 0;
		}
		$table = Database::table('active_downloads');
		try {
			$stmt = $pdo->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE `heartbeat_at` >= ?"
			);
			$stmt->execute([time() - 3600]);
			return (int) $stmt->fetchColumn();
		} catch (PDOException $e) {
			return 0;
		}
	}

	/** Live list of in-progress downloads (Faza 2.1), joined with the file name. */
	/**
	 * Uploads currently in flight (pt 8) — the mirror of listActive().
	 *
	 * Written by the upload server as it streams, so a row can outlive its transfer if that
	 * process is killed mid-write. Rows untouched for a couple of minutes are therefore left
	 * out rather than shown as live, and swept by the cleanup job.
	 */
	public static function listActiveUploads(int $limit = 100): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return [];
		}
		$table = Database::table('active_uploads');
		$users = Database::table('users');
		$limit = max(1, min(500, $limit));
		$fresh = time() - 120;
		try {
			$stmt = $pdo->prepare("SELECT a.`id`, a.`ip_address`, a.`filename`, a.`size`, a.`received`,
					a.`started_at`, a.`updated_at`, u.`username`
				FROM `{$table}` a LEFT JOIN `{$users}` u ON u.`id` = a.`user_id`
				WHERE a.`status` = 'active' AND a.`updated_at` >= ?
				ORDER BY a.`started_at` ASC LIMIT {$limit}");
			$stmt->execute([$fresh]);
			return $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (PDOException $e) {
			return [];
		}
	}

	/**
	 * Mark an upload as cancelled. Keeping the row briefly lets both the Python stream and the
	 * browser observe the same terminal state.
	 */
	public static function killUpload(int $id): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		try {
			$stmt = $pdo->prepare(
				"UPDATE `" . Database::table('active_uploads') . "`
				 SET `status` = 'cancelled', `updated_at` = ?
				 WHERE `id` = ? AND `status` = 'active'"
			);
			$stmt->execute([time(), $id]);
			return $stmt->rowCount() > 0;
		} catch (PDOException $e) {
			return false;
		}
	}

	/** Public opaque client handle used only to stop the matching browser XHR. */
	public static function uploadStatus(string $clientId): ?string
	{
		if (!preg_match('/\A[a-f0-9]{32}\z/', $clientId)) {
			return null;
		}
		$pdo = Database::getInstance();
		if (!$pdo) {
			return null;
		}
		try {
			$stmt = $pdo->prepare(
				"SELECT `status` FROM `" . Database::table('active_uploads') . "`
				 WHERE `client_id` = ? AND `updated_at` >= ?
				 ORDER BY `id` DESC LIMIT 1"
			);
			$stmt->execute([$clientId, time() - 3600]);
			$status = $stmt->fetchColumn();
			return in_array($status, ['active', 'cancelled'], true) ? (string) $status : null;
		} catch (PDOException $e) {
			return null;
		}
	}

	/** Drop upload rows whose writer went away without cleaning up. */
	public static function pruneUploads(int $olderThan = 3600): int
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return 0;
		}
		try {
			$stmt = $pdo->prepare("DELETE FROM `" . Database::table('active_uploads') . "` WHERE `updated_at` < ?");
			$stmt->execute([time() - max(60, $olderThan)]);
			return $stmt->rowCount();
		} catch (PDOException $e) {
			return 0;
		}
	}

	public static function listActive(int $limit = 100): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return [];
		}
		$a = Database::table('active_downloads');
		$f = Database::table('files');
		$limit = max(1, min(500, $limit));
		try {
			$sql = "SELECT a.`id`, a.`ip_address`, a.`file_id`, a.`started_at`,
						a.`instance_id`, a.`heartbeat_at`, f.`original_name`, f.`size`
					FROM `{$a}` a LEFT JOIN `{$f}` f ON a.`file_id` = f.`id`
					WHERE a.`heartbeat_at` >= ?
					ORDER BY a.`started_at` ASC LIMIT {$limit}";
			$stmt = $pdo->prepare($sql);
			$stmt->execute([time() - 3600]);
			return $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (PDOException $e) {
			return [];
		}
	}

	/**
	 * Sever an in-progress download by deleting its lease row. Every file and collection
	 * response is streamed through a bounded iterator which notices the missing row.
	 */
	public static function kill(int $id): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		try {
			$stmt = $pdo->prepare("DELETE FROM `" . Database::table('active_downloads') . "` WHERE `id` = ?");
			return $stmt->execute([$id]);
		} catch (PDOException $e) {
			return false;
		}
	}
}
