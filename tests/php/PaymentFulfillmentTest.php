<?php

/**
 * Payment fulfilment is a recoverable state machine: claiming work is not success, and the
 * product mutation must commit together with COMPLETED/granted_at.
 */
final class PaymentFulfillmentTest extends RepoTestCase
{
	private const FINALIZE_TRIGGER = 'fh_test_payment_finalize_fail';

	private int $groupId = 0;
	private int $userId = 0;
	private array $plan = [];

	protected function setUp(): void
	{
		$pdo = Database::getInstance();
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}
		$this->dropFinalizeTrigger();
		$this->truncate('notifications', 'notification_prefs', 'payments', 'plans', 'users');
		applyTestNotificationDefaults();

		$group = Database::saveGroup(null, ['name' => 'Payment Pro ' . bin2hex(random_bytes(3))]);
		$this->groupId = (int) $group['id'];

		$name = 'payuser' . bin2hex(random_bytes(3));
		$registered = Database::registerUser($name, $name . '@example.com', 'Str0ng!pass');
		$this->assertTrue($registered['success'], $registered['error'] ?? '');
		$this->userId = (int) Database::loginUser($name, 'Str0ng!pass')['user']['id'];

		$saved = PlanRepository::save(null, [
			'name' => 'Payment plan',
			'group_id' => $this->groupId,
			'duration_days' => 30,
			'enabled' => 1,
		]);
		$this->assertTrue($saved['success'], $saved['error'] ?? '');
		$this->plan = PlanRepository::get((int) $saved['id']);
	}

	protected function tearDown(): void
	{
		$pdo = Database::getInstance();
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}
		$this->dropFinalizeTrigger();
		$this->truncate('notifications', 'notification_prefs', 'payments', 'plans', 'users');
		if ($this->groupId > 0) {
			Database::deleteGroup($this->groupId);
		}
	}

	public function testSuccessfulFulfilmentCompletesOnceWithoutExtendingOnRetry(): void
	{
		$ext = $this->startPayment((int) $this->plan['id']);
		$payment = PaymentRepository::byExtOrderId($ext);

		$this->assertTrue(PaymentReconciler::fulfil($payment, 1900, 'PLN', 'notify'));

		$row = PaymentRepository::byExtOrderId($ext);
		$this->assertSame(PaymentRepository::COMPLETED, $row['status']);
		$this->assertNotNull($row['granted_at']);
		$this->assertNull($row['processing_token']);
		$this->assertNull($row['processing_expires_at']);
		$this->assertSame(1, (int) $row['fulfillment_attempts']);
		$this->assertNull($row['fulfillment_last_error']);

		$user = Database::getUserById($this->userId);
		$this->assertSame($this->groupId, (int) $user['group_id']);
		$this->assertSame($ext, $user['group_payment_ext_order_id']);
		$firstExpiry = (int) $user['group_expires_at'];

		$this->assertTrue(PaymentReconciler::fulfil($row, 1900, 'PLN', 'notify'));
		$afterRetry = PaymentRepository::byExtOrderId($ext);
		$userAfterRetry = Database::getUserById($this->userId);
		$this->assertSame(1, (int) $afterRetry['fulfillment_attempts']);
		$this->assertSame($firstExpiry, (int) $userAfterRetry['group_expires_at']);
	}

	public function testProductSnapshotSurvivesCatalogRowDeletion(): void
	{
		$ext = $this->startPayment((int) $this->plan['id']);
		Database::getInstance()
			->prepare('DELETE FROM `' . Database::table('plans') . '` WHERE `id` = ?')
			->execute([(int) $this->plan['id']]);
		$payment = PaymentRepository::byExtOrderId($ext);

		$this->assertTrue(PaymentReconciler::fulfil($payment, 1900, 'PLN', 'notify'));
		$completed = PaymentRepository::byExtOrderId($ext);
		$this->assertSame(PaymentRepository::COMPLETED, $completed['status']);
		$this->assertSame(1, (int) $completed['fulfillment_attempts']);
		$this->assertNull($completed['fulfillment_last_error']);
		$this->assertSame($this->groupId, (int) Database::getUserById($this->userId)['group_id']);
	}

	public function testCheckoutSnapshotFreezesInvoicePartiesAndDocumentText(): void
	{
		$oldSeller = Database::getSetting('invoice_seller', '');
		$oldPrefix = Database::getSetting('invoice_prefix', 'FH');
		$oldFooter = Database::getSetting('invoice_footer', '');
		try {
			Database::setSetting('invoice_seller', 'Snapshot Seller Ltd.');
			Database::setSetting('invoice_prefix', 'SNAP');
			Database::setSetting('invoice_footer', 'Original footer');
			$before = Database::getUserById($this->userId);
			$ext = $this->startPayment((int) $this->plan['id']);

			Database::setSetting('invoice_seller', 'Changed Seller');
			Database::setSetting('invoice_prefix', 'NEW');
			Database::setSetting('invoice_footer', 'Changed footer');
			Database::getInstance()
				->prepare('UPDATE `' . Database::table('users') . '`
					SET `username` = ?, `email` = ? WHERE `id` = ?')
				->execute(['renamed', 'changed@example.com', $this->userId]);

			$payment = PaymentRepository::byExtOrderId($ext);
			$snapshot = json_decode((string) $payment['product_snapshot'], true);
			$this->assertSame(2, $snapshot['version']);
			$this->assertSame('Payment plan', $snapshot['product']['name']);
			$this->assertSame('Snapshot Seller Ltd.', $snapshot['invoice']['seller']);
			$this->assertSame('SNAP', $snapshot['invoice']['prefix']);
			$this->assertSame('Original footer', $snapshot['invoice']['footer']);
			$this->assertSame($before['username'], $snapshot['invoice']['buyer']['username']);
			$this->assertSame($before['email'], $snapshot['invoice']['buyer']['email']);
		} finally {
			Database::setSetting('invoice_seller', $oldSeller);
			Database::setSetting('invoice_prefix', $oldPrefix);
			Database::setSetting('invoice_footer', $oldFooter);
		}
	}

	public function testAmountMismatchNeverClaimsOrGrantsThePayment(): void
	{
		$ext = $this->startPayment((int) $this->plan['id']);
		$before = Database::getUserById($this->userId);

		$this->assertFalse(PaymentReconciler::fulfil(
			PaymentRepository::byExtOrderId($ext),
			1899,
			'PLN',
			'notify'
		));

		$row = PaymentRepository::byExtOrderId($ext);
		$after = Database::getUserById($this->userId);
		$this->assertSame(PaymentRepository::NEW, $row['status']);
		$this->assertNull($row['granted_at']);
		$this->assertSame(0, (int) $row['fulfillment_attempts']);
		$this->assertSame('amount_or_currency_mismatch', $row['fulfillment_last_error']);
		$this->assertSame($before['group_id'], $after['group_id']);
		$this->assertSame($before['group_expires_at'], $after['group_expires_at']);
	}

	public function testExpiredLeaseCanBeTakenOverAndStaleOwnerCannotReleaseIt(): void
	{
		$ext = $this->startPayment((int) $this->plan['id']);

		$first = PaymentRepository::claimForGrant($ext);
		$this->assertSame(PaymentRepository::CLAIM_ACQUIRED, $first['state']);
		PaymentRepository::setStatus($ext, PaymentRepository::PENDING);
		$stillOwned = PaymentRepository::byExtOrderId($ext);
		$this->assertSame(PaymentRepository::PROCESSING, $stillOwned['status']);
		$this->assertSame($first['token'], $stillOwned['processing_token']);
		$this->assertSame(
			PaymentRepository::CLAIM_BUSY,
			PaymentRepository::claimForGrant($ext)['state']
		);

		Database::getInstance()
			->prepare('UPDATE `' . Database::table('payments') . '`
				SET `processing_expires_at` = ? WHERE `ext_order_id` = ?')
			->execute([time() - 1, $ext]);

		$second = PaymentRepository::claimForGrant($ext);
		$this->assertSame(PaymentRepository::CLAIM_ACQUIRED, $second['state']);
		$this->assertNotSame($first['token'], $second['token']);

		$pdo = Database::getInstance();
		$this->assertTrue($pdo->beginTransaction());
		$this->assertFalse(PaymentRepository::lockGrant($ext, (string) $first['token']));
		$this->assertTrue($pdo->rollBack());
		$this->assertFalse(PaymentRepository::failGrant($ext, (string) $first['token'], 'stale_worker'));
		$this->assertTrue(PaymentRepository::failGrant($ext, (string) $second['token'], 'forced_retry'));

		$row = PaymentRepository::byExtOrderId($ext);
		$this->assertSame(PaymentRepository::PENDING, $row['status']);
		$this->assertNull($row['granted_at']);
		$this->assertSame(2, (int) $row['fulfillment_attempts']);
		$this->assertSame('forced_retry', $row['fulfillment_last_error']);
	}

	public function testFinalizeFailureRollsBackGrantedProductAndRetrySucceeds(): void
	{
		$ext = $this->startPayment((int) $this->plan['id']);
		$before = Database::getUserById($this->userId);

		$this->installFinalizeTrigger();
		try {
			$this->assertFalse(PaymentReconciler::fulfil(
				PaymentRepository::byExtOrderId($ext),
				1900,
				'PLN',
				'notify'
			));
		} finally {
			$this->dropFinalizeTrigger();
		}

		$failed = PaymentRepository::byExtOrderId($ext);
		$afterFailure = Database::getUserById($this->userId);
		$this->assertSame(PaymentRepository::PENDING, $failed['status']);
		$this->assertNull($failed['granted_at']);
		$this->assertSame('finalize_failed', $failed['fulfillment_last_error']);
		$this->assertSame($before['group_id'], $afterFailure['group_id']);
		$this->assertSame($before['group_expires_at'], $afterFailure['group_expires_at']);
		$this->assertSame(0, $this->notificationCount('payment.completed'));
		$this->assertSame(1, $this->notificationCount('payment.failed'));

		$this->assertTrue(PaymentReconciler::fulfil($failed, 1900, 'PLN', 'notify'));
		$completed = PaymentRepository::byExtOrderId($ext);
		$afterRetry = Database::getUserById($this->userId);
		$this->assertSame(PaymentRepository::COMPLETED, $completed['status']);
		$this->assertNotNull($completed['granted_at']);
		$this->assertSame(2, (int) $completed['fulfillment_attempts']);
		$this->assertSame($this->groupId, (int) $afterRetry['group_id']);
		$this->assertSame(1, $this->notificationCount('payment.completed'));
	}

	public function testRefundLeaseIsExclusiveAndOnlyLatestPurchaseCanRevokeEntitlement(): void
	{
		$firstExt = $this->startPayment((int) $this->plan['id']);
		$this->assertTrue(PaymentReconciler::fulfil(
			PaymentRepository::byExtOrderId($firstExt),
			1900,
			'PLN',
			'notify'
		));
		$firstExpiry = (int) Database::getUserById($this->userId)['group_expires_at'];

		$secondExt = $this->startPayment((int) $this->plan['id']);
		$this->assertTrue(PaymentReconciler::fulfil(
			PaymentRepository::byExtOrderId($secondExt),
			1900,
			'PLN',
			'notify'
		));
		$latest = Database::getUserById($this->userId);
		$this->assertSame($secondExt, $latest['group_payment_ext_order_id']);
		$this->assertGreaterThan($firstExpiry, (int) $latest['group_expires_at']);

		$firstToken = PaymentRepository::claimRefund($firstExt);
		$this->assertNotNull($firstToken);
		$this->assertNull(PaymentRepository::claimRefund($firstExt));
		$oldRefund = PaymentRepository::finalizeRefund(
			$firstExt,
			(string) $firstToken,
			$this->groupId
		);
		$this->assertTrue($oldRefund['success']);
		$this->assertFalse($oldRefund['revoked']);
		$afterOldRefund = Database::getUserById($this->userId);
		$this->assertSame($this->groupId, (int) $afterOldRefund['group_id']);
		$this->assertSame($secondExt, $afterOldRefund['group_payment_ext_order_id']);

		$secondToken = PaymentRepository::claimRefund($secondExt);
		$this->assertNotNull($secondToken);
		$currentRefund = PaymentRepository::finalizeRefund(
			$secondExt,
			(string) $secondToken,
			$this->groupId
		);
		$this->assertTrue($currentRefund['success']);
		$this->assertTrue($currentRefund['revoked']);
		$afterCurrentRefund = Database::getUserById($this->userId);
		$this->assertNull($afterCurrentRefund['group_id']);
		$this->assertNull($afterCurrentRefund['group_payment_ext_order_id']);
	}

	public function testFailedProviderRefundCanReleaseItsLease(): void
	{
		$ext = $this->startPayment((int) $this->plan['id']);
		$this->assertTrue(PaymentReconciler::fulfil(
			PaymentRepository::byExtOrderId($ext),
			1900,
			'PLN',
			'notify'
		));
		$token = PaymentRepository::claimRefund($ext);
		$this->assertNotNull($token);

		PaymentRepository::releaseRefund($ext, (string) $token);
		$row = PaymentRepository::byExtOrderId($ext);
		$this->assertSame(PaymentRepository::COMPLETED, $row['status']);
		$this->assertNull($row['processing_token']);
		$this->assertNotNull(PaymentRepository::claimRefund($ext));
	}

	private function startPayment(int $planId): string
	{
		$ext = PaymentRepository::start('payu', $planId, $this->userId, 1900, 'PLN');
		$this->assertNotNull($ext);
		return (string) $ext;
	}

	private function installFinalizeTrigger(): void
	{
		$table = Database::table('payments');
		$sql = 'CREATE TRIGGER `' . self::FINALIZE_TRIGGER . '`
			BEFORE UPDATE ON `' . $table . '`
			FOR EACH ROW
			BEGIN
				IF NEW.`status` = \'COMPLETED\' AND OLD.`status` = \'PROCESSING\' THEN
					SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'forced finalize failure\';
				END IF;
			END';
		try {
			Database::getInstance()->exec($sql);
		} catch (PDOException $e) {
			$this->markTestSkipped('Test database cannot create a fault-injection trigger: ' . $e->getMessage());
		}
	}

	private function notificationCount(string $type): int
	{
		$stmt = Database::getInstance()
			->prepare('SELECT COUNT(*) FROM `' . Database::table('notifications') . '` WHERE `type` = ?');
		$stmt->execute([$type]);
		return (int) $stmt->fetchColumn();
	}

	private function dropFinalizeTrigger(): void
	{
		try {
			Database::getInstance()->exec('DROP TRIGGER IF EXISTS `' . self::FINALIZE_TRIGGER . '`');
		} catch (PDOException $e) {
		}
	}
}
