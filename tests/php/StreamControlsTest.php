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
		Database::setSetting('php_small_stream_max_per_ip', '6');
		Database::setSetting('php_small_stream_max_global', '8');
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

	/**
	 * Small answers (an image, a text file) hold a worker too: they take a slot in their own,
	 * larger pool, counted per network so the addresses of one IPv6 /64 share one allowance.
	 */
	public function testSmallAnswersTakeSlotsOfTheirOwnPoolPerNetwork(): void
	{
		Database::setSetting('php_small_stream_max_per_ip', '1');
		Database::setSetting('php_small_stream_max_global', '2');
		Database::invalidateSettingsCache();

		[$held, $slot] = $this->asWorker(fn() => FileManager::acquireStreamSlot('2001:db8:5:6::1', true));
		$this->assertTrue($slot['ok']);
		[, $sameNetwork] = $this->asWorker(fn() => FileManager::acquireStreamSlot('2001:db8:5:6:ffff::2', true));
		$this->assertFalse($sameNetwork['ok'], 'another address of the same /64');
		$this->assertSame(429, $sameNetwork['status']);

		[$large, $largeSlot] = $this->asWorker(fn() => FileManager::acquireStreamSlot('2001:db8:5:6::1'));
		$this->assertTrue($largeSlot['ok'], 'long streams have a pool of their own');

		[$other, $otherNetwork] = $this->asWorker(fn() => FileManager::acquireStreamSlot('2001:db8:5:7::1', true));
		$this->assertTrue($otherNetwork['ok']);
		[, $full] = $this->asWorker(fn() => FileManager::acquireStreamSlot('198.51.100.40', true));
		$this->assertFalse($full['ok'], 'site-wide cap of the small pool');
		$this->assertNotNull($held);
		$this->assertNotNull($large);
		$this->assertNotNull($other);
	}

	/**
	 * C-F9: a guest's concurrent downloads were counted per exact address, so an IPv6 client
	 * had a fresh allowance on every address of its /64. They are counted as the upload server
	 * counts them: IPv4 per address, IPv6 per /64 whatever the spelling, the stored rows
	 * keeping the exact address.
	 */
	public function testConcurrentDownloadsAreCountedPerIpv6Network(): void
	{
		Database::addActiveDownload('2001:db8:1:2::10', 'f1');
		Database::addActiveDownload('2001:0DB8:0001:0002:ffff:0:0:1', 'f2');
		Database::addActiveDownload('2001:db8:1:3::10', 'f3');
		Database::addActiveDownload('198.51.100.7', 'f4');
		Database::addActiveDownload('198.51.100.8', 'f5');
		Database::addActiveDownload('unknown', 'f6');

		$this->assertSame(2, Database::getConcurrentDownloads('2001:db8:1:2::99'));
		$this->assertSame(1, Database::getConcurrentDownloads('2001:db8:1:3:abcd::1'));
		$this->assertSame(0, Database::getConcurrentDownloads('2001:db8:1:4::1'));
		$this->assertSame(1, Database::getConcurrentDownloads('198.51.100.7'));
		$this->assertSame(1, Database::getConcurrentDownloads('::ffff:198.51.100.8'), 'an IPv4-mapped address is the IPv4 one');
		$this->assertSame(1, Database::getConcurrentDownloads('unknown'));
		$this->assertSame('2001:db8:1:2::10', (string) Database::getInstance()->query(
			"SELECT `ip_address` FROM `" . Database::table('active_downloads') . "` WHERE `file_id` = 'f1'"
		)->fetchColumn(), 'the live list keeps the exact address');
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

	/** The transfer allowance is asked before every chunk; a refusal ends the stream there. */
	public function testPumpStopsWhenTheAllowanceRefusesTheNextChunk(): void
	{
		$handle = fopen('php://memory', 'w+b');
		fwrite($handle, str_repeat('y', 200000));
		rewind($handle);
		$asked = [];
		ob_start();
		$sent = FileManager::pumpBytes(
			$handle,
			200000,
			0,
			null,
			microtime(true) + 30,
			static function (int $sent, int $next) use (&$asked): bool {
				$asked[] = [$sent, $next];
				return $sent + $next <= 131072;
			}
		);
		$body = (string) ob_get_clean();
		fclose($handle);
		$this->assertSame(131072, $sent);
		$this->assertSame(131072, strlen($body));
		$this->assertSame([[0, 65536], [65536, 65536], [131072, 65536]], $asked);
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
		// Only the upload server keeps a one-time claim or a cap exact: no embeds of either.
		$this->assertSame(403, FileManager::authorizeFileRead('once1', 'embed')['status']);
		$this->assertSame(403, FileManager::authorizeFileRead('capped1', 'embed')['status']);
		$this->assertTrue(FileManager::authorizeFileRead('plain1', 'embed')['allowed']);
		$this->assertNull(Database::getFileSharingState('once1')['consumed_at'], 'a refused embed burns nothing');

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
