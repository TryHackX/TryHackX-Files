<?php
/**
 * StorageEnforcer — what happens to files a user is no longer allowed to keep (pkt B).
 *
 * Group limits can shrink under an account: an admin moves someone to a smaller group, or a
 * paid plan lapses and GroupRepository::forUser drops them back to the default. Nothing about
 * the *past* uploads changes at that moment, so an account can end up over its quota, or
 * holding files bigger than its new per-file limit.
 *
 * The other limits need no help, and it is worth being precise about why:
 *
 *   - **Upload size / quota / files per session** are checked before a byte is written
 *     (upload_server.py), so a shrunken limit bites on the very next upload. Being over quota
 *     already blocks new uploads — it is a leftover, never a way to gain storage.
 *   - **Speed limits** are applied per request, so a transfer already in flight keeps the rate
 *     it started with and the next one gets the new rate. Nothing is throttled mid-stream.
 *   - **Concurrent downloads / connections** are counted when a download *starts*
 *     (FileManager::streamFile). Dropping the limit from 8 to 4 does not kill four running
 *     transfers; it refuses new ones until the count is back under the limit. There is nothing
 *     to gain by starting downloads ahead of a downgrade — they finish, and then the cap holds.
 *
 * That leaves stored files, which is what this class handles. The policy is deliberately slow
 * and visible:
 *
 *   1. The account is checked; if it is within its limits the clock is cleared and nothing
 *      happens.
 *   2. The first time it is found over, `users.limits_over_since` is stamped. The user is told
 *      what is wrong and when it will be acted on.
 *   3. Once `storage_grace_days` have passed, files are removed until the account fits again:
 *      first the ones that break the per-file size limit (largest first — the clearest
 *      violation), then the oldest, until the quota is met.
 *
 * Every deletion is written to the audit log. Nothing here is reachable from a request a user
 * controls, so there is no way to trigger someone else's cleanup.
 */
final class StorageEnforcer
{
	/** Grace period, in days, before an over-limit account starts losing files. */
	public static function graceDays(): int
	{
		return max(0, (int) Database::getSetting('storage_grace_days', 15));
	}

	public static function enabled(): bool
	{
		return Database::getSetting('storage_enforce', '1') === '1';
	}

	/**
	 * The effective storage limits for a user: their per-account override or the group's.
	 *
	 * @return array{quota: int, maxFile: int} both in bytes; 0 means "no limit"
	 */
	public static function limitsFor(int $userId): array
	{
		$user = Database::getUserById($userId);
		$group = Database::getUserEffectiveGroup($userId);

		// users.storage_limit (bytes) is the per-account override and wins when set, matching
		// get_user_quota() in upload_server.py.
		$quota = (int) ($user['storage_limit'] ?? 0);
		if ($quota <= 0) {
			$quota = ((int) ($group['storage_quota_mb'] ?? 0)) * 1024 * 1024;
		}

		$maxFile = ((int) ($group['max_file_size_mb'] ?? 0)) * 1024 * 1024;
		if ($maxFile <= 0) {
			$maxFile = ((int) Database::getSetting('system_max_file_size_mb', 5120)) * 1024 * 1024;
		}

		return ['quota' => max(0, $quota), 'maxFile' => max(0, $maxFile)];
	}

	/**
	 * How the account currently stands. Read-only — safe to call on any page render.
	 *
	 * @return array{
	 *   over: bool, used: int, quota: int, maxFile: int, overBy: int,
	 *   oversize: array<int, array{id: string, name: string, size: int}>,
	 *   since: ?int, deadline: ?int
	 * }
	 */
	public static function status(int $userId): array
	{
		$limits = self::limitsFor($userId);
		$used = 0;
		$oversize = [];

		$pdo = Database::getInstance();
		if ($pdo) {
			$files = Database::table('files');
			try {
				$stmt = $pdo->prepare(
					"SELECT COALESCE(SUM(`size`), 0) FROM `{$files}` WHERE `user_id` = ?"
				);
				$stmt->execute([$userId]);
				$used = (int) $stmt->fetchColumn();
				if ($limits['maxFile'] > 0) {
					$stmt = $pdo->prepare(
						"SELECT `id`, `original_name`, `size` FROM `{$files}`
						 WHERE `user_id` = ? AND `size` > ?
						 ORDER BY `size` DESC, `id` ASC LIMIT 500"
					);
					$stmt->execute([$userId, $limits['maxFile']]);
					foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
						$oversize[] = [
							'id' => $row['id'],
							'name' => $row['original_name'],
							'size' => (int) $row['size'],
						];
					}
				}
			} catch (PDOException $e) {
				// Treat an unreadable account as compliant — never delete on a failed read.
				return self::compliant($limits);
			}
		}

