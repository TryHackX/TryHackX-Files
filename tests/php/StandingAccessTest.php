<?php

if (!defined('APP_ROOT')) {
	define('APP_ROOT', PROJECT_ROOT . '/src');
}
require_once PROJECT_ROOT . '/src/includes/api/ApiSupport.php';
require_once PROJECT_ROOT . '/src/includes/api/FileController.php';

/**
 * API keys and webhooks are standing access to an account: a key uploads and deletes as it,
 * a webhook hears about every upload. C-F14: a session restored from a stolen cookie could add
 * either without the password; both now need a recently typed one (the panel asks for it and
 * repeats the request).
 */
final class StandingAccessTest extends RepoTestCase
{
	private int $userId = 0;

	protected function setUp(): void
	{
		$this->truncate('webhook_deliveries', 'webhooks', 'api_keys', 'users');
		$pdo = Database::getInstance();
		$pdo->prepare(
			'INSERT INTO `' . Database::table('users') . '`
			 (`username`, `email`, `password_hash`, `role`, `is_active`, `created_at`) VALUES (?, ?, ?, ?, 1, ?)'
		)->execute(['standing', 'standing@example.test', 'x', 'user', time()]);
		$this->userId = (int) $pdo->lastInsertId();
	}

	protected function tearDown(): void
	{
		$_SESSION = [];
	}

	public function testAKeyOrAWebhookNeedsARecentlyTypedPassword(): void
	{
		$_SESSION = ['user_id' => $this->userId, 'recent_auth_at' => time() - 3600];
		foreach (['handleUserCreateApiKey', 'handleUserCreateWebhook'] as $handler) {
			ob_start();
			FileController::$handler();
			$answer = json_decode((string) ob_get_clean(), true);
			$this->assertFalse($answer['success'] ?? true, $handler);
			$this->assertSame('recent_auth_required', $answer['code'] ?? null, $handler);
		}
		$this->assertSame(0, Database::countUserApiKeys($this->userId));
		$this->assertSame(0, Database::countUserWebhooks($this->userId));
	}
}
