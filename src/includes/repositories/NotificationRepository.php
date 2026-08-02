<?php
/**
 * NotificationRepository — storage for in-app notifications and the per-account preferences
 * that decide which of them are ever written.
 *
 * Two ideas carry the whole design:
 *
 *   1. **Stacking.** A popular file is downloaded a hundred times and that is *one* thing the
 *      owner wants to know, not a hundred. Events carrying the same `group_key` merge into the
 *      account's live (unread) row and raise its `count`; reading closes the stack, so what
 *      happens next is news again rather than an old line silently ticking upward.
 *
 *   2. **Ask before writing.** `Notifications::notify()` checks the account's preferences and
 *      the operator's defaults before anything reaches this class. A muted type costs one cached
 *      lookup and no rows — which matters, because the busiest emitter sits in the download path.
 *
 * Message *text* is deliberately not stored. A row keeps the type, the subject and whatever
 * parameters the sentence needs; the wording is looked up per request, in the reader's language.
 * Storing the sentence would freeze it in whatever language happened to be current when a
 * stranger pressed download.
 */
final class NotificationRepository
{
	/** How long a stack stays open even while unread. Beyond it, a new row starts. */
	private const STACK_WINDOW = 86400;

	/** Rows older than this are pruned by the cleanup job (read or not). */
	public const RETENTION = 90 * 86400;

	/** Hard cap per account, enforced on write, so one runaway emitter cannot fill the table. */
	private const MAX_PER_USER = 500;

	/**
	 * Per-request preference cache, keyed by user id.
	 *
	 * A class property rather than a `static` inside prefs(), because saving has to be able to
	 * drop it: a request that saves preferences and then emits a notification would otherwise
	 * decide with the values it read before the save.
	 *
	 * @var array<int, array>
	 */
	private static array $prefsCache = [];

	private static function table(): string
	{
		return Database::table('notifications');
	}

