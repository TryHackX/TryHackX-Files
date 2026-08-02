<?php

use PHPUnit\Framework\TestCase;

/**
 * Base for repository tests: gives each test a clean slate by truncating the tables it
 * touches (repositories manage their own connection/commits, so per-test truncation is more
 * robust here than an outer transaction that a repository COMMIT could break).
 */
abstract class RepoTestCase extends TestCase
{
	protected function truncate(string ...$tables): void
	{
		$pdo = Database::getInstance();
		$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
		foreach ($tables as $t) {
			$pdo->exec('TRUNCATE TABLE `' . Database::table($t) . '`');
		}
		$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
	}

	/** Insert a minimal file row directly (bypassing the upload path) for file-scoped tests. */
	protected function insertFile(string $id, ?int $userId = null, array $overrides = []): void
	{
		$pdo = Database::getInstance();
		$cols = array_merge([
			'id' => $id,
			'original_name' => 'test.bin',
			'mime_type' => 'application/octet-stream',
			'size' => 123,
			'delete_token' => password_hash('tok', PASSWORD_DEFAULT),
			'uploaded_at' => time(),
			'downloads' => 0,
			'user_id' => $userId,
		], $overrides);
		$fields = implode(', ', array_map(fn($c) => "`$c`", array_keys($cols)));
		$ph = implode(', ', array_fill(0, count($cols), '?'));
		$pdo->prepare("INSERT INTO `" . Database::table('files') . "` ($fields) VALUES ($ph)")
			->execute(array_values($cols));
	}
}
