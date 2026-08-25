<?php

/**
 * Persistent sign-in: one rotating token family per device.
 *
 * A PHP session cannot outlive the browser, so "stay signed in for 30 days" needs a credential
 * of its own. This is the classic series/secret scheme, and every part of it is load-bearing:
 *
 *   - the cookie carries a *series* identifying the device and a *secret* proving possession;
 *   - only hashes are stored, so a leaked database yields nothing that can be presented back;
 *   - the secret is replaced on every single use, so a copied cookie is good exactly once;
 *   - presenting a valid series with a stale secret means two parties hold the same cookie.
 *     There is no way to tell victim from thief, so the whole family is destroyed and both
 *     have to sign in again. That is the point: a silent theft becomes a visible logout.
 *
 * Restoring a session from a cookie deliberately does *not* count as a fresh credential check.
 * `recent_auth_at` stays unset, so anything gated behind a password — the panel, destructive
 * account actions — still asks, exactly as it would after the session simply expired.
 */
final class RememberTokenRepository
{
	public const COOKIE = 'fh_remember';

	/** Nothing may outlive this, whatever the administrator configures. */
	private const HARD_MAX_DAYS = 365;
	/** Keeping more than this per account is hoarding, not remembering. */
	private const MAX_PER_USER = 10;

	/** The durations the sign-in form may offer, in seconds. 0 means "this browser session". */
	public const DURATIONS = [0, 1800, 3600, 10800, 86400, 604800, 2592000, -1];

	/** Whether persistent sign-in is offered at all. */
	public static function enabled(): bool
	{
		return (string) Database::getSetting('login_remember_enabled', '1') === '1';
	}

	/** The longest a token may live, in seconds, as the administrator allows. */
	public static function maxLifetime(): int
	{
		$days = (int) Database::getSetting('login_remember_max_days', 30);
		return max(1, min(self::HARD_MAX_DAYS, $days)) * 86400;
	}

	/**
	 * Turn a requested duration into one this installation will actually grant.
	 *
	 * `-1` is the form's "stay signed in" and means the configured maximum — an unbounded
	 * credential is not something that can be issued safely, so there is no such option.
	 */
	public static function resolveLifetime(int $requested): int
	{
		if (!self::enabled() || $requested === 0) {
			return 0;
		}
		$maximum = self::maxLifetime();
		if ($requested < 0) {
			return $maximum;
		}
		return min($requested, $maximum);
	}

	/**
	 * Issue a token for one device and return the cookie value to set.
	 *
	 * @return string empty when nothing should be stored
	 */
	public static function issue(int $userId, int $lifetimeSeconds, string $ip, string $userAgent): string
	{
		$lifetime = self::resolveLifetime($lifetimeSeconds);
		if ($userId < 1 || $lifetime <= 0) {
			return '';
		}
		$pdo = Database::getInstance();
		if (!$pdo) {
			return '';
		}

		$series = bin2hex(random_bytes(32));
		$secret = bin2hex(random_bytes(32));
		$now = time();
		try {
			$pdo->prepare(
				"INSERT INTO `" . Database::table('remember_tokens') . "`
				 (`user_id`,`series`,`token_hash`,`expires_at`,`created_at`,`last_used_at`,
				  `last_ip`,`user_agent`)
				 VALUES (?,?,?,?,?,?,?,?)"
			)->execute([
				$userId,
				hash('sha256', $series),
				hash('sha256', $secret),
				$now + $lifetime,
				$now,
				$now,
				mb_substr($ip, 0, 45),
				self::describeAgent($userAgent),
			]);
		} catch (Throwable $e) {
			error_log('Persistent sign-in could not be stored: ' . $e->getMessage());
			return '';
		}

		self::prune($userId);
		return $series . ':' . $secret;
	}

