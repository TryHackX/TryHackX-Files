<?php
/**
 * TokenRepository (Faza 5 · #2).
 *
 * Upload-session tokens (captcha/anti-abuse) and single-use, IP-bound download
 * tokens. Extracted from the Database god-object; the matching Database::* methods
 * delegate here. Expired rows are purged by cron (Database::cleanupExpired), so the
 * hot paths only INSERT/SELECT — verify paths filter by timestamp.
 */
final class TokenRepository
{
	/* ---------- upload tokens ---------- */

	public static function createUpload(string $ip, ?int $userId = null): ?string
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return null;
		}

		$table = Database::table('upload_tokens');
		$token = bin2hex(random_bytes(32));

		try {
			$stmt = $pdo->prepare("INSERT INTO `{$table}` (`token`, `ip_address`, `user_id`, `created_at`, `files_count`) VALUES (?, ?, ?, ?, 0)");
			$stmt->execute([$token, $ip, $userId, time()]);
			return $token;
		} catch (PDOException $e) {
			error_log("Failed to create upload token: " . $e->getMessage());
			return null;
		}
	}

	public static function verifyUpload(string $token, string $ip): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}

		$table = Database::table('upload_tokens');
		$tokenLifetime = (int) Database::getSetting('recaptcha_token_lifetime', 120);
		$validTime = time() - ($tokenLifetime * 60);
		$maxFiles = (int) Database::getSetting('recaptcha_max_files_per_session', 0);

		try {
			$stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE `token` = ? AND `ip_address` = ? AND `created_at` > ?");
			$stmt->execute([$token, $ip, $validTime]);
			$row = $stmt->fetch();

			if (!$row) {
				return false;
			}
			if ($maxFiles > 0 && isset($row['files_count']) && $row['files_count'] >= $maxFiles) {
				return false;
			}
			return true;
		} catch (PDOException $e) {
			return false;
		}
	}

	public static function incrementFileCount(string $token): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}

		$table = Database::table('upload_tokens');
		try {
			$stmt = $pdo->prepare("UPDATE `{$table}` SET `files_count` = `files_count` + 1 WHERE `token` = ?");
			return $stmt->execute([$token]);
		} catch (PDOException $e) {
			return false;
		}
	}

	public static function deleteUpload(string $token): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}

		$table = Database::table('upload_tokens');
		try {
			$stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE `token` = ?");
			return $stmt->execute([$token]);
		} catch (PDOException $e) {
			return false;
		}
	}

	public static function claimUpload(string $token, int $userId): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}

		$table = Database::table('upload_tokens');
		$ip = getClientIP();
		try {
			// Only claim if it belongs to the same IP and currently has no user (is anonymous)
			$stmt = $pdo->prepare("UPDATE `{$table}` SET `user_id` = ? WHERE `token` = ? AND `ip_address` = ? AND `user_id` IS NULL");
			$stmt->execute([$userId, $token, $ip]);
			return $stmt->rowCount() > 0;
		} catch (PDOException $e) {
			return false;
		}
	}

	/**
	 * Make this browser's upload token belong to the account that just signed in.
	 *
	 * The upload token is what the upload server attributes files by, so it has to follow the
	 * person at the keyboard. An anonymous token is claimed, as before. A token that already
	 * belongs to a DIFFERENT account — the previous user of this tab — used to be left as it
	 * was, so everything uploaded after the switch still went to that previous account. It is
	 * now replaced by a new token for the signed-in account that keeps the same upload session
	 * (start time, so the same expiry, and the per-session file count). An unknown, expired or
	 * foreign-address token is left alone; the page fetches a new one the usual way.
	 *
	 * @return array{token:string,expires_at:int}|null the token the browser should use from
	 *         now on, or null when it should keep what it has
	 */
	public static function handOverOnLogin(string $token, int $userId, string $ip): ?array
	{
		$pdo = Database::getInstance();
		if (!$pdo || $token === '' || $userId < 1) {
			return null;
		}
		$table = Database::table('upload_tokens');
		$lifetime = max(1, (int) Database::getSetting('recaptcha_token_lifetime', 120)) * 60;
		try {
			$stmt = $pdo->prepare(
				"SELECT `user_id`, `created_at`, `files_count` FROM `{$table}`
				 WHERE `token` = ? AND `ip_address` = ? AND `created_at` > ?"
			);
			$stmt->execute([$token, $ip, time() - $lifetime]);
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			if (!$row) {
				return null;
			}
			$owner = $row['user_id'] === null ? null : (int) $row['user_id'];
			if ($owner === $userId) {
				return null;
			}
			if ($owner === null) {
				$claim = $pdo->prepare(
					"UPDATE `{$table}` SET `user_id` = ? WHERE `token` = ? AND `user_id` IS NULL"
				);
				$claim->execute([$userId, $token]);
				if ($claim->rowCount() > 0) {
					return null;
				}
			}
			$fresh = bin2hex(random_bytes(32));
			$pdo->prepare(
				"INSERT INTO `{$table}` (`token`, `ip_address`, `user_id`, `created_at`, `files_count`)
				 VALUES (?, ?, ?, ?, ?)"
			)->execute([$fresh, $ip, $userId, (int) $row['created_at'], (int) $row['files_count']]);
			return ['token' => $fresh, 'expires_at' => (int) $row['created_at'] + $lifetime];
		} catch (Throwable $e) {
			error_log('Upload token could not be handed over: ' . $e->getMessage());
			return null;
		}
	}

	/**
	 * Signing out ends the upload session the browser held for that account, so a later upload
	 * in the same tab cannot be attributed to the account that just left. Only a token that
	 * belongs to $userId is removed.
	 */
	public static function forgetForUser(string $token, int $userId): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo || $token === '' || $userId < 1) {
			return false;
		}
		try {
			$stmt = $pdo->prepare(
				"DELETE FROM `" . Database::table('upload_tokens') . "` WHERE `token` = ? AND `user_id` = ?"
			);
			$stmt->execute([$token, $userId]);
			return $stmt->rowCount() > 0;
		} catch (PDOException $e) {
			return false;
		}
	}

	public static function uploadInfo(string $token, string $ip): ?array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return null;
		}

		$table = Database::table('upload_tokens');
		$tokenLifetime = (int) Database::getSetting('recaptcha_token_lifetime', 120);
		$validTime = time() - ($tokenLifetime * 60);

		try {
			$stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE `token` = ? AND `ip_address` = ? AND `created_at` > ?");
			$stmt->execute([$token, $ip, $validTime]);
			$row = $stmt->fetch();

			if (!$row) {
				return null;
			}
			// The cap the panel saves and the upload server enforces, per audience. This used to
			// read `recaptcha_max_files_per_session`, a key nothing writes, so the page always
			// showed "unlimited" until the upload server refused the next file.
			$maxFiles = (int) Database::getSetting(
				!empty($row['user_id'])
					? 'recaptcha_max_files_per_session_auth'
					: 'recaptcha_max_files_per_session_guest',
				0
			);
			return [
				'valid' => true,
				'files_uploaded' => (int) ($row['files_count'] ?? 0),
				'files_limit' => $maxFiles,
				'files_remaining' => $maxFiles > 0 ? max(0, $maxFiles - (int) ($row['files_count'] ?? 0)) : -1,
				'expires_at' => (int) $row['created_at'] + ($tokenLifetime * 60),
			];
		} catch (PDOException $e) {
			return null;
		}
	}

	/* ---------- download tokens (single-use, IP-bound) ---------- */

	public static function createDownload(string $fileId, string $ip, ?int $userId = null): ?string
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return null;
		}

		$table = Database::table('download_tokens');
		$token = bin2hex(random_bytes(32));

		// Lifetime lives in the `download_token_ttl` setting (default 900s), shared with
		// verifyUseDownload() and the Python /download endpoint. Single-use + IP-bound.
		try {
			$stmt = $pdo->prepare("INSERT INTO `{$table}` (`token`, `file_id`, `ip_address`, `user_id`, `created_at`, `used`) VALUES (?, ?, ?, ?, ?, 0)");
			$stmt->execute([$token, $fileId, $ip, $userId, time()]);
			return $token;
		} catch (PDOException $e) {
			return null;
		}
	}

	public static function verifyUseDownload(string $token, string $ip): ?string
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return null;
		}

		$table = Database::table('download_tokens');
		$validTime = time() - (int) Database::getSetting('download_token_ttl', 900);

		try {
			$stmt = $pdo->prepare("SELECT `file_id` FROM `{$table}` WHERE `token` = ? AND `ip_address` = ? AND `used` = 0 AND `created_at` > ?");
			$stmt->execute([$token, $ip, $validTime]);
			$row = $stmt->fetch();

			if ($row) {
				// Mark as used immediately (single-use).
				$pdo->prepare("UPDATE `{$table}` SET `used` = 1 WHERE `token` = ?")->execute([$token]);
				return $row['file_id'];
			}
			return null;
		} catch (PDOException $e) {
			return null;
		}
	}
}
