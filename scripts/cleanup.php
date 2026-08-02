<?php
/**
 * TryHackX Files — periodic housekeeping (P5).
 *
 * Deletes rows past their useful life (expired upload/download tokens, stale
 * active-download rows, old recovery attempts). These DELETEs used to run inline
 * in hot request paths; this script moves them to a scheduled job so normal
 * traffic stays lean. Read paths filter by timestamp, so behaviour is correct
 * regardless of how often this runs.
 *
 * Run from cron / Task Scheduler, e.g. every 15 minutes:
 *   *\/15 * * * *  php /path/to/pon/scripts/cleanup.php >> /var/log/filehost-cleanup.log 2>&1
 *
 * Windows Task Scheduler (daily/hourly):
 *   php.exe C:\wamp64\www\pon\scripts\cleanup.php
 *
 * Safe to run by hand; it is idempotent and prints a one-line summary.
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

// Minimal bootstrap: DB credentials + the storage roots, without pulling in the web-request
// machinery (sessions, headers, redirects). The paths mirror src/config.php: an absolute
// UPLOADS_PATH/DATA_PATH is used as-is, a relative one resolves against the project root.
require_once $configLocal;
if (!defined('PROJECT_ROOT')) {
	define('PROJECT_ROOT', $projectRoot);
}

$resolveStoragePath = static function (string $constant, string $default) use ($projectRoot): string {
	if (!defined($constant)) {
		return $default;
	}
	$path = rtrim(str_replace('\\', '/', trim((string) constant($constant))), '/');
	if ($path === '') {
		return $default;
	}
	return preg_match('#^(//|/|[A-Za-z]:/)#', $path) === 1 ? $path : $projectRoot . '/' . ltrim($path, '/');
};
if (!defined('UPLOADS_DIR')) {
	define('UPLOADS_DIR', $resolveStoragePath('UPLOADS_PATH', $projectRoot . '/uploads'));
}
if (!defined('DATA_DIR')) {
	define('DATA_DIR', $resolveStoragePath('DATA_PATH', $projectRoot . '/data'));
}
unset($resolveStoragePath);

require_once $projectRoot . '/src/includes/Database.php';
// StorageEnforcer deletes through FileManager, so it has to be on hand here too.
require_once $projectRoot . '/src/includes/FileManager.php';

$pdo = Database::getInstance();
if (!$pdo) {
	fwrite(STDERR, "Cannot connect to the database.\n");
	exit(1);
}

$cleanupLock = 'fh:cleanup:' . substr(hash(
	'sha256',
	(string) (defined('DB_NAME') ? DB_NAME : '') . '|' . (string) (defined('DB_PREFIX') ? DB_PREFIX : '')
), 0, 32);
$lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
$lockStmt->execute([$cleanupLock]);
if ((int) $lockStmt->fetchColumn() !== 1) {
	fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] cleanup: skipped (another worker owns the lease)\n");
	exit(0);
}
register_shutdown_function(static function () use ($pdo, $cleanupLock): void {
	try {
		$stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
		$stmt->execute([$cleanupLock]);
	} catch (Throwable $e) {
		// The database also releases named locks when this CLI connection closes.
	}
});

$started = microtime(true);
$deleted = Database::cleanupExpired();

// Durable DB→filesystem deletion outbox. Request paths make rows unreachable first and make
// one best-effort pass; this worker supplies retry after permissions, locks or transient I/O
// errors are repaired.
$fileDeletion = FileManager::processDeletionQueue(500);
$fileQuarantine = FileQuarantine::purgeExpired(500);
$adFileDeletion = AdRepository::processBannerDeletionQueue(500);

// Accounts left over their storage limits by a group change or a lapsed plan (pkt B). Only
// those whose grace period has actually run out lose anything; the rest just get their clock
// started or refreshed.
$storage = StorageEnforcer::sweep();

// Files past their group's retention (pt 6). Until now this only ever ran when an admin
// pressed "Wyczyść stare" in the panel, so an install with a retention set and nobody
// clicking kept everything forever — exactly the case a cron job is for.
$expired = FileManager::deleteExpiredFiles();

// Payments still in flight (pt 5). A provider notification is an inbound HTTP call and can
// simply not arrive; asking the provider from here turns that from a lost sale into a delay.
$payments = PaymentReconciler::sweepPending();

// Warnings, which is the half of housekeeping the account cares about: files retention is
// about to take, and subscriptions about to lapse while there is still time to extend them.
// Both are `once` notifications keyed on the deadline, so running this every fifteen minutes
// says everything exactly one time.
$warnDays = max(0, (int) Database::getSetting('notify_expiry_days', 3));
$filesWarned = $warnDays > 0 ? FileManager::warnExpiringFiles($warnDays) : 0;
$planWarnDays = max(0, (int) Database::getSetting('notify_plan_days', 7));
$plansWarned = $planWarnDays > 0 ? PlanRepository::warnExpiring($planWarnDays) : 0;

// Ad placements (Faza 8). Display already stopped at ends_at — pickForZone filters on it —
// so this half is bookkeeping: flip lapsed ads to `expired` (with a notification) and warn
// owners whose paid time is about to run out.
$adWarnDays = max(0, (int) Database::getSetting('ads_warn_days', 3));
$adsWarned = $adWarnDays > 0 ? AdRepository::warnExpiring($adWarnDays) : 0;
$adsExpired = AdRepository::expireEnded();

// Expired ads past their renewal grace lose the row, the banner files and the stats —
// "Moje reklamy" is a shop window, not an archive (runda 4, pt 8).
$adGraceDays = max(0, (int) Database::getSetting('ads_grace_days', 14));
$adsPurged = $adGraceDays > 0 ? AdRepository::purgeExpired($adGraceDays) : 0;

// Notifications nobody will read again.
$notifPruned = NotificationRepository::prune();
$traffic = TrafficRepository::aggregateAndPrune(
	max(1, (int) Database::getSetting('traffic_detail_days', 30)),
	max(30, (int) Database::getSetting('traffic_daily_days', 730))
);

// Upload rows whose writer died mid-stream (pt 8). Readers already ignore anything stale, so
// this is only about not letting the table grow.
ActiveDownloadRepository::pruneUploads();

$ms = (int) round((microtime(true) - $started) * 1000);

$parts = [];
$hadError = false;
foreach ($deleted as $label => $count) {
	if ($count < 0) {
		$hadError = true;
		$parts[] = "{$label}=ERR";
	} else {
		$parts[] = "{$label}={$count}";
	}
}

$stamp = date('Y-m-d H:i:s');
fwrite(STDOUT, "[{$stamp}] cleanup ({$ms} ms): " . (implode(' ', $parts) ?: 'no tables') . "\n");
fwrite(STDOUT, sprintf(
	"[%s] storage: checked=%d in_grace=%d enforced=%d files_removed=%d freed=%s\n",
	$stamp, $storage['checked'], $storage['grace'], $storage['enforced'],
	$storage['deleted'], number_format($storage['freed'] / 1048576, 1) . ' MiB'
));
fwrite(STDOUT, "[{$stamp}] retention: expired_files={$expired}\n");
fwrite(STDOUT, sprintf(
	"[%s] file_deletion: processed=%d deleted=%d quarantined=%d failed=%d\n",
	$stamp, $fileDeletion['processed'], $fileDeletion['deleted'],
	$fileDeletion['quarantined'], $fileDeletion['failed']
));
fwrite(STDOUT, sprintf(
	"[%s] file_quarantine: processed=%d purged=%d failed=%d\n",
	$stamp, $fileQuarantine['processed'], $fileQuarantine['purged'],
	$fileQuarantine['failed']
));
fwrite(STDOUT, sprintf(
	"[%s] ad_file_deletion: processed=%d deleted=%d failed=%d\n",
	$stamp, $adFileDeletion['processed'], $adFileDeletion['deleted'], $adFileDeletion['failed']
));
fwrite(STDOUT, sprintf(
	"[%s] payments: checked=%d completed=%d canceled=%d\n",
	$stamp, $payments['checked'], $payments['completed'], $payments['canceled']
));
fwrite(STDOUT, sprintf(
	"[%s] notifications: files_warned=%d plans_warned=%d pruned=%d\n",
	$stamp, $filesWarned, $plansWarned, $notifPruned
));
fwrite(STDOUT, "[{$stamp}] ads: warned={$adsWarned} expired={$adsExpired} purged={$adsPurged}\n");
fwrite(STDOUT, sprintf(
	"[%s] traffic: aggregate_rows=%d detail_deleted=%d daily_pruned=%d\n",
	$stamp, $traffic['aggregated'], $traffic['deleted'], $traffic['daily_pruned']
));

$exitCode = ($hadError || $fileDeletion['failed'] > 0 || $fileQuarantine['failed'] > 0
	|| $adFileDeletion['failed'] > 0) ? 1 : 0;
if ($exitCode === 0) {
	// Publish completion only after every durable cleanup stage has succeeded.
	Database::setSetting('cron_last_run', (string) time());
}
exit($exitCode);
