<?php
/**
 * GroupRepository (Faza 5 · #2, extended Faza 6 · #1).
 *
 * User groups — named limit *and permission* profiles. Three groups are structural and cannot
 * be deleted (`is_system`):
 *
 *   - `guest` — everyone without an account. It has no members and no permissions; it exists
 *     so guest limits are edited in the same place as everyone else's (the Settings → Limity
 *     tab it replaced is gone).
 *   - `user`  — the default for registered users. A user with group_id = NULL resolves here.
 *   - `moderator` — a complete permission and limit profile attached automatically to the
 *     moderator role. It is additive to group_id, so a moderator can independently hold a
 *     paid plan; enforcement chooses the more favourable allowance from both profiles.
 *
 * The flat `guest_*` / `user_*` settings are still the values PHP's config constants and the
 * Python upload server read, so saving either system group mirrors its numbers back into them
 * (see syncSystemSettings). Groups are the UI; those settings remain the transport.
 */
final class GroupRepository
{
	/** Column names carrying a group's numeric limits (used for sanitising input). */
	private const LIMIT_FIELDS = [
		'max_file_size_mb', 'max_files_per_session', 'storage_quota_mb',
		'limit_upload', 'limit_download', 'concurrent_downloads', 'concurrent_connections_per_file',
		'transfer_quota_bytes',
	];

	/** `auto_delete_days` = -1: this group's files are never deleted for age (pt 6). */
	public const RETENTION_NEVER = -1;

	/**
	 * Which flat setting each limit column feeds, per system group. Saving the `guest` group
	 * writes the guest keys, the `user` group the user keys — keeping config.php's constants
	 * and upload_server.py's cache in step without either having to learn about groups.
	 */
	private const SETTING_MIRROR = [
		'guest' => [
			'max_file_size_mb'                => 'guest_max_file_size_mb',
			'max_files_per_session'           => 'guest_max_files',
			'limit_upload'                    => 'limit_upload_guest',
			'limit_download'                  => 'limit_download_guest',
			'concurrent_downloads'            => 'concurrent_downloads_guest',
			'concurrent_connections_per_file' => 'concurrent_connections_file_guest',
		],
		'user' => [
			'max_file_size_mb'                => 'user_max_file_size_mb',
			'max_files_per_session'           => 'user_max_files',
			'limit_upload'                    => 'limit_upload_user',
			'limit_download'                  => 'limit_download_user',
			'concurrent_downloads'            => 'concurrent_downloads_user',
			'concurrent_connections_per_file' => 'concurrent_connections_file_user',
		],
	];

