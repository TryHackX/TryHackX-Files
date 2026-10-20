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
	/** A recovery link is re-sent for the same account at most this often. */
	private const RECOVERY_RESEND_SECONDS = 300;

	/** A string field of the JSON request body; anything that is not a string reads as ''. */
	private static function inputString(string $key): string
	{
		$input = json_decode((string) file_get_contents('php://input'), true);
		return is_array($input) && is_string($input[$key] ?? null) ? $input[$key] : '';
	}

	/**
	 * Rate-limiter bucket for wrong sign-in passwords: the whole account, or the account as
	 * seen from one network (filehostThrottleKey(): an IPv6 /64 counts as one).
	 */
	public static function loginAccountBucket(int $accountId, ?string $networkKey = null): string
	{
		return 'acct:' . $accountId . ($networkKey !== null ? '|net:' . $networkKey : '');
	}

	/**
	 * What a sign-in attempt has to pass before its password is checked.
	 *
	 * The answer is never "refuse" for something a stranger elsewhere can cause: with a captcha
	 * configured every limit asks for the captcha instead, and without one only the network
	 * that ran out of guesses for this account is turned away.
	 *
	 * @param bool $accountLimitReached wrong passwords for the account, from anywhere
	 * @param bool $networkLimitReached wrong passwords for the account from this network
	 * @param bool $addressCaptchaDue   the operator's per-address captcha rule applies
	 * @return 'open'|'captcha'|'refuse'
	 */
	public static function loginGate(
		bool $captchaAvailable,
		bool $accountLimitReached,
		bool $networkLimitReached,
		bool $addressCaptchaDue
	): string {
		if ($captchaAvailable) {
			return $accountLimitReached || $networkLimitReached || $addressCaptchaDue ? 'captcha' : 'open';
		}
		return $networkLimitReached ? 'refuse' : 'open';
	}

	public static function handleUserLogin()
	{
		header('Content-Type: application/json');

		$input = json_decode(file_get_contents('php://input'), true);
		$input = is_array($input) ? $input : [];
		$username = is_string($input['username'] ?? null) ? $input['username'] : '';
		$password = is_string($input['password'] ?? null) ? $input['password'] : '';
		$captchaResponse = is_string($input['captcha_response'] ?? null) ? $input['captcha_response'] : '';
		$ip = getClientIP();
		$ipKey = filehostThrottleKey($ip);

		if ($username === '' || $password === '') {
			echo json_encode(['success' => false, 'error' => __('api.give_login_pass')]);
			return;
		}
		if (strlen($username) > InputLimits::HARD_USERNAME_MAX
			|| strlen($password) > InputLimits::HARD_PASSWORD_MAX) {
			echo json_encode(['success' => false, 'error' => __('api.bad_credentials')]);
			return;
		}

		// Three counters, and none of them lets a stranger shut an account out:
		// - per network (an IPv6 /64 is one network): when the operator's captcha is shown;
		// - per account and network: ten wrong passwords for one account from one network in
		//   15 minutes, and that network waits out the window for that account (or solves the
		//   captcha, when one is configured). Every other network, the owner's included, is
		//   untouched;
		// - per account, whatever the address: the brake on a spray from many networks. It only
		//   ever asks for the captcha, so without one it does nothing. Refusing the right
		//   password there would give anyone the power to lock any account.
		$failCount = Database::getSecurityEvent($ipKey, 'login_fail');
		$threshold = (int) Database::getSetting('recaptcha_login_attempt_threshold', 1);
		$loginProtectionEnabled = (Database::getSetting('recaptcha_on_admin', '0') === '1');
		$requireCaptcha = ($threshold >= 0 && $failCount >= $threshold);
		$accountId = UserRepository::idForUsername($username);
		$accountBucket = self::loginAccountBucket($accountId);
		$networkBucket = self::loginAccountBucket($accountId, $ipKey);
		$gate = self::loginGate(
			Database::isRecaptchaEnabled(),
			$accountId > 0 && RateLimiter::exceeded($accountBucket, 'login_account'),
			$accountId > 0 && RateLimiter::exceeded($networkBucket, 'login_account'),
			$loginProtectionEnabled && $requireCaptcha
		);

		if ($gate === 'refuse') {
			http_response_code(429);
			echo json_encode([
				'success' => false,
				'error' => __('api.too_many_attempts_minutes', [
					'minutes' => (int) ceil(RateLimiter::secondsLeft('login_account') / 60),
				]),
			]);
			return;
		}
		if ($gate === 'captcha') {
			if ($captchaResponse === '') {
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

		$result = Database::loginUser($username, $password);

		if ($result['success']) {
			// Reset attempts on success
			Database::clearSecurityEvent($ipKey, 'login_fail');

			$uploadToken = is_string($input['upload_token'] ?? null) ? $input['upload_token'] : '';
			// Only durations the form actually offers; anything else is treated as
			// "this browser session only", which is the safe reading of a bad request.
			$remember = (int) ($input['remember'] ?? 0);
			if (!in_array($remember, RememberTokenRepository::DURATIONS, true)) {
				$remember = 0;
			}

			// 2FA: the password alone doesn't grant a session — park the login and ask for a
			// TOTP code (handleUser2faLogin finishes it). Nothing privileged is set yet. An
			// account whose secret cannot be read right now (see getTotpState) still counts as
			// protected: it is parked too, and only a recovery code can finish the sign-in.
			$totp = Database::getTotpState((int) $result['user']['id']);
			if ($totp['enabled']) {
				$_SESSION['pending_2fa'] = [
					'user_id' => (int) $result['user']['id'],
					'at' => time(),
					'upload_token' => $uploadToken,
					'remember' => $remember,
					'fails' => 0,
				];
				echo json_encode(['success' => false, 'require_2fa' => true]);
				return;
			}

			$handedOver = completeLogin($result['user'], $uploadToken, $remember);
			if ($handedOver !== null) {
				// The page held another account's upload token; this is its own from now on.
				$result['upload_token'] = $handedOver['token'];
				$result['upload_token_expires_at'] = $handedOver['expires_at'];
			}
		} else {
			// Increment attempts
			Database::incrementSecurityEvent($ipKey, 'login_fail');
			// Only a wrong password counts against the account — not an unactivated or banned
			// one answering correctly.
			if ($accountId > 0 && ($result['reason'] ?? '') === 'credentials') {
				RateLimiter::hit($accountBucket, 'login_account');
				RateLimiter::hit($networkBucket, 'login_account');
			}
			unset($result['reason']);
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
		$password = self::inputString('password');
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
		$code = self::inputString('code');
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
		$keepDevice = RememberTokenRepository::remainingLifetime(RememberTokenRepository::presentedCookie(), $userId);
		$codes = Database::enableTotpWithRecoveryCodes($userId);
		if (!$codes) {
			echo json_encode(['success' => false, 'error' => __('api.2fa_enable_failed')]);
			return;
		}
		unset($_SESSION['pending_2fa_setup']);
		self::refreshSessionAfterReauthentication($userId, $keepDevice);
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
		$password = self::inputString('password');
		if (strlen($password) > InputLimits::PASSWORD_MAX) {
			echo json_encode(['success' => false, 'error' => __('api.bad_password')]);
			return;
		}

		$user = Database::getUserById($userId);
		if (!$user || !password_verify($password, $user['password_hash'])) {
			echo json_encode(['success' => false, 'error' => __('api.bad_password')]);
			return;
		}
		$keepDevice = RememberTokenRepository::remainingLifetime(RememberTokenRepository::presentedCookie(), $userId);
		if (!Database::setTotpEnabled($userId, false)) {
			echo json_encode(['success' => false, 'error' => __('api.system_error')]);
			return;
		}
		self::refreshSessionAfterReauthentication($userId, $keepDevice);
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

		$lang = strtolower(trim(self::inputString('language')));

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

		$password = self::inputString('password');
		if (strlen($password) > InputLimits::PASSWORD_MAX) {
			echo json_encode(['success' => false, 'error' => __('api.bad_password')]);
			return;
		}
		$user = Database::getUserById($userId);
		if (!$user || !password_verify($password, $user['password_hash'])) {
			echo json_encode(['success' => false, 'error' => __('api.bad_password')]);
			return;
		}

		$keepDevice = RememberTokenRepository::remainingLifetime(RememberTokenRepository::presentedCookie(), $userId);
		$codes = Database::regenerateRecoveryCodesAndInvalidateAccess($userId);
		if (!$codes) {
			echo json_encode(['success' => false, 'error' => __('api.2fa_codes_failed')]);
			return;
		}
		self::refreshSessionAfterReauthentication($userId, $keepDevice);
		Database::logAudit('2fa_recovery_regenerated', 'user: ' . ($user['username'] ?? ''), $userId, $user['username'] ?? null);
		echo json_encode(['success' => true, 'recovery_codes' => $codes]);
	}

	/** Second login step: verify the TOTP code for the parked (password-verified) login. */
	public static function handleUser2faLogin()
	{
		header('Content-Type: application/json');

		$pending = $_SESSION['pending_2fa'] ?? null;
		if (!is_array($pending) || (time() - (int) ($pending['at'] ?? 0)) > 300) {
			unset($_SESSION['pending_2fa']);
			echo json_encode(['success' => false, 'error' => __('api.2fa_session_expired')]);
			return;
		}

		$ipKey = filehostThrottleKey(getClientIP());
		$userId = (int) $pending['user_id'];
		$accountBucket = 'acct:' . $userId;
		// Three limits, because addresses are cheap: per network, per account (whatever the
		// address), and per parked sign-in — five wrong codes and the password has to be typed
		// again, so one password check can no longer feed an unbounded run of code guesses.
		if (Database::getSecurityEvent($ipKey, 'totp_fail') >= 10
			|| RateLimiter::exceeded($accountBucket, 'totp_account')) {
			echo json_encode(['success' => false, 'error' => __('api.too_many_attempts')]);
			return;
		}

		$input = json_decode(file_get_contents('php://input'), true);
		$code = is_array($input) && is_string($input['code'] ?? null) ? $input['code'] : '';
		$state = Database::getTotpState($userId);

		// A 6-digit authenticator code is the normal path; an 8-character code is tried as a
		// single-use recovery code, so a lost phone doesn't lock the account out. (Trying every
		// wrong 6-digit code against the bcrypt-hashed recovery codes too cost ten hash checks
		// per typo.) A code accepted once is refused for the rest of its time step.
		$usedRecoveryCode = false;
		$totpOk = false;
		if ($state['enabled'] && $state['secret']) {
			$counter = Totp::match($state['secret'], $code);
			$totpOk = $counter !== null && UserRepository::claimTotpCounter($userId, $counter);
		}
		if (!$totpOk && $state['enabled'] && RecoveryCodeRepository::looksLikeCode($code)) {
			$usedRecoveryCode = Database::consumeRecoveryCode($userId, $code);
		}

		if (!$totpOk && !$usedRecoveryCode) {
			Database::incrementSecurityEvent($ipKey, 'totp_fail');
			RateLimiter::hit($accountBucket, 'totp_account');
			$fails = (int) ($pending['fails'] ?? 0) + 1;
			if ($fails >= 5) {
				unset($_SESSION['pending_2fa']);
				echo json_encode(['success' => false, 'error' => __('api.2fa_session_expired')]);
				return;
			}
			$_SESSION['pending_2fa']['fails'] = $fails;
			echo json_encode([
				'success' => false,
				// The secret could not be read (key missing or changed): say so, because no
				// authenticator code can work until an administrator fixes it — only a
				// recovery code can.
				'error' => !empty($state['unavailable']) ? __('api.2fa_unavailable') : __('api.2fa_bad_code'),
				'require_2fa' => true,
			]);
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

		Database::clearSecurityEvent($ipKey, 'totp_fail');
		$user['is_admin'] = ($user['role'] === 'admin');
		$handedOver = completeLogin($user, (string) ($pending['upload_token'] ?? ''), (int) ($pending['remember'] ?? 0));

		$response = ['success' => true, 'user' => [
			'id' => (int) $user['id'],
			'username' => $user['username'],
			'is_admin' => $user['is_admin'],
		]];
		if ($handedOver !== null) {
			$response['upload_token'] = $handedOver['token'];
			$response['upload_token_expires_at'] = $handedOver['expires_at'];
		}
		echo json_encode($response);
	}

	public static function handleUserRegister()
	{
		header('Content-Type: application/json');

		if (Database::getSetting('registration_enabled', '1') !== '1') {
			echo json_encode(['success' => false, 'error' => __('api.reg_disabled')]);
			return;
		}

		$input = json_decode(file_get_contents('php://input'), true);
		$input = is_array($input) ? $input : [];
		$username = is_string($input['username'] ?? null) ? $input['username'] : '';
		$email = is_string($input['email'] ?? null) ? $input['email'] : '';
		$password = is_string($input['password'] ?? null) ? $input['password'] : '';
		$captchaResponse = is_string($input['captcha_response'] ?? null) ? $input['captcha_response'] : '';

		// Registration always requires CAPTCHA if enabled and configured
		$alwaysRequire = (Database::getSetting('recaptcha_register_always', '1') === '1');

		if ($alwaysRequire && Database::isRecaptchaEnabled()) {
			if ($captchaResponse === '' || !Database::verifyRecaptcha($captchaResponse, getClientIP())) {
				http_response_code(400);
				echo json_encode(['success' => false, 'error' => __('api.captcha_required')]);
				return;
			}
		}

		if ($username === '' || $email === '' || $password === '') {
			echo json_encode(['success' => false, 'error' => __('api.fill_all_fields')]);
			return;
		}

		$result = Database::registerUser($username, $email, $password);
		echo json_encode($result);
	}

	/**
	 * Re-enter the password from inside the panel.
	 *
	 * Actions that grant privileges or rewire payments answer `recent_auth_required` when the
	 * last password check is more than a few minutes old (bootstrap.php). The panel then asks
	 * for the password, sends it here, and retries the action. Wrong guesses are capped per
	 * account and network, the same budget the panel's own gate uses.
	 */
	public static function handleUserReauth()
	{
		header('Content-Type: application/json');
		$userId = (int) ($_SESSION['user_id'] ?? 0);
		if ($userId < 1) {
			http_response_code(401);
			echo json_encode(['success' => false, 'error' => __('api.not_logged_in')]);
			return;
		}
		$quota = RateLimiter::hit(
			'reauth:' . $userId . ':' . filehostThrottleKey(getClientIP()),
			'auth'
		);
		if (!$quota['allowed']) {
			http_response_code(429);
			echo json_encode([
				'success' => false,
				'error' => __('panel.reauth.throttled', [
					'minutes' => (string) max(1, (int) ceil(($quota['reset'] - time()) / 60)),
				]),
			]);
			return;
		}

		$input = json_decode(file_get_contents('php://input'), true);
		$password = is_array($input) && is_string($input['password'] ?? null) ? $input['password'] : '';
		if ($password === '' || !Database::verifyUserPassword($userId, $password)) {
			Database::logAudit(
				'panel_reauth_failed',
				'wrong password when re-confirming an action',
				$userId,
				(string) ($_SESSION['user_name'] ?? '')
			);
			echo json_encode(['success' => false, 'error' => __('api.bad_password')]);
			return;
		}

		$_SESSION['recent_auth_at'] = time();
		$_SESSION['panel_auth_at'] = time();
		echo json_encode(['success' => true]);
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
				'current' => (bool) ($row['current'] ?? false),
			];
		}, RememberTokenRepository::devices(
			$userId,
			RememberTokenRepository::presentedCookie()
		));

		echo json_encode([
			'success' => true,
			'enabled' => RememberTokenRepository::enabled(),
			'devices' => $devices,
		]);
	}

	/**
	 * Revoke every persistent sign-in for this account except the browser asking.
	 *
	 * The button exists for someone who has lost a device and cannot tell which row it is, and
	 * they are clicking it from a device that is definitely not the problem. Signing that one
	 * out too would only cost them a password prompt. A session-only sign-in has no current
	 * token, so there is nothing to spare and everything goes.
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
		$password = self::inputString('password');
		if (strlen($password) > InputLimits::HARD_PASSWORD_MAX
			|| !Database::verifyUserPassword($userId, $password)) {
			echo json_encode(['success' => false, 'error' => __('api.bad_password')]);
			return;
		}
		$_SESSION['recent_auth_at'] = time();

		$current = RememberTokenRepository::presentedCookie();
		$revoked = RememberTokenRepository::forgetOthers($userId, $current);
		if ($current === '') {
			RememberTokenRepository::sendCookie('', 0);
		}
		// Deleting the device tokens only stops future sign-ins. A browser that is signed in
		// right now — the lost laptop this button exists for — is ended by advancing the
		// session generation; this browser is then re-established on the new one.
		$pdo = Database::getInstance();
		if ($pdo) {
			UserRepository::advanceSessionVersion($pdo, $userId);
		}
		self::refreshSessionAfterReauthentication($userId);
		Database::logAudit(
			'remember_tokens_revoked',
			"user revoked {$revoked} persistent sign-in(s) from other devices",
			$userId,
			(string) ($_SESSION['user_name'] ?? '')
		);
		echo json_encode([
			'success' => true,
			'revoked' => $revoked,
			'kept_current' => $current !== '',
		]);
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
		// The same goes for the upload session this tab held for the account: uploads after
		// signing out (or after the next person signs in here) must not land in it.
		$userId = (int) ($_SESSION['user_id'] ?? 0);
		if ($userId > 0) {
			TokenRepository::forgetForUser(self::inputString('upload_token'), $userId);
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

		// Check login threshold for current address (the same key handleUserLogin counts under).
		// -1 means "never ask", exactly as the login handler reads it.
		$failCount = Database::getSecurityEvent(filehostThrottleKey(getClientIP()), 'login_fail');
		$threshold = (int) Database::getSetting('recaptcha_login_attempt_threshold', 1);
		$loginCaptchaRequired = $threshold >= 0 && $failCount >= $threshold;

		// Expose only safe public settings
		$config = [
			'registration_enabled' => Database::getSetting('registration_enabled', '1') === '1',
			'recaptcha_enabled' => Database::isRecaptchaEnabled(),
			// Site key of the ACTIVE provider — the front end renders whatever this pair
			// says, so a v2 key must never leak out while Turnstile is the selected one.
			'captcha_provider' => CaptchaService::provider(),
			'recaptcha_site_key' => CaptchaService::siteKey(),
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
		// The home page shows the outcome from a fixed code (see index.php); it used to receive
		// a translated sentence in `?msg=` that nothing ever displayed.
		$token = $_GET['token'] ?? '';
		if (!is_string($token) || $token === '') {
			header('Location: ' . APP_URL . '/index.php?msg=verify_missing');
			exit;
		}

		$result = Database::verifyUserByToken($token);
		header('Location: ' . APP_URL . '/index.php?msg=' . ($result['success'] ? 'verified' : 'verify_failed'));
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

		$currentPass = self::inputString('current_password');
		$newPass = self::inputString('new_password');

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

		$userId = (int) $_SESSION['user_id'];
		$keepDevice = RememberTokenRepository::remainingLifetime(RememberTokenRepository::presentedCookie(), $userId);
		if (Database::updateUserPassword($userId, $newPass)) {
			// Clear rate limit on success
			unset($_SESSION['rate_limits']['change_password_' . $userId]);
			// The change ended every session and revoked every device token and API key. This
			// browser is the one that just proved the old password, so — exactly like the 2FA
			// flows — it is the one kept signed in (and still remembered, if it was); everything
			// else has to sign in again.
			self::refreshSessionAfterReauthentication($userId, $keepDevice);
			// A password change the account holder did not make is the thing they most need to
			// hear about, so this one is announced on both channels by default.
			Notifications::send($userId, 'security.password', [
				'data' => ['ip' => getClientIP()],
				'link' => APP_URL . '/panel.php?tab=user',
			]);
			echo json_encode(['success' => true, 'message' => __('panel.acct.pw_updated')]);
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

		$currentPass = self::inputString('password'); // Note: frontend sends 'password'
		$newEmail = self::inputString('new_email');

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
		$result = Database::confirmEmailChange(is_string($token) ? $token : '');

		if (!$result['success']) {
			// A code, never the message itself: the panel only ever prints texts it owns, so a
			// crafted link cannot put words of its choosing into the account tab.
			$code = match ($result['error'] ?? '') {
				__('mail.change_send_failed') => 'email_change_send_failed',
				__('api.email_blocked') => 'email_blocked',
				default => 'email_link_expired',
			};
			header('Location: panel.php?tab=user&err=' . $code);
			exit;
		}

		// Halfway: the address on file agreed, and a link is now waiting in the new mailbox.
		// Nothing has moved yet, so nothing is announced yet either.
		if (($result['stage'] ?? 'done') === 'new') {
			header('Location: panel.php?tab=user&msg=email_change_stage_two');
			exit;
		}

		// Confirmed from the new address, so the account holder should see it in the app
		// too — the old address is no longer where anything arrives. Addressed to the account
		// that changed, not to whoever happens to be signed in in the browser that opened it.
		$ownerId = (int) ($result['user_id'] ?? 0);
		if ($ownerId > 0) {
			Notifications::send($ownerId, 'security.email', [
				'subject' => (string) ($result['email'] ?? ''),
				'link' => APP_URL . '/panel.php?tab=user',
			]);
		}
		// The change ended every session of the account. If this browser is signed in to that
		// same account it stays signed in, so the confirmation lands on the account tab rather
		// than on a sign-in form with no explanation. It is not a fresh password check, so no
		// recent-authentication window is opened by it.
		if ($ownerId > 0 && (int) ($_SESSION['user_id'] ?? 0) === $ownerId) {
			$user = Database::getUserById($ownerId);
			if ($user && (int) ($user['is_active'] ?? 0) === 1) {
				if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
					session_regenerate_id(true);
				}
				SessionAuth::establish($user);
			}
		}
		header('Location: panel.php?tab=user&msg=email_changed');
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
		$password = is_array($input) && is_string($input['password'] ?? null) ? $input['password'] : '';

		if ($password === '' || !Database::verifyUserPassword((int) $_SESSION['user_id'], $password)) {
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

	public static function handleRecoverPassword()
	{
		header('Content-Type: application/json');
		$input = json_decode(file_get_contents('php://input'), true);
		$userParam = is_array($input) && is_string($input['input'] ?? null) ? trim($input['input']) : '';

		if ($userParam === '') {
			echo json_encode(['success' => false, 'error' => __('api.give_email_or_login')]);
			return;
		}
		if (mb_strlen($userParam, 'UTF-8') > InputLimits::recoveryInputMax()) {
			echo json_encode(['success' => false, 'error' => __('api.input_too_long')]);
			return;
		}

		$ip = filehostThrottleKey(getClientIP());
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
		$sent = ['success' => true, 'message' => __('api.recovery_sent'), 'remaining_attempts' => $remaining];

		// Find user. An unknown name gets the same answer, straight away: the one-second sleep
		// that used to sit here made unknown accounts the SLOW ones, which is exactly the
		// difference an enumerator times.
		$user = Database::getUserByEmailOrUsername($userParam);
		if (!$user) {
			echo json_encode($sent);
			return;
		}

		// One live link per account, re-sent at most every few minutes. Every request used to
		// mint a new token — invalidating the link the owner had just received — and queue
		// another mail, so anyone could keep a victim's genuine reset link dead and their inbox
		// full from a handful of addresses.
		if (RecoveryRepository::issuedWithin((int) $user['id'], self::RECOVERY_RESEND_SECONDS)) {
			echo json_encode($sent);
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
		$appName = defined('APP_NAME') ? APP_NAME : (defined('PRODUCT_NAME') ? PRODUCT_NAME : 'TryHackX Files');
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

		if (Database::sendHtmlEmail(
			$user['email'],
			$subject,
			$body,
			'recovery:' . hash('sha256', $token)
		)) {
			echo json_encode($sent);
		} else {
			echo json_encode(['success' => false, 'error' => __('api.email_send_error'), 'remaining_attempts' => $remaining]);
		}
	}

	public static function handleResetPasswordSubmit()
	{
		header('Content-Type: application/json');
		$input = json_decode(file_get_contents('php://input'), true);
		$input = is_array($input) ? $input : [];
		$token = is_string($input['token'] ?? null) ? $input['token'] : '';
		$password = is_string($input['password'] ?? null) ? $input['password'] : '';

		if ($token === '' || $password === '') {
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
	private static function refreshSessionAfterReauthentication(int $userId, int $keepDeviceSeconds = 0): void
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

		// The change revoked every device token, this browser's included. If this browser was
		// remembered, it gets a new token for the time its old one had left, so it is not
		// signed out the moment the browser session ends.
		if ($keepDeviceSeconds > 0) {
			$cookie = RememberTokenRepository::issue(
				$userId,
				$keepDeviceSeconds,
				function_exists('getClientIP') ? getClientIP() : '',
				(string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
			);
			if ($cookie !== '') {
				RememberTokenRepository::sendCookie($cookie, $keepDeviceSeconds);
			}
		}
	}
}
