<?php
/**
 * Turning a paid order into a granted plan — the one place that decides it.
 *
 * There are two ways to learn that an order was paid, and they must not disagree:
 *
 *   - the provider **tells** us (PayU's notification — fast, but an inbound call that a
 *     firewall, a private address or an hour of downtime can swallow);
 *   - we **ask** (polling the order — slower, but it works from behind anything, which is
 *     also the only way this flow can be exercised on a machine PayU cannot reach at all).
 *
 * Both end here. `fulfil()` re-checks the amount and the currency, claims the payment
 * atomically and grants the plan, so it does not matter which route arrives first, whether
 * both do, or how many times either repeats.
 *
 * Deliberately a repository rather than part of `PaymentController`: `scripts/cleanup.php`
 * runs the same reconciliation from cron and does not load the web controllers.
 */
final class PaymentReconciler
{
	/** Provider statuses that mean "the buyer paid and we may keep it". */
	private const PAID = ['COMPLETED'];

	/** Paid, but the shop still has to accept the order before the funds move. */
	private const HELD = ['WAITING_FOR_CONFIRMATION'];

	/** Nothing more will happen to these. */
	private const DEAD = ['CANCELED', 'REJECTED'];

	/** How long to keep asking about an order nobody ever paid for. */
	private const GIVE_UP_AFTER = 72 * 3600;

	/** The immutable catalog row captured before the buyer was sent to the provider. */
	private static function snapshottedProduct(array $payment, string $expectedType): ?array
	{
		$snapshot = json_decode((string) ($payment['product_snapshot'] ?? ''), true);
		if (!is_array($snapshot)
			|| (string) ($snapshot['type'] ?? '') !== $expectedType
			|| !is_array($snapshot['product'] ?? null)) {
			return null;
		}
		return $snapshot['product'];
	}

	/**
	 * Bring one payment up to date with the provider.
	 *
	 * @return string the payment's status afterwards (PaymentRepository::* constants)
	 */
	public static function reconcile(array $payment): string
	{
		$status = (string) ($payment['status'] ?? PaymentRepository::NEW);
		if (in_array($status, [
			PaymentRepository::COMPLETED,
			PaymentRepository::CANCELED,
			PaymentRepository::REFUNDING,
			PaymentRepository::REFUNDED,
		], true)) {
			return $status; // terminal — nothing left to ask
		}
		if ($status === PaymentRepository::PROCESSING
			&& (int) ($payment['processing_expires_at'] ?? 0) > time()) {
			return $status; // another worker still owns a live fulfilment lease
		}

		$extOrderId = (string) $payment['ext_order_id'];
		if ((string) ($payment['provider'] ?? '') === 'przelewy24') {
			return self::reconcileP24($payment, $status);
		}
		$orderId = (string) ($payment['provider_order_id'] ?? '');
		if ($orderId === '') {
			// The order never reached the provider, so there is nothing to poll. Let it lapse
			// rather than asking about it forever.
			if (time() - (int) $payment['created_at'] > self::GIVE_UP_AFTER) {
				PaymentRepository::setStatus($extOrderId, PaymentRepository::CANCELED);
				return PaymentRepository::CANCELED;
			}
			return $status;
		}

		$order = PayU::orderStatus($orderId);
		if ($order === null) {
			return $status; // provider unreachable — try again next time, change nothing
		}

		$providerStatus = strtoupper((string) ($order['status'] ?? ''));

		if (in_array($providerStatus, self::DEAD, true)) {
			PaymentRepository::setStatus($extOrderId, PaymentRepository::CANCELED);
			return PaymentRepository::CANCELED;
		}

		if (in_array($providerStatus, self::HELD, true)) {
			// Take the money before handing over the goods; if the capture fails, leave the
			// payment pending and try again rather than granting on a maybe.
			if (!PayU::captureOrder($orderId)) {
				return $status;
			}
			$providerStatus = 'COMPLETED';
		}

		if (!in_array($providerStatus, self::PAID, true)) {
			if (time() - (int) $payment['created_at'] > self::GIVE_UP_AFTER) {
				PaymentRepository::setStatus($extOrderId, PaymentRepository::CANCELED);
				return PaymentRepository::CANCELED;
			}
			PaymentRepository::setStatus($extOrderId, PaymentRepository::PENDING);
			return PaymentRepository::PENDING;
		}

		if (self::fulfil($payment, (int) ($order['totalAmount'] ?? 0), (string) ($order['currencyCode'] ?? ''), 'poll')) {
			return PaymentRepository::COMPLETED;
		}

		// `fulfil()` may have released a failed lease back to PENDING or found another
		// worker already processing it. Return the persisted state, not the stale input row.
		$fresh = PaymentRepository::byExtOrderId($extOrderId);
		return (string) ($fresh['status'] ?? $status);
	}

