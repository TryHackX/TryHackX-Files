<?php

/**
 * Request-level session authentication.
 *
 * The session is only a cache of identity. The users table remains authoritative for the
 * account status, role and security version, so a demotion, deactivation or credential change
 * takes effect on the very next request instead of waiting for the browser session to expire.
 */
final class SessionAuth
{
	private const VERSION_KEY = 'auth_version';
	private static bool $resolved = false;
	private static bool $restoredFromCookie = false;
	private static ?array $cachedUser = null;

	/**
	 * Remove every identity/privilege value from the current session and rotate its id.
	 *
	 * The CSRF token and rate-limit counters are deliberately retained. That lets a request
	 * which discovers a stale login fail as an anonymous request without also weakening the
	 * CSRF gate or making the next login attempt start with a fresh brute-force budget.
	 */
	public static function invalidate(): void
	{
		self::$resolved = true;
		self::$cachedUser = null;
		if (class_exists('Permissions', false)) {
			Permissions::resetCurrentUserCache();
		}

		foreach ([
			'user_id',
			'user_name',
			'user_language',
			'user_role',
			'is_admin',
			'is_moderator',
			self::VERSION_KEY,
			'pending_2fa',
			'pending_2fa_setup',
			'recent_auth_at',
			'pending_activation_user_id',
			'preview_grants',
		] as $key) {
			unset($_SESSION[$key]);
		}

		self::$restoredFromCookie = false;
		// A session torn down here must not be resurrectable by the cookie that made it.
		if (class_exists('RememberTokenRepository')) {
			$cookie = RememberTokenRepository::presentedCookie();
			if ($cookie !== '') {
				RememberTokenRepository::forget($cookie);
				RememberTokenRepository::sendCookie('', 0);
			}
		}

		if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
			session_regenerate_id(true);
		}
	}

	/**
	 * Return the current database-backed user or null when the session is no longer valid.
	 *
	 * Database errors fail closed. In particular, a cached administrator flag is never trusted
	 * while the authoritative role cannot be read.
	 */
	public static function current(): ?array
	{
		if (self::$resolved) {
			$sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
			$sessionVersion = (int) ($_SESSION[self::VERSION_KEY] ?? 0);
			if (
				(self::$cachedUser === null && $sessionUserId === 0)
				|| (
					self::$cachedUser !== null
					&& $sessionUserId === (int) self::$cachedUser['id']
					&& $sessionVersion === (int) self::$cachedUser['session_version']
				)
			) {
				return self::$cachedUser;
			}
			self::$resolved = false;
			self::$cachedUser = null;
		}

		$userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT, [
			'options' => ['min_range' => 1],
		]);
		if ($userId === false || $userId === null) {
			if (isset($_SESSION['user_id'])) {
				self::invalidate();
				return null;
			}
			$userId = self::restoreFromCookie();
			if ($userId === null) {
				self::$resolved = true;
				self::$cachedUser = null;
				return null;
			}
		}

		$pdo = Database::getInstance();
		if (!$pdo) {
			self::invalidate();
			return null;
		}

		try {
			$table = Database::table('users');
			$stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE `id` = ?");
			$stmt->execute([(int) $userId]);
			$user = $stmt->fetch(PDO::FETCH_ASSOC);
		} catch (Throwable $e) {
			self::invalidate();
			return null;
		}

		if (!$user || (int) ($user['is_active'] ?? 0) !== 1) {
			self::invalidate();
			return null;
		}

		$currentVersion = (int) ($user['session_version'] ?? 0);
		if (self::$restoredFromCookie) {
			// A cookie carries no session generation, and it does not need to: any change that
			// advances one also deletes every remember token for the account, so a token that
			// still resolves was issued against the credential in force right now.
			self::$restoredFromCookie = false;
			$_SESSION[self::VERSION_KEY] = $currentVersion;
		}
		$storedVersion = (int) ($_SESSION[self::VERSION_KEY] ?? 0);
		if (
			$storedVersion < 1
			|| $currentVersion < 1
			|| !hash_equals((string) $currentVersion, (string) $storedVersion)
		) {
			self::invalidate();
			return null;
		}

		self::synchronize($user);
		$user['id'] = (int) $user['id'];
		$user['session_version'] = $currentVersion;
		$user['is_active'] = 1;
		$user['is_admin'] = ($user['role'] ?? 'user') === 'admin';
		$user['is_moderator'] = ($user['role'] ?? 'user') === 'moderator';
		self::$resolved = true;
		self::$cachedUser = $user;
		return self::$cachedUser;
	}

	/**
	 * Rebuild a session from a persistent sign-in cookie, if one was presented and holds up.
	 *
	 * The user row is not trusted from here — the caller re-reads it from the users table and
	 * re-checks status and session version, exactly as it does for an ordinary session. All
	 * this does is decide whose id to look up.
	 *
	 * Deliberately absent: `recent_auth_at`. Coming back on a cookie is not a credential
	 * check, so the panel and every destructive action still ask for the password.
	 */
	private static function restoreFromCookie(): ?int
	{
		if (session_status() !== PHP_SESSION_ACTIVE || !class_exists('RememberTokenRepository')) {
			return null;
		}
		$cookie = RememberTokenRepository::presentedCookie();
		if ($cookie === '') {
			return null;
		}

		[$userId, $rotated, $expiresAt] = RememberTokenRepository::consume(
			$cookie,
			function_exists('getClientIP') ? getClientIP() : '',
			(string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
		);
		if ($userId < 1) {
			RememberTokenRepository::sendCookie('', 0);
			return null;
		}

		// The cookie proved possession of a device credential, which is a privilege change:
		// the id it lands on must not be one an attacker could have fixed in advance.
		if (!headers_sent()) {
			session_regenerate_id(true);
		}
		// The row's own deadline, never a fresh one: using the token again must not extend the
		// window the user actually asked for.
		RememberTokenRepository::sendCookie($rotated, max(60, $expiresAt - time()));
		$_SESSION['user_id'] = $userId;
		$_SESSION['restored_from_cookie_at'] = time();
		self::$restoredFromCookie = true;
		return $userId;
	}

	/** Store a freshly authenticated user's authoritative identity in the active session. */
	public static function establish(array $user): void
	{
		$version = (int) ($user['session_version'] ?? 0);
		if ($version < 1) {
			throw new InvalidArgumentException('Cannot establish a session without session_version.');
		}
		self::synchronize($user);
		self::$resolved = false;
		self::$cachedUser = null;
		if (class_exists('Permissions', false)) {
			Permissions::resetCurrentUserCache();
		}
	}

	private static function synchronize(array $user): void
	{
		$role = in_array(($user['role'] ?? ''), ['user', 'moderator', 'admin'], true)
			? (string) $user['role']
			: 'user';

		$_SESSION['user_id'] = (int) $user['id'];
		$_SESSION['user_name'] = (string) ($user['username'] ?? '');
		$_SESSION['user_role'] = $role;
		$_SESSION['is_admin'] = $role === 'admin';
		$_SESSION['is_moderator'] = $role === 'moderator';
		$_SESSION[self::VERSION_KEY] = (int) $user['session_version'];
		$_SESSION['user_language'] = $user['language'] ?? null;
	}
}
