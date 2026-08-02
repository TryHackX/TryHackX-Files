<?php

/**
 * GroupRepository: named limit profiles; exactly one default; members resolve to default
 * when unassigned or pointing at a deleted group.
 */
final class GroupRepositoryTest extends RepoTestCase
{
	protected function setUp(): void
	{
		$this->truncate('payments', 'plans', 'groups', 'users');
		Database::getInstance()
			->prepare("INSERT INTO `" . Database::table('groups') . "` (`name`, `is_default`, `created_at`) VALUES ('Default', 1, ?)")
			->execute([time()]);
	}

	public function testDefaultGroupExists(): void
	{
		$def = Database::getDefaultGroup();
		$this->assertIsArray($def);
		$this->assertSame(1, (int) $def['is_default']);
	}

	public function testSaveCreatesAndUpdates(): void
	{
		$res = Database::saveGroup(null, ['name' => 'Premium', 'max_file_size_mb' => 100]);
		$this->assertTrue($res['success']);
		$id = (int) $res['id'];

		$grp = Database::getGroupById($id);
		$this->assertSame('Premium', $grp['name']);
		$this->assertSame(100, (int) $grp['max_file_size_mb']);

		$upd = Database::saveGroup($id, ['name' => 'Premium', 'max_file_size_mb' => 250]);
		$this->assertTrue($upd['success']);
		$this->assertSame(250, (int) Database::getGroupById($id)['max_file_size_mb']);
	}

	public function testPeriodicTransferAllowanceIsPersistedAndValidated(): void
	{
		$res = Database::saveGroup(null, [
			'name' => 'Metered',
			'transfer_quota_bytes' => 500 * 1024 * 1024,
			'transfer_quota_period' => 'month',
		]);
		$this->assertTrue($res['success']);
		$group = Database::getGroupById((int) $res['id']);
		$this->assertSame(500 * 1024 * 1024, (int) $group['transfer_quota_bytes']);
		$this->assertSame('month', $group['transfer_quota_period']);

		$this->assertTrue(Database::saveGroup((int) $res['id'], [
			'name' => 'Metered',
			'transfer_quota_bytes' => 123,
			'transfer_quota_period' => 'not-a-period',
		])['success']);
		$this->assertSame('week', Database::getGroupById((int) $res['id'])['transfer_quota_period']);
	}

	public function testDuplicateNameRejected(): void
	{
		Database::saveGroup(null, ['name' => 'Dup']);
		$again = Database::saveGroup(null, ['name' => 'Dup']);
		$this->assertFalse($again['success']);
	}

	public function testCannotDeleteDefaultButCanDeleteOther(): void
	{
		$def = Database::getDefaultGroup();
		$this->assertFalse(Database::deleteGroup((int) $def['id'])['success']);

		$id = (int) Database::saveGroup(null, ['name' => 'Temp'])['id'];
		$this->assertTrue(Database::deleteGroup($id)['success']);
		$this->assertNull(Database::getGroupById($id));
	}

	public function testExactlyOneDefaultAfterSwitch(): void
	{
		$id = (int) Database::saveGroup(null, ['name' => 'NewDefault', 'is_default' => 1])['id'];
		$pdo = Database::getInstance();
		$defaults = (int) $pdo->query("SELECT COUNT(*) FROM `" . Database::table('groups') . "` WHERE `is_default` = 1")->fetchColumn();
		$this->assertSame(1, $defaults);
		$this->assertSame($id, (int) Database::getDefaultGroup()['id']);
	}

	public function testAssignUserResolvesGroupThenDefault(): void
	{
		$this->truncate('users');
		$pdo = Database::getInstance();
		$pdo->prepare("INSERT INTO `" . Database::table('users') . "` (username,email,password_hash,role,is_active,created_at) VALUES (?,?,?,?,1,?)")
			->execute(['grpuser', 'g@u.pl', 'x', 'user', time()]);
		$uid = (int) $pdo->lastInsertId();

		$gid = (int) Database::saveGroup(null, ['name' => 'Assigned'])['id'];
		$this->assertTrue(Database::setUserGroup($uid, $gid));
		$this->assertSame('Assigned', Database::getUserGroup($uid)['name']);

		// Clearing the group falls back to the default.
		$this->assertTrue(Database::setUserGroup($uid, null));
		$this->assertSame(1, (int) Database::getUserGroup($uid)['is_default']);
	}

