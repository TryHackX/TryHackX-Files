<?php
if (basename($_SERVER['PHP_SELF']) === 'config.php') {
	http_response_code(403);
	exit('Direct access forbidden');
}

define('APP_VERSION', '2.76.8');
// APP_ROOT is this file's dir (src/). The project root is one level up and holds the
// non-public dirs (uploads/, data/, config/) — outside the web root (public/), so secrets
// and storage are not URL-reachable (St1).
define('APP_ROOT', dirname(__FILE__));
define('PROJECT_ROOT', dirname(APP_ROOT));

require_once APP_ROOT . '/brand.php';

$configLocalPath = PROJECT_ROOT . '/config/config.local.php';
$hasLocalConfig = file_exists($configLocalPath);

require_once APP_ROOT . '/includes/CanonicalUrl.php';

// --- HTTP test harness (runda 10) ---
// FH_TEST_MODE=1 points the whole served app at the throwaway test database and isolated
// storage dirs, and skips config.local.php entirely — an instance the request-level tests
// talk to must never read production coordinates. Only the test runner sets this env.
$fhTestMode = getenv('FH_TEST_MODE') === '1';

// Load DB credentials early so canonical URL / trusted proxies from config.local are available.
// It is also where the storage paths below may be overridden, so it must come first.
if ($fhTestMode) {
	define('DB_HOST', getenv('TEST_DB_HOST') ?: 'localhost');
	define('DB_USER', getenv('TEST_DB_USER') ?: 'root');
	define('DB_PASS', getenv('TEST_DB_PASS') !== false ? getenv('TEST_DB_PASS') : '');
	define('DB_NAME', getenv('TEST_DB_NAME') ?: 'filehost_test');
	define('DB_PREFIX', 'fh_');
	define('APP_SECRET_KEY', 'test-secret-key-0123456789abcdef');
	define('APP_CANONICAL_URL', getenv('FILEHOST_CANONICAL_URL') ?: 'http://localhost');
	$testTrustedProxies = trim((string) (getenv('TEST_TRUSTED_PROXIES') ?: ''));
	if ($testTrustedProxies !== '') {
		define('TRUSTED_PROXIES', $testTrustedProxies);
	}
	unset($testTrustedProxies);
	define('DATA_PATH', PROJECT_ROOT . '/tests/php/.tmp-data-http');
	define('UPLOADS_PATH', PROJECT_ROOT . '/tests/php/.tmp-uploads-http');
} elseif ($hasLocalConfig) {
	require_once $configLocalPath;
}

// An unconfigured deployment may point only to the relative installer path. Building this
// redirect from HTTP_HOST would turn a hostile Host header into an attacker-controlled URL.
if (!$fhTestMode && !$hasLocalConfig) {
	if (PHP_SAPI === 'cli') {
		throw new RuntimeException(PRODUCT_NAME . ' is not installed: config/config.local.php is missing.');
	}
	header('Location: install.php', true, 302);
	exit;
}

// APP_URL is a trust anchor, not a reflection of the current request. It is mandatory on an
// installed deployment and can come from config.local.php or an explicit process environment
// value for immutable/container deployments.
$canonicalSource = defined('APP_CANONICAL_URL')
	? (string) APP_CANONICAL_URL
	: (string) (getenv('FILEHOST_CANONICAL_URL') ?: '');
try {
	$baseUrl = filehostNormalizeCanonicalUrl($canonicalSource);
} catch (InvalidArgumentException $e) {
	if (PHP_SAPI === 'cli') {
		throw new RuntimeException(
			'APP_CANONICAL_URL (or FILEHOST_CANONICAL_URL) is required and must be a valid HTTP(S) base URL.',
			0,
			$e
		);
	}
	http_response_code(503);
	header('Content-Type: text/plain; charset=utf-8');
	header('Cache-Control: no-store');
	exit(PRODUCT_NAME . ' configuration error: a valid canonical URL is required.');
}
unset($canonicalSource);

define('APP_URL', $baseUrl);
unset($baseUrl);
define('INSTALL_MODE', false);

