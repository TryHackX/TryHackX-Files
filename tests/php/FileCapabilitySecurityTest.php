<?php

if (!defined('APP_ROOT')) {
	define('APP_ROOT', PROJECT_ROOT . '/src');
}
if (!defined('APP_VERSION')) {
	define('APP_VERSION', 'test');
}
require_once PROJECT_ROOT . '/src/includes/Lang.php';
require_once PROJECT_ROOT . '/src/includes/api/FileController.php';

final class FileCapabilitySecurityTest extends RepoTestCase
{
	private array $ids = [];

	protected function setUp(): void
	{
		$this->truncate('reports', 'collection_files', 'files');
		$_SESSION['delete_link_nonces'] = [];
	}

	protected function tearDown(): void
	{
		$_SESSION['delete_link_nonces'] = [];
		foreach ($this->ids as $id) {
			$dir = UPLOADS_DIR . '/' . $id;
			if (!is_dir($dir)) {
				continue;
			}
			foreach (scandir($dir) ?: [] as $entry) {
				if ($entry !== '.' && $entry !== '..') {
					@unlink($dir . '/' . $entry);
				}
			}
			@rmdir($dir);
		}
	}

	private function id(string $label): string
	{
		$id = 'cap_' . $label . '_' . substr(bin2hex(random_bytes(8)), 0, 12);
		$this->ids[] = $id;
		return $id;
	}

	private function artifact(string $id): string
	{
		$dir = UPLOADS_DIR . '/' . $id;
		if (!is_dir($dir)) {
			mkdir($dir, 0777, true);
		}
		$path = $dir . '/payload.bin';
		file_put_contents($path, 'capability fixture');
		return $path;
	}

	private function callPrivate(string $method, mixed ...$args): mixed
	{
		$reflection = new ReflectionMethod(FileController::class, $method);
		return $reflection->invoke(null, ...$args);
	}

	public function testFreshFileCannotBeRevertedWithoutItsDeleteCapability(): void
	{
		$id = $this->id('fresh');
		$artifact = $this->artifact($id);
		$this->insertFile($id, null, [
			'delete_token' => password_hash('right-secret', PASSWORD_DEFAULT),
			'uploaded_at' => time(),
		]);

		$this->callPrivate('revertPayload', $id . ':wrong-secret');

		$stmt = Database::getInstance()->prepare(
			'SELECT COUNT(*) FROM `' . Database::table('files') . '` WHERE `id` = ?'
		);
		$stmt->execute([$id]);
		$this->assertSame(1, (int) $stmt->fetchColumn());
		$this->assertFileExists($artifact);
	}

	public function testRevertWithCorrectCapabilityDeletesExactFile(): void
	{
		$id = $this->id('owned');
		$artifact = $this->artifact($id);
		$this->insertFile($id, null, [
			'delete_token' => password_hash('right-secret', PASSWORD_DEFAULT),
			'uploaded_at' => time(),
		]);

		$this->callPrivate('revertPayload', $id . ':right-secret');

		$stmt = Database::getInstance()->prepare(
			'SELECT COUNT(*) FROM `' . Database::table('files') . '` WHERE `id` = ?'
		);
		$stmt->execute([$id]);
		$this->assertSame(0, (int) $stmt->fetchColumn());
		$this->assertFileDoesNotExist($artifact);
	}

	public function testPublicInfoDtoIsAnExplicitAllowlistWithoutIpOrSecrets(): void
	{
		$dto = $this->callPrivate('publicFileDto', [
			'id' => 'abc123',
			'name' => 'photo.jpg',
			'mimeType' => 'image/jpeg',
			'size' => 42,
			'uploadedAt' => 123,
			'downloads' => 7,
			'previewType' => 'image',
			'uploadedIP' => '198.51.100.77',
			'deleteToken' => '$2y$secret',
			'path' => 'C:\\private\\payload',
			'userId' => 99,
		]);

		$this->assertSame(
			['id', 'name', 'mimeType', 'size', 'uploadedAt', 'downloads', 'previewType'],
			array_keys($dto)
		);
		$this->assertArrayNotHasKey('uploadedIP', $dto);
		$this->assertArrayNotHasKey('deleteToken', $dto);
		$this->assertArrayNotHasKey('path', $dto);
	}

	public function testDeleteLinkNonceIsBoundExpiresAndCanBeConsumedOnlyOnce(): void
	{
		$nonce = $this->callPrivate('issueDeleteLinkNonce', 'file_a', 'capability-a');
		$this->assertFalse($this->callPrivate('consumeDeleteLinkNonce', $nonce, 'file_b', 'capability-a'));
		$this->assertFalse($this->callPrivate('consumeDeleteLinkNonce', $nonce, 'file_a', 'capability-b'));
		$this->assertTrue($this->callPrivate('consumeDeleteLinkNonce', $nonce, 'file_a', 'capability-a'));
		$this->assertFalse($this->callPrivate('consumeDeleteLinkNonce', $nonce, 'file_a', 'capability-a'));

		$expired = $this->callPrivate('issueDeleteLinkNonce', 'file_a', 'capability-a');
		$_SESSION['delete_link_nonces'][$expired]['expires'] = time() - 1;
		$this->assertFalse($this->callPrivate('consumeDeleteLinkNonce', $expired, 'file_a', 'capability-a'));
	}

	public function testDeleteLinkTemplateUsesPostFormAndNeverAConfirmGetLink(): void
	{
		$title = 'Delete';
		$heading = 'Confirm';
		$message = 'Confirm deletion';
		$icon = 'fa-trash';
		$state = 'ask';
		$deleteForm = [
			'action' => 'https://files.example/api.php?action=delete_link',
			'id' => 'abc123',
			'token' => 'secret-token',
			'nonce' => str_repeat('a', 64),
		];

		ob_start();
		require PROJECT_ROOT . '/src/includes/delete_link_page.php';
		$html = (string) ob_get_clean();

		$this->assertStringContainsString('<form method="post"', $html);
		$this->assertStringContainsString('name="nonce"', $html);
		$this->assertStringNotContainsString('confirm=1', $html);
		$this->assertStringNotContainsString('<a class="btn btn-danger"', $html);
	}
}
