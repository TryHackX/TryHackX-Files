<?php

/**
 * Foreign-key policy is part of the data contract: operational children disappear with their
 * parent, historical rows keep their snapshot and lose only the dangling identifier.
 */
final class RelationIntegrityTest extends RepoTestCase
{
	public function testCriticalForeignKeysExposeExpectedDeletePolicies(): void
	{
		$pdo = Database::getInstance();
		$stmt = $pdo->prepare(
			"SELECT rc.`DELETE_RULE`
			 FROM information_schema.KEY_COLUMN_USAGE k
			 JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
			   ON rc.`CONSTRAINT_SCHEMA` = k.`CONSTRAINT_SCHEMA`
			  AND rc.`CONSTRAINT_NAME` = k.`CONSTRAINT_NAME`
			 WHERE k.`CONSTRAINT_SCHEMA` = DATABASE()
			   AND k.`TABLE_NAME` = ?
			   AND k.`COLUMN_NAME` = ?"
		);
		$expected = [
			['collection_files', 'collection_id', 'CASCADE'],
			['collection_files', 'file_id', 'CASCADE'],
			['reports', 'file_id', 'CASCADE'],
			['webhook_deliveries', 'webhook_id', 'CASCADE'],
			['notifications', 'user_id', 'CASCADE'],
			['files', 'user_id', 'RESTRICT'],
			['payments', 'user_id', 'SET NULL'],
			['payments', 'plan_id', 'SET NULL'],
			['plans', 'group_id', 'SET NULL'],
			['users', 'staff_group_id', 'SET NULL'],
			['promo_codes', 'plan_id', 'SET NULL'],
			['download_reservation_effects', 'reservation_id', 'CASCADE'],
		];

		foreach ($expected as [$table, $column, $deleteRule]) {
			$stmt->execute([Database::table($table), $column]);
			$this->assertSame(
				$deleteRule,
				$stmt->fetchColumn(),
				"Unexpected delete policy for {$table}.{$column}"
			);
		}
	}

	public function testFileDeletionCascadesOperationalChildrenButPreservesTraffic(): void
	{
		$pdo = Database::getInstance();
		$fileId = 'fk_file_' . bin2hex(random_bytes(4));
		$collectionId = 'fk_coll_' . bin2hex(random_bytes(4));
		$this->insertFile($fileId);

		$pdo->prepare(
			"INSERT INTO `" . Database::table('collections') . "`
			 (`id`, `name`, `user_id`, `delete_token`, `downloads`, `created_at`)
			 VALUES (?, 'FK collection', NULL, 'token', 0, ?)"
		)->execute([$collectionId, time()]);
		$pdo->prepare(
			"INSERT INTO `" . Database::table('collection_files') . "`
			 (`collection_id`, `file_id`, `position`) VALUES (?, ?, 0)"
		)->execute([$collectionId, $fileId]);
		$pdo->prepare(
			"INSERT INTO `" . Database::table('reports') . "`
			 (`file_id`, `reporter_name`, `reporter_email`, `report_title`,
			  `created_at`, `ip_address`)
			 VALUES (?, 'Reporter', 'reporter@example.test', 'Test', ?, '203.0.113.40')"
		)->execute([$fileId, time()]);
		$pdo->prepare(
			"INSERT INTO `" . Database::table('traffic_logs') . "`
			 (`ip_address`, `transfer_size`, `transfer_type`, `file_id`, `user_id`, `created_at`)
			 VALUES ('203.0.113.40', 12, 'download', ?, NULL, ?)"
		)->execute([$fileId, time()]);
		$trafficId = (int) $pdo->lastInsertId();

		try {
			$pdo->prepare(
				"DELETE FROM `" . Database::table('files') . "` WHERE `id` = ?"
			)->execute([$fileId]);

			$stmt = $pdo->prepare(
				"SELECT COUNT(*) FROM `" . Database::table('collection_files') . "`
				 WHERE `file_id` = ?"
			);
			$stmt->execute([$fileId]);
			$this->assertSame(0, (int) $stmt->fetchColumn());
			$stmt = $pdo->prepare(
				"SELECT COUNT(*) FROM `" . Database::table('reports') . "`
				 WHERE `file_id` = ?"
			);
			$stmt->execute([$fileId]);
			$this->assertSame(0, (int) $stmt->fetchColumn());
			$stmt = $pdo->prepare(
				"SELECT `file_id` FROM `" . Database::table('traffic_logs') . "`
				 WHERE `id` = ?"
			);
			$stmt->execute([$trafficId]);
			$this->assertNull($stmt->fetchColumn());
			$stmt = $pdo->prepare(
				"SELECT COUNT(*) FROM `" . Database::table('collections') . "`
				 WHERE `id` = ?"
			);
			$stmt->execute([$collectionId]);
			$this->assertSame(1, (int) $stmt->fetchColumn());
		} finally {
			$pdo->prepare(
				"DELETE FROM `" . Database::table('traffic_logs') . "` WHERE `id` = ?"
			)->execute([$trafficId]);
			$pdo->prepare(
				"DELETE FROM `" . Database::table('collections') . "` WHERE `id` = ?"
			)->execute([$collectionId]);
			$pdo->prepare(
				"DELETE FROM `" . Database::table('files') . "` WHERE `id` = ?"
			)->execute([$fileId]);
		}
	}

