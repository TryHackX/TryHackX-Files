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
		$this->truncate('notifications', 'ad_file_deletion_queue', 'ad_stats_daily', 'payments', 'ads', 'ad_packages', 'users');
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
		$adId = $this->draft();
		$this->assertTrue(AdRepository::markPaid($adId, $this->package, []));
		$this->assertNotNull(AdRepository::approve($adId, $this->userId));

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
