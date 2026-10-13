<?php

require_once dirname(__DIR__, 2) . '/src/includes/api/PremiumController.php';

/**
 * A paid order must either be delivered or be parked for an operator — never fail, and tell
 * the buyer so, on every sweep for ever.
 */
final class PaymentFulfilmentFailureTest extends RepoTestCase
{
	private int $groupId = 0;
	private int $userId = 0;
	private int $planId = 0;

	protected function setUp(): void
	{
		$this->truncate('notifications', 'notification_prefs', 'promo_reservations', 'promo_codes', 'payments', 'plans', 'users', 'security_events', 'audit_log');
		applyTestNotificationDefaults();
		$group = Database::saveGroup(null, ['name' => 'Failure plan ' . bin2hex(random_bytes(3))]);
		$this->groupId = (int) $group['id'];
		$name = 'failuser' . bin2hex(random_bytes(3));
		Database::registerUser($name, $name . '@example.com', 'Str0ng!pass');
		$this->userId = (int) Database::loginUser($name, 'Str0ng!pass')['user']['id'];
		$plan = PlanRepository::save(null, [
			'name' => 'Failure plan',
			'group_id' => $this->groupId,
			'duration_days' => 30,
			'enabled' => 1,
		]);
		$this->planId = (int) $plan['id'];
	}

	protected function tearDown(): void
	{
		$this->truncate('notifications', 'promo_reservations', 'promo_codes', 'payments', 'plans', 'security_events');
		if ($this->groupId > 0) {
			Database::deleteGroup($this->groupId);
		}
	}

	private function failedNotices(): int
	{
		return (int) Database::getInstance()->query(
			"SELECT COUNT(*) FROM `" . Database::table('notifications') . "` WHERE `type` = 'payment.failed'"
		)->fetchColumn();
	}

	public function testPromoOrderPaidAfterTheGiveUpSweepIsStillDelivered(): void
	{
		PromoCodeRepository::save(null, ['code' => 'LATE', 'percent_off' => 25, 'max_uses' => 1, 'enabled' => 1]);
		$ext = PaymentRepository::start('przelewy24', $this->planId, $this->userId, 750, 'PLN', PromoCodeRepository::findByCode('LATE'));
		$this->assertNotNull($ext);
		// What the 72-hour sweep does to an order nobody seems to have paid.
		PaymentRepository::setStatus((string) $ext, PaymentRepository::CANCELED);
		// Someone else uses the last slot of the code in the meantime.
		$this->assertSame(0, (int) PromoCodeRepository::findByCode('LATE')['reserved_count']);

		$this->assertTrue(PaymentReconciler::fulfil(PaymentRepository::byExtOrderId((string) $ext), 750, 'PLN', 'p24-notify'));
		$row = PaymentRepository::byExtOrderId((string) $ext);
		$this->assertSame(PaymentRepository::COMPLETED, $row['status']);
		$this->assertNotNull($row['granted_at']);
		$code = PromoCodeRepository::findByCode('LATE');
		$this->assertSame(1, (int) $code['used_count']);
		$this->assertSame(0, (int) $code['reserved_count']);
		$this->assertSame(0, $this->failedNotices());
	}

	public function testOrderWhoseGroupVanishedIsParkedAndAnnouncedOnce(): void
	{
		$ext = (string) PaymentRepository::start('payu', $this->planId, $this->userId, 1000, 'PLN');
		$this->assertSame(
			['success' => false, 'error' => 'api.group_has_pending_payments'],
			Database::deleteGroup($this->groupId),
			'a group with a paid order in flight must not be deletable'
		);
		// Simulate a group removed behind the application's back.
		Database::getInstance()->prepare("DELETE FROM `" . Database::table('groups') . "` WHERE `id` = ?")
			->execute([$this->groupId]);
		$deletedGroup = $this->groupId;
		$this->groupId = 0;

		for ($i = 0; $i < 3; $i++) {
			$this->assertFalse(PaymentReconciler::fulfil(PaymentRepository::byExtOrderId($ext), 1000, 'PLN', 'notify'));
		}
		$row = PaymentRepository::byExtOrderId($ext);
		$this->assertNull($row['granted_at']);
		$this->assertTrue(PaymentReconciler::isPermanentError($row['fulfillment_last_error']));
		$this->assertSame(1, $this->failedNotices(), 'the buyer hears about it once, not on every attempt');

		$sweep = PaymentReconciler::sweepPending();
		$this->assertSame(0, $sweep['checked'], 'a parked order leaves the polling sweep');
		$this->assertGreaterThan(0, $deletedGroup);
	}

	public function testTransientFailuresDoNotRepeatTheNotice(): void
	{
		$ext = (string) PaymentRepository::start('payu', $this->planId, $this->userId, 1000, 'PLN');
		for ($i = 0; $i < 3; $i++) {
			$this->assertFalse(PaymentReconciler::fulfil(PaymentRepository::byExtOrderId($ext), 10, 'PLN', 'notify'));
		}
		$this->assertSame(1, $this->failedNotices());
	}

	public function testRefusedPremiumActivationIsAuditedOncePerWindow(): void
	{
		Database::setSecretSetting('premium_api_token', 'right-token');
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer wrong-token';
		try {
			for ($i = 0; $i < 5; $i++) {
				ob_start();
				PremiumController::handlePremiumActivate();
				$out = (string) ob_get_clean();
				$this->assertStringContainsString('"success":false', $out);
			}
		} finally {
			unset($_SERVER['HTTP_AUTHORIZATION']);
			Database::setSecretSetting('premium_api_token', '');
		}
		$rows = (int) Database::getInstance()->query(
			"SELECT COUNT(*) FROM `" . Database::table('audit_log') . "` WHERE `action` = 'premium_activate_denied'"
		)->fetchColumn();
		$this->assertSame(1, $rows);
	}
}