	/**
	 * Validate a cookie and rotate it. Returns the user id and the replacement cookie value.
	 *
	 * @return array{0:int,1:string,2:int} user id, replacement cookie and the row's own
	 *         deadline; `[0, '', 0]` when the cookie is worthless and should be cleared
	 */
	public static function consume(string $cookie, string $ip, string $userAgent): array
	{
		$parts = explode(':', $cookie, 2);
		if (count($parts) !== 2
			|| preg_match('/\A[a-f0-9]{64}\z/D', $parts[0]) !== 1
			|| preg_match('/\A[a-f0-9]{64}\z/D', $parts[1]) !== 1) {
			return [0, '', 0];
		}
		[$series, $secret] = $parts;
		$pdo = Database::getInstance();
		if (!$pdo) {
			return [0, '', 0];
		}
		$table = Database::table('remember_tokens');
		$seriesHash = hash('sha256', $series);
		$now = time();

		try {
			$pdo->beginTransaction();
			$read = $pdo->prepare(
				"SELECT `id`,`user_id`,`token_hash`,`expires_at` FROM `{$table}`
				 WHERE `series` = ? FOR UPDATE"
			);
			$read->execute([$seriesHash]);
			$row = $read->fetch(PDO::FETCH_ASSOC);
			if (!$row) {
				$pdo->commit();
				return [0, '', 0];
			}
			if ((int) $row['expires_at'] <= $now) {
				$pdo->prepare("DELETE FROM `{$table}` WHERE `id` = ?")->execute([$row['id']]);
				$pdo->commit();
				return [0, '', 0];
			}
			if (!hash_equals((string) $row['token_hash'], hash('sha256', $secret))) {
				// Two parties hold this series. Which one is the owner is unknowable, so
				// neither keeps it.
				$userId = (int) $row['user_id'];
				$pdo->prepare("DELETE FROM `{$table}` WHERE `user_id` = ?")->execute([$userId]);
				$pdo->commit();
				self::reportReuse($userId, $ip);
				return [0, '', 0];
			}

			$replacement = bin2hex(random_bytes(32));
			$pdo->prepare(
				"UPDATE `{$table}` SET `token_hash` = ?, `last_used_at` = ?, `last_ip` = ?,
				 `user_agent` = ? WHERE `id` = ?"
			)->execute([
				hash('sha256', $replacement),
				$now,
				mb_substr($ip, 0, 45),
				self::describeAgent($userAgent),
				$row['id'],
			]);
			$pdo->commit();
			return [(int) $row['user_id'], $series . ':' . $replacement, (int) $row['expires_at']];
		} catch (Throwable $e) {
			try {
				if ($pdo->inTransaction()) {
					$pdo->rollBack();
				}
			} catch (Throwable) {
				// A dropped connection has already rolled this back.
			}
			error_log('Persistent sign-in lookup failed: ' . $e->getMessage());
			return [0, '', 0];
		}
	}

	/** Drop one device's token, identified by the cookie it presented. */
	public static function forget(string $cookie): void
	{
		$series = explode(':', $cookie, 2)[0] ?? '';
		if (preg_match('/\A[a-f0-9]{64}\z/D', $series) !== 1) {
			return;
		}
		$pdo = Database::getInstance();
		if (!$pdo) {
			return;
		}
		try {
			$pdo->prepare(
				"DELETE FROM `" . Database::table('remember_tokens') . "` WHERE `series` = ?"
			)->execute([hash('sha256', $series)]);
		} catch (Throwable $e) {
			error_log('Persistent sign-in could not be cleared: ' . $e->getMessage());
		}
	}

	/**
	 * Drop every device for one account.
	 *
	 * Called by "sign out everywhere", and by anything that invalidates the credential the
	 * tokens were issued against: a password change, a disabled account, a lost 2FA secret.
	 */
	public static function forgetUser(int $userId): int
	{
		$pdo = Database::getInstance();
		if (!$pdo || $userId < 1) {
			return 0;
		}
		try {
			$statement = $pdo->prepare(
				"DELETE FROM `" . Database::table('remember_tokens') . "` WHERE `user_id` = ?"
			);
			$statement->execute([$userId]);
			return $statement->rowCount();
		} catch (Throwable $e) {
			error_log('Persistent sign-in could not be revoked: ' . $e->getMessage());
			return 0;
		}
	}

