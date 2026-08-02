<?php

/**
 * PlanRepository: paid access plans (pt 9) — validation, and the group grant/expiry that
 * is the only part of a purchase the app itself owns.
 */
final class PlanRepositoryTest extends RepoTestCase
{
	private int $groupId;

	protected function setUp(): void
	{
		$this->truncate('plans', 'users');
		$res = Database::saveGroup(null, ['name' => 'Pro ' . bin2hex(random_bytes(3))]);
		$this->groupId = (int) $res['id'];
	}

	protected function tearDown(): void
	{
		Database::deleteGroup($this->groupId);
	}

	private function makeUser(): int
	{
		$name = 'planuser' . bin2hex(random_bytes(3));
		$res = Database::registerUser($name, $name . '@example.com', 'Str0ng!pass');
		$this->assertTrue($res['success'], $res['error'] ?? '');
		return (int) Database::loginUser($name, 'Str0ng!pass')['user']['id'];
	}

	private function makePlan(array $overrides = []): array
	{
		$res = PlanRepository::save(null, array_merge([
			'name' => 'Pro',
			'group_id' => $this->groupId,
			'duration_days' => 30,
			'enabled' => 1,
		], $overrides));
		$this->assertTrue($res['success'], $res['error'] ?? '');
		return PlanRepository::get((int) $res['id']);
	}

	public function testPlanNeedsANameAndAGroupBeforeGoingLive(): void
	{
		$this->assertFalse(PlanRepository::save(null, ['name' => ''])['success']);
		// A live plan with no group would take money for nothing.
		$this->assertFalse(PlanRepository::save(null, ['name' => 'Ghost', 'enabled' => 1])['success']);
		// As a draft it is fine — the group can be picked later.
		$this->assertTrue(PlanRepository::save(null, ['name' => 'Draft', 'enabled' => 0])['success']);
	}

	public function testModeratorPermissionGroupCannotBecomeAPlan(): void
	{
		$moderator = GroupRepository::getBySlug('moderator');
		$this->assertNotNull($moderator);
		$this->assertFalse(PlanRepository::save(null, [
			'name' => 'Invalid moderator plan',
			'group_id' => (int) $moderator['id'],
			'enabled' => 1,
		])['success']);
	}

	public function testOnlyEnabledPlansArePublic(): void
	{
		$this->makePlan(['name' => 'Live']);
		$this->makePlan(['name' => 'Draft', 'enabled' => 0]);

		$this->assertCount(2, PlanRepository::all());
		$enabled = PlanRepository::enabled();
		$this->assertCount(1, $enabled);
		$this->assertSame('Live', $enabled[0]['name']);
	}

	public function testPlanStoresOnlyKnownLimitCardFields(): void
	{
		$plan = $this->makePlan([
			'limit_fields' => ['transfer', 'quota', 'made_up'],
		]);
		$this->assertSame('quota,transfer', $plan['limit_fields']);
	}

	public function testGrantPutsTheUserInTheGroupWithAnExpiry(): void
	{
		$plan = $this->makePlan();
		$userId = $this->makeUser();

		$res = PlanRepository::grant($userId, $plan);
		$this->assertTrue($res['success']);
		$this->assertGreaterThan(time() + (29 * 86400), $res['expires_at']);

		$group = Database::getUserGroup($userId);
		$this->assertSame($this->groupId, (int) $group['id']);
	}

	/** Renewing the same plan adds to what is left instead of throwing it away. */
	public function testRebuyingTheSamePlanExtendsIt(): void
	{
		$plan = $this->makePlan();
		$userId = $this->makeUser();

		$first = PlanRepository::grant($userId, $plan)['expires_at'];
		$second = PlanRepository::grant($userId, $plan)['expires_at'];

		$this->assertGreaterThan($first, $second);
		$this->assertEqualsWithDelta($first + (30 * 86400), $second, 5);
	}

	public function testTwoConcurrentGrantsPreserveBothPaidPeriods(): void
	{
		$userId = $this->makeUser();
		$tag = bin2hex(random_bytes(8));
		$go = DATA_DIR . '/grant-go-' . $tag;
		$ready = [DATA_DIR . '/grant-ready-a-' . $tag, DATA_DIR . '/grant-ready-b-' . $tag];
		$worker = PROJECT_ROOT . '/tests/php/fixtures/grant_plan_worker.php';
		$env = array_merge(is_array(getenv()) ? getenv() : [], [
			'TEST_DB_HOST' => DB_HOST,
			'TEST_DB_USER' => DB_USER,
			'TEST_DB_PASS' => DB_PASS,
			'TEST_DB_NAME' => DB_NAME,
			'PROJECT_ROOT' => PROJECT_ROOT,
		]);
		$processes = [];

		try {
			foreach ($ready as $readyPath) {
				$process = proc_open(
					[PHP_BINARY, $worker, (string) $userId, (string) $this->groupId, $readyPath, $go],
					[
						0 => ['pipe', 'r'],
						1 => ['pipe', 'w'],
						2 => ['pipe', 'w'],
					],
					$pipes,
					PROJECT_ROOT,
					$env,
					['bypass_shell' => true]
				);
				$this->assertIsResource($process);
				fclose($pipes[0]);
				$processes[] = [$process, $pipes];
			}

			$deadline = microtime(true) + 10;
			while ((!is_file($ready[0]) || !is_file($ready[1])) && microtime(true) < $deadline) {
				usleep(1000);
			}
			$this->assertFileExists($ready[0]);
			$this->assertFileExists($ready[1]);
			$this->assertNotFalse(file_put_contents($go, 'go', LOCK_EX));

			foreach ($processes as [$process, $pipes]) {
				$stdout = stream_get_contents($pipes[1]);
				$stderr = stream_get_contents($pipes[2]);
				fclose($pipes[1]);
				fclose($pipes[2]);
				$code = proc_close($process);
				$this->assertSame(0, $code, "grant worker failed: {$stdout}\n{$stderr}");
			}
			$processes = [];

			$user = Database::getUserById($userId);
			$expected = time() + (60 * 86400);
			$this->assertSame($this->groupId, (int) $user['group_id']);
			$this->assertEqualsWithDelta($expected, (int) $user['group_expires_at'], 10);
		} finally {
			foreach ($processes as [$process, $pipes]) {
				foreach ($pipes as $pipe) {
					if (is_resource($pipe)) {
						fclose($pipe);
					}
				}
				if (is_resource($process)) {
					proc_terminate($process);
					proc_close($process);
				}
			}
			foreach (array_merge([$go], $ready) as $path) {
				if (is_file($path)) {
					unlink($path);
				}
			}
		}
	}

