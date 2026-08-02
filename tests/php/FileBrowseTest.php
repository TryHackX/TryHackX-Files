<?php

/**
 * FileManager::browse() — the single listing path behind the all-files browser.
 *
 * Covers the advanced filter set (pt 9) and the owner join (pt 10). Authorisation is *not*
 * exercised here: browse() trusts its caller, and FileController is what strips filters the
 * session may not use (see PermissionsTest for those rules).
 */
final class FileBrowseTest extends RepoTestCase
{
	private int $aliceId;
	private int $bobId;

	protected function setUp(): void
	{
		$this->truncate('files', 'users', 'traffic_logs');
		$pdo = Database::getInstance();

		$mk = function (string $name) use ($pdo): int {
			$pdo->prepare("INSERT INTO `" . Database::table('users') . "` (username,email,password_hash,role,is_active,created_at) VALUES (?,?,?,?,1,?)")
				->execute([$name, $name . '@u.pl', 'x', 'user', time()]);
			return (int) $pdo->lastInsertId();
		};
		$this->aliceId = $mk('alice');
		$this->bobId = $mk('bob');

		$day = 86400;
		$now = time();

		$this->insertFile('fsmall', $this->aliceId, [
			'original_name' => 'notes.txt', 'mime_type' => 'text/plain',
			'size' => 1024, 'downloads' => 1, 'uploaded_at' => $now - (2 * $day), 'uploaded_ip' => '10.0.0.1',
		]);
		$this->insertFile('fbig', $this->aliceId, [
			'original_name' => 'movie.mp4', 'mime_type' => 'video/mp4',
			'size' => 50 * 1024 * 1024, 'downloads' => 42, 'uploaded_at' => $now - (10 * $day), 'uploaded_ip' => '10.0.0.1',
		]);
		$this->insertFile('fbob', $this->bobId, [
			'original_name' => 'photo.jpg', 'mime_type' => 'image/jpeg',
			'size' => 2 * 1024 * 1024, 'downloads' => 5, 'uploaded_at' => $now - $day, 'uploaded_ip' => '10.0.0.2',
		]);
		// A guest upload — no owner account behind it.
		$this->insertFile('fguest', null, [
			'original_name' => 'anon.pdf', 'mime_type' => 'application/pdf',
			'size' => 4096, 'downloads' => 0, 'uploaded_at' => $now - (30 * $day), 'uploaded_ip' => '10.0.0.3',
		]);
	}

	/** Ids returned for a given filter set, for terse assertions. */
	private function ids(array $filters, array $opts = []): array
	{
		$res = FileManager::browse(array_merge(['per_page' => 100, 'filters' => $filters], $opts));
		return array_map(fn($f) => $f['id'], $res['files']);
	}

	public function testUnfilteredListsEverything(): void
	{
		$res = FileManager::browse(['per_page' => 100]);
		$this->assertSame(4, $res['total']);
	}

	public function testOwnerIsJoinedAndGuestsHaveNone(): void
	{
		$res = FileManager::browse(['per_page' => 100]);
		$byId = [];
		foreach ($res['files'] as $f) {
			$byId[$f['id']] = $f;
		}
		$this->assertSame('alice', $byId['fsmall']['owner']);
		$this->assertSame($this->aliceId, $byId['fsmall']['userId']);
		$this->assertNull($byId['fguest']['owner']);
		$this->assertNull($byId['fguest']['userId']);
	}

	public function testSizeRangeFilter(): void
	{
		$this->assertSame(['fbig'], $this->ids(['size_min' => 10 * 1024 * 1024]));
		$this->assertEqualsCanonicalizing(
			['fsmall', 'fguest'],
			$this->ids(['size_max' => 1024 * 1024])
		);
	}

	public function testDownloadRangeFilter(): void
	{
		$this->assertSame(['fbig'], $this->ids(['dl_min' => 10]));
		$this->assertEqualsCanonicalizing(['fsmall', 'fguest'], $this->ids(['dl_max' => 1]));
	}

