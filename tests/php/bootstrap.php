<?php
/**
 * PHPUnit bootstrap for the repository test suite (Faza 5 · #4).
 *
 * Stands up just enough of the app to exercise the repository layer against a real, throwaway
 * MySQL schema (the SQL is MySQL-specific — backticks, ON DUPLICATE KEY, FROM_UNIXTIME — so a
 * real server is used, not sqlite). DB coordinates are env-overridable so the same tests run
 * locally (WAMP) and in CI (MySQL service). The web layer (config.php, sessions, i18n) is NOT
 * loaded; the few global functions the repositories call are stubbed here so tests stay DB-only.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

// --- Destructive test database: explicit, ephemeral and fail-closed ---
$root = dirname(__DIR__, 2);
require_once __DIR__ . '/TestDatabaseGuard.php';

$readEnvironment = static function (string $name): string {
	$value = getenv($name);
	return $value === false ? '' : (string) $value;
};
$testDatabase = TestDatabaseGuard::validate([
	'ALLOW_DESTRUCTIVE_TEST_DB' => $readEnvironment('ALLOW_DESTRUCTIVE_TEST_DB'),
	'TEST_DB_NONCE' => $readEnvironment('TEST_DB_NONCE'),
	'TEST_DB_HOST' => $readEnvironment('TEST_DB_HOST'),
	'TEST_DB_ALLOWED_HOSTS' => $readEnvironment('TEST_DB_ALLOWED_HOSTS'),
	'TEST_DB_USER' => $readEnvironment('TEST_DB_USER'),
	'TEST_DB_PASS' => $readEnvironment('TEST_DB_PASS'),
	'TEST_DB_NAME' => $readEnvironment('TEST_DB_NAME'),
], $root . '/config/config.local.php');
unset($readEnvironment);

define('DB_HOST', $testDatabase['host']);
define('DB_USER', $testDatabase['user']);
define('DB_PASS', $testDatabase['pass']);
define('DB_NAME', $testDatabase['name']);
define('DB_PREFIX', 'fh_');

// Deterministic key so Crypto (encrypted settings, S12) round-trips in tests.
define('APP_SECRET_KEY', 'test-secret-key-0123456789abcdef');
define('APP_URL', 'http://localhost');
define('APP_NAME', 'FileHostTest');
define('FILEHOST_TESTING', true);

define('PROJECT_ROOT', $root);
// Every run gets private cache/upload roots tied to the same validated nonce.
$tmpData = __DIR__ . '/.tmp-data-' . $testDatabase['nonce'];
if (!is_dir($tmpData)) {
	mkdir($tmpData, 0700, true);
}
define('DATA_DIR', $tmpData);
$tmpUploads = __DIR__ . '/.tmp-uploads-' . $testDatabase['nonce'];
if (!is_dir($tmpUploads)) {
	mkdir($tmpUploads, 0700, true);
}
define('UPLOADS_DIR', $tmpUploads);

// Web-layer globals the repositories reference — stubbed so tests need no HTTP/session/i18n.
if (!function_exists('__')) {
	function __(string $key, array $params = []): string { return $key; }
}
if (!function_exists('getClientIP')) {
	function getClientIP(): string { return '203.0.113.1'; }
}

require_once $root . '/src/includes/Crypto.php';
require_once $root . '/src/includes/Database.php';
require_once $root . '/src/includes/SessionAuth.php';
if (!function_exists('getCurrentUser')) {
	function getCurrentUser(): ?array { return SessionAuth::current(); }
}
// FileManager::browse() is the query layer behind the all-files browser, so it is exercised
// by the same DB-only tests as the repositories (it owns SQL, not HTTP).
require_once $root . '/src/includes/FileManager.php';

/**
 * Remove only the per-run test roots validated above. Symlinks are unlinked, never followed.
 */
