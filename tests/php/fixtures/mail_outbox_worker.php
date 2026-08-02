<?php

if (PHP_SAPI !== 'cli' || $argc !== 5) {
	exit(64);
}

[$script, $readyPath, $goPath, $outputPath, $workerId] = $argv;
foreach (['TEST_DB_HOST', 'TEST_DB_USER', 'TEST_DB_NAME', 'PROJECT_ROOT'] as $name) {
	if (getenv($name) === false || getenv($name) === '') {
		exit(65);
	}
}

define('DB_HOST', (string) getenv('TEST_DB_HOST'));
define('DB_USER', (string) getenv('TEST_DB_USER'));
define('DB_PASS', (string) (getenv('TEST_DB_PASS') === false ? '' : getenv('TEST_DB_PASS')));
define('DB_NAME', (string) getenv('TEST_DB_NAME'));
define('DB_PREFIX', 'fh_');
define('APP_SECRET_KEY', 'test-secret-key-0123456789abcdef');
define('APP_URL', 'http://localhost');
define('APP_NAME', 'FileHostTest');
define('DATA_DIR', dirname($readyPath));

if (!function_exists('__')) {
	function __(string $key, array $params = []): string { return $key; }
}
if (!function_exists('getClientIP')) {
	function getClientIP(): string { return '203.0.113.1'; }
}

$root = (string) getenv('PROJECT_ROOT');
require_once $root . '/src/includes/Crypto.php';
require_once $root . '/src/includes/Database.php';

if (file_put_contents($readyPath, 'ready', LOCK_EX) === false) {
	exit(66);
}
$deadline = microtime(true) + 10;
while (!is_file($goPath)) {
	if (microtime(true) >= $deadline) {
		exit(67);
	}
	usleep(1000);
}

$result = MailService::processBatch(
	$workerId,
	1,
	120,
	static function (): bool {
		usleep(200000);
		return true;
	}
);
if (file_put_contents($outputPath, json_encode($result), LOCK_EX) === false) {
	exit(68);
}
exit(0);
