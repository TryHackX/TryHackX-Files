<?php

/**
 * CollectionRepository: the searchable/filterable collection browser behind the admin
 * Files tab (pt 4), and the bulk delete the "empty collections" filter feeds.
 */
final class CollectionRepositoryTest extends RepoTestCase
{
	protected function setUp(): void
	{
		$this->truncate('collections', 'collection_files', 'files');
	}

	/** A collection with two member files, plus one that never got any. */
	private function seed(): void
	{
		$this->insertFile('cr_a', null, ['size' => 1024 * 1024, 'original_name' => 'a.bin']);
		$this->insertFile('cr_b', null, ['size' => 2 * 1024 * 1024, 'original_name' => 'b.bin']);

		Database::createCollection('full1', 'Holiday photos', null, password_hash('tok', PASSWORD_DEFAULT));
		Database::addFilesToCollection('full1', ['cr_a', 'cr_b'], null);

		Database::createCollection('empty1', 'Leftovers', null, password_hash('tok', PASSWORD_DEFAULT));
	}

	public function testBrowseReturnsCountsAndSizes(): void
	{
		$this->seed();
		$res = Database::browseCollections(['per_page' => 20]);

		$this->assertSame(2, $res['total']);
		$byId = array_column($res['collections'], null, 'id');
		$this->assertSame(2, (int) $byId['full1']['file_count']);
		$this->assertSame(3 * 1024 * 1024, (int) $byId['full1']['total_size']);
		$this->assertSame(0, (int) $byId['empty1']['file_count']);
	}

	public function testEmptyFilterFindsOnlyCollectionsWithoutFiles(): void
	{
		$this->seed();
		$res = Database::browseCollections(['filters' => ['empty' => 1]]);

		$this->assertSame(1, $res['total']);
		$this->assertSame('empty1', $res['collections'][0]['id']);
	}

	/**
	 * The case the filter is really for: the collection still exists, but every file it
	 * pointed at is gone. It has to read as empty, not as "2 files" from stale link rows.
	 */
	public function testCollectionWhoseFilesWereDeletedCountsAsEmpty(): void
	{
		$this->seed();
		$pdo = Database::getInstance();
		$pdo->exec('DELETE FROM `' . Database::table('files') . '`');

		$res = Database::browseCollections(['filters' => ['empty' => 1]]);
		$this->assertSame(2, $res['total']);
	}

	public function testFileCountAndSizeRanges(): void
	{
		$this->seed();

		$this->assertSame(1, Database::browseCollections(['filters' => ['files_min' => 1]])['total']);
		$this->assertSame(2, Database::browseCollections(['filters' => ['files_max' => 5]])['total']);
		// 3 MiB of members — inside a 2..4 MiB window, outside a 4 MiB floor.
		$this->assertSame(1, Database::browseCollections(['filters' => ['size_min' => 2 * 1024 * 1024]])['total']);
		$this->assertSame(0, Database::browseCollections(['filters' => ['size_min' => 4 * 1024 * 1024]])['total']);
	}

	public function testSearchMatchesNameAndId(): void
	{
		$this->seed();
		$this->assertSame(1, Database::browseCollections(['search' => 'Holiday'])['total']);
		$this->assertSame(1, Database::browseCollections(['search' => 'empty1'])['total']);
		$this->assertSame(0, Database::browseCollections(['search' => 'nothing-here'])['total']);
	}

	public function testPaginationSplitsTheResult(): void
	{
		$this->seed();
		$first = Database::browseCollections(['per_page' => 1, 'page' => 1]);
		$second = Database::browseCollections(['per_page' => 1, 'page' => 2]);

		$this->assertSame(2, $first['total']);       // total counts the whole set, not the page
		$this->assertCount(1, $first['collections']);
		$this->assertCount(1, $second['collections']);
		$this->assertNotSame($first['collections'][0]['id'], $second['collections'][0]['id']);
	}

	public function testDeleteManyRemovesCollectionsButKeepsFiles(): void
	{
		$this->seed();
		$this->assertSame(2, Database::deleteCollections(['full1', 'empty1'], null));

		$this->assertSame(0, Database::browseCollections([])['total']);

		// The members were the users' own uploads and outlive any bundle they sat in.
		$pdo = Database::getInstance();
		$this->assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM `' . Database::table('files') . '`')->fetchColumn());
		$this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM `' . Database::table('collection_files') . '`')->fetchColumn());
	}
}