	public function testDurationZeroMeansNoExpiry(): void
	{
		$plan = $this->makePlan(['duration_days' => 0]);
		$userId = $this->makeUser();

		$this->assertNull(PlanRepository::grant($userId, $plan)['expires_at']);
		$this->assertSame($this->groupId, (int) Database::getUserGroup($userId)['id']);
	}

	public function testGrantRefusesAGroupDeletedAfterCheckoutConfiguration(): void
	{
		$plan = $this->makePlan();
		$userId = $this->makeUser();
		$this->assertTrue(Database::deleteGroup($this->groupId)['success']);

		$result = PlanRepository::grant($userId, $plan);
		$this->assertFalse($result['success']);
		$this->assertNotSame($this->groupId, (int) (Database::getUserById($userId)['group_id'] ?? 0));
	}

	/**
	 * The expiry is enforced on read, so a lapsed subscription ends on time even if nothing
	 * ran on a schedule to clean it up.
	 */
	public function testLapsedAssignmentFallsBackToTheDefaultGroup(): void
	{
		$plan = $this->makePlan();
		$userId = $this->makeUser();
		PlanRepository::grant($userId, $plan);

		$pdo = Database::getInstance();
		$pdo->prepare('UPDATE `' . Database::table('users') . '` SET `group_expires_at` = ? WHERE `id` = ?')
			->execute([time() - 60, $userId]);

		$group = Database::getUserGroup($userId);
		$this->assertNotSame($this->groupId, (int) ($group['id'] ?? 0));

		// And the row was cleared, not merely ignored.
		$stmt = $pdo->prepare('SELECT `group_id` FROM `' . Database::table('users') . '` WHERE `id` = ?');
		$stmt->execute([$userId]);
		$this->assertNull($stmt->fetchColumn() ?: null);
	}

	public function testRevokeReturnsTheUserToTheDefaultGroup(): void
	{
		$plan = $this->makePlan();
		$userId = $this->makeUser();
		PlanRepository::grant($userId, $plan);

		$this->assertTrue(PlanRepository::revoke($userId));
		$this->assertNotSame($this->groupId, (int) (Database::getUserGroup($userId)['id'] ?? 0));
	}

	/* ---- showcase cards: `free` / `guest` ---- */

	/** Nothing about a showcase card may end up looking purchasable, whatever was submitted. */
	public function testShowcaseKindsCannotCarryACheckout(): void
	{
		$plan = $this->makePlan([
			'kind' => 'free',
			'checkout_type' => 'builtin',
			'amount_minor' => 1900,
			'duration_days' => 30,
		]);

		$this->assertSame('free', $plan['kind']);
		$this->assertSame('none', $plan['checkout_type']);
		$this->assertSame(0, (int) $plan['amount_minor']);
		$this->assertSame(0, (int) $plan['duration_days']);
	}

	public function testAutomaticContentIsOnlyForShowcaseKinds(): void
	{
		$paid = $this->makePlan(['kind' => 'paid', 'auto_content' => 1]);
		$this->assertSame(0, (int) $paid['auto_content'], 'a paid plan has nothing to derive');

		$free = $this->makePlan(['kind' => 'free', 'auto_content' => 1]);
		$this->assertSame(1, (int) $free['auto_content']);
		$this->assertSame(1, (int) $free['show_limits'], 'the generated card is the limits');
	}

	/**
	 * The generated card describes the bound group: its permissions become the feature list, so
	 * granting one in Settings adds a line to the pricing page and nothing has to be retyped.
	 */
	public function testAutomaticContentFollowsTheGroupPermissions(): void
	{
		Database::saveGroup($this->groupId, ['name' => 'Pro auto ' . bin2hex(random_bytes(2)), 'permissions' => '']);
		$plan = $this->makePlan(['kind' => 'free', 'auto_content' => 1]);

		$before = PlanRepository::autoContent($plan)['features'];
		$this->assertNotContains(__('premium.auto_feat_collections'), $before);

		Database::saveGroup($this->groupId, [
			'name' => 'Pro auto ' . bin2hex(random_bytes(2)),
			'permissions' => 'myfiles.collections,myfiles.coll_create',
		]);
		$after = PlanRepository::autoContent($plan)['features'];
		$this->assertContains(__('premium.auto_feat_collections'), $after);
	}

	/** A built-in card is switched off, never deleted — the next migration would put it back. */
	public function testSystemPlansRefuseToBeDeleted(): void
	{
		$plan = $this->makePlan(['kind' => 'guest']);
		$pdo = Database::getInstance();
		$pdo->prepare('UPDATE `' . Database::table('plans') . '` SET `is_system` = 1 WHERE `id` = ?')
			->execute([(int) $plan['id']]);

		$this->assertFalse(PlanRepository::delete((int) $plan['id']));
		$this->assertNotNull(PlanRepository::get((int) $plan['id']));
	}
}
