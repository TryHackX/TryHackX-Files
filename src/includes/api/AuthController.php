<?php
/**
 * AuthController (Faza 5 · #1).
 *
 * Account + session endpoints: login (incl. 2FA second step), registration, activation,
 * logout, the public app config, availability checks, password / email changes, account
 * deletion, own-account stats, and password recovery. Handler bodies are the former
 * api.php handleXxx functions, moved verbatim; the router dispatches here.
 */
final class AuthController
{
	public static function handleUserLogin()
	{
		header('Content-Type: application/json');

		$input = json_decode(file_get_contents('php://input'), true);
		$username = $input['username'] ?? '';
		$password = $input['password'] ?? '';
		$captchaResponse = $input['captcha_response'] ?? '';
		$ip = getClientIP();

		// Check Security Threshold
		$failCount = Database::getSecurityEvent($ip, 'login_fail');
		$threshold = (int) Database::getSetting('recaptcha_login_attempt_threshold', 1);
		$loginProtectionEnabled = (Database::getSetting('recaptcha_on_admin', '0') === '1');
		$requireCaptcha = ($threshold >= 0 && $failCount >= $threshold);

		if ($loginProtectionEnabled && $requireCaptcha && Database::isRecaptchaEnabled()) {
			if (empty($captchaResponse)) {
				http_response_code(400);
				echo json_encode(['success' => false, 'error' => __('api.captcha_required'), 'require_captcha' => true]);
				return;
			}
			if (!Database::verifyRecaptcha($captchaResponse, $ip)) {
				http_response_code(400);
				echo json_encode(['success' => false, 'error' => __('api.captcha_bad'), 'require_captcha' => true]);
				return;
			}
		}

		if (empty($username) || empty($password)) {
			echo json_encode(['success' => false, 'error' => __('api.give_login_pass')]);
			return;
		}
		if (strlen((string) $username) > InputLimits::HARD_USERNAME_MAX
			|| strlen((string) $password) > InputLimits::HARD_PASSWORD_MAX) {
			echo json_encode(['success' => false, 'error' => __('api.bad_credentials')]);
			return;
		}

		$result = Database::loginUser($username, $password);

		if ($result['success']) {
			// Reset attempts on success
			Database::clearSecurityEvent($ip, 'login_fail');
			unset($_SESSION['login_attempts']); // Cleanup legacy

			$uploadToken = $input['upload_token'] ?? '';
			// Only durations the form actually offers; anything else is treated as
			// "this browser session only", which is the safe reading of a bad request.
			$remember = (int) ($input['remember'] ?? 0);
			if (!in_array($remember, RememberTokenRepository::DURATIONS, true)) {
				$remember = 0;
			}

			// 2FA: the password alone doesn't grant a session — park the login and ask for a
			// TOTP code (handleUser2faLogin finishes it). Nothing privileged is set yet.
			$totp = Database::getTotpState((int) $result['user']['id']);
			if ($totp['enabled']) {
				$_SESSION['pending_2fa'] = [
					'user_id' => (int) $result['user']['id'],
					'at' => time(),
					'upload_token' => $uploadToken,
					'remember' => $remember,
				];
				echo json_encode(['success' => false, 'require_2fa' => true]);
				return;
			}

			completeLogin($result['user'], $uploadToken, $remember);
		} else {
			// Increment attempts
			Database::incrementSecurityEvent($ip, 'login_fail');
			$failCount++; // Update local for response check
			if ($threshold >= 0 && $failCount >= $threshold) {
				$result['require_captcha'] = true;
			}
		}

		echo json_encode($result);
	}

	/* ---- 2FA / TOTP (Faza 4.4) ---- */

