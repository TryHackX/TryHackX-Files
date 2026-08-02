<?php
/**
 * TrafficRepository (Faza 5 · #2).
 *
 * Transfer accounting (traffic_logs) feeding the admin dashboard: per-period totals,
 * per-day series, top IPs / top files, and the suspicious-IP heuristic. Extracted from
 * the Database god-object; the matching Database::* methods delegate here. Writes are on
 * the download/upload path; reads are admin-only.
 */
final class TrafficRepository
{
	public static function log(string $ip, int $size, string $type, ?string $fileId = null, ?int $userId = null): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$table = Database::table('traffic_logs');
		try {
			$stmt = $pdo->prepare("INSERT INTO `{$table}` (`ip_address`, `transfer_size`, `transfer_type`, `file_id`, `user_id`, `created_at`) VALUES (?, ?, ?, ?, ?, ?)");
			return $stmt->execute([$ip, $size, $type, $fileId, $userId, time()]);
		} catch (PDOException $e) {
			return false;
		}
	}

	/** Total transferred bytes over the rolling window for $period (day/week/month/year). */
	public static function stats(string $period = 'day'): int
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return 0;
		}
		$table = Database::table('traffic_logs');
		$daily = Database::table('traffic_daily');
		switch ($period) {
			case 'year':
				$startTime = strtotime('-1 year');
				break;
			case 'month':
				$startTime = strtotime('-1 month');
				break;
			case 'week':
				$startTime = strtotime('-1 week');
				break;
			case 'day':
			default:
				$startTime = strtotime('-24 hours');
				break;
		}
		try {
			$stmt = $pdo->prepare("SELECT COALESCE(SUM(`transfer_size`), 0) FROM `{$table}` WHERE `created_at` > ?");
			$stmt->execute([$startTime]);
			$total = (int) $stmt->fetchColumn();
			$stmt = $pdo->prepare(
				"SELECT COALESCE(SUM(`transfer_size`), 0) FROM `{$daily}` WHERE `day` >= ?"
			);
			$stmt->execute([date('Y-m-d', $startTime)]);
			return $total + (int) $stmt->fetchColumn();
		} catch (PDOException $e) {
			return 0;
		}
	}

	public static function topIPs(int $hours = 24, int $limit = 50): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return [];
		}
		$table = Database::table('traffic_logs');
		$startTime = time() - ($hours * 3600);
		try {
			$stmt = $pdo->prepare("SELECT `ip_address`, SUM(`transfer_size`) as total_traffic, COUNT(*) as request_count FROM `{$table}` WHERE `created_at` > ? GROUP BY `ip_address` ORDER BY total_traffic DESC LIMIT " . (int) $limit);
			$stmt->execute([$startTime]);
			return $stmt->fetchAll();
		} catch (PDOException $e) {
			return [];
		}
	}

	/** Per-day upload/download totals for the last N days, today included (dashboard charts). */
	public static function series(int $days = 7): array
	{
		$days = max(1, $days);
		return self::seriesRange(strtotime('today') - (($days - 1) * 86400), time(), 'day');
	}

	/**
	 * Bucket size => [MySQL format for grouping, PHP format for the seed keys, step expression].
	 *
	 * The gaps have to be filled in PHP rather than left to the query: a period with no traffic
	 * produces no row at all, and a chart that silently skips those reads as if the quiet hours
	 * never happened. Stepping goes through strtotime() rather than adding a fixed number of
	 * seconds, so a DST switch shifts the boundary instead of sliding every later bucket.
	 *
	 * Both sides format the same timestamps — MySQL for grouping, PHP for the seed keys — so the
	 * two must agree on the timezone. That was already true of the fixed 7-day series this
	 * replaces; a MySQL session on a different zone would misalign either version.
	 */
	private const BUCKETS = [
		'hour'  => ['%Y-%m-%d %H:00', 'Y-m-d H:00', '+1 hour'],
		'day'   => ['%Y-%m-%d', 'Y-m-d', '+1 day'],
		'month' => ['%Y-%m', 'Y-m', '+1 month'],
	];

	/**
	 * Upload/download totals between two timestamps, bucketed by hour/day/week/month (pt 5).
	 *
	 * This is what lets the dashboard chart offer selectable ranges the way a price chart does:
	 * the same data, read at whatever resolution the chosen span deserves.
	 */
	public static function seriesRange(int $from, int $to, string $bucket = 'day'): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return [];
		}
		if (!isset(self::BUCKETS[$bucket])) {
			$bucket = 'day';
		}
		[$sqlFmt, $phpFmt, $stepExpr] = self::BUCKETS[$bucket];
		if ($to <= $from) {
			$to = $from + 1;
		}

		$table = Database::table('traffic_logs');
		try {
			// The shift keeps these slot keys on the same clock as the PHP-generated ones below;
			// without it the two disagree whenever MySQL and PHP sit in different timezones,
			// and the newest bucket lands under a label the chart never draws.
			$stmt = $pdo->prepare("SELECT FROM_UNIXTIME(`created_at` + ?, ?) AS slot, `transfer_type`, SUM(`transfer_size`) AS bytes
				FROM `{$table}` WHERE `created_at` >= ? AND `created_at` <= ? GROUP BY slot, `transfer_type`");
			$stmt->execute([Database::tzShiftSeconds(), $sqlFmt, $from, $to]);

			// Pre-seed every slot in the range so quiet periods render as zero-height bars. The
			// first cursor is the *start* of the bucket $from falls in, so the leading bar is a
			// whole one rather than a stub offset by however far into the hour the call landed.
			$slots = [];
			$anchor = $bucket === 'month' ? date('Y-m-01', $from) : date($phpFmt, $from);
			$cursor = strtotime($anchor) ?: $from;
			$guard = 0;
			while ($cursor <= $to && $guard++ < 2000) {
				$key = date($phpFmt, $cursor);
				$slots[$key] = ['date' => $key, 'upload' => 0, 'download' => 0];
				$next = strtotime($stepExpr, $cursor);
				if (!$next || $next <= $cursor) {
					break;
				}
				$cursor = $next;
			}

			while ($row = $stmt->fetch()) {
				$slot = (string) $row['slot'];
				if (isset($slots[$slot]) && in_array($row['transfer_type'], ['upload', 'download'], true)) {
					$slots[$slot][$row['transfer_type']] += (int) $row['bytes'];
				}
			}
			if ($bucket !== 'hour') {
				$daily = Database::table('traffic_daily');
				$dailyFormat = $bucket === 'month' ? '%Y-%m' : '%Y-%m-%d';
				$aggregate = $pdo->prepare(
					"SELECT DATE_FORMAT(`day`, ?) AS slot, `transfer_type`,
					        SUM(`transfer_size`) AS bytes
					 FROM `{$daily}` WHERE `day` >= ? AND `day` <= ?
					 GROUP BY slot, `transfer_type`"
				);
				$aggregate->execute([
					$dailyFormat,
					date('Y-m-d', $from),
					date('Y-m-d', $to),
				]);
				while ($row = $aggregate->fetch(PDO::FETCH_ASSOC)) {
					$slot = (string) $row['slot'];
					if (isset($slots[$slot])
						&& in_array($row['transfer_type'], ['upload', 'download'], true)) {
						$slots[$slot][$row['transfer_type']] += (int) $row['bytes'];
					}
				}
			}
			return array_values($slots);
		} catch (PDOException $e) {
			return [];
		}
	}

	/**
	 * Most-downloaded files (dashboard widget).
	 *
	 * With no window this reads the all-time counter on the file row — one cheap indexed
	 * sort, and exact even for downloads that predate traffic logging. With a window it
	 * counts download events in traffic_logs instead, which is the only place the *when*
	 * of a download is recorded; files with no logged download in the window drop out.
	 */
	public static function topFiles(int $limit = 5, ?int $from = null, ?int $to = null): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return [];
		}
		$files = Database::table('files');
		$limit = max(1, min(50, $limit));

		try {
			if ($from === null && $to === null) {
				$stmt = $pdo->query("SELECT `id`, `original_name`, `size`, `downloads` FROM `{$files}` ORDER BY `downloads` DESC LIMIT {$limit}");
				return $stmt->fetchAll(PDO::FETCH_ASSOC);
			}

			$logs = Database::table('traffic_logs');
			$where = ["t.`transfer_type` = 'download'", "t.`file_id` IS NOT NULL"];
			$params = [];
			if ($from !== null) {
				$where[] = 't.`created_at` >= ?';
				$params[] = $from;
			}
			if ($to !== null) {
				$where[] = 't.`created_at` <= ?';
				$params[] = $to;
			}

			$sql = "SELECT f.`id`, f.`original_name`, f.`size`, COUNT(*) AS `downloads`
					FROM `{$logs}` t
					JOIN `{$files}` f ON f.`id` = t.`file_id`
					WHERE " . implode(' AND ', $where) . "
					GROUP BY f.`id`, f.`original_name`, f.`size`
					ORDER BY `downloads` DESC
					LIMIT {$limit}";
			$stmt = $pdo->prepare($sql);
			$stmt->execute($params);
			return $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (PDOException $e) {
			return [];
		}
	}

	/** IPs whose total transfer over the window exceeds $thresholdBytes (abuse heuristic). */
	public static function suspiciousIPs(int $thresholdBytes, int $hours = 24): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return [];
		}
		$table = Database::table('traffic_logs');
		$startTime = time() - ($hours * 3600);
		try {
			$stmt = $pdo->prepare("SELECT `ip_address`, SUM(`transfer_size`) as total_traffic, COUNT(*) as request_count FROM `{$table}` WHERE `created_at` > ? GROUP BY `ip_address` HAVING total_traffic > ? ORDER BY total_traffic DESC");
			$stmt->execute([$startTime, $thresholdBytes]);
			return $stmt->fetchAll();
		} catch (PDOException $e) {
			return [];
		}
	}

	/**
	 * Move one complete old detail day into the durable aggregate, then prune it atomically.
	 *
	 * One day per cleanup run bounds lock time on a busy installation while guaranteeing a crash
	 * can never leave the aggregate published without removing exactly the rows it represents.
	 *
	 * @return array{aggregated:int,deleted:int,daily_pruned:int}
	 */
	public static function aggregateAndPrune(int $detailDays = 30, int $dailyDays = 730): array
	{
		$out = ['aggregated' => 0, 'deleted' => 0, 'daily_pruned' => 0];
		$pdo = Database::getInstance();
		if (!$pdo) {
			return $out;
		}
		$logs = Database::table('traffic_logs');
		$daily = Database::table('traffic_daily');
		$detailCutoff = strtotime('today') - max(1, $detailDays) * 86400;
		$shift = Database::tzShiftSeconds();
		try {
			$pdo->beginTransaction();
			$oldest = $pdo->prepare(
				"SELECT MIN(DATE(FROM_UNIXTIME(`created_at` + ?)))
				 FROM `{$logs}` WHERE `created_at` < ?"
			);
			$oldest->execute([$shift, $detailCutoff]);
			$day = $oldest->fetchColumn();
			if ($day) {
				$aggregate = $pdo->prepare(
					"INSERT INTO `{$daily}`
					 (`day`, `transfer_type`, `transfer_size`, `transfer_count`)
					 SELECT ?, `transfer_type`, SUM(`transfer_size`), COUNT(*)
					 FROM `{$logs}`
					 WHERE DATE(FROM_UNIXTIME(`created_at` + ?)) = ?
					 GROUP BY `transfer_type`
					 ON DUPLICATE KEY UPDATE
					  `transfer_size` = VALUES(`transfer_size`),
					  `transfer_count` = VALUES(`transfer_count`)"
				);
				$aggregate->execute([$day, $shift, $day]);
				$out['aggregated'] = $aggregate->rowCount();
				$delete = $pdo->prepare(
					"DELETE FROM `{$logs}` WHERE DATE(FROM_UNIXTIME(`created_at` + ?)) = ?"
				);
				$delete->execute([$shift, $day]);
				$out['deleted'] = $delete->rowCount();
			}
			$prune = $pdo->prepare("DELETE FROM `{$daily}` WHERE `day` < ?");
			$prune->execute([date('Y-m-d', strtotime('today') - max(30, $dailyDays) * 86400)]);
			$out['daily_pruned'] = $prune->rowCount();
			$pdo->commit();
			return $out;
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return $out;
		}
	}
}