	/** All groups with a live member count, system groups first, then default, then alphabetical. */
	public static function all(): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return [];
		}
		$g = Database::table('groups');
		$u = Database::table('users');
		try {
			// Role-bound/system groups first, then the operator-created plan groups.
			$sql = "SELECT g.*,
					 (SELECT COUNT(*) FROM `{$u}` uu WHERE uu.group_id = g.id) AS members,
					 (SELECT COUNT(*) FROM `{$u}` su
					  WHERE su.staff_group_id = g.id AND su.role = 'moderator') AS staff_members
					FROM `{$g}` g
					ORDER BY (g.`slug` = 'guest') DESC, (g.`slug` = 'user') DESC,
					         (g.`slug` = 'moderator') DESC, g.`is_default` DESC, g.`name` ASC";
			return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
		} catch (PDOException $e) {
			return [];
		}
	}

	public static function getById(int $id): ?array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return null;
		}
		try {
			$stmt = $pdo->prepare("SELECT * FROM `" . Database::table('groups') . "` WHERE `id` = ?");
			$stmt->execute([$id]);
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			return $row ?: null;
		} catch (PDOException $e) {
			return null;
		}
	}

	/** A system group by its slug ('guest' / 'user' / 'moderator'). */
	public static function getBySlug(string $slug): ?array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return null;
		}
		try {
			$stmt = $pdo->prepare("SELECT * FROM `" . Database::table('groups') . "` WHERE `slug` = ?");
			$stmt->execute([$slug]);
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			return $row ?: null;
		} catch (PDOException $e) {
			return null;
		}
	}

	public static function getDefault(): ?array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return null;
		}
		$g = Database::table('groups');
		try {
			// Anonymous and role-bound groups are never account-plan fallbacks.
			$row = $pdo->query("SELECT * FROM `{$g}` WHERE `is_default` = 1 AND (`slug` IS NULL OR `slug` NOT IN ('guest', 'moderator')) ORDER BY `id` ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
			if (!$row) {
				$row = $pdo->query("SELECT * FROM `{$g}` WHERE (`slug` IS NULL OR `slug` NOT IN ('guest', 'moderator')) ORDER BY `id` ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
			}
			return $row ?: null;
		} catch (PDOException $e) {
			return null;
		}
	}

	/**
	 * Resolve the effective group for a registered user: their assigned group, or
	 * the default group when group_id is NULL or points at a deleted group.
	 *
	 * pt 9: an assignment bought through a plan carries `group_expires_at`. A lapsed one is
	 * dropped here, on the next read — so a subscription ends on time without anything having
	 * to run on a schedule, and a cron that never fires cannot leave paid access standing.
	 * The row is cleared as well as ignored, so the state on disk matches what is enforced.
	 */
	public static function forUser(int $userId): ?array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return null;
		}
		$users = Database::table('users');
		try {
			$stmt = $pdo->prepare("SELECT `group_id`, `group_expires_at` FROM `{$users}` WHERE `id` = ?");
			$stmt->execute([$userId]);
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			$gid = $row['group_id'] ?? null;
			$expires = isset($row['group_expires_at']) ? (int) $row['group_expires_at'] : 0;

			if ($gid && $expires > 0 && $expires <= time()) {
				// pt 6: retention restarts here. The plan ended now, so the default group's
				// clock starts now — not at the upload date, which for a long-standing account
				// would mean everything disappearing on the day the subscription lapsed.
				//
				// This is a CAS, not a blind UPDATE. A payment may have extended or replaced
				// the assignment between the SELECT above and this write; clearing by id alone
				// would then erase access which has just been paid for.
				$clear = $pdo->prepare(
					"UPDATE `{$users}`
					 SET `group_id` = NULL, `group_expires_at` = NULL, `group_changed_at` = ?,
					     `group_payment_ext_order_id` = NULL
					 WHERE `id` = ? AND `group_id` = ? AND `group_expires_at` = ?"
				);
				$clear->execute([time(), $userId, (int) $gid, $expires]);
				if ($clear->rowCount() === 1) {
					Database::logAudit('plan_expired', 'user #' . $userId . ', group #' . (int) $gid, $userId);
					// This is the moment the account's limits actually change, so it is the
					// moment to say so. Keyed on the expiry timestamp: whichever request trips
					// the CAS first announces it; every other request loses the CAS.
					$expiredGroup = self::getById((int) $gid);
					Notifications::send($userId, 'plan.expired', [
						'subject' => (string) ($expiredGroup['name'] ?? ''),
						'group' => 'plan.expired:' . $expires,
						'once' => true,
						'link' => (defined('APP_URL') ? APP_URL : '') . '/premium',
					]);
					$gid = null;
				} else {
					// A concurrent grant won. Resolve the fresh assignment instead of using
					// the stale expired snapshot read above.
					$stmt->execute([$userId]);
					$fresh = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
					$gid = $fresh['group_id'] ?? null;
				}
			}

			if ($gid) {
				$grp = self::getById((int) $gid);
				if ($grp && !in_array(($grp['slug'] ?? null), ['guest', 'moderator'], true)) {
					return $grp;
				}
			}
			return self::getDefault();
		} catch (PDOException $e) {
			return self::getDefault();
		}
	}

	/**
	 * Resolve the role-bound Moderator system group.
	 *
	 * Unlike forUser(), this group never expires. It is an additive permission and limit source
	 * for every moderator, independent from the account's plan group.
	 */
	public static function staffForUser(int $userId): ?array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return null;
		}
		$users = Database::table('users');
		$groups = Database::table('groups');
		try {
			$stmt = $pdo->prepare(
				"SELECT g.* FROM `{$users}` u
				 JOIN `{$groups}` g ON g.`id` = u.`staff_group_id`
				  AND g.`slug` = 'moderator' AND g.`is_system` = 1
				 WHERE u.`id` = ? AND u.`role` = 'moderator'
				 LIMIT 1"
			);
			$stmt->execute([$userId]);
			return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
		} catch (PDOException $e) {
			return null;
		}
	}

	private static function betterUnlimitedLimit(int $primary, int $staff): int
	{
		// Zero means unlimited (or the installation-wide maximum for per-file size), so it is
		// always at least as good as a finite group value.
		if ($primary <= 0 || $staff <= 0) {
			return 0;
		}
		return max($primary, $staff);
	}

	private static function periodSeconds(string $period): int
	{
		return [
			'day' => 86400,
			'week' => 7 * 86400,
			'month' => 30 * 86400,
			'year' => 365 * 86400,
		][$period] ?? (7 * 86400);
	}

	/**
	 * Merge a plan/default group with the role-bound Moderator group.
	 *
	 * Finite limits choose the larger allowance. Zero is unlimited. Transfer allowances with
	 * different periods are compared as bytes/second and keep the winning period verbatim.
	 */
	public static function mergeLimits(?array $primary, ?array $staff): ?array
	{
		if (!$primary) {
			return $staff;
		}
		if (!$staff) {
			return $primary;
		}

		$effective = $primary;
		foreach (self::LIMIT_FIELDS as $field) {
			if ($field === 'transfer_quota_bytes') {
				continue;
			}
			$effective[$field] = self::betterUnlimitedLimit(
				max(0, (int) ($primary[$field] ?? 0)),
				max(0, (int) ($staff[$field] ?? 0))
			);
		}

		$primaryTransfer = max(0, (int) ($primary['transfer_quota_bytes'] ?? 0));
		$staffTransfer = max(0, (int) ($staff['transfer_quota_bytes'] ?? 0));
		$primaryPeriod = (string) ($primary['transfer_quota_period'] ?? 'week');
		$staffPeriod = (string) ($staff['transfer_quota_period'] ?? 'week');
		if ($primaryTransfer <= 0 || $staffTransfer <= 0) {
			$effective['transfer_quota_bytes'] = 0;
			$effective['transfer_quota_period'] = $primaryTransfer <= 0 ? $primaryPeriod : $staffPeriod;
		} elseif (($staffTransfer / self::periodSeconds($staffPeriod))
			> ($primaryTransfer / self::periodSeconds($primaryPeriod))) {
			$effective['transfer_quota_bytes'] = $staffTransfer;
			$effective['transfer_quota_period'] = $staffPeriod;
		} else {
			$effective['transfer_quota_bytes'] = $primaryTransfer;
			$effective['transfer_quota_period'] = $primaryPeriod;
		}

		$primaryRetention = self::retentionDays($primary);
		$staffRetention = self::retentionDays($staff);
		$effective['auto_delete_days'] = ($primaryRetention <= 0 || $staffRetention <= 0)
			? self::RETENTION_NEVER
			: max($primaryRetention, $staffRetention);
		$effective['limit_sources'] = array_values(array_filter([
			isset($primary['id']) ? (int) $primary['id'] : null,
			isset($staff['id']) ? (int) $staff['id'] : null,
		]));
		return $effective;
	}

	/** Effective account limits, preserving forUser() as the raw plan/default identity. */
	public static function effectiveForUser(int $userId): ?array
	{
		return self::mergeLimits(self::forUser($userId), self::staffForUser($userId));
	}

	/** Create (id = null) or update a group. Returns ['success'=>bool, 'id'=>int] or an error. */
	public static function save(?int $id, array $data): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return ['success' => false, 'error' => 'Database error'];
		}
		$g = Database::table('groups');

		$name = trim($data['name'] ?? '');
		if ($name === '' || mb_strlen($name) > 50) {
			return ['success' => false, 'error' => __('api.group_bad_name')];
		}

		$existing = $id ? self::getById($id) : null;
		if ($id && !$existing) {
			return ['success' => false, 'error' => __('api.group_missing')];
		}

		$v = [];
		foreach (self::LIMIT_FIELDS as $f) {
			$v[$f] = max(0, (int) ($data[$f] ?? 0));
		}
		$quotaPeriod = (string) ($data['transfer_quota_period'] ?? 'week');
		if (!in_array($quotaPeriod, ['day', 'week', 'month', 'year'], true)) {
			$quotaPeriod = 'week';
		}

		// pt 6: retention. Not a LIMIT_FIELD because -1 is meaningful here (never delete) and
		// those are clamped at 0. Capped at 100 years so a typo cannot overflow the date maths.
		$retention = (int) ($data['auto_delete_days'] ?? 0);
		$retention = $retention < 0 ? self::RETENTION_NEVER : min($retention, 36500);

		// No group may raise a per-file limit above the system-wide cap — the check the old
		// Settings → Limity tab used to run, now that groups are where limits are entered.
		// 0 means "use the system max", so it is always fine.
		$systemMax = (int) Database::getSetting('system_max_file_size_mb', 5120);
		if ($v['max_file_size_mb'] > $systemMax) {
			return ['success' => false, 'error' => __('api.group_over_system', ['system' => $systemMax])];
		}

		$isDefault = !empty($data['is_default']) ? 1 : 0;

		// Permissions arrive as an array of keys; normalize() drops unknown ones and any whose
		// parent permission is missing, so what lands in the DB is always self-consistent.
		$perms = $data['permissions'] ?? [];
		if (is_string($perms)) {
			$perms = explode(',', $perms);
		}
		// The guest group is for people without an account — permissions would have nothing to
		// attach to, so it never carries any.
		$isGuest = ($existing['slug'] ?? null) === 'guest';
		$isModerator = ($existing['slug'] ?? null) === 'moderator';
		if ($isModerator) {
			$isDefault = 0;
		}
		$permissions = $isGuest ? '' : Permissions::serialize((array) $perms);

		try {
			$stmt = $pdo->prepare("SELECT `id` FROM `{$g}` WHERE `name` = ? AND `id` <> ?");
			$stmt->execute([$name, $id ?? 0]);
			if ($stmt->fetch()) {
				return ['success' => false, 'error' => __('api.group_dup_name')];
			}

			if ($id) {
				$sql = "UPDATE `{$g}` SET `name`=?, `max_file_size_mb`=?, `max_files_per_session`=?, `storage_quota_mb`=?,
						`limit_upload`=?, `limit_download`=?, `concurrent_downloads`=?, `concurrent_connections_per_file`=?,
						`transfer_quota_bytes`=?, `transfer_quota_period`=?,
						`auto_delete_days`=?, `permissions`=? WHERE `id`=?";
				$pdo->prepare($sql)->execute([
					$name, $v['max_file_size_mb'], $v['max_files_per_session'], $v['storage_quota_mb'],
					$v['limit_upload'], $v['limit_download'], $v['concurrent_downloads'], $v['concurrent_connections_per_file'],
					$v['transfer_quota_bytes'], $quotaPeriod, $retention, $permissions, $id,
				]);
				$gid = $id;
			} else {
				$sql = "INSERT INTO `{$g}`
						(`name`,`max_file_size_mb`,`max_files_per_session`,`storage_quota_mb`,`limit_upload`,`limit_download`,`concurrent_downloads`,`concurrent_connections_per_file`,`transfer_quota_bytes`,`transfer_quota_period`,`auto_delete_days`,`permissions`,`is_default`,`created_at`)
						VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,?)";
				$pdo->prepare($sql)->execute([
					$name, $v['max_file_size_mb'], $v['max_files_per_session'], $v['storage_quota_mb'],
					$v['limit_upload'], $v['limit_download'], $v['concurrent_downloads'], $v['concurrent_connections_per_file'],
					$v['transfer_quota_bytes'], $quotaPeriod, $retention, $permissions, time(),
				]);
				$gid = (int) $pdo->lastInsertId();
			}

			// Enforce exactly one default group. Unchecking on the current default is ignored
			// (something must stay default), so switching happens by marking another group default.
			// Anonymous and role-bound groups can never become account-plan defaults.
			if ($isDefault && !$isGuest && !$isModerator) {
				$pdo->prepare("UPDATE `{$g}` SET `is_default` = IF(`id` = ?, 1, 0) WHERE `slug` IS NULL OR `slug` NOT IN ('guest', 'moderator')")->execute([$gid]);
			}

			// Keep the flat settings that PHP's constants and the Python server read in step.
			if (!empty($existing['slug'])) {
				self::syncSystemSettings((string) $existing['slug'], $v);
			}

			return ['success' => true, 'id' => $gid];
		} catch (PDOException $e) {
			return ['success' => false, 'error' => __('api.group_save_failed')];
		}
	}

	/**
	 * Mirror a system group's limits into the flat settings keys the rest of the stack reads
	 * (config.php constants, FileManager's concurrency check, upload_server.py's cache).
	 */
	private static function syncSystemSettings(string $slug, array $limits): void
	{
		$map = self::SETTING_MIRROR[$slug] ?? null;
		if (!$map) {
			return;
		}
		foreach ($map as $column => $settingKey) {
			Database::setSetting($settingKey, (int) ($limits[$column] ?? 0));
		}
		Database::invalidateSettingsCache();
	}

	public static function delete(int $id): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return ['success' => false, 'error' => 'Database error'];
		}
		$g = Database::table('groups');
		$u = Database::table('users');
		try {
			$ownsTransaction = !$pdo->inTransaction();
			if ($ownsTransaction) {
				$pdo->beginTransaction();
			}
			$lock = $pdo->prepare("SELECT * FROM `{$g}` WHERE `id` = ? FOR UPDATE");
			$lock->execute([$id]);
			$grp = $lock->fetch(PDO::FETCH_ASSOC);
			if (!$grp) {
				if ($ownsTransaction) {
					$pdo->rollBack();
				}
				return ['success' => false, 'error' => __('api.group_missing')];
			}
			// Guest and User are structural: the app resolves guests and unassigned users to
			// them, so there is no meaningful "deleted" state for either.
			if ((int) ($grp['is_system'] ?? 0) === 1) {
				if ($ownsTransaction) {
					$pdo->rollBack();
				}
				return ['success' => false, 'error' => __('api.group_system_locked')];
			}
			if ((int) $grp['is_default'] === 1) {
				if ($ownsTransaction) {
					$pdo->rollBack();
				}
				return ['success' => false, 'error' => __('api.group_default_locked')];
			}
			// Reassign members to NULL → they resolve to the default group.
			$pdo->prepare(
				"UPDATE `{$u}`
				 SET `group_id` = NULL, `group_expires_at` = NULL, `group_changed_at` = ?,
				     `group_payment_ext_order_id` = NULL
				 WHERE `group_id` = ?"
			)->execute([time(), $id]);
			$pdo->prepare(
				"UPDATE `{$u}` SET `staff_group_id` = NULL WHERE `staff_group_id` = ?"
			)->execute([$id]);
			$pdo->prepare("DELETE FROM `{$g}` WHERE `id` = ?")->execute([$id]);
			if ($ownsTransaction) {
				$pdo->commit();
			}
			return ['success' => true];
		} catch (Throwable $e) {
			if (isset($ownsTransaction) && $ownsTransaction && $pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return ['success' => false, 'error' => __('api.group_delete_failed')];
		}
	}

	/**
	 * Assign a user to a group (null = clear → resolves to default). Rejects unknown groups.
	 *
	 * pt 6: stamps `group_changed_at` when the assignment actually changes, because retention
	 * counts from that moment. Re-saving the same group is not a change and must not restart
	 * the clock, or repeatedly pressing Save would keep files alive indefinitely.
	 */
	public static function assignUser(int $userId, ?int $groupId): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		try {
			$ownsTransaction = !$pdo->inTransaction();
			if ($ownsTransaction) {
				$pdo->beginTransaction();
			}
			if ($groupId !== null) {
				$groups = Database::table('groups');
				$groupStmt = $pdo->prepare("SELECT `id`, `slug` FROM `{$groups}` WHERE `id` = ? FOR UPDATE");
				$groupStmt->execute([$groupId]);
				$grp = $groupStmt->fetch(PDO::FETCH_ASSOC);
				// Guest is anonymous-only; Moderator is assigned by role, not as a plan group.
				if (!$grp || in_array(($grp['slug'] ?? null), ['guest', 'moderator'], true)) {
					if ($ownsTransaction) {
						$pdo->rollBack();
					}
					return false;
				}
			}
			$users = Database::table('users');
			$stmt = $pdo->prepare(
				"SELECT `group_id`, `group_expires_at`, `group_payment_ext_order_id`
				 FROM `{$users}` WHERE `id` = ? FOR UPDATE"
			);
			$stmt->execute([$userId]);
			$current = $stmt->fetch(PDO::FETCH_ASSOC);
			if (!$current) {
				if ($ownsTransaction) {
					$pdo->rollBack();
				}
				return false;
			}
			$before = $current['group_id'] === null ? null : (int) $current['group_id'];

			if ($before === $groupId
				&& $current['group_expires_at'] === null
				&& $current['group_payment_ext_order_id'] === null) {
				if ($ownsTransaction) {
					$pdo->commit();
				}
				return true;
			}
			if ($before === $groupId) {
				// A manual assignment means "until changed by an operator", not "until the
				// expiry left behind by a previous paid plan".
				$pdo->prepare(
					"UPDATE `{$users}` SET `group_expires_at` = NULL,
					 `group_payment_ext_order_id` = NULL WHERE `id` = ?"
				)->execute([$userId]);
			} else {
				$pdo->prepare(
					"UPDATE `{$users}`
					 SET `group_id` = ?, `group_expires_at` = NULL, `group_changed_at` = ?,
					     `group_payment_ext_order_id` = NULL
					 WHERE `id` = ?"
				)->execute([$groupId, time(), $userId]);
			}
			if ($ownsTransaction) {
				$pdo->commit();
			}
			return true;
		} catch (Throwable $e) {
			if (isset($ownsTransaction) && $ownsTransaction && $pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return false;
		}
	}

	/**
	 * How long a group keeps its members' files, in days (pt 6). 0 = forever.
	 *
	 * Three states live in one column, so the operator can express all of "same as everyone
	 * else", "shorter/longer than everyone else" and "never" without a second control:
	 *   -1 → never delete, whatever the installation default says (what a premium tier sells);
	 *    0 → follow the installation default in Settings → Storage (`auto_delete_days`);
	 *    N → this group's own retention.
	 */
	public static function retentionDays(?array $group): int
	{
		$raw = (int) ($group['auto_delete_days'] ?? 0);
		if ($raw === self::RETENTION_NEVER) {
			return 0;
		}
		if ($raw > 0) {
			return $raw;
		}
		return max(0, (int) Database::getSetting('auto_delete_days', 0));
	}

	/**
	 * Retention per group id for the whole install, plus the two fallbacks the sweep needs:
	 * `guest` (files with no owner) and `default` (accounts whose group_id is NULL).
	 *
	 * @return array{groups: array<int,int>, guest: int, default: int, moderator: int} days; 0 = keep forever
	 */
	public static function retentionMap(): array
	{
		$out = ['groups' => [], 'guest' => 0, 'default' => 0, 'moderator' => 0];
		foreach (self::all() as $g) {
			$days = self::retentionDays($g);
			$out['groups'][(int) $g['id']] = $days;
			if (($g['slug'] ?? null) === 'guest') {
				$out['guest'] = $days;
			}
			if ((int) ($g['is_default'] ?? 0) === 1 && ($g['slug'] ?? null) !== 'guest') {
				$out['default'] = $days;
			}
			if (($g['slug'] ?? null) === 'moderator') {
				$out['moderator'] = $days;
			}
		}
		return $out;
	}
}