	private static function reconcileP24(array $payment, string $status): string
	{
		$extOrderId = (string) $payment['ext_order_id'];
		$order = P24::orderStatus($extOrderId);
		if ($order === null) {
			if (time() - (int) $payment['created_at'] > self::GIVE_UP_AFTER) {
				PaymentRepository::setStatus($extOrderId, PaymentRepository::CANCELED);
				return PaymentRepository::CANCELED;
			}
			return $status;
		}
		$providerStatus = (int) ($order['status'] ?? 0);
		if ($providerStatus === 3) {
			PaymentRepository::setStatus($extOrderId, PaymentRepository::CANCELED);
			return PaymentRepository::CANCELED;
		}
		if ($providerStatus !== 2) {
			if (time() - (int) $payment['created_at'] > self::GIVE_UP_AFTER) {
				PaymentRepository::setStatus($extOrderId, PaymentRepository::CANCELED);
				return PaymentRepository::CANCELED;
			}
			PaymentRepository::setStatus($extOrderId, PaymentRepository::PENDING);
			return PaymentRepository::PENDING;
		}

		$orderId = (int) ($order['orderId'] ?? 0);
		$amount = (int) ($order['amount'] ?? 0);
		$currency = strtoupper((string) ($order['currency'] ?? ''));
		if ($orderId < 1 || $amount !== (int) $payment['amount_minor']
			|| $currency !== strtoupper((string) $payment['currency'])) {
			PaymentRepository::noteGrantError($extOrderId, 'p24_poll_mismatch');
			return $status;
		}
		$knownOrderId = (int) ($payment['provider_order_id'] ?? 0);
		if ($knownOrderId > 0 && $knownOrderId !== $orderId) {
			PaymentRepository::noteGrantError($extOrderId, 'p24_order_id_mismatch');
			return $status;
		}
		if ($knownOrderId < 1) {
			PaymentRepository::setProviderOrderId($extOrderId, (string) $orderId);
			$payment['provider_order_id'] = (string) $orderId;
		}
		if (!P24::verifyTransaction($extOrderId, $orderId, $amount, $currency)) {
			return $status;
		}
		if (self::fulfil($payment, $amount, $currency, 'p24-poll')) {
			return PaymentRepository::COMPLETED;
		}
		$fresh = PaymentRepository::byExtOrderId($extOrderId);
		return (string) ($fresh['status'] ?? $status);
	}

