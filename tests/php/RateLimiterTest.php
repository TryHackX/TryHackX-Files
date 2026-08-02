<?php

require_once PROJECT_ROOT . '/src/includes/RateLimiter.php';

final class RateLimiterTest extends RepoTestCase
{
	protected function setUp(): void
	{
		$this->truncate('rate_limits');
	}

	public function testAtomicHitReturnsTheStoredCounterWithoutAReadRace(): void
	{
		$first = RateLimiter::hit('test:atomic', 'auth');
		$second = RateLimiter::hit('test:atomic', 'auth');

		$this->assertTrue($first['allowed']);
		$this->assertSame(10, $first['limit']);
		$this->assertSame(9, $first['remaining']);
		$this->assertSame(8, $second['remaining']);
		$this->assertSame($first['reset'], $second['reset']);

		$table = Database::table('rate_limits');
		$this->assertSame(
			2,
			(int) Database::getInstance()
				->query("SELECT `hits` FROM `{$table}` WHERE `bucket` = 'test:atomic|auth'")
				->fetchColumn()
		);
	}

	public function testAlignedWindowRollsAnOldRowBackToOne(): void
	{
		RateLimiter::hit('test:roll', 'auth');
		$table = Database::table('rate_limits');
		Database::getInstance()
			->exec("UPDATE `{$table}` SET `window_start` = `window_start` - 600, `hits` = 10");

		$result = RateLimiter::hit('test:roll', 'auth');

		$this->assertTrue($result['allowed']);
		$this->assertSame(9, $result['remaining']);
		$this->assertGreaterThan(time(), $result['reset']);
	}
}