	public function testAccountWithFilesIsRestrictedAndFinancialHistoryIsPreserved(): void
	{
		$pdo = Database::getInstance();
		$nonce = bin2hex(random_bytes(5));
		$pdo->prepare(
			"INSERT INTO `" . Database::table('users') . "`
			 (`username`, `email`, `password_hash`, `created_at`)
			 VALUES (?, ?, 'hash', ?)"
		)->execute(["fk_{$nonce}", "fk_{$nonce}@example.test", time()]);
		$userId = (int) $pdo->lastInsertId();
		$fileId = 'fk_owner_' . bin2hex(random_bytes(4));
		$this->insertFile($fileId, $userId);
		$orderId = 'fk-order-' . $nonce;

		try {
			try {
				$pdo->prepare(
					"DELETE FROM `" . Database::table('users') . "` WHERE `id` = ?"
				)->execute([$userId]);
				$this->fail('An account with unqueued files must be protected by RESTRICT.');
			} catch (PDOException $e) {
				$this->assertSame('23000', (string) $e->getCode());
			}

			$pdo->prepare(
				"INSERT INTO `" . Database::table('payments') . "`
				 (`ext_order_id`, `provider`, `plan_id`, `user_id`, `amount_minor`,
				  `currency`, `status`, `kind`, `created_at`, `updated_at`)
				 VALUES (?, 'test', NULL, ?, 100, 'PLN', 'COMPLETED', 'purchase', ?, ?)"
			)->execute([$orderId, $userId, time(), time()]);
			$paymentId = (int) $pdo->lastInsertId();
			$pdo->prepare(
				"INSERT INTO `" . Database::table('notifications') . "`
				 (`user_id`, `type`, `subject`, `created_at`, `updated_at`)
				 VALUES (?, 'account.test', 'FK test', ?, ?)"
			)->execute([$userId, time(), time()]);

			$pdo->prepare(
				"DELETE FROM `" . Database::table('files') . "` WHERE `id` = ?"
			)->execute([$fileId]);
			$pdo->prepare(
				"DELETE FROM `" . Database::table('users') . "` WHERE `id` = ?"
			)->execute([$userId]);

			$stmt = $pdo->prepare(
				"SELECT `user_id` FROM `" . Database::table('payments') . "`
				 WHERE `id` = ?"
			);
			$stmt->execute([$paymentId]);
			$this->assertNull($stmt->fetchColumn());
			$stmt = $pdo->prepare(
				"SELECT COUNT(*) FROM `" . Database::table('notifications') . "`
				 WHERE `user_id` = ?"
			);
			$stmt->execute([$userId]);
			$this->assertSame(0, (int) $stmt->fetchColumn());
		} finally {
			$pdo->prepare(
				"DELETE FROM `" . Database::table('payments') . "`
				 WHERE `ext_order_id` = ?"
			)->execute([$orderId]);
			$pdo->prepare(
				"DELETE FROM `" . Database::table('files') . "` WHERE `id` = ?"
			)->execute([$fileId]);
			$pdo->prepare(
				"DELETE FROM `" . Database::table('users') . "` WHERE `id` = ?"
			)->execute([$userId]);
		}
	}
}