	public function testDateRangeFilter(): void
	{
		$cutoff = time() - (3 * 86400);
		$this->assertEqualsCanonicalizing(['fsmall', 'fbob'], $this->ids(['date_from' => $cutoff]));
		$this->assertEqualsCanonicalizing(['fbig', 'fguest'], $this->ids(['date_to' => $cutoff]));
	}

	public function testOwnerFilterIncludingGuests(): void
	{
		$this->assertEqualsCanonicalizing(['fsmall', 'fbig'], $this->ids(['users' => [$this->aliceId]]));
		// 0 stands for "uploaded by a guest".
		$this->assertSame(['fguest'], $this->ids(['users' => [0]]));
		$this->assertEqualsCanonicalizing(
			['fbob', 'fguest'],
			$this->ids(['users' => [$this->bobId, 0]])
		);
	}

	public function testIpFilter(): void
	{
		$this->assertEqualsCanonicalizing(['fsmall', 'fbig'], $this->ids(['ips' => ['10.0.0.1']]));
		$this->assertSame([], $this->ids(['ips' => ['192.168.1.1']]));
	}

	public function testExtensionAndMimeFilters(): void
	{
		$this->assertSame(['fguest'], $this->ids(['extensions' => ['pdf']]));
		$this->assertEqualsCanonicalizing(['fsmall', 'fguest'], $this->ids(['extensions' => ['txt', 'pdf']]));
		$this->assertSame(['fbob'], $this->ids(['mime' => 'image/']));
	}

	public function testSearchMatchesNameAndId(): void
	{
		$this->assertSame(['fbob'], $this->ids([], ['search' => 'photo']));
		$this->assertSame(['fbig'], $this->ids([], ['search' => 'fbig']));
	}

	/**
	 * The owner and the IP are other people's data, so the term only reaches them when the
	 * caller says the session may see them. Without the flags, a group denied the owner column
	 * could still confirm who uploaded what by searching for a name and watching rows appear.
	 */
	public function testSearchReachesOwnerAndIpOnlyWhenAllowed(): void
	{
		$this->assertSame([], $this->ids([], ['search' => 'alice']));
		$this->assertEqualsCanonicalizing(
			['fsmall', 'fbig'],
			$this->ids([], ['search' => 'alice', 'search_owner' => true])
		);

		$this->assertSame([], $this->ids([], ['search' => '10.0.0.2']));
		$this->assertSame(['fbob'], $this->ids([], ['search' => '10.0.0.2', 'search_ip' => true]));
	}

	public function testFiltersCombineWithAnd(): void
	{
		// alice's files that are also over 10 MiB — only the video qualifies.
		$this->assertSame(
			['fbig'],
			$this->ids(['users' => [$this->aliceId], 'size_min' => 10 * 1024 * 1024])
		);
	}

	public function testSortByOwner(): void
	{
		$res = FileManager::browse(['per_page' => 100, 'sort' => 'owner', 'order' => 'ASC']);
		$owners = array_values(array_filter(array_map(fn($f) => $f['owner'], $res['files'])));
		$sorted = $owners;
		sort($sorted);
		$this->assertSame($sorted, $owners);
	}

	public function testSortByUploadIp(): void
	{
		$res = FileManager::browse(['per_page' => 100, 'sort' => 'uploaded_ip', 'order' => 'ASC']);
		$ips = array_map(static fn(array $file): string => (string) $file['uploadedIP'], $res['files']);
		$sorted = $ips;
		sort($sorted, SORT_STRING);
		$this->assertSame($sorted, $ips);
	}

	public function testMultiColumnSortUsesEveryPriorityInOrder(): void
	{
		$pdo = Database::getInstance();
		$table = Database::table('files');
		$pdo->exec("UPDATE `{$table}` SET `downloads` = 7 WHERE `id` IN ('fsmall', 'fbob')");
		$res = FileManager::browse([
			'per_page' => 100,
			'sorts' => ['downloads' => 'DESC', 'size' => 'ASC'],
		]);
		$ids = array_map(static fn(array $file): string => $file['id'], $res['files']);
		$this->assertLessThan(array_search('fbob', $ids, true), array_search('fsmall', $ids, true));
		$this->assertSame('fbig', $ids[0]);
	}

