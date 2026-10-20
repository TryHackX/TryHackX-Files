<?php

require_once dirname(__DIR__, 2) . '/src/includes/AdRenderer.php';

/**
 * What an ad order buys is fixed when the buyer is sent to pay: placements added afterwards
 * need their own top-up, and a refund on rejection gives back the order that bought the
 * rejected thing — not whatever the ad paid for last.
 */
final class AdPurchaseIntegrityTest extends RepoTestCase
{
	private int $userId = 0;
	private array $package = [];

	protected function setUp(): void
	{
		$this->truncate('notifications', 'notification_prefs', 'ad_file_deletion_queue', 'ad_stats_daily', 'payments', 'ads', 'ad_packages', 'users');
		applyTestNotificationDefaults();
		$name = 'adbuyer' . bin2hex(random_bytes(3));
		$registered = Database::registerUser($name, $name . '@example.com', 'Str0ng!pass');
		$this->assertTrue($registered['success'], $registered['error'] ?? '');
		$this->userId = (int) Database::loginUser($name, 'Str0ng!pass')['user']['id'];
		$saved = AdRepository::packageSave(null, [
			'name' => 'Home banner',
			'zone' => 'home_top',
			'amount_minor' => 1000,
			'currency' => 'PLN',
			'duration_days' => 30,
			'enabled' => 1,
			'addon_zones' => ['download_top' => 5000, 'premium_top' => 5000],
		]);
		$this->assertTrue($saved['success']);
		$this->package = AdRepository::packageGet((int) $saved['id']) ?? [];
	}

	protected function tearDown(): void
	{
		$this->truncate('notifications', 'ad_file_deletion_queue', 'ad_stats_daily', 'payments', 'ads', 'ad_packages');
	}

	private function draft(array $addons = []): int
	{
		$created = AdRepository::createDraft($this->userId, $this->package, [
			'name' => 'Campaign',
			'target_url' => 'https://example.com/',
			'alt_text' => 'Campaign',
			'addons' => $addons,
		]);
		$this->assertTrue($created['success']);
		return (int) $created['id'];
	}

	private function statusByZone(int $adId): array
	{
		$out = [];
		foreach (AdRepository::childrenOf($adId) as $child) {
			$out[(string) $child['zone']] = (string) $child['status'];
		}
		ksort($out);
		return $out;
	}

	private function checkout(int $adId, bool $addonTopUp, string $kind): string
	{
		$priced = AdRepository::priceCheckout($adId, $this->package, $addonTopUp);
		$ext = PaymentRepository::startAd(
			'payu',
			$adId,
			(int) $this->package['id'],
			$this->userId,
			$priced['amount'],
			'PLN',
			$kind,
			['children' => $priced['children']]
		);
		$this->assertNotNull($ext);
		return (string) $ext;
	}

	/** A draft bought, paid and approved: a live purchase with its order on record. */
	private function liveAd(array $addons = []): int
	{
		$adId = $this->draft($addons);
		$priced = AdRepository::priceCheckout($adId, $this->package, false);
		$ext = $this->checkout($adId, false, PaymentRepository::KIND_AD);
		$this->assertTrue(PaymentReconciler::fulfil(PaymentRepository::byExtOrderId($ext), $priced['amount'], 'PLN', 'notify'));
		$this->assertNotNull(AdRepository::approve($adId, $this->userId));
		return $adId;
	}

	private function edit(int $adId): array
	{
		$res = AdRepository::updateOwn($adId, $this->userId, [
			'name' => 'Campaign v2',
			'image_url' => '',
			'target_url' => 'https://example.com/v2',
			'alt_text' => 'Campaign v2',
		]);
		$this->assertTrue($res['success']);
		return $res;
	}

