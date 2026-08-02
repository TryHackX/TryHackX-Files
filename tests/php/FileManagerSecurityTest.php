<?php

/**
 * Filesystem boundary tests for administrative deletion.
 *
 * The production uploads directory can contain real data on a developer machine, so every
 * fixture uses a unique, strictly valid id and tearDown removes only that exact directory.
 */
final class FileManagerSecurityTest extends RepoTestCase
{
	private array $ids = [];

	protected function setUp(): void
	{
		$this->truncate('reports', 'collection_files', 'files');
	}

	protected function tearDown(): void
	{
		foreach ($this->ids as $id) {
			$dir = UPLOADS_DIR . '/' . $id;
			if (is_dir($dir)) {
				foreach (scandir($dir) ?: [] as $entry) {
					if ($entry !== '.' && $entry !== '..') {
						@unlink($dir . '/' . $entry);
					}
				}
				@rmdir($dir);
			}
			@unlink(DATA_DIR . '/thumbs/' . $id . '.jpg');
		}
	}

	private function fixtureId(string $suffix): string
	{
		$id = 'fmsec_' . $suffix . '_' . substr(hash('sha256', uniqid('', true)), 0, 10);
		$this->ids[] = $id;
		return $id;
	}

	private function createArtifact(string $id): string
	{
		$dir = UPLOADS_DIR . '/' . $id;
		if (!is_dir($dir)) {
			mkdir($dir, 0777, true);
		}
		$path = $dir . '/payload.bin';
		file_put_contents($path, 'fixture');
		return $path;
	}

	public function testFileIdRejectsEveryPathForm(): void
	{
		foreach ([
			'../config',
			'..\\config',
			'/absolute',
			'C:\\windows',
			'with.dot',
			"line\nbreak",
			str_repeat('a', 33),
		] as $id) {
			$this->assertFalse(FileManager::isValidFileId($id), $id);
		}

		$this->assertTrue(FileManager::isValidFileId('abcDEF0123_-'));
	}

	public function testInlineAllowlistRejectsActiveDocumentFormats(): void
	{
		foreach (['image/jpeg', 'image/png', 'video/mp4', 'audio/mpeg', 'application/pdf', 'text/plain'] as $mime) {
			$this->assertTrue(FileManager::isInlinePreviewAllowed($mime), $mime);
		}
		foreach ([
			'text/html',
			'application/xhtml+xml',
			'image/svg+xml',
			'application/xml',
			'text/xml',
			'application/javascript',
			'text/plain; charset=UTF-8',
		] as $mime) {
			if ($mime === 'text/plain; charset=UTF-8') {
				$this->assertTrue(FileManager::isInlinePreviewAllowed($mime), $mime);
			} else {
				$this->assertFalse(FileManager::isInlinePreviewAllowed($mime), $mime);
			}
		}

		$this->assertTrue(FileManager::isEmbeddableImage('image/webp'));
		$this->assertFalse(FileManager::isEmbeddableImage('image/svg+xml'));
		$this->assertFalse(FileManager::isEmbeddableImage('text/html'));
	}

	public function testSingleRangeParserCoversClosedOpenSuffixAndInvalidRanges(): void
	{
		$this->assertSame(
			['status' => 200, 'start' => 0, 'end' => 99],
			FileManager::parseByteRange(null, 100)
		);
		$this->assertSame(
			['status' => 206, 'start' => 10, 'end' => 20],
			FileManager::parseByteRange('bytes=10-20', 100)
		);
		$this->assertSame(
			['status' => 206, 'start' => 90, 'end' => 99],
			FileManager::parseByteRange('bytes=-10', 100)
		);
		$this->assertSame(
			['status' => 206, 'start' => 10, 'end' => 99],
			FileManager::parseByteRange('bytes=10-', 100)
		);
		$this->assertSame(416, FileManager::parseByteRange('bytes=100-', 100)['status']);
		$this->assertSame(416, FileManager::parseByteRange('bytes=50-20', 100)['status']);
		$this->assertSame(416, FileManager::parseByteRange('bytes=nope', 100)['status']);
		$this->assertSame(200, FileManager::parseByteRange('bytes=0-1,4-5', 100)['status']);
	}

	public function testAdminDeleteDoesNotTouchArtifactsWithoutDatabaseRow(): void
	{
		$id = $this->fixtureId('orphan');
		$artifact = $this->createArtifact($id);

		$this->assertFalse(FileManager::deleteFileAdmin($id));
		$this->assertFileExists($artifact);
	}

	public function testAdminDeleteRemovesOnlyKnownValidFile(): void
	{
		$id = $this->fixtureId('known');
		$artifact = $this->createArtifact($id);
		$thumbDir = DATA_DIR . '/thumbs';
		if (!is_dir($thumbDir)) {
			mkdir($thumbDir, 0777, true);
		}
		$thumb = $thumbDir . '/' . $id . '.jpg';
		file_put_contents($thumb, 'thumb');
		$this->insertFile($id);

		$this->assertTrue(FileManager::deleteFileAdmin($id));
		$this->assertFileDoesNotExist($artifact);
		$this->assertDirectoryDoesNotExist(UPLOADS_DIR . '/' . $id);
		$this->assertFileDoesNotExist($thumb);
	}

	public function testStorageResolverRepairsLegacyDirectoryUsingDatabaseName(): void
	{
		$id = $this->fixtureId('manifest');
		$dir = UPLOADS_DIR . '/' . $id;
		mkdir($dir, 0777, true);
		file_put_contents($dir . '/000-decoy.bin', 'decoy!!');
		$payload = $dir . '/payload.bin';
		file_put_contents($payload, 'payload');
		$this->insertFile($id, null, ['original_name' => 'payload.bin', 'size' => 7]);

		$file = FileManager::getFile($id);

		$this->assertIsArray($file);
		$this->assertSame(realpath($payload), $file['path']);
		$manifestPath = $dir . '/' . StorageManifest::FILE_NAME;
		$this->assertFileExists($manifestPath);
		$manifest = json_decode((string) file_get_contents($manifestPath), true);
		$this->assertSame('payload.bin', $manifest['name'] ?? null);
		$this->assertSame(hash('sha256', 'payload'), $manifest['sha256'] ?? null);

		// Request-time resolution avoids hashing every large response, while the explicit
		// integrity mode detects same-size corruption for maintenance jobs.
		file_put_contents($payload, 'PAYLOAD');
		$this->assertNull(StorageManifest::resolve(
			UPLOADS_DIR,
			$id,
			'payload.bin',
			7,
			true,
			false
		));
	}

	public function testManifestCannotRedirectResolverToAnotherEntry(): void
	{
		$id = $this->fixtureId('redirect');
		$dir = UPLOADS_DIR . '/' . $id;
		mkdir($dir, 0777, true);
		file_put_contents($dir . '/payload.bin', 'payload');
		file_put_contents($dir . '/decoy.bin', 'payload');
		file_put_contents($dir . '/' . StorageManifest::FILE_NAME, json_encode([
			'version' => 1,
			'name' => 'decoy.bin',
			'size' => 7,
			'sha256' => hash('sha256', 'payload'),
		]));
		$this->insertFile($id, null, ['original_name' => 'payload.bin', 'size' => 7]);

		$this->assertNull(FileManager::getFile($id));
	}
}