	/**
	 * Record one event for one account, merging into an open stack when there is one.
	 *
	 * @param string $groupKey what this event is "the same as". Empty = never stacks.
	 * @param int    $by       how many occurrences this call represents. A sweep that removes
	 *                         forty files says 40 once instead of calling this forty times.
	 * @param bool   $silent   write it already-read: a record that the account was told by some
	 *                         other channel, without lighting up the bell (see Notifications).
	 * @return bool whether a row was written or updated
	 */
	public static function push(
		int $userId,
		string $type,
		string $subject = '',
		string $link = '',
		array $data = [],
		string $groupKey = '',
		int $by = 1,
		bool $silent = false,
		string $dedupeKey = ''
	): bool {
		$pdo = Database::getInstance();
		if (!$pdo || $userId <= 0) {
			return false;
		}
		$now = time();
		$table = self::table();
		$by = max(1, $by);
		$groupKey = mb_substr($groupKey, 0, 80);
		$dedupeKey = mb_substr($dedupeKey, 0, 80);
		$encodedData = $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null;
		$openStackKey = $groupKey !== '' && !$silent ? $groupKey : null;
		$dedupeValue = $dedupeKey !== '' ? $dedupeKey : null;

		try {
			if ($groupKey !== '') {
				// Closing an expired stack frees its unique key. Reading does the same below.
				$stmt = $pdo->prepare(
					"UPDATE `{$table}` SET `open_stack_key` = NULL
					 WHERE `user_id` = ? AND `open_stack_key` = ? AND `updated_at` < ?"
				);
				$stmt->execute([$userId, $groupKey, $now - self::STACK_WINDOW]);
			}

			$columns = "(`user_id`, `type`, `group_key`, `open_stack_key`, `dedupe_key`,
				`subject`, `link`, `data`, `count`, `created_at`, `updated_at`, `read_at`)";
			$values = [
				$userId,
				mb_substr($type, 0, 40),
				$groupKey,
				$openStackKey,
				$dedupeValue,
				mb_substr($subject, 0, 255),
				mb_substr($link, 0, 255),
				$encodedData,
				$by,
				$now,
				$now,
				$silent ? $now : null,
			];

			if ($dedupeValue !== null) {
				// INSERT IGNORE turns the unique (user,dedupe_key) constraint into an atomic
				// once-only claim. A duplicate must not send another mail either.
				$stmt = $pdo->prepare(
					"INSERT IGNORE INTO `{$table}` {$columns}
					 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
				);
				$stmt->execute($values);
				if ($stmt->rowCount() !== 1) {
					return false;
				}
			} elseif ($openStackKey !== null) {
				$stmt = $pdo->prepare(
					"INSERT INTO `{$table}` {$columns}
					 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
					 ON DUPLICATE KEY UPDATE
					  `count` = `count` + VALUES(`count`),
					  `updated_at` = VALUES(`updated_at`),
					  `subject` = VALUES(`subject`),
					  `link` = VALUES(`link`),
					  `data` = VALUES(`data`)"
				);
				$stmt->execute($values);
			} else {
				$stmt = $pdo->prepare(
					"INSERT INTO `{$table}` {$columns}
					 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
				);
				$stmt->execute($values);
			}

			self::trim($userId);
			return true;
		} catch (PDOException $e) {
			return false;
		}
	}

	/**
	 * Has this account already been told this?
	 *
	 * For warnings rather than events: "your plan runs out on the 3rd" is true every time the
	 * cron runs, and saying it once is the whole point. Stacking cannot express that — it would
	 * turn one warning into "×12 since Tuesday" — so the emitter asks first.
	 *
	 * @param int $since only look this far back; 0 = ever.
	 */
	public static function exists(int $userId, string $groupKey, int $since = 0): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo || $userId <= 0 || $groupKey === '') {
			return false;
		}
		try {
			$sql = "SELECT 1 FROM `" . self::table() . "` WHERE `user_id` = ? AND `group_key` = ?";
			$params = [$userId, mb_substr($groupKey, 0, 80)];
			if ($since > 0) {
				$sql .= ' AND `updated_at` >= ?';
				$params[] = $since;
			}
			$stmt = $pdo->prepare($sql . ' LIMIT 1');
			$stmt->execute($params);
			return (bool) $stmt->fetchColumn();
		} catch (PDOException $e) {
			return false;
		}
	}

	/** Drop the oldest rows once an account is past the cap. Read ones go first. */
	private static function trim(int $userId): void
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return;
		}
		try {
			$table = self::table();
			$count = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `user_id` = ?");
			$count->execute([$userId]);
			$total = (int) $count->fetchColumn();
			if ($total <= self::MAX_PER_USER) {
				return;
			}
			$excess = $total - self::MAX_PER_USER;
			// `read_at IS NULL` sorts last, so anything already dealt with is discarded first.
			$del = $pdo->prepare("DELETE FROM `{$table}` WHERE `user_id` = ?
				ORDER BY (`read_at` IS NULL) ASC, `updated_at` ASC LIMIT {$excess}");
			$del->execute([$userId]);
		} catch (PDOException $e) {
		}
	}

	/**
	 * A page of an account's notifications, newest activity first.
	 *
	 * @return array{items: array, total: int, unread: int}
	 */
	public static function browse(int $userId, int $limit = 10, int $offset = 0, bool $unreadOnly = false): array
	{
		$pdo = Database::getInstance();
		$out = ['items' => [], 'total' => 0, 'unread' => 0];
		if (!$pdo || $userId <= 0) {
			return $out;
		}
		$table = self::table();
		$limit = max(1, min(100, $limit));
		$offset = max(0, $offset);
		$where = $unreadOnly ? ' AND `read_at` IS NULL' : '';

		try {
			$stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE `user_id` = ?{$where}
				ORDER BY `updated_at` DESC LIMIT {$limit} OFFSET {$offset}");
			$stmt->execute([$userId]);
			$out['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

			$c = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `user_id` = ?{$where}");
			$c->execute([$userId]);
			$out['total'] = (int) $c->fetchColumn();

			$out['unread'] = self::unreadCount($userId);
		} catch (PDOException $e) {
		}
		return $out;
	}

	public static function unreadCount(int $userId): int
	{
		$pdo = Database::getInstance();
		if (!$pdo || $userId <= 0) {
			return 0;
		}
		try {
			$stmt = $pdo->prepare("SELECT COUNT(*) FROM `" . self::table() . "` WHERE `user_id` = ? AND `read_at` IS NULL");
			$stmt->execute([$userId]);
			return (int) $stmt->fetchColumn();
		} catch (PDOException $e) {
			return 0;
		}
	}

	/**
	 * Unread rows the account has not even GLANCED at (runda 4, pt 4). "Seen" ≠ "read":
	 * opening the bell popover stamps `users.notification_seen_at` and the badge counts
	 * only what arrived after that stamp — the rows themselves stay unread until clicked.
	 * This is what makes the badge a "something new" light instead of a to-do counter.
	 */
	public static function freshCount(int $userId): int
	{
		$pdo = Database::getInstance();
		if (!$pdo || $userId <= 0) {
			return 0;
		}
		try {
			$stmt = $pdo->prepare("SELECT COUNT(*) FROM `" . self::table() . "` n
				JOIN `" . Database::table('users') . "` u ON u.`id` = n.`user_id`
				WHERE n.`user_id` = ? AND n.`read_at` IS NULL AND n.`updated_at` > u.`notification_seen_at`");
			$stmt->execute([$userId]);
			return (int) $stmt->fetchColumn();
		} catch (PDOException $e) {
			return 0;
		}
	}

	/** Stamp "the account has looked at the bell" — the badge zeroes, the rows stay unread. */
	public static function markSeen(int $userId): void
	{
		$pdo = Database::getInstance();
		if (!$pdo || $userId <= 0) {
			return;
		}
		try {
			$pdo->prepare("UPDATE `" . Database::table('users') . "` SET `notification_seen_at` = ? WHERE `id` = ?")
				->execute([time(), $userId]);
		} catch (PDOException $e) {
		}
	}

	/** Mark specific rows read. Scoped to the owner — ids come from the browser. */
	public static function markRead(int $userId, array $ids): int
	{
		$pdo = Database::getInstance();
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
		if (!$pdo || $userId <= 0 || !$ids) {
			return 0;
		}
		try {
			$in = implode(',', array_fill(0, count($ids), '?'));
			$stmt = $pdo->prepare("UPDATE `" . self::table() . "`
				SET `read_at` = ?, `open_stack_key` = NULL
				WHERE `user_id` = ? AND `read_at` IS NULL AND `id` IN ({$in})");
			$stmt->execute(array_merge([time(), $userId], $ids));
			return $stmt->rowCount();
		} catch (PDOException $e) {
			return 0;
		}
	}

	public static function markAllRead(int $userId): int
	{
		$pdo = Database::getInstance();
		if (!$pdo || $userId <= 0) {
			return 0;
		}
		try {
			$stmt = $pdo->prepare("UPDATE `" . self::table() . "`
				SET `read_at` = ?, `open_stack_key` = NULL
				WHERE `user_id` = ? AND `read_at` IS NULL");
			$stmt->execute([time(), $userId]);
			return $stmt->rowCount();
		} catch (PDOException $e) {
			return 0;
		}
	}

	public static function delete(int $userId, array $ids): int
	{
		$pdo = Database::getInstance();
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
		if (!$pdo || $userId <= 0 || !$ids) {
			return 0;
		}
		try {
			$in = implode(',', array_fill(0, count($ids), '?'));
			$stmt = $pdo->prepare("DELETE FROM `" . self::table() . "` WHERE `user_id` = ? AND `id` IN ({$in})");
			$stmt->execute(array_merge([$userId], $ids));
			return $stmt->rowCount();
		} catch (PDOException $e) {
			return 0;
		}
	}

	/** Empty the list. `$readOnly` clears what has been dealt with and keeps the rest. */
	public static function clear(int $userId, bool $readOnly = false): int
	{
		$pdo = Database::getInstance();
		if (!$pdo || $userId <= 0) {
			return 0;
		}
		try {
			$sql = "DELETE FROM `" . self::table() . "` WHERE `user_id` = ?" . ($readOnly ? ' AND `read_at` IS NOT NULL' : '');
			$stmt = $pdo->prepare($sql);
			$stmt->execute([$userId]);
			return $stmt->rowCount();
		} catch (PDOException $e) {
			return 0;
		}
	}

	/** Housekeeping: forget what nobody will read again. Called from scripts/cleanup.php. */
	public static function prune(): int
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return 0;
		}
		try {
			$stmt = $pdo->prepare("DELETE FROM `" . self::table() . "` WHERE `updated_at` < ?");
			$stmt->execute([time() - self::RETENTION]);
			return $stmt->rowCount();
		} catch (PDOException $e) {
			return 0;
		}
	}

	/* ---------------- Preferences ---------------- */

	/**
	 * One account's raw preference map, or an empty array when it has never been saved.
	 *
	 * Cached per request: the download path asks this question once per file, and so does every
	 * sweep over a long list of accounts.
	 *
	 * @return array<string, array{app?: bool, mail?: bool}>
	 */
	public static function prefs(int $userId): array
	{
		if (array_key_exists($userId, self::$prefsCache)) {
			return self::$prefsCache[$userId];
		}
		self::$prefsCache[$userId] = [];

		$pdo = Database::getInstance();
		if (!$pdo || $userId <= 0) {
			return [];
		}
		try {
			$stmt = $pdo->prepare("SELECT `prefs` FROM `" . Database::table('notification_prefs') . "` WHERE `user_id` = ?");
			$stmt->execute([$userId]);
			$raw = (string) $stmt->fetchColumn();
			$decoded = $raw !== '' ? json_decode($raw, true) : null;
			self::$prefsCache[$userId] = is_array($decoded) ? $decoded : [];
		} catch (PDOException $e) {
		}
		return self::$prefsCache[$userId];
	}

	/** Replace an account's preference map. */
	public static function savePrefs(int $userId, array $prefs): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo || $userId <= 0) {
			return false;
		}
		try {
			$stmt = $pdo->prepare("INSERT INTO `" . Database::table('notification_prefs') . "` (`user_id`, `prefs`, `updated_at`)
				VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `prefs` = VALUES(`prefs`), `updated_at` = VALUES(`updated_at`)");
			$stmt->execute([$userId, json_encode($prefs, JSON_UNESCAPED_UNICODE), time()]);
			self::$prefsCache[$userId] = $prefs;
			return true;
		} catch (PDOException $e) {
			return false;
		}
	}

	/** Every account id that could be notified — used by the broadcast. */
	public static function allUserIds(): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return [];
		}
		try {
			$stmt = $pdo->query("SELECT `id` FROM `" . Database::table('users') . "` WHERE `is_active` = 1");
			return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
		} catch (PDOException $e) {
			return [];
		}
	}

	/**
	 * Active recipients for a mail broadcast, fetched in one query to avoid looking every
	 * account up separately while the request fills the durable outbox.
	 *
	 * @return list<array{id:int,email:string}>
	 */
	public static function activeRecipients(): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return [];
		}
		try {
			$stmt = $pdo->query(
				"SELECT `id`, `email` FROM `" . Database::table('users') . "`
				 WHERE `is_active` = 1 AND `email` <> '' ORDER BY `id`"
			);
			return array_values(array_map(
				static fn(array $row): array => [
					'id' => (int) $row['id'],
					'email' => (string) $row['email'],
				],
				$stmt->fetchAll(PDO::FETCH_ASSOC)
			));
		} catch (PDOException $e) {
			return [];
		}
	}
}
