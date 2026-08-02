<?php

/**
 * Permissions: the rules that decide what a non-admin group can do in the all-files browser.
 *
 * These are pure functions over a permission list, so they are tested directly rather than
 * through HTTP — the controller's job is only to call them, and getting the dependency and
 * staff-only rules wrong is what would actually leak data.
 */
final class PermissionsTest extends RepoTestCase
{
	protected function setUp(): void
	{
		// forCurrentUser()/isStaff() read the session; start each test as nobody.
		$_SESSION = [];
		$this->truncate('api_keys', 'upload_tokens', 'download_tokens', 'recovery_tokens', 'users');
		Database::invalidateSettingsCache();
	}

	public function testNormalizeDropsUnknownKeys(): void
	{
		$this->assertSame(
			['files.view_all'],
			Permissions::normalize(['files.view_all', 'files.not_a_real_permission', ''])
		);
	}

	public function testNormalizeDropsChildWithoutParent(): void
	{
		// Every browsing capability hangs off view_all; without it none of them mean anything.
		$this->assertSame([], Permissions::normalize(['files.search_all', 'files.see_owner']));
		$this->assertSame([], Permissions::normalize(['filter.size']));
	}

	public function testNormalizeCollapsesWholeChain(): void
	{
		// filter.ip needs advanced_filters, which needs view_all — dropping the root must
		// cascade all the way down rather than leaving an orphaned filter behind.
		$this->assertSame([], Permissions::normalize(['filter.ip', 'files.advanced_filters']));

		$this->assertSame(
			['files.view_all', 'files.advanced_filters', 'filter.ip'],
			Permissions::normalize(['filter.ip', 'files.advanced_filters', 'files.view_all'])
		);
	}

	public function testNormalizeIsOrderIndependentAndStable(): void
	{
		$a = Permissions::normalize(['filter.size', 'files.advanced_filters', 'files.view_all']);
		$b = Permissions::normalize(['files.view_all', 'filter.size', 'files.advanced_filters']);
		$this->assertSame($a, $b, 'input order must not change the stored value');
		$this->assertSame(['files.view_all', 'files.advanced_filters', 'filter.size'], $a);
	}

	public function testSerializeParseRoundTrip(): void
	{
		$perms = ['files.view_all', 'files.search_all'];
		$stored = Permissions::serialize($perms);
		$this->assertSame('files.view_all,files.search_all', $stored);
		$this->assertSame($perms, Permissions::parse($stored));
	}

	public function testParseHandlesEmptyAndNull(): void
	{
		$this->assertSame([], Permissions::parse(null));
		$this->assertSame([], Permissions::parse(''));
		$this->assertSame([], Permissions::parse('   '));
	}

	public function testPlanGroupsNeverGrantStaffOnlyPermissions(): void
	{
		$group = ['permissions' => implode(',', [
			'files.view_all',
			'files.see_ip',
			'files.advanced_filters',
			'filter.ip',
			'moderation.reports.view',
			'premium.metrics',
			'ads.metrics',
		])];

		// Plain users never receive staff capabilities, however their group is configured.
		$user = Permissions::forGroup($group, 'user');
		$this->assertContains('files.view_all', $user);
		$this->assertNotContains('files.see_ip', $user);
		$this->assertNotContains('filter.ip', $user);
		$this->assertNotContains('moderation.reports.view', $user);
		$this->assertNotContains('premium.metrics', $user);
		$this->assertNotContains('ads.metrics', $user);

		// Moderator operations come from the role-bound system group, not the plan group.
		$mod = Permissions::forGroup($group, 'moderator');
		$this->assertContains('files.view_all', $mod);
		$this->assertNotContains('files.see_ip', $mod);
		$this->assertNotContains('filter.ip', $mod);
		$this->assertNotContains('moderation.reports.view', $mod);
		$this->assertNotContains('premium.metrics', $mod);
		$this->assertNotContains('ads.metrics', $mod);
	}

