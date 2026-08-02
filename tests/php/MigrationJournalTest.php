<?php

/**
 * Migration publication is fail-closed: an incomplete contract never becomes ready, and every
 * attempt leaves an operator-visible journal entry.
 */
final class MigrationJournalTest extends RepoTestCase
{
	protected function setUp(): void
	{
		// Earlier repository tests intentionally replace system-group data with tiny fixtures.
		// Force a real contract pass so this class starts from the same invariant as production.
		Database::setSetting('schema_contract_checked_at', '0');
		Database::migrate();
		Database::invalidateSettingsCache();

		// The group-repository tests intentionally exercise arbitrary operator permission
		// edits. The publication-boundary matrix below simulates a historical upgrade and
		// therefore needs the one-time v24/v31 upgrade postconditions as its own fixture,
		// rather than depending on whichever editable group state a previous test left behind.
		$pdo = Database::getInstance();
		$groups = Database::table('groups');
		$rows = $pdo->query(
			"SELECT `id`, `slug`, `permissions` FROM `{$groups}`"
		)->fetchAll(PDO::FETCH_ASSOC);
		$update = $pdo->prepare("UPDATE `{$groups}` SET `permissions` = ? WHERE `id` = ?");
		foreach ($rows as $row) {
			if (($row['slug'] ?? null) === 'guest') {
				continue;
			}
			$permissions = array_filter(array_map(
				'trim',
				explode(',', (string) $row['permissions'])
			));
			$permissions = array_merge($permissions, [
				'myfiles.collections',
				'myfiles.coll_create',
				'myfiles.coll_add',
				'myfiles.coll_delete',
				'ads.buy',
			]);
			$update->execute([
				Permissions::serialize($permissions),
				(int) $row['id'],
			]);
		}
		$plans = Database::table('plans');
		foreach (['free' => 'user', 'guest' => 'guest'] as $kind => $slug) {
			$exists = $pdo->prepare(
				"SELECT `id` FROM `{$plans}` WHERE `kind` = ? AND `is_system` = 1 LIMIT 1"
			);
			$exists->execute([$kind]);
			if ($exists->fetchColumn()) {
				continue;
			}
			$groupId = $pdo->prepare("SELECT `id` FROM `{$groups}` WHERE `slug` = ?");
			$groupId->execute([$slug]);
			$pdo->prepare(
				"INSERT INTO `{$plans}`
				 (`name`, `kind`, `group_id`, `checkout_type`, `auto_content`,
				  `show_limits`, `is_system`, `enabled`, `sort_order`, `created_at`)
				 VALUES (?, ?, ?, 'none', 1, 1, 1, 0, ?, ?)"
			)->execute([
				ucfirst($kind),
				$kind,
				$groupId->fetchColumn() ?: null,
				$kind === 'guest' ? -2 : -1,
				time(),
			]);
		}
	}

	public function testCurrentSchemaSatisfiesEveryDeclaredHistoricalContract(): void
	{
		$contractsMethod = new ReflectionMethod(Database::class, 'migrationStepContracts');
		$contracts = $contractsMethod->invoke(null);
		$this->assertSame(range(2, Database::CURRENT_SCHEMA_VERSION), array_keys($contracts));

		$problemsMethod = new ReflectionMethod(Database::class, 'migrationStepProblems');
		$pdo = Database::getInstance();
		$prefix = defined('DB_PREFIX') ? DB_PREFIX : '';
		foreach (array_keys($contracts) as $version) {
			$this->assertSame(
				[],
				$problemsMethod->invoke(null, $pdo, $prefix, $version),
				"Current schema violates the historical contract for migration {$version}."
			);
		}
	}

