<?php
/**
 * TryHackX Files installer
 *
 * Self-contained setup wizard for Windows and Linux.
 * Environment checks and installation steps run asynchronously through a JSON API.
 *
 * Security:
 *  - an existing config.local.php or install.lock closes the installer unconditionally;
 *  - bootstrap requires a one-time environment secret and an allowed source IP;
 *  - every API action is POST-only and requires a session-bound CSRF token.
 */

declare(strict_types=1);

// The installer lives in public/, but writes secrets/storage to the project root (one level up):
// config/config.local.php, data/, uploads/ — all outside the web root.
define('INSTALLER_ROOT', dirname(__DIR__));
define('INSTALLER_LOCK', INSTALLER_ROOT . '/data/install.lock');
define('INSTALLER_CONFIG', INSTALLER_ROOT . '/config/config.local.php');
define('INSTALLER_BOOTSTRAP_CLAIM', INSTALLER_ROOT . '/data/install.bootstrap.claim');
define('INSTALLER_MIN_PHP', '8.1.0');

require_once INSTALLER_ROOT . '/src/brand.php';
require_once INSTALLER_ROOT . '/src/includes/CanonicalUrl.php';
require_once INSTALLER_ROOT . '/src/includes/InstallerSecurity.php';
require_once INSTALLER_ROOT . '/src/includes/InputLimits.php';

// Configuration is authoritative. Missing/restored lock state can never reopen an installed
// application, and a lock without configuration is an offline-recovery state.
installerAssertOpen();

$installerRemoteIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$installerSecret = trim((string) (getenv('FILEHOST_INSTALL_TOKEN') ?: ''));
$installerAllowlist = (string) (getenv('FILEHOST_INSTALL_ALLOW_IPS') ?: '');
if (!filehostInstallerSecretIsStrong($installerSecret)
	|| !filehostInstallerIpIsAllowed($installerRemoteIp, $installerAllowlist)) {
	if (!filehostInstallerSecretIsStrong($installerSecret)) {
		error_log(PRODUCT_NAME . ' installer disabled: set a non-placeholder FILEHOST_INSTALL_TOKEN of at least 32 characters.');
	}
	installerFailClosed();
}

$installerSecureCookie = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('FILEHOST_INSTALLER');
session_set_cookie_params([
	'lifetime' => 0,
	'path' => '/',
	'domain' => '',
	'secure' => $installerSecureCookie,
	'httponly' => true,
	'samesite' => 'Strict',
]);
session_start();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'; script-src-attr 'none'; img-src 'self' data:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
header('Referrer-Policy: no-referrer');

if (empty($_SESSION['installer_csrf'])) {
	$_SESSION['installer_csrf'] = bin2hex(random_bytes(32));
}

$installerAuthorized = installerSessionAuthorized($installerRemoteIp, $installerSecret);
if (file_exists(INSTALLER_BOOTSTRAP_CLAIM) && !$installerAuthorized) {
	installerFailClosed();
}

// Bootstrap authorization is POST-only, CSRF-bound, rate-limited and consumes the deployment's
// one-time claim. The environment secret is never placed in a URL, cookie or HTML response.
if (!isset($_GET['api'])
	&& $_SERVER['REQUEST_METHOD'] === 'POST'
	&& array_key_exists('bootstrap_token', $_POST)) {
	handleBootstrapUnlock($installerRemoteIp, $installerSecret);
}

/* ============================================================
 *  JSON API
 * ============================================================ */

if (isset($_GET['api'])) {
	header('Content-Type: application/json; charset=utf-8');

	$api = (string) $_GET['api'];

	installerAssertOpen();
	if (!$installerAuthorized) {
		installerFailClosed();
	}
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		header('Allow: POST');
		apiOut(false, ['error' => 'Method not allowed.'], 405);
	}
	$token = (string) ($_SERVER['HTTP_X_INSTALLER_CSRF'] ?? '');
	if (!hash_equals((string) $_SESSION['installer_csrf'], $token)) {
		apiOut(false, ['error' => 'Invalid security token. Refresh the page.'], 403);
	}

	try {
		switch ($api) {
			case 'requirements':
				apiOut(true, ['checks' => runRequirementChecks()]);
				// no break — apiOut exits
			case 'python_health':
				apiOut(true, ['python' => pythonHealth()]);
			case 'test_db':
				handleTestDb();
			case 'db_prepare':
				handleDbPrepare();
			case 'create_tables':
				handleCreateTables();
			case 'default_settings':
				handleDefaultSettings();
			case 'create_admin':
				handleCreateAdmin();
			case 'write_config':
				handleWriteConfig();
			case 'finalize':
				handleFinalize();
			default:
				apiOut(false, ['error' => 'Unknown action.'], 400);
		}
	} catch (Throwable $e) {
		apiOut(false, ['error' => 'Server error: ' . $e->getMessage()], 500);
	}
}

/* ============================================================
 *  API helpers
 * ============================================================ */

function installerFailClosed(): never
{
	http_response_code(404);
	header('Cache-Control: no-store');
	header('X-Content-Type-Options: nosniff');
	header('X-Frame-Options: DENY');
	header('Referrer-Policy: no-referrer');
	if (isset($_GET['api'])) {
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(['success' => false, 'error' => 'Not found'], JSON_UNESCAPED_UNICODE);
	} else {
		header('Content-Type: text/plain; charset=utf-8');
		echo 'Not found';
	}
	exit;
}

function installerAssertOpen(): void
{
	$state = filehostInstallerState(file_exists(INSTALLER_CONFIG), file_exists(INSTALLER_LOCK));
	if ($state !== 'open') {
		installerFailClosed();
	}
}

function installerSessionAuthorized(string $remoteIp, string $secret): bool
{
	$auth = $_SESSION['installer_authorization'] ?? null;
	if (!is_array($auth)
		|| !isset($auth['ip'], $auth['secret_fingerprint'], $auth['claim'], $auth['authorized_at'])
		|| (int) $auth['authorized_at'] < time() - 3600
		|| (int) $auth['authorized_at'] > time() + 60
		|| !hash_equals((string) $auth['ip'], $remoteIp)
		|| !hash_equals((string) $auth['secret_fingerprint'], hash('sha256', $secret))) {
		return false;
	}

	$claim = @file_get_contents(INSTALLER_BOOTSTRAP_CLAIM);
	return is_string($claim) && hash_equals((string) $auth['claim'], trim($claim));
}

function installerBootstrapRatePath(string $remoteIp): string
{
	return rtrim(sys_get_temp_dir(), '/\\')
		. DIRECTORY_SEPARATOR . 'filehost-installer-' . hash('sha256', $remoteIp) . '.json';
}

function installerBootstrapAttemptAllowed(string $remoteIp): bool
{
	$handle = @fopen(installerBootstrapRatePath($remoteIp), 'c+b');
	if ($handle === false || !flock($handle, LOCK_EX)) {
		if (is_resource($handle)) {
			fclose($handle);
		}
		return false;
	}

	$raw = stream_get_contents($handle);
	$data = json_decode(is_string($raw) ? $raw : '', true);
	$now = time();
	$windowStarted = is_array($data) ? (int) ($data['window'] ?? 0) : 0;
	$attempts = is_array($data) ? (int) ($data['attempts'] ?? 0) : 0;
	if ($windowStarted <= 0 || $windowStarted > $now || $now - $windowStarted >= 900) {
		$windowStarted = $now;
		$attempts = 0;
	}

	$allowed = $attempts < 5;
	if ($allowed) {
		$attempts++;
	}
	rewind($handle);
	$encoded = json_encode(['window' => $windowStarted, 'attempts' => $attempts]);
	$persisted = ftruncate($handle, 0)
		&& is_string($encoded)
		&& fwrite($handle, $encoded) === strlen($encoded)
		&& fflush($handle);
	flock($handle, LOCK_UN);
	fclose($handle);

	return $allowed && $persisted;
}