	/**
	 * Grant the plan behind a paid order — the single fulfilment path.
	 *
	 * @param string $via how we found out ('notify' or 'poll'), for the audit trail
	 */
	public static function fulfil(array $payment, int $paidMinor, string $currency, string $via): bool
	{
		$extOrderId = (string) $payment['ext_order_id'];

		// Paid for one thing, charged for another: refuse rather than grant on a mismatch.
		if ($paidMinor < (int) $payment['amount_minor'] || strtoupper($currency) !== strtoupper((string) $payment['currency'])) {
			PaymentRepository::noteGrantError($extOrderId, 'amount_or_currency_mismatch');
			Database::logAudit(
				'payment_amount_mismatch',
				$extOrderId . ': got ' . $paidMinor . ' ' . $currency
					. ', expected ' . $payment['amount_minor'] . ' ' . $payment['currency'],
				(int) $payment['user_id']
			);
			// The buyer is owed an explanation here more than anywhere else in this flow: money
			// moved and nothing was granted, and the audit log is not somewhere they can look.
			$tab = ($payment['kind'] ?? '') === PaymentRepository::KIND_AD ? 'myads' : 'premium';
			Notifications::send((int) $payment['user_id'], 'payment.failed', [
				'subject' => $extOrderId,
				'link' => (defined('APP_URL') ? APP_URL : '') . '/panel.php?tab=' . $tab,
			]);
			return false;
		}

		// The claim is what makes this safe to call from both routes at once: the UPDATE only
		// matches while the payment is unfulfilled, so exactly one caller proceeds.
		$pdo = Database::getInstance();
		if (!$pdo || $pdo->inTransaction()) {
			PaymentRepository::noteGrantError($extOrderId, 'transaction_unavailable');
			return false;
		}

		// Claim only elects a worker. The payment remains ungranted until the product mutation
		// and `completeGrant()` commit together below.
		$claim = PaymentRepository::claimForGrant($extOrderId);
		if (($claim['state'] ?? '') === PaymentRepository::CLAIM_COMPLETED) {
			return true;
		}
		if (($claim['state'] ?? '') !== PaymentRepository::CLAIM_ACQUIRED) {
			if (($claim['state'] ?? '') === PaymentRepository::CLAIM_ERROR) {
				PaymentRepository::noteGrantError($extOrderId, 'claim_failed');
			}
			return false;
		}
		$token = (string) ($claim['token'] ?? '');

		try {
			if (!$pdo->beginTransaction() || !PaymentRepository::lockGrant($extOrderId, $token)) {
				throw new RuntimeException('Unable to lock the payment fulfilment lease.');
			}
		} catch (Throwable $e) {
			error_log('Payment fulfilment transaction failed for ' . $extOrderId . ': ' . $e->getMessage());
			return self::finishGrant($pdo, $payment, $via, $token, false, 'transaction_start_failed');
		}

		try {
		self::lockProductTarget($pdo, $payment);
		// An ad purchase (Faza 8): fulfilment is "into the review queue", not a group grant.
		// The buyer paid for a placement the admin has not seen yet, so the goods here are
		// pending status + a heads-up to the staff. A boost order is the exception: it
		// strengthens an already-approved creative, nothing changes content-wise, so it
		// applies immediately with no review round.
		// Surcharge top-up for extra placements on an existing ad (runda 5): the new draft
		// children go to the review queue; the live parent is untouched.
		if (($payment['kind'] ?? '') === PaymentRepository::KIND_AD_ADDON) {
			$adId = (int) ($payment['ad_id'] ?? 0);
			$ok = $adId > 0 && AdRepository::markAddonsPaid($adId);
			Database::logAudit(
				$ok ? 'ad_addons_paid' : 'ad_payment_orphan',
				$payment['provider'] . ' (' . $via . '), ad #' . $adId . ', order ' . $extOrderId,
				(int) $payment['user_id']
			);
			$ad = $ok ? AdRepository::get($adId) : null;
			$afterCommit = null;
			if ($ok) {
				$afterCommit = static function () use ($payment, $ad, $extOrderId, $adId): void {
					Notifications::send((int) $payment['user_id'], 'ad.paid', [
						'subject' => (string) ($ad['name'] ?? $extOrderId),
						'link' => (defined('APP_URL') ? APP_URL : '') . '/panel.php?tab=myads',
					]);
					Notifications::sendMany(Notifications::staffIds(), 'ad.submitted', [
						'subject' => (string) ($ad['name'] ?? ('#' . $adId)),
						'link' => (defined('APP_URL') ? APP_URL : '') . '/panel.php?tab=moderate&mstab=ads&astab=queue',
					]);
				};
			}
			return self::finishGrant($pdo, $payment, $via, $token, $ok, 'ad_addons_failed', $afterCommit);
		}

		if (($payment['kind'] ?? '') === PaymentRepository::KIND_AD) {
			$adId = (int) ($payment['ad_id'] ?? 0);
			$package = self::snapshottedProduct($payment, 'ad_package');
			if (!$package && !empty($payment['package_id'])) {
				// Compatibility only for orders created before schema v43.
				$package = AdRepository::packageGet((int) $payment['package_id']);
			}

			if ($package && ($package['kind'] ?? 'placement') === 'boost') {
				$ok = $adId > 0 && AdRepository::applyBoost($adId, $package);
				Database::logAudit(
					$ok ? 'ad_boost_applied' : 'ad_payment_orphan',
					$payment['provider'] . ' (' . $via . '), ad #' . $adId . ', order ' . $extOrderId,
					(int) $payment['user_id']
				);
				$ad = $ok ? AdRepository::get($adId) : null;
				$afterCommit = null;
				if ($ok) {
					$afterCommit = static function () use ($payment, $ad, $extOrderId): void {
						Notifications::send((int) $payment['user_id'], 'ad.boosted', [
							'subject' => (string) ($ad['name'] ?? $extOrderId),
							'data' => ['until' => date('d.m.Y', (int) ($ad['boost_until'] ?? time()))],
							'link' => (defined('APP_URL') ? APP_URL : '') . '/panel.php?tab=myads',
						]);
					};
				}
				return self::finishGrant($pdo, $payment, $via, $token, $ok, 'ad_boost_failed', $afterCommit);
			}

			// Renewal (runda 4): the ad already ran once — the same creative needs no second
			// review, so the paid days apply immediately (extend a live ad, revive one that
			// expired within the grace window).
			$adNow = AdRepository::get($adId);
			if ($adNow && in_array($adNow['status'], ['active', 'expired'], true)
				&& $package && ($package['kind'] ?? 'placement') === 'placement') {
				// Add-ons the buyer dropped in the renewal modal (runda 6): they were left
				// out of the price at checkout, so they leave the purchase now — before the
				// remaining group gets its new clock.
				$meta = json_decode((string) ($payment['meta'] ?? ''), true) ?: [];
				foreach ((array) ($meta['drop'] ?? []) as $dropId) {
					$child = AdRepository::get((int) $dropId);
					if ($child && (int) ($child['parent_ad_id'] ?? 0) === $adId) {
						AdRepository::delete((int) $dropId);
					}
				}
				$ok = AdRepository::renew($adId, $package);
				Database::logAudit(
					$ok ? 'ad_renewed' : 'ad_payment_orphan',
					$payment['provider'] . ' (' . $via . '), ad #' . $adId . ', order ' . $extOrderId,
					(int) $payment['user_id']
				);
				$fresh = $ok ? AdRepository::get($adId) : null;
				$afterCommit = null;
				if ($ok) {
					$afterCommit = static function () use ($payment, $adNow, $fresh, $extOrderId): void {
						Notifications::send((int) $payment['user_id'], 'ad.renewed', [
							'subject' => (string) ($adNow['name'] ?? $extOrderId),
							'data' => ['until' => date('d.m.Y', (int) ($fresh['ends_at'] ?? time()))],
							'link' => (defined('APP_URL') ? APP_URL : '') . '/panel.php?tab=myads',
						]);
					};
				}
				return self::finishGrant($pdo, $payment, $via, $token, $ok, 'ad_renew_failed', $afterCommit);
			}

			$ok = $adId > 0 && AdRepository::markPaid($adId, $package ?: []);
			Database::logAudit(
				$ok ? 'ad_payment_completed' : 'ad_payment_orphan',
				$payment['provider'] . ' (' . $via . '), ad #' . $adId . ', order ' . $extOrderId,
				(int) $payment['user_id']
			);
			$afterCommit = null;
			if ($ok) {
				// `ad.paid`, not the plan-flavoured `payment.completed`: the buyer's next step
				// is a review queue, not an active subscription, and the copy must say so.
				$ad = AdRepository::get($adId);
				$afterCommit = static function () use ($payment, $ad, $adId): void {
					Notifications::send((int) $payment['user_id'], 'ad.paid', [
						'subject' => (string) ($ad['name'] ?? ('#' . $adId)),
						'link' => (defined('APP_URL') ? APP_URL : '') . '/panel.php?tab=myads',
					]);
					Notifications::sendMany(Notifications::staffIds(), 'ad.submitted', [
						'subject' => (string) ($ad['name'] ?? ('#' . $adId)),
						'link' => (defined('APP_URL') ? APP_URL : '') . '/panel.php?tab=ads&astab=queue',
					]);
				};
			}
			return self::finishGrant($pdo, $payment, $via, $token, $ok, 'ad_mark_paid_failed', $afterCommit);
		}

		$plan = self::snapshottedProduct($payment, 'plan');
		if (!$plan) {
			// Compatibility only for orders created before schema v43.
			$plan = PlanRepository::get((int) $payment['plan_id']);
		}
		if (!$plan) {
			Database::logAudit('payment_plan_missing', $extOrderId, (int) $payment['user_id']);
			return self::finishGrant($pdo, $payment, $via, $token, false, 'plan_missing');
		}

			$result = PlanRepository::grant(
				(int) $payment['user_id'],
				$plan,
				null,
				$extOrderId
			);
		// The promo code the checkout priced in (runda 9) is spent HERE, with the goods —
		// counting it at checkout would let abandoned carts eat the cap.
		$meta = json_decode((string) ($payment['meta'] ?? ''), true) ?: [];
		if (!empty($result['success']) && !empty($meta['promo'])) {
			$promoCommitted = PromoCodeRepository::commitReservation($extOrderId);
			if (!$promoCommitted && empty($payment['product_snapshot'])) {
				// Orders predating v43 had no reservation row; retain forward compatibility
				// while making every new checkout use the atomic reservation protocol.
				$promoCommitted = PromoCodeRepository::redeem((string) $meta['promo']);
			}
			if (!$promoCommitted) {
				$result = ['success' => false, 'error' => 'promo_reservation_failed'];
			}
		}
		Database::logAudit(
			!empty($result['success']) ? 'payment_completed' : 'payment_grant_failed',
			$payment['provider'] . ' (' . $via . '), plan #' . $plan['id'] . ', order ' . $extOrderId
				. (!empty($meta['promo']) ? ', code ' . $meta['promo'] : ''),
			(int) $payment['user_id']
		);

		$afterCommit = !empty($result['success'])
			? static function () use ($payment, $plan, $result): void {
				Notifications::send(
					(int) $payment['user_id'],
					'payment.completed',
					[
						'subject' => (string) $plan['name'],
						'data' => [
							'until' => !empty($result['expires_at']) ? date('d.m.Y', (int) $result['expires_at']) : __('mail.plan_no_expiry'),
						],
						'link' => (defined('APP_URL') ? APP_URL : '') . '/panel.php?tab=premium',
					]
				);
			}
			: null;
		return self::finishGrant(
			$pdo,
			$payment,
			$via,
			$token,
			!empty($result['success']),
			'plan_grant_failed',
			$afterCommit
		);
		} catch (Throwable $e) {
			error_log('Payment fulfilment failed for ' . $extOrderId . ': ' . $e->getMessage());
			return self::finishGrant($pdo, $payment, $via, $token, false, 'internal_error');
		}
	}