function removeEphemeralTestTree(string $path, string $nonce): void
{
	$parent = realpath(dirname($path));
	if ($parent === false || $parent !== realpath(__DIR__)
		|| !str_ends_with(basename($path), '-' . $nonce)
		|| (!str_starts_with(basename($path), '.tmp-data-')
			&& !str_starts_with(basename($path), '.tmp-uploads-'))) {
		error_log('Refusing to clean an unexpected PHPUnit temp path: ' . $path);
		return;
	}
	if (!is_dir($path)) {
		return;
	}

	try {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($iterator as $item) {
			$itemPath = $item->getPathname();
			if ($item->isLink() || $item->isFile()) {
				if (!unlink($itemPath)) {
					throw new RuntimeException('Cannot remove test artifact: ' . $itemPath);
				}
			} elseif ($item->isDir() && !rmdir($itemPath)) {
				throw new RuntimeException('Cannot remove test directory: ' . $itemPath);
			}
		}
		if (!rmdir($path)) {
			throw new RuntimeException('Cannot remove test root: ' . $path);
		}
	} catch (Throwable $e) {
		error_log('Ephemeral PHPUnit cleanup failed: ' . $e->getMessage());
	}
}

// Make sure the target database exists, then build a fresh schema for the whole run.
try {
	$dsn = 'mysql:host=' . DB_HOST . ';charset=utf8mb4';
	$admin = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
	$admin->exec('CREATE DATABASE `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
} catch (PDOException $e) {
	fwrite(STDERR, "Cannot reach test MySQL ({$e->getMessage()}). Set TEST_DB_* env vars.\n");
	exit(1);
}

// Register immediately after creation so schema/migration/test failures still drop the exact
// validated database. A hard-killed process can leave it behind, but the nonce prevents reuse.
$cleanupDsn = $dsn;
$cleanupUser = DB_USER;
$cleanupPass = DB_PASS;
$cleanupDatabase = DB_NAME;
$cleanupNonce = $testDatabase['nonce'];
$cleanupData = DATA_DIR;
$cleanupUploads = UPLOADS_DIR;
register_shutdown_function(static function () use (
	$cleanupDsn,
	$cleanupUser,
	$cleanupPass,
	$cleanupDatabase,
	$cleanupNonce,
	$cleanupData,
	$cleanupUploads
): void {
	try {
		$dropper = new PDO(
			$cleanupDsn,
			$cleanupUser,
			$cleanupPass,
			[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
		);
		$dropper->exec('DROP DATABASE `' . $cleanupDatabase . '`');
	} catch (Throwable $e) {
		error_log('Ephemeral PHPUnit database cleanup failed: ' . $e->getMessage());
	}
	removeEphemeralTestTree($cleanupData, $cleanupNonce);
	removeEphemeralTestTree($cleanupUploads, $cleanupNonce);
});

$res = Database::createTables('fh_', true);
if (empty($res['success'])) {
	fwrite(STDERR, 'createTables failed: ' . ($res['error'] ?? 'unknown') . "\n");
	exit(1);
}

// Drop any stale settings cache so schema_version is read from the fresh test DB (not a file
// left by a previous run) — otherwise migrate() would think the DB is already up to date.
Database::invalidateSettingsCache();

// createTables() builds the original schema; later features (groups A8, collections, API keys,
// webhooks, per-file options, encrypted secrets) are added by the versioned migrations — run
// them so the test DB matches a fully-migrated production schema.
Database::migrate();
Database::invalidateSettingsCache();

/**
 * Notification defaults for the whole suite: every type on, e-mail off.
 *
 * Several repositories now announce what they did (a lapsed plan, a quota grace period), and
 * some of those types are mailable and on by default. There is no SMTP here or in CI, so
 * leaving them on would have unrelated tests emit a `mail()` warning — which `failOnWarning`
 * turns into a failed run. Tests that need a different baseline call this again themselves.
 */
function applyTestNotificationDefaults(): void
{
	$map = [];
	foreach (Notifications::TYPES as $type => $meta) {
		$map[$type] = ['enabled' => true, 'app' => true, 'mail' => false];
	}
	Notifications::saveDefaults($map);
}
applyTestNotificationDefaults();

// Shared test base class (PHPUnit does not autoload it).
require_once __DIR__ . '/RepoTestCase.php';
