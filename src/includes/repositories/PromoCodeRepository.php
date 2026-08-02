<?php
/**
 * PromoCodeRepository (runda 9) — discount codes for the built-in premium checkout.
 *
 * Deliberately percent-only: a percentage needs no currency. A code may apply to every paid
 * plan or one specific plan. The cap is 90% — a 100% code would mean a zero-amount provider
 * order, and "free by code" is what the admin grant action already does better.
 *
 * A code is *validated* at checkout but *redeemed* only at fulfilment: the payment can
 * still fail after the redirect, and a failed payment must not eat one of the code's uses.
 * Redemption is a guarded UPDATE, so two concurrent fulfilments cannot overspend the cap.
 */
final class PromoCodeRepository
{
	private static function table(): string
	{
		return Database::table('promo_codes');
	}

	/** Uppercase, trimmed — the one canonical spelling, applied on both write and lookup. */
	public static function normalize(string $code): string
	{
		return mb_strtoupper(trim($code));
	}

	public static function all(): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return [];
		}
		try {
			$promos = self::table();
			$plans = Database::table('plans');
			$stmt = $pdo->query(
				"SELECT pc.*, p.`name` AS `plan_name`
				 FROM `{$promos}` pc
				 LEFT JOIN `{$plans}` p ON p.`id` = pc.`plan_id`
				 ORDER BY pc.`created_at` DESC"
			);
			return $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (PDOException $e) {
			return [];
		}
	}

	public static function findByCode(string $code): ?array
	{
		$pdo = Database::getInstance();
		$code = self::normalize($code);
		if (!$pdo || $code === '') {
			return null;
		}
		try {
			$stmt = $pdo->prepare("SELECT * FROM `" . self::table() . "` WHERE `code` = ?");
			$stmt->execute([$code]);
			return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
		} catch (PDOException $e) {
			return null;
		}
	}

	/**
	 * The row, but only while it is actually usable: enabled, not expired, uses left.
	 * This is the question the checkout asks; the answer decides the charged amount.
	 */
	public static function validate(string $code, ?int $planId = null): ?array
	{
		$row = self::findByCode($code);
		if (!$row || empty($row['enabled'])) {
			return null;
		}
		if (!empty($row['expires_at']) && (int) $row['expires_at'] < time()) {
			return null;
		}
		if ((int) $row['max_uses'] > 0 && (int) $row['used_count'] >= (int) $row['max_uses']) {
			return null;
		}
		if (($row['scope'] ?? 'all') === 'plan') {
			$scopePlanId = (int) ($row['plan_id'] ?? 0);
			// A deleted target plan leaves the code visibly scoped but unusable; it must never
			// broaden silently to "all plans".
			if ($scopePlanId <= 0 || ($planId !== null && $scopePlanId !== $planId)) {
				return null;
			}
		}
		return $row;
	}

	/** What a validated code does to an amount. Never returns less than 1 minor unit. */
	public static function discountedAmount(array $codeRow, int $amountMinor): int
	{
		$pct = max(1, min(90, (int) $codeRow['percent_off']));
		return max(1, (int) round($amountMinor * (100 - $pct) / 100));
	}

	/**
	 * Spend one use, atomically. The WHERE repeats the whole validity check so a code that
	 * expired or filled up between checkout and fulfilment simply stops being counted —
	 * the buyer keeps the discount (the order was priced already), the cap keeps its truth.
	 */
	public static function redeem(string $code): bool
	{
		$pdo = Database::getInstance();
		$code = self::normalize($code);
		if (!$pdo || $code === '') {
			return false;
		}
		try {
			$stmt = $pdo->prepare("UPDATE `" . self::table() . "`
				SET `used_count` = `used_count` + 1
				WHERE `code` = ? AND `enabled` = 1
				AND (`max_uses` = 0 OR `used_count` < `max_uses`)");
			$stmt->execute([$code]);
			return $stmt->rowCount() > 0;
		} catch (PDOException $e) {
			return false;
		}
	}

	/**
	 * Reserve one use for a payment while the payment row is created.
	 * The caller must own a transaction.
	 *
	 * @return array|null immutable promo facts stored with the payment
	 */
	public static function reserveForPayment(
		PDO $pdo,
		string $extOrderId,
		string $code,
		int $planId
	): ?array
	{
		if (!$pdo->inTransaction() || $extOrderId === '' || $planId <= 0) {
			return null;
		}
		$code = self::normalize($code);
		try {
			$stmt = $pdo->prepare(
				"SELECT * FROM `" . self::table() . "`
				 WHERE `code` = ? AND `enabled` = 1
				   AND (`expires_at` IS NULL OR `expires_at` >= ?)
				   AND (
				    `scope` = 'all'
				    OR (`scope` = 'plan' AND `plan_id` = ?)
				   )
				 FOR UPDATE"
			);
			$stmt->execute([$code, time(), $planId]);
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			if (!$row) {
				return null;
			}
			$maxUses = (int) $row['max_uses'];
			if ($maxUses > 0
				&& (int) $row['used_count'] + (int) $row['reserved_count'] >= $maxUses) {
				return null;
			}

			$update = $pdo->prepare(
				"UPDATE `" . self::table() . "`
				 SET `reserved_count` = `reserved_count` + 1
				 WHERE `id` = ?
				   AND (`max_uses` = 0 OR `used_count` + `reserved_count` < `max_uses`)"
			);
			$update->execute([(int) $row['id']]);
			if ($update->rowCount() !== 1) {
				return null;
			}
			$now = time();
			$pdo->prepare(
				"INSERT INTO `" . Database::table('promo_reservations') . "`
				 (`ext_order_id`, `promo_id`, `status`, `created_at`, `updated_at`)
				 VALUES (?, ?, 'reserved', ?, ?)"
			)->execute([$extOrderId, (int) $row['id'], $now, $now]);
			return [
				'code' => (string) $row['code'],
				'percent_off' => (int) $row['percent_off'],
				'scope' => (string) ($row['scope'] ?? 'all'),
				'plan_id' => isset($row['plan_id']) ? (int) $row['plan_id'] : null,
			];
		} catch (PDOException $e) {
			return null;
		}
	}

	/** Convert a checkout reservation into one used code inside fulfilment's transaction. */
	public static function commitReservation(string $extOrderId): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo || !$pdo->inTransaction()) {
			return false;
		}
		$reservations = Database::table('promo_reservations');
		try {
			$stmt = $pdo->prepare(
				"SELECT `promo_id`, `status` FROM `{$reservations}`
				 WHERE `ext_order_id` = ? FOR UPDATE"
			);
			$stmt->execute([$extOrderId]);
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			if (!$row) {
				return false;
			}
			if ($row['status'] === 'redeemed') {
				return true;
			}
			if ($row['status'] !== 'reserved') {
				return false;
			}
			$promo = $pdo->prepare(
				"UPDATE `" . self::table() . "`
				 SET `reserved_count` = `reserved_count` - 1,
				     `used_count` = `used_count` + 1
				 WHERE `id` = ? AND `reserved_count` > 0"
			);
			$promo->execute([(int) $row['promo_id']]);
			if ($promo->rowCount() !== 1) {
				return false;
			}
			$done = $pdo->prepare(
				"UPDATE `{$reservations}` SET `status` = 'redeemed', `updated_at` = ?
				 WHERE `ext_order_id` = ? AND `status` = 'reserved'"
			);
			$done->execute([time(), $extOrderId]);
			return $done->rowCount() === 1;
		} catch (PDOException $e) {
			return false;
		}
	}

	/** Release an unpaid/canceled checkout's reserved use. Caller may own a transaction. */
	public static function releaseReservation(string $extOrderId): bool
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
			$reservations = Database::table('promo_reservations');
			$stmt = $pdo->prepare(
				"SELECT `promo_id`, `status` FROM `{$reservations}`
				 WHERE `ext_order_id` = ? FOR UPDATE"
			);
			$stmt->execute([$extOrderId]);
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			if (!$row || $row['status'] !== 'reserved') {
				if ($ownsTransaction) {
					$pdo->commit();
				}
				return $row !== false;
			}
			$promo = $pdo->prepare(
				"UPDATE `" . self::table() . "`
				 SET `reserved_count` = `reserved_count` - 1
				 WHERE `id` = ? AND `reserved_count` > 0"
			);
			$promo->execute([(int) $row['promo_id']]);
			if ($promo->rowCount() !== 1) {
				throw new RuntimeException('Promo reservation counter is inconsistent.');
			}
			$pdo->prepare(
				"UPDATE `{$reservations}` SET `status` = 'released', `updated_at` = ?
				 WHERE `ext_order_id` = ? AND `status` = 'reserved'"
			)->execute([time(), $extOrderId]);
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
	 * Create or update a code from the admin form.
	 *
	 * @return array{success: bool, id?: int, error?: string}
	 */
	public static function save(?int $id, array $data): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return ['success' => false, 'error' => 'db'];
		}
		$code = self::normalize((string) ($data['code'] ?? ''));
		if ($code === '' || mb_strlen($code) > 40 || !preg_match('/^[A-Z0-9_-]+$/u', $code)) {
			return ['success' => false, 'error' => 'code'];
		}
		$existing = self::findByCode($code);
		if ($existing && (int) $existing['id'] !== (int) $id) {
			return ['success' => false, 'error' => 'duplicate'];
		}
		$scope = ($data['scope'] ?? 'all') === 'plan' ? 'plan' : 'all';
		$planId = $scope === 'plan' ? max(0, (int) ($data['plan_id'] ?? 0)) : 0;
		if ($scope === 'plan') {
			$plan = $planId > 0 ? PlanRepository::get($planId) : null;
			if (!$plan || ($plan['kind'] ?? 'paid') !== 'paid') {
				return ['success' => false, 'error' => 'plan'];
			}
		}
		$fields = [
			'code' => $code,
			'scope' => $scope,
			'plan_id' => $planId > 0 ? $planId : null,
			'percent_off' => max(1, min(90, (int) ($data['percent_off'] ?? 10))),
			'max_uses' => max(0, (int) ($data['max_uses'] ?? 0)),
			'expires_at' => !empty($data['expires_at']) ? (int) $data['expires_at'] : null,
			'enabled' => !empty($data['enabled']) ? 1 : 0,
		];
		try {
			if ($id) {
				$sets = implode(', ', array_map(fn($k) => "`{$k}` = ?", array_keys($fields)));
				$stmt = $pdo->prepare("UPDATE `" . self::table() . "` SET {$sets} WHERE `id` = ?");
				$stmt->execute([...array_values($fields), $id]);
			} else {
				$fields['used_count'] = 0;
				$fields['created_at'] = time();
				$cols = implode(', ', array_map(fn($k) => "`{$k}`", array_keys($fields)));
				$marks = implode(', ', array_fill(0, count($fields), '?'));
				$pdo->prepare("INSERT INTO `" . self::table() . "` ({$cols}) VALUES ({$marks})")
					->execute(array_values($fields));
				$id = (int) $pdo->lastInsertId();
			}
			return ['success' => true, 'id' => (int) $id];
		} catch (PDOException $e) {
			return ['success' => false, 'error' => 'db'];
		}
	}

	public static function delete(int $id): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo || $id <= 0) {
			return false;
		}
		try {
			$stmt = $pdo->prepare("DELETE FROM `" . self::table() . "` WHERE `id` = ?");
			$stmt->execute([$id]);
			return $stmt->rowCount() > 0;
		} catch (PDOException $e) {
			return false;
		}
	}
}
