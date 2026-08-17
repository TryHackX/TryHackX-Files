<?php

/**
 * Inspect, restore or permanently purge exact file IDs from the deletion quarantine.
 *
 * Examples:
 *   php scripts/file-quarantine.php list
 *   php scripts/file-quarantine.php restore FILE_ID
 *   php scripts/file-quarantine.php purge FILE_ID --force
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

$resolveStoragePath = static function (string $constant, string $default) use ($projectRoot): string {
	if (!defined($constant)) {
		return $default;
	}
	$path = rtrim(str_replace('\\', '/', trim((string) constant($constant))), '/');
	if ($path === '') {
		return $default;
	}
	return preg_match('#^(//|/|[A-Za-z]:/)#', $path) === 1
		? $path
		: $projectRoot . '/' . ltrim($path, '/');
};
if (!defined('PROJECT_ROOT')) {
	define('PROJECT_ROOT', $projectRoot);
}
if (!defined('UPLOADS_DIR')) {
	define('UPLOADS_DIR', $resolveStoragePath('UPLOADS_PATH', $projectRoot . '/uploads'));
}
if (!defined('DATA_DIR')) {
	define('DATA_DIR', $resolveStoragePath('DATA_PATH', $projectRoot . '/data'));
}
unset($resolveStoragePath);

require_once $projectRoot . '/src/includes/FileManager.php';

$pdo = Database::getInstance();
if (!$pdo) {
	fwrite(STDERR, "Cannot connect to the database.\n");
	exit(1);
}
Database::migrate();

$command = strtolower((string) ($argv[1] ?? 'list'));
if ($command === 'list') {
	$stmt = $pdo->query(
		"SELECT `file_id`, `reason`, `actor_type`, `actor_id`, `size`, `checksum`,
		        `state`, `quarantine_until`, `attempts`, `last_error`, `created_at`
		 FROM `" . Database::table('file_quarantine') . "`
		 ORDER BY `created_at` DESC LIMIT 500"
	);
	$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
	if ($rows === []) {
		fwrite(STDOUT, "Quarantine is empty.\n");
		exit(0);
	}
	foreach ($rows as $row) {
		fwrite(STDOUT, sprintf(
			"%s  %-11s  until=%s  size=%d  reason=%s  actor=%s:%s  attempts=%d%s\n",
			$row['file_id'],
			$row['state'],
			date('Y-m-d H:i:s', (int) $row['quarantine_until']),
			(int) $row['size'],
			$row['reason'],
			$row['actor_type'],
			$row['actor_id'] ?? '-',
			(int) $row['attempts'],
			$row['last_error'] ? '  error=' . $row['last_error'] : ''
		));
	}
	exit(0);
}

$id = (string) ($argv[2] ?? '');
if (!FileManager::isValidFileId($id)) {
	fwrite(STDERR, "Supply one exact, valid file ID.\n");
	exit(2);
}
if ($command === 'restore') {
	if (!FileQuarantine::restore($id)) {
		fwrite(STDERR, "Restore refused or failed for {$id}; inspect the quarantine row and logs.\n");
		exit(1);
	}
	fwrite(STDOUT, "Restored {$id}.\n");
	exit(0);
}
if ($command === 'purge') {
	if (!in_array('--force', $argv, true)) {
		fwrite(STDERR, "Permanent purge requires --force and cannot be undone.\n");
		exit(2);
	}
	if (!FileQuarantine::purge($id)) {
		fwrite(STDERR, "Purge refused or failed for {$id}.\n");
		exit(1);
	}
	fwrite(STDOUT, "Permanently purged {$id}.\n");
	exit(0);
}

fwrite(STDERR, "Usage: php scripts/file-quarantine.php list|restore ID|purge ID --force\n");
exit(2);
