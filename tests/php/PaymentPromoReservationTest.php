<?php

final class PaymentPromoReservationTest extends RepoTestCase
{
	private int $groupId = 0;
	private int $userId = 0;
	private int $planId = 0;
	private int $otherPlanId = 0;
	private array $promo = [];

	protected function setUp(): void
	{
		$this->truncate(
			'notifications',
			'promo_reservations',
			'promo_codes',
			'payments',
			'plans',
			'users'
		);
		applyTestNotificationDefaults();

		$group = Database::saveGroup(null, ['name' => 'Promo ' . bin2hex(random_bytes(3))]);
		$this->groupId = (int) $group['id'];
		$name = 'promouser' . bin2hex(random_bytes(3));
		Database::registerUser($name, $name . '@example.com', 'Str0ng!pass');
		$this->userId = (int) Database::loginUser($name, 'Str0ng!pass')['user']['id'];
		$plan = PlanRepository::save(null, [
			'name' => 'Promo plan',
			'group_id' => $this->groupId,
			'duration_days' => 30,
			'enabled' => 1,
		]);
		$this->planId = (int) $plan['id'];
		$otherPlan = PlanRepository::save(null, [
			'name' => 'Other promo plan',
			'group_id' => $this->groupId,
			'duration_days' => 30,
			'enabled' => 1,
		]);
		$this->otherPlanId = (int) $otherPlan['id'];
		$savedPromo = PromoCodeRepository::save(null, [
			'code' => 'ONCE',
			'percent_off' => 25,
			'max_uses' => 1,
			'enabled' => 1,
		]);
		$this->promo = PromoCodeRepository::findByCode('ONCE') ?? [];
		$this->assertTrue($savedPromo['success']);
	}

	protected function tearDown(): void
	{
		$this->truncate('notifications', 'promo_reservations', 'promo_codes', 'payments');
		if ($this->planId > 0) {
			PlanRepository::delete($this->planId);
		}
		if ($this->otherPlanId > 0) {
			PlanRepository::delete($this->otherPlanId);
		}
		if ($this->groupId > 0) {
			Database::deleteGroup($this->groupId);
		}
	}

	public function testCancelReleasesReservedPromoUse(): void
	{
		$ext = PaymentRepository::start(
			'payu',
			$this->planId,
			$this->userId,
			1425,
			'PLN',
			$this->promo
		);
		$this->assertNotNull($ext);
		$this->assertPromoCounts(0, 1);

		PaymentRepository::setStatus((string) $ext, PaymentRepository::CANCELED);

		$this->assertPromoCounts(0, 0);
	}

	public function testFulfilmentRedeemsExactlyTheReservedUse(): void
	{
		$ext = PaymentRepository::start(
			'payu',
			$this->planId,
			$this->userId,
			1425,
			'PLN',
			$this->promo
		);
		$this->assertNotNull($ext);
		$this->assertNull(PaymentRepository::start(
			'payu',
			$this->planId,
			$this->userId,
			1425,
			'PLN',
			$this->promo
		), 'the cap includes reservations, not only completed redemptions');

		$this->assertTrue(PaymentReconciler::fulfil(
			PaymentRepository::byExtOrderId((string) $ext),
			1425,
			'PLN',
			'notify'
		));
		$this->assertPromoCounts(1, 0);
		$this->assertTrue(PaymentReconciler::fulfil(
			PaymentRepository::byExtOrderId((string) $ext),
			1425,
			'PLN',
			'notify'
		));
		$this->assertPromoCounts(1, 0);
	}

	public function testPlanScopedCodeCannotPriceOrReserveAnotherPlan(): void
	{
		$saved = PromoCodeRepository::save(null, [
			'code' => 'ONLYTHIS',
			'scope' => 'plan',
			'plan_id' => $this->planId,
			'percent_off' => 15,
			'enabled' => 1,
		]);
		$this->assertTrue($saved['success']);
		$promo = PromoCodeRepository::findByCode('ONLYTHIS');
		$this->assertNotNull(PromoCodeRepository::validate('ONLYTHIS', $this->planId));
		$this->assertNull(PromoCodeRepository::validate('ONLYTHIS', $this->otherPlanId));

		$this->assertNull(PaymentRepository::start(
			'payu',
			$this->otherPlanId,
			$this->userId,
			1700,
			'PLN',
			$promo
		));
		$validOrder = PaymentRepository::start(
			'payu',
			$this->planId,
			$this->userId,
			1700,
			'PLN',
			$promo
		);
		$this->assertNotNull($validOrder);
		PaymentRepository::setStatus((string) $validOrder, PaymentRepository::CANCELED);
	}

	public function testPlanScopedCodeRequiresAnExistingPaidPlan(): void
	{
		$result = PromoCodeRepository::save(null, [
			'code' => 'MISSINGPLAN',
			'scope' => 'plan',
			'plan_id' => 999999,
			'percent_off' => 10,
			'enabled' => 1,
		]);
		$this->assertFalse($result['success']);
		$this->assertSame('plan', $result['error']);
	}

	private function assertPromoCounts(int $used, int $reserved): void
	{
		$row = PromoCodeRepository::findByCode('ONCE');
		$this->assertSame($used, (int) $row['used_count']);
		$this->assertSame($reserved, (int) $row['reserved_count']);
	}
}