	/**
	 * C-F1: an edit of a live purchase sends what was bought back to review — and only that.
	 * Placements whose top-up was never paid used to go live with the re-review's approval.
	 */
	public function testUnpaidPlacementsStayDraftsAcrossAnEditAndItsReReview(): void
	{
		$adId = $this->liveAd();
		AdRepository::addPlacements($adId, $this->package, ['download_top', 'premium_top']);
		$this->assertTrue($this->edit($adId)['pending']);
		$this->assertSame('pending', AdRepository::get($adId)['status']);
		$this->assertSame(['download_top' => 'draft', 'premium_top' => 'draft'], $this->statusByZone($adId));

		$this->assertNotNull(AdRepository::approve($adId, $this->userId));
		$this->assertSame('active', AdRepository::get($adId)['status']);
		$this->assertSame(['download_top' => 'draft', 'premium_top' => 'draft'], $this->statusByZone($adId));
	}

	/** C-F1: a purchase rejected with a refund is over; an edit cannot put it back in the queue. */
	public function testARefundedRejectionCannotReturnToTheQueue(): void
	{
		$adId = $this->draft();
		$ext = $this->checkout($adId, false, PaymentRepository::KIND_AD);
		$this->assertTrue(PaymentReconciler::fulfil(PaymentRepository::byExtOrderId($ext), 1000, 'PLN', 'notify'));
		$this->assertTrue(AdRepository::reject($adId, 'not acceptable'));
		$token = PaymentRepository::claimRefund($ext);
		$this->assertNotNull($token);
		$this->assertTrue(PaymentRepository::finalizeRefund($ext, (string) $token, 0)['success']);

		$this->assertFalse($this->edit($adId)['pending']);
		$this->assertSame('rejected', AdRepository::get($adId)['status']);
		// Nothing can approve it either, whatever state a row was forced into.
		Database::getInstance()->prepare("UPDATE `" . Database::table('ads') . "` SET `status` = 'pending' WHERE `id` = ?")
			->execute([$adId]);
		$this->assertNull(AdRepository::approve($adId, $this->userId));
		$this->assertSame('pending', AdRepository::get($adId)['status']);
	}

	/** C-F1: a rejection without a refund may still be fixed and resubmitted. */
	public function testARejectionWithoutRefundCanBeResubmitted(): void
	{
		$adId = $this->draft();
		$ext = $this->checkout($adId, false, PaymentRepository::KIND_AD);
		$this->assertTrue(PaymentReconciler::fulfil(PaymentRepository::byExtOrderId($ext), 1000, 'PLN', 'notify'));
		$this->assertTrue(AdRepository::reject($adId, 'fix the text'));
		$this->assertTrue($this->edit($adId)['pending']);
		$this->assertNotNull(AdRepository::approve($adId, $this->userId));
		$this->assertSame('active', AdRepository::get($adId)['status']);
	}

	/** C-F1: a top-up paid while its buyer edited the ad still promotes the placements it paid. */
	public function testATopUpPaidAfterAnEditStillPromotesItsPlacements(): void
	{
		$adId = $this->liveAd();
		AdRepository::addPlacements($adId, $this->package, ['download_top']);
		$ext = $this->checkout($adId, true, PaymentRepository::KIND_AD_ADDON);
		$this->edit($adId); // while the buyer is at the provider
		$this->assertTrue(PaymentReconciler::fulfil(PaymentRepository::byExtOrderId($ext), 5000, 'PLN', 'notify'));
		$this->assertSame(['download_top' => 'pending'], $this->statusByZone($adId));

		$this->assertNotNull(AdRepository::approve($adId, $this->userId));
		$this->assertSame(['download_top' => 'active'], $this->statusByZone($adId));
	}

