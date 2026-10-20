<?php
require_once __DIR__ . '/../ClientIp.php';

/**
 * The transfer allowance: read for the account pages, and charged for the bytes PHP serves
 * itself (previews and embeds). upload_server.py charges its own downloads to the same rows.
 *
 * Accounts are counted per user, guests per network — the throttling key, so an IPv6 guest is
 * one subject per /64 rather than one per address it can pick freely. Both sides must use the
 * same subjects and the same UTC period windows.
 */
final class TransferQuotaRepository
{
	/** @return array{start:int,end:int} */
	public static function periodWindow(string $period, ?int $now = null): array
	{
		$current = (new DateTimeImmutable('@' . ($now ?? time())))->setTimezone(new DateTimeZone('UTC'));
		switch ($period) {
			case 'day':
				$start = $current->setTime(0, 0);
				$end = $start->modify('+1 day');
				break;
			case 'month':
				$start = $current->modify('first day of this month')->setTime(0, 0);
				$end = $start->modify('+1 month');
				break;
			case 'year':
				$start = $current->setDate((int) $current->format('Y'), 1, 1)->setTime(0, 0);
				$end = $start->modify('+1 year');
				break;
			default:
				$period = 'week';
				$start = $current->modify('monday this week')->setTime(0, 0);
				$end = $start->modify('+1 week');
		}
		return ['start' => $start->getTimestamp(), 'end' => $end->getTimestamp()];
	}

	/** @return array{limit:int,used:int,reserved:int,period:string,resets_at:int} */
	public static function forUser(int $userId, ?array $group): array
	{
		return self::forSubject('user', (string) $userId, $group);
	}

	/** The row a downloader is charged to: [subject_type, subject_key]. */
	private static function subject(?int $userId, string $ip): array
	{
		return $userId
			? ['user', (string) $userId]
			: ['ip', filehostThrottleKey($ip)];
	}

	/**
	 * Would $bytes more push this downloader past the allowance upload_server.py enforces?
	 */
	public static function wouldExceed(?int $userId, string $ip, ?array $group, int $bytes): bool
	{
		[$type, $key] = self::subject($userId, $ip);
		$state = self::forSubject($type, $key, $group);
		return $state['limit'] > 0 && $state['used'] + $state['reserved'] + max(0, $bytes) > $state['limit'];
	}

	/**
	 * Reserve $bytes of this downloader's allowance before PHP starts sending them.
	 *
	 * The check and the reservation are one locked step, so parallel previews cannot each see
	 * room for themselves and overshoot together. Returns null when the allowance cannot cover
	 * the bytes, an empty array when no allowance applies (or there is nothing to charge), and
	 * otherwise the reservation, to grow with extend() and to settle with commit().
	 *
	 * @return array{subject_type:string,subject_key:string,period:string,period_start:int,limit:int,reserved_bytes:int}|array{}|null
	 */
	public static function reserve(?int $userId, string $ip, ?array $group, int $bytes): ?array
	{
		$limit = max(0, (int) ($group['transfer_quota_bytes'] ?? 0));
		$bytes = max(0, $bytes);
		if ($limit <= 0 || $bytes <= 0) {
			return [];
		}
		$period = (string) ($group['transfer_quota_period'] ?? 'week');
		if (!in_array($period, ['day', 'week', 'month', 'year'], true)) {
			$period = 'week';
		}
		[$type, $key] = self::subject($userId, $ip);
		$reservation = [
			'subject_type' => $type,
			'subject_key' => $key,
			'period' => $period,
			'period_start' => self::periodWindow($period)['start'],
			'limit' => $limit,
			'reserved_bytes' => 0,
		];
		return self::extend($reservation, $bytes) ? $reservation : null;
	}

