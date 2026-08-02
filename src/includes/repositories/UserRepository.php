<?php
/**
 * UserRepository (Faza 5 · #2 — final).
 *
 * User accounts and everything tied to a user record: registration, login, password &
 * email changes, activation (email verification), 2FA/TOTP secrets, and the admin-side
 * user management (list, status, role/storage/password edits). Extracted from the Database
 * god-object; the matching Database::* methods delegate here. Cross-domain helpers still
 * go through Database::* delegators (settings, bans, mail) so this class stays about users.
 * Password recovery lives in RecoveryRepository; file rows in FileRepository.
 */
final class UserRepository
{
	/**
	 * Delete every bearer credential that can authenticate or authorize work for a user.
	 *
	 * This intentionally excludes durable business records (files, payments, notifications).
	 * Those have their own retention policy; the rows below are credentials and must disappear
	 * atomically with a security-sensitive account change.
	 */
	private static function revokeAuthenticationArtifacts(PDO $pdo, int $userId): void
	{
		foreach (['api_keys', 'upload_tokens', 'download_tokens', 'recovery_tokens'] as $name) {
			$table = Database::table($name);
			$pdo->prepare("DELETE FROM `{$table}` WHERE `user_id` = ?")->execute([$userId]);
		}
	}

	/**
	 * Advance the browser-session generation and revoke all independent bearer credentials.
	 * The caller must already own a transaction.
	 */
	public static function invalidateAccessInTransaction(PDO $pdo, int $userId): bool
	{
		$users = Database::table('users');
		$stmt = $pdo->prepare(
			"UPDATE `{$users}` SET `session_version` = `session_version` + 1 WHERE `id` = ?"
		);
		$stmt->execute([$userId]);
		if ($stmt->rowCount() !== 1) {
			return false;
		}
		self::revokeAuthenticationArtifacts($pdo, $userId);
		return true;
	}

