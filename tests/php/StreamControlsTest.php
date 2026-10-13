<?php

/**
 * Bytes served by PHP itself (previews, embeds) hold a worker of a shared pool: they take a
 * slot under a site-wide and a per-address cap, follow the group's speed limit, stop at a
 * deadline or when an administrator kills them — and a preview, which never counts as a
 * download, is withheld wherever counting is the point.
 */
final class StreamControlsTest extends RepoTestCase
{
	protected function setUp(): void
	{
		$this->truncate('active_downloads', 'files', 'transfer_quota_usage', 'security_events');
		Database::setSetting('php_stream_max_per_ip', '1');
		Database::setSetting('php_stream_max_global', '2');
		Database::setSetting('recaptcha_enabled', '0');
		Database::invalidateSettingsCache();
		$_SESSION = [];
	}

	protected function tearDown(): void
	{
		$this->truncate('active_downloads', 'files', 'transfer_quota_usage');
		Database::setSetting('php_stream_max_per_ip', '2');
		Database::setSetting('php_stream_max_global', '4');
		$guest = GroupRepository::getBySlug('guest');
		if ($guest) {
			Database::getInstance()->prepare(
				"UPDATE `" . Database::table('groups') . "` SET `transfer_quota_bytes` = 0 WHERE `id` = ?"
			)->execute([(int) $guest['id']]);
		}
		Database::invalidateSettingsCache();
		$_SESSION = [];
	}

	/**
	 * Run $work as another PHP worker would: on its own database connection, which keeps the
	 * slots it took for as long as the returned connection is alive.
	 */
	private function asWorker(callable $work): array
	{
		Database::resetInstance();
		$connection = Database::getInstance();
		$result = $work();
		Database::resetInstance();
		return [$connection, $result];
	}

	public function testSlotsAreCappedPerAddressAndSiteWide(): void
	{
		[$first, $slot] = $this->asWorker(fn() => FileManager::acquireStreamSlot('198.51.100.1'));
		$this->assertTrue($slot['ok']);
		[, $second] = $this->asWorker(fn() => FileManager::acquireStreamSlot('198.51.100.1'));
		$this->assertFalse($second['ok'], 'second stream from one address');
		$this->assertSame(429, $second['status']);

		[$other, $slot] = $this->asWorker(fn() => FileManager::acquireStreamSlot('198.51.100.2'));
		$this->assertTrue($slot['ok']);
		[, $third] = $this->asWorker(fn() => FileManager::acquireStreamSlot('198.51.100.3'));
		$this->assertFalse($third['ok'], 'site-wide cap');

		// A worker that ends (its connection closes) gives its slots back by itself.
		$first = null;
		[, $after] = $this->asWorker(fn() => FileManager::acquireStreamSlot('198.51.100.3'));
		$this->assertTrue($after['ok']);
		$this->assertNotNull($other);
		$this->assertSame(0, (int) Database::getInstance()->query(
			"SELECT COUNT(*) FROM `" . Database::table('active_downloads') . "`"
		)->fetchColumn(), 'a preview slot is not a row the upload server would count as a download');
	}

	public function testKillIsReportedOnlyWhenSomethingWasStoppedAndTheStreamNotices(): void
	{
		$id = (int) Database::addActiveDownload('198.51.100.4', 'f1');
		$this->assertTrue(ActiveDownloadRepository::heartbeat($id));
		$this->assertTrue(Database::killActiveDownload($id));
		$this->assertFalse(Database::killActiveDownload($id));
		$this->assertFalse(ActiveDownloadRepository::heartbeat($id));
	}

	public function testPumpFollowsTheSpeedLimitAndTheDeadline(): void
	{
		$handle = fopen('php://memory', 'w+b');
		fwrite($handle, str_repeat('x', 300000));
		rewind($handle);
		$started = microtime(true);
		ob_start();
		$sent = FileManager::pumpBytes($handle, 300000, 200000, null, microtime(true) + 30);
		$body = (string) ob_get_clean();
		$elapsed = microtime(true) - $started;
		$this->assertSame(300000, $sent);
		$this->assertSame(300000, strlen($body));
		$this->assertGreaterThan(1.2, $elapsed, '300 kB at 200 kB/s must take about 1.5 s');

		rewind($handle);
		ob_start();
		$this->assertSame(0, FileManager::pumpBytes($handle, 300000, 0, null, microtime(true) - 1));
		ob_end_clean();
		fclose($handle);
	}

	public function testPreviewIsWithheldWhereCountingMatters(): void
	{
		$this->insertFile('plain1', null, ['size' => 10]);
		$this->insertFile('once1', null, ['size' => 10, 'one_time' => 1]);
		$this->insertFile('capped1', null, ['size' => 10, 'max_downloads' => 3]);
		$file = static fn(string $id): array => ['id' => $id, 'size' => 10];

		$this->assertSame(['allowed' => true, 'reason' => ''], FileManager::previewPolicy($file('plain1'), null, '198.51.100.5'));
		$this->assertSame('one_time', FileManager::previewPolicy($file('once1'), null, '198.51.100.5')['reason']);
		$this->assertSame('download_cap', FileManager::previewPolicy($file('capped1'), null, '198.51.100.5')['reason']);
		$this->assertSame(403, FileManager::authorizeFileRead('once1', 'preview')['status']);

		// A guest whose transfer allowance cannot cover the file.
		$guest = GroupRepository::getBySlug('guest');
		$this->assertNotNull($guest);
		Database::getInstance()->prepare(
			"UPDATE `" . Database::table('groups') . "` SET `transfer_quota_bytes` = 100, `transfer_quota_period` = 'week' WHERE `id` = ?"
		)->execute([(int) $guest['id']]);
		$window = TransferQuotaRepository::periodWindow('week');
		Database::getInstance()->prepare(
			"INSERT INTO `" . Database::table('transfer_quota_usage') . "`
			 (`subject_type`, `subject_key`, `period`, `period_start`, `used_bytes`, `reserved_bytes`, `updated_at`)
			 VALUES ('ip', ?, 'week', ?, 95, 0, ?)"
		)->execute(['198.51.100.5', $window['start'], time()]);
		$this->assertSame('transfer_quota', FileManager::previewPolicy($file('plain1'), null, '198.51.100.5')['reason']);
		$this->assertTrue(FileManager::previewPolicy($file('plain1'), null, '198.51.100.6')['allowed']);
	}
}