		$overQuota = $limits['quota'] > 0 && $used > $limits['quota'];
		$over = $overQuota || $oversize !== [];

		$user = Database::getUserById($userId);
		$since = isset($user['limits_over_since']) ? (int) $user['limits_over_since'] : 0;

		return [
			'over' => $over,
			'used' => $used,
			'quota' => $limits['quota'],
			'maxFile' => $limits['maxFile'],
			'overBy' => $overQuota ? ($used - $limits['quota']) : 0,
			'oversize' => $oversize,
			'since' => $since ?: null,
			'deadline' => ($over && $since) ? $since + (self::graceDays() * 86400) : null,
		];
	}

	private static function compliant(array $limits): array
	{
		return [
			'over' => false, 'used' => 0, 'quota' => $limits['quota'], 'maxFile' => $limits['maxFile'],
			'overBy' => 0, 'oversize' => [], 'since' => null, 'deadline' => null,
		];
	}

	/**
	 * Bring one account back into line, if its grace has run out.
	 *
	 * Starts (or clears) the clock as a side effect, so calling this regularly is what keeps
	 * `limits_over_since` honest. Returns what it did.
	 *
	 * @return array{state: string, deleted: int, freed: int, deadline: ?int}
	 */
	public static function enforce(int $userId): array
	{
		$status = self::status($userId);

		if (!$status['over']) {
			self::setOverSince($userId, null);
			return ['state' => 'ok', 'deleted' => 0, 'freed' => 0, 'deadline' => null];
		}
		if (!self::enabled()) {
			return ['state' => 'disabled', 'deleted' => 0, 'freed' => 0, 'deadline' => null];
		}

		// First sighting: start the clock and warn, delete nothing.
		if (!$status['since']) {
			$now = time();
			self::setOverSince($userId, $now);
			Database::logAudit(
				'storage_over_limit',
				sprintf('user #%d over limits (used %d B, quota %d B, %d oversize)',
					$userId, $status['used'], $status['quota'], count($status['oversize'])),
				$userId
			);
			$deadline = $now + (self::graceDays() * 86400);
			// The grace period only means anything if the account knows it started. Once per
			// stretch of being over: `limits_over_since` is cleared the moment they get back
			// under, so going over again later is genuinely new and says so again.
			Notifications::send($userId, 'storage.quota', [
				'data' => ['date' => date('d.m.Y', $deadline), 'days' => self::graceDays()],
				'group' => 'storage.quota:' . $now,
				'once' => true,
				'link' => (defined('APP_URL') ? APP_URL : '') . '/panel.php?tab=myfiles',
			]);
			return ['state' => 'grace', 'deleted' => 0, 'freed' => 0,
				'deadline' => $deadline];
		}

		if (time() < $status['since'] + (self::graceDays() * 86400)) {
			return ['state' => 'grace', 'deleted' => 0, 'freed' => 0, 'deadline' => $status['deadline']];
		}

		return self::reclaim($userId, $status);
	}

	/**
	 * Delete until the account fits: the files that break the per-file limit first (largest
	 * first), then the oldest, until the quota is met.
	 */
	private static function reclaim(int $userId, array $status): array
	{
		$deleted = 0;
		$freed = 0;

		// 1. Files that are simply not allowed at this size any more.
		$oversize = $status['oversize'];
		usort($oversize, fn($a, $b) => $b['size'] <=> $a['size']);
		foreach ($oversize as $file) {
			if (FileManager::deleteForStoragePolicy($file['id'], $userId)) {
				$deleted++;
				$freed += $file['size'];
				Database::logAudit(
					'storage_file_removed',
					sprintf('user #%d: "%s" (%d B) exceeds the per-file limit of %d B',
						$userId, $file['name'], $file['size'], $status['maxFile']),
					$userId
				);
			}
		}

		// 2. Oldest first, until the quota is met.
		$used = $status['used'] - $freed;
		if ($status['quota'] > 0 && $used > $status['quota']) {
			$pdo = Database::getInstance();
			$files = Database::table('files');
			$removedIds = array_column($oversize, 'id');
			try {
				$stmt = $pdo->prepare("SELECT `id`, `original_name`, `size` FROM `{$files}`
					WHERE `user_id` = ? ORDER BY `uploaded_at` ASC, `id` ASC LIMIT 500");
				$stmt->execute([$userId]);
				foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
					if ($used <= $status['quota']) {
						break;
					}
					if (in_array($row['id'], $removedIds, true)) {
						continue; // already gone in step 1
					}
					if (FileManager::deleteForStoragePolicy($row['id'], $userId)) {
						$deleted++;
						$freed += (int) $row['size'];
						$used -= (int) $row['size'];
						Database::logAudit(
							'storage_file_removed',
							sprintf('user #%d: "%s" (%d B) removed to meet the %d B quota',
								$userId, $row['original_name'], (int) $row['size'], $status['quota']),
							$userId
						);
					}
				}
			} catch (PDOException $e) {
				// Partial reclaim is fine — the next sweep picks up where this stopped.
			}
		}

		// Re-check rather than assume: if something could not be deleted the clock stays on.
		$after = self::status($userId);
		self::setOverSince($userId, $after['over'] ? ($status['since'] ?: time()) : null);

		return ['state' => 'enforced', 'deleted' => $deleted, 'freed' => $freed, 'deadline' => null];
	}

	private static function setOverSince(int $userId, ?int $ts): void
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return;
		}
		try {
			$stmt = $pdo->prepare("UPDATE `" . Database::table('users') . "` SET `limits_over_since` = ? WHERE `id` = ?");
			$stmt->execute([$ts, $userId]);
		} catch (PDOException $e) {
			// Not fatal: the next run re-derives the state from the files themselves.
		}
	}

	/**
	 * Run over every account that has files (cron). Bounded work per user — one query for the
	 * file list, deletions only for accounts whose grace has actually expired.
	 *
	 * @return array{checked: int, grace: int, enforced: int, deleted: int, freed: int}
	 */
	public static function sweep(): array
	{
		$out = ['checked' => 0, 'grace' => 0, 'enforced' => 0, 'deleted' => 0, 'freed' => 0];
		$pdo = Database::getInstance();
		if (!$pdo) {
			return $out;
		}

		try {
			// Accounts that either hold files or are already on the clock. A persisted keyset
			// cursor bounds each cron run without starving high-id accounts.
			$users = Database::table('users');
			$files = Database::table('files');
			$cursor = max(0, (int) Database::getSetting('storage_sweep_cursor', 0));
			$sql = "SELECT DISTINCT u.`id` FROM `{$users}` u
				LEFT JOIN `{$files}` f ON f.`user_id` = u.`id`
				WHERE u.`id` > ? AND (f.`id` IS NOT NULL OR u.`limits_over_since` IS NOT NULL)
				ORDER BY u.`id` ASC LIMIT 500";
			$stmt = $pdo->prepare($sql);
			$stmt->execute([$cursor]);
			$ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
			if ($ids === [] && $cursor > 0) {
				$cursor = 0;
				$stmt->execute([$cursor]);
				$ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
			}
		} catch (PDOException $e) {
			return $out;
		}

		foreach ($ids as $id) {
			$res = self::enforce((int) $id);
			$out['checked']++;
			if ($res['state'] === 'grace') {
				$out['grace']++;
			} elseif ($res['state'] === 'enforced') {
				$out['enforced']++;
				$out['deleted'] += $res['deleted'];
				$out['freed'] += $res['freed'];
			}
		}
		if ($ids !== []) {
			Database::setSetting('storage_sweep_cursor', (string) max(array_map('intval', $ids)));
		}
		return $out;
	}
}
