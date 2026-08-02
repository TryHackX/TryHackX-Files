<?php
/**
 * Payment attempts (pt 10).
 *
 * One row per checkout the buyer started. It exists for three reasons, all of which the
 * provider-agnostic `premium_activate` flow could ignore and a real integration cannot:
 *
 *   - **Identity.** A provider notification carries the order id *we* invented and nothing
 *     else useful. This table is what turns it back into (plan, buyer).
 *   - **Idempotency.** PayU retries a notification until it gets a 200, and it sends more than
 *     one status on the way to COMPLETED. A short PROCESSING lease elects one worker;
 *     `granted_at` is written only after the product was actually granted.
 *   - **Evidence.** "Money left my account and nothing happened" needs somewhere to look. The
 *     audit log has the events; this has the order.
 *
 * No card data, no provider tokens — only the amount, the currency, and the ids needed to
 * reconcile.
 */
final class PaymentRepository
{
	/** Statuses we store. Provider vocabularies are mapped onto these before they get here. */
	public const NEW = 'NEW';
	public const PENDING = 'PENDING';
	public const PROCESSING = 'PROCESSING';
	public const COMPLETED = 'COMPLETED';
	public const CANCELED = 'CANCELED';
	public const REFUNDING = 'REFUNDING';
	// Money given back after completion (a rejected ad refunded through the provider).
	// A terminal state of its own so revenue stats can exclude it without losing the row.
	public const REFUNDED = 'REFUNDED';