	public function testModeratorSystemGroupSurvivesPremiumPlanGroupChange(): void
	{
		$base = Database::saveGroup(null, [
			'name' => 'Base ' . bin2hex(random_bytes(3)),
			'permissions' => ['ads.buy'],
		]);
		$staff = GroupRepository::getBySlug('moderator');
		$this->assertNotNull($staff);
		$this->assertTrue(Database::saveGroup((int) $staff['id'], [
			'name' => (string) $staff['name'],
			'permissions' => [
				'files.view_all',
				'files.see_ip',
				'moderation.reports.view',
				'premium.metrics',
			],
		])['success']);
		$premium = Database::saveGroup(null, [
			'name' => 'Premium ' . bin2hex(random_bytes(3)),
			'permissions' => ['ads.buy', 'ads.exempt'],
		]);
		$this->assertTrue(Database::registerUser(
			'systemmod',
			'systemmod@example.test',
			'Passw0rd!'
		)['success']);
		$userId = (int) Database::getUserByEmailOrUsername('systemmod')['id'];
		$this->assertTrue(Database::setUserGroup($userId, (int) $base['id']));
		$this->assertTrue(Database::adminUpdateUser($userId, [
			'role' => 'moderator',
		])['success']);

		SessionAuth::establish(Database::getUserById($userId));
		$this->assertContains('ads.buy', Permissions::forCurrentUser());
		$this->assertContains('moderation.reports.view', Permissions::forCurrentUser());
		$this->assertContains('premium.metrics', Permissions::forCurrentUser());
		$this->assertContains('files.see_ip', Permissions::forCurrentUser());

		$grant = PlanRepository::grant($userId, [
			'id' => 999,
			'group_id' => (int) $premium['id'],
			'duration_days' => 30,
		]);
		$this->assertTrue($grant['success']);
		Permissions::resetCurrentUserCache();

		$this->assertSame((int) $premium['id'], (int) Database::getUserGroup($userId)['id']);
		$this->assertSame((int) $staff['id'], (int) Database::getUserStaffGroup($userId)['id']);
		$this->assertContains('ads.exempt', Permissions::forCurrentUser());
		$this->assertContains('moderation.reports.view', Permissions::forCurrentUser());
		$this->assertContains('premium.metrics', Permissions::forCurrentUser());
	}

	public function testOperationalPermissionsRequireTheirParentCapabilities(): void
	{
		$this->assertSame([], Permissions::normalize(['moderation.reports.resolve']));
		$this->assertSame([], Permissions::normalize(['moderation.files.delete']));
		$this->assertSame([], Permissions::normalize(['premium.payments']));
		$this->assertSame([], Permissions::normalize(['premium.refunds']));
		$this->assertSame([], Permissions::normalize(['premium.bulk_grants']));
		$this->assertSame([], Permissions::normalize(['premium.grants']));
		$this->assertSame([], Permissions::normalize(['ads.refund']));

		$this->assertSame(
			['moderation.reports.view', 'moderation.reports.resolve', 'moderation.files.delete'],
			Permissions::normalize([
				'moderation.files.delete',
				'moderation.reports.resolve',
				'moderation.reports.view',
			])
		);
		$this->assertSame(
			['premium.metrics', 'premium.payments', 'premium.refunds'],
			Permissions::normalize(['premium.refunds', 'premium.payments', 'premium.metrics'])
		);
		$this->assertSame(
			['premium.subscribers', 'premium.grants', 'premium.bulk_grants'],
			Permissions::normalize(['premium.bulk_grants', 'premium.grants', 'premium.subscribers'])
		);
		$this->assertSame(
			['ads.approve', 'ads.refund'],
			Permissions::normalize(['ads.refund', 'ads.approve'])
		);
	}

	public function testAdminHoldsEveryPermission(): void
	{
		$this->assertSame(Permissions::all(), Permissions::forGroup(['permissions' => ''], 'admin'));
	}

	public function testHasIsTrueForAdminSessionRegardlessOfGroup(): void
	{
		$this->assertTrue(Database::registerUser('adminuser', 'admin@example.test', 'Passw0rd!')['success']);
		$userId = (int) Database::getUserByEmailOrUsername('adminuser')['id'];
		$this->assertTrue(Database::adminUpdateUser($userId, ['role' => 'admin'])['success']);
		$user = Database::getUserById($userId);
		SessionAuth::establish($user);

		$this->assertTrue(Permissions::has('files.view_all'));
		$this->assertTrue(Permissions::has('filter.ip'));
	}

	public function testGuestSessionHoldsNothing(): void
	{
		$this->assertSame([], Permissions::forCurrentUser());
		$this->assertFalse(Permissions::has('files.view_all'));
	}
}
