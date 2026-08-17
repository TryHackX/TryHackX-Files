<?php
/**
 * Advertising (Faza 8) — creatives, purchasable packages, and their metrics.
 *
 * One row in `ads` per creative, whoever owns it: the operator's own placements
 * (`owner_id` NULL) and the ones users buy through a package. Status carries the whole
 * lifecycle — draft (created, unpaid), pending (paid, awaiting review), active, rejected,
 * paused, expired — so the render path can stay a single indexed query.
 *
 * Display correctness never depends on cron: `pickForZone()` filters on `ends_at` at read
 * time, the same lazy-expiry idiom the group assignments use (GroupRepository::forUser).
 * The cron half (`expireEnded`, `warnExpiring`) only does bookkeeping and notifications.
 *
 * Metrics are a daily aggregate (`ad_stats_daily`, one row per ad per day, atomic UPSERT)
 * rather than a raw event log: an ad is shown on every page view, and traffic_logs-scale
 * growth per creative would need its own retention sweep while answering the only question
 * anyone asks — impressions, clicks, CTR per day — more slowly.
 */
final class AdRepository
{
	public const TYPES = ['image', 'html', 'adsense'];
	public const STATUSES = ['draft', 'pending', 'active', 'rejected', 'paused', 'expired'];

	/** Statuses the render path considers at all (plus the ends_at predicate). */
	private const RENDERABLE = 'active';

	/* ---------------- Creatives ---------------- */

	public static function get(int $id): ?array
	{
		$pdo = Database::getInstance();
		if (!$pdo || $id <= 0) {
			return null;
		}
		try {
			$stmt = $pdo->prepare("SELECT * FROM `" . Database::table('ads') . "` WHERE `id` = ?");
			$stmt->execute([$id]);
			return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
		} catch (PDOException $e) {
			return null;
		}
	}

