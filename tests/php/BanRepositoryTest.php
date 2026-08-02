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

	public function testAddIsUpsert(): void
	{
		BanRepository::add('ip', '5.5.5.5', null, 'first');
		BanRepository::add('ip', '5.5.5.5', null, 'second'); // ON DUPLICATE KEY UPDATE
		$res = BanRepository::list(1, 20);
		$this->assertSame(1, $res['total']);
		$this->assertSame('second', BanRepository::details('ip', '5.5.5.5')['reason']);
	}
}
