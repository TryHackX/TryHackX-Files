<?php

/**
 * Read-only orphan report for the application's relational integrity boundary.
 *
 * Usage:
 *   php scripts/check-db-integrity.php
 *
 * Exit 0 means no orphan was found, exit 2 means at least one relation is inconsistent,
 * and exit 1 means the report itself could not be produced.
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
if (!defined('DATA_DIR')) {
	define('DATA_DIR', $projectRoot . '/data');
}
if (!defined('UPLOADS_DIR')) {
	define('UPLOADS_DIR', $projectRoot . '/uploads');
}
require_once $projectRoot . '/src/includes/Database.php';

$pdo = Database::getInstance();
if (!$pdo) {
	fwrite(STDERR, "Cannot connect to the database.\n");
	exit(1);
}

$relations = [
	['users', 'group_id', 'groups', 'id'],
	['users', 'staff_group_id', 'groups', 'id'],
	['files', 'user_id', 'users', 'id'],
	['collections', 'user_id', 'users', 'id'],
	['collection_files', 'collection_id', 'collections', 'id'],
	['collection_files', 'file_id', 'files', 'id'],
	['reports', 'file_id', 'files', 'id'],
	['api_keys', 'user_id', 'users', 'id'],
	['webhooks', 'user_id', 'users', 'id'],
	['webhook_deliveries', 'webhook_id', 'webhooks', 'id'],
	['notifications', 'user_id', 'users', 'id'],
	['notification_prefs', 'user_id', 'users', 'id'],
	['recovery_tokens', 'user_id', 'users', 'id'],
	['totp_recovery_codes', 'user_id', 'users', 'id'],
	['upload_tokens', 'user_id', 'users', 'id'],
	['download_tokens', 'user_id', 'users', 'id'],
	['active_uploads', 'user_id', 'users', 'id'],
	['upload_storage_reservations', 'user_id', 'users', 'id'],
	['traffic_logs', 'user_id', 'users', 'id'],
	['traffic_logs', 'file_id', 'files', 'id'],
	['audit_log', 'user_id', 'users', 'id'],
	['plans', 'group_id', 'groups', 'id'],
	['payments', 'user_id', 'users', 'id'],
	['payments', 'actor_id', 'users', 'id'],
	['payments', 'plan_id', 'plans', 'id'],
	['payments', 'ad_id', 'ads', 'id'],
	['payments', 'package_id', 'ad_packages', 'id'],
	['ads', 'owner_id', 'users', 'id'],
	['ads', 'approved_by', 'users', 'id'],
	['ads', 'package_id', 'ad_packages', 'id'],
	['ad_stats_daily', 'ad_id', 'ads', 'id'],
	['download_reservations', 'user_id', 'users', 'id'],
	['download_reservation_effects', 'reservation_id', 'download_reservations', 'id'],
	['email_reservations', 'user_id', 'users', 'id'],
	['promo_reservations', 'promo_id', 'promo_codes', 'id'],
	['promo_reservations', 'ext_order_id', 'payments', 'ext_order_id'],
	['promo_codes', 'plan_id', 'plans', 'id'],
];

$orphans = [];
try {
	foreach ($relations as [$child, $childColumn, $parent, $parentColumn]) {
		$childTable = Database::table($child);
		$parentTable = Database::table($parent);
		$sql = "SELECT COUNT(*) FROM `{$childTable}` c
			LEFT JOIN `{$parentTable}` p ON p.`{$parentColumn}` = c.`{$childColumn}`
			WHERE c.`{$childColumn}` IS NOT NULL AND p.`{$parentColumn}` IS NULL";
		$count = (int) $pdo->query($sql)->fetchColumn();
		$orphans["{$child}.{$childColumn}->{$parent}.{$parentColumn}"] = $count;
	}
} catch (Throwable $e) {
	fwrite(STDERR, "Integrity report failed: {$e->getMessage()}\n");
	exit(1);
}

$invalid = array_filter($orphans, static fn(int $count): bool => $count > 0);
$result = [
	'success' => $invalid === [],
	'schema_version' => (int) Database::getSetting('schema_version', 0),
	'checked_relations' => count($orphans),
	'orphan_counts' => $orphans,
];
fwrite(
	STDOUT,
	json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
);
exit($invalid === [] ? 0 : 2);
