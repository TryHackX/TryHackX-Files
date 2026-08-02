<?php
/**
 * TryHackX Files durable e-mail outbox worker.
 *
 * One batch (cron / Task Scheduler):
 *   php scripts/mail-worker.php --limit=25
 *
 * Long-running service:
 *   php scripts/mail-worker.php --loop --limit=25 --sleep=5
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
if (!defined('APP_VERSION')) {
	define('APP_VERSION', '2.76.5');
}
if (!defined('APP_URL')) {
	$canonical = defined('APP_CANONICAL_URL')
		? trim((string) APP_CANONICAL_URL)
		: trim((string) (getenv('FILEHOST_CANONICAL_URL') ?: ''));
	if ($canonical === '') {
		fwrite(STDERR, "APP_CANONICAL_URL is required.\n");
		exit(1);
	}
	define('APP_URL', rtrim($canonical, '/'));
}

require_once $projectRoot . '/src/includes/Database.php';
$pdo = Database::getInstance();
if (!$pdo) {
	fwrite(STDERR, "Cannot connect to the database.\n");
	exit(1);
}
Database::invalidateSettingsCache();
if ((int) Database::getSetting('schema_version', 0) !== Database::CURRENT_SCHEMA_VERSION
	|| (string) Database::getSetting('schema_ready', '0') !== '1') {
	fwrite(STDERR, "Database schema is not ready for this worker.\n");
	exit(2);
}

$loop = in_array('--loop', $argv, true);
$minimalLogs = in_array(
	strtolower(trim((string) getenv('FILEHOST_MINIMAL_LOGS'))),
	['1', 'true', 'yes', 'on'],
	true
);
$quiet = $minimalLogs || in_array('--quiet', $argv, true);
$limit = 25;
$sleepSeconds = 5;
foreach ($argv as $arg) {
	if (preg_match('/^--limit=(\d+)$/', $arg, $match)) {
		$limit = max(1, min(100, (int) $match[1]));
	} elseif (preg_match('/^--sleep=(\d+)$/', $arg, $match)) {
		$sleepSeconds = max(1, min(60, (int) $match[1]));
	}
}
$workerId = (gethostname() ?: 'host') . ':' . getmypid() . ':' . bin2hex(random_bytes(8));
$lastPruneDay = '';

do {
	$result = MailService::processBatch($workerId, $limit);
	$today = date('Y-m-d');
	if ($lastPruneDay !== $today) {
		$result['pruned'] = MailService::pruneSent(30);
		$lastPruneDay = $today;
	}
	$result['queue'] = MailService::stats();
	if (!$quiet) {
		fwrite(
			STDOUT,
			'[' . date('Y-m-d H:i:s') . '] mail-worker '
			. json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL
		);
	}
	if ($loop) {
		sleep($result['claimed'] > 0 ? 1 : $sleepSeconds);
	}
} while ($loop);
