<?php

/**
 * The numbers behind the Premium tab (pt 6).
 *
 * Money reported wrongly is worse than money not reported, so the two rules that are easy to
 * get quietly wrong get tests: only paid orders count, and currencies are never added together.
 */
final class PaymentStatsTest extends RepoTestCase
{
	private int $userA = 0;
	private int $userB = 0;
	private int $planId = 0;

	protected function setUp(): void
	{
		$this->truncate('payments', 'plans');
		$pdo = Database::getInstance();
		$pdo->prepare(
			'INSERT INTO `' . Database::table('plans') . '`
			 (`name`, `enabled`, `created_at`) VALUES (?, 1, ?)'
		)->execute(['Statistics fixture', time()]);
		$this->planId = (int) $pdo->lastInsertId();
		$this->userA = $this->makeUser();
		$this->userB = $this->makeUser();
	}

	private function makeUser(): int
	{
		$name = 'payuser' . bin2hex(random_bytes(4));
		Database::registerUser($name, $name . '@example.com', 'Str0ng!pass');
		return (int) Database::loginUser($name, 'Str0ng!pass')['user']['id'];
	}

	/** Insert a payment directly, so a status can be set without going near a provider. */
	private function payment(int $userId, int $minor, string $currency, string $status, int $ageDays = 0): string
	{
		$ext = PaymentRepository::start('payu', $this->planId, $userId, $minor, $currency);
		$pdo = Database::getInstance();
		$pdo->prepare('UPDATE `' . Database::table('payments') . '`
			SET `status` = ?, `created_at` = ? WHERE `ext_order_id` = ?')
			->execute([$status, time() - ($ageDays * 86400), $ext]);
		return $ext;
	}

	public function testOnlyPaidOrdersCountAsRevenue(): void
	{
		$this->payment($this->userA, 1900, 'PLN', PaymentRepository::COMPLETED);
		$this->payment($this->userA, 5000, 'PLN', PaymentRepository::PENDING);
		$this->payment($this->userB, 9900, 'PLN', PaymentRepository::CANCELED);

		$s = PaymentRepository::stats(30);
		$this->assertCount(1, $s['revenue']);
		$this->assertSame(1900, $s['revenue'][0]['minor'], 'a started checkout is not a sale');
		$this->assertSame(1, $s['orders']);
		$this->assertSame(1, $s['pending']);
		$this->assertSame(1, $s['canceled']);
		$this->assertSame(1, $s['buyers']);
	}

	public function testCurrenciesAreReportedSeparately(): void
	{
		$this->payment($this->userA, 1900, 'PLN', PaymentRepository::COMPLETED);
		$this->payment($this->userB, 1900, 'EUR', PaymentRepository::COMPLETED);

		$s = PaymentRepository::stats(30);
		$this->assertCount(2, $s['revenue'], '19 PLN + 19 EUR is not 38 of anything');
		$byCurrency = [];
		foreach ($s['revenue'] as $r) {
			$byCurrency[$r['currency']] = $r['minor'];
		}
		$this->assertSame(1900, $byCurrency['PLN']);
		$this->assertSame(1900, $byCurrency['EUR']);
		$this->assertSame(2, $s['orders'], 'the order count still spans both');
		$this->assertSame(2, $s['buyers']);
	}

	public function testTheSameBuyerCountsOnce(): void
	{
		$this->payment($this->userA, 1900, 'PLN', PaymentRepository::COMPLETED);
		$this->payment($this->userA, 1900, 'PLN', PaymentRepository::COMPLETED);

		$s = PaymentRepository::stats(30);
		$this->assertSame(2, $s['orders']);
		$this->assertSame(1, $s['buyers'], 'two purchases by one person is one buyer');
		$this->assertSame(3800, $s['revenue'][0]['minor']);
	}

	public function testTheRangeExcludesOlderPayments(): void
	{
		$this->payment($this->userA, 1900, 'PLN', PaymentRepository::COMPLETED, 2);
		$this->payment($this->userB, 9900, 'PLN', PaymentRepository::COMPLETED, 60);

		$this->assertSame(1900, PaymentRepository::stats(7)['revenue'][0]['minor']);
		$this->assertSame(11800, PaymentRepository::stats(90)['revenue'][0]['minor']);
	}

	public function testSeriesHasOneEntryPerDayIncludingEmptyOnes(): void
	{
		$this->payment($this->userA, 2500, 'PLN', PaymentRepository::COMPLETED);

		$series = PaymentRepository::series(7);
		$this->assertCount(7, $series['days']);
		$this->assertCount(7, $series['revenue']);
		$this->assertSame('PLN', $series['currency']);
		// Today is the last bucket, and it holds the payment in major units.
		$this->assertSame(25.0, $series['revenue'][6]);
		$this->assertSame(0.0, $series['revenue'][0], 'a quiet day is a real zero');
	}

	public function testBrowseFiltersByStatus(): void
	{
		$this->payment($this->userA, 1900, 'PLN', PaymentRepository::COMPLETED);
		$this->payment($this->userA, 1900, 'PLN', PaymentRepository::CANCELED);

		$this->assertSame(2, PaymentRepository::browse()['total']);
		$this->assertSame(1, PaymentRepository::browse(['status' => PaymentRepository::COMPLETED])['total']);
		$this->assertSame(1, PaymentRepository::browse(['status' => PaymentRepository::CANCELED])['total']);
	}

	public function testBulkBuyerCandidatesFreezeOnlyCompletedPurchasesInRange(): void
	{
		$this->payment($this->userA, 1900, 'PLN', PaymentRepository::COMPLETED);
		$this->payment($this->userA, 1900, 'PLN', PaymentRepository::COMPLETED);
		$this->payment($this->userB, 1900, 'PLN', PaymentRepository::COMPLETED, 60);
		$this->payment($this->userB, 1900, 'PLN', PaymentRepository::PENDING);

		$ids = PaymentRepository::bulkGrantCandidates([
			'source' => 'buyers',
			'from' => time() - (7 * 86400),
			'to' => time() + 86400,
			'purchased_plan_id' => $this->planId,
		]);

		$this->assertSame([$this->userA], $ids, 'duplicates, pending payments and old buyers are excluded');
	}
}