	/**
	 * C-F2: two orders paid for one draft — the second can never be delivered. It is parked
	 * after one attempt and the administrators are told, instead of being retried every
	 * quarter hour; its failure audit line survives the rolled-back attempt (C-F21).
	 */
	public function testASecondPaidOrderForOneDraftIsParkedAndAnnounced(): void
	{
		$adminName = 'adadmin' . bin2hex(random_bytes(3));
		Database::createAdmin($adminName, 'Str0ng!pass', $adminName . '@example.com');
		$this->truncate('audit_log');
		$adId = $this->draft();
		$first = $this->checkout($adId, false, PaymentRepository::KIND_AD);
		$second = $this->checkout($adId, false, PaymentRepository::KIND_AD);
		$this->assertTrue(PaymentReconciler::fulfil(PaymentRepository::byExtOrderId($first), 1000, 'PLN', 'notify'));
		$this->assertFalse(PaymentReconciler::fulfil(PaymentRepository::byExtOrderId($second), 1000, 'PLN', 'notify'));

		$row = PaymentRepository::byExtOrderId($second);
		$this->assertTrue(PaymentReconciler::isPermanentError($row['fulfillment_last_error']), (string) $row['fulfillment_last_error']);
		$this->assertSame(1, (int) $row['fulfillment_attempts']);
		$this->assertSame(0, PaymentReconciler::sweepPending()['checked'], 'a parked order leaves the sweep');
		$count = static fn(string $sql): int => (int) Database::getInstance()->query($sql)->fetchColumn();
		$this->assertSame(1, $count(
			"SELECT COUNT(*) FROM `" . Database::table('notifications') . "` WHERE `type` = 'payment.stuck'"
		));
		$this->assertSame(1, $count(
			"SELECT COUNT(*) FROM `" . Database::table('audit_log') . "` WHERE `action` = 'ad_payment_orphan'"
		), 'the failure is on record, not rolled back with the attempt');
	}

	/** C-F2: one order at a time per ad — a fresh open order is found for the checkout to refuse. */
	public function testAnOpenOrderOfTheSameAdIsFound(): void
	{
		$adId = $this->draft();
		$this->assertNull(PaymentRepository::openOrderForAd($adId, 900));
		$ext = $this->checkout($adId, false, PaymentRepository::KIND_AD);
		$this->assertSame($ext, PaymentRepository::openOrderForAd($adId, 900)['ext_order_id'] ?? null);
		PaymentRepository::setStatus($ext, PaymentRepository::CANCELED);
		$this->assertNull(PaymentRepository::openOrderForAd($adId, 900));
	}

	/**
	 * C-F3: a renewal is priced with the placements it renews. A draft placement (its top-up
	 * never paid) used to be charged for and then left a draft.
	 */
	public function testARenewalChargesAndRenewsOnlyTheLivePlacements(): void
	{
		$adId = $this->liveAd(['premium_top']);
		AdRepository::addPlacements($adId, $this->package, ['download_top']); // never paid
		$priced = AdRepository::priceCheckout($adId, $this->package, false);
		$this->assertSame(6000, $priced['amount'], 'base + the live placement, not the draft');
		$liveChild = null;
		foreach (AdRepository::childrenOf($adId) as $child) {
			if ($child['zone'] === 'premium_top') {
				$liveChild = (int) $child['id'];
			}
		}
		$this->assertSame([$liveChild], $priced['children']);

		$before = (int) AdRepository::get($adId)['ends_at'];
		$ext = PaymentRepository::startAd('payu', $adId, (int) $this->package['id'], $this->userId, $priced['amount'], 'PLN',
			PaymentRepository::KIND_AD, ['children' => $priced['children']]);
		$this->assertTrue(PaymentReconciler::fulfil(PaymentRepository::byExtOrderId((string) $ext), 6000, 'PLN', 'notify'));
		$this->assertSame($before + 30 * 86400, (int) AdRepository::get($adId)['ends_at']);
		$this->assertSame(['download_top' => 'draft', 'premium_top' => 'active'], $this->statusByZone($adId));
		foreach (AdRepository::childrenOf($adId) as $child) {
			if ($child['zone'] === 'premium_top') {
				$this->assertSame($before + 30 * 86400, (int) $child['ends_at']);
			}
		}
	}

