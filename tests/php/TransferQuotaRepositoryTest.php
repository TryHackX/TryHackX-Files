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

	private function guestRow(string $key): array
	{
		$stmt = Database::getInstance()->prepare(
			"SELECT `used_bytes`, `reserved_bytes` FROM `" . Database::table('transfer_quota_usage') . "`
			 WHERE `subject_type` = 'ip' AND `subject_key` = ?"
		);
		$stmt->execute([$key]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
		return ['used' => (int) ($row['used_bytes'] ?? -1), 'reserved' => (int) ($row['reserved_bytes'] ?? -1)];
	}

	/**
	 * Bytes PHP serves itself are reserved before they are sent and charged after: whatever a
	 * reservation leaves unsent goes back, and a reservation the allowance cannot cover is
	 * refused instead of letting parallel streams overshoot.
	 */
	public function testReservationsAreCheckedGrownAndSettledAgainstTheAllowance(): void
	{
		$group = ['transfer_quota_bytes' => 1000, 'transfer_quota_period' => 'week'];
		$this->assertSame([], TransferQuotaRepository::reserve(null, '198.51.100.20', ['transfer_quota_bytes' => 0], 500), 'no allowance, nothing to charge');
		$this->assertSame([], TransferQuotaRepository::reserve(null, '198.51.100.20', $group, 0));

		$first = TransferQuotaRepository::reserve(null, '198.51.100.20', $group, 600);
		$this->assertIsArray($first);
		$this->assertSame(['used' => 0, 'reserved' => 600], $this->guestRow('198.51.100.20'));
		$this->assertNull(TransferQuotaRepository::reserve(null, '198.51.100.20', $group, 600), 'a parallel stream must not overshoot');

		$this->assertTrue(TransferQuotaRepository::extend($first, 300));
		$this->assertSame(900, $first['reserved_bytes']);
		$this->assertFalse(TransferQuotaRepository::extend($first, 200), 'past the allowance');
		$this->assertSame(900, $first['reserved_bytes']);

		TransferQuotaRepository::commit($first, 700);
		$this->assertSame(['used' => 700, 'reserved' => 0], $this->guestRow('198.51.100.20'));
		$this->assertTrue(TransferQuotaRepository::wouldExceed(null, '198.51.100.20', $group, 301));
		$this->assertFalse(TransferQuotaRepository::wouldExceed(null, '198.51.100.20', $group, 300));
	}

	/** Guests are one subject per IPv6 /64, as the upload server counts them. */
	public function testAddressesOfOneIpv6NetworkShareOneGuestAllowance(): void
	{
		$group = ['transfer_quota_bytes' => 1000, 'transfer_quota_period' => 'day'];
		$one = TransferQuotaRepository::reserve(null, '2001:db8:1:2::5', $group, 800);
		$this->assertIsArray($one);
		$this->assertSame('2001:db8:1:2::/64', $one['subject_key']);
		$this->assertNull(TransferQuotaRepository::reserve(null, '2001:db8:1:2:aaaa::6', $group, 300));
		$this->assertIsArray(TransferQuotaRepository::reserve(null, '2001:db8:1:3::5', $group, 300), 'another /64 has its own');
		TransferQuotaRepository::commit($one, 800);
		$this->assertTrue(TransferQuotaRepository::wouldExceed(null, '2001:db8:1:2::ffff', $group, 201));
	}
}