	public function testRepresentativeHistoricalContractSnapshotsStayStable(): void
	{
		$fixture = json_decode(
			file_get_contents(__DIR__ . '/fixtures/migration-contract-snapshots.json'),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		$this->assertSame(1, $fixture['format']);

		$contractsMethod = new ReflectionMethod(Database::class, 'migrationStepContracts');
		$contracts = $contractsMethod->invoke(null);
		$foreignKeysMethod = new ReflectionMethod(Database::class, 'migrationForeignKeyRules');
		$foreignKeys = $foreignKeysMethod->invoke(null);
		foreach ($fixture['milestones'] as $rawMilestone => $expected) {
			$milestone = (int) $rawMilestone;
			$artifacts = [];
			for ($version = 2; $version <= $milestone; $version++) {
				foreach (($contracts[$version]['columns'] ?? []) as $table => $columns) {
					foreach ($columns as $column) {
						$artifacts[] = "column:{$table}.{$column}";
					}
				}
				foreach (($contracts[$version]['indexes'] ?? []) as $table => $indexes) {
					foreach ($indexes as $name => $columns) {
						$artifacts[] = "index:{$table}.{$name}("
							. implode(',', $columns) . ')';
					}
				}
				foreach (($contracts[$version]['unique_indexes'] ?? []) as $table => $indexes) {
					foreach ($indexes as $name) {
						$artifacts[] = "unique-index:{$table}.{$name}";
					}
				}
			}
			if ($milestone >= 54) {
				foreach ($foreignKeys as $name => $deleteRule) {
					$artifacts[] = "foreign-key:{$name}:{$deleteRule}";
				}
			}
			$artifacts = array_values(array_unique($artifacts));
			sort($artifacts, SORT_STRING);
			$serialized = json_encode(
				$artifacts,
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
			);
			$this->assertSame(
				$expected,
				[
					'artifact_count' => count($artifacts),
					'sha256' => hash('sha256', $serialized),
				],
				"Historical contract snapshot {$milestone} changed unexpectedly."
			);
		}
	}

	public function testEveryMigrationPublicationBoundaryIsFailClosedAndRetryable(): void
	{
		$persist = new ReflectionMethod(Database::class, 'persistMigrationVersion');
		$this->assertTrue(Database::setSetting('schema_version', '1'));
		$this->assertTrue(Database::setSetting('schema_ready', '0'));

		try {
			foreach (range(2, Database::CURRENT_SCHEMA_VERSION) as $version) {
				putenv("FILEHOST_TEST_FAIL_MIGRATION_BEFORE_PUBLISH={$version}");
				try {
					$persist->invoke(null, $version);
					$this->fail("Migration {$version} publication fault was not injected.");
				} catch (RuntimeException $e) {
					$this->assertStringContainsString(
						"migration {$version} failure",
						strtolower($e->getMessage())
					);
				}
				Database::invalidateSettingsCache();
				$this->assertSame(
					(string) ($version - 1),
					(string) Database::getSetting('schema_version'),
					"Migration {$version} published its number after an injected failure."
				);

				putenv('FILEHOST_TEST_FAIL_MIGRATION_BEFORE_PUBLISH');
				$persist->invoke(null, $version);
				Database::invalidateSettingsCache();
				$this->assertSame(
					(string) $version,
					(string) Database::getSetting('schema_version'),
					"Migration {$version} did not publish cleanly on retry."
				);
			}
		} finally {
			putenv('FILEHOST_TEST_FAIL_MIGRATION_BEFORE_PUBLISH');
		}

		Database::migrate();
		Database::invalidateSettingsCache();
		$this->assertSame((string) Database::CURRENT_SCHEMA_VERSION, (string) Database::getSetting('schema_version'));
		$this->assertSame('1', (string) Database::getSetting('schema_ready'));
	}

	public function testVersion46UpgradeCreatesIndexesLeasesAndCompletesJournal(): void
	{
		$pdo = Database::getInstance();
		$files = Database::table('files');
		$active = Database::table('active_downloads');
		$journal = Database::table('migration_journal');
		$pdo->exec("ALTER TABLE `{$files}` DROP INDEX `idx_user_uploaded_id`");
		$pdo->prepare(
			"DELETE FROM `{$journal}` WHERE `version` BETWEEN 47 AND 59"
		)->execute();
		$this->assertTrue(Database::setSetting('schema_version', '46'));
		$this->assertTrue(Database::setSetting('schema_ready', '0'));

		Database::migrate();
		Database::invalidateSettingsCache();

		$this->assertSame((string) Database::CURRENT_SCHEMA_VERSION, (string) Database::getSetting('schema_version'));
		$this->assertSame('1', (string) Database::getSetting('schema_ready'));
		$index = $pdo->query("SHOW INDEX FROM `{$files}` WHERE `Key_name` = 'idx_user_uploaded_id'")
			->fetchAll(PDO::FETCH_ASSOC);
		$this->assertCount(3, $index);
		$stmt = $pdo->prepare(
			"SELECT `status`, `attempts`, `last_error` FROM `{$journal}` WHERE `version` = 47"
		);
		$stmt->execute();
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		$this->assertSame('completed', $row['status']);
		$this->assertGreaterThanOrEqual(1, (int) $row['attempts']);
		$this->assertNull($row['last_error']);
		$stmt = $pdo->prepare(
			"SELECT `status`, `attempts`, `last_error`
			 FROM `{$journal}` WHERE `version` = 59"
		);
		$stmt->execute();
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		$this->assertSame('completed', $row['status']);
		$this->assertGreaterThanOrEqual(1, (int) $row['attempts']);
		$this->assertNull($row['last_error']);
		$stmt = $pdo->prepare(
			"SELECT `status`, `attempts`, `last_error`
			 FROM `{$journal}` WHERE `version` = 54"
		);
		$stmt->execute();
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		$this->assertSame('completed', $row['status']);
		$this->assertGreaterThanOrEqual(1, (int) $row['attempts']);
		$this->assertNull($row['last_error']);
		$effects = Database::table('download_reservation_effects');
		$columns = $pdo->query(
			"SHOW COLUMNS FROM `{$effects}`"
		)->fetchAll(PDO::FETCH_COLUMN);
		$this->assertContains('effect_type', $columns);
		$this->assertContains('applied_at', $columns);
		$tokens = Database::table('download_tokens');
		$columns = $pdo->query(
			"SHOW COLUMNS FROM `{$tokens}`"
		)->fetchAll(PDO::FETCH_COLUMN);
		$this->assertContains('reservation_id', $columns);
		$this->assertContains('reserved_until', $columns);
		$this->assertContains('used_at', $columns);
		$stmt = $pdo->prepare(
			"SELECT `status`, `attempts`, `last_error`
			 FROM `{$journal}` WHERE `version` = 53"
		);
		$stmt->execute();
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		$this->assertSame('completed', $row['status']);
		$this->assertGreaterThanOrEqual(1, (int) $row['attempts']);
		$this->assertNull($row['last_error']);
		$daily = Database::table('traffic_daily');
		$columns = $pdo->query("SHOW COLUMNS FROM `{$daily}`")->fetchAll(PDO::FETCH_COLUMN);
		$this->assertContains('day', $columns);
		$this->assertContains('transfer_size', $columns);
		$reservations = Database::table('download_reservations');
		$columns = $pdo->query("SHOW COLUMNS FROM `{$reservations}`")->fetchAll(PDO::FETCH_COLUMN);
		$this->assertContains('state', $columns);
		$this->assertContains('bytes_sent', $columns);
		$this->assertContains('lease_until', $columns);
		$stmt = $pdo->prepare(
			"SELECT `status`, `attempts`, `last_error` FROM `{$journal}` WHERE `version` = 52"
		);
		$stmt->execute();
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		$this->assertSame('completed', $row['status']);
		$this->assertGreaterThanOrEqual(1, (int) $row['attempts']);
		$this->assertNull($row['last_error']);
		$stmt = $pdo->prepare(
			"SELECT `status`, `attempts`, `last_error` FROM `{$journal}` WHERE `version` = 51"
		);
		$stmt->execute();
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		$this->assertSame('completed', $row['status']);
		$this->assertGreaterThanOrEqual(1, (int) $row['attempts']);
		$this->assertNull($row['last_error']);
		$notifications = Database::table('notifications');
		$columns = $pdo->query("SHOW COLUMNS FROM `{$notifications}`")->fetchAll(PDO::FETCH_COLUMN);
		$this->assertContains('open_stack_key', $columns);
		$this->assertContains('dedupe_key', $columns);
		$stmt = $pdo->prepare(
			"SELECT `status`, `attempts`, `last_error` FROM `{$journal}` WHERE `version` = 50"
		);
		$stmt->execute();
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		$this->assertSame('completed', $row['status']);
		$this->assertGreaterThanOrEqual(1, (int) $row['attempts']);
		$this->assertNull($row['last_error']);
		$deliveries = Database::table('webhook_deliveries');
		$columns = $pdo->query("SHOW COLUMNS FROM `{$deliveries}`")->fetchAll(PDO::FETCH_COLUMN);
		$this->assertContains('event_id', $columns);
		$this->assertContains('lease_owner', $columns);
		$this->assertContains('lease_until', $columns);
		$stmt = $pdo->prepare(
			"SELECT `status`, `attempts`, `last_error` FROM `{$journal}` WHERE `version` = 49"
		);
		$stmt->execute();
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		$this->assertSame('completed', $row['status']);
		$this->assertGreaterThanOrEqual(1, (int) $row['attempts']);
		$this->assertNull($row['last_error']);
		$columns = $pdo->query("SHOW COLUMNS FROM `{$active}`")->fetchAll(PDO::FETCH_COLUMN);
		$this->assertContains('instance_id', $columns);
		$this->assertContains('heartbeat_at', $columns);
		$stmt = $pdo->prepare(
			"SELECT `status`, `attempts`, `last_error` FROM `{$journal}` WHERE `version` = 48"
		);
		$stmt->execute();
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		$this->assertSame('completed', $row['status']);
		$this->assertGreaterThanOrEqual(1, (int) $row['attempts']);
		$this->assertNull($row['last_error']);
	}

	public function testPublishedVersionRepairsMissingHistoricalColumnWithoutDowngrade(): void
	{
		$pdo = Database::getInstance();
		$reservations = Database::table('upload_storage_reservations');
		$journal = Database::table('migration_journal');
		$before = $pdo->query(
			"SELECT `attempts` FROM `{$journal}` WHERE `version` = 44"
		)->fetchColumn();
		$pdo->exec("ALTER TABLE `{$reservations}` DROP COLUMN `created_at`");
		$this->assertTrue(Database::setSetting('schema_contract_checked_at', '0'));

		Database::migrate();
		Database::invalidateSettingsCache();

		$columns = $pdo->query(
			"SHOW COLUMNS FROM `{$reservations}`"
		)->fetchAll(PDO::FETCH_COLUMN);
		$this->assertContains('created_at', $columns);
		$this->assertSame((string) Database::CURRENT_SCHEMA_VERSION, (string) Database::getSetting('schema_version'));
		$this->assertSame('1', (string) Database::getSetting('schema_ready'));
		$stmt = $pdo->prepare(
			"SELECT `status`, `attempts`, `last_error` "
			. "FROM `{$journal}` WHERE `version` = 44"
		);
		$stmt->execute();
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		$this->assertSame('completed', $row['status']);
		$this->assertGreaterThan((int) $before, (int) $row['attempts']);
		$this->assertNull($row['last_error']);
	}

	public function testPublishedVersionRepairsOldTableAndAlterColumnContracts(): void
	{
		$pdo = Database::getInstance();
		$rateLimits = Database::table('rate_limits');
		$users = Database::table('users');
		$journal = Database::table('migration_journal');
		$before = [];
		foreach ([10, 16] as $step) {
			$stmt = $pdo->prepare(
				"SELECT `attempts` FROM `{$journal}` WHERE `version` = ?"
			);
			$stmt->execute([$step]);
			$before[$step] = (int) $stmt->fetchColumn();
		}
		$pdo->exec("DROP TABLE `{$rateLimits}`");
		$pdo->exec("ALTER TABLE `{$users}` DROP COLUMN `language`");
		$this->assertTrue(Database::setSetting('schema_contract_checked_at', '0'));

		Database::migrate();
		Database::invalidateSettingsCache();

		$this->assertSame((string) Database::CURRENT_SCHEMA_VERSION, (string) Database::getSetting('schema_version'));
		$this->assertSame('1', (string) Database::getSetting('schema_ready'));
		$this->assertSame(
			['bucket', 'window_start', 'hits'],
			$pdo->query("SHOW COLUMNS FROM `{$rateLimits}`")->fetchAll(PDO::FETCH_COLUMN)
		);
		$this->assertContains(
			'language',
			$pdo->query("SHOW COLUMNS FROM `{$users}`")->fetchAll(PDO::FETCH_COLUMN)
		);
		foreach ([10, 16] as $step) {
			$stmt = $pdo->prepare(
				"SELECT `status`, `attempts`, `last_error` "
				. "FROM `{$journal}` WHERE `version` = ?"
			);
			$stmt->execute([$step]);
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			$this->assertSame('completed', $row['status']);
			$this->assertGreaterThan($before[$step], (int) $row['attempts']);
			$this->assertNull($row['last_error']);
		}
	}

	public function testPublishedVersionRepairsColumnsAndIndexesInPartialHistoricalTables(): void
	{
		$pdo = Database::getInstance();
		$rateLimits = Database::table('rate_limits');
		$deliveries = Database::table('webhook_deliveries');
		$journal = Database::table('migration_journal');
		$before = [];
		foreach ([8, 10] as $step) {
			$stmt = $pdo->prepare(
				"SELECT `attempts` FROM `{$journal}` WHERE `version` = ?"
			);
			$stmt->execute([$step]);
			$before[$step] = (int) $stmt->fetchColumn();
		}

		$pdo->exec("ALTER TABLE `{$rateLimits}` DROP PRIMARY KEY");
		$pdo->exec("ALTER TABLE `{$rateLimits}` DROP INDEX `idx_window`");
		$pdo->exec("ALTER TABLE `{$rateLimits}` ADD INDEX `idx_window` (`bucket`)");
		$pdo->exec("ALTER TABLE `{$rateLimits}` DROP COLUMN `hits`");
		$pdo->exec("ALTER TABLE `{$deliveries}` DROP COLUMN `event`");
		$this->assertTrue(Database::setSetting('schema_contract_checked_at', '0'));

		Database::migrate();
		Database::invalidateSettingsCache();

		$this->assertSame((string) Database::CURRENT_SCHEMA_VERSION, (string) Database::getSetting('schema_version'));
		$this->assertSame('1', (string) Database::getSetting('schema_ready'));
		$this->assertContains(
			'hits',
			$pdo->query("SHOW COLUMNS FROM `{$rateLimits}`")->fetchAll(PDO::FETCH_COLUMN)
		);
		$this->assertContains(
			'event',
			$pdo->query("SHOW COLUMNS FROM `{$deliveries}`")->fetchAll(PDO::FETCH_COLUMN)
		);
		$rateIndex = $pdo->query(
			"SHOW INDEX FROM `{$rateLimits}` WHERE `Key_name` = 'idx_window'"
		)->fetchAll(PDO::FETCH_ASSOC);
		$this->assertNotEmpty($rateIndex);
		$this->assertSame('window_start', (string) $rateIndex[0]['Column_name']);
		$primary = $pdo->query(
			"SHOW INDEX FROM `{$rateLimits}` WHERE `Key_name` = 'PRIMARY'"
		)->fetchAll(PDO::FETCH_ASSOC);
		$this->assertNotEmpty($primary);
		$this->assertSame('bucket', (string) $primary[0]['Column_name']);

		foreach ([8, 10] as $step) {
			$stmt = $pdo->prepare(
				"SELECT `status`, `attempts`, `last_error` "
				. "FROM `{$journal}` WHERE `version` = ?"
			);
			$stmt->execute([$step]);
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			$this->assertSame('completed', $row['status']);
			$this->assertGreaterThan($before[$step], (int) $row['attempts']);
			$this->assertNull($row['last_error']);
		}
	}

	public function testVersion53ForwardRepairDoesNotCompleteALiveReservation(): void
	{
		$pdo = Database::getInstance();
		$tokens = Database::table('download_tokens');
		$reservations = Database::table('download_reservations');
		$reservationId = str_repeat('d', 32);
		$pdo->exec("ALTER TABLE `{$tokens}` DROP INDEX `idx_download_token_reservation`");
		$stmt = $pdo->prepare(
			"INSERT INTO `{$reservations}`
			 (`id`, `resource_type`, `resource_id`, `token_fingerprint`,
			  `active_download_id`, `user_id`, `ip_address`, `state`, `bytes_sent`,
			  `lease_until`, `created_at`, `started_at`, `finished_at`, `updated_at`)
			 VALUES (?, 'file', 'repair-live', ?, NULL, NULL, '203.0.113.90',
			  'started', 123, ?, ?, ?, NULL, ?)"
		);
		$now = time();
		$stmt->execute([
			$reservationId,
			str_repeat('e', 64),
			$now + 120,
			$now,
			$now,
			$now,
		]);
		$this->assertTrue(Database::setSetting('schema_contract_checked_at', '0'));

		try {
			Database::migrate();
			Database::invalidateSettingsCache();

			$this->assertSame(
				'started',
				$pdo->query(
					"SELECT `state` FROM `{$reservations}` "
					. "WHERE `id` = '{$reservationId}'"
				)->fetchColumn()
			);
			$indexQuery = $pdo->prepare(
				"SELECT `COLUMN_NAME` FROM information_schema.STATISTICS "
				. "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? "
				. "AND INDEX_NAME = 'idx_download_token_reservation' "
				. "ORDER BY `SEQ_IN_INDEX`"
			);
			$indexQuery->execute([$tokens]);
			$columns = $indexQuery->fetchAll(PDO::FETCH_COLUMN);
			$this->assertSame(['reservation_id', 'reserved_until'], $columns);
			$this->assertSame((string) Database::CURRENT_SCHEMA_VERSION, (string) Database::getSetting('schema_version'));
		} finally {
			$pdo->prepare("DELETE FROM `{$reservations}` WHERE `id` = ?")
				->execute([$reservationId]);
		}
	}

	public function testVersion40ForwardRepairDoesNotHashCurrentCapabilitiesAgain(): void
	{
		$pdo = Database::getInstance();
		$users = Database::table('users');
		$recovery = Database::table('recovery_tokens');
		$username = 'migration-cap-' . bin2hex(random_bytes(4));
		$email = $username . '@example.test';
		$tokenHash = hash('sha256', 'already-hashed-capability');
		$now = time();
		$stmt = $pdo->prepare(
			"INSERT INTO `{$users}`
			 (`username`, `email`, `password_hash`, `created_at`, `activation_token`,
			  `activation_expires_at`)
			 VALUES (?, ?, ?, ?, ?, ?)"
		);
		$stmt->execute([
			$username,
			$email,
			password_hash('Migration-Test-Password-42!', PASSWORD_DEFAULT),
			$now,
			$tokenHash,
			$now + 900,
		]);
		$userId = (int) $pdo->lastInsertId();
		$pdo->exec("ALTER TABLE `{$recovery}` ADD INDEX `idx_recovery_user_repair` (`user_id`)");
		$pdo->exec("ALTER TABLE `{$recovery}` DROP INDEX `uniq_recovery_user`");
		$pdo->exec("ALTER TABLE `{$recovery}` ADD INDEX `uniq_recovery_user` (`user_id`)");
		$this->assertTrue(Database::setSetting('schema_contract_checked_at', '0'));

		try {
			Database::migrate();
			Database::invalidateSettingsCache();

			$stmt = $pdo->prepare(
				"SELECT `activation_token` FROM `{$users}` WHERE `id` = ?"
			);
			$stmt->execute([$userId]);
			$this->assertSame($tokenHash, $stmt->fetchColumn());
			$index = $pdo->query(
				"SHOW INDEX FROM `{$recovery}` WHERE `Key_name` = 'uniq_recovery_user'"
			)->fetchAll(PDO::FETCH_ASSOC);
			$this->assertNotEmpty($index);
			$this->assertSame('0', (string) $index[0]['Non_unique']);
			$this->assertSame((string) Database::CURRENT_SCHEMA_VERSION, (string) Database::getSetting('schema_version'));
		} finally {
			$pdo->prepare("DELETE FROM `{$users}` WHERE `id` = ?")->execute([$userId]);
			$indexes = $pdo->query(
				"SHOW INDEX FROM `{$recovery}` "
				. "WHERE `Key_name` = 'idx_recovery_user_repair'"
			)->fetchAll(PDO::FETCH_ASSOC);
			if ($indexes !== []) {
				$pdo->exec(
					"ALTER TABLE `{$recovery}` DROP INDEX `idx_recovery_user_repair`"
				);
			}
		}
	}

	public function testVersion40DataTransformRollsBackWithVersionPublication(): void
	{
		$pdo = Database::getInstance();
		$users = Database::table('users');
		$journal = Database::table('migration_journal');
		$trigger = 'fh_test_fail_v40_journal_update';
		$username = 'migration-v40-' . bin2hex(random_bytes(4));
		$rawToken = str_repeat('a', 64);
		$now = time();
		$stmt = $pdo->prepare(
			"INSERT INTO `{$users}`
			 (`username`, `email`, `password_hash`, `created_at`, `activation_token`,
			  `activation_expires_at`)
			 VALUES (?, ?, ?, ?, ?, ?)"
		);
		$stmt->execute([
			$username,
			$username . '@example.test',
			password_hash('Migration-Test-Password-42!', PASSWORD_DEFAULT),
			$now,
			$rawToken,
			$now + 900,
		]);
		$userId = (int) $pdo->lastInsertId();
		$pdo->exec("DROP TRIGGER IF EXISTS `{$trigger}`");
		$pdo->prepare(
			"DELETE FROM `{$journal}` WHERE `version` >= 40"
		)->execute();
		$this->assertTrue(Database::setSetting('schema_version', '39'));
		$this->assertTrue(Database::setSetting('schema_ready', '0'));
		$pdo->exec(
			"CREATE TRIGGER `{$trigger}` BEFORE UPDATE ON `{$journal}` "
			. "FOR EACH ROW SIGNAL SQLSTATE '45000' "
			. "SET MESSAGE_TEXT = 'injected v40 journal failure'"
		);

		try {
			try {
				Database::migrate();
				$this->fail('The injected journal failure must abort migration v40.');
			} catch (RuntimeException $e) {
				$this->assertStringContainsString(
					'injected v40 journal failure',
					strtolower($e->getMessage())
				);
				Database::invalidateSettingsCache();
				$this->assertSame('39', (string) Database::getSetting('schema_version'));
				$stmt = $pdo->prepare(
					"SELECT `activation_token` FROM `{$users}` WHERE `id` = ?"
				);
				$stmt->execute([$userId]);
				$this->assertSame($rawToken, $stmt->fetchColumn());
			}
		} finally {
			$pdo->exec("DROP TRIGGER IF EXISTS `{$trigger}`");
		}

		Database::migrate();
		Database::invalidateSettingsCache();
		$stmt = $pdo->prepare(
			"SELECT `activation_token` FROM `{$users}` WHERE `id` = ?"
		);
		$stmt->execute([$userId]);
		$this->assertSame(hash('sha256', $rawToken), $stmt->fetchColumn());
		$this->assertSame((string) Database::CURRENT_SCHEMA_VERSION, (string) Database::getSetting('schema_version'));
		$this->assertSame('1', (string) Database::getSetting('schema_ready'));
		$pdo->prepare("DELETE FROM `{$users}` WHERE `id` = ?")->execute([$userId]);
	}

	public function testFutureSchemaVersionFailsClosedWithoutDowngrade(): void
	{
		$future = Database::CURRENT_SCHEMA_VERSION + 1;
		$this->assertTrue(Database::setSetting('schema_version', (string) $future));
		$this->assertTrue(Database::setSetting('schema_ready', '1'));

		try {
			Database::migrate();
			$this->fail('A future schema version must not be silently downgraded.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString(
				'unsupported schema version ' . $future,
				strtolower($e->getMessage())
			);
			Database::invalidateSettingsCache();
			$this->assertSame((string) $future, (string) Database::getSetting('schema_version'));
			$this->assertSame('0', (string) Database::getSetting('schema_ready'));
		} finally {
			$this->assertTrue(Database::setSetting(
				'schema_version',
				(string) Database::CURRENT_SCHEMA_VERSION
			));
			Database::migrate();
			Database::invalidateSettingsCache();
		}

		$this->assertSame('1', (string) Database::getSetting('schema_ready'));
	}

	public function testLegacyMigrationFailureStopsVersionAndNextRunResumes(): void
	{
		$pdo = Database::getInstance();
		$reports = Database::table('reports');
		$blockedReports = $reports . '_migration_blocked';
		$journal = Database::table('migration_journal');
		$renamed = false;

		$pdo->exec("RENAME TABLE `{$reports}` TO `{$blockedReports}`");
		$renamed = true;
		$this->assertTrue(Database::setSetting('schema_version', '2'));
		$this->assertTrue(Database::setSetting('schema_ready', '1'));

		try {
			Database::migrate();
			$this->fail('A legacy migration error must stop before publishing the next version.');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('reports', strtolower($e->getMessage()));
			Database::invalidateSettingsCache();
			$this->assertSame('2', (string) Database::getSetting('schema_version'));
			$this->assertSame('0', (string) Database::getSetting('schema_ready'));

			$stmt = $pdo->prepare(
				"SELECT `status`, `attempts`, `last_error`
				 FROM `{$journal}` WHERE `version` = 3"
			);
			$stmt->execute();
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
			$this->assertSame('failed', $row['status']);
			$this->assertGreaterThanOrEqual(1, (int) $row['attempts']);
			$this->assertStringContainsString('reports', strtolower((string) $row['last_error']));
		} finally {
			if ($renamed) {
				$pdo->exec("RENAME TABLE `{$blockedReports}` TO `{$reports}`");
			}
		}

		Database::migrate();
		Database::invalidateSettingsCache();

		$this->assertSame((string) Database::CURRENT_SCHEMA_VERSION, (string) Database::getSetting('schema_version'));
		$this->assertSame('1', (string) Database::getSetting('schema_ready'));
		$stmt = $pdo->prepare(
			"SELECT `status`, `attempts`, `last_error`
			 FROM `{$journal}` WHERE `version` = 3"
		);
		$stmt->execute();
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		$this->assertSame('completed', $row['status']);
		$this->assertGreaterThanOrEqual(2, (int) $row['attempts']);
		$this->assertNull($row['last_error']);
	}

	public function testVersion54RepairsThePublishedPlanGroupDeletePolicy(): void
	{
		$pdo = Database::getInstance();
		$prefix = defined('DB_PREFIX') ? DB_PREFIX : '';
		$constraintName = substr(
			'fk_' . substr(hash('sha256', $prefix), 0, 8) . '_plan_group',
			0,
			64
		);
		$plans = Database::table('plans');
		$groups = Database::table('groups');
		$journal = Database::table('migration_journal');

		$pdo->exec("ALTER TABLE `{$plans}` DROP FOREIGN KEY `{$constraintName}`");
		$pdo->exec(
			"ALTER TABLE `{$plans}`
			 ADD CONSTRAINT `{$constraintName}` FOREIGN KEY (`group_id`)
			 REFERENCES `{$groups}` (`id`) ON DELETE RESTRICT"
		);
		$pdo->prepare("DELETE FROM `{$journal}` WHERE `version` BETWEEN 55 AND 59")->execute();
		$this->assertTrue(Database::setSetting('schema_version', '54'));
		$this->assertTrue(Database::setSetting('schema_ready', '0'));

		Database::migrate();
		Database::invalidateSettingsCache();

		$this->assertSame((string) Database::CURRENT_SCHEMA_VERSION, (string) Database::getSetting('schema_version'));
		$this->assertSame('1', (string) Database::getSetting('schema_ready'));
		$stmt = $pdo->prepare(
			"SELECT `DELETE_RULE` FROM information_schema.REFERENTIAL_CONSTRAINTS
			 WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?"
		);
		$stmt->execute([$constraintName]);
		$this->assertSame('SET NULL', $stmt->fetchColumn());
		$stmt = $pdo->prepare(
			"SELECT `status`, `attempts`, `last_error`
			 FROM `{$journal}` WHERE `version` = 55"
		);
		$stmt->execute();
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		$this->assertSame('completed', $row['status']);
		$this->assertGreaterThanOrEqual(1, (int) $row['attempts']);
		$this->assertNull($row['last_error']);
	}

	public function testVersion56RepairsThePublishedMailRetentionIndex(): void
	{
		$pdo = Database::getInstance();
		$outbox = Database::table('mail_outbox');
		$journal = Database::table('migration_journal');
		$pdo->exec("ALTER TABLE `{$outbox}` DROP INDEX `idx_mail_outbox_sent`");
		$pdo->prepare("DELETE FROM `{$journal}` WHERE `version` = 57")->execute();
		$this->assertTrue(Database::setSetting('schema_version', '56'));
		$this->assertTrue(Database::setSetting('schema_ready', '0'));

		Database::migrate();
		Database::invalidateSettingsCache();

		$this->assertSame((string) Database::CURRENT_SCHEMA_VERSION, (string) Database::getSetting('schema_version'));
		$this->assertSame('1', (string) Database::getSetting('schema_ready'));
		$stmt = $pdo->prepare(
			"SELECT `COLUMN_NAME` FROM information_schema.STATISTICS
			 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
			   AND INDEX_NAME = 'idx_mail_outbox_sent'
			 ORDER BY `SEQ_IN_INDEX`"
		);
		$stmt->execute([$outbox]);
		$this->assertSame(['status', 'sent_at'], $stmt->fetchAll(PDO::FETCH_COLUMN));
		$stmt = $pdo->prepare(
			"SELECT `status`, `attempts`, `last_error`
			 FROM `{$journal}` WHERE `version` = 57"
		);
		$stmt->execute();
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		$this->assertSame('completed', $row['status']);
		$this->assertGreaterThanOrEqual(1, (int) $row['attempts']);
		$this->assertNull($row['last_error']);
	}
}