	/** Serialize a provider refund request for a completed payment. */
	public static function claimRefund(string $extOrderId, int $leaseSeconds = 900): ?string
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return null;
		}
		$token = bin2hex(random_bytes(24));
		$now = time();
		try {
			$stmt = $pdo->prepare(
				"UPDATE `" . Database::table('payments') . "`
				 SET `status` = ?, `processing_token` = ?, `processing_started_at` = ?,
				     `processing_expires_at` = ?, `updated_at` = ?
				 WHERE `ext_order_id` = ? AND `granted_at` IS NOT NULL
				   AND (`status` = ? OR (`status` = ? AND `processing_expires_at` <= ?))"
			);
			$stmt->execute([
				self::REFUNDING,
				$token,
				$now,
				$now + max(60, min(604800, $leaseSeconds)),
				$now,
				$extOrderId,
				self::COMPLETED,
				self::REFUNDING,
				$now,
			]);
			return $stmt->rowCount() === 1 ? $token : null;
		} catch (PDOException $e) {
			return null;
		}
	}

	public static function releaseRefund(string $extOrderId, string $token): void
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return;
		}
		try {
			$stmt = $pdo->prepare(
				"UPDATE `" . Database::table('payments') . "`
				 SET `status` = ?, `processing_token` = NULL, `processing_started_at` = NULL,
				     `processing_expires_at` = NULL, `updated_at` = ?
				 WHERE `ext_order_id` = ? AND `status` = ? AND `processing_token` = ?"
			);
			$stmt->execute([self::COMPLETED, time(), $extOrderId, self::REFUNDING, $token]);
		} catch (PDOException $e) {
		}
	}

	/**
	 * Persist the correlation data needed by an asynchronous refund callback without
	 * overwriting checkout metadata such as a promo reservation or ad add-on list.
	 */
	public static function setRefundContext(string $extOrderId, string $token, array $context): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		try {
			$pdo->beginTransaction();
			$stmt = $pdo->prepare(
				"SELECT `meta` FROM `" . Database::table('payments') . "`
				 WHERE `ext_order_id` = ? AND `status` = ? AND `processing_token` = ?
				 FOR UPDATE"
			);
			$stmt->execute([$extOrderId, self::REFUNDING, $token]);
			$raw = $stmt->fetchColumn();
			if ($raw === false) {
				$pdo->rollBack();
				return false;
			}
			$meta = json_decode((string) $raw, true);
			$meta = is_array($meta) ? $meta : [];
			// The lease token is authoritative and must never be replaced by caller data.
			$meta['_refund'] = ['token' => $token] + $context;
			$update = $pdo->prepare(
				"UPDATE `" . Database::table('payments') . "` SET `meta` = ?, `updated_at` = ?
				 WHERE `ext_order_id` = ? AND `status` = ? AND `processing_token` = ?"
			);
			$update->execute([
				json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
				time(),
				$extOrderId,
				self::REFUNDING,
				$token,
			]);
			if ($update->rowCount() !== 1) {
				$pdo->rollBack();
				return false;
			}
			$pdo->commit();
			return true;
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return false;
		}
	}

	public static function refundContext(array $payment): array
	{
		$meta = json_decode((string) ($payment['meta'] ?? ''), true);
		return is_array($meta['_refund'] ?? null) ? $meta['_refund'] : [];
	}

	/**
	 * Finalize the ledger and revoke only the entitlement this payment granted, atomically.
	 *
	 * @return array{success: bool, revoked: bool, user_id: int}
	 */
	public static function finalizeRefund(
		string $extOrderId,
		string $token,
		int $expectedGroupId
	): array {
		$pdo = Database::getInstance();
		$out = ['success' => false, 'revoked' => false, 'user_id' => 0];
		if (!$pdo) {
			return $out;
		}
		try {
			$pdo->beginTransaction();
			$paymentQuery = $pdo->prepare(
				"SELECT `user_id` FROM `" . Database::table('payments') . "`
				 WHERE `ext_order_id` = ? AND `status` = ? AND `processing_token` = ?
				 AND `granted_at` IS NOT NULL FOR UPDATE"
			);
			$paymentQuery->execute([$extOrderId, self::REFUNDING, $token]);
			$userId = (int) ($paymentQuery->fetchColumn() ?: 0);
			if ($userId < 1) {
				$pdo->rollBack();
				return $out;
			}
			$out['user_id'] = $userId;

			$userQuery = $pdo->prepare(
				"SELECT `group_id`, `group_payment_ext_order_id`
				 FROM `" . Database::table('users') . "`
				 WHERE `id` = ? FOR UPDATE"
			);
			$userQuery->execute([$userId]);
			$user = $userQuery->fetch(PDO::FETCH_ASSOC) ?: [];
			$currentGroup = (int) ($user['group_id'] ?? 0);
			$currentPayment = (string) ($user['group_payment_ext_order_id'] ?? '');
			if ($expectedGroupId > 0
				&& $currentGroup === $expectedGroupId
				&& hash_equals($extOrderId, $currentPayment)) {
				$revoke = $pdo->prepare(
					"UPDATE `" . Database::table('users') . "`
					 SET `group_id` = NULL, `group_expires_at` = NULL, `group_changed_at` = ?,
					     `group_payment_ext_order_id` = NULL
					 WHERE `id` = ? AND `group_id` = ? AND `group_payment_ext_order_id` = ?"
				);
				$revoke->execute([time(), $userId, $expectedGroupId, $extOrderId]);
				$out['revoked'] = $revoke->rowCount() === 1;
			}

			$finish = $pdo->prepare(
				"UPDATE `" . Database::table('payments') . "`
				 SET `status` = ?, `processing_token` = NULL, `processing_started_at` = NULL,
				     `processing_expires_at` = NULL, `updated_at` = ?
				 WHERE `ext_order_id` = ? AND `status` = ? AND `processing_token` = ?"
			);
			$finish->execute([
				self::REFUNDED, time(), $extOrderId, self::REFUNDING, $token,
			]);
			if ($finish->rowCount() !== 1) {
				throw new RuntimeException('Refund lease was lost before finalization.');
			}
			$pdo->commit();
			$out['success'] = true;
			return $out;
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return $out;
		}
	}

	/** Results returned by claimForGrant(). */
	public const CLAIM_ACQUIRED = 'acquired';
	public const CLAIM_COMPLETED = 'completed';
	public const CLAIM_BUSY = 'busy';
	public const CLAIM_MISSING = 'missing';
	public const CLAIM_ERROR = 'error';

	/** Long enough for normal DB fulfilment, short enough for a crashed worker to recover. */
	private const DEFAULT_LEASE_SECONDS = 300;

	/**
	 * What kind of event a row is (pt 2 / pt 5).
	 *
	 * The table started as a record of checkouts and is now the account's plan history, because
	 * "how did I end up on this plan" has three answers and only one of them involved money.
	 * Keeping them in one table is what makes the history readable in order; `kind` is what
	 * keeps a free grant out of the revenue figures.
	 */
	public const KIND_PURCHASE = 'purchase';
	public const KIND_ADMIN_GRANT = 'admin_grant';
	public const KIND_ADMIN_REVOKE = 'admin_revoke';
	// An ad placement bought through a package (Faza 8). Kept out of `purchase` so the
	// premium revenue stats stay about plans; `ad_id` names what was bought.
	public const KIND_AD = 'ad_purchase';
	// Extra zones added to an EXISTING ad purchase (runda 5): the surcharge-only top-up.
	// A separate kind because fulfilment must not confuse it with a renewal of the base.
	public const KIND_AD_ADDON = 'ad_addon';

	/** A fresh, unguessable order id. Kept short: PayU caps `extOrderId` at 64 characters. */
	public static function newOrderId(): string
	{
		return 'fh-' . dechex(time()) . '-' . bin2hex(random_bytes(8));
	}

	/**
	 * Freeze the parties and operator-controlled document text at checkout time.
	 *
	 * A receipt generated years later must not silently change because the account was renamed,
	 * a plan was deleted, or the installation changed its seller/footer settings.
	 */
	private static function checkoutInvoiceSnapshot(PDO $pdo, int $userId): array
	{
		$settings = [
			'invoice_seller' => '',
			'invoice_prefix' => 'FH',
			'invoice_footer' => '',
		];
		$stmt = $pdo->prepare(
			"SELECT `setting_key`, `setting_value` FROM `" . Database::table('settings') . "`
			 WHERE `setting_key` IN ('invoice_seller', 'invoice_prefix', 'invoice_footer')"
		);
		$stmt->execute();
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$settings[(string) $row['setting_key']] = (string) $row['setting_value'];
		}

		$buyer = ['username' => '#' . $userId, 'email' => ''];
		$stmt = $pdo->prepare(
			"SELECT `username`, `email` FROM `" . Database::table('users') . "` WHERE `id` = ?"
		);
		$stmt->execute([$userId]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		if ($row) {
			$buyer = [
				'username' => (string) $row['username'],
				'email' => (string) $row['email'],
			];
		}

		return [
			'seller' => $settings['invoice_seller'],
			'prefix' => trim($settings['invoice_prefix']) ?: 'FH',
			'footer' => $settings['invoice_footer'],
			'buyer' => $buyer,
		];
	}

	/**
	 * Record a started checkout.
	 *
	 * @return string|null the ext_order_id, or null when the row could not be written — in
	 *                     which case the caller must not send the buyer to pay, because
	 *                     nothing would be able to fulfil the order afterwards.
	 */
	public static function start(
		string $provider,
		int $planId,
		int $userId,
		int $amountMinor,
		string $currency,
		?array $promo = null
	): ?string
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return null;
		}
		$extOrderId = self::newOrderId();
		try {
			$pdo->beginTransaction();
			$planStmt = $pdo->prepare(
				"SELECT * FROM `" . Database::table('plans') . "` WHERE `id` = ? FOR UPDATE"
			);
			$planStmt->execute([$planId]);
			$plan = $planStmt->fetch(PDO::FETCH_ASSOC);
			if (!$plan) {
				$pdo->rollBack();
				return null;
			}
			$snapshot = json_encode([
				'version' => 2,
				'type' => 'plan',
				'product' => $plan,
				'charged_amount_minor' => $amountMinor,
				'charged_currency' => strtoupper($currency),
				'invoice' => self::checkoutInvoiceSnapshot($pdo, $userId),
			], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			if ($snapshot === false) {
				throw new RuntimeException('Could not encode the payment product snapshot.');
			}
			$stmt = $pdo->prepare("INSERT INTO `" . Database::table('payments') . "`
				(`ext_order_id`, `provider`, `plan_id`, `user_id`, `amount_minor`, `currency`,
				 `status`, `kind`, `meta`, `product_snapshot`, `created_at`, `updated_at`)
				VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
			$now = time();
			$stmt->execute([
				$extOrderId, $provider, $planId, $userId, $amountMinor, strtoupper($currency),
				self::NEW, self::KIND_PURCHASE, null, $snapshot, $now, $now,
			]);

			// The reservation references this order. Create the parent payment first, then
			// attach the frozen promotion facts in the same transaction.
			if ($promo !== null) {
				$promoFacts = PromoCodeRepository::reserveForPayment(
					$pdo,
					$extOrderId,
					(string) ($promo['code'] ?? ''),
					$planId
				);
				if ($promoFacts === null) {
					$pdo->rollBack();
					return null;
				}
				$meta = json_encode([
					'promo' => $promoFacts['code'],
					'percent_off' => $promoFacts['percent_off'],
					'promo_scope' => $promoFacts['scope'],
					'promo_plan_id' => $promoFacts['plan_id'],
				]);
				if ($meta === false) {
					throw new RuntimeException('Could not encode the payment promotion snapshot.');
				}
				$update = $pdo->prepare(
					"UPDATE `" . Database::table('payments') . "`
					 SET `meta` = ? WHERE `ext_order_id` = ?"
				);
				$update->execute([$meta, $extOrderId]);
				if ($update->rowCount() !== 1) {
					throw new RuntimeException('Could not attach the promotion to the payment.');
				}
			}
			$pdo->commit();
			return $extOrderId;
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return null;
		}
	}

	/**
	 * Record a started ad checkout (Faza 8). Same contract as start(): the row must exist
	 * before the buyer is sent to pay. `plan_id` stays NULL — `kind` + `ad_id` are what
	 * fulfilment branches on, and `package_id` says which product (a placement or a boost)
	 * this order actually bought.
	 */
	public static function startAd(string $provider, int $adId, int $packageId, int $userId, int $amountMinor, string $currency, string $kind = self::KIND_AD, array $meta = []): ?string
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return null;
		}
		$extOrderId = self::newOrderId();
		try {
			$pdo->beginTransaction();
			$packageStmt = $pdo->prepare(
				"SELECT * FROM `" . Database::table('ad_packages') . "`
				 WHERE `id` = ? FOR UPDATE"
			);
			$packageStmt->execute([$packageId]);
			$package = $packageStmt->fetch(PDO::FETCH_ASSOC);
			if (!$package) {
				$pdo->rollBack();
				return null;
			}
			$snapshot = json_encode([
				'version' => 2,
				'type' => 'ad_package',
				'product' => $package,
				'ad_id' => $adId,
				'charged_amount_minor' => $amountMinor,
				'charged_currency' => strtoupper($currency),
				'invoice' => self::checkoutInvoiceSnapshot($pdo, $userId),
			], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			if ($snapshot === false) {
				throw new RuntimeException('Could not encode the ad product snapshot.');
			}
			$stmt = $pdo->prepare("INSERT INTO `" . Database::table('payments') . "`
				(`ext_order_id`, `provider`, `plan_id`, `ad_id`, `package_id`, `user_id`,
				 `amount_minor`, `currency`, `status`, `kind`, `meta`, `product_snapshot`,
				 `created_at`, `updated_at`)
				VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
			$now = time();
			$stmt->execute([
				$extOrderId, $provider, $adId, $packageId, $userId, $amountMinor, $currency,
				self::NEW, $kind, $meta ? json_encode($meta) : null, $snapshot, $now, $now,
			]);
			$pdo->commit();
			return $extOrderId;
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
			return null;
		}
	}

	/**
	 * Record something an administrator did to an account's plan (pt 2 / pt 5).
	 *
	 * Written as a settled row with no amount: it happened, it cost nothing, and it belongs in
	 * the same chronological list as the purchases so "granted by an admin on the 3rd, expired
	 * on the 5th, bought again on the 6th" reads in order. `stats()` filters it out of revenue.
	 */
	public static function recordAdminAction(string $kind, int $planId, int $userId, int $actorId, string $currency = 'PLN'): void
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return;
		}
		try {
			$stmt = $pdo->prepare("INSERT INTO `" . Database::table('payments') . "`
				(`ext_order_id`, `provider`, `plan_id`, `user_id`, `actor_id`, `amount_minor`, `currency`,
				 `status`, `kind`, `granted_at`, `created_at`, `updated_at`)
				VALUES (?, 'admin', ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)");
			$now = time();
			$stmt->execute([
				self::newOrderId(), $planId, $userId, $actorId, $currency,
				self::COMPLETED, $kind, $now, $now, $now,
			]);
		} catch (PDOException $e) {
		}
	}

	/**
	 * The most recent COMPLETED payment for an ad — what a refund on rejection gives back.
	 * The ad id on a payment row is always the purchase's ROOT ad (children never pay).
	 */
	public static function latestCompletedForAd(int $adId): ?array
	{
		$pdo = Database::getInstance();
		if (!$pdo || $adId <= 0) {
			return null;
		}
		try {
			$stmt = $pdo->prepare("SELECT * FROM `" . Database::table('payments') . "`
				WHERE `ad_id` = ? AND `status` = ? ORDER BY `created_at` DESC LIMIT 1");
			$stmt->execute([$adId, self::COMPLETED]);
			return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
		} catch (PDOException $e) {
			return null;
		}
	}

	public static function byExtOrderId(string $extOrderId): ?array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return null;
		}
		try {
			$stmt = $pdo->prepare("SELECT * FROM `" . Database::table('payments') . "` WHERE `ext_order_id` = ?");
			$stmt->execute([$extOrderId]);
			return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
		} catch (PDOException $e) {
			return null;
		}
	}

	/** Note the provider's own order id, once the order has been registered with them. */
	public static function setProviderOrderId(string $extOrderId, string $providerOrderId): void
	{
		self::update($extOrderId, ['provider_order_id' => $providerOrderId]);
	}

	/** Attach order metadata (JSON) after start() — e.g. the promo code the price used. */
	public static function setMeta(string $extOrderId, array $meta): void
	{
		self::update($extOrderId, ['meta' => json_encode($meta, JSON_UNESCAPED_UNICODE)]);
	}

	public static function setStatus(string $extOrderId, string $status): void
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return;
		}

		$ownsTransaction = !$pdo->inTransaction();
		try {
			if ($ownsTransaction) {
				$pdo->beginTransaction();
			}
			$payments = Database::table('payments');
			$now = time();

			if ($status === self::REFUNDED) {
				// Refund is the one legitimate transition away from completed fulfilment.
				$stmt = $pdo->prepare("UPDATE `{$payments}`
					SET `status` = ?, `updated_at` = ?
					WHERE `ext_order_id` = ? AND `status` = ? AND `granted_at` IS NOT NULL");
				$stmt->execute([$status, $now, $extOrderId, self::COMPLETED]);
				if ($ownsTransaction) {
					$pdo->commit();
				}
				return;
			}

			if (!in_array($status, [self::NEW, self::PENDING, self::CANCELED], true)) {
				if ($ownsTransaction) {
					$pdo->rollBack();
				}
				return; // PROCESSING/COMPLETED belong exclusively to the lease protocol below.
			}

			// Monotonic state machine. Late provider messages may advance or repeat state, but
			// cannot resurrect a canceled order or downgrade a live fulfilment lease.
			$allowed = match ($status) {
				self::NEW => [self::NEW],
				self::PENDING => [self::NEW, self::PENDING, self::PROCESSING],
				self::CANCELED => [self::NEW, self::PENDING, self::PROCESSING, self::CANCELED],
			};
			$marks = implode(',', array_fill(0, count($allowed), '?'));
			$stmt = $pdo->prepare("UPDATE `{$payments}`
				SET `status` = ?, `processing_token` = NULL, `processing_started_at` = NULL,
					`processing_expires_at` = NULL, `updated_at` = ?
				WHERE `ext_order_id` = ? AND `granted_at` IS NULL
				AND `status` IN ({$marks})
				AND (`status` <> ? OR `processing_expires_at` IS NULL OR `processing_expires_at` <= ?)");
			$stmt->execute([
				$status,
				$now,
				$extOrderId,
				...$allowed,
				self::PROCESSING,
				$now,
			]);
			if ($status === self::CANCELED) {
				$read = $pdo->prepare(
					"SELECT `status` FROM `{$payments}` WHERE `ext_order_id` = ? FOR UPDATE"
				);
				$read->execute([$extOrderId]);
				if ($read->fetchColumn() === self::CANCELED
					&& !PromoCodeRepository::releaseReservation($extOrderId)) {
					// No reservation is normal; an existing inconsistent reservation is not.
					$reservation = $pdo->prepare(
						"SELECT `status` FROM `" . Database::table('promo_reservations') . "`
						 WHERE `ext_order_id` = ?"
					);
					$reservation->execute([$extOrderId]);
					if ($reservation->fetchColumn() === 'reserved') {
						throw new RuntimeException('Could not release the payment promo reservation.');
					}
				}
			}
			if ($ownsTransaction) {
				$pdo->commit();
			}
		} catch (Throwable $e) {
			if ($ownsTransaction && $pdo->inTransaction()) {
				$pdo->rollBack();
			}
		}
	}

	/** Persist an authenticated provider event fingerprint for audit and deduplication. */
	public static function recordProviderEvent(
		string $provider,
		string $eventId,
		string $extOrderId,
		string $providerStatus
	): bool {
		if (!preg_match('/\A[0-9a-f]{64}\z/D', $eventId)) {
			return false;
		}
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		try {
			$stmt = $pdo->prepare(
				"INSERT IGNORE INTO `" . Database::table('payment_events') . "`
				 (`provider`, `event_id`, `ext_order_id`, `provider_status`, `received_at`)
				 VALUES (?, ?, ?, ?, ?)"
			);
			$stmt->execute([
				substr($provider, 0, 20),
				$eventId,
				substr($extOrderId, 0, 64),
				substr($providerStatus, 0, 32),
				time(),
			]);
			return $stmt->rowCount() === 1;
		} catch (PDOException $e) {
			return false;
		}
	}

	/**
	 * Acquire a recoverable fulfilment lease.
	 *
	 * `granted_at` is deliberately untouched here: it is proof of completed goods, never a
	 * lock. The random owner token prevents a worker whose lease was taken over from releasing
	 * or completing the new owner's work.
	 *
	 * @return array{state: string, token?: string, lease_expires_at?: int}
	 */
	public static function claimForGrant(string $extOrderId, int $leaseSeconds = self::DEFAULT_LEASE_SECONDS): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return ['state' => self::CLAIM_ERROR];
		}

		$leaseSeconds = max(30, min(3600, $leaseSeconds));
		$now = time();
		$expiresAt = $now + $leaseSeconds;

		try {
			$token = bin2hex(random_bytes(32));
			$stmt = $pdo->prepare("UPDATE `" . Database::table('payments') . "`
				SET `status` = ?, `processing_token` = ?, `processing_started_at` = ?,
					`processing_expires_at` = ?,
					`fulfillment_attempts` = `fulfillment_attempts` + 1,
					`updated_at` = ?
				WHERE `ext_order_id` = ? AND `granted_at` IS NULL
				AND `status` NOT IN (?, ?)
				AND (`status` <> ? OR `processing_expires_at` IS NULL OR `processing_expires_at` <= ?)");
			$stmt->execute([
				self::PROCESSING,
				$token,
				$now,
				$expiresAt,
				$now,
				$extOrderId,
				self::COMPLETED,
				self::REFUNDED,
				self::PROCESSING,
				$now,
			]);
			if ($stmt->rowCount() > 0) {
				return [
					'state' => self::CLAIM_ACQUIRED,
					'token' => $token,
					'lease_expires_at' => $expiresAt,
				];
			}

			// The conditional UPDATE lost to another worker or the payment is terminal. Read
			// the resulting state so callers can distinguish "already done" from "try later".
			$read = $pdo->prepare("SELECT `status`, `granted_at`, `processing_expires_at`
				FROM `" . Database::table('payments') . "` WHERE `ext_order_id` = ?");
			$read->execute([$extOrderId]);
			$row = $read->fetch(PDO::FETCH_ASSOC);
			if (!$row) {
				return ['state' => self::CLAIM_MISSING];
			}
			if ($row['granted_at'] !== null
				|| in_array((string) $row['status'], [self::COMPLETED, self::REFUNDED], true)) {
				return ['state' => self::CLAIM_COMPLETED];
			}
			if ((string) $row['status'] === self::PROCESSING
				&& (int) ($row['processing_expires_at'] ?? 0) > $now) {
				return ['state' => self::CLAIM_BUSY];
			}
			return ['state' => self::CLAIM_ERROR];
		} catch (\Throwable $e) {
			return ['state' => self::CLAIM_ERROR];
		}
	}

	/**
	 * Lock and verify an acquired lease inside the caller's fulfilment transaction.
	 *
	 * Holding this row lock until completeGrant()+COMMIT prevents lease takeover while the
	 * product mutation is in progress, even if an unusually slow fulfilment crosses its TTL.
	 */
	public static function lockGrant(string $extOrderId, string $token): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo || !$pdo->inTransaction() || $token === '') {
			return false;
		}
		try {
			$stmt = $pdo->prepare("SELECT `status`, `granted_at`, `processing_token`
				FROM `" . Database::table('payments') . "`
				WHERE `ext_order_id` = ? FOR UPDATE");
			$stmt->execute([$extOrderId]);
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			return $row
				&& (string) $row['status'] === self::PROCESSING
				&& $row['granted_at'] === null
				&& hash_equals((string) $row['processing_token'], $token);
		} catch (PDOException $e) {
			return false;
		}
	}

	/** Mark actual success. Must run in the same transaction as the product mutation. */
	public static function completeGrant(string $extOrderId, string $token): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo || !$pdo->inTransaction() || $token === '') {
			return false;
		}
		try {
			$now = time();
			$stmt = $pdo->prepare("UPDATE `" . Database::table('payments') . "`
				SET `status` = ?, `granted_at` = ?,
					`processing_token` = NULL, `processing_started_at` = NULL,
					`processing_expires_at` = NULL, `fulfillment_last_error` = NULL, `updated_at` = ?
				WHERE `ext_order_id` = ? AND `status` = ? AND `granted_at` IS NULL
				AND `processing_token` = ?");
			$stmt->execute([
				self::COMPLETED,
				$now,
				$now,
				$extOrderId,
				self::PROCESSING,
				$token,
			]);
			return $stmt->rowCount() > 0;
		} catch (PDOException $e) {
			return false;
		}
	}

	/**
	 * Release a failed lease after the product transaction was rolled back.
	 *
	 * A stale token cannot release a lease another worker has since acquired.
	 */
	public static function failGrant(string $extOrderId, string $token, string $error): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo || $token === '') {
			return false;
		}
		$error = self::normaliseFulfillmentError($error);
		try {
			$now = time();
			$stmt = $pdo->prepare("UPDATE `" . Database::table('payments') . "`
				SET `status` = ?, `processing_token` = NULL, `processing_started_at` = NULL,
					`processing_expires_at` = NULL, `fulfillment_last_error` = ?, `updated_at` = ?
				WHERE `ext_order_id` = ? AND `status` = ? AND `granted_at` IS NULL
				AND `processing_token` = ?");
			$stmt->execute([
				self::PENDING,
				$error,
				$now,
				$extOrderId,
				self::PROCESSING,
				$token,
			]);
			return $stmt->rowCount() > 0;
		} catch (PDOException $e) {
			return false;
		}
	}

	/** Record a pre-claim failure such as a provider amount/currency mismatch. */
	public static function noteGrantError(string $extOrderId, string $error): void
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return;
		}
		try {
			$stmt = $pdo->prepare("UPDATE `" . Database::table('payments') . "`
				SET `fulfillment_last_error` = ?, `updated_at` = ?
				WHERE `ext_order_id` = ? AND `granted_at` IS NULL");
			$stmt->execute([self::normaliseFulfillmentError($error), time(), $extOrderId]);
		} catch (PDOException $e) {
		}
	}

	/** A user's own payment history — newest first. */
	public static function forUser(int $userId, int $limit = 50): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return [];
		}
		$payments = Database::table('payments');
		$plans = Database::table('plans');
		try {
			// pt 2: the account's own history, purchases and admin actions alike, newest first.
			$users = Database::table('users');
			$stmt = $pdo->prepare("SELECT p.*, pl.`name` AS plan_name, a.`username` AS actor_name
				FROM `{$payments}` p
				LEFT JOIN `{$plans}` pl ON pl.`id` = p.`plan_id`
				LEFT JOIN `{$users}` a ON a.`id` = p.`actor_id`
				WHERE p.`user_id` = ?
				ORDER BY p.`created_at` DESC LIMIT " . max(1, min(200, $limit)));
			$stmt->execute([$userId]);
			$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
			foreach ($rows as &$row) {
				$snapshot = json_decode((string) ($row['product_snapshot'] ?? ''), true);
				$product = is_array($snapshot['product'] ?? null) ? $snapshot['product'] : [];
				if (($snapshot['type'] ?? '') === 'plan' && !empty($product['name'])) {
					$row['plan_name'] = (string) $product['name'];
				}
				$row['product_snapshot_data'] = $snapshot ?: null;
			}
			unset($row);
			return $rows;
		} catch (PDOException $e) {
			return [];
		}
	}

	/**
	 * Headline numbers for the Premium tab (pt 6).
	 *
	 * Revenue is grouped **by currency** and never summed across them: a shop selling in PLN
	 * and EUR has two revenues, and adding 1900 to 1900 because both are integers would be a
	 * number that means nothing. Only COMPLETED payments count — a started checkout is not a
	 * sale, and counting one would flatter every figure on the page.
	 *
	 * @return array{revenue: list<array{currency: string, minor: int, orders: int}>,
	 *               orders: int, pending: int, canceled: int, buyers: int, active: int}
	 */
	public static function stats(int $days = 30): array
	{
		$out = ['revenue' => [], 'orders' => 0, 'pending' => 0, 'canceled' => 0, 'buyers' => 0, 'active' => 0];
		$pdo = Database::getInstance();
		if (!$pdo) {
			return $out;
		}
		$payments = Database::table('payments');
		$users = Database::table('users');
		$plans = Database::table('plans');
		$since = $days > 0 ? time() - ($days * 86400) : 0;

		try {
			// Only real purchases are revenue: an admin grant is a settled row worth nothing,
			// and counting it would drag the average order down and inflate the conversion.
			$stmt = $pdo->prepare("SELECT `currency`, SUM(`amount_minor`) AS minor, COUNT(*) AS orders
				FROM `{$payments}` WHERE `status` = ? AND `kind` = ? AND `created_at` >= ?
				GROUP BY `currency` ORDER BY minor DESC");
			$stmt->execute([self::COMPLETED, self::KIND_PURCHASE, $since]);
			foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
				$out['revenue'][] = [
					'currency' => (string) $row['currency'],
					'minor' => (int) $row['minor'],
					'orders' => (int) $row['orders'],
				];
				$out['orders'] += (int) $row['orders'];
			}

			$stmt = $pdo->prepare("SELECT `status`, COUNT(*) AS n FROM `{$payments}`
				WHERE `kind` = ? AND `created_at` >= ? GROUP BY `status`");
			$stmt->execute([self::KIND_PURCHASE, $since]);
			foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
				if (in_array($row['status'], [self::NEW, self::PENDING, self::PROCESSING], true)) {
					$out['pending'] += (int) $row['n'];
				} elseif ($row['status'] === self::CANCELED) {
					$out['canceled'] = (int) $row['n'];
				}
			}

			$stmt = $pdo->prepare("SELECT COUNT(DISTINCT `user_id`) FROM `{$payments}`
				WHERE `status` = ? AND `kind` = ? AND `created_at` >= ?");
			$stmt->execute([self::COMPLETED, self::KIND_PURCHASE, $since]);
			$out['buyers'] = (int) $stmt->fetchColumn();

			// Live subscriptions, which is a different question from "who bought recently":
			// a yearly plan sold in January is still a subscriber in June.
			$stmt = $pdo->prepare(
				"SELECT COUNT(DISTINCT u.`id`) FROM `{$users}` u
				 JOIN `{$plans}` p ON p.`group_id` = u.`group_id`
				 WHERE u.`is_active` = 1
				  AND (u.`group_expires_at` IS NULL OR u.`group_expires_at` > ?)"
			);
			$stmt->execute([time()]);
			$out['active'] = (int) $stmt->fetchColumn();
		} catch (PDOException $e) {
			return $out;
		}
		return $out;
	}

	/**
	 * Daily completed revenue and order count, for the chart (pt 6).
	 *
	 * Every day in the range is present, including the empty ones — a chart that silently
	 * skips quiet days draws a busier shop than the one that exists.
	 *
	 * @return array{days: list<string>, revenue: list<float>, orders: list<int>, currency: string}
	 */
	public static function series(int $days = 30): array
	{
		$days = max(1, min(365, $days));
		$labels = [];
		$revenue = [];
		$orders = [];
		for ($i = $days - 1; $i >= 0; $i--) {
			$labels[] = date('Y-m-d', time() - ($i * 86400));
			$revenue[] = 0.0;
			$orders[] = 0;
		}
		$index = array_flip($labels);

		// One currency on the chart: the busiest one, named in the payload so the axis can say
		// which. Mixing them into one line would be the same category error as summing them.
		$stats = self::stats($days);
		$currency = $stats['revenue'][0]['currency'] ?? 'PLN';

		$pdo = Database::getInstance();
		if (!$pdo) {
			return ['days' => $labels, 'revenue' => $revenue, 'orders' => $orders, 'currency' => $currency];
		}

		try {
			// The shift makes MySQL group by the same day boundaries PHP used for the labels
			// above — see Database::tzShiftSeconds().
			$stmt = $pdo->prepare("SELECT DATE(FROM_UNIXTIME(`created_at` + ?)) AS d,
					SUM(`amount_minor`) AS minor, COUNT(*) AS n
				FROM `" . Database::table('payments') . "`
				WHERE `status` = ? AND `kind` = ? AND `currency` = ? AND `created_at` >= ?
				GROUP BY d ORDER BY d ASC");
			$stmt->execute([Database::tzShiftSeconds(), self::COMPLETED, self::KIND_PURCHASE, $currency, time() - ($days * 86400)]);
			foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
				$key = (string) $row['d'];
				if (isset($index[$key])) {
					$revenue[$index[$key]] = round(((int) $row['minor']) / 100, 2);
					$orders[$index[$key]] = (int) $row['n'];
				}
			}
		} catch (PDOException $e) {
		}

		return ['days' => $labels, 'revenue' => $revenue, 'orders' => $orders, 'currency' => $currency];
	}

	/** A page of payments with the buyer and plan resolved — the admin purchases list (pt 6). */
	public static function browse(array $opts = []): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return ['payments' => [], 'total' => 0];
		}
		$payments = Database::table('payments');
		$plans = Database::table('plans');
		$users = Database::table('users');

		$page = max(1, (int) ($opts['page'] ?? 1));
		$perPage = min(100, max(5, (int) ($opts['per_page'] ?? 20)));

		$where = [];
		$params = [];
		$status = (string) ($opts['status'] ?? '');
		if (in_array($status, [self::NEW, self::PENDING, self::PROCESSING, self::COMPLETED, self::CANCELED, self::REFUNDING, self::REFUNDED], true)) {
			$where[] = 'p.`status` = ?';
			$params[] = $status;
		}
		$search = trim((string) ($opts['search'] ?? ''));
		if ($search !== '') {
			$like = '%' . $search . '%';
			$where[] = '(p.`ext_order_id` LIKE ? OR p.`provider_order_id` LIKE ? OR u.`username` LIKE ? OR u.`email` LIKE ?)';
			array_push($params, $like, $like, $like, $like);
		}
		$kind = (string) ($opts['kind'] ?? '');
		if (in_array($kind, [self::KIND_PURCHASE, self::KIND_ADMIN_GRANT, self::KIND_ADMIN_REVOKE], true)) {
			$where[] = 'p.`kind` = ?';
			$params[] = $kind;
		}
		$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
		$join = "LEFT JOIN `{$users}` u ON u.`id` = p.`user_id`
			LEFT JOIN `{$plans}` pl ON pl.`id` = p.`plan_id`
			LEFT JOIN `{$users}` a ON a.`id` = p.`actor_id`";

		try {
			$countStmt = $pdo->prepare("SELECT COUNT(*) FROM `{$payments}` p {$join} {$whereSql}");
			$countStmt->execute($params);
			$total = (int) $countStmt->fetchColumn();

			$sql = "SELECT p.*, u.`username`, u.`email`, pl.`name` AS plan_name, a.`username` AS actor_name
				FROM `{$payments}` p {$join} {$whereSql}
				ORDER BY p.`created_at` DESC LIMIT ? OFFSET ?";
			$stmt = $pdo->prepare($sql);
			$bound = array_merge($params, [$perPage, ($page - 1) * $perPage]);
			foreach ($bound as $i => $v) {
				$stmt->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
			}
			$stmt->execute();
			return ['payments' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
		} catch (PDOException $e) {
			return ['payments' => [], 'total' => 0];
		}
	}

	/**
	 * Who currently holds a paid plan (pt 6).
	 *
	 * Driven by the users table rather than the payments table, because a plan granted by hand
	 * from the panel is just as real a subscription as one that was paid for — and the question
	 * "who has premium right now" must not answer "only the ones who used the checkout".
	 */
	public static function subscribers(int $limit = 200): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return [];
		}
		$users = Database::table('users');
		$groups = Database::table('groups');
		$payments = Database::table('payments');
		$plans = Database::table('plans');
		try {
			$stmt = $pdo->prepare("SELECT u.`id`, u.`username`, u.`email`, u.`group_id`, u.`group_expires_at`,
					g.`name` AS group_name,
					COALESCE(pay.`purchases`, 0) AS purchases, pay.`last_paid`
				FROM `{$users}` u
				JOIN `{$groups}` g ON g.`id` = u.`group_id`
				LEFT JOIN (
					SELECT `user_id`, COUNT(*) AS purchases, MAX(`created_at`) AS last_paid
					FROM `{$payments}` WHERE `status` = ? AND `kind` = 'purchase'
					GROUP BY `user_id`
				) pay ON pay.`user_id` = u.`id`
				WHERE u.`is_active` = 1
				  AND (u.`group_expires_at` IS NULL OR u.`group_expires_at` > ?)
				  AND EXISTS (
					SELECT 1 FROM `{$plans}` p WHERE p.`group_id` = u.`group_id`
				  )
				ORDER BY (u.`group_expires_at` IS NULL) ASC, u.`group_expires_at` ASC
				LIMIT " . max(1, min(500, $limit)));
			$stmt->execute([self::COMPLETED, time()]);
			return $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (PDOException $e) {
			return [];
		}
	}

	/** Resolve the exact account ids for a reviewed bulk entitlement operation. */
	public static function bulkGrantCandidates(array $criteria, int $limit = 2001): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return [];
		}
		$users = Database::table('users');
		$groups = Database::table('groups');
		$plans = Database::table('plans');
		$payments = Database::table('payments');
		$source = (string) ($criteria['source'] ?? 'active_subscribers');
		$limit = max(1, min(2001, $limit));
		$params = [];
		if ($source === 'group') {
			$sql = "SELECT DISTINCT u.`id` FROM `{$users}` u
				WHERE u.`is_active` = 1 AND (
					(u.`group_id` = ? AND (u.`group_expires_at` IS NULL OR u.`group_expires_at` > ?))
					OR u.`staff_group_id` = ?
				)
				ORDER BY u.`id` LIMIT {$limit}";
			$params = [(int) ($criteria['group_id'] ?? 0), time(), (int) ($criteria['group_id'] ?? 0)];
		} elseif ($source === 'buyers') {
			$where = ["u.`is_active` = 1", "p.`status` = ?", "p.`kind` = 'purchase'", "p.`created_at` >= ?", "p.`created_at` < ?"];
			$params = [self::COMPLETED, (int) ($criteria['from'] ?? 0), (int) ($criteria['to'] ?? PHP_INT_MAX)];
			if (!empty($criteria['purchased_plan_id'])) {
				$where[] = 'p.`plan_id` = ?';
				$params[] = (int) $criteria['purchased_plan_id'];
			}
			$sql = "SELECT DISTINCT u.`id` FROM `{$payments}` p
				JOIN `{$users}` u ON u.`id` = p.`user_id`
				WHERE " . implode(' AND ', $where) . " ORDER BY u.`id` LIMIT {$limit}";
		} else {
			$sql = "SELECT DISTINCT u.`id` FROM `{$users}` u
				WHERE u.`is_active` = 1
				  AND (u.`group_expires_at` IS NULL OR u.`group_expires_at` > ?)
				  AND EXISTS (SELECT 1 FROM `{$plans}` p WHERE p.`group_id` = u.`group_id` AND p.`kind` = 'paid')
				ORDER BY u.`id` LIMIT {$limit}";
			$params = [time()];
		}
		try {
			$stmt = $pdo->prepare($sql);
			$stmt->execute($params);
			return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
		} catch (PDOException $e) {
			return [];
		}
	}

	private static function normaliseFulfillmentError(string $error): string
	{
		$error = preg_replace('/\s+/u', ' ', trim($error)) ?? '';
		if ($error === '') {
			$error = 'fulfillment_failed';
		}
		return mb_substr($error, 0, 1000);
	}

	private static function update(string $extOrderId, array $fields): void
	{
		$pdo = Database::getInstance();
		if (!$pdo || !$fields) {
			return;
		}
		$fields['updated_at'] = time();
		$sets = implode(', ', array_map(fn($c) => "`{$c}` = ?", array_keys($fields)));
		try {
			$stmt = $pdo->prepare("UPDATE `" . Database::table('payments') . "` SET {$sets} WHERE `ext_order_id` = ?");
			$stmt->execute(array_merge(array_values($fields), [$extOrderId]));
		} catch (PDOException $e) {
		}
	}
}
