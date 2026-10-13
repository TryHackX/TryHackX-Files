<?php

/** Read-side representation of the transfer allowance enforced by upload_server.py. */
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

	/**
	 * Would $bytes more push this downloader past the allowance upload_server.py enforces?
	 * Accounts are counted per user, guests per address — the same subjects the sidecar uses.
	 */
	public static function wouldExceed(?int $userId, string $ip, ?array $group, int $bytes): bool
	{
		$state = $userId
			? self::forSubject('user', (string) $userId, $group)
			: self::forSubject('ip', $ip, $group);
		return $state['limit'] > 0 && $state['used'] + $state['reserved'] + max(0, $bytes) > $state['limit'];
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