	public function testUnknownSortFallsBackInsteadOfReachingSql(): void
	{
		// A sort key that isn't whitelisted must not blow up or be interpolated.
		$res = FileManager::browse(['per_page' => 100, 'sort' => 'size`; DROP TABLE x; --']);
		$this->assertSame(4, $res['total']);
	}

	public function testSharingStateFilters(): void
	{
		$pdo = Database::getInstance();
		$t = Database::table('files');
		$pdo->prepare("UPDATE `$t` SET `password_hash` = ? WHERE `id` = 'fsmall'")->execute(['hash']);
		$pdo->prepare("UPDATE `$t` SET `one_time` = 1 WHERE `id` = 'fbob'")->execute();
		$pdo->prepare("UPDATE `$t` SET `expires_at` = ? WHERE `id` = 'fbig'")->execute([time() - 100]);

		$this->assertSame(['fsmall'], $this->ids(['sharing' => ['password']]));
		$this->assertSame(['fbob'], $this->ids(['sharing' => ['onetime']]));
		$this->assertSame(['fbig'], $this->ids(['sharing' => ['expired']]));
		$this->assertSame(['fguest'], $this->ids(['sharing' => ['public']]));
		// Several states are OR-ed together within the sharing filter.
		$this->assertEqualsCanonicalizing(['fsmall', 'fbob'], $this->ids(['sharing' => ['password', 'onetime']]));
	}

	public function testInactiveFilterCountsNeverDownloadedFromUpload(): void
	{
		// A recent download keeps a file out of the "inactive for 5 days" set.
		Database::logTraffic('10.0.0.1', 100, 'download', 'fbig', $this->aliceId);

		$inactive = $this->ids(['inactive_days' => 5]);
		$this->assertNotContains('fbig', $inactive, 'a just-downloaded file is active');
		$this->assertContains('fguest', $inactive, 'never downloaded, uploaded 30 days ago');
		$this->assertNotContains('fbob', $inactive, 'uploaded yesterday');
	}

	public function testPagingReportsFullTotal(): void
	{
		$res = FileManager::browse(['per_page' => 5, 'page' => 1]);
		$this->assertSame(4, $res['total']);
		$this->assertCount(4, $res['files']);

		$page1 = FileManager::browse(['per_page' => 2, 'page' => 1]);
		$page2 = FileManager::browse(['per_page' => 2, 'page' => 2]);
		$this->assertSame(4, $page1['total']);
		$this->assertCount(2, $page1['files']);
		$this->assertCount(2, $page2['files']);
		$this->assertEmpty(array_intersect(
			array_map(fn($f) => $f['id'], $page1['files']),
			array_map(fn($f) => $f['id'], $page2['files'])
		));
	}

	public function testDeadFilterFindsRowsWithNoBytesOnDisk(): void
	{
		// None of these fixtures have an upload directory, so every row is "dead".
		$res = FileManager::browse(['per_page' => 100, 'filters' => ['dead' => 1]]);
		$this->assertSame(4, $res['total']);
	}

	public function testFacetsReportRealValues(): void
	{
		$facets = FileManager::browseFacets();

		$owners = [];
		foreach ($facets['users'] as $u) {
			$owners[(int) $u['id']] = (int) $u['count'];
		}
		$this->assertSame(2, $owners[$this->aliceId]);
		$this->assertSame(1, $owners[$this->bobId]);
		$this->assertSame(1, $owners[0], 'guest uploads are offered as a filter choice');

		$ips = array_column($facets['ips'], 'count', 'ip');
		$this->assertSame(2, $ips['10.0.0.1']);

		$exts = array_column($facets['extensions'], 'count', 'ext');
		$this->assertSame(1, $exts['pdf']);
		$this->assertSame(1, $exts['mp4']);
	}
}
