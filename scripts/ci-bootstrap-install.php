<?php
/**
 * Non-interactive fresh-install bootstrap used only by the isolated Docker CI smoke job.
 *
 * This deliberately refuses normal execution and never drops an existing database or
 * replaces a configuration file. The browser installer remains the production workflow.
 */

if (PHP_SAPI !== 'cli' || getenv('FILEHOST_CI_BOOTSTRAP') !== '1') {
	fwrite(STDERR, "This helper is restricted to FILEHOST_CI_BOOTSTRAP=1 in CLI.\n");
	exit(64);
}

$projectRoot = dirname(__DIR__);
$required = [
	'FILEHOST_DB_HOST',
	'FILEHOST_DB_USER',
	'FILEHOST_DB_PASS',
	'FILEHOST_DB_NAME',
	'FILEHOST_CANONICAL_URL',
];
$environment = [];
foreach ($required as $name) {
	$value = getenv($name);
	if ($value === false || trim($value) === '') {
		fwrite(STDERR, "Missing required CI variable: {$name}\n");
		exit(64);
	}
	$environment[$name] = (string) $value;
}

$prefix = (string) (getenv('FILEHOST_DB_PREFIX') ?: 'fh_');
if (!preg_match('/^[A-Za-z0-9_]{0,24}$/', $prefix)
	|| !preg_match('/^[A-Za-z0-9_]{1,64}$/', $environment['FILEHOST_DB_NAME'])
) {
	fwrite(STDERR, "Unsafe CI database name or table prefix.\n");
	exit(64);
}

$configPath = $projectRoot . '/config/config.local.php';
if (file_exists($configPath)) {
	fwrite(STDERR, "Refusing to replace an existing config.local.php.\n");
	exit(73);
}

define('PROJECT_ROOT', $projectRoot);
define('DATA_DIR', $projectRoot . '/data');
define('UPLOADS_DIR', $projectRoot . '/uploads');
define('DB_HOST', $environment['FILEHOST_DB_HOST']);
define('DB_USER', $environment['FILEHOST_DB_USER']);
define('DB_PASS', $environment['FILEHOST_DB_PASS']);
define('DB_NAME', $environment['FILEHOST_DB_NAME']);
define('DB_PREFIX', $prefix);
define('APP_CANONICAL_URL', $environment['FILEHOST_CANONICAL_URL']);
define('APP_SECRET_KEY', bin2hex(random_bytes(32)));

require_once $projectRoot . '/src/includes/CanonicalUrl.php';
try {
	filehostNormalizeCanonicalUrl(APP_CANONICAL_URL);
} catch (InvalidArgumentException) {
	fwrite(STDERR, "Invalid FILEHOST_CANONICAL_URL.\n");
	exit(64);
}

require_once $projectRoot . '/src/includes/Database.php';
if (!Database::connectWith(DB_HOST, DB_USER, DB_PASS, DB_NAME)) {
	fwrite(STDERR, "Could not connect to the isolated CI database.\n");
	exit(69);
}

$created = Database::createTables(DB_PREFIX);
if (empty($created['success'])) {
	fwrite(STDERR, 'Could not create schema: ' . ($created['error'] ?? 'unknown') . "\n");
	exit(70);
}

Database::invalidateSettingsCache();
Database::migrate();
Database::invalidateSettingsCache();

$defaults = [
	'app_name' => 'FileHost CI',
	'maintenance_mode' => '0',
	'registration_enabled' => '1',
	'user_activation_mode' => 'auto',
	'system_max_file_size_mb' => '32',
	'max_upload_folder_mb' => '0',
	'blocked_extensions' => 'php,phtml,phar,exe,bat,cmd,sh,py,html,svg,xml,htaccess',
	'concurrent_downloads_guest' => '2',
	'concurrent_downloads_user' => '2',
	'recaptcha_enabled' => '0',
	'recaptcha_token_lifetime' => '120',
	'recaptcha_max_files_per_session_guest' => '5',
	'recaptcha_max_files_per_session_auth' => '5',
	'recaptcha_download_threshold' => '-1',
	'download_token_ttl' => '900',
	'limit_upload_guest' => '0',
	'limit_download_guest' => '0',
	'limit_upload_user' => '0',
	'limit_download_user' => '0',
	'email_method' => 'php',
	'email_from' => 'noreply@example.invalid',
	'email_from_name' => 'FileHost CI',
];
if (!Database::insertDefaultSettings($defaults, DB_PREFIX)) {
	fwrite(STDERR, "Could not seed CI settings.\n");
	exit(70);
}

$schemaVersion = (int) Database::getSetting('schema_version', 0);
$schemaReady = (string) Database::getSetting('schema_ready', '0');
if ($schemaVersion !== Database::CURRENT_SCHEMA_VERSION || $schemaReady !== '1') {
	fwrite(STDERR, "Fresh CI schema did not become ready.\n");
	exit(70);
}

$export = static fn(string $value): string => var_export($value, true);
$config = "<?php\n"
	. "/** Generated only by the isolated Docker CI smoke test. */\n"
	. "define('DB_HOST', " . $export(DB_HOST) . ");\n"
	. "define('DB_USER', " . $export(DB_USER) . ");\n"
	. "define('DB_PASS', " . $export(DB_PASS) . ");\n"
	. "define('DB_NAME', " . $export(DB_NAME) . ");\n"
	. "define('DB_PREFIX', " . $export(DB_PREFIX) . ");\n"
	. "define('APP_CANONICAL_URL', " . $export(APP_CANONICAL_URL) . ");\n"
	. "define('APP_SECRET_KEY', " . $export(APP_SECRET_KEY) . ");\n";

$configDirectory = dirname($configPath);
if (!is_dir($configDirectory)
	&& !mkdir($configDirectory, 0750, true)
	&& !is_dir($configDirectory)
) {
	fwrite(STDERR, "Could not create the CI config directory.\n");
	exit(73);
}
$stagedConfig = $configDirectory . '/.config.local.ci-' . bin2hex(random_bytes(8));
$handle = @fopen($stagedConfig, 'x+b');
if ($handle === false) {
	fwrite(STDERR, "Could not create staged CI configuration.\n");
	exit(73);
}
$written = fwrite($handle, $config);
$flushed = $written === strlen($config) && fflush($handle);
fclose($handle);
if (!$flushed || !@chmod($stagedConfig, 0600) || !@link($stagedConfig, $configPath)) {
	@unlink($stagedConfig);
	fwrite(STDERR, "Could not publish CI configuration atomically.\n");
	exit(73);
}
@unlink($stagedConfig);

$lockPath = DATA_DIR . '/install.lock';
if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0750, true) && !is_dir(DATA_DIR)) {
	fwrite(STDERR, "Could not create the CI data directory.\n");
	exit(73);
}
$lock = @fopen($lockPath, 'x+b');
if ($lock === false
	|| fwrite($lock, "FileHost isolated CI installation\n") === false
	|| !fflush($lock)
) {
	if (is_resource($lock)) {
		fclose($lock);
	}
	fwrite(STDERR, "Could not persist the CI installer lock.\n");
	exit(73);
}
fclose($lock);
@chmod($lockPath, 0600);

echo json_encode([
	'success' => true,
	'schema_version' => $schemaVersion,
	'schema_ready' => true,
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
