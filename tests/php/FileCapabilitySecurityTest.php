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
		$this->truncate('reports', 'collection_files', 'collections', 'file_deletion_queue', 'file_quarantine', 'files', 'users');
		$_SESSION['delete_link_nonces'] = [];
	}

	/** A real account row — `files.user_id` carries a foreign key onto it. */
	private function user(string $name): int
	{
		$pdo = Database::getInstance();
		$pdo->prepare(
			'INSERT INTO `' . Database::table('users') . '`
			 (username, email, password_hash, role, is_active, created_at) VALUES (?,?,?,?,1,?)'
		)->execute([$name, $name . '@example.test', 'x', 'user', time()]);
		return (int) $pdo->lastInsertId();
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

	/* ---- action=delete: who may delete, and on what proof ---- */

	private function rowCount(string $id): int
	{
		$stmt = Database::getInstance()->prepare(
			'SELECT COUNT(*) FROM `' . Database::table('files') . '` WHERE `id` = ?'
		);
		$stmt->execute([$id]);
		return (int) $stmt->fetchColumn();
	}

	/** Drive handleDelete() as a POST from `$sessionUser` (null = signed out); decoded reply. */
	private function callDelete(array $post, ?int $sessionUser = null): array
	{
		$method = $_SERVER['REQUEST_METHOD'] ?? null;
		$session = $_SESSION;

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = $post;
		unset($_SESSION['user_id']);
		if ($sessionUser !== null) {
			$_SESSION['user_id'] = $sessionUser;
		}

		ob_start();
		try {
			FileController::handleDelete();
		} finally {
			$body = (string) ob_get_clean();
			$_POST = [];
			$_SESSION = $session;
			if ($method === null) {
				unset($_SERVER['REQUEST_METHOD']);
			} else {
				$_SERVER['REQUEST_METHOD'] = $method;
			}
		}

		return is_array($decoded = json_decode($body, true)) ? $decoded : [];
	}

	/**
	 * The "My files" regression: the panel has no delete token to send, because the stored one
	 * is a bcrypt hash. Owning the row is the proof.
	 */
	public function testOwnerDeletesOwnFileWithoutPresentingADeleteToken(): void
	{
		$owner = $this->user('owner');
		$id = $this->id('mine');
		$artifact = $this->artifact($id);
		$this->insertFile($id, $owner, ['delete_token' => password_hash('never-sent', PASSWORD_DEFAULT)]);

		$reply = $this->callDelete(['id' => $id], $owner);

		$this->assertTrue($reply['success'] ?? false);
		$this->assertSame(0, $this->rowCount($id));
		$this->assertFileDoesNotExist($artifact);
	}

	/** Being signed in is not the proof — being signed in *as the owner* is. */
	public function testOtherAccountCannotDeleteSomeoneElsesFileWithoutTheToken(): void
	{
		$owner = $this->user('owner');
		$stranger = $this->user('stranger');
		$id = $this->id('theirs');
		$artifact = $this->artifact($id);
		$this->insertFile($id, $owner, ['delete_token' => password_hash('right-secret', PASSWORD_DEFAULT)]);

		$this->assertFalse($this->callDelete(['id' => $id], $stranger)['success'] ?? false);
		$this->assertFalse($this->callDelete(['id' => $id, 'token' => 'guessed'], $stranger)['success'] ?? false);
		$this->assertSame(1, $this->rowCount($id));
		$this->assertFileExists($artifact);
	}

	/** A guest upload has no owner to fall back on, so the capability is still required. */
	public function testGuestUploadStillRequiresTheCapabilityToken(): void
	{
		$id = $this->id('guest');
		$artifact = $this->artifact($id);
		$this->insertFile($id, null, ['delete_token' => password_hash('right-secret', PASSWORD_DEFAULT)]);

		$this->assertFalse($this->callDelete(['id' => $id])['success'] ?? false);
		$this->assertFalse($this->callDelete(['id' => $id, 'token' => 'wrong-secret'])['success'] ?? false);
		$this->assertSame(1, $this->rowCount($id));

		$this->assertTrue($this->callDelete(['id' => $id, 'token' => 'right-secret'])['success'] ?? false);
		$this->assertSame(0, $this->rowCount($id));
		$this->assertFileDoesNotExist($artifact);
	}

	/**
	 * A signed-in account must not inherit the guest path's reach over rows it does not own,
	 * and `user_id = NULL` must never be read as "owned by nobody, so owned by me".
	 */
	public function testSignedInAccountDoesNotInheritGuestUploads(): void
	{
		$member = $this->user('member');
		$id = $this->id('anon');
		$this->insertFile($id, null, ['delete_token' => password_hash('right-secret', PASSWORD_DEFAULT)]);

		$this->assertFalse($this->callDelete(['id' => $id], $member)['success'] ?? false);
		$this->assertSame(1, $this->rowCount($id));
	}

	/**
	 * `action=delete` used to issue its own DELETE against `files` and clean up only reports,
	 * so a deleted file left its collection memberships behind — there is no foreign key on
	 * `collection_files.file_id` to catch that — and the bytes were purged on a best-effort
	 * basis with the result discarded. It now runs the same durable path as the delete link.
	 */
	public function testDeleteClearsEveryReferenceToTheFile(): void
	{
		$owner = $this->user('owner');
		$id = $this->id('linked');
		$artifact = $this->artifact($id);
		$this->insertFile($id, $owner, ['delete_token' => password_hash('never-sent', PASSWORD_DEFAULT)]);

		$pdo = Database::getInstance();
		$collection = 'coll_' . substr(bin2hex(random_bytes(8)), 0, 12);
		$pdo->prepare(
			'INSERT INTO `' . Database::table('collections') . '`
			 (id, name, user_id, delete_token, created_at) VALUES (?,?,?,?,?)'
		)->execute([$collection, 'bundle', $owner, password_hash('t', PASSWORD_DEFAULT), time()]);
		$pdo->prepare(
			'INSERT INTO `' . Database::table('collection_files') . '`
			 (collection_id, file_id, position) VALUES (?,?,0)'
		)->execute([$collection, $id]);
		$pdo->prepare(
			'INSERT INTO `' . Database::table('reports') . '`
			 (file_id, reporter_name, reporter_email, report_title, created_at, ip_address)
			 VALUES (?,?,?,?,?,?)'
		)->execute([$id, 'someone', 'r@example.test', 'complaint', time(), '203.0.113.9']);

		$countIn = function (string $table) use ($pdo, $id): int {
			$stmt = $pdo->prepare(
				'SELECT COUNT(*) FROM `' . Database::table($table) . '` WHERE `file_id` = ?'
			);
			$stmt->execute([$id]);
			return (int) $stmt->fetchColumn();
		};
		$this->assertSame(1, $countIn('collection_files'));
		$this->assertSame(1, $countIn('reports'));

		$this->assertTrue($this->callDelete(['id' => $id], $owner)['success'] ?? false);

		$this->assertSame(0, $this->rowCount($id));
		$this->assertSame(0, $countIn('collection_files'), 'collection membership was orphaned');
		$this->assertSame(0, $countIn('reports'));
		$this->assertFileDoesNotExist($artifact);

		// The collection row itself is not the file's to remove.
		$stmt = $pdo->prepare('SELECT COUNT(*) FROM `' . Database::table('collections') . '` WHERE `id` = ?');
		$stmt->execute([$collection]);
		$this->assertSame(1, (int) $stmt->fetchColumn());

		$pdo->prepare('DELETE FROM `' . Database::table('collections') . '` WHERE `id` = ?')
			->execute([$collection]);
	}

	/**
	 * The "My files" JSON must not carry the row's `delete_token` column. It is a bcrypt hash,
	 * so it is useless to the client, and on a pre-bcrypt row it would be the live capability.
	 */
	public function testOwnedRowShapeNeverCarriesTheDeleteToken(): void
	{
		$owner = $this->user('owner');
		$id = $this->id('shape');
		$this->insertFile($id, $owner, ['delete_token' => password_hash('never-sent', PASSWORD_DEFAULT)]);

		$res = FileManager::browse(['owner_id' => $owner, 'owner_fields' => true, 'unpaged' => true]);

		$this->assertCount(1, $res['files']);
		$this->assertSame($id, $res['files'][0]['id']);
		$this->assertArrayNotHasKey('deleteToken', $res['files'][0]);
		$this->assertArrayNotHasKey('delete_token', $res['files'][0]);
		$this->assertStringNotContainsString('$2y$', json_encode($res['files']));
	}
}
