<?php

/**
 * Filing a file into an existing collection (pt 5) and the permissions around it (pt 4).
 *
 * The interesting cases are all about what must *not* happen: no duplicate members, no
 * collecting a file into a collection that is not yours, and no permission surviving without
 * the one it depends on.
 */
final class CollectionAddTest extends RepoTestCase
{
    private int $userId = 0;
    private int $otherId = 0;
    private string $collectionId = '';

    protected function setUp(): void
    {
        $this->truncate('files', 'collections', 'collection_files');

        $this->userId = $this->makeUser();
        $this->otherId = $this->makeUser();

        $this->collectionId = 'c' . bin2hex(random_bytes(6));
        Database::createCollection($this->collectionId, 'Test', $this->userId, password_hash('t', PASSWORD_DEFAULT));
    }

    private function makeUser(): int
    {
        $name = 'colluser' . bin2hex(random_bytes(4));
        Database::registerUser($name, $name . '@example.com', 'Str0ng!pass');
        return (int) Database::loginUser($name, 'Str0ng!pass')['user']['id'];
    }

    /* ---------------- membership ---------------- */

    public function testAddingTheSameFileTwiceLeavesOneMember(): void
    {
        $this->insertFile('addf1', $this->userId);

        $this->assertSame(1, Database::addFilesToCollection($this->collectionId, ['addf1'], $this->userId));
        $this->assertSame(0, Database::addFilesToCollection($this->collectionId, ['addf1'], $this->userId),
            'a second add reports nothing added');
        $this->assertSame(1, $this->memberCount(), 'and really does not add a second row');
    }

    public function testOwnerScopeRefusesSomeoneElsesFile(): void
    {
        $this->insertFile('addf2', $this->otherId);

        $this->assertSame(0, Database::addFilesToCollection($this->collectionId, ['addf2'], $this->userId),
            'scoped to the owner, so another account\'s file is not collected');
        $this->assertSame(0, $this->memberCount());

        // ownerId null is the "may collect what I do not own" path, which `files.coll_add` gates.
        $this->assertSame(1, Database::addFilesToCollection($this->collectionId, ['addf2'], null));
        $this->assertSame(1, $this->memberCount());
    }

    public function testPositionsKeepAppending(): void
    {
        $this->insertFile('addf3', $this->userId);
        $this->insertFile('addf4', $this->userId);

        Database::addFilesToCollection($this->collectionId, ['addf3'], $this->userId);
        Database::addFilesToCollection($this->collectionId, ['addf4'], $this->userId);

        $stmt = Database::getInstance()->prepare(
            'SELECT `file_id`, `position` FROM `' . Database::table('collection_files') . '`
             WHERE `collection_id` = ? ORDER BY `position` ASC'
        );
        $stmt->execute([$this->collectionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame(['addf3' => 0, 'addf4' => 1], array_map('intval', $rows));
    }

    public function testAtomicCreateRollsBackWhenTooFewOwnedFilesRemain(): void
    {
        $this->insertFile('atomic-own', $this->userId);
        $this->insertFile('atomic-other', $this->otherId);

        $result = Database::createCollectionWithFiles(
            'atomic-fail',
            'Atomic',
            $this->userId,
            password_hash('delete-token', PASSWORD_DEFAULT),
            ['atomic-own', 'atomic-other'],
            $this->userId,
            ['one_time' => 1],
            2
        );

        $this->assertFalse($result['success']);
        $this->assertSame(
            0,
            (int) Database::getInstance()->query(
                "SELECT COUNT(*) FROM `" . Database::table('collections') . "`
                 WHERE `id` = 'atomic-fail'"
            )->fetchColumn()
        );
    }

    public function testAtomicCreateCommitsMembersAndOptionsTogether(): void
    {
        $this->insertFile('atomic-a', $this->userId);
        $this->insertFile('atomic-b', $this->userId);

        $result = Database::createCollectionWithFiles(
            'atomic-ok',
            'Atomic',
            $this->userId,
            password_hash('delete-token', PASSWORD_DEFAULT),
            ['atomic-b', 'atomic-a'],
            $this->userId,
            ['one_time' => 1, 'max_downloads' => 3, 'on_limit_action' => 'delete'],
            2
        );

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['added']);
        $collection = Database::getCollection('atomic-ok');
        $this->assertSame(1, (int) $collection['one_time']);
        $this->assertSame(3, (int) $collection['max_downloads']);
        $this->assertSame('delete', $collection['on_limit_action']);
        $this->assertSame(['atomic-b', 'atomic-a'], array_column($collection['files'], 'id'));
    }

    /* ---------------- permissions ---------------- */

    public function testOwnCollectionPermissionsNeedTheirMaster(): void
    {
        $this->assertSame([], Permissions::normalize(['myfiles.coll_add']),
            'adding to a collection means nothing without the collections surface');
        $this->assertSame([], Permissions::normalize(['myfiles.coll_create']));
        $this->assertSame(['myfiles.collections', 'myfiles.coll_add'],
            Permissions::normalize(['myfiles.collections', 'myfiles.coll_add']));
    }

    public function testOwnCollectionFiltersNeedTheirOwnMaster(): void
    {
        $this->assertSame(['myfiles.collections'],
            Permissions::normalize(['myfiles.collections', 'mcfilter.size']),
            'a criterion without "own collection filters" is dropped');
        $this->assertContains('mcfilter.size',
            Permissions::normalize(['myfiles.collections', 'myfiles.coll_filters', 'mcfilter.size']));
    }

    public function testCollectingOthersFilesNeedsTheAllFilesBrowser(): void
    {
        $this->assertSame([], Permissions::normalize(['files.coll_add']));
        $this->assertSame(['files.view_all', 'files.coll_add'],
            Permissions::normalize(['files.view_all', 'files.coll_add']));
    }

    /** The two "add to collection" permissions are genuinely independent of one another. */
    public function testOwnAndForeignAddAreSeparate(): void
    {
        $own = Permissions::normalize(['myfiles.collections', 'myfiles.coll_add']);
        $this->assertNotContains('files.coll_add', $own);

        $foreign = Permissions::normalize(['files.view_all', 'files.coll_add']);
        $this->assertNotContains('myfiles.coll_add', $foreign);
    }

    private function memberCount(): int
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT COUNT(*) FROM `' . Database::table('collection_files') . '` WHERE `collection_id` = ?'
        );
        $stmt->execute([$this->collectionId]);
        return (int) $stmt->fetchColumn();
    }
}
