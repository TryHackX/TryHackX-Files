<?php

/**
 * Per-group retention and the payment ledger (pt 6 / pt 10).
 *
 * Both are about not doing something twice and not doing it too early: a group decides how
 * long its members' files live, a group *change* restarts that clock, and a payment is
 * fulfilled exactly once however many times the provider tells us about it.
 */
final class RetentionTest extends RepoTestCase
{
	private int $groupId = 0;
	private int $userId = 0;
	private int $planId = 0;

	protected function setUp(): void
	{
		$this->truncate('files', 'payments');
		Database::setSetting('auto_delete_days', 0);
		Database::invalidateSettingsCache();

		$res = Database::saveGroup(null, ['name' => 'Ret ' . bin2hex(random_bytes(3))]);
		$this->groupId = (int) $res['id'];

		$name = 'retuser' . bin2hex(random_bytes(3));
		Database::registerUser($name, $name . '@example.com', 'Str0ng!pass');
		$this->userId = (int) Database::loginUser($name, 'Str0ng!pass')['user']['id'];
		$savedPlan = PlanRepository::save(null, [
			'name' => 'Retention payment fixture',
			'group_id' => $this->groupId,
			'duration_days' => 30,
			'enabled' => 1,
		]);
		$this->planId = (int) ($savedPlan['id'] ?? 0);
	}

	protected function tearDown(): void
	{
		$this->truncate('payments');
		if ($this->planId > 0) {
			PlanRepository::delete($this->planId);
		}
		Database::deleteGroup($this->groupId);
		Database::setSetting('auto_delete_days', 0);
		Database::invalidateSettingsCache();
	}

	private function setRetention(int $days): array
	{
		Database::saveGroup($this->groupId, ['name' => 'Ret ' . $this->groupId, 'auto_delete_days' => $days]);
		return Database::getGroupById($this->groupId);
	}

	/* ---------------- retention resolution ---------------- */

	public function testZeroFollowsTheInstallationDefault(): void
	{
		$group = $this->setRetention(0);
		$this->assertSame(0, GroupRepository::retentionDays($group), 'default is 0 → keep forever');

		Database::setSetting('auto_delete_days', 30);
		Database::invalidateSettingsCache();
		$this->assertSame(30, GroupRepository::retentionDays($group));
	}

	public function testOwnValueBeatsTheDefault(): void
	{
		Database::setSetting('auto_delete_days', 30);
		Database::invalidateSettingsCache();
		$this->assertSame(7, GroupRepository::retentionDays($this->setRetention(7)));
	}

	public function testNeverBeatsTheDefault(): void
	{
		Database::setSetting('auto_delete_days', 30);
		Database::invalidateSettingsCache();
		$group = $this->setRetention(GroupRepository::RETENTION_NEVER);
		$this->assertSame(-1, (int) $group['auto_delete_days'], 'stored verbatim');
		$this->assertSame(0, GroupRepository::retentionDays($group), 'resolves to "keep forever"');
	}

	/* ---------------- the sweep ---------------- */

	public function testSweepDeletesOnlyWhatIsPastItsGroupsRetention(): void
	{
		$this->setRetention(7);
		Database::setUserGroup($this->userId, $this->groupId);
		$this->backdateGroupChange(60);

		$this->insertFile('rold', $this->userId, ['uploaded_at' => time() - 30 * 86400]);
		$this->insertFile('rnew', $this->userId, ['uploaded_at' => time() - 2 * 86400]);

		$this->assertSame(1, FileManager::deleteExpiredFiles());
		$this->assertFalse($this->fileRowExists('rold'));
		$this->assertTrue($this->fileRowExists('rnew'));
	}

	public function testNeverKeepsEverything(): void
	{
		$this->setRetention(GroupRepository::RETENTION_NEVER);
		Database::setUserGroup($this->userId, $this->groupId);
		$this->backdateGroupChange(9999);

		$this->insertFile('rancient', $this->userId, ['uploaded_at' => time() - 5000 * 86400]);
		$this->assertSame(0, FileManager::deleteExpiredFiles());
		$this->assertTrue($this->fileRowExists('rancient'));
	}

	/**
	 * The point of the whole feature: a lapsed plan must not delete years of uploads on the
	 * day it lapses. The clock starts at the group change, not at the upload.
	 */
	public function testGroupChangeRestartsTheClock(): void
	{
		$this->setRetention(7);
		Database::setUserGroup($this->userId, $this->groupId);

		$this->insertFile('rmoved', $this->userId, ['uploaded_at' => time() - 400 * 86400]);

		// The assignment happened just now, so the account has its full seven days.
		$this->assertSame(0, FileManager::deleteExpiredFiles());
		$this->assertTrue($this->fileRowExists('rmoved'));

		// Once those days have passed, it goes.
		$this->backdateGroupChange(10);
		$this->assertSame(1, FileManager::deleteExpiredFiles());
		$this->assertFalse($this->fileRowExists('rmoved'));
	}

	/* ---------------- payments ---------------- */

	public function testAPaymentIsFulfilledExactlyOnce(): void
	{
		$ext = PaymentRepository::start('payu', $this->planId, $this->userId, 1900, 'PLN');
		$this->assertNotNull($ext);

		$claim = PaymentRepository::claimForGrant($ext);
		$this->assertSame(PaymentRepository::CLAIM_ACQUIRED, $claim['state'], 'first notification wins');

		$row = PaymentRepository::byExtOrderId($ext);
		$this->assertSame(PaymentRepository::PROCESSING, $row['status']);
		$this->assertNull($row['granted_at'], 'claiming must not report ungranted goods as complete');

		$retry = PaymentRepository::claimForGrant($ext);
		$this->assertSame(PaymentRepository::CLAIM_BUSY, $retry['state'], 'a live lease blocks a retry');

		$pdo = Database::getInstance();
		$this->assertTrue($pdo->beginTransaction());
		$this->assertTrue(PaymentRepository::lockGrant($ext, (string) $claim['token']));
		$this->assertTrue(PaymentRepository::completeGrant($ext, (string) $claim['token']));
		$this->assertTrue($pdo->commit());

		$row = PaymentRepository::byExtOrderId($ext);
		$this->assertSame(PaymentRepository::COMPLETED, $row['status']);
		$this->assertNotNull($row['granted_at']);

		$completed = PaymentRepository::claimForGrant($ext);
		$this->assertSame(PaymentRepository::CLAIM_COMPLETED, $completed['state']);
	}

	public function testOrderIdsAreUnique(): void
	{
		$a = PaymentRepository::start('payu', $this->planId, $this->userId, 1900, 'PLN');
		$b = PaymentRepository::start('payu', $this->planId, $this->userId, 1900, 'PLN');
		$this->assertNotSame($a, $b);
		$this->assertLessThanOrEqual(64, strlen((string) $a), 'PayU caps extOrderId at 64 chars');
	}

	/**
	 * Is the row still there? Not `FileManager::getFile()`: that also insists on bytes on disk,
	 * which these metadata-only fixtures never had — it would report every one of them missing.
	 */
	private function fileRowExists(string $id): bool
	{
		$stmt = Database::getInstance()
			->prepare('SELECT 1 FROM `' . Database::table('files') . '` WHERE `id` = ?');
		$stmt->execute([$id]);
		return (bool) $stmt->fetchColumn();
	}

	/** Move the account's "group changed" stamp $days into the past. */
	private function backdateGroupChange(int $days): void
	{
		Database::getInstance()
			->prepare('UPDATE `' . Database::table('users') . '` SET `group_changed_at` = ? WHERE `id` = ?')
			->execute([time() - $days * 86400, $this->userId]);
	}
}