	public static function handleUser2faStatus()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id'])) {
			echo json_encode(['success' => false, 'error' => __('api.not_logged_in')]);
			return;
		}
		$userId = (int) $_SESSION['user_id'];
		$state = Database::getTotpState($userId);
		echo json_encode([
			'success' => true,
			'enabled' => $state['enabled'],
			// So the account tab can warn when the user is running low on fallbacks.
			'recovery_remaining' => $state['enabled'] ? Database::countRecoveryCodes($userId) : 0,
		]);
	}

	/** Start setup: mint a secret (stored but inactive) and hand back the otpauth URI for a QR. */
	public static function handleUser2faSetup()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.not_logged_in')]);
			return;
		}
		$userId = (int) $_SESSION['user_id'];
		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$password = (string) ($input['password'] ?? '');
		if (strlen($password) > InputLimits::PASSWORD_MAX) {
			echo json_encode(['success' => false, 'error' => __('api.bad_password')]);
			return;
		}
		if (!Database::verifyUserPassword($userId, $password)) {
			echo json_encode(['success' => false, 'error' => __('api.bad_password')]);
			return;
		}
		if (Database::getTotpState($userId)['enabled']) {
			echo json_encode(['success' => false, 'error' => __('api.2fa_already_on')]);
			return;
		}

		$secret = Totp::generateSecret();
		if (!Database::setTotpSecret($userId, $secret)) {
			echo json_encode(['success' => false, 'error' => __('api.2fa_prepare_failed')]);
			return;
		}
		$_SESSION['recent_auth_at'] = time();
		$_SESSION['pending_2fa_setup'] = [
			'user_id' => $userId,
			'at' => time(),
		];

		$account = $_SESSION['user_name'] ?? ('user' . $userId);
		$issuer = defined('APP_NAME') ? APP_NAME : (defined('PRODUCT_NAME') ? PRODUCT_NAME : 'TryHackX Files');
		echo json_encode([
			'success' => true,
			'secret' => $secret, // shown for manual entry; the QR carries the same value
			'uri' => Totp::uri($secret, $account, $issuer),
		]);
	}

	/** Finish setup: a correct code proves the app holds the same secret, so activate it. */
	public static function handleUser2faConfirm()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.not_logged_in')]);
			return;
		}
		$userId = (int) $_SESSION['user_id'];
		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$code = (string) ($input['code'] ?? '');
		$pending = $_SESSION['pending_2fa_setup'] ?? null;
		if (
			!is_array($pending)
			|| (int) ($pending['user_id'] ?? 0) !== $userId
			|| time() - (int) ($pending['at'] ?? 0) > 600
			|| time() - (int) ($_SESSION['recent_auth_at'] ?? 0) > 600
		) {
			unset($_SESSION['pending_2fa_setup']);
			echo json_encode(['success' => false, 'error' => __('api.2fa_session_expired')]);
			return;
		}

		$state = Database::getTotpState($userId);
		if (!$state['secret']) {
			echo json_encode(['success' => false, 'error' => __('api.2fa_no_setup')]);
			return;
		}
		if (!Totp::verify($state['secret'], $code)) {
			echo json_encode(['success' => false, 'error' => __('api.2fa_bad_code_retry')]);
			return;
		}
		$codes = Database::enableTotpWithRecoveryCodes($userId);
		if (!$codes) {
			echo json_encode(['success' => false, 'error' => __('api.2fa_enable_failed')]);
			return;
		}
		unset($_SESSION['pending_2fa_setup']);
		self::refreshSessionAfterReauthentication($userId);
		Database::logAudit('2fa_enabled', 'user: ' . ($_SESSION['user_name'] ?? ''), $userId, $_SESSION['user_name'] ?? null);

		echo json_encode(['success' => true, 'recovery_codes' => $codes]);
	}

	/** Turning 2FA off requires the account password, so a hijacked session can't strip it. */
	public static function handleUser2faDisable()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.not_logged_in')]);
			return;
		}
		$userId = (int) $_SESSION['user_id'];
		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$password = (string) ($input['password'] ?? '');
		if (strlen($password) > InputLimits::PASSWORD_MAX) {
			echo json_encode(['success' => false, 'error' => __('api.bad_password')]);
			return;
		}

		$user = Database::getUserById($userId);
		if (!$user || !password_verify($password, $user['password_hash'])) {
			echo json_encode(['success' => false, 'error' => __('api.bad_password')]);
			return;
		}
		if (!Database::setTotpEnabled($userId, false)) {
			echo json_encode(['success' => false, 'error' => __('api.system_error')]);
			return;
		}
		self::refreshSessionAfterReauthentication($userId);
		Database::logAudit('2fa_disabled', 'user: ' . ($user['username'] ?? ''), $userId, $user['username'] ?? null);
		echo json_encode(['success' => true]);
	}

	/**
	 * Store the user's preferred interface language.
	 *
	 * Kept in the account row as well as the cookie so the choice follows them to a new
	 * browser, and so anything the app writes *to* the user later can use it. Only languages
	 * the admin has enabled are accepted.
	 */
	public static function handleUserSetLanguage()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.not_logged_in')]);
			return;
		}

		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$lang = strtolower(trim((string) ($input['language'] ?? '')));

		// An empty value clears the preference and returns the user to automatic resolution.
		// pt 6: the check is against forUsers(), the set the admin offers here, not merely what
		// is installed — otherwise a hand-written request could pin a hidden language.
		if ($lang !== '' && !isset(Lang::forUsers()[$lang])) {
			echo json_encode(['success' => false, 'error' => __('api.lang_unknown')]);
			return;
		}

		$pdo = Database::getInstance();
		if (!$pdo) {
			echo json_encode(['success' => false, 'error' => __('api.db_error')]);
			return;
		}
		try {
			$stmt = $pdo->prepare("UPDATE `" . Database::table('users') . "` SET `language` = ? WHERE `id` = ?");
			$stmt->execute([$lang !== '' ? $lang : null, (int) $_SESSION['user_id']]);
		} catch (PDOException $e) {
			echo json_encode(['success' => false, 'error' => __('api.db_error')]);
			return;
		}

		// Keep the cookie in step so the very next page render already uses it.
		if ($lang !== '') {
			$_SESSION['user_language'] = $lang;
			$_COOKIE['lang'] = $lang;
		} else {
			unset($_SESSION['user_language'], $_COOKIE['lang']);
		}
		if (!headers_sent()) {
			setcookie('lang', $lang, [
				'expires' => $lang !== '' ? time() + 31536000 : time() - 3600,
				'path' => '/',
				'secure' => function_exists('isRequestSecure') ? isRequestSecure() : false,
				'httponly' => false,
				'samesite' => 'Lax',
			]);
		}

		echo json_encode(['success' => true, 'language' => $lang]);
	}

	/**
	 * Issue a fresh set of recovery codes, invalidating the previous ones.
	 *
	 * Guarded by the account password for the same reason disabling 2FA is: a hijacked
	 * session must not be able to mint itself a permanent way back in.
	 */
	public static function handleUser2faRecoveryCodes()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.not_logged_in')]);
			return;
		}
		$userId = (int) $_SESSION['user_id'];

		if (!Database::getTotpState($userId)['enabled']) {
			echo json_encode(['success' => false, 'error' => __('api.2fa_not_on')]);
			return;
		}

		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$password = (string) ($input['password'] ?? '');
		if (strlen($password) > InputLimits::PASSWORD_MAX) {
			echo json_encode(['success' => false, 'error' => __('api.bad_password')]);
			return;
		}
		$user = Database::getUserById($userId);
		if (!$user || !password_verify($password, $user['password_hash'])) {
			echo json_encode(['success' => false, 'error' => __('api.bad_password')]);
			return;
		}

		$codes = Database::regenerateRecoveryCodesAndInvalidateAccess($userId);
		if (!$codes) {
			echo json_encode(['success' => false, 'error' => __('api.2fa_codes_failed')]);
			return;
		}
		self::refreshSessionAfterReauthentication($userId);
		Database::logAudit('2fa_recovery_regenerated', 'user: ' . ($user['username'] ?? ''), $userId, $user['username'] ?? null);
		echo json_encode(['success' => true, 'recovery_codes' => $codes]);
	}

	/** Second login step: verify the TOTP code for the parked (password-verified) login. */
	public static function handleUser2faLogin()
	{
		header('Content-Type: application/json');

		$pending = $_SESSION['pending_2fa'] ?? null;
		if (!$pending || (time() - (int) $pending['at']) > 300) {
			unset($_SESSION['pending_2fa']);
			echo json_encode(['success' => false, 'error' => __('api.2fa_session_expired')]);
			return;
		}

		$ip = getClientIP();
		if (Database::getSecurityEvent($ip, 'totp_fail') >= 10) {
			echo json_encode(['success' => false, 'error' => __('api.too_many_attempts')]);
			return;
		}

		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$code = (string) ($input['code'] ?? '');
		$userId = (int) $pending['user_id'];
		$state = Database::getTotpState($userId);

		// A 6-digit authenticator code is the normal path; anything else is tried as a
		// single-use recovery code, so a lost phone doesn't lock the account out. Both are
		// gated by the same per-IP failure counter checked above.
		$usedRecoveryCode = false;
		$totpOk = $state['enabled'] && $state['secret'] && Totp::verify($state['secret'], $code);
		if (!$totpOk && $state['enabled']) {
			$usedRecoveryCode = Database::consumeRecoveryCode($userId, $code);
		}

		if (!$totpOk && !$usedRecoveryCode) {
			Database::incrementSecurityEvent($ip, 'totp_fail');
			echo json_encode(['success' => false, 'error' => __('api.2fa_bad_code'), 'require_2fa' => true]);
			return;
		}

		$user = Database::getUserById($userId);
		if (!$user || (int) ($user['is_active'] ?? 0) !== 1) {
			unset($_SESSION['pending_2fa']);
			echo json_encode(['success' => false, 'error' => __('api.account_not_found')]);
			return;
		}

		if ($usedRecoveryCode) {
			$left = Database::countRecoveryCodes($userId);
			Database::logAudit('2fa_recovery_used', "codes left: $left", $userId, $user['username'] ?? null);
		}

		Database::clearSecurityEvent($ip, 'totp_fail');
		$user['is_admin'] = ($user['role'] === 'admin');
		completeLogin($user, (string) ($pending['upload_token'] ?? ''), (int) ($pending['remember'] ?? 0));

		echo json_encode(['success' => true, 'user' => [
			'id' => (int) $user['id'],
			'username' => $user['username'],
			'is_admin' => $user['is_admin'],
		]]);
	}

	public static function handleUserRegister()
	{
		header('Content-Type: application/json');

		if (Database::getSetting('registration_enabled', '1') !== '1') {
			echo json_encode(['success' => false, 'error' => __('api.reg_disabled')]);
			return;
		}

		$input = json_decode(file_get_contents('php://input'), true);
		$username = $input['username'] ?? '';
		$email = $input['email'] ?? '';
		$password = $input['password'] ?? '';
		$captchaResponse = $input['captcha_response'] ?? '';

		// Registration always requires CAPTCHA if enabled and configured
		$alwaysRequire = (Database::getSetting('recaptcha_register_always', '1') === '1');

		if ($alwaysRequire && Database::isRecaptchaEnabled()) {
			if (empty($captchaResponse) || !Database::verifyRecaptcha($captchaResponse, getClientIP())) {
				http_response_code(400);
				echo json_encode(['success' => false, 'error' => __('api.captcha_required')]);
				return;
			}
		}

		if (empty($username) || empty($email) || empty($password)) {
			echo json_encode(['success' => false, 'error' => __('api.fill_all_fields')]);
			return;
		}

		$result = Database::registerUser($username, $email, $password);
		echo json_encode($result);
	}

	public static function handleUserCheck()
	{
		header('Content-Type: application/json');
		// Session already started at the top of api.php

		if (isset($_SESSION['user_id'])) {
			echo json_encode([
				'success' => true,
				'logged_in' => true,
				'user' => [
					'id' => $_SESSION['user_id'],
					'username' => $_SESSION['user_name'],
					'is_admin' => $_SESSION['is_admin'] ?? false
				]
			]);
		} else {
			echo json_encode(['success' => true, 'logged_in' => false]);
		}
	}

	/**
	 * The devices that can currently sign this account back in without a password.
	 *
	 * A persistent sign-in is a credential the user cannot see, so it has to be listed
	 * somewhere they can. The series and secret are never exposed — only when the device was
	 * first trusted, when it last used its token, when it stops working, and the address and
	 * browser string it was last seen from.
	 */
	public static function handleUserRememberDevices()
	{
		header('Content-Type: application/json');
		$userId = (int) ($_SESSION['user_id'] ?? 0);
		if ($userId < 1) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.not_logged_in')]);
			return;
		}
		$devices = array_map(static function (array $row): array {
			return [
				'created_at' => (int) $row['created_at'],
				'last_used_at' => (int) ($row['last_used_at'] ?? 0),
				'expires_at' => (int) $row['expires_at'],
				'last_ip' => (string) ($row['last_ip'] ?? ''),
				'user_agent' => (string) ($row['user_agent'] ?? ''),
			];
		}, RememberTokenRepository::devices($userId));

		echo json_encode([
			'success' => true,
			'enabled' => RememberTokenRepository::enabled(),
			'devices' => $devices,
		]);
	}

	/**
	 * Revoke every persistent sign-in for this account, this browser included.
	 *
	 * Deliberately all of them and not "the others": the point of the button is that someone
	 * has lost a device and cannot tell which entry it is. Leaving the current one alive would
	 * also mean trusting that whoever clicked is the owner, which is what the password check
	 * in front of it establishes rather than assumes.
	 */
	public static function handleUserRememberRevoke()
	{
		header('Content-Type: application/json');
		$userId = (int) ($_SESSION['user_id'] ?? 0);
		if ($userId < 1) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.not_logged_in')]);
			return;
		}
		// The password, here and now — the same rule the rest of the account tab follows. A
		// hijacked session must not be able to quietly cut the owner's other devices loose.
		$input = json_decode(file_get_contents('php://input'), true) ?: [];
		$password = (string) ($input['password'] ?? '');
		if (strlen($password) > InputLimits::HARD_PASSWORD_MAX
			|| !Database::verifyUserPassword($userId, $password)) {
			echo json_encode(['success' => false, 'error' => __('api.bad_password')]);
			return;
		}
		$_SESSION['recent_auth_at'] = time();

		$revoked = RememberTokenRepository::forgetUser($userId);
		RememberTokenRepository::sendCookie('', 0);
		Database::logAudit(
			'remember_tokens_revoked',
			"user revoked {$revoked} persistent sign-in(s)",
			$userId,
			(string) ($_SESSION['user_name'] ?? '')
		);
		echo json_encode(['success' => true, 'revoked' => $revoked]);
	}

	public static function handleUserLogout()
	{
		header('Content-Type: application/json');
		// Session already started at the top of api.php

		// Signing out has to take the device credential with it. Destroying the session alone
		// would leave a cookie that silently signs the browser straight back in.
		$cookie = RememberTokenRepository::presentedCookie();
		if ($cookie !== '') {
			RememberTokenRepository::forget($cookie);
			RememberTokenRepository::sendCookie('', 0);
		}

		$_SESSION = [];
		if (ini_get("session.use_cookies")) {
			$params = session_get_cookie_params();
			setcookie(
				session_name(),
				'',
				time() - 42000,
				$params["path"],
				$params["domain"],
				$params["secure"],
				$params["httponly"]
			);
		}
		session_destroy();
		echo json_encode(['success' => true]);
	}

	public static function handleConfig()
	{
		header('Content-Type: application/json');

		$ip = getClientIP();
		// Check login threshold for current IP
		$failCount = Database::getSecurityEvent($ip, 'login_fail');
		$threshold = (int) Database::getSetting('recaptcha_login_attempt_threshold', 1);
		$loginCaptchaRequired = ($failCount >= $threshold);

		// Expose only safe public settings
		$config = [
			'registration_enabled' => Database::getSetting('registration_enabled', '1') === '1',
			'recaptcha_enabled' => Database::isRecaptchaEnabled(),
			'recaptcha_site_key' => Database::getSetting('recaptcha_site_key', ''),
			'recaptcha_on_login' => Database::getSetting('recaptcha_on_admin', '0') === '1',
			'login_captcha_required' => $loginCaptchaRequired,
			'recaptcha_register_always' => Database::getSetting('recaptcha_register_always', '1') === '1',
			'recaptcha_token_lifetime' => (int) Database::getSetting('recaptcha_token_lifetime', '120'),
			'input_limits' => [
				'username_min' => InputLimits::usernameMin(),
				'username_max' => InputLimits::usernameMax(),
				'email_max' => InputLimits::emailMax(),
				'password_min' => InputLimits::accountPasswordMin(),
				'password_max' => InputLimits::accountPasswordMax(),
			],
			// ... potentially other limits for UI hints
		];

		echo json_encode($config);
	}

	public static function handleCheckUsername()
	{
		header('Content-Type: application/json');

		$username = trim($_GET['username'] ?? '');

		if (strlen($username) < InputLimits::usernameMin()) {
			echo json_encode(['available' => false, 'error' => __('api.name_too_short')]);
			return;
		}
		if (!InputLimits::validUsername($username)) {
			echo json_encode(['available' => false, 'error' => __('api.username_format')]);
			return;
		}

		$available = Database::isUsernameAvailable($username);
		echo json_encode(['available' => $available]);
	}

	public static function handleCheckEmail()
	{
		header('Content-Type: application/json');

		$email = trim($_GET['email'] ?? '');

		if (!InputLimits::validEmail($email)) {
			echo json_encode(['available' => false, 'error' => __('api.bad_email2')]);
			return;
		}

		$available = Database::isEmailAvailable($email);
		echo json_encode(['available' => $available]);
	}

	public static function handleVerifyEmail()
	{
		$token = $_GET['token'] ?? '';
		if (!$token) {
			header("Location: " . APP_URL . "/index.php?msg=" . urlencode(__('api.verification_token_missing')));
			exit;
		}

		$result = Database::verifyUserByToken($token);
		if ($result['success']) {
			header("Location: " . APP_URL . "/index.php?msg=" . urlencode(__('api.verification_success')));
		} else {
			header("Location: " . APP_URL . "/index.php?msg=" . urlencode(__('api.verification_failed')));
		}
		exit;
	}

	public static function handleResendVerification()
	{
		header('Content-Type: application/json');

		if (session_status() === PHP_SESSION_NONE)
			session_start();
		$userId = $_SESSION['pending_activation_user_id'] ?? 0;

		if (!$userId) {
			echo json_encode(['success' => false, 'error' => __('api.no_perm_relogin')]);
			exit;
		}

		$result = Database::resendActivationEmailById((int) $userId);
		echo json_encode($result);
		exit;
	}

	public static function handleUserStats()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id'])) {
			echo json_encode(['success' => false, 'error' => __('api.not_logged_in2')]);
			return;
		}

		$stats = Database::getUserStats($_SESSION['user_id']);
		echo json_encode(['success' => true, 'stats' => $stats]);
	}

	public static function handleUserChangePassword()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.not_logged_in2')]);
			return;
		}

		if (!checkRateLimit('change_password')) {
			echo json_encode(['success' => false, 'error' => __('api.too_many_attempts_4h')]);
			return;
		}

		$input = json_decode(file_get_contents('php://input'), true);
		$currentPass = $input['current_password'] ?? '';
		$newPass = $input['new_password'] ?? '';

		if (!is_string($currentPass) || strlen($currentPass) > InputLimits::PASSWORD_MAX) {
			incrementRateLimit('change_password');
			echo json_encode(['success' => false, 'error' => __('api.bad_current_password')]);
			return;
		}
		if (!is_string($newPass)) {
			echo json_encode(['success' => false, 'error' => __('api.pass_weak')]);
			return;
		}
		if (!Database::verifyUserPassword($_SESSION['user_id'], $currentPass)) {
			incrementRateLimit('change_password');
			echo json_encode(['success' => false, 'error' => __('api.bad_current_password')]);
			return;
		}

		if (!PasswordPolicy::isValid($newPass)) {
			echo json_encode(['success' => false, 'error' => __('api.pass_weak')]);
			return;
		}

		if ($currentPass === $newPass) {
			echo json_encode(['success' => false, 'error' => __('api.newpass_same')]);
			return;
		}

		if (Database::updateUserPassword($_SESSION['user_id'], $newPass)) {
			// Clear rate limit on success
			unset($_SESSION['rate_limits']['change_password_' . $_SESSION['user_id']]);
			// A password change the account holder did not make is the thing they most need to
			// hear about, so this one is announced on both channels by default.
			Notifications::send((int) $_SESSION['user_id'], 'security.password', [
				'data' => ['ip' => getClientIP()],
				'link' => APP_URL . '/panel.php?tab=user',
			]);
			echo json_encode(['success' => true]);
		} else {
			echo json_encode(['success' => false, 'error' => __('api.pass_update_failed')]);
		}
	}

	public static function handleUserRequestEmailChange()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.not_logged_in2')]);
			return;
		}

		if (!checkRateLimit('change_email')) {
			echo json_encode(['success' => false, 'error' => __('api.too_many_attempts_4h')]);
			return;
		}

		$input = json_decode(file_get_contents('php://input'), true);
		$currentPass = $input['password'] ?? ''; // Note: frontend sends 'password'
		$newEmail = $input['new_email'] ?? '';

		if (!is_string($currentPass) || strlen($currentPass) > InputLimits::PASSWORD_MAX) {
			incrementRateLimit('change_email');
			echo json_encode(['success' => false, 'error' => __('api.bad_password2')]);
			return;
		}
		if (!Database::verifyUserPassword($_SESSION['user_id'], $currentPass)) {
			incrementRateLimit('change_email');
			echo json_encode(['success' => false, 'error' => __('api.bad_password2')]);
			return;
		}

		if (!InputLimits::validEmail((string) $newEmail)) {
			echo json_encode(['success' => false, 'error' => __('api.bad_email3')]);
			return;
		}

		$result = Database::requestEmailChange($_SESSION['user_id'], $newEmail);

		if (!$result['success']) {
			// Maybe increment limit on specific logic errors too? For now only auth errors.
		} else {
			unset($_SESSION['rate_limits']['change_email_' . $_SESSION['user_id']]);
		}

		echo json_encode($result);
	}

	public static function handleUserVerifyEmailChange()
	{
		$token = $_GET['token'] ?? '';
		$result = Database::confirmEmailChange($token);

		// Redirect to panel or show message
		if ($result['success']) {
			// Confirmed from the *new* address, so the account holder should see it in the app
			// too — the old address is no longer where anything arrives.
			if (isset($_SESSION['user_id'])) {
				Notifications::send((int) $_SESSION['user_id'], 'security.email', [
					'subject' => (string) ($result['email'] ?? ''),
					'link' => APP_URL . '/panel.php?tab=user',
				]);
			}
			// Ideally redirect to panel with success message
			header('Location: panel.php?tab=user&msg=email_changed');
		} else {
			header('Location: panel.php?tab=user&err=' . urlencode($result['error']));
		}
		exit;
	}

	public static function handleUserDeleteAccount()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.not_logged_in2')]);
			return;
		}

		// pt 4: the owner account is not deletable, so say so before the password is even
		// checked — and long before the files are removed (which used to happen anyway, with
		// the deletion then failing in the repository).
		if (Database::isRootAdmin((int) $_SESSION['user_id'])) {
			echo json_encode(['success' => false, 'error' => __('api.root_admin_protected')]);
			return;
		}

		$input = json_decode(file_get_contents('php://input'), true);
		$password = $input['password'] ?? '';

		if (!Database::verifyUserPassword($_SESSION['user_id'], $password)) {
			echo json_encode(['success' => false, 'error' => __('api.bad_password2')]);
			return;
		}

		// User metadata and every owned file row are removed in one transaction. Physical
		// artifacts are queued durably and retried by the cleanup worker.
		if (Database::deleteUser($_SESSION['user_id'])) {
			FileManager::processDeletionQueue(100);
			// Logout
			session_destroy();
			echo json_encode(['success' => true]);
		} else {
			echo json_encode(['success' => false, 'error' => __('api.delete_account_failed')]);
		}
	}

	public static function handleGetUserStats()
	{
		header('Content-Type: application/json');
		if (!isset($_SESSION['user_id'])) {
			http_response_code(403);
			echo json_encode(['success' => false, 'error' => __('api.not_logged_in2')]);
			return;
		}

		$pdo = Database::getInstance();
		$prefix = defined('DB_PREFIX') ? DB_PREFIX : '';
		$stmt = $pdo->prepare("SELECT COUNT(*) as count, COALESCE(SUM(size), 0) as size, COALESCE(SUM(downloads), 0) as downloads FROM `{$prefix}files` WHERE user_id = ?");
		$stmt->execute([$_SESSION['user_id']]);
		$stats = $stmt->fetch();

		echo json_encode([
			'success' => true,
			'stats' => [
				'files' => (int) $stats['count'],
				'storage' => (int) $stats['size'],
				'downloads' => (int) $stats['downloads']
			]
		]);
	}

	public static function handleRecoverPassword()
	{
		header('Content-Type: application/json');
		$input = json_decode(file_get_contents('php://input'), true);
		$userParam = trim($input['input'] ?? '');

		if (empty($userParam)) {
			echo json_encode(['success' => false, 'error' => __('api.give_email_or_login')]);
			return;
		}
		if (mb_strlen($userParam, 'UTF-8') > InputLimits::recoveryInputMax()) {
			echo json_encode(['success' => false, 'error' => __('api.input_too_long')]);
			return;
		}

		$ip = getClientIP();
		$limit = (int) Database::getSetting('recovery_attempts_limit', 5);
		$window = (int) Database::getSetting('recovery_window_hours', 48);

		// Check rate limit
		$attempts = Database::getRecoveryAttemptsCount($ip, $window);
		if ($attempts >= $limit) {
			echo json_encode(['success' => false, 'error' => __('api.recovery_limit'), 'remaining_attempts' => 0]);
			return;
		}

		// Always log attempt
		Database::logRecoveryAttempt($ip);
		$remaining = $limit - ($attempts + 1);

		// Find user
		$user = Database::getUserByEmailOrUsername($userParam);

		if (!$user) {
			// Mock success to prevent enumeration, but show remaining attempts
			// Delay slightly
			sleep(1);
			echo json_encode(['success' => true, 'message' => __('api.recovery_sent'), 'remaining_attempts' => $remaining]);
			return;
		}

		// Create token
		$token = Database::createRecoveryToken($user['id']);
		if (!$token) {
			echo json_encode(['success' => false, 'error' => __('api.system_error'), 'remaining_attempts' => $remaining]);
			return;
		}

		// Send email
		$appUrl = APP_URL;
		$resetLink = "$appUrl/?action=reset&token=$token";
		$appName = Database::getSetting(
			'app_name',
			defined('PRODUCT_NAME') ? PRODUCT_NAME : 'TryHackX Files'
		);
		$language = trim((string) ($user['language'] ?? ''));
		if ($language === '') {
			$language = (string) Database::getSetting('default_language', 'pl');
		}
		$translate = static fn(string $key, array $params = []): string =>
			Lang::translateFor($language, $key, $params);
		$safeUsername = htmlspecialchars((string) $user['username'], ENT_QUOTES, 'UTF-8');
		$safeResetLink = htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8');

		$subject = $translate('mail.recovery_subject', ['app' => $appName]);
		$body = '<p>' . $translate('mail.hello', ['name' => $safeUsername]) . '</p>';
		$body .= '<p>' . $translate('mail.recovery_intro') . '</p>';
		$body .= '<p>' . $translate('mail.recovery_click') . '</p>';
		$body .= "<p><a href='$safeResetLink' style='background:#3182ce;color:white;padding:10px 20px;text-decoration:none;border-radius:5px;'>"
			. $translate('mail.reset_button') . '</a></p>';
		$body .= "<p style='margin-top:20px;font-size:12px;color:#888;'>"
			. $translate('mail.button_fallback')
			. "<br><a href='$safeResetLink' style='word-break:break-all;overflow-wrap:break-word;color:#3182ce;'>"
			. $safeResetLink . '</a></p>';
		$body .= '<p>' . $translate('mail.link_valid_minutes', ['minutes' => 15]) . '</p>';
		$body .= '<p><small>' . $translate('mail.change_ignore') . '</small></p>';

		if (Database::sendEmail(
			$user['email'],
			$subject,
			$body,
			'recovery:' . hash('sha256', $token)
		)) {
			echo json_encode(['success' => true, 'message' => __('api.recovery_sent'), 'remaining_attempts' => $remaining]);
		} else {
			echo json_encode(['success' => false, 'error' => __('api.email_send_error'), 'remaining_attempts' => $remaining]);
		}
	}

	public static function handleResetPasswordSubmit()
	{
		header('Content-Type: application/json');
		$input = json_decode(file_get_contents('php://input'), true);
		$token = $input['token'] ?? '';
		$password = $input['password'] ?? '';

		if (empty($token) || empty($password)) {
			echo json_encode(['success' => false, 'error' => __('api.missing_data')]);
			return;
		}

		if (!PasswordPolicy::isValid($password)) {
			echo json_encode(['success' => false, 'error' => __('api.pass_weak')]);
			return;
		}

		if (Database::consumeRecoveryTokenAndResetPassword($token, $password)) {
			echo json_encode(['success' => true]);
		} else {
			echo json_encode(['success' => false, 'error' => __('api.link_expired')]);
		}
	}

	/**
	 * Keep only the browser that just supplied a fresh factor after a global credential revoke.
	 */
	private static function refreshSessionAfterReauthentication(int $userId): void
	{
		$user = Database::getUserById($userId);
		if (!$user || (int) ($user['is_active'] ?? 0) !== 1) {
			SessionAuth::invalidate();
			return;
		}
		if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
			session_regenerate_id(true);
		}
		SessionAuth::establish($user);
		$_SESSION['recent_auth_at'] = time();
	}
}