	/**
	 * Drop every persistent sign-in on the installation.
	 *
	 * Turning the feature off has to take the outstanding cookies with it. Otherwise the switch
	 * only stops new ones being issued, and every device that already holds one stays signed in
	 * for as long as its token was granted.
	 */
	public static function forgetAll(): int
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return 0;
		}
		try {
			$statement = $pdo->query(
				"DELETE FROM `" . Database::table('remember_tokens') . "`"
			);
			return $statement === false ? 0 : $statement->rowCount();
		} catch (Throwable $e) {
			error_log('Persistent sign-in could not be revoked globally: ' . $e->getMessage());
			return 0;
		}
	}

	/**
	 * The devices currently able to restore this account, newest use first.
	 *
	 * The browser asking is marked rather than hidden: a list that silently omits the device
	 * you are holding reads as "nothing is remembered", which is exactly the wrong impression
	 * when something is.
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function devices(int $userId, string $currentCookie = ''): array
	{
		$currentSeries = '';
		$series = explode(':', $currentCookie, 2)[0] ?? '';
		if (preg_match('/\A[a-f0-9]{64}\z/D', $series) === 1) {
			$currentSeries = hash('sha256', $series);
		}
		$pdo = Database::getInstance();
		if (!$pdo || $userId < 1) {
			return [];
		}
		try {
			$statement = $pdo->prepare(
				"SELECT `id`,`series`,`created_at`,`last_used_at`,`expires_at`,`last_ip`,`user_agent`
				 FROM `" . Database::table('remember_tokens') . "`
				 WHERE `user_id` = ? AND `expires_at` > ?
				 ORDER BY COALESCE(`last_used_at`, `created_at`) DESC, `id` DESC"
			);
			$statement->execute([$userId, time()]);
			$rows = [];
			foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
				$row['current'] = $currentSeries !== ''
					&& hash_equals((string) $row['series'], $currentSeries);
				unset($row['series']);
				$rows[] = $row;
			}
			return $rows;
		} catch (Throwable $e) {
			return [];
		}
	}

	/**
	 * Drop every device for one account except the one asking.
	 *
	 * This is the emergency button, and sparing the current browser is what makes it usable:
	 * the person clicking it has lost a laptop and is on their phone. Signing the phone out
	 * too would only mean typing the password again on the one device that is definitely not
	 * the problem. With no current token — a session-only sign-in — it removes everything,
	 * which is the same thing.
	 */
	public static function forgetOthers(int $userId, string $currentCookie): int
	{
		$series = explode(':', $currentCookie, 2)[0] ?? '';
		if (preg_match('/\A[a-f0-9]{64}\z/D', $series) !== 1) {
			return self::forgetUser($userId);
		}
		$pdo = Database::getInstance();
		if (!$pdo || $userId < 1) {
			return 0;
		}
		try {
			$statement = $pdo->prepare(
				"DELETE FROM `" . Database::table('remember_tokens') . "`
				 WHERE `user_id` = ? AND `series` <> ?"
			);
			$statement->execute([$userId, hash('sha256', $series)]);
			return $statement->rowCount();
		} catch (Throwable $e) {
			error_log('Persistent sign-in could not be revoked: ' . $e->getMessage());
			return 0;
		}
	}

	/** Remove expired rows, and the oldest ones past the per-account ceiling. */
	public static function prune(int $userId = 0): int
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return 0;
		}
		$table = Database::table('remember_tokens');
		$removed = 0;
		try {
			$expired = $pdo->prepare("DELETE FROM `{$table}` WHERE `expires_at` <= ?");
			$expired->execute([time()]);
			$removed = $expired->rowCount();

			if ($userId > 0) {
				$keep = $pdo->prepare(
					"SELECT `id` FROM `{$table}` WHERE `user_id` = ?
					 ORDER BY COALESCE(`last_used_at`, `created_at`) DESC, `id` DESC LIMIT " . self::MAX_PER_USER
				);
				$keep->execute([$userId]);
				$keepIds = array_map('intval', $keep->fetchAll(PDO::FETCH_COLUMN));
				if ($keepIds !== []) {
					$placeholders = implode(',', array_fill(0, count($keepIds), '?'));
					$trim = $pdo->prepare(
						"DELETE FROM `{$table}` WHERE `user_id` = ?
						 AND `id` NOT IN ({$placeholders})"
					);
					$trim->execute(array_merge([$userId], $keepIds));
					$removed += $trim->rowCount();
				}
			}
		} catch (Throwable $e) {
			error_log('Persistent sign-in prune failed: ' . $e->getMessage());
		}
		return $removed;
	}

	/**
	 * Write the cookie, or clear it when the value is empty.
	 *
	 * `SameSite=Lax` is not decoration here: it is what stops a cross-site request from
	 * carrying this cookie and silently restoring a session for a form the user never saw.
	 * The application's CSRF token is the second half of that; neither is sufficient alone.
	 */
	public static function sendCookie(string $value, int $lifetimeSeconds): void
	{
		if (headers_sent() || PHP_SAPI === 'cli') {
			return;
		}
		$secure = function_exists('isRequestSecure') ? isRequestSecure() : true;
		if ($value === '') {
			setcookie(self::COOKIE, '', [
				'expires' => 1,
				'path' => '/',
				'secure' => $secure,
				'httponly' => true,
				'samesite' => 'Lax',
			]);
			unset($_COOKIE[self::COOKIE]);
			return;
		}
		setcookie(self::COOKIE, $value, [
			'expires' => time() + max(60, $lifetimeSeconds),
			'path' => '/',
			'secure' => $secure,
			'httponly' => true,
			'samesite' => 'Lax',
		]);
		$_COOKIE[self::COOKIE] = $value;
	}

	/** The cookie this request presented, if it is even shaped like one. */
	public static function presentedCookie(): string
	{
		$raw = $_COOKIE[self::COOKIE] ?? '';
		return is_string($raw) && strlen($raw) === 129 ? $raw : '';
	}

	/** A short, non-identifying label for the device list. */
	private static function describeAgent(string $userAgent): string
	{
		$clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim($userAgent)) ?? '';
		return mb_substr($clean, 0, 255);
	}

	/** One audit line, because a reused cookie is the only signal a theft ever gives. */
	private static function reportReuse(int $userId, string $ip): void
	{
		try {
			Database::logAudit(
				'remember_token_reuse',
				'every persistent sign-in for this account was revoked; cookie replayed from '
				. ($ip !== '' ? $ip : 'an unknown address'),
				$userId
			);
		} catch (Throwable $e) {
			error_log('Could not record a persistent sign-in replay: ' . $e->getMessage());
		}
	}
}