function installerClearBootstrapAttempts(string $remoteIp): void
{
	$handle = @fopen(installerBootstrapRatePath($remoteIp), 'c+b');
	if ($handle === false || !flock($handle, LOCK_EX)) {
		if (is_resource($handle)) {
			fclose($handle);
		}
		return;
	}
	rewind($handle);
	ftruncate($handle, 0);
	fwrite($handle, json_encode(['window' => time(), 'attempts' => 0]));
	fflush($handle);
	flock($handle, LOCK_UN);
	fclose($handle);
}

function installerWriteStream($handle, string $content): bool
{
	$length = strlen($content);
	$written = 0;
	while ($written < $length) {
		$count = fwrite($handle, substr($content, $written));
		if ($count === false || $count === 0) {
			return false;
		}
		$written += $count;
	}
	if (!fflush($handle)) {
		return false;
	}
	if (function_exists('fsync') && !fsync($handle)) {
		return false;
	}
	return true;
}

function installerStagedConfigPath(): ?string
{
	$path = $_SESSION['installer_staged_config'] ?? null;
	if (!is_string($path) || $path === '') {
		return null;
	}

	$configDir = realpath(dirname(INSTALLER_CONFIG));
	$stageDir = realpath(dirname($path));
	if ($configDir === false || $stageDir === false) {
		return null;
	}
	$dirsMatch = DIRECTORY_SEPARATOR === '\\'
		? strcasecmp($configDir, $stageDir) === 0
		: hash_equals($configDir, $stageDir);
	if (!$dirsMatch || is_link($path)
		|| !preg_match('/^\.config\.local\.[a-f0-9]{32}\.staged$/', basename($path))) {
		return null;
	}

	return $path;
}

function installerDiscardStagedConfig(): void
{
	$path = installerStagedConfigPath();
	if ($path !== null && is_file($path)) {
		@unlink($path);
	}
	unset($_SESSION['installer_staged_config'], $_SESSION['installer_staged_config_sha256']);
}

function installerBootstrapRedirect(string $message): never
{
	$_SESSION['installer_bootstrap_error'] = $message;
	header('Location: install.php', true, 303);
	exit;
}

function handleBootstrapUnlock(string $remoteIp, string $secret): never
{
	installerAssertOpen();
	$csrf = (string) ($_POST['installer_bootstrap_csrf'] ?? '');
	if (!hash_equals((string) $_SESSION['installer_csrf'], $csrf)) {
		installerBootstrapRedirect('The session expired. Refresh the page and try again.');
	}
	if (!installerBootstrapAttemptAllowed($remoteIp)) {
		installerBootstrapRedirect('Too many attempts. Try again in 15 minutes.');
	}

	$provided = (string) ($_POST['bootstrap_token'] ?? '');
	if (!hash_equals(hash('sha256', $secret), hash('sha256', $provided))) {
		installerBootstrapRedirect('Invalid installer secret.');
	}

	$dataDir = dirname(INSTALLER_BOOTSTRAP_CLAIM);
	if (!is_dir($dataDir) && !@mkdir($dataDir, 0755, true) && !is_dir($dataDir)) {
		installerBootstrapRedirect('The secure installer state could not be prepared.');
	}

	$claimHandle = @fopen(INSTALLER_BOOTSTRAP_CLAIM, 'x+b');
	if ($claimHandle === false) {
		installerBootstrapRedirect(
			'Another session already claimed the installer. Further recovery must be performed offline.'
		);
	}
	$claim = bin2hex(random_bytes(32));
	if (!installerWriteStream($claimHandle, $claim . "\n")) {
		fclose($claimHandle);
		installerBootstrapRedirect(
			'The installer state could not be persisted. Further recovery must be performed offline.'
		);
	}
	fclose($claimHandle);
	@chmod(INSTALLER_BOOTSTRAP_CLAIM, 0600);

	session_regenerate_id(true);
	$_SESSION['installer_authorization'] = [
		'ip' => $remoteIp,
		'secret_fingerprint' => hash('sha256', $secret),
		'claim' => $claim,
		'authorized_at' => time(),
	];
	$_SESSION['installer_csrf'] = bin2hex(random_bytes(32));
	unset($_SESSION['installer_bootstrap_error']);
	installerClearBootstrapAttempts($remoteIp);

	header('Location: install.php', true, 303);
	exit;
}

function apiOut(bool $success, array $data = [], int $code = 200): never
{
	http_response_code($code);
	echo json_encode(['success' => $success] + $data, JSON_UNESCAPED_UNICODE);
	exit;
}

function jsonInput(): array
{
	$raw = file_get_contents('php://input');
	$data = json_decode($raw ?: '[]', true);
	return is_array($data) ? $data : [];
}

/** Return the validated database configuration saved after a successful connection test. */
function sessionDbConfig(): array
{
	$cfg = $_SESSION['installer_db'] ?? null;
	if (!is_array($cfg) || empty($cfg['name'])) {
		apiOut(false, ['error' => 'Database configuration is missing. Test the connection first.'], 400);
	}
	return $cfg;
}

function validateDbInput(array $in): array
{
	$host = trim((string) ($in['host'] ?? 'localhost'));
	$user = trim((string) ($in['user'] ?? ''));
	$pass = (string) ($in['pass'] ?? '');
	$name = trim((string) ($in['name'] ?? ''));
	$prefix = trim((string) ($in['prefix'] ?? 'fh_'));

	if ($host === '' || $user === '' || $name === '') {
		apiOut(false, ['error' => 'Host, user and database name are required.'], 400);
	}
	if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
		apiOut(false, ['error' => 'The database name may contain only letters, numbers and underscores.'], 400);
	}
	if ($prefix !== '' && !preg_match('/^[a-z0-9_]{1,16}$/', $prefix)) {
		apiOut(false, ['error' => 'The prefix may contain only lowercase letters, numbers and "_" (maximum 16 characters).'], 400);
	}

	// Optional storage location. Empty = default <projekt>/uploads. The lock flag makes the
	// path editable only by hand in config.local.php (the panel then shows it read-only).
	$uploadsPath = rtrim(str_replace('\\', '/', trim((string) ($in['uploads_path'] ?? ''))), '/');
	$lockUploadsPath = !empty($in['lock_uploads_path']);
	if ($uploadsPath !== '') {
		if (!is_dir($uploadsPath) && !@mkdir($uploadsPath, 0755, true) && !is_dir($uploadsPath)) {
			apiOut(false, ['error' => 'Cannot create the uploads directory: ' . $uploadsPath], 400);
		}
		if (!is_writable($uploadsPath)) {
			apiOut(false, ['error' => 'The uploads directory is not writable: ' . $uploadsPath], 400);
		}
	}

	return compact('host', 'user', 'pass', 'name', 'prefix', 'uploadsPath', 'lockUploadsPath');
}

