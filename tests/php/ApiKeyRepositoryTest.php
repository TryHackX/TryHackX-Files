<?php

/**
 * ApiKeyRepository: per-user API keys (only the SHA-256 is stored; resolve hashes the
 * presented key and looks it up, accepting a raw key or "Bearer <key>").
 */
final class ApiKeyRepositoryTest extends RepoTestCase
{
	protected function setUp(): void
	{
		$this->truncate('api_keys', 'upload_tokens', 'download_tokens', 'recovery_tokens', 'users');
		Database::invalidateSettingsCache();
	}

	private function makeUser(string $name = 'apiuser'): int
	{
		$result = Database::registerUser($name, $name . '@example.test', 'Passw0rd!');
		$this->assertTrue($result['success']);
		return (int) Database::getUserByEmailOrUsername($name)['id'];
	}

	private function makeKey(int $userId, string $raw, string $label = 'k'): int
	{
		$id = Database::createApiKey($userId, hash('sha256', $raw), substr($raw, 0, 11), $label);
		$this->assertIsInt($id);
		return $id;
	}

	public function testCreateListCount(): void
	{
		$userId = $this->makeUser();
		$this->makeKey($userId, 'fh_' . str_repeat('a', 48), 'laptop');
		$this->assertSame(1, Database::countUserApiKeys($userId));
		$keys = Database::getUserApiKeys($userId);
		$this->assertCount(1, $keys);
		$this->assertSame('laptop', $keys[0]['label']);
	}

	public function testResolveAcceptsRawAndBearer(): void
	{
		$userId = $this->makeUser();
		$raw = 'fh_' . bin2hex(random_bytes(24));
		$this->makeKey($userId, $raw);
		$this->assertSame($userId, Database::resolveApiKey($raw));
		$this->assertSame($userId, Database::resolveApiKey('Bearer ' . $raw));
		$this->assertNull(Database::resolveApiKey('fh_not_a_real_key'));
		$this->assertNull(Database::resolveApiKey(''));
	}

	public function testRevokeIsScopedToOwner(): void
	{
		$userId = $this->makeUser();
		$raw = 'fh_' . bin2hex(random_bytes(24));
		$id = $this->makeKey($userId, $raw);

		$this->assertFalse(Database::revokeApiKey($id, 999)); // not the owner
		$this->assertTrue(Database::revokeApiKey($id, $userId));
		$this->assertNull(Database::resolveApiKey($raw));
	}

	public function testResolveRejectsInactiveOwnerEvenIfKeyRowStillExists(): void
	{
		$userId = $this->makeUser();
		$raw = 'fh_' . bin2hex(random_bytes(24));
		$this->makeKey($userId, $raw);

		$pdo = Database::getInstance();
		$pdo->prepare("UPDATE `" . Database::table('users') . "` SET `is_active` = 0 WHERE `id` = ?")
			->execute([$userId]);

		$this->assertNull(Database::resolveApiKey($raw));
	}
}