// Trusted-Host gate: reject a foreign scheme, hostname or port before opening the session,
// connecting to the database or dispatching a route. X-Forwarded-Proto is considered only by
// isRequestSecure(), which itself accepts it exclusively from TRUSTED_PROXIES.
if (PHP_SAPI !== 'cli') {
	$requestHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
	if (!filehostRequestMatchesCanonicalOrigin(isRequestSecure(), $requestHost, APP_URL)) {
		http_response_code(421);
		header('Content-Type: text/plain; charset=utf-8');
		header('Cache-Control: no-store');
		header('Connection: close');
		exit('Misdirected Request');
	}
	unset($requestHost);
}

/**
 * Storage roots. `config/config.local.php` may define UPLOADS_PATH (and/or DATA_PATH) to put
 * uploads on another disk or on a pooled volume — see docs/STORAGE.md. An absolute path is
 * used as-is (`D:/filehost-uploads`, `/mnt/storage/uploads`, `//nas/share/uploads`); a
 * relative one resolves against the project root, so the default stays `<project>/uploads`.
 */
$resolveStoragePath = static function (string $constant, string $default): string {
	if (!defined($constant)) {
		return $default;
	}
	$path = trim((string) constant($constant));
	if ($path === '') {
		return $default;
	}
	$path = rtrim(str_replace('\\', '/', $path), '/');
	$isAbsolute = preg_match('#^(//|/|[A-Za-z]:/)#', $path) === 1;
	return $isAbsolute ? $path : PROJECT_ROOT . '/' . ltrim($path, '/');
};
define('UPLOADS_DIR', $resolveStoragePath('UPLOADS_PATH', PROJECT_ROOT . '/uploads'));
define('DATA_DIR', $resolveStoragePath('DATA_PATH', PROJECT_ROOT . '/data'));
unset($resolveStoragePath);

// The HTTP test server must not share WAMP/Apache's global session directory. Files there
// may belong to another service account, which makes an otherwise-correct cookie jar fail
// nondeterministically with EACCES and masks real authentication regressions.
if ($fhTestMode) {
	$testSessionDir = DATA_DIR . '/sessions';
	if (!is_dir($testSessionDir) && !mkdir($testSessionDir, 0700, true) && !is_dir($testSessionDir)) {
		throw new RuntimeException('Could not create the isolated HTTP-test session directory.');
	}
	session_save_path($testSessionDir);
	unset($testSessionDir);
}

startSecureSession();

require_once APP_ROOT . '/includes/Database.php';
require_once APP_ROOT . '/includes/SessionAuth.php';

$db = Database::getInstance();
if ($db) {
	Database::migrate();
	$settings = Database::getAllSettings();

	// PHP owns the CSP header for its own responses; .htaccess only covers what we skip
	// (`Header setifempty` there — two CSP headers are enforced as their intersection,
	// so the ad-domain relaxation below MUST be the only policy on the response).
	emitContentSecurityPolicy($settings);

	define('APP_NAME', $settings['app_name'] ?? PRODUCT_NAME);

	// Load limits
	define('GUEST_MAX_FILE_SIZE_MB', (int) ($settings['guest_max_file_size_mb'] ?? 250));
	define('USER_MAX_FILE_SIZE_MB', (int) ($settings['user_max_file_size_mb'] ?? 5120));
	define('GUEST_MAX_FILES', (int) ($settings['guest_max_files'] ?? 5));
	define('USER_MAX_FILES', (int) ($settings['user_max_files'] ?? 15));

	// For backward compatibility and general max limit (use user limit as absolute max)
	define('MAX_FILE_SIZE_MB', USER_MAX_FILE_SIZE_MB);
	define('MAX_FILES', USER_MAX_FILES);

	define('MAX_UPLOAD_FOLDER_MB', (int) ($settings['max_upload_folder_mb'] ?? 0));
	define('AUTO_DELETE_DAYS', (int) ($settings['auto_delete_days'] ?? 0));
} else {
	define('APP_NAME', PRODUCT_NAME);
	define('GUEST_MAX_FILE_SIZE_MB', 250);
	define('USER_MAX_FILE_SIZE_MB', 5120);
	define('GUEST_MAX_FILES', 5);
	define('USER_MAX_FILES', 15);
	define('MAX_FILE_SIZE_MB', 5120);
	define('MAX_FILES', 15);
	define('MAX_UPLOAD_FOLDER_MB', 0);
	define('AUTO_DELETE_DAYS', 0);
}