function installerPdo(array $cfg, bool $withDb = true): PDO
{
	$dsn = 'mysql:host=' . $cfg['host'] . ($withDb ? ';dbname=' . $cfg['name'] : '') . ';charset=utf8mb4';
	return new PDO($dsn, $cfg['user'], $cfg['pass'], [
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		PDO::ATTR_EMULATE_PREPARES => false,
		PDO::ATTR_TIMEOUT => 5,
	]);
}

function friendlyDbError(PDOException $e): string
{
	$msg = $e->getMessage();
	if (str_contains($msg, 'Access denied')) {
		return 'Access denied — check the user name and password.';
	}
	if (str_contains($msg, 'Unknown database')) {
		return 'The database does not exist and could not be created.';
	}
	if (str_contains($msg, 'Connection refused') || str_contains($msg, 'No such file') || str_contains($msg, 'target machine actively refused')) {
		return 'Cannot connect to MySQL — is the database server running?';
	}
	if (str_contains($msg, 'getaddrinfo') || str_contains($msg, 'Name or service not known')) {
		return 'Unknown database host.';
	}
	return 'Database error: ' . $msg;
}

/* ============================================================
 *  Checks
 * ============================================================ */

function runRequirementChecks(): array
{
	$checks = [];

	$checks[] = [
		'id' => 'os',
		'label' => 'Operating system',
		'status' => 'ok',
		'detail' => PHP_OS_FAMILY . ' (' . php_uname('r') . ')',
	];

	$phpOk = version_compare(PHP_VERSION, INSTALLER_MIN_PHP, '>=');
	$checks[] = [
		'id' => 'php',
		'label' => 'PHP ' . INSTALLER_MIN_PHP . '+',
		'status' => $phpOk ? 'ok' : 'fail',
		'detail' => 'Detected PHP ' . PHP_VERSION,
	];

	foreach (['pdo_mysql' => true, 'curl' => true, 'mbstring' => true, 'openssl' => true, 'fileinfo' => false] as $ext => $required) {
		$loaded = extension_loaded($ext);
		$checks[] = [
			'id' => 'ext_' . $ext,
			'label' => 'PHP extension: ' . $ext,
			'status' => $loaded ? 'ok' : ($required ? 'fail' : 'warn'),
			'detail' => $loaded ? 'Loaded' : ($required ? 'Missing — required' : 'Missing — recommended'),
		];
	}

	foreach ([['dir' => INSTALLER_ROOT . '/config', 'label' => 'config/ directory (stores config.local.php)'],
			  ['dir' => INSTALLER_ROOT . '/uploads', 'label' => 'uploads/ directory'],
			  ['dir' => INSTALLER_ROOT . '/data', 'label' => 'data/ directory']] as $item) {
		if (!is_dir($item['dir'])) {
			@mkdir($item['dir'], 0755, true);
		}
		$writable = is_dir($item['dir']) && is_writable($item['dir']);
		$checks[] = [
			'id' => 'dir_' . basename($item['dir']),
			'label' => $item['label'],
			'status' => $writable ? 'ok' : 'fail',
			'detail' => $writable ? 'Writable' : 'Not writable',
		];
	}

	$checks[] = [
		'id' => 'mail',
		'label' => 'mail() function (activation e-mails)',
		'status' => function_exists('mail') ? 'ok' : 'warn',
		'detail' => function_exists('mail') ? 'Available' : 'Unavailable — e-mail cannot be sent',
	];

	$hasExistingConfig = file_exists(INSTALLER_CONFIG);
	$checks[] = [
		'id' => 'config',
		'label' => 'Existing configuration',
		'status' => $hasExistingConfig ? 'fail' : 'ok',
		'detail' => $hasExistingConfig
			? 'config.local.php exists — the installer never overwrites it'
			: 'Clean installation',
	];

	return $checks;
}

function pythonHealth(): array
{
	$result = ['running' => false, 'detail' => 'The upload server on port 8001 is not responding'];

	if (!function_exists('curl_init')) {
		return $result;
	}

	$ch = curl_init('http://127.0.0.1:8001/health');
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => 2,
		CURLOPT_CONNECTTIMEOUT => 2,
	]);
	$response = curl_exec($ch);
	$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);

	if ($httpCode === 200 && $response) {
		$json = json_decode($response, true);
		$result = [
			'running' => true,
			'detail' => 'Running (v' . ($json['version'] ?? '?') . ')',
			'db_connected' => (bool) ($json['database_connected'] ?? false),
		];
	}

	return $result;
}

/* ============================================================
 *  Install steps
 * ============================================================ */

function handleTestDb(): never
{
	$cfg = validateDbInput(jsonInput());

	try {
		$pdo = installerPdo($cfg, false);

		$stmt = $pdo->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?');
		$stmt->execute([$cfg['name']]);
		$dbExists = (bool) $stmt->fetch();

		$tablesExist = false;
		if ($dbExists) {
			$stmt = $pdo->prepare('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
			$stmt->execute([$cfg['name'], $cfg['prefix'] . 'files']);
			$tablesExist = (bool) $stmt->fetch();
		}

		$_SESSION['installer_db'] = $cfg;

		apiOut(true, [
			'message' => 'Connection successful!',
			'db_exists' => $dbExists,
			'tables_exist' => $tablesExist,
		]);
	} catch (PDOException $e) {
		apiOut(false, ['error' => friendlyDbError($e)], 400);
	}
}

function handleDbPrepare(): never
{
	$cfg = sessionDbConfig();

	try {
		$pdo = installerPdo($cfg, false);
		$pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $cfg['name']) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
		apiOut(true, ['message' => 'Database ready']);
	} catch (PDOException $e) {
		apiOut(false, ['error' => friendlyDbError($e)], 400);
	}
}

function handleCreateTables(): never
{
	$cfg = sessionDbConfig();
	require_once INSTALLER_ROOT . '/src/includes/Database.php';

	if (!Database::connectWith($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['name'])) {
		apiOut(false, ['error' => 'Cannot connect to the database.'], 500);
	}

	$result = Database::createTables($cfg['prefix']);
	if (!$result['success']) {
		apiOut(false, ['error' => $result['error'] ?? 'The database tables could not be created.'], 500);
	}

	// createTables() establishes the compatible legacy baseline. Every fresh deployment must
	// then traverse the same audited migration chain as an upgrade; otherwise modern workers
	// see schema_version=0 and correctly refuse to start.
	Database::invalidateSettingsCache();
	Database::migrate();
	Database::invalidateSettingsCache();
	$schemaVersion = (int) Database::getSetting('schema_version', 0);
	$schemaReady = (string) Database::getSetting('schema_ready', '0');
	if ($schemaVersion !== Database::CURRENT_SCHEMA_VERSION || $schemaReady !== '1') {
		apiOut(false, ['error' => 'The database schema could not be migrated to the current version.'], 500);
	}

	apiOut(true, ['message' => 'Database schema created and migrated']);
}