	/** Every creative with its owner and package resolved — the admin list. */
	public static function all(): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return [];
		}
		$ads = Database::table('ads');
		$users = Database::table('users');
		$packages = Database::table('ad_packages');
		try {
			$stmt = $pdo->query("SELECT a.*, u.`username` AS owner_name, p.`name` AS package_name
				FROM `{$ads}` a
				LEFT JOIN `{$users}` u ON u.`id` = a.`owner_id`
				LEFT JOIN `{$packages}` p ON p.`id` = a.`package_id`
				ORDER BY a.`created_at` DESC");
			return $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (PDOException $e) {
			return [];
		}
	}

	/**
	 * One user's own creatives, newest first — the "Moje reklamy" tab. Primaries only;
	 * add-on placements ride along under `children` so the tab shows one purchase as one
	 * row with its zones, not N indistinguishable rows.
	 */
	public static function forOwner(int $userId): array
	{
		$pdo = Database::getInstance();
		if (!$pdo || $userId <= 0) {
			return [];
		}
		$ads = Database::table('ads');
		$packages = Database::table('ad_packages');
		$payments = Database::table('payments');
		try {
			$stmt = $pdo->prepare("SELECT a.*, p.`name` AS package_name, p.`duration_days`,
					p.`amount_minor`, p.`currency`, p.`addon_zones`,
					pay.`ext_order_id` AS order_id, pay.`status` AS payment_status
				FROM `{$ads}` a
				LEFT JOIN `{$packages}` p ON p.`id` = a.`package_id`
				LEFT JOIN (
					SELECT paid.* FROM `{$payments}` paid
					JOIN (
						SELECT `ad_id`, MAX(`id`) AS `id`
						FROM `{$payments}`
						WHERE `status` IN ('COMPLETED', 'REFUNDED') AND `ad_id` IS NOT NULL
						GROUP BY `ad_id`
					) latest ON latest.`id` = paid.`id`
				) pay ON pay.`ad_id` = a.`id`
				WHERE a.`owner_id` = ? AND a.`parent_ad_id` IS NULL ORDER BY a.`created_at` DESC");
			$stmt->execute([$userId]);
			$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
			if ($rows === []) {
				return [];
			}
			$ids = array_map('intval', array_column($rows, 'id'));
			$placeholders = implode(',', array_fill(0, count($ids), '?'));
			$children = $pdo->prepare(
				"SELECT * FROM `{$ads}` WHERE `parent_ad_id` IN ({$placeholders})
				 ORDER BY `parent_ad_id` ASC, `id` ASC"
			);
			$children->execute($ids);
			$byParent = [];
			foreach ($children->fetchAll(PDO::FETCH_ASSOC) as $child) {
				$byParent[(int) $child['parent_ad_id']][] = $child;
			}
			foreach ($rows as &$row) {
				$row['children'] = $byParent[(int) $row['id']] ?? [];
			}
			unset($row);
			return $rows;
		} catch (PDOException $e) {
			return [];
		}
	}

	/** The add-on placements bought together with a primary ad. */
	public static function childrenOf(int $adId): array
	{
		$pdo = Database::getInstance();
		if (!$pdo || $adId <= 0) {
			return [];
		}
		try {
			$stmt = $pdo->prepare("SELECT * FROM `" . Database::table('ads') . "` WHERE `parent_ad_id` = ? ORDER BY `id` ASC");
			$stmt->execute([$adId]);
			return $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (PDOException $e) {
			return [];
		}
	}

	/** Paid ads awaiting review — the approval queue, oldest first so nobody waits longest. */
	public static function pending(): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return [];
		}
		$ads = Database::table('ads');
		$users = Database::table('users');
		$packages = Database::table('ad_packages');
		$payments = Database::table('payments');
		try {
			// The paid order rides along so the reviewer can quote it when rejecting — a refund
			// is manual, and "which payment was that" should not require a second screen. The
			// order's own amount comes too: the package price alone hides add-on surcharges.
			$stmt = $pdo->query("SELECT a.*, u.`username` AS owner_name, p.`name` AS package_name,
					p.`amount_minor`, p.`currency`, pay.`ext_order_id` AS order_id,
					pay.`amount_minor` AS order_amount_minor, pay.`currency` AS order_currency
				FROM `{$ads}` a
				LEFT JOIN `{$users}` u ON u.`id` = a.`owner_id`
				LEFT JOIN `{$packages}` p ON p.`id` = a.`package_id`
				LEFT JOIN (
					SELECT paid.* FROM `{$payments}` paid
					JOIN (
						SELECT `ad_id`, MAX(`id`) AS `id`
						FROM `{$payments}` WHERE `status` = 'COMPLETED' AND `ad_id` IS NOT NULL
						GROUP BY `ad_id`
					) latest ON latest.`id` = paid.`id`
				) pay ON pay.`ad_id` = a.`id`
				WHERE a.`status` = 'pending' ORDER BY a.`updated_at` ASC");
			return $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (PDOException $e) {
			return [];
		}
	}

	public static function pendingCount(): int
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return 0;
		}
		try {
			// One purchase = one queue card = one tick of the badge, however many placements
			// it spans — children collapse into their root here exactly like in the queue view.
			$stmt = $pdo->query("SELECT COUNT(DISTINCT COALESCE(`parent_ad_id`, `id`))
				FROM `" . Database::table('ads') . "` WHERE `status` = 'pending'");
			return (int) $stmt->fetchColumn();
		} catch (PDOException $e) {
			return 0;
		}
	}

	/**
	 * Create or update a creative from the admin form. Fields arrive pre-validated by the
	 * controller (types, URLs, zone); this only writes them.
	 */
	public static function save(?int $id, array $data): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return ['success' => false, 'error' => 'db'];
		}
		$table = Database::table('ads');
		$now = time();
		$fields = [
			'name' => (string) ($data['name'] ?? ''),
			'type' => in_array($data['type'] ?? '', self::TYPES, true) ? $data['type'] : 'image',
			'zone' => (string) ($data['zone'] ?? ''),
			'status' => in_array($data['status'] ?? '', self::STATUSES, true) ? $data['status'] : 'paused',
			'image_url' => (string) ($data['image_url'] ?? ''),
			'target_url' => (string) ($data['target_url'] ?? ''),
			'alt_text' => (string) ($data['alt_text'] ?? ''),
			'html' => (string) ($data['html'] ?? ''),
			'adsense_slot' => (string) ($data['adsense_slot'] ?? ''),
			'weight' => max(1, (int) ($data['weight'] ?? 1)),
			'starts_at' => !empty($data['starts_at']) ? (int) $data['starts_at'] : null,
			'ends_at' => !empty($data['ends_at']) ? (int) $data['ends_at'] : null,
		];
		try {
			if ($id) {
				$sets = implode(', ', array_map(fn($k) => "`{$k}` = ?", array_keys($fields)));
				$stmt = $pdo->prepare("UPDATE `{$table}` SET {$sets}, `updated_at` = ? WHERE `id` = ?");
				$stmt->execute([...array_values($fields), $now, $id]);
			} else {
				$fields['created_at'] = $now;
				$fields['updated_at'] = $now;
				$cols = implode(', ', array_map(fn($k) => "`{$k}`", array_keys($fields)));
				$marks = implode(', ', array_fill(0, count($fields), '?'));
				$stmt = $pdo->prepare("INSERT INTO `{$table}` ({$cols}) VALUES ({$marks})");
				$stmt->execute(array_values($fields));
				$id = (int) $pdo->lastInsertId();
			}
		} catch (PDOException $e) {
			return ['success' => false, 'error' => 'db'];
		}
		self::refreshAdsenseActive();
		return ['success' => true, 'id' => $id];
	}

	/**
	 * A buyer's creative, bound to its package. Everything a buyer may not choose is taken
	 * from the package or the session here, not from the request: type is always image, the
	 * zone is the package's, the owner is the caller.
	 */
	public static function createDraft(int $userId, array $package, array $data): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return ['success' => false, 'error' => 'db'];
		}
		$now = time();
		try {
			$pdo->beginTransaction();
			// The rotation weight IS the package's priority — a pricier package buys a bigger
			// share of the zone's impressions, permanently for that ad.
			$stmt = $pdo->prepare("INSERT INTO `" . Database::table('ads') . "`
				(`name`, `type`, `zone`, `owner_id`, `status`, `image_url`, `target_url`, `alt_text`,
				 `weight`, `package_id`, `purchase_duration_days`, `parent_ad_id`, `created_at`, `updated_at`)
				VALUES (?, 'image', ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
			$stmt->execute([
				(string) ($data['name'] ?? ''),
				(string) $package['zone'],
				$userId,
				(string) ($data['image_url'] ?? ''),
				(string) ($data['target_url'] ?? ''),
				(string) ($data['alt_text'] ?? ''),
				max(1, min(100, (int) ($package['priority'] ?? 10))),
				(int) $package['id'],
				max(1, (int) ($package['duration_days'] ?? 30)),
				null,
				$now,
				$now,
			]);
			$primaryId = (int) $pdo->lastInsertId();

			// Add-on placements (Faza 8 runda 4): the package whitelists which extra zones
			// are for sale and their surcharge; each becomes a child ad sharing the copy.
			$allowed = json_decode((string) ($package['addon_zones'] ?? ''), true) ?: [];
			$seen = [(string) $package['zone']];
			foreach ((array) ($data['addons'] ?? []) as $zone) {
				$zone = (string) $zone;
				if (!isset($allowed[$zone]) || in_array($zone, $seen, true) || !isset(AdRenderer::ZONES[$zone])) {
					continue;
				}
				$seen[] = $zone;
				$stmt->execute([
					(string) ($data['name'] ?? ''),
					$zone,
					$userId,
					'',
					(string) ($data['target_url'] ?? ''),
					(string) ($data['alt_text'] ?? ''),
					max(1, min(100, (int) ($package['priority'] ?? 10))),
					(int) $package['id'],
					max(1, (int) ($package['duration_days'] ?? 30)),
					$primaryId,
					$now,
					$now,
				]);
			}
			$pdo->commit();
			return ['success' => true, 'id' => $primaryId];
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return ['success' => false, 'error' => 'db'];
		}
	}

	/**
	 * A buyer updating their own creative. Editing what is already under or past review sends
	 * it back to the queue — the approved thing must be the thing that shows — but an already
	 * running ad keeps its `ends_at`: re-approval latency must not eat paid days twice. On
	 * top of that, an edit of a LIVE ad stamps `resubmitted_at`: if the re-review then drags
	 * past the operator's grace, approve() credits the whole delay back (runda 4, pt 8).
	 * Child placements follow the primary: shared copy, same review state.
	 */
	public static function updateOwn(int $adId, int $userId, array $data): array
	{
		$pdo = Database::getInstance();
		$ad = self::get($adId);
		if (!$pdo || !$ad || (int) $ad['owner_id'] !== $userId || !empty($ad['parent_ad_id'])) {
			return ['success' => false, 'error' => 'not_found'];
		}
		if (in_array($ad['status'], ['expired'], true)) {
			return ['success' => false, 'error' => 'expired'];
		}
		$backToReview = in_array($ad['status'], ['pending', 'active', 'rejected'], true);
		$wasLive = $ad['status'] === 'active';
		try {
			$now = time();
			$stmt = $pdo->prepare("UPDATE `" . Database::table('ads') . "`
				SET `name` = ?, `image_url` = ?, `target_url` = ?, `alt_text` = ?,
					`status` = ?, `reject_reason` = NULL,
					`resubmitted_at` = COALESCE(`resubmitted_at`, ?), `updated_at` = ?
				WHERE (`id` = ? OR `parent_ad_id` = ?) AND `owner_id` = ?");
			$stmt->execute([
				(string) ($data['name'] ?? $ad['name']),
				(string) ($data['image_url'] ?? ''),
				(string) ($data['target_url'] ?? ''),
				(string) ($data['alt_text'] ?? ''),
				$backToReview ? 'pending' : $ad['status'],
				$wasLive ? $now : null,
				$now,
				$adId,
				$adId,
				$userId,
			]);
			return ['success' => true, 'pending' => $backToReview];
		} catch (PDOException $e) {
			return ['success' => false, 'error' => 'db'];
		}
	}

	/**
	 * Extra placements added to an EXISTING purchase (runda 5): draft children for zones
	 * the package sells that the ad does not occupy yet. The "dopłać" checkout pays their
	 * surcharges; until then they are drafts and render nowhere.
	 *
	 * @return array<int, string> new child id => zone
	 */
	public static function addPlacements(int $adId, array $package, array $zones): array
	{
		$pdo = Database::getInstance();
		$ad = self::get($adId);
		if (!$pdo || !$ad || !empty($ad['parent_ad_id'])) {
			return [];
		}
		$allowed = json_decode((string) ($package['addon_zones'] ?? ''), true) ?: [];
		$created = [];
		$now = time();
		try {
			$pdo->beginTransaction();
			$lock = $pdo->prepare(
				"SELECT `id`, `zone` FROM `" . Database::table('ads') . "`
				 WHERE `id` = ? OR `parent_ad_id` = ? FOR UPDATE"
			);
			$lock->execute([$adId, $adId]);
			$lockedRows = $lock->fetchAll(PDO::FETCH_ASSOC);
			if ($lockedRows === []) {
				$pdo->rollBack();
				return [];
			}
			$taken = array_map(static fn(array $row): string => (string) $row['zone'], $lockedRows);
			$stmt = $pdo->prepare("INSERT INTO `" . Database::table('ads') . "`
				(`name`, `type`, `zone`, `owner_id`, `status`, `image_url`, `target_url`, `alt_text`,
				 `weight`, `package_id`, `purchase_duration_days`, `parent_ad_id`, `created_at`, `updated_at`)
				VALUES (?, 'image', ?, ?, 'draft', '', ?, ?, ?, ?, ?, ?, ?, ?)");
			foreach (array_unique(array_map('strval', $zones)) as $zone) {
				if (!isset($allowed[$zone]) || in_array($zone, $taken, true) || !isset(AdRenderer::ZONES[$zone])) {
					continue;
				}
				$taken[] = $zone;
				$stmt->execute([
					(string) $ad['name'], $zone, (int) $ad['owner_id'],
					(string) $ad['target_url'], (string) $ad['alt_text'],
					max(1, (int) $ad['weight']), (int) $package['id'],
					max(1, (int) ($ad['purchase_duration_days'] ?? $package['duration_days'] ?? 30)),
					$adId, $now, $now,
				]);
				$created[(int) $pdo->lastInsertId()] = $zone;
			}
			$pdo->commit();
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return [];
		}
		return $created;
	}

	/** The surcharge total for an ad's still-unpaid (draft) add-on placements. */
	public static function draftAddonAmount(int $adId, array $package): int
	{
		$allowed = json_decode((string) ($package['addon_zones'] ?? ''), true) ?: [];
		$sum = 0;
		foreach (self::childrenOf($adId) as $c) {
			if ($c['status'] === 'draft') {
				$sum += max(0, (int) ($allowed[(string) $c['zone']] ?? 0));
			}
		}
		return $sum;
	}

	/**
	 * The addon top-up was paid: its draft children join the review queue. The parent's
	 * approved creative stays live untouched — only the new placements need eyes.
	 */
	public static function markAddonsPaid(int $adId): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		try {
			self::copyBannerToChildren($adId);
			$stmt = $pdo->prepare("UPDATE `" . Database::table('ads') . "`
				SET `status` = 'pending', `updated_at` = ? WHERE `parent_ad_id` = ? AND `status` = 'draft'");
			$stmt->execute([time(), $adId]);
			return $stmt->rowCount() > 0;
		} catch (PDOException $e) {
			return false;
		}
	}

	/**
	 * The owner's own kill-switch (runda 4, pt 8): hide the ad without touching its clock.
	 * Deliberately NOT a refundable pause — the UI says so before the click — because paid
	 * calendar time is what was sold. `pickForZone` skips self-paused rows; everything else
	 * (status, ends_at, expiry) behaves as if the ad were still showing.
	 */
	public static function setSelfPaused(int $adId, int $userId, bool $paused): bool
	{
		$pdo = Database::getInstance();
		$ad = self::get($adId);
		if (!$pdo || !$ad || (int) $ad['owner_id'] !== $userId || !empty($ad['parent_ad_id'])
			|| $ad['status'] !== 'active') {
			return false;
		}
		try {
			$pdo->prepare("UPDATE `" . Database::table('ads') . "`
				SET `self_paused` = ?, `updated_at` = ? WHERE `id` = ? OR `parent_ad_id` = ?")
				->execute([$paused ? 1 : 0, time(), $adId, $adId]);
			return true;
		} catch (PDOException $e) {
			return false;
		}
	}

	/**
	 * Renewal (runda 4, pt 8): buy the same package again for an existing purchase. No
	 * review round — the creative is exactly what was already approved. A running ad gets
	 * the days appended; one expired within the grace window comes back live from now.
	 */
	public static function renew(int $adId, array $package): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$now = time();
		$days = max(1, (int) ($package['duration_days'] ?? 30)) * 86400;
		$ownsTransaction = !$pdo->inTransaction();
		try {
			if ($ownsTransaction) {
				$pdo->beginTransaction();
			}
			$lock = $pdo->prepare(
				"SELECT `status`, `ends_at` FROM `" . Database::table('ads') . "`
				 WHERE `id` = ? AND `parent_ad_id` IS NULL FOR UPDATE"
			);
			$lock->execute([$adId]);
			$ad = $lock->fetch(PDO::FETCH_ASSOC);
			if (!$ad || !in_array($ad['status'], ['active', 'expired'], true)) {
				if ($ownsTransaction) {
					$pdo->rollBack();
				}
				return false;
			}
			if ($ad['status'] === 'active') {
				$base = max($now, (int) ($ad['ends_at'] ?? $now));
				$sql = "UPDATE `" . Database::table('ads') . "`
				 SET `ends_at` = ?, `purchase_duration_days` = ?, `updated_at` = ?
				 WHERE (`id` = ? OR `parent_ad_id` = ?) AND `status` = 'active'";
			} else {
				$base = $now;
				$sql = "UPDATE `" . Database::table('ads') . "`
				 SET `status` = 'active', `ends_at` = ?, `purchase_duration_days` = ?, `updated_at` = ?
				 WHERE (`id` = ? OR `parent_ad_id` = ?) AND `status` = 'expired'";
			}
			$pdo->prepare($sql)->execute([
				$base + $days,
				max(1, (int) ($package['duration_days'] ?? 30)),
				$now,
				$adId,
				$adId,
			]);
			if ($ownsTransaction) {
				$pdo->commit();
			}
		} catch (Throwable $e) {
			if ($ownsTransaction && $pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return false;
		}
		self::refreshAdsenseActive();
		return true;
	}

	/**
	 * The grace window's end (runda 4, pt 8): expired ads whose renewal period ran out are
	 * removed for good — row, children, banner files, stats. Cron-only.
	 *
	 * @return int primaries purged
	 */
	public static function purgeExpired(int $graceDays): int
	{
		$pdo = Database::getInstance();
		if (!$pdo || $graceDays <= 0) {
			return 0;
		}
		$cutoff = time() - $graceDays * 86400;
		try {
			$stmt = $pdo->prepare("SELECT `id` FROM `" . Database::table('ads') . "`
				WHERE `status` = 'expired' AND `parent_ad_id` IS NULL
				AND `ends_at` IS NOT NULL AND `ends_at` <= ?");
			$stmt->execute([$cutoff]);
			$ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
		} catch (PDOException $e) {
			return 0;
		}
		foreach ($ids as $id) {
			self::delete((int) $id);
		}
		return count($ids);
	}

	public static function delete(int $id): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$ownsTransaction = !$pdo->inTransaction();
		try {
			if ($ownsTransaction) {
				$pdo->beginTransaction();
			}
			$pending = $pdo->prepare(
				"SELECT `id` FROM `" . Database::table('payments') . "`
				 WHERE `ad_id` = ? AND `granted_at` IS NULL
				   AND `status` IN (?, ?, ?)
				 LIMIT 1 FOR UPDATE"
			);
			$pending->execute([
				$id,
				PaymentRepository::NEW,
				PaymentRepository::PENDING,
				PaymentRepository::PROCESSING,
			]);
			if ($pending->fetchColumn()) {
				if ($ownsTransaction) {
					$pdo->rollBack();
				}
				return false;
			}
			$lock = $pdo->prepare(
				"SELECT `id`, `image_path` FROM `" . Database::table('ads') . "`
				 WHERE `id` = ? OR `parent_ad_id` = ? FOR UPDATE"
			);
			$lock->execute([$id, $id]);
			$rows = $lock->fetchAll(PDO::FETCH_ASSOC);
			if ($rows === []) {
				if ($ownsTransaction) {
					$pdo->rollBack();
				}
				return false;
			}
			$ids = array_map('intval', array_column($rows, 'id'));
			$queue = $pdo->prepare(
				"INSERT INTO `" . Database::table('ad_file_deletion_queue') . "`
				 (`filename`, `attempts`, `next_attempt_at`, `last_error`, `created_at`)
				 VALUES (?, 0, 0, NULL, ?)
				 ON DUPLICATE KEY UPDATE
				  `next_attempt_at` = LEAST(`next_attempt_at`, VALUES(`next_attempt_at`))"
			);
			foreach ($rows as $row) {
				$filename = (string) ($row['image_path'] ?? '');
				if (preg_match('/\A[a-f0-9]{32}\.(jpg|png|webp|gif)\z/D', $filename)) {
					$queue->execute([$filename, time()]);
				}
			}
			$in = implode(',', array_fill(0, count($ids), '?'));
			$pdo->prepare(
				"DELETE FROM `" . Database::table('ad_stats_daily') . "` WHERE `ad_id` IN ({$in})"
			)->execute($ids);
			$delete = $pdo->prepare(
				"DELETE FROM `" . Database::table('ads') . "` WHERE `id` IN ({$in})"
			);
			$delete->execute($ids);
			if ($delete->rowCount() !== count($ids)) {
				throw new RuntimeException('The locked ad family changed during deletion.');
			}
			if ($ownsTransaction) {
				$pdo->commit();
			}
		} catch (Throwable $e) {
			if ($ownsTransaction && $pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return false;
		}
		if ($ownsTransaction) {
			self::processBannerDeletionQueue(100);
		}
		self::refreshAdsenseActive();
		return true;
	}

	/* ---------------- Lifecycle ---------------- */

	/**
	 * Payment fulfilment: draft → pending. Conditional so a repeated webhook is a no-op even
	 * without the payment claim in front of it.
	 */
	public static function markPaid(int $adId, array $package = []): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$ownsTransaction = !$pdo->inTransaction();
		try {
			if ($ownsTransaction) {
				$pdo->beginTransaction();
			}
			$lock = $pdo->prepare(
				"SELECT `status`, `purchase_duration_days` FROM `" . Database::table('ads') . "`
				 WHERE `id` = ? AND `parent_ad_id` IS NULL FOR UPDATE"
			);
			$lock->execute([$adId]);
			$ad = $lock->fetch(PDO::FETCH_ASSOC);
			if (!$ad || $ad['status'] !== 'draft') {
				if ($ownsTransaction) {
					$pdo->rollBack();
				}
				return false;
			}
			$duration = max(
				1,
				(int) ($package['duration_days'] ?? $ad['purchase_duration_days'] ?? 30)
			);
			$now = time();
			$stmt = $pdo->prepare("UPDATE `" . Database::table('ads') . "`
				SET `status` = 'pending', `purchase_duration_days` = ?, `updated_at` = ?
				WHERE `id` = ? AND `status` = 'draft'");
			$stmt->execute([$duration, $now, $adId]);
			$paid = $stmt->rowCount() > 0;
			if ($paid) {
				// Add-on placements were paid by the same order.
				$pdo->prepare("UPDATE `" . Database::table('ads') . "`
					SET `status` = 'pending', `purchase_duration_days` = ?, `updated_at` = ?
					WHERE `parent_ad_id` = ? AND `status` = 'draft'")
					->execute([$duration, $now, $adId]);
			}
			if ($ownsTransaction) {
				$pdo->commit();
			}
			return $paid;
		} catch (Throwable $e) {
			if ($ownsTransaction && $pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return false;
		}
	}

	/**
	 * Approve a pending purchase. Paid time starts here, not at checkout: the buyer must not
	 * lose display days to review latency, so `ends_at` is stamped from the package's
	 * duration at the moment of approval. A re-approved ad that already has an end date
	 * keeps it (the days were granted once) — EXCEPT that a re-review slower than the
	 * operator's grace (`ads_review_comp_days`, runda 4 pt 8) credits the whole delay back:
	 * the buyer's clock ran while the ad hung unseen in the queue, and past the grace that
	 * becomes the operator's cost, not theirs. Child placements follow the primary.
	 */
	public static function approve(int $adId, int $adminId): ?array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return null;
		}
		try {
			$pdo->beginTransaction();
			$lock = $pdo->prepare(
				"SELECT * FROM `" . Database::table('ads') . "` WHERE `id` = ? FOR UPDATE"
			);
			$lock->execute([$adId]);
			$ad = $lock->fetch(PDO::FETCH_ASSOC);
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return null;
		}
		if (!$ad || $ad['status'] !== 'pending') {
			$pdo->rollBack();
			return null;
		}
		try {
			$now = time();
			$endsAt = $ad['ends_at'] !== null ? (int) $ad['ends_at'] : null;
			if ($endsAt === null && !empty($ad['parent_ad_id'])) {
			// A later-added placement (runda 5): its clock is the parent's — the buyer paid a
			// surcharge for the remaining window, not a fresh full period.
			$parentQuery = $pdo->prepare(
				"SELECT `ends_at` FROM `" . Database::table('ads') . "` WHERE `id` = ? FOR UPDATE"
			);
			$parentQuery->execute([(int) $ad['parent_ad_id']]);
			$parentEnd = $parentQuery->fetchColumn();
			$endsAt = $parentEnd !== false && $parentEnd !== null ? (int) $parentEnd : null;
			} elseif ($endsAt === null) {
			// Captured when the draft was created/paid; package edits or soft deletion after
			// checkout cannot alter the period the buyer is waiting to receive.
			$days = (int) ($ad['purchase_duration_days'] ?? 0);
			$endsAt = $days > 0 ? $now + ($days * 86400) : null;
			} elseif ($endsAt !== null && !empty($ad['resubmitted_at'])) {
			$compDays = (int) Database::getSetting('ads_review_comp_days', 3);
			$delay = $now - (int) $ad['resubmitted_at'];
			if ($compDays > 0 && $delay > $compDays * 86400) {
				$endsAt += $delay;
			}
			}
			$stmt = $pdo->prepare("UPDATE `" . Database::table('ads') . "`
				SET `status` = 'active', `approved_at` = ?, `approved_by` = ?, `ends_at` = ?,
					`reject_reason` = NULL, `resubmitted_at` = NULL, `updated_at` = ?
				WHERE (`id` = ? OR `parent_ad_id` = ?) AND `status` = 'pending'");
			$stmt->execute([$now, $adminId, $endsAt, $now, $adId, $adId]);
			if ($stmt->rowCount() === 0) {
				$pdo->rollBack();
				return null;
			}
			// A primary's clock governs the whole purchase: re-stamp every child, whatever its
			// state. Without this, a child approved before its parent kept the NULL it inherited
			// from a then-unapproved parent — and ran forever on a 30-day purchase.
			if (empty($ad['parent_ad_id'])) {
				$pdo->prepare("UPDATE `" . Database::table('ads') . "`
					SET `ends_at` = ?, `updated_at` = ? WHERE `parent_ad_id` = ? AND `status` = 'active'")
					->execute([$endsAt, $now, $adId]);
			}
			$pdo->commit();
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return null;
		}
		self::refreshAdsenseActive();
		return self::get($adId);
	}

	/**
	 * Approve an addon top-up (runda 7): the primary already runs, only its freshly paid
	 * child placements await review. They join the parent's clock — the surcharge bought
	 * the remaining window, not a fresh period.
	 */
	public static function approveChildren(int $parentId, int $adminId): ?array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return null;
		}
		try {
			$pdo->beginTransaction();
			$parentQuery = $pdo->prepare(
				"SELECT `ends_at` FROM `" . Database::table('ads') . "`
				 WHERE `id` = ? AND `parent_ad_id` IS NULL FOR UPDATE"
			);
			$parentQuery->execute([$parentId]);
			$parentEnd = $parentQuery->fetchColumn();
			if ($parentEnd === false) {
				$pdo->rollBack();
				return null;
			}
			$now = time();
			$endsAt = $parentEnd !== null ? (int) $parentEnd : null;
			$stmt = $pdo->prepare("UPDATE `" . Database::table('ads') . "`
				SET `status` = 'active', `approved_at` = ?, `approved_by` = ?, `ends_at` = ?,
					`reject_reason` = NULL, `resubmitted_at` = NULL, `updated_at` = ?
				WHERE `parent_ad_id` = ? AND `status` = 'pending'");
			$stmt->execute([$now, $adminId, $endsAt, $now, $parentId]);
			if ($stmt->rowCount() === 0) {
				$pdo->rollBack();
				return null;
			}
			$pdo->commit();
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return null;
		}
		self::refreshAdsenseActive();
		return self::get($parentId);
	}

	/** Reject an addon top-up's pending children while the primary keeps running. */
	public static function rejectChildren(int $parentId, string $reason): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		try {
			$stmt = $pdo->prepare("UPDATE `" . Database::table('ads') . "`
				SET `status` = 'rejected', `reject_reason` = ?, `updated_at` = ?
				WHERE `parent_ad_id` = ? AND `status` = 'pending'");
			$stmt->execute([mb_substr($reason, 0, 255), time(), $parentId]);
			return $stmt->rowCount() > 0;
		} catch (PDOException $e) {
			return false;
		}
	}

	public static function reject(int $adId, string $reason): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		try {
			$stmt = $pdo->prepare("UPDATE `" . Database::table('ads') . "`
				SET `status` = 'rejected', `reject_reason` = ?, `updated_at` = ?
				WHERE (`id` = ? OR `parent_ad_id` = ?) AND `status` = 'pending'");
			$stmt->execute([mb_substr($reason, 0, 255), time(), $adId, $adId]);
			return $stmt->rowCount() > 0;
		} catch (PDOException $e) {
			return false;
		}
	}

	/** Pause/resume by the admin. Resume only re-activates something that was paused. */
	public static function setStatus(int $adId, string $status): bool
	{
		if (!in_array($status, self::STATUSES, true)) {
			return false;
		}
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		try {
			$stmt = $pdo->prepare("UPDATE `" . Database::table('ads') . "` SET `status` = ?, `updated_at` = ? WHERE `id` = ?");
			$stmt->execute([$status, time(), $adId]);
		} catch (PDOException $e) {
			return false;
		}
		self::refreshAdsenseActive();
		return true;
	}

	/**
	 * Cron bookkeeping: mark lapsed ads `expired` and tell their owners. Display already
	 * stopped at `ends_at` (pickForZone filters on it), so nothing here is time-critical.
	 *
	 * @return int rows flipped
	 */
	public static function expireEnded(): int
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return 0;
		}
		$now = time();
		try {
			$stmt = $pdo->prepare("SELECT `id`, `owner_id`, `name`, `parent_ad_id` FROM `" . Database::table('ads') . "`
				WHERE `status` = 'active' AND `ends_at` IS NOT NULL AND `ends_at` <= ?");
			$stmt->execute([$now]);
			$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
			if (!$rows) {
				return 0;
			}
			$upd = $pdo->prepare("UPDATE `" . Database::table('ads') . "`
				SET `status` = 'expired', `updated_at` = ? WHERE `id` = ? AND `status` = 'active'");
			$grace = (int) Database::getSetting('ads_grace_days', 14);
			foreach ($rows as $row) {
				$upd->execute([$now, (int) $row['id']]);
				// One purchase, one notice: child placements expire silently with the primary.
				if (!empty($row['owner_id']) && empty($row['parent_ad_id'])) {
					Notifications::send((int) $row['owner_id'], 'ad.expired', [
						'subject' => (string) $row['name'],
						'data' => ['days' => $grace],
						'link' => (defined('APP_URL') ? APP_URL : '') . '/panel.php?tab=myads',
					]);
				}
			}
		} catch (PDOException $e) {
			return 0;
		}
		self::refreshAdsenseActive();
		return count($rows);
	}

	/** Warn owners of ads about to lapse — once per deadline, like plan.expiring. */
	public static function warnExpiring(int $daysAhead): int
	{
		$pdo = Database::getInstance();
		if (!$pdo || $daysAhead <= 0) {
			return 0;
		}
		$now = time();
		try {
			$stmt = $pdo->prepare("SELECT `id`, `owner_id`, `name`, `ends_at` FROM `" . Database::table('ads') . "`
				WHERE `status` = 'active' AND `owner_id` IS NOT NULL AND `parent_ad_id` IS NULL
				AND `ends_at` IS NOT NULL AND `ends_at` > ? AND `ends_at` <= ?");
			$stmt->execute([$now, $now + ($daysAhead * 86400)]);
			$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (PDOException $e) {
			return 0;
		}
		foreach ($rows as $row) {
			$ends = (int) $row['ends_at'];
			Notifications::send((int) $row['owner_id'], 'ad.expiring', [
				'subject' => (string) $row['name'],
				'data' => [
					'date' => date('d.m.Y', $ends),
					'days' => max(1, (int) ceil(($ends - $now) / 86400)),
				],
				'group' => 'ad.expiring:' . $row['id'] . ':' . $ends,
				'once' => true,
				'link' => (defined('APP_URL') ? APP_URL : '') . '/panel.php?tab=myads',
			]);
		}
		return count($rows);
	}

	/* ---------------- Rendering ---------------- */

	/**
	 * An ad's weight as the rotation sees it right now: the base weight (a purchased ad
	 * carries its package's priority) plus a paid boost while it lasts. Weighted random
	 * keeps a floor for everyone — an ad with weight 10 next to one with 80 still gets
	 * ~11% of the impressions, it is never starved outright.
	 */
	public static function effectiveWeight(array $ad, ?int $now = null): int
	{
		$now = $now ?? time();
		$boost = (!empty($ad['boost_until']) && (int) $ad['boost_until'] > $now)
			? (int) ($ad['boost_weight'] ?? 0) : 0;
		return max(1, (int) $ad['weight'] + $boost);
	}

	/**
	 * The one query the public pages run: everything currently showable in a zone. Weighted
	 * random pick happens in PHP — the sets are tiny and MySQL's ORDER BY RAND() tricks are
	 * not worth their obscurity here.
	 */
	public static function pickForZone(string $zone): ?array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return null;
		}
		$now = time();
		try {
			$stmt = $pdo->prepare("SELECT * FROM `" . Database::table('ads') . "`
				WHERE `zone` = ? AND `status` = '" . self::RENDERABLE . "' AND `self_paused` = 0
				AND (`starts_at` IS NULL OR `starts_at` <= ?)
				AND (`ends_at` IS NULL OR `ends_at` > ?)");
			$stmt->execute([$zone, $now, $now]);
			$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (PDOException $e) {
			return null;
		}
		if (!$rows) {
			return null;
		}
		if (count($rows) === 1) {
			return $rows[0];
		}
		$total = 0;
		foreach ($rows as $row) {
			$total += self::effectiveWeight($row, $now);
		}
		$hit = random_int(1, $total);
		foreach ($rows as $row) {
			$hit -= self::effectiveWeight($row, $now);
			if ($hit <= 0) {
				return $row;
			}
		}
		return $rows[0];
	}

	/**
	 * How many ads are (or would be) live in a zone — the number the per-zone cap compares
	 * against. Scheduled-out ads do not count; a paused one does not either.
	 */
	public static function activeCountInZone(string $zone, int $excludeId = 0): int
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return 0;
		}
		$now = time();
		try {
			$stmt = $pdo->prepare("SELECT COUNT(*) FROM `" . Database::table('ads') . "`
				WHERE `zone` = ? AND `status` = 'active' AND `id` != ?
				AND (`starts_at` IS NULL OR `starts_at` <= ?)
				AND (`ends_at` IS NULL OR `ends_at` > ?)");
			$stmt->execute([$zone, $excludeId, $now, $now]);
			return (int) $stmt->fetchColumn();
		} catch (PDOException $e) {
			return 0;
		}
	}

	/**
	 * Apply a purchased boost: extra rotation weight for a limited time. Buying again while
	 * one is running extends the clock and keeps the stronger bonus — predictable for the
	 * buyer, no unbounded stacking for the zone.
	 */
	public static function applyBoost(int $adId, array $package): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$now = time();
		$bonus = max(1, (int) ($package['weight_bonus'] ?? 0));
		$days = max(1, (int) ($package['duration_days'] ?? 1));
		$ownsTransaction = !$pdo->inTransaction();
		try {
			if ($ownsTransaction) {
				$pdo->beginTransaction();
			}
			$lock = $pdo->prepare(
				"SELECT `boost_until`, `boost_weight` FROM `" . Database::table('ads') . "`
				 WHERE `id` = ? AND `parent_ad_id` IS NULL FOR UPDATE"
			);
			$lock->execute([$adId]);
			$ad = $lock->fetch(PDO::FETCH_ASSOC);
			if (!$ad) {
				if ($ownsTransaction) {
					$pdo->rollBack();
				}
				return false;
			}
			$active = !empty($ad['boost_until']) && (int) $ad['boost_until'] > $now;
			$until = ($active ? (int) $ad['boost_until'] : $now) + $days * 86400;
			$weight = $active ? max((int) $ad['boost_weight'], $bonus) : $bonus;
			// The boost was bought for the purchase, so add-on placements ride along.
			$pdo->prepare("UPDATE `" . Database::table('ads') . "`
				SET `boost_weight` = ?, `boost_until` = ?, `updated_at` = ? WHERE `id` = ? OR `parent_ad_id` = ?")
				->execute([$weight, $until, $now, $adId, $adId]);
			if ($ownsTransaction) {
				$pdo->commit();
			}
			return true;
		} catch (Throwable $e) {
			if ($ownsTransaction && $pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return false;
		}
	}

	/**
	 * Keep the denormalized `ads_adsense_active` flag current. config.php reads it on every
	 * request to decide whether the CSP must admit Google's ad domains, and the settings are
	 * already loaded there — this flag is what makes that decision free.
	 */
	public static function refreshAdsenseActive(): void
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return;
		}
		$active = Database::getSetting('ads_adsense_auto', '0') === '1';
		if (!$active) {
			try {
				$stmt = $pdo->query("SELECT 1 FROM `" . Database::table('ads') . "`
					WHERE `type` = 'adsense' AND `status` = 'active' LIMIT 1");
				$active = (bool) $stmt->fetchColumn();
			} catch (PDOException $e) {
				return; // unknown — keep the stored value rather than guessing
			}
		}
		Database::setSetting('ads_adsense_active', $active ? '1' : '0');
	}

	/* ---------------- Metrics ---------------- */

	/**
	 * Count events for one ad today. INSERT … ON DUPLICATE KEY is atomic, so concurrent
	 * page views cannot lose increments, and the table stays bounded at ads × days.
	 */
	public static function bumpStats(int $adId, int $impressions = 0, int $clicks = 0): void
	{
		if ($adId <= 0 || ($impressions <= 0 && $clicks <= 0)) {
			return;
		}
		$pdo = Database::getInstance();
		if (!$pdo) {
			return;
		}
		try {
			$stmt = $pdo->prepare("INSERT INTO `" . Database::table('ad_stats_daily') . "`
				(`ad_id`, `day`, `impressions`, `clicks`) VALUES (?, ?, ?, ?)
				ON DUPLICATE KEY UPDATE
					`impressions` = `impressions` + VALUES(`impressions`),
					`clicks` = `clicks` + VALUES(`clicks`)");
			$stmt->execute([$adId, date('Y-m-d'), max(0, $impressions), max(0, $clicks)]);
		} catch (PDOException $e) {
			// Metrics must never break a page view.
		}
	}

	/** Totals over the last N days, plus how many ads are currently live. */
	public static function statsTotals(int $days = 30): array
	{
		$out = ['impressions' => 0, 'clicks' => 0, 'ctr' => 0.0, 'active' => 0];
		$pdo = Database::getInstance();
		if (!$pdo) {
			return $out;
		}
		try {
			$stmt = $pdo->prepare("SELECT COALESCE(SUM(`impressions`), 0), COALESCE(SUM(`clicks`), 0)
				FROM `" . Database::table('ad_stats_daily') . "` WHERE `day` >= ?");
			$stmt->execute([date('Y-m-d', time() - $days * 86400)]);
			[$imps, $clicks] = $stmt->fetch(PDO::FETCH_NUM) ?: [0, 0];
			$out['impressions'] = (int) $imps;
			$out['clicks'] = (int) $clicks;
			$out['ctr'] = $out['impressions'] > 0 ? round($out['clicks'] / $out['impressions'] * 100, 2) : 0.0;

			$now = time();
			$stmt = $pdo->prepare("SELECT COUNT(*) FROM `" . Database::table('ads') . "`
				WHERE `status` = 'active'
				AND (`starts_at` IS NULL OR `starts_at` <= ?)
				AND (`ends_at` IS NULL OR `ends_at` > ?)");
			$stmt->execute([$now, $now]);
			$out['active'] = (int) $stmt->fetchColumn();
		} catch (PDOException $e) {
		}
		return $out;
	}

	/** The buyer's chart for one purchase: the primary and its add-ons summed per day. */
	public static function statsSeriesForIds(array $ids, int $days = 30): array
	{
		$ids = array_values(array_filter(array_map('intval', $ids), fn($n) => $n > 0));
		if (count($ids) === 1) {
			return self::statsSeries($days, $ids[0]);
		}
		$days = max(1, min(365, $days));
		$labels = [];
		$impressions = [];
		$clicks = [];
		$index = [];
		for ($i = $days - 1; $i >= 0; $i--) {
			$d = date('Y-m-d', time() - $i * 86400);
			$index[$d] = count($labels);
			$labels[] = $d;
			$impressions[] = 0;
			$clicks[] = 0;
		}
		$pdo = Database::getInstance();
		if (!$pdo || !$ids) {
			return ['days' => $labels, 'impressions' => $impressions, 'clicks' => $clicks];
		}
		try {
			$marks = implode(',', array_fill(0, count($ids), '?'));
			$stmt = $pdo->prepare("SELECT `day`, SUM(`impressions`) AS i, SUM(`clicks`) AS c
				FROM `" . Database::table('ad_stats_daily') . "`
				WHERE `day` >= ? AND `ad_id` IN ({$marks}) GROUP BY `day`");
			$stmt->execute(array_merge([$labels[0]], $ids));
			foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
				$key = (string) $row['day'];
				if (isset($index[$key])) {
					$impressions[$index[$key]] = (int) $row['i'];
					$clicks[$index[$key]] = (int) $row['c'];
				}
			}
		} catch (PDOException $e) {
		}
		return ['days' => $labels, 'impressions' => $impressions, 'clicks' => $clicks];
	}

	/**
	 * Impressions + clicks per day for the chart, seeded with zeroes so quiet days keep
	 * their place on the axis (the same shape PaymentRepository::series returns).
	 * $adId narrows to one creative — the buyer's own chart.
	 */
	public static function statsSeries(int $days = 30, ?int $adId = null): array
	{
		$days = max(1, min(365, $days));
		$labels = [];
		$impressions = [];
		$clicks = [];
		$index = [];
		for ($i = $days - 1; $i >= 0; $i--) {
			$d = date('Y-m-d', time() - $i * 86400);
			$index[$d] = count($labels);
			$labels[] = $d;
			$impressions[] = 0;
			$clicks[] = 0;
		}
		$pdo = Database::getInstance();
		if (!$pdo) {
			return ['days' => $labels, 'impressions' => $impressions, 'clicks' => $clicks];
		}
		try {
			$sql = "SELECT `day`, SUM(`impressions`) AS i, SUM(`clicks`) AS c
				FROM `" . Database::table('ad_stats_daily') . "` WHERE `day` >= ?";
			$args = [$labels[0]];
			if ($adId !== null) {
				$sql .= " AND `ad_id` = ?";
				$args[] = $adId;
			}
			$stmt = $pdo->prepare($sql . " GROUP BY `day`");
			$stmt->execute($args);
			foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
				$key = (string) $row['day'];
				if (isset($index[$key])) {
					$impressions[$index[$key]] = (int) $row['i'];
					$clicks[$index[$key]] = (int) $row['c'];
				}
			}
		} catch (PDOException $e) {
		}
		return ['days' => $labels, 'impressions' => $impressions, 'clicks' => $clicks];
	}

	/** Per-creative totals over the range — the admin breakdown table. */
	public static function statsPerAd(int $days = 30): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return [];
		}
		$ads = Database::table('ads');
		$stats = Database::table('ad_stats_daily');
		try {
			$stmt = $pdo->prepare("SELECT a.`id`, a.`name`, a.`type`, a.`zone`, a.`status`,
					COALESCE(SUM(s.`impressions`), 0) AS impressions,
					COALESCE(SUM(s.`clicks`), 0) AS clicks
				FROM `{$ads}` a
				LEFT JOIN `{$stats}` s ON s.`ad_id` = a.`id` AND s.`day` >= ?
				GROUP BY a.`id` ORDER BY impressions DESC, a.`created_at` DESC");
			$stmt->execute([date('Y-m-d', time() - $days * 86400)]);
			$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (PDOException $e) {
			return [];
		}
		foreach ($rows as &$row) {
			$imps = (int) $row['impressions'];
			$row['impressions'] = $imps;
			$row['clicks'] = (int) $row['clicks'];
			$row['ctr'] = $imps > 0 ? round($row['clicks'] / $imps * 100, 2) : 0.0;
		}
		return $rows;
	}

	/** Lifetime + range totals for one purchase (primary + its add-on placements). */
	public static function statsForAd(int $adId, int $days = 30): array
	{
		$ids = array_merge([$adId], array_map(fn($c) => (int) $c['id'], self::childrenOf($adId)));
		$out = ['impressions' => 0, 'clicks' => 0, 'ctr' => 0.0, 'series' => self::statsSeriesForIds($ids, $days)];
		$pdo = Database::getInstance();
		if (!$pdo) {
			return $out;
		}
		try {
			$marks = implode(',', array_fill(0, count($ids), '?'));
			$stmt = $pdo->prepare("SELECT COALESCE(SUM(`impressions`), 0), COALESCE(SUM(`clicks`), 0)
				FROM `" . Database::table('ad_stats_daily') . "` WHERE `ad_id` IN ({$marks})");
			$stmt->execute($ids);
			[$imps, $clicks] = $stmt->fetch(PDO::FETCH_NUM) ?: [0, 0];
			$out['impressions'] = (int) $imps;
			$out['clicks'] = (int) $clicks;
			$out['ctr'] = $out['impressions'] > 0 ? round($out['clicks'] / $out['impressions'] * 100, 2) : 0.0;
		} catch (PDOException $e) {
		}
		return $out;
	}

	/* ---------------- Banner files ---------------- */

	/** Where uploaded creatives live: outside the webroot, served only through `ad_banner`. */
	public static function bannerDir(): string
	{
		return (defined('DATA_DIR') ? DATA_DIR : dirname(__DIR__, 3) . '/data') . '/ads';
	}

	public static function bannerSignature(int $adId): string
	{
		return Crypto::sign('ad-banner', (string) $adId);
	}

	public static function bannerUrl(int $adId): string
	{
		return APP_URL . '/api.php?action=ad_banner&id=' . $adId
			. '&sig=' . self::bannerSignature($adId);
	}

	private static function phpMemoryLimitBytes(): int
	{
		$value = trim((string) ini_get('memory_limit'));
		if ($value === '' || $value === '-1') {
			return 0;
		}
		$unit = strtolower(substr($value, -1));
		$number = (float) $value;
		return (int) round($number * match ($unit) {
			'g' => 1024 ** 3,
			'm' => 1024 ** 2,
			'k' => 1024,
			default => 1,
		});
	}

	/**
	 * Validate and store an uploaded banner. The extension comes from what the bytes say the
	 * file is, never from the client's filename; the name is random so nothing about the
	 * uploader leaks into a URL.
	 *
	 * Static creatives (jpg/png/webp) larger than their zone's target box are cover-cropped
	 * to exactly that box, so every ad in a zone renders at one predictable size. Animated
	 * GIFs are stored as-is — GD would flatten them to one frame — and the slot CSS caps
	 * their display height instead.
	 *
	 * @return array{success: bool, error?: string, path?: string, mime?: string}
	 */
	public static function saveBanner(int $adId, array $file): array
	{
		$ad = self::get($adId);
		if (!$ad) {
			return ['success' => false, 'error' => 'not_found'];
		}
		if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
			return ['success' => false, 'error' => 'upload'];
		}
		$maxKb = max(64, (int) Database::getSetting('ads_max_banner_kb', 5120));
		$actualSize = @filesize((string) $file['tmp_name']);
		if ($actualSize === false || $actualSize < 1 || $actualSize > $maxKb * 1024) {
			return ['success' => false, 'error' => 'too_large'];
		}
		$info = @getimagesize($file['tmp_name']);
		$types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
		if ($info === false || !isset($types[$info[2]])) {
			return ['success' => false, 'error' => 'not_image'];
		}
		$width = (int) ($info[0] ?? 0);
		$height = (int) ($info[1] ?? 0);
		$pixels = $width * $height;
		$maxPixels = max(1_000_000, min(
			100_000_000,
			(int) Database::getSetting('ads_max_banner_pixels', 40_000_000)
		));
		if ($width < 1 || $height < 1 || $width > 16384 || $height > 16384 || $pixels > $maxPixels) {
			return ['success' => false, 'error' => 'dimensions'];
		}

		// GD expands compressed input to several bytes per pixel. Refuse before decode when
		// source + destination surfaces would consume the process's safe memory headroom.
		[$targetWidth, $targetHeight] = AdRenderer::zoneDims((string) $ad['zone']);
		$willDecode = $info[2] !== IMAGETYPE_GIF
			&& $width >= $targetWidth && $height >= $targetHeight
			&& ($width !== $targetWidth || $height !== $targetHeight);
		if ($willDecode) {
			$estimatedBytes = ($pixels * 5) + (max(1, $targetWidth * $targetHeight) * 5)
				+ (int) $actualSize;
			$memoryLimit = self::phpMemoryLimitBytes();
			if ($estimatedBytes > 256 * 1024 * 1024
				|| ($memoryLimit > 0
					&& memory_get_usage(true) + $estimatedBytes
						> max(0, $memoryLimit - 16 * 1024 * 1024))) {
				return ['success' => false, 'error' => 'dimensions'];
			}
		}
		$dir = self::bannerDir();
		if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
			return ['success' => false, 'error' => 'storage'];
		}
		$name = bin2hex(random_bytes(16)) . '.' . $types[$info[2]];
		$dest = $dir . '/' . $name;
		$moved = is_uploaded_file($file['tmp_name'])
			? move_uploaded_file($file['tmp_name'], $dest)
			: @rename($file['tmp_name'], $dest);
		if (!$moved) {
			return ['success' => false, 'error' => 'storage'];
		}
		self::cropToZone($dest, $info[2], (string) $ad['zone']);
		$mime = $info['mime'] ?? 'application/octet-stream';
		$pdo = Database::getInstance();
		try {
			$pdo->prepare("UPDATE `" . Database::table('ads') . "`
				SET `image_path` = ?, `image_mime` = ?, `image_url` = '', `updated_at` = ? WHERE `id` = ?")
				->execute([$name, $mime, time(), $adId]);
		} catch (PDOException $e) {
			@unlink($dest);
			return ['success' => false, 'error' => 'db'];
		}
		self::deleteBannerFile($ad['image_path'] ?? null);
		return ['success' => true, 'path' => $name, 'mime' => $mime];
	}

	/**
	 * Cover-crop a stored banner to its zone's target box, in place. Only shrinks: an image
	 * already smaller than the box (or an animated GIF, or a missing GD) is left untouched —
	 * upscaling would only blur what the buyer uploaded.
	 */
	private static function cropToZone(string $path, int $imageType, string $zone): void
	{
		if ($imageType === IMAGETYPE_GIF || !function_exists('imagecreatefromjpeg')) {
			return;
		}
		[$tw, $th] = AdRenderer::zoneDims($zone);
		if ($tw <= 0 || $th <= 0) {
			return;
		}
		[$sw, $sh] = @getimagesize($path) ?: [0, 0];
		if ($sw < $tw || $sh < $th || ($sw === $tw && $sh === $th)) {
			return;
		}
		$src = match ($imageType) {
			IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
			IMAGETYPE_PNG => @imagecreatefrompng($path),
			IMAGETYPE_WEBP => @imagecreatefromwebp($path),
			default => false,
		};
		if (!$src) {
			return; // undecodable — keep the original rather than destroy it
		}
		// Scale to cover the box, then take its center.
		$scale = max($tw / $sw, $th / $sh);
		$cw = (int) round($tw / $scale);
		$ch = (int) round($th / $scale);
		$sx = (int) max(0, ($sw - $cw) / 2);
		$sy = (int) max(0, ($sh - $ch) / 2);
		$dst = imagecreatetruecolor($tw, $th);
		if ($imageType === IMAGETYPE_PNG || $imageType === IMAGETYPE_WEBP) {
			imagealphablending($dst, false);
			imagesavealpha($dst, true);
		}
		imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $tw, $th, $cw, $ch);
		match ($imageType) {
			IMAGETYPE_JPEG => @imagejpeg($dst, $path, 88),
			IMAGETYPE_PNG => @imagepng($dst, $path),
			IMAGETYPE_WEBP => @imagewebp($dst, $path, 88),
		};
		// No imagedestroy(): deprecated in PHP 8.5, and GdImage objects have been freed by
		// refcount since 8.0. Both handles are function-locals and this returns immediately.
		unset($src, $dst);
	}

	/**
	 * Give every add-on placement that has no banner of its own a copy of the primary's,
	 * re-cropped to its zone's box. Runs right before checkout, so what the reviewer sees
	 * is exactly what each zone will show.
	 */
	public static function copyBannerToChildren(int $primaryId): void
	{
		$primary = self::get($primaryId);
		if (!$primary || empty($primary['image_path'])) {
			return;
		}
		$src = self::bannerDir() . '/' . $primary['image_path'];
		if (!is_file($src)) {
			return;
		}
		$ext = pathinfo((string) $primary['image_path'], PATHINFO_EXTENSION);
		$types = ['jpg' => IMAGETYPE_JPEG, 'png' => IMAGETYPE_PNG, 'webp' => IMAGETYPE_WEBP, 'gif' => IMAGETYPE_GIF];
		$pdo = Database::getInstance();
		foreach (self::childrenOf($primaryId) as $child) {
			if (!empty($child['image_path'])) {
				continue;
			}
			$name = bin2hex(random_bytes(16)) . '.' . $ext;
			$dest = self::bannerDir() . '/' . $name;
			if (!@copy($src, $dest)) {
				continue;
			}
			self::cropToZone($dest, $types[$ext] ?? IMAGETYPE_JPEG, (string) $child['zone']);
			try {
				$pdo->prepare("UPDATE `" . Database::table('ads') . "`
					SET `image_path` = ?, `image_mime` = ?, `updated_at` = ? WHERE `id` = ?")
					->execute([$name, (string) $primary['image_mime'], time(), (int) $child['id']]);
			} catch (PDOException $e) {
				@unlink($dest);
			}
		}
	}

	/** Remove a stored banner file. The name is ours (hex + known extension), but stay paranoid. */
	public static function deleteBannerFile(?string $name): void
	{
		if ($name === null || $name === '' || !preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|gif)$/', $name)) {
			return;
		}
		@unlink(self::bannerDir() . '/' . $name);
	}

	/** Retry the durable ad-banner deletion outbox. */
	public static function processBannerDeletionQueue(int $limit = 100): array
	{
		$result = ['processed' => 0, 'deleted' => 0, 'failed' => 0];
		$pdo = Database::getInstance();
		if (!$pdo) {
			return $result;
		}
		$limit = max(1, min(500, $limit));
		$lockName = 'fh:ad-delete:' . sha1(
			(defined('DB_NAME') ? DB_NAME : '') . ':' . Database::table('ad_file_deletion_queue')
		);
		try {
			$lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
			$lock->execute([$lockName]);
			if ((int) $lock->fetchColumn() !== 1) {
				return $result;
			}
			$table = Database::table('ad_file_deletion_queue');
			$stmt = $pdo->prepare(
				"SELECT `filename`, `attempts` FROM `{$table}`
				 WHERE `next_attempt_at` <= ? ORDER BY `created_at` LIMIT {$limit}"
			);
			$stmt->execute([time()]);
			$remove = $pdo->prepare("DELETE FROM `{$table}` WHERE `filename` = ?");
			$retry = $pdo->prepare(
				"UPDATE `{$table}` SET `attempts` = `attempts` + 1,
				 `next_attempt_at` = ?, `last_error` = ? WHERE `filename` = ?"
			);
			foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $job) {
				$name = (string) $job['filename'];
				$result['processed']++;
				$path = self::bannerDir() . '/' . $name;
				$ok = preg_match('/\A[a-f0-9]{32}\.(jpg|png|webp|gif)\z/D', $name) === 1
					&& (!file_exists($path) || (is_file($path) && @unlink($path)));
				if ($ok) {
					$remove->execute([$name]);
					$result['deleted']++;
				} else {
					$attempt = (int) $job['attempts'] + 1;
					$retry->execute([
						time() + min(86400, 30 * (2 ** min(11, $attempt - 1))),
						'Banner file could not be removed.',
						$name,
					]);
					$result['failed']++;
				}
			}
		} catch (Throwable $e) {
			error_log('Ad banner deletion worker failed: ' . $e->getMessage());
			$result['failed']++;
		} finally {
			try {
				$release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
				$release->execute([$lockName]);
			} catch (Throwable $e) {
			}
		}
		return $result;
	}

	/* ---------------- Packages ---------------- */

	public static function packageGet(int $id): ?array
	{
		$pdo = Database::getInstance();
		if (!$pdo || $id <= 0) {
			return null;
		}
		try {
			$stmt = $pdo->prepare("SELECT * FROM `" . Database::table('ad_packages') . "` WHERE `id` = ?");
			$stmt->execute([$id]);
			return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
		} catch (PDOException $e) {
			return null;
		}
	}

	public static function packagesAll(): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return [];
		}
		try {
			$stmt = $pdo->query("SELECT * FROM `" . Database::table('ad_packages') . "` ORDER BY `sort` ASC, `id` ASC");
			return $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (PDOException $e) {
			return [];
		}
	}

	/** Enabled packages, optionally narrowed to one kind ('placement' | 'boost'). */
	public static function packagesEnabled(?string $kind = null): array
	{
		return array_values(array_filter(
			self::packagesAll(),
			fn($p) => !empty($p['enabled']) && ($kind === null || ($p['kind'] ?? 'placement') === $kind)
		));
	}

	public static function packageSave(?int $id, array $data): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return ['success' => false, 'error' => 'db'];
		}
		$table = Database::table('ad_packages');
		$kind = ($data['kind'] ?? 'placement') === 'boost' ? 'boost' : 'placement';
		// Add-on zones for sale with this placement: {zone: surcharge_minor}. Only known
		// zones other than the base one survive; a boost sells no zones at all.
		$addons = [];
		if ($kind === 'placement') {
			$raw = is_array($data['addon_zones'] ?? null) ? $data['addon_zones']
				: (json_decode((string) ($data['addon_zones'] ?? ''), true) ?: []);
			foreach ($raw as $zone => $price) {
				if (isset(AdRenderer::ZONES[$zone]) && $zone !== (string) ($data['zone'] ?? '')) {
					$addons[$zone] = max(0, (int) $price);
				}
			}
		}
		$fields = [
			'name' => (string) ($data['name'] ?? ''),
			'description' => (string) ($data['description'] ?? ''),
			'kind' => $kind,
			// A boost is zone-independent — it strengthens an ad wherever it already runs.
			'zone' => $kind === 'boost' ? '' : (string) ($data['zone'] ?? ''),
			'addon_zones' => $addons ? json_encode($addons) : '',
			'duration_days' => max(1, (int) ($data['duration_days'] ?? 30)),
			'amount_minor' => max(0, (int) ($data['amount_minor'] ?? 0)),
			'currency' => strtoupper(substr((string) ($data['currency'] ?? 'PLN'), 0, 3)),
			// What a purchased ad enters the rotation with (placement), or how much extra
			// weight a boost adds while it lasts. "Plan za 10 zł → priorytet 10, za 100 zł → 80".
			'priority' => max(1, min(100, (int) ($data['priority'] ?? 10))),
			'weight_bonus' => max(0, min(500, (int) ($data['weight_bonus'] ?? 0))),
			'enabled' => !empty($data['enabled']) ? 1 : 0,
			'sort' => (int) ($data['sort'] ?? 0),
		];
		try {
			if ($id) {
				$sets = implode(', ', array_map(fn($k) => "`{$k}` = ?", array_keys($fields)));
				$stmt = $pdo->prepare("UPDATE `{$table}` SET {$sets} WHERE `id` = ?");
				$stmt->execute([...array_values($fields), $id]);
			} else {
				$fields['created_at'] = time();
				$cols = implode(', ', array_map(fn($k) => "`{$k}`", array_keys($fields)));
				$marks = implode(', ', array_fill(0, count($fields), '?'));
				$stmt = $pdo->prepare("INSERT INTO `{$table}` ({$cols}) VALUES ({$marks})");
				$stmt->execute(array_values($fields));
				$id = (int) $pdo->lastInsertId();
			}
			return ['success' => true, 'id' => $id];
		} catch (PDOException $e) {
			return ['success' => false, 'error' => 'db'];
		}
	}

	public static function packageDelete(int $id): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		try {
			$pending = $pdo->prepare(
				"SELECT COUNT(*) FROM `" . Database::table('payments') . "`
				 WHERE `package_id` = ? AND `granted_at` IS NULL
				   AND `status` IN (?, ?, ?)"
			);
			$pending->execute([
				$id,
				PaymentRepository::NEW,
				PaymentRepository::PENDING,
				PaymentRepository::PROCESSING,
			]);
			if ((int) $pending->fetchColumn() > 0) {
				$disable = $pdo->prepare(
					"UPDATE `" . Database::table('ad_packages') . "` SET `enabled` = 0 WHERE `id` = ?"
				);
				$disable->execute([$id]);
				return $disable->rowCount() > 0;
			}
			$delete = $pdo->prepare(
				"DELETE FROM `" . Database::table('ad_packages') . "` WHERE `id` = ?"
			);
			$delete->execute([$id]);
			return $delete->rowCount() > 0;
		} catch (PDOException $e) {
			return false;
		}
	}
}