	public function testModeratorSystemGroupCarriesLimitsButIsNotPlanAssignable(): void
	{
		$pdo = Database::getInstance();
		$pdo->prepare(
			"INSERT INTO `" . Database::table('groups') . "`
			 (`name`, `slug`, `is_system`, `is_default`, `created_at`)
			 VALUES ('Moderator', 'moderator', 1, 0, ?)"
		)->execute([time()]);
		$moderatorId = (int) $pdo->lastInsertId();
		$pdo->prepare(
			"INSERT INTO `" . Database::table('users') . "`
			 (`username`, `email`, `password_hash`, `role`, `is_active`, `created_at`)
			 VALUES ('mod-group-user', 'mod-group@example.test', 'x', 'moderator', 1, ?)"
		)->execute([time()]);
		$userId = (int) $pdo->lastInsertId();

		$this->assertFalse(Database::setUserGroup($userId, $moderatorId));
		$this->assertTrue(Database::saveGroup($moderatorId, [
			'name' => 'Moderator',
			'max_file_size_mb' => 100,
			'storage_quota_mb' => 100,
			'auto_delete_days' => -1,
			'is_default' => 1,
			'permissions' => ['moderation.reports.view'],
		])['success']);

		$moderator = Database::getGroupById($moderatorId);
		$this->assertSame(100, (int) $moderator['max_file_size_mb']);
		$this->assertSame(100, (int) $moderator['storage_quota_mb']);
		$this->assertSame(-1, (int) $moderator['auto_delete_days']);
		$this->assertSame(0, (int) $moderator['is_default']);
		$this->assertFalse(Database::deleteGroup($moderatorId)['success']);
	}

	public function testModeratorAndPlanLimitsMergeInTheUsersFavour(): void
	{
		$pdo = Database::getInstance();
		$default = Database::getDefaultGroup();
		Database::saveGroup((int) $default['id'], [
			'name' => 'Default',
			'max_file_size_mb' => 100,
			'max_files_per_session' => 5,
			'storage_quota_mb' => 1000,
			'transfer_quota_bytes' => 500,
			'transfer_quota_period' => 'week',
			'auto_delete_days' => 7,
		]);
		$pdo->prepare(
			"INSERT INTO `" . Database::table('groups') . "`
			 (`name`, `slug`, `is_system`, `is_default`, `max_file_size_mb`,
			  `max_files_per_session`, `storage_quota_mb`, `transfer_quota_bytes`,
			  `transfer_quota_period`, `auto_delete_days`, `created_at`)
			 VALUES ('Moderator', 'moderator', 1, 0, 250, 20, 500, 100, 'day', -1, ?)"
		)->execute([time()]);
		$moderatorId = (int) $pdo->lastInsertId();
		$pdo->prepare(
			"INSERT INTO `" . Database::table('users') . "`
			 (`username`, `email`, `password_hash`, `role`, `staff_group_id`, `is_active`, `created_at`)
			 VALUES ('limit-mod', 'limit-mod@example.test', 'x', 'moderator', ?, 1, ?)"
		)->execute([$moderatorId, time()]);
		$userId = (int) $pdo->lastInsertId();

		$effective = Database::getUserEffectiveGroup($userId);
		$this->assertSame(250, (int) $effective['max_file_size_mb']);
		$this->assertSame(20, (int) $effective['max_files_per_session']);
		$this->assertSame(1000, (int) $effective['storage_quota_mb']);
		$this->assertSame(100, (int) $effective['transfer_quota_bytes']);
		$this->assertSame('day', $effective['transfer_quota_period']);
		$this->assertSame(0, GroupRepository::retentionDays($effective));
	}

	public function testManualAssignmentAndGroupDeletionClearPaidExpiry(): void
	{
		$pdo = Database::getInstance();
		$pdo->prepare("INSERT INTO `" . Database::table('users') . "`
			(username,email,password_hash,role,is_active,created_at) VALUES (?,?,?,?,1,?)")
			->execute(['expiryuser', 'expiry@example.test', 'x', 'user', time()]);
		$userId = (int) $pdo->lastInsertId();
		$groupId = (int) Database::saveGroup(null, ['name' => 'Temporary paid group'])['id'];

		$pdo->prepare("UPDATE `" . Database::table('users') . "`
			SET `group_id` = ?, `group_expires_at` = ? WHERE `id` = ?")
			->execute([$groupId, time() + 86400, $userId]);
		$this->assertTrue(Database::setUserGroup($userId, $groupId));

		$stmt = $pdo->prepare("SELECT `group_expires_at` FROM `" . Database::table('users') . "` WHERE `id` = ?");
		$stmt->execute([$userId]);
		$this->assertNull($stmt->fetchColumn());

		$pdo->prepare("UPDATE `" . Database::table('users') . "`
			SET `group_expires_at` = ? WHERE `id` = ?")
			->execute([time() + 86400, $userId]);
		$this->assertTrue(Database::deleteGroup($groupId)['success']);
		$stmt = $pdo->prepare("SELECT `group_id`, `group_expires_at` FROM `" . Database::table('users') . "` WHERE `id` = ?");
		$stmt->execute([$userId]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		$this->assertNull($row['group_id']);
		$this->assertNull($row['group_expires_at']);
	}
}