function handleDefaultSettings(): never
{
	$cfg = sessionDbConfig();
	$in = jsonInput();
	try {
		$canonicalUrl = filehostNormalizeCanonicalUrl((string) ($in['canonical_url'] ?? ''));
	} catch (InvalidArgumentException) {
		apiOut(false, ['error' => 'Enter a valid canonical HTTP(S) URL without query parameters or a fragment.'], 400);
	}
	$_SESSION['installer_canonical_url'] = $canonicalUrl;
	$canonicalHost = trim((string) parse_url($canonicalUrl, PHP_URL_HOST), '[]');
	$emailHost = filter_var($canonicalHost, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
		? $canonicalHost
		: 'localhost';

	require_once INSTALLER_ROOT . '/src/includes/Database.php';
	if (!Database::connectWith($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['name'])) {
		apiOut(false, ['error' => 'Cannot connect to the database.'], 500);
	}

	$appName = trim((string) ($in['app_name'] ?? PRODUCT_NAME)) ?: PRODUCT_NAME;

	$defaults = [
		'app_name' => mb_substr($appName, 0, 50),
		'maintenance_mode' => '0',
		'maintenance_message' => '', // empty = per-language default on the maintenance page (B8)
		'registration_enabled' => '1',
		'user_activation_mode' => 'auto',
		'email_verification_lifetime' => '24',
		'email_resend_cooldown' => '30',
		'guest_max_file_size_mb' => '250',
		'user_max_file_size_mb' => '5120',
		'guest_max_files' => '5',
		'user_max_files' => '15',
		'system_max_file_size_mb' => '5120',
		'max_upload_folder_mb' => '0',
		'auto_delete_days' => '0',
		'file_quarantine_days' => '0',
		'collection_counts_file_downloads' => '0',
		// Install-time seed only: migration v4 copies this into the default "User" group's
		// quota. There is no panel field for it any more — a group's quota is edited in
		// Settings → Groups.
		'default_storage_limit_mb' => '500',
		'blocked_extensions' => implode(',', filehostInstallerDefaultBlockedExtensions()),
		'concurrent_downloads_guest' => '1',
		'concurrent_downloads_user' => '0',
		'recaptcha_enabled' => '0',
		'recaptcha_site_key' => '',
		'recaptcha_secret_key' => '',
		'recaptcha_token_lifetime' => '120',
		'recaptcha_login_attempt_threshold' => '3',
		'recaptcha_delete_token_threshold' => '3',
		'recaptcha_file_password_threshold' => '3',
		'recaptcha_download_threshold' => '-1',
		'recaptcha_register_always' => '1',
		'recaptcha_report_threshold_count' => '5',
		'recaptcha_security_window' => '60',
		'collection_protected_file_policy' => 'prompt_skip',
		'recovery_attempts_limit' => '5',
		'recovery_window_hours' => '48',
		'email_method' => 'php',
		'email_from' => 'noreply@' . $emailHost,
		'email_from_name' => $appName,
	];

	if (!Database::insertDefaultSettings($defaults, $cfg['prefix'])) {
		apiOut(false, ['error' => 'The default settings could not be saved.'], 500);
	}

	apiOut(true, ['message' => 'Default settings saved']);
}

function handleCreateAdmin(): never
{
	$cfg = sessionDbConfig();
	$in = jsonInput();

	$username = trim((string) ($in['username'] ?? ''));
	$email = trim((string) ($in['email'] ?? ''));
	$password = (string) ($in['password'] ?? '');

	if (!InputLimits::validUsername($username)) {
		apiOut(false, ['error' => 'The administrator user name must contain 3–32 letters, digits, dots, underscores or hyphens.'], 400);
	}
	if (!InputLimits::validEmail($email)) {
		apiOut(false, ['error' => 'Invalid e-mail address.'], 400);
	}
	if (strlen($password) < InputLimits::ACCOUNT_PASSWORD_MIN || strlen($password) > InputLimits::ACCOUNT_PASSWORD_MAX
		|| !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)
		|| !preg_match('/[^a-zA-Z0-9]/', $password)) {
		apiOut(false, ['error' => 'Password: at least 8 characters, an uppercase letter, a number and a special character.'], 400);
	}

	require_once INSTALLER_ROOT . '/src/includes/Database.php';
	if (!Database::connectWith($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['name'])) {
		apiOut(false, ['error' => 'Cannot connect to the database.'], 500);
	}

	try {
		$result = Database::createAdmin($username, $password, $email, $cfg['prefix']);
	} catch (AccountAlreadyExistsException) {
		// Idempotent retry after the browser or process was interrupted after INSERT.
		apiOut(true, ['message' => 'The account already exists — skipped', 'skipped' => true]);
	}

	if (!$result['success']) {
		apiOut(false, ['error' => $result['error'] ?? 'The administrator account could not be created.'], 500);
	}

	apiOut(true, ['message' => 'Administrator account created']);
}

function handleWriteConfig(): never
{
	installerAssertOpen();
	$cfg = sessionDbConfig();
	$canonicalUrl = (string) ($_SESSION['installer_canonical_url'] ?? '');
	try {
		$canonicalUrl = filehostNormalizeCanonicalUrl($canonicalUrl);
	} catch (InvalidArgumentException) {
		apiOut(false, ['error' => 'Save a valid canonical application URL first.'], 400);
	}

	$esc = static fn(string $v): string => str_replace(['\\', "'"], ['\\\\', "\\'"], $v);

	$content = "<?php\n"
		. "/**\n"
		. ' * ' . PRODUCT_NAME . " local configuration\n"
		. " * Wygenerowano: " . date('Y-m-d H:i:s') . "\n"
		. " */\n\n"
		. "define('DB_HOST', '{$esc($cfg['host'])}');\n"
		. "define('DB_USER', '{$esc($cfg['user'])}');\n"
		. "define('DB_PASS', '{$esc($cfg['pass'])}');\n"
		. "define('DB_NAME', '{$esc($cfg['name'])}');\n"
		. "define('DB_PREFIX', '{$esc($cfg['prefix'])}');\n"
		. "define('APP_CANONICAL_URL', '{$esc($canonicalUrl)}');\n\n"
		. "// Secret key for encrypting secrets stored in the DB (e.g. the SMTP password).\n"
		. "// Kept here — outside the web root and out of any DB dump — so a database leak\n"
		. "// alone cannot decrypt them (S12). Keep it stable; changing it orphans secrets.\n"
		. "define('APP_SECRET_KEY', '" . bin2hex(random_bytes(32)) . "');\n\n"
		. "// ---------------------------------------------------------------------------\n"
		. "// Storage location. Absolute path = used as-is, relative = from the project root.\n"
		// Docs live next to the project so a fresh install can find them without the web UI.
		. "// Changing it only repoints the app — move the existing uploads/ contents yourself\n"
		. "// and make sure the web user and the Python upload server can write there.\n"
		. "// Details: docs/STORAGE.md\n";

	if (($cfg['uploadsPath'] ?? '') !== '') {
		$content .= "define('UPLOADS_PATH', '{$esc($cfg['uploadsPath'])}');\n";
	} else {
		$content .= "// define('UPLOADS_PATH', 'D:/filehost-uploads');   // Windows\n"
			. "// define('UPLOADS_PATH', '/mnt/storage/uploads');  // Linux\n";
	}
	$content .= "// define('DATA_PATH', '/mnt/storage/data');        // thumbnails + caches (optional)\n";

	if (!empty($cfg['lockUploadsPath'])) {
		$content .= "\n// Locked during installation: the admin panel shows the upload path read-only.\n"
			. "// Remove (or set to false) this line to allow changing it from the panel again.\n"
			. "define('UPLOADS_PATH_LOCKED', true);\n";
	}

	$configDir = dirname(INSTALLER_CONFIG);
	if (!is_dir($configDir) && !@mkdir($configDir, 0755, true) && !is_dir($configDir)) {
		apiOut(false, ['error' => 'The config/ directory could not be prepared.'], 500);
	}

	installerDiscardStagedConfig();
	$stagePath = $configDir . DIRECTORY_SEPARATOR . '.config.local.'
		. bin2hex(random_bytes(16)) . '.staged';
	$stageHandle = @fopen($stagePath, 'x+b');
	if ($stageHandle === false) {
		apiOut(false, ['error' => 'The secure configuration file could not be prepared.'], 500);
	}
	@chmod($stagePath, 0600);
	if (!installerWriteStream($stageHandle, $content)) {
		fclose($stageHandle);
		@unlink($stagePath);
		apiOut(false, ['error' => 'The staged configuration could not be persisted.'], 500);
	}
	fclose($stageHandle);
	$_SESSION['installer_staged_config'] = $stagePath;
	$_SESSION['installer_staged_config_sha256'] = hash('sha256', $content);

	apiOut(true, ['message' => 'Configuration staged for atomic publication']);
}

