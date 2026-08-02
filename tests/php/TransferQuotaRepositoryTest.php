<?php

final class TransferQuotaRepositoryTest extends RepoTestCase
{
	protected function setUp(): void
	{
		$this->truncate('transfer_quota_usage', 'users');
	}

	public function testUtcCalendarWindowsAreDeterministic(): void
	{
		$now = gmmktime(12, 0, 0, 7, 29, 2026);
		$this->assertSame(
			gmmktime(0, 0, 0, 7, 27, 2026),
			TransferQuotaRepository::periodWindow('week', $now)['start']
		);
		$this->assertSame(
			gmmktime(0, 0, 0, 8, 1, 2026),
			TransferQuotaRepository::periodWindow('month', $now)['end']
		);
	}

	public function testUserStatusReadsCurrentUsedAndReservedBytes(): void
	{
		$pdo = Database::getInstance();
		$pdo->prepare(
			"INSERT INTO `" . Database::table('users') . "`
			 (`username`, `email`, `password_hash`, `role`, `is_active`, `created_at`)
			 VALUES ('quota-reader', 'quota-reader@example.test', 'x', 'user', 1, ?)"
		)->execute([time()]);
		$userId = (int) $pdo->lastInsertId();
		$window = TransferQuotaRepository::periodWindow('week');
		$pdo->prepare(
			"INSERT INTO `" . Database::table('transfer_quota_usage') . "`
			 (`subject_type`, `subject_key`, `period`, `period_start`, `used_bytes`,
			  `reserved_bytes`, `updated_at`) VALUES ('user', ?, 'week', ?, 100, 25, ?)"
		)->execute([(string) $userId, $window['start'], time()]);

		$status = TransferQuotaRepository::forUser($userId, [
			'transfer_quota_bytes' => 1000,
			'transfer_quota_period' => 'week',
		]);
		$this->assertSame(1000, $status['limit']);
		$this->assertSame(100, $status['used']);
		$this->assertSame(25, $status['reserved']);
		$this->assertSame($window['end'], $status['resets_at']);
	}
}
