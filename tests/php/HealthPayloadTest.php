<?php

use PHPUnit\Framework\TestCase;

if (!defined('APP_ROOT')) {
	define('APP_ROOT', PROJECT_ROOT . '/src');
}
if (!defined('APP_VERSION')) {
	define('APP_VERSION', 'test');
}
require_once PROJECT_ROOT . '/src/includes/Lang.php';
require_once PROJECT_ROOT . '/src/includes/api/FileController.php';

/**
 * The public `api.php?action=health` relays the upload server's loopback-only /health. That
 * body names the absolute uploads directory and the config-file state, so the relay must keep
 * only "up or down" — and say nothing about versions either.
 */
final class HealthPayloadTest extends TestCase
{
	public function testRelayKeepsOnlyUpOrDown(): void
	{
		$payload = FileController::publicHealthPayload([
			'status' => 'ok',
			'version' => '9.9.9',
			'schema_version' => 64,
			'uploads_dir' => '/srv/files/uploads',
			'config_exists' => true,
			'database_configured' => true,
			'database_connected' => true,
		], true);

		$this->assertSame(
			[
				'success' => true,
				'php' => ['database_connected' => true],
				'python' => ['status' => 'ok', 'database_connected' => true],
				'python_running' => true,
			],
			$payload
		);
		$this->assertStringNotContainsString('/srv/files', (string) json_encode($payload));
	}

	public function testStoppedUploadServerIsReportedAsDown(): void
	{
		$this->assertSame(
			[
				'success' => true,
				'php' => ['database_connected' => false],
				'python' => null,
				'python_running' => false,
			],
			FileController::publicHealthPayload(null, false)
		);
	}

	public function testUnexpectedSidecarFieldsCannotLeakThroughStatus(): void
	{
		$payload = FileController::publicHealthPayload(['status' => ['nested' => 'x']], true);

		$this->assertSame('unknown', $payload['python']['status'] ?? null);
		$this->assertFalse($payload['python']['database_connected'] ?? true);
	}
}