function handleFinalize(): never
{
	installerAssertOpen();
	$stagePath = installerStagedConfigPath();
	$expectedStageHash = (string) ($_SESSION['installer_staged_config_sha256'] ?? '');
	if ($stagePath === null || !is_file($stagePath) || !is_readable($stagePath)
		|| !preg_match('/^[a-f0-9]{64}$/', $expectedStageHash)
		|| !hash_equals($expectedStageHash, (string) @hash_file('sha256', $stagePath))) {
		apiOut(false, ['error' => 'The staged configuration is incomplete. Repeat the previous step.'], 409);
	}

	$lockDir = dirname(INSTALLER_LOCK);
	if (!is_dir($lockDir) && !@mkdir($lockDir, 0755, true) && !is_dir($lockDir)) {
		apiOut(false, ['error' => 'The installer state directory could not be prepared.'], 500);
	}

	// Publish the fail-closed state first. A crash from this point onward leaves the web
	// installer inaccessible and requires deliberate offline recovery; it never reopens it.
	$lockHandle = @fopen(INSTALLER_LOCK, 'x+b');
	if ($lockHandle === false) {
		installerFailClosed();
	}
	$note = PRODUCT_NAME . ' installed: ' . date(DATE_ATOM) . "\n"
		. "Fail-closed state. Reinstallation and recovery require offline maintenance.\n";
	if (!installerWriteStream($lockHandle, $note)) {
		fclose($lockHandle);
		apiOut(false, [
			'error' => 'The installation lock could not be persisted. The installer remains closed; offline recovery is required.',
		], 500);
	}
	fclose($lockHandle);
	@chmod(INSTALLER_LOCK, 0600);

	// `link` publishes the already flushed file atomically and, unlike rename(), cannot replace
	// an existing config.local.php. Both files are in config/, so they are on the same volume.
	if (file_exists(INSTALLER_CONFIG) || !@link($stagePath, INSTALLER_CONFIG)) {
		apiOut(false, [
			'error' => 'The configuration could not be published atomically. The installer remains closed; offline recovery is required.',
		], 500);
	}
	@chmod(INSTALLER_CONFIG, 0600);
	@unlink($stagePath);
	unset($_SESSION['installer_staged_config'], $_SESSION['installer_staged_config_sha256']);

	// Powiadom serwer Pythona o nowej konfiguracji (best effort)
	$pythonReloaded = false;
	if (function_exists('curl_init')) {
		$ch = curl_init('http://127.0.0.1:8001/reload');
		curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 2]);
		$response = curl_exec($ch);
		$pythonReloaded = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200 && $response !== false;
		curl_close($ch);
	}

	unset(
		$_SESSION['installer_db'],
		$_SESSION['installer_canonical_url'],
		$_SESSION['installer_authorization']
	);

	apiOut(true, ['message' => 'Installation complete', 'python_reloaded' => $pythonReloaded]);
}

/* ============================================================
 *  UI
 * ============================================================ */