// i18n (Faza 4.3): resolve the active language before anything renders — init() may set
// the language cookie, so it has to run while headers are still open. $settings only exists
// once the DB is up; before install we just fall back to the browser/default.
require_once APP_ROOT . '/includes/Lang.php';
Lang::init(isset($settings) ? ($settings['default_language'] ?? null) : null);

// Create the storage roots on demand. A custom UPLOADS_PATH/DATA_PATH can point at a disk
// that is not mounted (or not writable by the web user), so fail loudly instead of letting
// every later write break in a confusing way. See docs/STORAGE.md.
foreach (['uploads' => UPLOADS_DIR, 'data' => DATA_DIR] as $label => $dir) {
	if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
		http_response_code(500);
		exit(PRODUCT_NAME . ': cannot create the ' . $label . ' directory: ' . htmlspecialchars($dir)
			. ' — check that the drive is mounted and writable (see docs/STORAGE.md).');
	}
}

/**
 * Content-Security-Policy for PHP responses (Faza 8).
 *
 * The baseline below mirrors public/.htaccess exactly. While the advertising feature is
 * enabled AND external ad code can actually render (`ads_adsense_active` — kept current by
 * AdRepository on every ad/settings change), Google's ad-serving origins are admitted;
 * `ads_csp_extra` lets the operator whitelist another network's origins. The moment ads
 * are switched off, the next request is back on the strict policy — the settings cache is
 * invalidated on write, so there is no lag beyond the in-flight request.
 */
function emitContentSecurityPolicy(array $settings): void
{
	if (PHP_SAPI === 'cli' || headers_sent()) {
		return;
	}

	$strictScripts = defined('FILEHOST_CSP_STRICT_SCRIPTS')
		&& FILEHOST_CSP_STRICT_SCRIPTS === true;
	$script = "'self'"
		. ($strictScripts ? '' : " 'unsafe-inline'")
		. ' https://www.google.com https://www.gstatic.com';
	// blob: is same-origin object URLs only — the panel's banner cropper previews the
	// picked file through URL.createObjectURL, which img-src would otherwise block.
	$img = "'self' data: blob:";
	$connect = "'self'";
	$frame = "'self' https://www.google.com";

	$adsOn = ($settings['ads_enabled'] ?? '0') === '1';
	if ($adsOn && ($settings['ads_adsense_active'] ?? '0') === '1') {
		$script .= ' https://pagead2.googlesyndication.com https://googleads.g.doubleclick.net'
			. ' https://tpc.googlesyndication.com https://www.googletagservices.com'
			. ' https://ep2.adtrafficquality.google';
		$img .= ' https://*.googlesyndication.com https://*.doubleclick.net https://*.google.com'
			. ' https://*.google.pl https://ep1.adtrafficquality.google';
		$connect .= ' https://pagead2.googlesyndication.com https://*.doubleclick.net'
			. ' https://*.google.com https://ep1.adtrafficquality.google';
		$frame .= ' https://googleads.g.doubleclick.net https://tpc.googlesyndication.com'
			. ' https://*.doubleclick.net https://ep2.adtrafficquality.google';
	}
	if ($adsOn) {
		// Operator-supplied origins for other networks. Stored pre-sanitised (see
		// AdsController::sanitizeCspExtra), re-filtered here so a hand-edited DB row still
		// cannot smuggle a directive into the header.
		$extra = '';
		foreach (preg_split('/\s+/', trim((string) ($settings['ads_csp_extra'] ?? ''))) ?: [] as $token) {
			if ($token !== '' && preg_match('~^https://[a-z0-9*][a-z0-9.*-]*(:\d{1,5})?$~i', $token)) {
				$extra .= ' ' . $token;
			}
		}
		if ($extra !== '') {
			$script .= $extra;
			$img .= $extra;
			$connect .= $extra;
			$frame .= $extra;
		}
	}

	header("Content-Security-Policy: default-src 'self'; script-src {$script}; "
		. ($strictScripts ? "script-src-attr 'none'; " : '')
		. "style-src 'self' 'unsafe-inline'; img-src {$img}; connect-src {$connect}; "
		. "font-src 'self'; frame-src {$frame}; object-src 'none'; base-uri 'self'; "
		. "form-action 'self'; frame-ancestors 'self'");
}

