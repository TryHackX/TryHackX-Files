<?php

/**
 * BanRepository: IP/username/email blacklist with optional expiry and lazy cleanup.
 */
final class BanRepositoryTest extends RepoTestCase
{
	protected function setUp(): void
	{
		$this->truncate('blacklists');
	}

	public function testAddAndDetect(): void
	{
		$this->assertTrue(BanRepository::add('ip', '10.0.0.9', null, 'spam'));
		$this->assertTrue(BanRepository::isBanned('ip', '10.0.0.9'));

		$row = BanRepository::details('ip', '10.0.0.9');
		$this->assertIsArray($row);
		$this->assertSame('spam', $row['reason']);
		$this->assertNull($row['expires_at']);
	}

	public function testUnknownValueIsNotBanned(): void
	{
		$this->assertFalse(BanRepository::isBanned('ip', '10.0.0.99'));
		$this->assertNull(BanRepository::details('ip', '10.0.0.99'));
	}

	public function testExpiredBanIsAutoCleanedOnRead(): void
	{
		BanRepository::add('email', 'x@y.z', time() - 60, 'old'); // already expired
		$this->assertFalse(BanRepository::isBanned('email', 'x@y.z'));

		// details() should have deleted the stale row.
		$pdo = Database::getInstance();
		$n = (int) $pdo->query("SELECT COUNT(*) FROM `" . Database::table('blacklists') . "`")->fetchColumn();
		$this->assertSame(0, $n);
	}

	public function testRemove(): void
	{
		BanRepository::add('username', 'bob', null, '');
		$row = BanRepository::details('username', 'bob');
		$this->assertTrue(BanRepository::remove((int) $row['id']));
		$this->assertFalse(BanRepository::isBanned('username', 'bob'));
	}

	public function testListPurgesExpiredAndPaginates(): void
	{
		BanRepository::add('ip', '1.1.1.1', null, 'a');
		BanRepository::add('ip', '2.2.2.2', null, 'b');
		BanRepository::add('ip', '3.3.3.3', time() - 10, 'expired'); // purged by list()

		$res = BanRepository::list(1, 20);
		$this->assertSame(2, $res['total']);
		$this->assertCount(2, $res['bans']);
	}

	/**
	 * An IPv6 client picks any address of its /64, so an address ban is stored and matched
	 * per network; IPv4 (also written as IPv4-mapped IPv6) stays per address.
	 */
	public function testIpv6BansCoverTheWholeSlash64(): void
	{
		$this->assertTrue(BanRepository::add('ip', '2001:db8:1:2::5', null, 'spam'));
		$row = BanRepository::details('ip', '2001:db8:1:2:aaaa:bbbb:cccc:dddd');
		$this->assertIsArray($row);
		$this->assertSame('2001:db8:1:2::/64', $row['value']);
		$this->assertTrue(BanRepository::isBanned('ip', '2001:db8:1:2::6'));
		$this->assertFalse(BanRepository::isBanned('ip', '2001:db8:1:3::5'), 'the neighbouring /64');

		// The network form may be typed directly, and is stored the same way.
		BanRepository::add('ip', '2001:DB8:7:8:0:0:0:1/64', null, '');
		$this->assertTrue(BanRepository::isBanned('ip', '2001:db8:7:8::ffff'));

		BanRepository::add('ip', '192.0.2.44', null, '');
		$this->assertTrue(BanRepository::isBanned('ip', '::ffff:192.0.2.44'), 'IPv4-mapped is the IPv4 address');
		$this->assertFalse(BanRepository::isBanned('ip', '192.0.2.45'));
	}

	/** Address bans written before they were stored per network keep matching exactly. */
	public function testExactIpv6RowsFromBeforeStillMatch(): void
	{
		Database::getInstance()->prepare(
			"INSERT INTO `" . Database::table('blacklists') . "` (`type`, `value`, `created_at`, `expires_at`, `reason`)
			 VALUES ('ip', '2001:db8:9:9::7', ?, NULL, 'legacy')"
		)->execute([time()]);
		$this->assertTrue(BanRepository::isBanned('ip', '2001:db8:9:9::7'));
		$this->assertFalse(BanRepository::isBanned('ip', '2001:db8:9:9::8'));
	}

	public function testAddIsUpsert(): void
	{
		BanRepository::add('ip', '5.5.5.5', null, 'first');
		BanRepository::add('ip', '5.5.5.5', null, 'second'); // ON DUPLICATE KEY UPDATE
		$res = BanRepository::list(1, 20);
		$this->assertSame(1, $res['total']);
		$this->assertSame('second', BanRepository::details('ip', '5.5.5.5')['reason']);
	}
}