	public function testPlacementsAddedAfterCheckoutDoNotRideTheBaseOrder(): void
	{
		$adId = $this->draft();
		$this->assertSame(['amount' => 1000, 'children' => []], AdRepository::priceCheckout($adId, $this->package, false));
		$ext = $this->checkout($adId, false, PaymentRepository::KIND_AD);

		// The buyer adds two add-on zones while the 10 PLN order is still open.
		$this->assertCount(2, AdRepository::addPlacements($adId, $this->package, ['download_top', 'premium_top']));
		$this->assertTrue(PaymentReconciler::fulfil(PaymentRepository::byExtOrderId($ext), 1000, 'PLN', 'notify'));

		$this->assertSame('pending', AdRepository::get($adId)['status']);
		$this->assertSame(['download_top' => 'draft', 'premium_top' => 'draft'], $this->statusByZone($adId));
		$this->assertSame(10000, AdRepository::priceCheckout($adId, $this->package, true)['amount']);
	}

	public function testPricedAddonsFollowTheirOrder(): void
	{
		$adId = $this->draft(['download_top']);
		$priced = AdRepository::priceCheckout($adId, $this->package, false);
		$this->assertSame(6000, $priced['amount']);
		$ext = $this->checkout($adId, false, PaymentRepository::KIND_AD);
		$this->assertTrue(PaymentReconciler::fulfil(PaymentRepository::byExtOrderId($ext), 6000, 'PLN', 'notify'));
		$this->assertSame(['download_top' => 'pending'], $this->statusByZone($adId));
	}

	public function testTopUpActivatesOnlyThePlacementsItPaidFor(): void
	{
		$adId = $this->liveAd();

		AdRepository::addPlacements($adId, $this->package, ['download_top']);
		$ext = $this->checkout($adId, true, PaymentRepository::KIND_AD_ADDON);
		AdRepository::addPlacements($adId, $this->package, ['premium_top']);

		$this->assertTrue(PaymentReconciler::fulfil(PaymentRepository::byExtOrderId($ext), 5000, 'PLN', 'notify'));
		$this->assertSame(['download_top' => 'pending', 'premium_top' => 'draft'], $this->statusByZone($adId));
	}

	public function testRefundLookupIsScopedToTheKindOfPurchase(): void
	{
		$adId = $this->draft();
		$base = $this->checkout($adId, false, PaymentRepository::KIND_AD);
		$this->assertTrue(PaymentReconciler::fulfil(PaymentRepository::byExtOrderId($base), 1000, 'PLN', 'notify'));
		$this->assertNotNull(AdRepository::approve($adId, $this->userId));
		AdRepository::addPlacements($adId, $this->package, ['download_top']);
		$topUp = $this->checkout($adId, true, PaymentRepository::KIND_AD_ADDON);
		$this->assertTrue(PaymentReconciler::fulfil(PaymentRepository::byExtOrderId($topUp), 5000, 'PLN', 'notify'));
		// A renewal paid after the top-up is the newest payment of the ad.
		$renewal = PaymentRepository::startAd('payu', $adId, (int) $this->package['id'], $this->userId, 1000, 'PLN');
		$this->assertTrue(PaymentReconciler::fulfil(PaymentRepository::byExtOrderId((string) $renewal), 1000, 'PLN', 'notify'));

		$this->assertSame($topUp, PaymentRepository::latestCompletedForAd($adId, PaymentRepository::KIND_AD_ADDON)['ext_order_id']);
		$this->assertSame($renewal, PaymentRepository::latestCompletedForAd($adId)['ext_order_id']);
	}

	public function testOnlyAdministratorsMayAuthorMarkupCreatives(): void
	{
		$saved = $_SESSION ?? [];
		try {
			$_SESSION = ['user_id' => $this->userId];
			$this->assertTrue(AdsController::mayAuthorMarkup('image'));
			$this->assertFalse(AdsController::mayAuthorMarkup('html'));
			$this->assertFalse(AdsController::mayAuthorMarkup('adsense'));
			$_SESSION['is_admin'] = true;
			$this->assertTrue(AdsController::mayAuthorMarkup('html'));
		} finally {
			$_SESSION = $saved;
		}
	}
}