	/**
	 * Add $bytes to a reservation, if the allowance still covers them. A long stream grows its
	 * reservation step by step instead of holding the whole file from the first byte: a worker
	 * that dies mid-stream (and never settles) then strands at most one step, not the file.
	 */
	public static function extend(array &$reservation, int $bytes): bool
	{
		$bytes = max(0, $bytes);
		if ($bytes === 0) {
			return true;
		}
		$pdo = Database::getInstance();
		if (!$pdo || !isset($reservation['subject_type'], $reservation['subject_key'], $reservation['period'], $reservation['period_start'], $reservation['limit'])) {
			return false;
		}
		$table = Database::table('transfer_quota_usage');
		$where = [
			(string) $reservation['subject_type'],
			(string) $reservation['subject_key'],
			(string) $reservation['period'],
			(int) $reservation['period_start'],
		];
		$now = time();
		$ownsTransaction = !$pdo->inTransaction();
		try {
			if ($ownsTransaction) {
				$pdo->beginTransaction();
			}
			$pdo->prepare(
				"INSERT INTO `{$table}` (`subject_type`, `subject_key`, `period`, `period_start`,
				  `used_bytes`, `reserved_bytes`, `updated_at`)
				 VALUES (?, ?, ?, ?, 0, 0, ?)
				 ON DUPLICATE KEY UPDATE `updated_at` = VALUES(`updated_at`)"
			)->execute([...$where, $now]);
			$row = $pdo->prepare(
				"SELECT `used_bytes`, `reserved_bytes` FROM `{$table}`
				 WHERE `subject_type` = ? AND `subject_key` = ? AND `period` = ? AND `period_start` = ?
				 FOR UPDATE"
			);
			$row->execute($where);
			$current = $row->fetch(PDO::FETCH_ASSOC) ?: [];
			$used = max(0, (int) ($current['used_bytes'] ?? 0));
			$reserved = max(0, (int) ($current['reserved_bytes'] ?? 0));
			if ($used + $reserved + $bytes > (int) $reservation['limit']) {
				if ($ownsTransaction) {
					$pdo->rollBack();
				}
				return false;
			}
			$pdo->prepare(
				"UPDATE `{$table}` SET `reserved_bytes` = `reserved_bytes` + ?, `updated_at` = ?
				 WHERE `subject_type` = ? AND `subject_key` = ? AND `period` = ? AND `period_start` = ?"
			)->execute([$bytes, $now, ...$where]);
			if ($ownsTransaction) {
				$pdo->commit();
			}
		} catch (Throwable $e) {
			if ($ownsTransaction && $pdo->inTransaction()) {
				$pdo->rollBack();
			}
			error_log('Transfer allowance reservation failed: ' . $e->getMessage());
			return false;
		}
		$reservation['reserved_bytes'] = (int) ($reservation['reserved_bytes'] ?? 0) + $bytes;
		return true;
	}

	/**
	 * Settle a reservation: release it and charge the bytes actually sent (never more than
	 * were reserved). Charged to the period the reservation was made in, even when a stream
	 * crosses into the next one — exactly as the upload server settles its own.
	 */
	public static function commit(array $reservation, int $sentBytes): void
	{
		if (!isset($reservation['subject_type'], $reservation['subject_key'], $reservation['period'], $reservation['period_start'])) {
			return;
		}
		$pdo = Database::getInstance();
		if (!$pdo) {
			return;
		}
		$reserved = max(0, (int) ($reservation['reserved_bytes'] ?? 0));
		$charged = min(max(0, $sentBytes), $reserved);
		try {
			$pdo->prepare(
				"UPDATE `" . Database::table('transfer_quota_usage') . "`
				 SET `reserved_bytes` = IF(`reserved_bytes` > ?, `reserved_bytes` - ?, 0),
				     `used_bytes` = `used_bytes` + ?, `updated_at` = ?
				 WHERE `subject_type` = ? AND `subject_key` = ? AND `period` = ? AND `period_start` = ?"
			)->execute([
				$reserved,
				$reserved,
				$charged,
				time(),
				(string) $reservation['subject_type'],
				(string) $reservation['subject_key'],
				(string) $reservation['period'],
				(int) $reservation['period_start'],
			]);
		} catch (PDOException $e) {
			error_log('Transfer allowance settlement failed: ' . $e->getMessage());
		}
	}

	/** @return array{limit:int,used:int,reserved:int,period:string,resets_at:int} */
	private static function forSubject(string $subjectType, string $subjectKey, ?array $group): array
	{
		$limit = max(0, (int) ($group['transfer_quota_bytes'] ?? 0));
		$period = (string) ($group['transfer_quota_period'] ?? 'week');
		if (!in_array($period, ['day', 'week', 'month', 'year'], true)) {
			$period = 'week';
		}
		$window = self::periodWindow($period);
		$used = 0;
		$reserved = 0;
		$pdo = Database::getInstance();
		if ($pdo && $limit > 0) {
			try {
				$stmt = $pdo->prepare(
					"SELECT `used_bytes`, `reserved_bytes` FROM `"
					. Database::table('transfer_quota_usage')
					. "` WHERE `subject_type` = ? AND `subject_key` = ?
					    AND `period` = ? AND `period_start` = ?"
				);
				$stmt->execute([$subjectType, $subjectKey, $period, $window['start']]);
				$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
				$used = max(0, (int) ($row['used_bytes'] ?? 0));
				$reserved = max(0, (int) ($row['reserved_bytes'] ?? 0));
			} catch (PDOException $e) {
				// A status widget must not make the account page unavailable.
			}
		}
		return [
			'limit' => $limit,
			'used' => $used,
			'reserved' => $reserved,
			'period' => $period,
			'resets_at' => $window['end'],
		];
	}
}
