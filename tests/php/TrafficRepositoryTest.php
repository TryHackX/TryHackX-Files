<?php

/**
 * TrafficRepository: transfer accounting feeding the admin dashboard.
 */
final class TrafficRepositoryTest extends RepoTestCase
{
	protected function setUp(): void
	{
		$this->truncate('traffic_logs', 'traffic_daily', 'files');
	}

	public function testStatsSumOverWindow(): void
	{
		Database::logTraffic('10.0.0.1', 1000, 'download', null, null);
		Database::logTraffic('10.0.0.1', 500, 'upload', null, null);
		$this->assertSame(1500, Database::getTrafficStats('day'));
	}

	public function testTopIpsAndSuspicious(): void
	{
		Database::logTraffic('10.0.0.1', 5000, 'download', null, null);
		Database::logTraffic('10.0.0.2', 100, 'download', null, null);

		$top = Database::getTopTrafficIPs(24, 10);
		$this->assertSame('10.0.0.1', $top[0]['ip_address']); // biggest first
		$this->assertSame(5000, (int) $top[0]['total_traffic']);

		$suspicious = Database::getSuspiciousIPs(1000, 24); // only IPs over 1000 bytes
		$this->assertCount(1, $suspicious);
		$this->assertSame('10.0.0.1', $suspicious[0]['ip_address']);
	}

	public function testSeriesHasOneEntryPerDay(): void
	{
		Database::logTraffic('10.0.0.1', 200, 'download', null, null);
		$series = Database::getTrafficSeries(7);
		$this->assertCount(7, $series);
		$today = end($series);
		$this->assertSame(200, (int) $today['download']);
	}

	public function testTopFilesByDownloads(): void
	{
		$this->insertFile('t_low', null, ['downloads' => 1]);
		$this->insertFile('t_high', null, ['downloads' => 50]);
		$top = Database::getTopFiles(5);
		$this->assertSame('t_high', $top[0]['id']);
	}

	public function testOldDetailsAreAggregatedBeforeTheyArePruned(): void
	{
		$pdo = Database::getInstance();
		$logs = Database::table('traffic_logs');
		$createdAt = strtotime('-40 days 12:00');
		$stmt = $pdo->prepare(
			"INSERT INTO `{$logs}`
			 (`ip_address`, `transfer_size`, `transfer_type`, `created_at`)
			 VALUES (?, ?, ?, ?), (?, ?, ?, ?)"
		);
		$stmt->execute([
			'10.0.0.7', 1200, 'download', $createdAt,
			'10.0.0.8', 300, 'upload', $createdAt,
		]);

		$result = TrafficRepository::aggregateAndPrune(30, 730);

		$this->assertSame(2, $result['deleted']);
		$this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM `{$logs}`")->fetchColumn());
		$this->assertSame(1500, Database::getTrafficStats('year'));

		$series = TrafficRepository::seriesRange(
			strtotime('-41 days'),
			strtotime('-39 days'),
			'day'
		);
		$day = date('Y-m-d', $createdAt);
		$bucket = array_values(array_filter($series, static fn(array $row): bool => $row['date'] === $day));
		$this->assertCount(1, $bucket);
		$this->assertSame(1200, (int) $bucket[0]['download']);
		$this->assertSame(300, (int) $bucket[0]['upload']);
	}
}
