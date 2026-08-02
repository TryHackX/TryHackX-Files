<?php

/**
 * SettingsRepository: key/value settings with request+file cache and encrypted secrets (S12).
 * Exercised through the public Database::* accessors (the delegators callers actually use).
 */
final class SettingsRepositoryTest extends RepoTestCase
{
	private function cachePath(): string
	{
		return DATA_DIR . DIRECTORY_SEPARATOR . 'settings.cache.json';
	}

	protected function setUp(): void
	{
		if (is_dir($this->cachePath())) {
			@rmdir($this->cachePath());
		}
		Database::invalidateSettingsCache();
	}

	protected function tearDown(): void
	{
		if (is_dir($this->cachePath())) {
			@rmdir($this->cachePath());
		}
	}

	public function testGetReturnsDefaultForMissingKey(): void
	{
		$this->assertSame('fallback', Database::getSetting('no_such_key_xyz', 'fallback'));
	}

	public function testSetThenGetRoundTrip(): void
	{
		$this->assertTrue(Database::setSetting('unit_test_key', 'hello'));
		Database::invalidateSettingsCache();
		$this->assertSame('hello', Database::getSetting('unit_test_key'));
	}

	public function testGetAllSettingsIsArray(): void
	{
		Database::setSetting('unit_all_probe', '1');
		Database::invalidateSettingsCache();
		$all = Database::getAllSettings();
		$this->assertIsArray($all);
		$this->assertArrayHasKey('unit_all_probe', $all);
	}

	public function testSecretIsEncryptedAtRestButRoundTrips(): void
	{
		Database::setSecretSetting('unit_secret', 'super-secret-value');
		Database::invalidateSettingsCache();

		// Public accessor decrypts back to the original.
		$this->assertSame('super-secret-value', Database::getSecretSetting('unit_secret'));

		// The stored column must NOT be the plaintext (S12: encrypted at rest).
		$pdo = Database::getInstance();
		$stmt = $pdo->prepare("SELECT `setting_value` FROM `" . Database::table('settings') . "` WHERE `setting_key` = ?");
		$stmt->execute(['unit_secret']);
		$stored = (string) $stmt->fetchColumn();
		$this->assertNotSame('super-secret-value', $stored);
		$this->assertStringStartsWith('enc:', $stored);
	}

	public function testPersistedCacheIsVersionedDataOnlyJsonWithRestrictedPermissions(): void
	{
		Database::setSetting('unit_json_probe', 'zażółć');
		Database::invalidateSettingsCache();
		$this->assertSame('zażółć', Database::getSetting('unit_json_probe'));

		$path = $this->cachePath();
		$this->assertFileExists($path);
		$raw = (string) file_get_contents($path);
		$this->assertStringNotContainsString('<?php', $raw);
		$payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(1, $payload['format']);
		$this->assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/D', $payload['generation']);
		$this->assertIsInt($payload['generated_at']);
		$this->assertSame('zażółć', $payload['settings']['unit_json_probe']);

		if (DIRECTORY_SEPARATOR !== '\\') {
			clearstatcache(true, $path);
			$this->assertSame(0600, fileperms($path) & 0777);
		}
	}

	public function testCacheGenerationChangesAfterACommittedSettingWrite(): void
	{
		Database::setSetting('unit_generation_probe', 'before');
		Database::invalidateSettingsCache();
		Database::getSetting('unit_generation_probe');
		$before = json_decode((string) file_get_contents($this->cachePath()), true, 512, JSON_THROW_ON_ERROR);

		Database::setSetting('unit_generation_probe', 'after');
		$this->assertFileDoesNotExist($this->cachePath());
		$this->assertSame('after', Database::getSetting('unit_generation_probe'));
		$after = json_decode((string) file_get_contents($this->cachePath()), true, 512, JSON_THROW_ON_ERROR);

		$this->assertNotSame($before['generation'], $after['generation']);
	}

	public function testMalformedJsonIsRejectedAndRebuiltFromDatabase(): void
	{
		Database::setSetting('unit_corrupt_probe', 'database-wins');
		Database::invalidateSettingsCache();
		file_put_contents($this->cachePath(), '{"format":1,"settings":');

		$this->assertSame('database-wins', Database::getSetting('unit_corrupt_probe'));
		$payload = json_decode(
			(string) file_get_contents($this->cachePath()),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		$this->assertSame('database-wins', $payload['settings']['unit_corrupt_probe']);
	}

	public function testLegacyPhpCacheIsDeletedWithoutExecution(): void
	{
		Database::setSetting('unit_legacy_probe', 'database-value');
		Database::invalidateSettingsCache();
		$marker = DATA_DIR . DIRECTORY_SEPARATOR . 'legacy-cache-executed';
		$legacy = DATA_DIR . DIRECTORY_SEPARATOR . 'settings.cache.php';
		file_put_contents(
			$legacy,
			'<?php file_put_contents(' . var_export($marker, true) . ', "yes"); return [];'
		);

		$this->assertSame('database-value', Database::getSetting('unit_legacy_probe'));
		$this->assertFileDoesNotExist($legacy);
		$this->assertFileDoesNotExist($marker);
	}

	public function testInvalidationReportsAnUnexpectedCachePathInsteadOfIgnoringIt(): void
	{
		Database::invalidateSettingsCache();
		$this->assertTrue(mkdir($this->cachePath()));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('not a regular file');
		Database::invalidateSettingsCache();
	}

	public function testMigratedBlocklistContainsBrowserActiveDocumentFormats(): void
	{
		$blocked = array_map(
			'trim',
			explode(',', (string) Database::getSetting('blocked_extensions', ''))
		);
		foreach (['html', 'htm', 'xhtml', 'svg', 'shtml', 'xml'] as $extension) {
			$this->assertContains($extension, $blocked);
		}
	}
}