	/**
	 * Serialize distinct paid orders that mutate the same account or ad. The payment-row lock
	 * handles webhook retries; this second lock prevents two legitimate purchases from losing
	 * an extension when both read the same product state at once.
	 */
	private static function lockProductTarget(PDO $pdo, array $payment): void
	{
		$kind = (string) ($payment['kind'] ?? PaymentRepository::KIND_PURCHASE);
		if (in_array($kind, [PaymentRepository::KIND_AD, PaymentRepository::KIND_AD_ADDON], true)) {
			$adId = (int) ($payment['ad_id'] ?? 0);
			if ($adId <= 0) {
				return;
			}
			$stmt = $pdo->prepare("SELECT `id` FROM `" . Database::table('ads') . "`
				WHERE `id` = ? OR `parent_ad_id` = ? ORDER BY `id` FOR UPDATE");
			$stmt->execute([$adId, $adId]);
			$stmt->fetchAll(PDO::FETCH_COLUMN);
			return;
		}

		$userId = (int) ($payment['user_id'] ?? 0);
		if ($userId <= 0) {
			return;
		}

		$planId = (int) ($payment['plan_id'] ?? 0);
		if ($planId > 0) {
			$snapshotPlan = self::snapshottedProduct($payment, 'plan');
			$groupId = (int) ($snapshotPlan['group_id'] ?? 0);
			if ($groupId <= 0) {
				$planStmt = $pdo->prepare(
					"SELECT `group_id` FROM `" . Database::table('plans') . "` WHERE `id` = ?"
				);
				$planStmt->execute([$planId]);
				$groupId = (int) ($planStmt->fetchColumn() ?: 0);
			}
			if ($groupId > 0) {
				$groupStmt = $pdo->prepare(
					"SELECT `id` FROM `" . Database::table('groups') . "` WHERE `id` = ? FOR UPDATE"
				);
				$groupStmt->execute([$groupId]);
				$groupStmt->fetchColumn();
			}
		}
		$stmt = $pdo->prepare("SELECT `id` FROM `" . Database::table('users') . "`
			WHERE `id` = ? FOR UPDATE");
		$stmt->execute([$userId]);
		$stmt->fetchColumn();
	}

	/**
	 * Commit a successful product mutation together with the terminal payment state, or roll
	 * everything back and release this worker's lease for a retry.
	 */
	private static function finishGrant(
		PDO $pdo,
		array $payment,
		string $via,
		string $token,
		bool $success,
		string $error,
		?callable $afterCommit = null
	): bool {
		$extOrderId = (string) $payment['ext_order_id'];

		if ($success) {
			try {
				if (!PaymentRepository::completeGrant($extOrderId, $token)) {
					$error = 'finalize_failed';
				} elseif ($pdo->commit()) {
					if ($afterCommit !== null) {
						try {
							$afterCommit();
						} catch (Throwable $e) {
							// Fulfilment is already durable; notification failure must not
							// turn a successful payment into a retry and grant twice.
							error_log('Payment success notification failed for ' . $extOrderId . ': ' . $e->getMessage());
						}
					}
					return true;
				} else {
					$error = 'commit_failed';
				}
			} catch (Throwable $e) {
				$error = 'commit_failed';
				error_log('Payment fulfilment commit failed for ' . $extOrderId . ': ' . $e->getMessage());
			}
		}

		if ($pdo->inTransaction()) {
			try {
				$pdo->rollBack();
			} catch (Throwable $e) {
				error_log('Payment fulfilment rollback failed for ' . $extOrderId . ': ' . $e->getMessage());
			}
		}

		// A commit can fail ambiguously after reaching the server. Never turn a payment back
		// into PENDING if the durable row says the transaction did in fact complete.
		$fresh = PaymentRepository::byExtOrderId($extOrderId);
		if ($fresh && (string) ($fresh['status'] ?? '') === PaymentRepository::COMPLETED
			&& $fresh['granted_at'] !== null) {
			return true;
		}

		// If rollback itself failed the connection may still own the row lock. Leave the
		// recoverable lease alone; its TTL is safer than mutating it in an uncertain tx.
		if ($pdo->inTransaction()) {
			return false;
		}

		if (!PaymentRepository::failGrant($extOrderId, $token, $error)) {
			return false; // stale owner: another worker now controls the retry
		}

		Database::logAudit(
			'payment_fulfillment_failed',
			$payment['provider'] . ' (' . $via . '), order ' . $extOrderId . ', reason ' . $error,
			(int) $payment['user_id']
		);
		$tab = ($payment['kind'] ?? '') === PaymentRepository::KIND_AD
			|| ($payment['kind'] ?? '') === PaymentRepository::KIND_AD_ADDON
			? 'myads'
			: 'premium';
		try {
			Notifications::send((int) $payment['user_id'], 'payment.failed', [
				'subject' => $extOrderId,
				'link' => (defined('APP_URL') ? APP_URL : '') . '/panel.php?tab=' . $tab,
			]);
		} catch (Throwable $e) {
			error_log('Payment failure notification failed for ' . $extOrderId . ': ' . $e->getMessage());
		}
		return false;
	}

	/**
	 * Reconcile every payment still in flight — the cron half.
	 *
	 * This is the safety net that makes a lost notification a delay rather than a lost sale.
	 *
	 * @return array{checked: int, completed: int, canceled: int}
	 */
	public static function sweepPending(): array
	{
		$out = ['checked' => 0, 'completed' => 0, 'canceled' => 0];
		$pdo = Database::getInstance();
		if (!$pdo) {
			return $out;
		}

		try {
			$stmt = $pdo->prepare("SELECT * FROM `" . Database::table('payments') . "`
				WHERE `granted_at` IS NULL AND (
					`status` IN (?, ?)
					OR (`status` = ? AND (`processing_expires_at` IS NULL OR `processing_expires_at` <= ?))
				)
				ORDER BY `created_at` ASC LIMIT 200");
			$stmt->execute([
				PaymentRepository::NEW,
				PaymentRepository::PENDING,
				PaymentRepository::PROCESSING,
				time(),
			]);
			$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (PDOException $e) {
			return $out;
		}

		foreach ($rows as $row) {
			$out['checked']++;
			$status = self::reconcile($row);
			if ($status === PaymentRepository::COMPLETED) {
				$out['completed']++;
			} elseif ($status === PaymentRepository::CANCELED) {
				$out['canceled']++;
			}
		}
		return $out;
	}
}