$csrf = htmlspecialchars((string) $_SESSION['installer_csrf'], ENT_QUOTES);
$bootstrapError = (string) ($_SESSION['installer_bootstrap_error'] ?? '');
unset($_SESSION['installer_bootstrap_error']);
$canonicalDefault = '';
$canonicalEnv = (string) (getenv('FILEHOST_CANONICAL_URL') ?: '');
if ($canonicalEnv !== '') {
	try {
		$canonicalDefault = filehostNormalizeCanonicalUrl($canonicalEnv);
	} catch (InvalidArgumentException) {
		// The form remains empty so the operator must provide a valid explicit value.
	}
}
$canonicalDefaultEscaped = htmlspecialchars($canonicalDefault, ENT_QUOTES);
$osFamily = PHP_OS_FAMILY;
?>
<!DOCTYPE html>
<html lang="en">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="robots" content="noindex, nofollow">
	<?php if ($installerAuthorized): ?>
		<meta name="filehost-installer-csrf" content="<?= $csrf ?>">
	<?php endif; ?>
	<title><?= htmlspecialchars(PRODUCT_NAME) ?> Installer</title>
	<style>
		:root {
			--bg: #0b0c10;
			--surface: rgba(23, 25, 33, .72);
			--surface-solid: #15171f;
			--border: rgba(255, 255, 255, .08);
			--text: #eef0f6;
			--muted: #9aa1b5;
			--accent: #6366f1;
			--accent-2: #8b5cf6;
			--ok: #34d399;
			--warn: #fbbf24;
			--fail: #f87171;
			--radius: 18px;
		}

		* { margin: 0; padding: 0; box-sizing: border-box; }

		body {
			font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
			background: var(--bg);
			color: var(--text);
			min-height: 100vh;
			display: flex;
			align-items: center;
			justify-content: center;
			padding: 24px;
			overflow-x: hidden;
		}

		/* --- animated background --- */
		.bg { position: fixed; inset: 0; z-index: -1; overflow: hidden; }

		.orb {
			position: absolute;
			border-radius: 50%;
			filter: blur(110px);
			opacity: .45;
			animation: float 18s ease-in-out infinite;
		}

		.orb-1 { width: 480px; height: 480px; background: #4f46e5; top: -140px; left: -100px; }
		.orb-2 { width: 420px; height: 420px; background: #7c3aed; bottom: -120px; right: -80px; animation-delay: -9s; }
		.orb-3 { width: 260px; height: 260px; background: #0ea5e9; top: 40%; left: 60%; animation-delay: -4s; opacity: .25; }

		@keyframes float {
			0%, 100% { transform: translate(0, 0) scale(1); }
			50% { transform: translate(40px, -30px) scale(1.08); }
		}

		.bg-grid {
			position: absolute; inset: 0;
			background-image: linear-gradient(rgba(255,255,255,.025) 1px, transparent 1px),
				linear-gradient(90deg, rgba(255,255,255,.025) 1px, transparent 1px);
			background-size: 44px 44px;
			mask-image: radial-gradient(ellipse at center, black 30%, transparent 75%);
		}

		/* --- card --- */
		.wrap { width: 100%; max-width: 660px; }

		.brand {
			display: flex; align-items: center; gap: 12px;
			justify-content: center; margin-bottom: 28px;
			animation: fadeDown .6s ease both;
		}

		.brand-icon {
			width: 44px; height: 44px; border-radius: 12px;
			background: linear-gradient(135deg, var(--accent), var(--accent-2));
			display: flex; align-items: center; justify-content: center;
			box-shadow: 0 8px 24px rgba(99, 102, 241, .35);
		}

		.brand-icon svg { width: 22px; height: 22px; stroke: #fff; }
		.brand h1 { font-size: 22px; font-weight: 700; letter-spacing: -.02em; }
		.brand h1 span { color: var(--muted); font-weight: 500; }

		.card {
			background: var(--surface);
			backdrop-filter: blur(22px);
			-webkit-backdrop-filter: blur(22px);
			border: 1px solid var(--border);
			border-radius: var(--radius);
			box-shadow: 0 24px 70px rgba(0, 0, 0, .45);
			overflow: hidden;
			animation: fadeUp .6s .1s ease both;
		}

		@keyframes fadeUp { from { opacity: 0; transform: translateY(18px); } to { opacity: 1; transform: none; } }
		@keyframes fadeDown { from { opacity: 0; transform: translateY(-14px); } to { opacity: 1; transform: none; } }

		/* --- stepper --- */
		.stepper { display: flex; padding: 22px 28px 0; gap: 8px; }

		.step-dot { flex: 1; text-align: center; position: relative; }

		.step-dot .bar {
			height: 4px; border-radius: 2px;
			background: rgba(255, 255, 255, .08);
			overflow: hidden; position: relative;
		}

		.step-dot .bar::after {
			content: ''; position: absolute; inset: 0;
			background: linear-gradient(90deg, var(--accent), var(--accent-2));
			transform: scaleX(0); transform-origin: left;
			transition: transform .5s cubic-bezier(.65, 0, .35, 1);
		}

		.step-dot.active .bar::after, .step-dot.done .bar::after { transform: scaleX(1); }

		.step-dot .lbl {
			display: block; margin-top: 8px; font-size: 11.5px;
			color: var(--muted); font-weight: 500; transition: color .3s;
		}

		.step-dot.active .lbl { color: var(--text); }

		/* --- panels --- */
		.panels { position: relative; }

		.panel {
			padding: 26px 28px 28px;
			display: none;
			animation: panelIn .45s cubic-bezier(.2, .7, .3, 1) both;
		}

		.panel.show { display: block; }

		@keyframes panelIn { from { opacity: 0; transform: translateX(26px); } to { opacity: 1; transform: none; } }

		.panel h2 { font-size: 18px; font-weight: 650; margin-bottom: 4px; letter-spacing: -.01em; }
		.panel .sub { color: var(--muted); font-size: 13.5px; margin-bottom: 20px; line-height: 1.5; }

		/* --- check list --- */
		.checks { display: flex; flex-direction: column; gap: 8px; min-height: 120px; }

		.check {
			display: flex; align-items: center; gap: 12px;
			background: rgba(255, 255, 255, .03);
			border: 1px solid var(--border);
			border-radius: 12px;
			padding: 11px 14px;
			opacity: 0; transform: translateY(8px);
			animation: checkIn .4s ease forwards;
		}

		@keyframes checkIn { to { opacity: 1; transform: none; } }

		.check .ico {
			width: 26px; height: 26px; flex-shrink: 0;
			display: flex; align-items: center; justify-content: center;
			border-radius: 50%;
			font-size: 13px;
		}

		.check .ico svg { width: 14px; height: 14px; }

		.check.ok .ico { background: rgba(52, 211, 153, .12); color: var(--ok); }
		.check.warn .ico { background: rgba(251, 191, 36, .12); color: var(--warn); }
		.check.fail .ico { background: rgba(248, 113, 113, .12); color: var(--fail); }
		.check.pending .ico { background: rgba(255, 255, 255, .06); }

		.check .txt { flex: 1; min-width: 0; }
		.check .txt b { display: block; font-size: 13.5px; font-weight: 550; }
		.check .txt small { color: var(--muted); font-size: 12px; }

		.checkmark { stroke: currentColor; stroke-width: 3; fill: none; stroke-linecap: round; stroke-linejoin: round;
			stroke-dasharray: 24; stroke-dashoffset: 24; animation: draw .45s .1s ease forwards; }

		@keyframes draw { to { stroke-dashoffset: 0; } }

		.spinner {
			width: 15px; height: 15px; border-radius: 50%;
			border: 2px solid rgba(255, 255, 255, .15);
			border-top-color: var(--accent);
			animation: spin .7s linear infinite;
		}

		@keyframes spin { to { transform: rotate(360deg); } }

		/* --- forms --- */
		.grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
		@media (max-width: 560px) { .grid2 { grid-template-columns: 1fr; } }

		.field { margin-bottom: 14px; }
		.field label { display: block; font-size: 12.5px; font-weight: 550; color: var(--muted); margin-bottom: 6px; }

		.field input {
			width: 100%;
			background: rgba(255, 255, 255, .04);
			border: 1px solid var(--border);
			border-radius: 10px;
			color: var(--text);
			font: inherit; font-size: 14px;
			padding: 10.5px 13px;
			outline: none;
			transition: border-color .2s, box-shadow .2s, background .2s;
		}

		.field input:focus {
			border-color: var(--accent);
			box-shadow: 0 0 0 3px rgba(99, 102, 241, .18);
			background: rgba(255, 255, 255, .06);
		}

		.field .hint { font-size: 11.5px; color: var(--muted); margin-top: 5px; }

		/* --- password strength --- */
		.strength { height: 4px; border-radius: 2px; background: rgba(255,255,255,.07); margin-top: 8px; overflow: hidden; }
		.strength i { display: block; height: 100%; width: 0; border-radius: 2px; transition: width .35s ease, background .35s; }

		.reqs { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
		.reqs span {
			font-size: 11px; padding: 3px 9px; border-radius: 20px;
			background: rgba(255,255,255,.05); color: var(--muted);
			border: 1px solid var(--border);
			transition: all .25s;
		}
		.reqs span.met { background: rgba(52, 211, 153, .12); color: var(--ok); border-color: rgba(52, 211, 153, .25); }

		/* --- alerts --- */
		.alert {
			display: none; align-items: flex-start; gap: 10px;
			border-radius: 12px; padding: 12px 14px;
			font-size: 13px; line-height: 1.45; margin-top: 14px;
			animation: checkIn .35s ease both;
		}

		.alert.show { display: flex; }
		.alert.ok { background: rgba(52, 211, 153, .1); color: var(--ok); border: 1px solid rgba(52, 211, 153, .25); }
		.alert.err { background: rgba(248, 113, 113, .1); color: var(--fail); border: 1px solid rgba(248, 113, 113, .25); }
		.alert.warn { background: rgba(251, 191, 36, .1); color: var(--warn); border: 1px solid rgba(251, 191, 36, .25); }

		/* --- buttons --- */
		.actions { display: flex; justify-content: space-between; gap: 12px; margin-top: 24px; }

		.btn {
			font: inherit; font-size: 14px; font-weight: 600;
			border: none; border-radius: 11px;
			padding: 11px 22px; cursor: pointer;
			display: inline-flex; align-items: center; gap: 8px;
			transition: transform .15s ease, box-shadow .2s, opacity .2s, background .2s;
			user-select: none;
		}

		.btn:disabled { opacity: .45; cursor: not-allowed; transform: none !important; }

		.btn-primary {
			background: linear-gradient(135deg, var(--accent), var(--accent-2));
			color: #fff;
			box-shadow: 0 6px 20px rgba(99, 102, 241, .35);
		}

		.btn-primary:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 10px 26px rgba(99, 102, 241, .45); }
		.btn-primary:active:not(:disabled) { transform: translateY(0); }

		.btn-ghost { background: rgba(255, 255, 255, .05); color: var(--text); border: 1px solid var(--border); }
		.btn-ghost:hover:not(:disabled) { background: rgba(255, 255, 255, .09); }

		.btn .spinner { display: none; }
		.btn.loading .spinner { display: inline-block; }
		.btn.loading .btn-txt { opacity: .7; }

		/* --- install tasks --- */
		.tasks { display: flex; flex-direction: column; gap: 6px; }

		.task {
			display: flex; align-items: center; gap: 12px;
			padding: 10px 14px;
			border-radius: 10px;
			background: rgba(255, 255, 255, .02);
			border: 1px solid transparent;
			font-size: 13.5px;
			color: var(--muted);
			transition: all .3s;
		}

		.task.running { border-color: rgba(99, 102, 241, .3); background: rgba(99, 102, 241, .06); color: var(--text); }
		.task.done { color: var(--text); }
		.task.done .status { color: var(--ok); }
		.task.error { border-color: rgba(248, 113, 113, .3); background: rgba(248, 113, 113, .06); color: var(--fail); }
		.task .status { margin-left: auto; font-size: 12px; display: flex; align-items: center; }

		.progress { height: 6px; border-radius: 3px; background: rgba(255,255,255,.07); margin: 18px 0 6px; overflow: hidden; }
		.progress i {
			display: block; height: 100%; width: 0; border-radius: 3px;
			background: linear-gradient(90deg, var(--accent), var(--accent-2));
			transition: width .5s cubic-bezier(.65, 0, .35, 1);
			box-shadow: 0 0 12px rgba(99, 102, 241, .6);
		}

		/* --- success --- */
		.success-wrap { text-align: center; padding: 8px 0 4px; }

		.success-circle {
			width: 76px; height: 76px; margin: 0 auto 18px;
			border-radius: 50%;
			background: rgba(52, 211, 153, .1);
			border: 2px solid rgba(52, 211, 153, .4);
			display: flex; align-items: center; justify-content: center;
			animation: pop .5s cubic-bezier(.3, 1.5, .5, 1) both;
		}

		@keyframes pop { from { transform: scale(.4); opacity: 0; } to { transform: scale(1); opacity: 1; } }

		.success-circle svg { width: 34px; height: 34px; color: var(--ok); }

		.success-wrap h2 { margin-bottom: 6px; }
		.success-wrap .sub { margin-bottom: 18px; }

		.summary {
			text-align: left;
			background: rgba(255, 255, 255, .03);
			border: 1px solid var(--border);
			border-radius: 12px;
			padding: 14px 16px;
			font-size: 13px;
			margin-bottom: 18px;
		}

		.summary div { display: flex; justify-content: space-between; padding: 5px 0; gap: 12px; }
		.summary div + div { border-top: 1px solid rgba(255, 255, 255, .05); }
		.summary span { color: var(--muted); }
		.summary b { font-weight: 550; text-align: right; word-break: break-all; }

		.hint-box {
			text-align: left; font-size: 12.5px; line-height: 1.6; color: var(--muted);
			background: rgba(251, 191, 36, .06);
			border: 1px solid rgba(251, 191, 36, .2);
			border-radius: 12px; padding: 12px 16px; margin-bottom: 18px;
		}

		.hint-box code {
			background: rgba(255, 255, 255, .07); border-radius: 5px;
			padding: 1.5px 6px; font-size: 11.5px; color: var(--text);
			font-family: ui-monospace, 'Cascadia Code', Consolas, monospace;
		}

		footer { text-align: center; color: var(--muted); font-size: 12px; margin-top: 22px; opacity: .7; }

		.locked { text-align: center; padding: 44px 28px; }
		.locked .ico { font-size: 40px; margin-bottom: 14px; }
		.locked h2 { margin-bottom: 8px; }
		.locked p { color: var(--muted); font-size: 14px; line-height: 1.6; }
		.locked code { background: rgba(255,255,255,.07); border-radius: 5px; padding: 2px 7px; font-size: 12.5px; }

		@media (prefers-reduced-motion: reduce) {
			*, *::before, *::after { animation-duration: .01ms !important; transition-duration: .01ms !important; }
		}
	</style>
</head>

<body>
	<div class="bg">
		<div class="orb orb-1"></div>
		<div class="orb orb-2"></div>
		<div class="orb orb-3"></div>
		<div class="bg-grid"></div>
	</div>

	<div class="wrap">
		<div class="brand">
			<div class="brand-icon">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
					<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
					<polyline points="17 8 12 3 7 8" />
					<line x1="12" y1="3" x2="12" y2="15" />
				</svg>
			</div>
			<h1><?= htmlspecialchars(PRODUCT_NAME) ?> <span>· Installer</span></h1>
		</div>

		<div class="card">
			<?php if (!$installerAuthorized): ?>
				<div class="locked">
					<div class="ico">🔐</div>
					<h2>Installer authorization</h2>
					<p>Enter the one-time bootstrap secret configured on the server as
						<code>FILEHOST_INSTALL_TOKEN</code>.</p>
					<?php if ($bootstrapError !== ''): ?>
						<div class="alert err show" style="margin-top:18px"><?= htmlspecialchars($bootstrapError) ?></div>
					<?php endif; ?>
					<form method="post" action="install.php" style="margin-top:22px;text-align:left">
						<input type="hidden" name="installer_bootstrap_csrf" value="<?= $csrf ?>">
						<div class="field">
							<label for="bootstrap_token">Installer secret</label>
							<input type="password" id="bootstrap_token" name="bootstrap_token" maxlength="255"
								required minlength="32" maxlength="1024" autocomplete="one-time-code">
						</div>
						<button type="submit" class="btn btn-primary" style="width:100%">
							Authorize installation
						</button>
					</form>
				</div>
			<?php else: ?>
				<div class="stepper" id="stepper">
					<div class="step-dot active" data-step="1"><div class="bar"></div><span class="lbl">Environment</span></div>
					<div class="step-dot" data-step="2"><div class="bar"></div><span class="lbl">Database</span></div>
					<div class="step-dot" data-step="3"><div class="bar"></div><span class="lbl">Administrator</span></div>
					<div class="step-dot" data-step="4"><div class="bar"></div><span class="lbl">Installation</span></div>
				</div>

				<div class="panels">
					<!-- STEP 1: environment -->
					<section class="panel show" id="panel-1">
						<h2>Environment checks</h2>
						<p class="sub">Checking PHP, required extensions, directory permissions and the Python upload server.</p>

						<div class="checks" id="checks"></div>
						<div class="alert err" id="env-alert"></div>

						<div class="actions">
							<button class="btn btn-ghost" id="recheckBtn">
								<span class="btn-txt">Check again</span>
							</button>
							<button class="btn btn-primary" id="toStep2" disabled>
								<span class="btn-txt">Next →</span>
							</button>
						</div>
					</section>

					<!-- STEP 2: database -->
					<section class="panel" id="panel-2">
						<h2>Database connection</h2>
						<p class="sub">Enter MySQL or MariaDB credentials. The database will be created when it does not exist.</p>

						<div class="grid2">
							<div class="field">
								<label for="db_host">Host</label>
								<input type="text" id="db_host" value="localhost" autocomplete="off">
							</div>
							<div class="field">
								<label for="db_name">Database name</label>
								<input type="text" id="db_name" value="filehost" autocomplete="off">
							</div>
							<div class="field">
								<label for="db_user">User</label>
								<input type="text" id="db_user" value="root" autocomplete="off">
							</div>
							<div class="field">
								<label for="db_pass">Password</label>
								<input type="password" id="db_pass" value="" maxlength="1024" autocomplete="new-password">
							</div>
						</div>
						<div class="field">
							<label for="db_prefix">Table prefix</label>
							<input type="text" id="db_prefix" value="fh_" autocomplete="off">
							<div class="hint">Allows multiple installations in one database. Use lowercase letters, numbers and "_".</div>
						</div>
						<div class="field">
							<label for="uploads_path">Uploads directory (optional)</label>
							<input type="text" id="uploads_path" value="" autocomplete="off" placeholder="default: uploads/ in the project directory">
							<div class="hint">Leave empty to use <code>uploads/</code>. You may use another volume, for example <code>D:/filehost-uploads</code> or <code>/mnt/storage/uploads</code>. The directory must be writable.</div>
						</div>
						<div class="field">
							<label for="lock_uploads_path" style="display:flex;align-items:center;gap:8px;cursor:pointer;">
								<input type="checkbox" id="lock_uploads_path" style="width:auto;">
								<span>Lock the uploads path in the administration panel</span>
							</label>
							<div class="hint">When locked, the path can be changed <strong>only</strong> by editing <code>config/config.local.php</code>.</div>
						</div>

						<div class="alert" id="db-alert"></div>

						<div class="actions">
							<button class="btn btn-ghost" data-back="1"><span class="btn-txt">← Back</span></button>
							<div style="display:flex;gap:10px">
								<button class="btn btn-ghost" id="testDbBtn">
									<span class="spinner"></span><span class="btn-txt">Test connection</span>
								</button>
								<button class="btn btn-primary" id="toStep3" disabled><span class="btn-txt">Next →</span></button>
							</div>
						</div>
					</section>

					<!-- STEP 3: admin -->
					<section class="panel" id="panel-3">
						<h2>Administrator account</h2>
						<p class="sub">The first account receives permanent full access to every management function.</p>

						<div class="field">
							<label for="app_name">Service name</label>
							<input type="text" id="app_name" value="<?= htmlspecialchars(PRODUCT_NAME) ?>" maxlength="50">
						</div>
						<div class="field">
							<label for="canonical_url">Canonical public URL</label>
							<input type="url" id="canonical_url" value="<?= $canonicalDefaultEscaped ?>"
								placeholder="https://files.example.com" required maxlength="2048"
								autocomplete="url">
							<div class="hint">The full public HTTP(S) URL, optionally including a port and base path.
								Every security-sensitive link uses only this address.</div>
						</div>
						<div class="grid2">
							<div class="field">
								<label for="adm_user">User name</label>
								<input type="text" id="adm_user" value="admin" minlength="3" maxlength="32"
									pattern="[A-Za-z0-9_.-]{3,32}" autocomplete="off">
							</div>
							<div class="field">
								<label for="adm_email">E-mail</label>
								<input type="email" id="adm_email" maxlength="254" placeholder="admin@example.com" autocomplete="off">
							</div>
						</div>
						<div class="field">
							<label for="adm_pass">Password</label>
							<input type="password" id="adm_pass" minlength="8" maxlength="72" autocomplete="new-password">
							<div class="strength"><i id="strengthBar"></i></div>
							<div class="reqs">
								<span data-req="len">8+ characters</span>
								<span data-req="upper">Uppercase letter</span>
								<span data-req="digit">Number</span>
								<span data-req="special">Special character</span>
							</div>
						</div>
						<div class="field">
							<label for="adm_pass2">Repeat password</label>
							<input type="password" id="adm_pass2" minlength="8" maxlength="72" autocomplete="new-password">
						</div>

						<div class="alert err" id="adm-alert"></div>

						<div class="actions">
							<button class="btn btn-ghost" data-back="2"><span class="btn-txt">← Back</span></button>
							<button class="btn btn-primary" id="toStep4" disabled><span class="btn-txt">Install ✦</span></button>
						</div>
					</section>

					<!-- STEP 4: install -->
					<section class="panel" id="panel-4">
						<div id="installing">
							<h2>Installing…</h2>
							<p class="sub">The application is being configured. Keep this tab open.</p>

							<div class="tasks" id="tasks"></div>
							<div class="progress"><i id="progressBar"></i></div>
							<div class="alert err" id="install-alert"></div>
							<div class="actions" id="retryActions" style="display:none">
								<button class="btn btn-ghost" data-back="2"><span class="btn-txt">← Edit details</span></button>
								<button class="btn btn-primary" id="retryBtn"><span class="btn-txt">Retry installation</span></button>
							</div>
						</div>

						<div class="success-wrap" id="success" style="display:none">
							<div class="success-circle">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
									<polyline points="20 6 9 17 4 12" class="checkmark" style="stroke-dasharray:30;stroke-dashoffset:30" />
								</svg>
							</div>
							<h2>Installation complete! 🎉</h2>
							<p class="sub"><?= htmlspecialchars(PRODUCT_NAME) ?> is ready.</p>

							<div class="summary" id="summaryBox"></div>

							<div class="hint-box" id="pythonHint" style="display:none">
								<b>⚠ The Python upload server is not running.</b> Start it before accepting uploads:<br>
								<?php if ($osFamily === 'Windows'): ?>
									<code>python -m venv venv</code> → <code>venv\Scripts\python -m pip install --require-hashes -r requirements-lock.txt</code> →
									<code>venv\Scripts\python upload_server.py</code>
								<?php else: ?>
									<code>python3 -m venv venv</code> → <code>venv/bin/python -m pip install --require-hashes -r requirements-lock.txt</code> →
									<code>venv/bin/python upload_server.py</code> (or use the systemd service described in README)
								<?php endif; ?>
							</div>

							<div style="display:flex;gap:10px;justify-content:center">
								<a href="index.php" class="btn btn-primary" style="text-decoration:none">Open home page</a>
								<a href="panel.php" class="btn btn-ghost" style="text-decoration:none">Administration panel</a>
							</div>
						</div>
					</section>
				</div>
			<?php endif; ?>
		</div>

		<footer><?= htmlspecialchars(PRODUCT_NAME) ?> Installer · PHP <?= htmlspecialchars(PHP_VERSION) ?> · <?= htmlspecialchars($osFamily) ?></footer>
	</div>

	<?php if ($installerAuthorized): ?>
	<script src="assets/js/install.js?v=<?= (int) @filemtime(__DIR__ . '/assets/js/install.js') ?>" defer></script>
	<?php endif; ?>
</body>

</html>