function formatSize($bytes): string
{
	$bytes = (int) ($bytes ?? 0);
	if ($bytes <= 0)
		return '0 B';
	$units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB'];
	$i = min(count($units) - 1, (int) floor(log($bytes, 1024)));
	return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}

/**
 * Resolve the real client IP.
 *
 * Proxy headers (X-Forwarded-For, CF-Connecting-IP, X-Real-IP) are trusted ONLY when the
 * direct peer (REMOTE_ADDR) is a configured reverse proxy. Define TRUSTED_PROXIES in
 * config.local.php as a comma-separated list of proxy IPs to enable them. Without it, only
 * REMOTE_ADDR is used, which prevents clients from spoofing their IP to bypass bans/limits.
 */
function getClientIP(): string
{
	$remote = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

	$trusted = [];
	if (defined('TRUSTED_PROXIES') && TRUSTED_PROXIES !== '') {
		$trusted = array_map('trim', explode(',', TRUSTED_PROXIES));
	}

	if ($trusted && in_array($remote, $trusted, true)) {
		foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $h) {
			if (!empty($_SERVER[$h])) {
				$ip = trim(explode(',', $_SERVER[$h])[0]);
				if (filter_var($ip, FILTER_VALIDATE_IP)) {
					return $ip;
				}
			}
		}
	}

	return $remote;
}

/**
 * Start the session with hardened cookie flags. Safe to call multiple times.
 */
function startSecureSession(): void
{
	if (session_status() !== PHP_SESSION_NONE) {
		return;
	}

	session_set_cookie_params([
		'lifetime' => 0,
		'path' => '/',
		'domain' => '',
		'secure' => isRequestSecure(),
		'httponly' => true,
		'samesite' => 'Lax',
	]);

	session_start();
}

function isRequestSecure(): bool
{
	if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
		return true;
	}
	// Honour X-Forwarded-Proto only from trusted proxies.
	if (defined('TRUSTED_PROXIES') && TRUSTED_PROXIES !== '') {
		$trusted = array_map('trim', explode(',', TRUSTED_PROXIES));
		if (in_array($_SERVER['REMOTE_ADDR'] ?? '', $trusted, true)
			&& ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
			return true;
		}
	}
	return false;
}

/**
 * Return the per-session CSRF token, creating it on first use.
 */
function csrfToken(): string
{
	if (empty($_SESSION['csrf_token'])) {
		$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
	}
	return $_SESSION['csrf_token'];
}

/**
 * Validate a CSRF token from the X-CSRF-Token header or a `_csrf` request field.
 * Also enforces a same-origin check when an Origin/Referer header is present.
 */
function csrfValidate(): bool
{
	$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? '';
	if (empty($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
		return false;
	}

	$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
	if ($origin === '' && !empty($_SERVER['HTTP_REFERER'])) {
		$origin = $_SERVER['HTTP_REFERER'];
	}
	if ($origin !== '') {
		$originValue = filehostOriginOrNull($origin);
		if ($originValue === null || !hash_equals(filehostCanonicalOrigin(APP_URL), $originValue)) {
			return false;
		}
	}

	return true;
}

function generateToken(int $length = 32): string
{
	return bin2hex(random_bytes($length / 2));
}

function generateFileId(): string
{
	return dechex(time()) . bin2hex(random_bytes(4));
}

function getCurrentUser(): ?array
{
	return SessionAuth::current();
}

// Validate and synchronize a logged-in session before any public entry point can trust
// `$_SESSION['is_admin']`, `user_role` or merely the presence of `user_id`.
if (isset($_SESSION['user_id'])) {
	getCurrentUser();
}
