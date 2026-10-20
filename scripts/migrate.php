<?php
/**
 * Run the database migration now — typically after fixing whatever made a step fail.
 *
 *   php scripts/migrate.php
 *
 * After a failed step, web requests wait five minutes before running it again and answer 503
 * meanwhile, so a broken upgrade does not have every visitor re-run it. This script retries at
 * once and reports the outcome; the migration journal (`<prefix>migration_journal`) keeps the
 * details of every attempt. Run it as the account the web server runs PHP as.
 *
 * Exit 0: the schema is current and ready. Exit 1: setup problem. Exit 2: the migration failed.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit("This script is CLI-only.\n");
}

$projectRoot = dirname(__DIR__);
$configLocal = $projectRoot . '/config/config.local.php';
if (!is_file($configLocal)) {
	fwrite(STDERR, "config.local.php not found — is the app installed?\n");
	exit(1);
}
require_once $configLocal;

if (!defined('PROJECT_ROOT')) {
	define('PROJECT_ROOT', $projectRoot);
}
require_once $projectRoot . '/src/includes/LocalConfig.php';
if (!defined('DATA_DIR')) {
	define('DATA_DIR', filehostResolveStoragePath(
		defined('DATA_PATH') ? (string) constant('DATA_PATH') : null,
		$projectRoot . '/data',
		$projectRoot
	));
}
if (!defined('UPLOADS_DIR')) {
	define('UPLOADS_DIR', filehostResolveStoragePath(
		defined('UPLOADS_PATH') ? (string) constant('UPLOADS_PATH') : null,
		$projectRoot . '/uploads',
		$projectRoot
	));
}
require_once $projectRoot . '/src/includes/Database.php';

if (!Database::getInstance()) {
	fwrite(STDERR, "Cannot connect to the database.\n");
	exit(1);
}

try {
	Database::invalidateSettingsCache();
	Database::migrate(true);
} catch (Throwable $e) {
	fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . "\n");
	exit(2);
}

Database::invalidateSettingsCache();
$version = (int) Database::getSetting('schema_version', 0);
$ready = (string) Database::getSetting('schema_ready', '0') === '1';
$current = $ready && $version === Database::CURRENT_SCHEMA_VERSION;
fwrite(STDOUT, json_encode([
	'success' => $current,
	'schema_version' => $version,
	'schema_ready' => $ready,
], JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($current ? 0 : 2);