	/** Public entry point for 2FA/recovery-code changes performed by another repository. */
	public static function invalidateAccess(int $userId): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo || $userId < 1) {
			return false;
		}

		try {
			$pdo->beginTransaction();
			if (!self::invalidateAccessInTransaction($pdo, $userId)) {
				$pdo->rollBack();
				return false;
			}
			$pdo->commit();
			return true;
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			error_log('Account deletion transaction failed: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Create the initial admin account (used by the installer, before DB_PREFIX may be
	 * defined — hence the explicit $prefix). Fails if the username or email already exist.
	 */
	public static function createAdmin(string $username, string $password, string $email, string $prefix = ''): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return ['success' => false, 'error' => 'Brak połączenia z bazą danych'];
		}
		$username = trim($username);
		$email = trim($email);
		if (!InputLimits::validUsername($username)
			|| !InputLimits::validEmail($email)
			|| PasswordPolicy::violation($password) !== null) {
			return ['success' => false, 'error' => 'Invalid administrator account data'];
		}

		if (empty($prefix) && defined('DB_PREFIX')) {
			$prefix = DB_PREFIX;
		}
		$table = $prefix . 'users';

		try {
			$stmt = $pdo->prepare("SELECT id FROM `{$table}` WHERE `username` = ?");
			$stmt->execute([$username]);
			if ($stmt->fetch()) {
				throw new AccountAlreadyExistsException('Administrator username already exists.');
			}

			$stmt = $pdo->prepare("SELECT id FROM `{$table}` WHERE `email` = ?");
			$stmt->execute([$email]);
			if ($stmt->fetch()) {
				throw new AccountAlreadyExistsException('Administrator e-mail already exists.');
			}

			$hash = password_hash($password, PASSWORD_DEFAULT);

			$stmt = $pdo->prepare("INSERT INTO `{$table}` (`username`, `email`, `password_hash`, `role`, `is_active`, `storage_limit`, `created_at`) VALUES (?, ?, ?, 'admin', 1, 0, ?)");
			$result = $stmt->execute([$username, $email, $hash, time()]);
			return ['success' => $result, 'error' => $result ? null : 'INSERT zwrócił false'];
		} catch (PDOException $e) {
			error_log("Failed to create admin: " . $e->getMessage());
			return ['success' => false, 'error' => 'Błąd bazy danych'];
		}
	}

	/**
	 * Register a new account. Validates the username/email/password, honours the
	 * blacklist and the configured activation mode (auto / admin approval / email link),
	 * and — for the email mode — sends the activation message. Returns a result array.
	 */
	public static function register(string $username, string $email, string $password): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return ['success' => false, 'error' => __('api.db_error')];
		}

		$username = trim($username);
		$email = trim($email);

		if (strlen($username) < InputLimits::usernameMin()) {
			return ['success' => false, 'error' => __('api.username_short_configured', ['min' => InputLimits::usernameMin()])];
		}
		if (!InputLimits::validUsername($username)) {
			return ['success' => false, 'error' => __('api.username_format')];
		}
		if (!InputLimits::validEmail($email)) {
			return ['success' => false, 'error' => __('api.bad_email')];
		}
		$passwordViolation = PasswordPolicy::violation($password);
		if ($passwordViolation === 'length') {
			return ['success' => false, 'error' => __('api.pass_min_configured', ['min' => InputLimits::accountPasswordMin()])];
		}
		if ($passwordViolation === 'uppercase') {
			return ['success' => false, 'error' => __('api.password_uppercase')];
		}
		if ($passwordViolation === 'maximum') {
			return ['success' => false, 'error' => __('api.password_too_long')];
		}
		if ($passwordViolation === 'digit') {
			return ['success' => false, 'error' => __('api.password_digit')];
		}
		if ($passwordViolation === 'special') {
			return ['success' => false, 'error' => __('api.password_special')];
		}

		if (Database::isBlacklisted('email', $email)) {
			return ['success' => false, 'error' => __('api.email_blocked')];
		}
		if (Database::isBlacklisted('username', $username)) {
			return ['success' => false, 'error' => __('api.username_blocked')];
		}

		// pkt C: domain rules, addresses still held from a previous account, and the per-IP
		// account cap. All off by default; see RegistrationGuard.
		$registrationIp = function_exists('getClientIP') ? getClientIP() : '';
		$blocked = RegistrationGuard::checkEmail($email) ?? RegistrationGuard::checkIp($registrationIp);
		if ($blocked !== null) {
			return ['success' => false, 'error' => $blocked];
		}

		$table = Database::table('users');

		try {
			$stmt = $pdo->prepare("SELECT id FROM `{$table}` WHERE `username` = ?");
			$stmt->execute([$username]);
			if ($stmt->fetch()) {
				return ['success' => false, 'error' => __('api.username_taken')];
			}

			$stmt = $pdo->prepare("SELECT id FROM `{$table}` WHERE `email` = ?");
			$stmt->execute([$email]);
			if ($stmt->fetch()) {
				return ['success' => false, 'error' => __('api.email_taken')];
			}

			// Activation Logic
			$activationMode = Database::getSetting('user_activation_mode', 'auto');
			$isActive = 1;
			$activationToken = null;
			$activationTokenHash = null;
			$activationExpiresAt = null;
			$infoMsg = __('api.account_created');

			if ($activationMode === 'admin') {
				$isActive = 0;
				$infoMsg = __('api.account_pending');
			} elseif ($activationMode === 'email') {
				$isActive = 0;
				$activationToken = bin2hex(random_bytes(32));
				$activationTokenHash = hash('sha256', $activationToken);
				$activationExpiresAt = time()
					+ max(1, (int) Database::getSetting('email_verification_lifetime', '24')) * 3600;
			}

			$stmt = $pdo->prepare(
				"INSERT INTO `{$table}`
				 (`username`, `email`, `password_hash`, `created_at`, `is_active`,
				  `activation_token`, `activation_expires_at`, `last_activation_email_at`,
				  `registered_ip`)
				 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
			);

			$lastEmailAt = ($activationMode === 'email') ? time() : null;

			if (!$stmt->execute([
				$username,
				$email,
				password_hash($password, PASSWORD_DEFAULT),
				time(),
				$isActive,
				$activationTokenHash,
				$activationExpiresAt,
				$lastEmailAt,
				$registrationIp ?: null,
			])) {
				return ['success' => false, 'error' => __('api.registration_failed')];
			}

			if ($activationMode === 'email') {
				$appName = defined('APP_NAME') ? APP_NAME : (defined('PRODUCT_NAME') ? PRODUCT_NAME : 'TryHackX Files');
				$activLink = APP_URL . '/api.php?action=verify_email&token=' . $activationToken;

				$subject = __('mail.activation_subject', ['app' => $appName]);
				$body = "<p style='margin-bottom: 24px;'>" . __('mail.hello', ['name' => $username]) . "</p>";
				$body .= "<p style='margin-bottom: 24px;'>" . __('mail.activation_intro', ['app' => $appName]) . "</p>";
				$body .= "<div style='text-align: center; margin: 32px 0;'>";
				$body .= "<a href='$activLink' style='display: inline-block; padding: 12px 32px; background-color: #3182ce; color: white; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 16px; box-shadow: 0 2px 4px rgba(49,130,206,0.3);'>" . __('mail.activate_button') . "</a>";
				$body .= "</div>";
				$body .= "<p style='color: #718096; font-size: 14px;'>" . __('mail.link_valid_hours', ['hours' => Database::getSetting('email_verification_lifetime', '24')]) . "</p>";
				$body .= "<p style='margin-top: 32px; font-size: 12px; color: #a0aec0;'>" . __('mail.button_fallback') . "<br><a href='$activLink' style='word-break: break-all; overflow-wrap: break-word; color: #3182ce;'>$activLink</a></p>";

				if (Database::sendEmail(
					$email,
					$subject,
					$body,
					'activation-register:' . $activationTokenHash
				)) {
					$infoMsg = __('mail.account_created_check');
				} else {
					$infoMsg = __('mail.account_created_mailfail');
					error_log("Failed to send activation email to $email after registration");
				}
			}

			return ['success' => true, 'message' => $infoMsg, 'activation_required' => !$isActive];
		} catch (PDOException $e) {
			return ['success' => false, 'error' => __('api.db_error')];
		}
	}

	public static function verifyPassword(int $userId, string $password): bool
	{
		if (strlen($password) > InputLimits::HARD_PASSWORD_MAX) {
			return false;
		}
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$table = Database::table('users');
		try {
			$stmt = $pdo->prepare("SELECT `password_hash` FROM `{$table}` WHERE `id` = ?");
			$stmt->execute([$userId]);
			$row = $stmt->fetch();
			return $row && password_verify($password, $row['password_hash']);
		} catch (PDOException $e) {
			return false;
		}
	}

	public static function updatePassword(int $userId, string $newPassword): bool
	{
		if (!PasswordPolicy::isValid($newPassword)) {
			return false;
		}
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$table = Database::table('users');
		try {
			$hash = password_hash($newPassword, PASSWORD_DEFAULT);
			$pdo->beginTransaction();
			if (!self::replacePasswordHashInTransaction($pdo, $userId, $hash)) {
				$pdo->rollBack();
				return false;
			}
			$pdo->commit();
			return true;
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return false;
		}
	}

	/**
	 * Replace a password and revoke every active credential inside the caller's transaction.
	 */
	public static function replacePasswordHashInTransaction(
		PDO $pdo,
		int $userId,
		string $passwordHash
	): bool {
		$table = Database::table('users');
		$stmt = $pdo->prepare("UPDATE `{$table}` SET `password_hash` = ? WHERE `id` = ?");
		$stmt->execute([$passwordHash, $userId]);
		return $stmt->rowCount() === 1
			&& self::invalidateAccessInTransaction($pdo, $userId);
	}

	/** Start an email change: enforce uniqueness + a 2-week cooldown, then mail a confirm link. */
	public static function requestEmailChange(int $userId, string $newEmail): array
	{
		return self::requestEmailChangeAtomic($userId, $newEmail);
	}

	public static function confirmEmailChange(string $token): array
	{
		return self::confirmEmailChangeAtomic($token);
	}

	private static function requestEmailChangeAtomic(int $userId, string $newEmail): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return ['success' => false, 'error' => __('api.db_error')];
		}
		$newEmail = trim($newEmail);
		if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
			return ['success' => false, 'error' => __('api.bad_email')];
		}
		$blocked = RegistrationGuard::checkEmail($newEmail);
		if ($blocked !== null) {
			return ['success' => false, 'error' => $blocked];
		}

		$table = Database::table('users');
		$token = bin2hex(random_bytes(32));
		$tokenHash = hash('sha256', $token);
		$issuedAt = time();
		$expiresAt = $issuedAt
			+ max(1, (int) Database::getSetting('email_change_token_lifetime', '15')) * 60;
		try {
			$pdo->beginTransaction();
			$userQuery = $pdo->prepare(
				"SELECT `last_email_change_at` FROM `{$table}` WHERE `id` = ? FOR UPDATE"
			);
			$userQuery->execute([$userId]);
			$user = $userQuery->fetch(PDO::FETCH_ASSOC);
			if (!$user) {
				$pdo->rollBack();
				return ['success' => false, 'error' => __('api.user_not_found')];
			}
			if ($issuedAt - (int) $user['last_email_change_at'] < 1209600) {
				$pdo->rollBack();
				return ['success' => false, 'error' => __('api.email_change_cooldown')];
			}

			$duplicate = $pdo->prepare(
				"SELECT `id` FROM `{$table}` WHERE `email` = ? AND `id` <> ? FOR UPDATE"
			);
			$duplicate->execute([$newEmail, $userId]);
			if ($duplicate->fetchColumn()) {
				$pdo->rollBack();
				return ['success' => false, 'error' => __('api.email_taken')];
			}

			$save = $pdo->prepare(
				"UPDATE `{$table}`
				 SET `pending_email` = ?,
				     `email_change_token` = ?,
				     `email_change_expires_at` = ?
				 WHERE `id` = ?"
			);
			$save->execute([$newEmail, $tokenHash, $expiresAt, $userId]);
			if ($save->rowCount() !== 1) {
				$pdo->rollBack();
				return ['success' => false, 'error' => __('api.email_change_store_failed')];
			}
			$pdo->commit();
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return ['success' => false, 'error' => __('api.db_error')];
		}

		$link = APP_URL . '/api.php?action=user_verify_email_change&token=' . $token;
		$appName = defined('APP_NAME') ? APP_NAME : (defined('PRODUCT_NAME') ? PRODUCT_NAME : 'TryHackX Files');
		$subject = __('mail.change_subject', ['app' => $appName]);
		$body = '<p>' . __('mail.change_intro', ['email' => $newEmail]) . '</p>';
		$body .= '<p>' . __('mail.change_click') . '</p>';
		$body .= "<p><a href='" . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . "'>"
			. htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '</a></p>';
		$body .= '<p>' . __('mail.change_ignore') . '</p>';

		if (!Database::sendEmail(
			$newEmail,
			$subject,
			$body,
			'email-change:' . $tokenHash
		)) {
			$clear = $pdo->prepare(
				"UPDATE `{$table}`
				 SET `pending_email` = NULL,
				     `email_change_token` = NULL,
				     `email_change_expires_at` = NULL
				 WHERE `id` = ? AND `email_change_token` = ?"
			);
			$clear->execute([$userId, $tokenHash]);
			return ['success' => false, 'error' => __('mail.change_send_failed')];
		}
		return ['success' => true, 'message' => __('mail.change_sent')];
	}

	private static function confirmEmailChangeAtomic(string $token): array
	{
		$pdo = Database::getInstance();
		if (!$pdo || !preg_match('/\A[0-9a-f]{64}\z/D', $token)) {
			return ['success' => false, 'error' => __('api.link_expired')];
		}
		$table = Database::table('users');
		$tokenHash = hash('sha256', $token);
		try {
			$pdo->beginTransaction();
			$stmt = $pdo->prepare(
				"SELECT `id`, `email`, `pending_email`
				 FROM `{$table}`
				 WHERE `email_change_token` = ?
				   AND `email_change_expires_at` > ?
				 FOR UPDATE"
			);
			$stmt->execute([$tokenHash, time()]);
			$user = $stmt->fetch(PDO::FETCH_ASSOC);
			if (!$user || !filter_var($user['pending_email'], FILTER_VALIDATE_EMAIL)) {
				$pdo->rollBack();
				return ['success' => false, 'error' => __('api.link_expired')];
			}

			$claim = $pdo->prepare(
				"UPDATE `{$table}`
				 SET `email` = ?,
				     `pending_email` = NULL,
				     `email_change_token` = NULL,
				     `email_change_expires_at` = NULL,
				     `last_email_change_at` = ?
				 WHERE `id` = ? AND `email_change_token` = ?"
			);
			$claim->execute([$user['pending_email'], time(), $user['id'], $tokenHash]);
			if ($claim->rowCount() !== 1
				|| !self::invalidateAccessInTransaction($pdo, (int) $user['id'])) {
				$pdo->rollBack();
				return ['success' => false, 'error' => __('api.link_expired')];
			}
			$pdo->commit();
			RegistrationGuard::reserve((string) $user['email'], (int) $user['id']);
			return [
				'success' => true,
				'message' => __('api.email_change_success'),
				'user_id' => (int) $user['id'],
				'email' => (string) $user['pending_email'],
			];
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return ['success' => false, 'error' => __('api.db_error')];
		}
	}

	/**
	 * The owner account: the oldest one on the install, i.e. the admin the installer created
	 * (pt 4). Returns 0 when there are no users at all.
	 *
	 * Deliberately `MIN(id)` rather than a hardcoded 1: an install restored from a dump, or one
	 * whose auto-increment starts elsewhere, has no id 1 — and then the "primary admin is
	 * protected" rule silently protected nobody. Cached per request; the value only changes
	 * when the owner account is deleted, which is exactly what this guards against.
	 */
	public static function rootAdminId(): int
	{
		static $cached = null;
		if ($cached !== null) {
			return $cached;
		}
		$pdo = Database::getInstance();
		if (!$pdo) {
			return 0; // no connection — do not claim anyone is the owner
		}
		try {
			$table = Database::table('users');
			$id = $pdo->query("SELECT MIN(`id`) FROM `{$table}`")->fetchColumn();
			return $cached = (int) $id;
		} catch (PDOException $e) {
			return 0;
		}
	}

	/** Is this the owner account? See rootAdminId(). */
	public static function isRootAdmin(int $userId): bool
	{
		$root = self::rootAdminId();
		return $root > 0 && $userId === $root;
	}

	/** Caller holds the target row; lock the active-admin roster before removing access. */
	private static function isLastActiveAdmin(PDO $pdo, string $table, int $userId): bool
	{
		$stmt = $pdo->query(
			"SELECT `id` FROM `{$table}`
			 WHERE `role` = 'admin' AND `is_active` = 1 ORDER BY `id` FOR UPDATE"
		);
		$ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
		return in_array($userId, $ids, true) && count($ids) <= 1;
	}

	/** Delete a user and all authentication artefacts. The owner account is protected. */
	public static function delete(int $userId): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}

		if (self::isRootAdmin($userId)) {
			return false; // last line of defence; callers refuse earlier, before touching files
		}

		$usersTable = Database::table('users');
		$recoveryCodes = Database::table('totp_recovery_codes');
		$filesTable = Database::table('files');
		$deletionQueue = Database::table('file_deletion_queue');
		$collectionFiles = Database::table('collection_files');
		$reports = Database::table('reports');

		try {
			$pdo->beginTransaction();
			$lock = $pdo->prepare(
				"SELECT `id`, `role`, `is_active` FROM `{$usersTable}` WHERE `id` = ? FOR UPDATE"
			);
			$lock->execute([$userId]);
			$target = $lock->fetch(PDO::FETCH_ASSOC);
			if (!$target) {
				$pdo->rollBack();
				return false;
			}
			if ((string) $target['role'] === 'admin' && (int) $target['is_active'] === 1
				&& self::isLastActiveAdmin($pdo, $usersTable, $userId)) {
				$pdo->rollBack();
				return false;
			}

			$fileQuery = $pdo->prepare(
				"SELECT `id` FROM `{$filesTable}` WHERE `user_id` = ? FOR UPDATE"
			);
			$fileQuery->execute([$userId]);
			$fileIds = $fileQuery->fetchAll(PDO::FETCH_COLUMN);
			if ($fileIds !== []) {
				$queue = $pdo->prepare(
					"INSERT INTO `{$deletionQueue}`
					 (`file_id`, `attempts`, `next_attempt_at`, `last_error`, `created_at`)
					 VALUES (?, 0, 0, NULL, ?)
					 ON DUPLICATE KEY UPDATE
					  `next_attempt_at` = LEAST(`next_attempt_at`, VALUES(`next_attempt_at`))"
				);
				foreach ($fileIds as $fileId) {
					$queue->execute([(string) $fileId, time()]);
				}
				foreach (array_chunk($fileIds, 500) as $chunk) {
					$in = implode(',', array_fill(0, count($chunk), '?'));
					$pdo->prepare(
						"DELETE FROM `{$collectionFiles}` WHERE `file_id` IN ({$in})"
					)->execute($chunk);
					$pdo->prepare(
						"DELETE FROM `{$reports}` WHERE `file_id` IN ({$in})"
					)->execute($chunk);
				}
				$deleteFiles = $pdo->prepare("DELETE FROM `{$filesTable}` WHERE `user_id` = ?");
				$deleteFiles->execute([$userId]);
				if ($deleteFiles->rowCount() !== count($fileIds)) {
					throw new RuntimeException('The locked account files changed during deletion.');
				}
			}
			self::revokeAuthenticationArtifacts($pdo, $userId);
			$pdo->prepare("DELETE FROM `{$recoveryCodes}` WHERE `user_id` = ?")->execute([$userId]);
			$delete = $pdo->prepare("DELETE FROM `{$usersTable}` WHERE `id` = ?");
			$delete->execute([$userId]);
			if ($delete->rowCount() !== 1) {
				$pdo->rollBack();
				return false;
			}
			$pdo->commit();
			return true;
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return false;
		}
	}

	/**
	 * Verify credentials for login. Distinguishes: bad credentials, an inactive account
	 * (with resend metadata, kept out of the response to avoid enumeration — the pending
	 * id goes to the session instead), and an IP ban. On success returns the public user.
	 */
	public static function login(string $username, string $password): array
	{
		if (strlen($username) > InputLimits::HARD_USERNAME_MAX || strlen($password) > InputLimits::HARD_PASSWORD_MAX) {
			return ['success' => false, 'error' => __('api.bad_credentials')];
		}
		$pdo = Database::getInstance();
		if (!$pdo) {
			return ['success' => false, 'error' => __('api.db_error')];
		}
		$usersTable = Database::table('users');
		try {
			$stmt = $pdo->prepare("SELECT * FROM `{$usersTable}` WHERE `username` = ?");
			$stmt->execute([$username]);
			$user = $stmt->fetch(PDO::FETCH_ASSOC);

			if ($user && password_verify($password, $user['password_hash'])) {
				if (isset($user['is_active']) && $user['is_active'] == 0) {
					$canResend = !empty($user['activation_token']);
					$msg = $canResend ? __('api.inactive_email') : __('api.inactive_pending');

					$cooldown = (int) Database::getSetting('email_resend_cooldown', '30');
					$lastSent = $user['last_activation_email_at'] ?? 0;

					if ($canResend) {
						if (session_status() === PHP_SESSION_NONE) {
							session_start();
						}
						$_SESSION['pending_activation_user_id'] = $user['id'];
					}

					return [
						'success' => false,
						'error' => $msg,
						'inactive' => $canResend,
						'email' => null,
						'cooldown_minutes' => $cooldown,
						'last_activation_sent' => $lastSent,
						'server_time' => time()
					];
				}

				$isBanned = Database::getBanDetails('ip', getClientIP());
				if ($isBanned) {
					$msg = __('api.ip_banned');
					if ($isBanned['expires_at']) {
						$msg = __('api.ip_banned_until', [
							'until' => date('d.m.Y H:i', $isBanned['expires_at']),
						]);
					}
					if ($isBanned['reason']) {
						$msg .= ' ' . __('api.ip_banned_reason', ['reason' => $isBanned['reason']]);
					}
					return ['success' => false, 'error' => $msg];
				}

				$isAdmin = ($user['role'] === 'admin');

				return [
					'success' => true,
					'user' => [
						'id' => $user['id'],
						'username' => $user['username'],
						'email' => $user['email'],
						'is_admin' => $isAdmin,
						'role' => $user['role'],
						'session_version' => (int) ($user['session_version'] ?? 0),
						'language' => $user['language'] ?? null,
					]
				];
			}

			return ['success' => false, 'error' => __('api.bad_credentials')];
		} catch (PDOException $e) {
			return ['success' => false, 'error' => __('api.db_error')];
		}
	}

	/** Activate an account from its email link (token + not-yet-active + not expired). */
	public static function activateByToken(string $token): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return ['success' => false, 'error' => __('api.db_error')];
		}
		$table = Database::table('users');
		try {
			$claim = $pdo->prepare(
				"UPDATE `{$table}`
				 SET `is_active` = 1,
				     `activation_token` = NULL,
				     `activation_expires_at` = NULL,
				     `session_version` = `session_version` + 1
				 WHERE `activation_token` = ?
				   AND `activation_expires_at` > ?
				   AND `is_active` = 0"
			);
			$claim->execute([hash('sha256', $token), time()]);
			return $claim->rowCount() === 1
				? ['success' => true]
				: ['success' => false, 'error' => __('api.link_expired')];
		} catch (PDOException $e) {
			return ['success' => false, 'error' => __('api.db_error')];
		}
	}

	public static function resendActivationById(int $userId): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return ['success' => false, 'error' => __('api.db_error')];
		}
		$table = Database::table('users');
		try {
			$stmt = $pdo->prepare("SELECT `email` FROM `{$table}` WHERE `id` = ?");
			$stmt->execute([$userId]);
			$user = $stmt->fetch(PDO::FETCH_ASSOC);

			if (!$user) {
				return ['success' => false, 'error' => __('api.user_not_found')];
			}

			return self::resendActivation($user['email']);
		} catch (PDOException $e) {
			return ['success' => false, 'error' => __('api.db_error')];
		}
	}

	/** Resend the activation link for an email (cooldown-gated; never reveals existence). */
	public static function resendActivation(string $email): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return ['success' => false, 'error' => __('api.db_error')];
		}
		$table = Database::table('users');
		try {
			$stmt = $pdo->prepare(
				"SELECT `id`, `username`, `is_active`, `last_activation_email_at`
				 FROM `{$table}` WHERE `email` = ?"
			);
			$stmt->execute([$email]);
			$user = $stmt->fetch(PDO::FETCH_ASSOC);

			if (!$user) {
				// Don't reveal if user exists
				return ['success' => true, 'message' => __('api.activation_sent_if_exists')];
			}

			if ($user['is_active']) {
				return ['success' => false, 'error' => __('api.account_already_active')];
			}

			$cooldownMinutes = (int) Database::getSetting('email_resend_cooldown', '30');
			$lastSent = $user['last_activation_email_at'] ?? 0;
			$diff = time() - $lastSent;
			if ($diff < $cooldownMinutes * 60) {
				$wait = ceil(($cooldownMinutes * 60 - $diff) / 60);
				return ['success' => false, 'error' => __('api.activation_wait', ['minutes' => $wait])];
			}

			$token = bin2hex(random_bytes(32));
			$issuedAt = time();
			$expiresAt = $issuedAt
				+ max(1, (int) Database::getSetting('email_verification_lifetime', '24')) * 3600;
			$tokenHash = hash('sha256', $token);
			$stmt = $pdo->prepare(
				"UPDATE `{$table}`
				 SET `activation_token` = ?,
				     `activation_expires_at` = ?,
				     `last_activation_email_at` = ?
				 WHERE `id` = ?
				   AND `is_active` = 0
				   AND COALESCE(`last_activation_email_at`, 0) = ?"
			);
			$stmt->execute([
				$tokenHash,
				$expiresAt,
				$issuedAt,
				$user['id'],
				(int) $lastSent,
			]);
			if ($stmt->rowCount() !== 1) {
				return ['success' => false, 'error' => __('api.activation_retry')];
			}

			$appName = defined('APP_NAME') ? APP_NAME : (defined('PRODUCT_NAME') ? PRODUCT_NAME : 'TryHackX Files');
			$appUrl = APP_URL;
			$activLink = $appUrl . '/api.php?action=verify_email&token=' . $token;

			$subject = __('mail.activation_subject', ['app' => $appName]);
			$body = "<p style='margin-bottom: 24px;'>" . __('mail.hello', ['name' => $user['username']]) . "</p>";
			$body .= "<p style='margin-bottom: 24px;'>" . __('mail.activation_resend_intro', ['app' => $appName]) . "</p>";
			$body .= "<div style='text-align: center; margin: 32px 0;'>";
			$body .= "<a href='$activLink' style='display: inline-block; padding: 12px 32px; background-color: #3182ce; color: white; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 16px; box-shadow: 0 2px 4px rgba(49,130,206,0.3);'>" . __('mail.activate_button') . "</a>";
			$body .= "</div>";
			$body .= "<p style='color: #718096; font-size: 14px;'>" . __('mail.link_valid_hours', ['hours' => Database::getSetting('email_verification_lifetime', '24')]) . "</p>";
			$body .= "<p style='margin-top: 32px; font-size: 12px; color: #a0aec0;'>" . __('mail.button_fallback') . "<br><a href='$activLink' style='word-break: break-all; overflow-wrap: break-word; color: #3182ce;'>$activLink</a></p>";

			if (Database::sendEmail(
				$email,
				$subject,
				$body,
				'activation-resend:' . $tokenHash
			)) {
				return ['success' => true, 'message' => __('mail.activation_sent')];
			}
			$clear = $pdo->prepare(
				"UPDATE `{$table}`
				 SET `activation_token` = NULL,
				     `activation_expires_at` = NULL,
				     `last_activation_email_at` = ?
				 WHERE `id` = ? AND `activation_token` = ?"
			);
			$clear->execute([(int) $lastSent, (int) $user['id'], $tokenHash]);
			return ['success' => false, 'error' => __('api.email_send_error')];
		} catch (PDOException $e) {
			error_log("Resend Activation DB Error: " . $e->getMessage());
			return ['success' => false, 'error' => __('api.db_error')];
		}
	}

	/** A full user record by id (for the REST API's whoami), or null. */
	public static function getById(int $id): ?array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return null;
		}
		$table = Database::table('users');
		try {
			$stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE `id` = ?");
			$stmt->execute([$id]);
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			return $row ?: null;
		} catch (PDOException $e) {
			return null;
		}
	}

	/* ---- 2FA / TOTP (Faza 4.4). Secret stored on setup start; only counts once enabled. ---- */

	/** Stash a freshly generated secret for a pending (not yet confirmed) setup. */
	public static function setTotpSecret(int $userId, string $secretB32): bool
	{
		if (!preg_match('/\A[A-Z2-7]{16,128}\z/D', $secretB32)) {
			return false;
		}
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$table = Database::table('users');
		try {
			$stmt = $pdo->prepare("UPDATE `{$table}` SET `totp_secret` = ?, `totp_enabled` = 0 WHERE `id` = ?");
			$stmt->execute([Crypto::encrypt($secretB32), $userId]);
			return $stmt->rowCount() === 1;
		} catch (Throwable $e) {
			return false;
		}
	}

	/** Returns ['secret' => ?string, 'enabled' => bool] for a user. */
	public static function getTotpState(int $userId): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return ['secret' => null, 'enabled' => false];
		}
		$table = Database::table('users');
		try {
			$stmt = $pdo->prepare("SELECT `totp_secret`, `totp_enabled` FROM `{$table}` WHERE `id` = ?");
			$stmt->execute([$userId]);
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			if (!$row) {
				return ['secret' => null, 'enabled' => false];
			}
			$stored = (string) ($row['totp_secret'] ?? '');
			$secret = $stored === '' ? null : Crypto::decrypt($stored);
			if ($stored !== '' && ($secret === '' || !preg_match('/\A[A-Z2-7]{16,128}\z/D', $secret))) {
				return ['secret' => null, 'enabled' => false];
			}
			return ['secret' => $secret, 'enabled' => !empty($row['totp_enabled'])];
		} catch (Throwable $e) {
			return ['secret' => null, 'enabled' => false];
		}
	}

	/**
	 * Enable the pending TOTP secret, mint fallbacks and revoke prior access atomically.
	 */
	public static function enableTotpWithRecoveryCodes(int $userId): array
	{
		$pdo = Database::getInstance();
		if (!$pdo || $userId < 1) {
			return [];
		}
		$table = Database::table('users');
		try {
			$pdo->beginTransaction();
			$stmt = $pdo->prepare(
				"UPDATE `{$table}`
				 SET `totp_enabled` = 1
				 WHERE `id` = ? AND `totp_enabled` = 0 AND `totp_secret` IS NOT NULL"
			);
			$stmt->execute([$userId]);
			if ($stmt->rowCount() !== 1) {
				throw new RuntimeException('TOTP setup state changed.');
			}
			$codes = RecoveryCodeRepository::replaceInTransaction($pdo, $userId);
			if (!self::invalidateAccessInTransaction($pdo, $userId)) {
				throw new RuntimeException('Could not revoke existing access.');
			}
			$pdo->commit();
			return $codes;
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return [];
		}
	}

	public static function setTotpEnabled(int $userId, bool $enabled): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$table = Database::table('users');
		try {
			$pdo->beginTransaction();
			if ($enabled) {
				$stmt = $pdo->prepare("UPDATE `{$table}` SET `totp_enabled` = 1 WHERE `id` = ? AND `totp_secret` IS NOT NULL");
				$stmt->execute([$userId]);
				if ($stmt->rowCount() !== 1) {
					$pdo->rollBack();
					return false;
				}
			} else {
				// Disabling clears the secret too, so re-enabling always starts fresh.
				$stmt = $pdo->prepare("UPDATE `{$table}` SET `totp_enabled` = 0, `totp_secret` = NULL WHERE `id` = ?");
				$stmt->execute([$userId]);
				if ($stmt->rowCount() !== 1) {
					$pdo->rollBack();
					return false;
				}
				$pdo->prepare(
					"DELETE FROM `" . Database::table('totp_recovery_codes') . "` WHERE `user_id` = ?"
				)->execute([$userId]);
			}
			if (!self::invalidateAccessInTransaction($pdo, $userId)) {
				$pdo->rollBack();
				return false;
			}
			$pdo->commit();
			return true;
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return false;
		}
	}

	/* ---- Availability checks + admin-side user management ---- */

	public static function usernameAvailable(string $username): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$usersTable = Database::table('users');
		try {
			$stmt = $pdo->prepare("SELECT id FROM `{$usersTable}` WHERE `username` = ?");
			$stmt->execute([$username]);
			return !$stmt->fetch();
		} catch (PDOException $e) {
			return false;
		}
	}

	public static function emailAvailable(string $email): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$table = Database::table('users');
		try {
			$stmt = $pdo->prepare("SELECT id FROM `{$table}` WHERE `email` = ?");
			$stmt->execute([$email]);
			return !$stmt->fetch();
		} catch (PDOException $e) {
			return false;
		}
	}

	/** Paged user list for the admin panel, with per-user file count / storage / last IP / group. */
	public static function all(
		int $page = 1,
		int $limit = 50,
		string $sortBy = 'created_at',
		string $order = 'desc',
		array $sorts = []
	): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return ['users' => [], 'total' => 0];
		}

		$usersTable = Database::table('users');
		$filesTable = Database::table('files');
		$offset = ($page - 1) * $limit;

		// Validate sort and order
		$allowedSort = ['username', 'email', 'created_at', 'storage_used', 'files_count', 'role', 'is_active'];
		if (!in_array($sortBy, $allowedSort)) {
			$sortBy = 'created_at';
		}
		$order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

		try {
			$stmt = $pdo->query("SELECT COUNT(*) FROM `{$usersTable}`");
			$total = (int) $stmt->fetchColumn();

			// Sort on real user columns is qualified with `u.` (the groups JOIN also has a
			// `created_at`), while computed aliases stay bare.
			$sortExpr = in_array($sortBy, ['storage_used', 'files_count'], true) ? "`{$sortBy}`" : "u.`{$sortBy}`";
			$sortParts = [];
			foreach ($sorts as $column => $direction) {
				if (!in_array($column, $allowedSort, true) || count($sortParts) >= 5) {
					continue;
				}
				$expr = in_array($column, ['storage_used', 'files_count'], true)
					? "`{$column}`" : "u.`{$column}`";
				$sortParts[] = $expr . ' ' . (strtoupper((string) $direction) === 'ASC' ? 'ASC' : 'DESC');
			}
			if ($sortParts === []) {
				$sortParts[] = "{$sortExpr} {$order}";
			}
			$sortParts[] = 'u.`id` ASC';
			$sortSql = implode(', ', $sortParts);
			$groupsTable = Database::table('groups');

			$sql = "SELECT
						u.*,
						(SELECT COUNT(*) FROM `{$filesTable}` f WHERE f.user_id = u.id) as files_count,
						(SELECT COALESCE(SUM(size), 0) FROM `{$filesTable}` f WHERE f.user_id = u.id) as storage_used,
						(SELECT uploaded_ip FROM `{$filesTable}` f WHERE f.user_id = u.id ORDER BY uploaded_at DESC LIMIT 1) as last_ip,
						g.name as group_name,
						sg.name as staff_group_name
					FROM `{$usersTable}` u
					LEFT JOIN `{$groupsTable}` g ON u.group_id = g.id
					LEFT JOIN `{$groupsTable}` sg ON u.staff_group_id = sg.id
					ORDER BY {$sortSql}
					LIMIT :limit OFFSET :offset";

			$stmt = $pdo->prepare($sql);
			$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
			$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
			$stmt->execute();

			return ['users' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
		} catch (PDOException $e) {
			error_log("getAllUsers error: " . $e->getMessage());
			return ['users' => [], 'total' => 0];
		}
	}

	public static function setActive(int $id, int $active): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$table = Database::table('users');
		try {
			$active = $active === 1 ? 1 : 0;
			$pdo->beginTransaction();
			$stmt = $pdo->prepare(
				"SELECT `is_active`, `role` FROM `{$table}` WHERE `id` = ? FOR UPDATE"
			);
			$stmt->execute([$id]);
			$current = $stmt->fetch(PDO::FETCH_ASSOC);
			if (!$current) {
				$pdo->rollBack();
				return false;
			}
			if ($active === 0 && (string) $current['role'] === 'admin' && self::isRootAdmin($id)) {
				$pdo->rollBack();
				return false;
			}
			if ($active === 0 && (int) $current['is_active'] === 1
				&& (string) $current['role'] === 'admin'
				&& self::isLastActiveAdmin($pdo, $table, $id)) {
				$pdo->rollBack();
				return false;
			}
			if ((int) $current['is_active'] !== $active) {
				$stmt = $pdo->prepare("UPDATE `{$table}` SET `is_active` = ? WHERE `id` = ?");
				$stmt->execute([$active, $id]);
				if (!self::invalidateAccessInTransaction($pdo, $id)) {
					$pdo->rollBack();
					return false;
				}
			}
			$pdo->commit();
			return true;
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return false;
		}
	}

	/**
	 * Admin edit of a user: role, per-user storage limit (bytes; 0 = unlimited) and/or a
	 * password reset. The moderator role automatically attaches the system Moderator group;
	 * that permission group is independent from the paid-plan group.
	 */
	public static function adminUpdate(int $id, array $data): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return ['success' => false, 'error' => __('api.db_error')];
		}
		$table = Database::table('users');

		$sets = [];
		$params = [];
		$securityChange = false;

		if (isset($data['role']) && $data['role'] !== '') {
			if (!in_array($data['role'], ['user', 'moderator', 'admin'], true)) {
				return ['success' => false, 'error' => __('api.invalid_role')];
			}
			$sets[] = '`role` = ?';
			$params[] = $data['role'];
		}

		if (isset($data['storage_limit']) && $data['storage_limit'] !== '') {
			$sets[] = '`storage_limit` = ?';
			$params[] = max(0, (int) $data['storage_limit']);
		}

		if (!empty($data['password'])) {
			$passwordViolation = PasswordPolicy::violation((string) $data['password']);
			if ($passwordViolation === 'length') {
				return ['success' => false, 'error' => __('api.pass_min_configured', ['min' => InputLimits::accountPasswordMin()])];
			}
			if ($passwordViolation === 'maximum') {
				return ['success' => false, 'error' => __('api.password_too_long')];
			}
			$sets[] = '`password_hash` = ?';
			$params[] = password_hash($data['password'], PASSWORD_DEFAULT);
			$securityChange = true;
		}

		try {
			$pdo->beginTransaction();
			$target = $pdo->prepare(
				"SELECT `role`, `is_active`, `staff_group_id`
				 FROM `{$table}` WHERE `id` = ? FOR UPDATE"
			);
			$target->execute([$id]);
			$current = $target->fetch(PDO::FETCH_ASSOC);
			if (!$current) {
				$pdo->rollBack();
				return ['success' => false, 'error' => __('api.user_not_found')];
			}
			if (isset($data['role']) && $data['role'] !== 'admin'
				&& (string) $current['role'] === 'admin' && self::isRootAdmin($id)) {
				$pdo->rollBack();
				return ['success' => false, 'error' => __('api.root_admin_access_protected')];
			}
			if (isset($data['role']) && $data['role'] !== 'admin'
				&& (string) $current['role'] === 'admin' && (int) $current['is_active'] === 1
				&& self::isLastActiveAdmin($pdo, $table, $id)) {
				$pdo->rollBack();
				return ['success' => false, 'error' => __('api.last_admin_protected')];
			}
			if (isset($data['role']) && $data['role'] !== ''
				&& (string) $data['role'] !== (string) $current['role']) {
				$securityChange = true;
			}

			$targetRole = isset($data['role']) && $data['role'] !== ''
				? (string) $data['role']
				: (string) $current['role'];
			if ($targetRole === 'moderator') {
				$group = $pdo->query(
					"SELECT `id` FROM `" . Database::table('groups') . "`
					 WHERE `slug` = 'moderator' AND `is_system` = 1 LIMIT 1 FOR UPDATE"
				);
				$staffGroupId = (int) $group->fetchColumn();
				if ($staffGroupId <= 0) {
					$pdo->rollBack();
					return ['success' => false, 'error' => __('api.moderator_group_missing')];
				}
				// A legacy/client-supplied staff_group_id is intentionally ignored. A role maps
				// to exactly one canonical permission group, preventing profile substitution.
				$sets[] = '`staff_group_id` = ?';
				$params[] = $staffGroupId;
				if ((int) ($current['staff_group_id'] ?? 0) !== $staffGroupId) {
					$securityChange = true;
				}
			} elseif ($current['staff_group_id'] !== null) {
				// Admins hold everything implicitly, while ordinary users must never retain a
				// dormant role-bound permission assignment.
				$sets[] = '`staff_group_id` = NULL';
				$securityChange = true;
			}

			if (!$sets) {
				$pdo->rollBack();
				return ['success' => false, 'error' => __('api.no_changes')];
			}
			$params[] = $id;
			$stmt = $pdo->prepare("UPDATE `{$table}` SET " . implode(', ', $sets) . " WHERE `id` = ?");
			$stmt->execute($params);
			if ($stmt->rowCount() !== 1) {
				$exists = $pdo->prepare("SELECT 1 FROM `{$table}` WHERE `id` = ?");
				$exists->execute([$id]);
				if (!$exists->fetchColumn()) {
					$pdo->rollBack();
					return ['success' => false, 'error' => __('api.user_not_found')];
				}
			}
			if ($securityChange && !self::invalidateAccessInTransaction($pdo, $id)) {
				$pdo->rollBack();
				return ['success' => false, 'error' => __('api.revoke_access_failed')];
			}
			$pdo->commit();
			return ['success' => true];
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return ['success' => false, 'error' => __('api.user_save_failed')];
		}
	}

	/** Fetch a single user's editable fields for the admin "manage user" modal. */
	public static function getForAdmin(int $id): ?array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return null;
		}
		try {
			$stmt = $pdo->prepare(
				"SELECT `id`, `username`, `email`, `role`, `staff_group_id`,
				        `storage_limit`, `is_active`
				 FROM `" . Database::table('users') . "` WHERE `id` = ?"
			);
			$stmt->execute([$id]);
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			return $row ?: null;
		} catch (PDOException $e) {
			return null;
		}
	}

	/** Look a user up by either their email or username (password-recovery entry point). */
	public static function getByEmailOrUsername(string $input): ?array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return null;
		}
		$table = Database::table('users');
		try {
			$stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE `email` = ? OR `username` = ?");
			$stmt->execute([$input, $input]);
			return $stmt->fetch() ?: null;
		} catch (PDOException $e) {
			return null;
		}
	}
}
