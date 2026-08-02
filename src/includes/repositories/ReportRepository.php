<?php
/**
 * ReportRepository (Faza 5 · #2).
 *
 * Abuse reports against files (Report → Moderate). Handles submission, the moderation
 * list/detail views, rejection, and cascade removal when a file is deleted. Also owns the
 * per-IP report throttle: countForIP() and markVerified() implement the "N reports / window,
 * reset after a passed CAPTCHA" rule (the reset flag lives in security_events). Extracted
 * from the Database god-object; the matching Database::* methods delegate here.
 */
final class ReportRepository
{
	public static function add(string $fileId, array $data): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return ['success' => false, 'error' => 'Database error'];
		}

		$filesTable = Database::table('files');
		$reportsTable = Database::table('reports');

		// Verify file exists
		$stmt = $pdo->prepare("SELECT id FROM `{$filesTable}` WHERE `id` = ?");
		$stmt->execute([$fileId]);
		if (!$stmt->fetch()) {
			return ['success' => false, 'error' => 'File does not exist'];
		}

		try {
			$stmt = $pdo->prepare("INSERT INTO `{$reportsTable}`
				(`file_id`, `reporter_name`, `reporter_email`, `reporter_entity`, `reporter_org`, `report_title`, `report_link`, `additional_info`, `created_at`, `ip_address`)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

			$stmt->execute([
				$fileId,
				$data['name'],
				$data['email'],
				$data['entity'] ?? '',
				$data['org'] ?? '',
				$data['title'],
				$data['link'] ?? '',
				$data['info'] ?? '',
				time(),
				getClientIP()
			]);

			return ['success' => true, 'report_id' => $pdo->lastInsertId()];
		} catch (PDOException $e) {
			error_log("Report error: " . $e->getMessage());
			return ['success' => false, 'error' => 'Database error'];
		}
	}

	public static function listReported(int $page = 1, int $limit = 20): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return ['reports' => [], 'total' => 0];
		}

		$reportsTable = Database::table('reports');
		$filesTable = Database::table('files');
		$offset = ($page - 1) * $limit;

		try {
			$stmt = $pdo->query("SELECT COUNT(*) FROM `{$reportsTable}`");
			$total = (int) $stmt->fetchColumn();

			$sql = "SELECT r.*, f.original_name, f.size, f.uploaded_at as file_uploaded_at
					FROM `{$reportsTable}` r
					LEFT JOIN `{$filesTable}` f ON r.file_id = f.id
					ORDER BY r.created_at DESC
					LIMIT :limit OFFSET :offset";

			$stmt = $pdo->prepare($sql);
			$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
			$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
			$stmt->execute();

			return ['reports' => $stmt->fetchAll(), 'total' => $total];
		} catch (PDOException $e) {
			return ['reports' => [], 'total' => 0];
		}
	}

	public static function details(int $reportId): ?array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return null;
		}

		$reportsTable = Database::table('reports');
		$filesTable = Database::table('files');

		try {
			$stmt = $pdo->prepare("SELECT r.*, f.original_name, f.size, f.mime_type, f.uploaded_at as file_uploaded_at
				FROM `{$reportsTable}` r
				LEFT JOIN `{$filesTable}` f ON r.file_id = f.id
				WHERE r.id = ?");
			$stmt->execute([$reportId]);
			return $stmt->fetch() ?: null;
		} catch (PDOException $e) {
			return null;
		}
	}

	public static function reject(int $reportId): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}

		$reportsTable = Database::table('reports');

		try {
			$stmt = $pdo->prepare("DELETE FROM `{$reportsTable}` WHERE `id` = ?");
			return $stmt->execute([$reportId]);
		} catch (PDOException $e) {
			return false;
		}
	}

	/**
	 * Remove any moderation reports tied to the given file id(s). Called from every
	 * file-deletion path so deleting a file also closes its reports instead of leaving
	 * dead entries on the "Moderate" list. Chunked to keep the IN() list bounded on
	 * large bulk/cron cleanups. Returns the number of report rows removed.
	 */
	public static function deleteByFileIds(array $fileIds): int
	{
		if (empty($fileIds)) {
			return 0;
		}

		$pdo = Database::getInstance();
		if (!$pdo) {
			return 0;
		}

		$reportsTable = Database::table('reports');
		$fileIds = array_values(array_unique($fileIds));
		$removed = 0;

		try {
			foreach (array_chunk($fileIds, 500) as $chunk) {
				$placeholders = implode(',', array_fill(0, count($chunk), '?'));
				$stmt = $pdo->prepare("DELETE FROM `{$reportsTable}` WHERE `file_id` IN ({$placeholders})");
				$stmt->execute($chunk);
				$removed += $stmt->rowCount();
			}
			return $removed;
		} catch (PDOException $e) {
			return 0;
		}
	}

	/** True when $email has filed more than 5 reports in the last hour (fails safe → true on error). */
	public static function isSpammer(string $email): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$reportsTable = Database::table('reports');
		try {
			$stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$reportsTable}` WHERE `reporter_email` = ? AND `created_at` > ?");
			$stmt->execute([$email, time() - 3600]);
			return (int) $stmt->fetchColumn() > 5;
		} catch (PDOException $e) {
			return true; // Fail safe
		}
	}

	/** Record that $ip just passed a CAPTCHA for reporting — resets its report throttle window. */
	public static function markVerified(string $ip): void
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return;
		}
		$table = Database::table('security_events');
		try {
			$stmt = $pdo->prepare("INSERT INTO `{$table}` (`ip_address`, `event_type`, `counter`, `last_updated_at`)
				VALUES (?, 'report_verified', 1, ?)
				ON DUPLICATE KEY UPDATE `last_updated_at` = ?");
			$stmt->execute([$ip, time(), time()]);
		} catch (PDOException $e) {
		}
	}

	/**
	 * Number of reports from $ip within the last $minutes, but never counting past the
	 * last passed CAPTCHA (markVerified) — a verified report effectively resets the counter,
	 * so a legitimate reporter isn't blocked after proving they're human.
	 */
	public static function countForIP(string $ip, int $minutes): int
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return 0;
		}

		$table = Database::table('reports');
		$eventsTable = Database::table('security_events');
		try {
			// Check for last verification bypass
			$lastVerified = 0;
			try {
				$stmt = $pdo->prepare("SELECT `last_updated_at` FROM `{$eventsTable}` WHERE `ip_address` = ? AND `event_type` = 'report_verified'");
				$stmt->execute([$ip]);
				$lastVerified = (int) $stmt->fetchColumn();
			} catch (PDOException $e) { /* ignore */
			}

			$since = time() - ($minutes * 60);
			// Reset point is the LATEST of (Window Start, Last Verification)
			// This effectively "resets" the counter after a verified CAPTCHA
			if ($lastVerified > $since) {
				$since = $lastVerified;
			}

			$stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `ip_address` = ? AND `created_at` > ?");
			$stmt->execute([$ip, $since]);
			return (int) $stmt->fetchColumn();
		} catch (PDOException $e) {
			return 0;
		}
	}
}
