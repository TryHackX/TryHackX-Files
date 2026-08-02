<?php
/**
 * RecoveryRepository (Faza 5 · #2).
 *
 * Password-recovery plumbing: per-IP attempt logging + count (rate-limiting the "forgot
 * password" flow) and short-lived, single-user reset tokens (15-minute validity, one live
 * token per user). Extracted from the Database god-object; the matching Database::* methods
 * delegate here. Old rows are purged by cron (Database::cleanupExpired); reads are time-filtered.
 */
final class RecoveryRepository
{
	public static function logAttempt(string $ip): void
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return;
		}
		$table = Database::table('recovery_attempts');
		try {
			$stmt = $pdo->prepare("INSERT INTO `{$table}` (`ip_address`, `attempted_at`) VALUES (?, ?)");
			$stmt->execute([$ip, time()]);
		} catch (PDOException $e) {
		}
	}

	public static function attemptsCount(string $ip, int $hours = 48): int
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return 0;
		}
		$table = Database::table('recovery_attempts');
		$since = time() - ($hours * 3600);
		try {
			$stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `ip_address` = ? AND `attempted_at` > ?");
			$stmt->execute([$ip, $since]);
			return (int) $stmt->fetchColumn();
		} catch (PDOException $e) {
			return 0;
		}
	}

	/** Issue a fresh 15-minute reset token, replacing any prior token for this user. */
	public static function createToken(int $userId): ?string
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return null;
		}
		$table = Database::table('recovery_tokens');
		$token = bin2hex(random_bytes(32));
		$tokenHash = hash('sha256', $token);
		$createdAt = time();
		$expiresAt = $createdAt + (15 * 60); // 15 minutes validity

		try {
			$stmt = $pdo->prepare(
				"INSERT INTO `{$table}` (`token`, `user_id`, `created_at`, `expires_at`)
				 VALUES (?, ?, ?, ?)
				 ON DUPLICATE KEY UPDATE
				 `token` = VALUES(`token`),
				 `created_at` = VALUES(`created_at`),
				 `expires_at` = VALUES(`expires_at`)"
			);
			$stmt->execute([$tokenHash, $userId, $createdAt, $expiresAt]);
			return $token;
		} catch (PDOException $e) {
			return null;
		}
	}

	/** Resolve a still-valid reset token to its user id, or null. */
	public static function verifyToken(string $token): ?int
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return null;
		}
		$table = Database::table('recovery_tokens');
		try {
			$stmt = $pdo->prepare("SELECT `user_id` FROM `{$table}` WHERE `token` = ? AND `expires_at` > ?");
			$stmt->execute([hash('sha256', $token), time()]);
			$row = $stmt->fetch();
			return $row ? (int) $row['user_id'] : null;
		} catch (PDOException $e) {
			return null;
		}
	}

	public static function deleteToken(string $token): void
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return;
		}
		$table = Database::table('recovery_tokens');
		try {
			$stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE `token` = ?");
			$stmt->execute([hash('sha256', $token)]);
		} catch (PDOException $e) {
		}
	}

	/**
	 * Atomically claim a reset capability, change the password and revoke all credentials.
	 */
	public static function consumeAndResetPassword(string $token, string $newPassword): bool
	{
		if (!PasswordPolicy::isValid($newPassword)) {
			return false;
		}
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$table = Database::table('recovery_tokens');
		$tokenHash = hash('sha256', $token);
		try {
			$pdo->beginTransaction();
			$stmt = $pdo->prepare(
				"SELECT `user_id` FROM `{$table}`
				 WHERE `token` = ? AND `expires_at` > ? FOR UPDATE"
			);
			$stmt->execute([$tokenHash, time()]);
			$userId = (int) ($stmt->fetchColumn() ?: 0);
			if ($userId < 1) {
				$pdo->rollBack();
				return false;
			}

			if (!UserRepository::replacePasswordHashInTransaction(
				$pdo,
				$userId,
				password_hash($newPassword, PASSWORD_DEFAULT)
			)) {
				$pdo->rollBack();
				return false;
			}

			// invalidateAccessInTransaction removes every recovery token for the account.
			$pdo->commit();
			return true;
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return false;
		}
	}
}
