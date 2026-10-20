<?php

/**
 * WebhookRepository: user endpoints + delivery enqueue (one row per subscribed webhook).
 */
final class WebhookRepositoryTest extends RepoTestCase
{
	protected function setUp(): void
	{
		$this->truncate('webhook_deliveries', 'webhooks', 'users');
		$stmt = Database::getInstance()->prepare(
			'INSERT INTO `' . Database::table('users') . '`
			 (`id`,`username`,`email`,`password_hash`,`role`,`is_active`,`created_at`)
			 VALUES (?,?,?,?,?,?,?)'
		);
		foreach ([3, 8, 9] as $userId) {
			$stmt->execute([
				$userId,
				'webhook-' . $userId,
				'webhook' . $userId . '@example.test',
				'x',
				'user',
				1,
				time(),
			]);
		}
	}

	public function testCreateListCount(): void
	{
		$id = Database::createWebhook(3, 'https://example.com/hook', 'secret', 'upload,download');
		$this->assertIsInt($id);
		$this->assertSame(1, Database::countUserWebhooks(3));
		$hooks = Database::getUserWebhooks(3);
		$this->assertSame('https://example.com/hook', $hooks[0]['url']);
	}

	public function testEnqueueOnlyForSubscribedEvent(): void
	{
		Database::createWebhook(8, 'https://example.com/h', 'sec', 'upload');

		$this->assertSame(1, Database::enqueueWebhookEvent(8, 'upload', ['file' => 'x']));
		$this->assertSame(0, Database::enqueueWebhookEvent(8, 'download', ['file' => 'x'])); // not subscribed
		$this->assertSame(0, Database::enqueueWebhookEvent(8, 'not_an_event', []));          // invalid event

		$pdo = Database::getInstance();
		$n = (int) $pdo->query("SELECT COUNT(*) FROM `" . Database::table('webhook_deliveries') . "`")->fetchColumn();
		$this->assertSame(1, $n);
	}

	public function testDeleteRemovesHookAndDeliveries(): void
	{
		$id = Database::createWebhook(9, 'https://example.com/h', 'sec', 'upload');
		Database::enqueueWebhookEvent(9, 'upload', []);

		$this->assertFalse(Database::deleteWebhook($id, 111)); // wrong owner
		$this->assertTrue(Database::deleteWebhook($id, 9));

		$pdo = Database::getInstance();
		$this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM `" . Database::table('webhooks') . "`")->fetchColumn());
		$this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM `" . Database::table('webhook_deliveries') . "`")->fetchColumn());
	}

	/**
	 * C-F14: a webhook reports every new upload of the account to its URL, and one added from a
	 * hijacked session survived the owner's password change. A password change — the owner's
	 * own, through a reset link, or set by an administrator — now removes the account's
	 * webhooks with their pending deliveries, as it revokes the API keys. Others keep theirs.
	 */
	public function testAPasswordChangeRemovesTheAccountsWebhooks(): void
	{
		Database::createWebhook(3, 'https://example.com/a', 'sec', 'upload');
		Database::createWebhook(8, 'https://example.com/b', 'sec', 'upload');
		Database::createWebhook(9, 'https://example.com/c', 'sec', 'upload');
		$this->assertSame(1, Database::enqueueWebhookEvent(3, 'upload', ['file' => 'x']));
		$pdo = Database::getInstance();

		$this->assertTrue(UserRepository::updatePassword(3, 'N3w!Str0ng-pass'));
		$this->assertSame(0, Database::countUserWebhooks(3));
		$this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM `" . Database::table('webhook_deliveries') . "`")->fetchColumn());

		$reset = UserRepository::adminUpdate(8, ['password' => 'An0ther!Str0ng-pass'], 9);
		$this->assertTrue($reset['success'], $reset['error'] ?? '');
		$this->assertSame(0, Database::countUserWebhooks(8));
		$this->assertSame(1, Database::countUserWebhooks(9));
	}
}
