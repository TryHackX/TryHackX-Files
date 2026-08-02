<?php

/**
 * Money-sensitive ad lifecycle invariants: the purchased duration is immutable, updates are
 * atomic, and an unpaid checkout keeps its draft target alive.
 */
final class AdRepositoryAtomicTest extends RepoTestCase
{
	private int $userId = 0;
	private int $packageId = 0;
	private array $package = [];

	protected function setUp(): void
	{
		$this->truncate(
			'ad_file_deletion_queue',
			'ad_stats_daily',
			'payments',
			'ads',
			'ad_packages',
			'users'
		);
		$name = 'aduser' . bin2hex(random_bytes(3));
		$registered = Database::registerUser($name, $name . '@example.com', 'Str0ng!pass');
		$this->assertTrue($registered['success'], $registered['error'] ?? '');
		$this->userId = (int) Database::loginUser($name, 'Str0ng!pass')['user']['id'];

		$pdo = Database::getInstance();
		$pdo->prepare(
			'INSERT INTO `' . Database::table('ad_packages') . '`
			 (`name`, `description`, `kind`, `zone`, `addon_zones`, `duration_days`,
			  `amount_minor`, `currency`, `priority`, `weight_bonus`, `enabled`, `sort`, `created_at`)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
		)->execute([
			'Atomic package',
			'',
			'placement',
			'home_top',
			'',
			17,
			1900,
			'PLN',
			25,
			0,
			1,
			0,
			time(),
		]);
		$this->packageId = (int) $pdo->lastInsertId();
		$this->package = [
			'id' => $this->packageId,
			'zone' => 'home_top',
			'addon_zones' => '',
			'duration_days' => 17,
			'priority' => 25,
		];
	}

	protected function tearDown(): void
	{
		$pdo = Database::getInstance();
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}
		$this->truncate(
			'ad_file_deletion_queue',
			'ad_stats_daily',
			'payments',
			'ads',
			'ad_packages',
			'users'
		);
	}

	public function testApprovalUsesPurchasedDurationAfterPackageMutation(): void
	{
		$created = AdRepository::createDraft($this->userId, $this->package, [
			'name' => 'Immutable campaign',
			'target_url' => 'https://example.com/',
			'alt_text' => 'Campaign',
		]);
		$this->assertTrue($created['success'], $created['error'] ?? '');
		$adId = (int) $created['id'];
		$this->assertSame(17, (int) AdRepository::get($adId)['purchase_duration_days']);
		$this->assertTrue(AdRepository::markPaid($adId, $this->package));

		Database::getInstance()
			->prepare('UPDATE `' . Database::table('ad_packages') . '` SET `duration_days` = 1 WHERE `id` = ?')
			->execute([$this->packageId]);

		$approved = AdRepository::approve($adId, $this->userId);
		$this->assertNotNull($approved);
		$this->assertSame('active', $approved['status']);
		$this->assertEqualsWithDelta(time() + (17 * 86400), (int) $approved['ends_at'], 5);
	}

	public function testSequentialRenewalsNeverLosePurchasedTime(): void
	{
		$created = AdRepository::createDraft($this->userId, $this->package, [
			'name' => 'Renew campaign',
			'target_url' => 'https://example.com/',
			'alt_text' => 'Campaign',
		]);
		$adId = (int) $created['id'];
		$this->assertTrue(AdRepository::markPaid($adId, $this->package));
		$this->assertNotNull(AdRepository::approve($adId, $this->userId));
		$before = (int) AdRepository::get($adId)['ends_at'];
		$renewal = $this->package + ['duration_days' => 3];
		$renewal['duration_days'] = 3;

		$this->assertTrue(AdRepository::renew($adId, $renewal));
		$this->assertTrue(AdRepository::renew($adId, $renewal));
		$after = (int) AdRepository::get($adId)['ends_at'];
		$this->assertSame($before + (6 * 86400), $after);
	}

	public function testPendingPaymentPreventsDraftDeletion(): void
	{
		$created = AdRepository::createDraft($this->userId, $this->package, [
			'name' => 'Paid target',
			'target_url' => 'https://example.com/',
			'alt_text' => 'Campaign',
		]);
		$adId = (int) $created['id'];
		$ext = PaymentRepository::startAd(
			'payu',
			$adId,
			$this->packageId,
			$this->userId,
			1900,
			'PLN'
		);
		$this->assertNotNull($ext);
		$this->assertFalse(AdRepository::delete($adId));
		$this->assertNotNull(AdRepository::get($adId));

		PaymentRepository::setStatus((string) $ext, PaymentRepository::CANCELED);
		$this->assertTrue(AdRepository::delete($adId));
		$this->assertNull(AdRepository::get($adId));
	}

	public function testOwnerListCarriesLatestPrintableAdOrder(): void
	{
		$created = AdRepository::createDraft($this->userId, $this->package, [
			'name' => 'Invoice campaign',
			'target_url' => 'https://example.com/',
			'alt_text' => 'Campaign',
		]);
		$adId = (int) $created['id'];
		$first = PaymentRepository::startAd(
			'payu',
			$adId,
			$this->packageId,
			$this->userId,
			1900,
			'PLN'
		);
		$this->assertNotNull($first);
		$settle = Database::getInstance()->prepare(
			'UPDATE `' . Database::table('payments') . '`
			 SET `status` = ?, `granted_at` = ? WHERE `ext_order_id` = ?'
		);
		$settle->execute([PaymentRepository::COMPLETED, time(), $first]);

		$latest = PaymentRepository::startAd(
			'payu',
			$adId,
			$this->packageId,
			$this->userId,
			1900,
			'PLN'
		);
		$this->assertNotNull($latest);
		$settle->execute([PaymentRepository::COMPLETED, time(), $latest]);

		$rows = AdRepository::forOwner($this->userId);
		$this->assertCount(1, $rows);
		$this->assertSame($latest, $rows[0]['order_id']);
		$this->assertSame(PaymentRepository::COMPLETED, $rows[0]['payment_status']);
	}
}
