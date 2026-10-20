<?php
require_once __DIR__ . '/../ClientIp.php';

/**
 * ReportRepository (Faza 5 · #2).
 *
 * Abuse reports against files (Report → Moderate). Handles submission, the moderation
 * list/detail views, rejection, and cascade removal when a file is deleted. Also owns the
 * report throttle, counted per network (an IPv6 /64, see filehostThrottleKey) in
 * security_events over the security window:
 *   - every accepted report, which nothing but the window resets — the hard cap
 *     (NETWORK_HARD_LIMIT) that holds with or without a CAPTCHA;
 *   - reports since the last passed CAPTCHA — countForIP()/markVerified(), the "N reports,
 *     then a CAPTCHA" rule;
 *   - which files the network has reported — a repeat is not stored twice.
 * Extracted from the Database god-object; the matching Database::* methods delegate here.
 */
final class ReportRepository
{
	/** Reports one network may file per security window, CAPTCHA or not. */
	public const NETWORK_HARD_LIMIT = 20;

	private const SENT_EVENT = 'report_sent';
	private const UNVERIFIED_EVENT = 'report_unverified';

	/** The security_events type marking that a network reported this file. */
	private static function fileEvent(string $fileId): string
	{
		return 'rpt:' . substr(sha1($fileId), 0, 24);
	}

	/** Every report accepted from $ip's network in the current window. */
	public static function sentFromNetwork(string $ip): int
	{
		return Database::getSecurityEvent(filehostThrottleKey($ip), self::SENT_EVENT);
	}

	/** Whether $ip's network already reported this file in the current window. */
	public static function alreadyReported(string $ip, string $fileId): bool
	{
		return Database::getSecurityEvent(filehostThrottleKey($ip), self::fileEvent($fileId)) > 0;
	}

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

			$ip = getClientIP();
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
				$ip
			]);
			$reportId = $pdo->lastInsertId();

			$network = filehostThrottleKey($ip);
			Database::incrementSecurityEvent($network, self::SENT_EVENT);
			Database::incrementSecurityEvent($network, self::UNVERIFIED_EVENT);
			Database::incrementSecurityEvent($network, self::fileEvent($fileId));

			return ['success' => true, 'report_id' => $reportId];
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
	 * Record that $ip just passed a CAPTCHA for reporting: its network's count towards the next
	 * CAPTCHA starts again. The hard cap (sentFromNetwork) is not touched.
	 */
	public static function markVerified(string $ip): void
	{
		Database::clearSecurityEvent(filehostThrottleKey($ip), self::UNVERIFIED_EVENT);
	}

	/**
	 * Reports from $ip's network since its last passed CAPTCHA (markVerified), within the
	 * security window (`recaptcha_security_window`, which is also what every caller passes as
	 * $minutes) — so a legitimate reporter isn't blocked after proving they're human.
	 */
	public static function countForIP(string $ip, int $minutes): int
	{
		return Database::getSecurityEvent(filehostThrottleKey($ip), self::UNVERIFIED_EVENT);
	}
}
