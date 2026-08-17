<?php

/**
 * Verify every DB file row against its exact local object and SHA-256 manifest.
 *
 * Usage:
 *   php scripts/check-storage-integrity.php
 *   php scripts/check-storage-integrity.php --repair-missing
 *
 * The default is read-only. --repair-missing hashes a legacy object only when its exact
 * database filename and size match, then writes the versioned manifest atomically.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit("This script is CLI-only.\n");
}

// See scripts/mail-worker.php: `register_argc_argv` is Off in Debian's default php.ini, so
// static analysis reports $argv as possibly undefined even though the CLI SAPI always sets it.
$argv = $_SERVER['argv'] ?? [];

$projectRoot = dirname(__DIR__);
$configLocal = $projectRoot . '/config/config.local.php';
if (!is_file($configLocal)) {
	fwrite(STDERR, "config.local.php not found — is the app installed?\n");
	exit(1);
}
require_once $configLocal;

$resolveRoot = static function (string $constant, string $fallback) use ($projectRoot): string {
	$value = defined($constant) ? trim((string) constant($constant)) : $fallback;
	$value = str_replace('\\', '/', rtrim($value, '/\\'));
	$absolute = str_starts_with($value, '/')
		|| str_starts_with($value, '//')
		|| preg_match('/\A[A-Za-z]:\//', $value) === 1;
	return $absolute ? $value : $projectRoot . '/' . $value;
};
if (!defined('UPLOADS_DIR')) {
	define('UPLOADS_DIR', $resolveRoot('UPLOADS_PATH', 'uploads'));
}
if (!defined('DATA_DIR')) {
	define('DATA_DIR', $resolveRoot('DATA_PATH', 'data'));
}

require_once $projectRoot . '/src/includes/Database.php';
require_once $projectRoot . '/src/includes/StorageManifest.php';

$repair = in_array('--repair-missing', $argv, true);
$unknown = array_values(array_filter(
	array_slice($argv, 1),
	static fn(string $arg): bool => $arg !== '--repair-missing' && $arg !== '--json'
));
if ($unknown) {
	fwrite(STDERR, 'Unknown option: ' . implode(', ', $unknown) . PHP_EOL);
	exit(64);
}

$pdo = Database::getInstance();
if (!$pdo) {
	fwrite(STDERR, "Cannot connect to the database.\n");
	exit(1);
}

$checked = 0;
$repaired = 0;
$invalidCount = 0;
$invalid = [];
try {
	$stmt = $pdo->query(
		'SELECT `id`,`original_name`,`size` FROM `' . Database::table('files') . '` ORDER BY `id`'
	);
	while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
		$checked++;
		$id = (string) $row['id'];
		$manifest = UPLOADS_DIR . '/' . $id . '/' . StorageManifest::FILE_NAME;
		$hadManifest = is_file($manifest) && !is_link($manifest);
		$resolved = StorageManifest::resolve(
			UPLOADS_DIR,
			$id,
			(string) $row['original_name'],
			(int) $row['size'],
			true,
			$repair
		);
		if ($resolved === null) {
			$invalidCount++;
			if (count($invalid) < 100) {
				$invalid[] = $id;
			}
		} elseif (!$hadManifest && is_file($manifest)) {
			$repaired++;
		}
	}
} catch (Throwable $e) {
	fwrite(STDERR, "Storage integrity report failed: {$e->getMessage()}\n");
	exit(1);
}

$result = [
	'success' => $invalidCount === 0,
	'uploads_dir' => UPLOADS_DIR,
	'checked_files' => $checked,
	'repaired_manifests' => $repaired,
	'invalid_count' => $invalidCount,
	'invalid_file_ids' => $invalid,
];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($invalidCount === 0 ? 0 : 2);
