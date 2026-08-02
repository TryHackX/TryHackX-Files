<?php

/**
 * StorageEnforcer: what happens to stored files when a group shrinks under an account
 * (an admin moves someone, or a paid plan lapses) — see pkt B.
 */
final class StorageEnforcerTest extends RepoTestCase
{
	private int $groupId;
	private int $userId;

	protected function setUp(): void
	{
		$this->truncate('files', 'users');
		Database::setSetting('storage_enforce', '1');
		Database::setSetting('storage_grace_days', 15);

		// 10 MiB quota, 2 MiB per file.
		$res = Database::saveGroup(null, [
			'name' => 'Small ' . bin2hex(random_bytes(3)),
			'storage_quota_mb' => 10,
			'max_file_size_mb' => 2,
		]);
		$this->groupId = (int) $res['id'];

		$name = 'quotauser' . bin2hex(random_bytes(3));
		Database::registerUser($name, $name . '@example.com', 'Str0ng!pass');
		$this->userId = (int) Database::loginUser($name, 'Str0ng!pass')['user']['id'];
		Database::setUserGroup($this->userId, $this->groupId);

		// Registration seeds a per-account quota from `default_storage_limit_mb`, and that
		// override beats the group's (same precedence as get_user_quota() in the upload server).
		// Clear it so these tests exercise the group limit.
		Database::getInstance()
			->prepare('UPDATE `' . Database::table('users') . '` SET `storage_limit` = 0 WHERE `id` = ?')
			->execute([$this->userId]);
	}

	protected function tearDown(): void
	{
		Database::deleteGroup($this->groupId);
	}

	private const MIB = 1048576;

	/** Insert a file owned by the test user, `$ageDays` old. */
	private function file(string $id, int $sizeMib, int $ageDays = 0): void
	{
		$this->insertFile($id, $this->userId, [
			'size' => $sizeMib * self::MIB,
			'original_name' => $id . '.bin',
			'uploaded_at' => time() - ($ageDays * 86400),
		]);
	}

	private function fileIds(): array
	{
		$stmt = Database::getInstance()->prepare(
			'SELECT `id` FROM `' . Database::table('files') . '` WHERE `user_id` = ? ORDER BY `uploaded_at` ASC'
		);
		$stmt->execute([$this->userId]);
		return $stmt->fetchAll(PDO::FETCH_COLUMN);
	}

	private function backdateClock(int $days): void
	{
		Database::getInstance()
			->prepare('UPDATE `' . Database::table('users') . '` SET `limits_over_since` = ? WHERE `id` = ?')
			->execute([time() - ($days * 86400), $this->userId]);
	}

	public function testAccountWithinItsLimitsIsLeftAlone(): void
	{
		$this->file('se_ok1', 1);
		$this->file('se_ok2', 1);

		$status = StorageEnforcer::status($this->userId);
		$this->assertFalse($status['over']);

		$res = StorageEnforcer::enforce($this->userId);
		$this->assertSame('ok', $res['state']);
		$this->assertCount(2, $this->fileIds());
	}

	public function testOverQuotaStartsAClockButDeletesNothing(): void
	{
		for ($i = 0; $i < 6; $i++) {
			$this->file('se_q' . $i, 2, 10 - $i); // 12 MiB against a 10 MiB quota
		}

		$res = StorageEnforcer::enforce($this->userId);
		$this->assertSame('grace', $res['state']);
		$this->assertSame(0, $res['deleted']);
		$this->assertCount(6, $this->fileIds());

		$status = StorageEnforcer::status($this->userId);
		$this->assertTrue($status['over']);
		$this->assertNotNull($status['since']);
		$this->assertSame(2 * self::MIB, $status['overBy']);
	}

	public function testOldestGoFirstOnceTheGraceHasPassed(): void
	{
		// Oldest first in the list; 12 MiB total against a 10 MiB quota.
		for ($i = 0; $i < 6; $i++) {
			$this->file('se_g' . $i, 2, 10 - $i);
		}
		StorageEnforcer::enforce($this->userId);   // starts the clock
		$this->backdateClock(20);

		$res = StorageEnforcer::enforce($this->userId);
		$this->assertSame('enforced', $res['state']);
		$this->assertSame(1, $res['deleted']);     // one 2 MiB file is enough
		$this->assertSame(2 * self::MIB, $res['freed']);

		$left = $this->fileIds();
		$this->assertCount(5, $left);
		$this->assertNotContains('se_g0', $left);  // the oldest one went
		$this->assertContains('se_g5', $left);     // the newest stayed

		// Back within limits, so the clock is cleared.
		$this->assertNull(StorageEnforcer::status($this->userId)['since']);
	}

	/** A file larger than the group's per-file limit is a violation on its own. */
	public function testOversizeFileIsReportedAndRemovedFirst(): void
	{
		$this->file('se_big', 5, 1);    // 5 MiB > the 2 MiB per-file limit
		$this->file('se_small', 1, 9);  // older, but perfectly legal

		$status = StorageEnforcer::status($this->userId);
		$this->assertTrue($status['over']);
		$this->assertSame(0, $status['overBy']);            // 6 MiB is inside the 10 MiB quota
		$this->assertCount(1, $status['oversize']);
		$this->assertSame('se_big', $status['oversize'][0]['id']);

		StorageEnforcer::enforce($this->userId);
		$this->backdateClock(20);
		$res = StorageEnforcer::enforce($this->userId);

		$this->assertSame('enforced', $res['state']);
		// The oversize file goes even though it is the newer one; the legal file stays.
		$this->assertSame(['se_small'], $this->fileIds());
	}

	public function testEnforcementCanBeSwitchedOff(): void
	{
		Database::setSetting('storage_enforce', '0');
		for ($i = 0; $i < 6; $i++) {
			$this->file('se_off' . $i, 2, 10 - $i);
		}

		$res = StorageEnforcer::enforce($this->userId);
		$this->assertSame('disabled', $res['state']);
		$this->assertCount(6, $this->fileIds());

		Database::setSetting('storage_enforce', '1');
	}

	public function testSweepVisitsAccountsThatHoldFiles(): void
	{
		$this->file('se_sw1', 2, 3);
		$out = StorageEnforcer::sweep();
		$this->assertGreaterThanOrEqual(1, $out['checked']);
		$this->assertSame(0, $out['deleted']);
	}
}
