<?php

use PHPUnit\Framework\TestCase;

/**
 * While the database is down, one request dials it once, not once per repository it touches.
 * The real test database is up, so a refusal is simulated by marking one just now.
 */
final class DatabaseConnectionTest extends TestCase
{
	private function setPrivate(string $name, mixed $value): void
	{
		$property = new ReflectionProperty(Database::class, $name);
		$property->setValue(null, $value);
	}

	protected function tearDown(): void
	{
		// Whatever happened above, later tests get a working connection.
		Database::resetInstance();
		$this->assertInstanceOf(PDO::class, Database::getInstance());
	}

	public function testARefusedConnectionIsBelievedForAFewSeconds(): void
	{
		$this->assertInstanceOf(PDO::class, Database::getInstance());

		// The database just refused: the next callers in this request do not dial it again.
		$this->setPrivate('instance', null);
		$this->setPrivate('connectFailedAt', microtime(true));
		$this->assertNull(Database::getInstance());
		$this->assertNull(Database::getInstance());

		// A few seconds later (a long-running worker), it is tried again and the mark cleared.
		$this->setPrivate('connectFailedAt', microtime(true) - 10);
		$this->assertInstanceOf(PDO::class, Database::getInstance());
		$this->assertNull((new ReflectionProperty(Database::class, 'connectFailedAt'))->getValue());
	}

	public function testResetForgetsTheRefusal(): void
	{
		$this->setPrivate('instance', null);
		$this->setPrivate('connectFailedAt', microtime(true));
		Database::resetInstance();
		$this->assertInstanceOf(PDO::class, Database::getInstance());
	}

	/** A storage root that cannot be created is reported to the log, not to every visitor. */
	public function testAMissingStorageRootDoesNotPrintItsPathToVisitors(): void
	{
		$config = (string) file_get_contents(PROJECT_ROOT . '/src/config.php');
		$start = strpos($config, "foreach (['uploads' => UPLOADS_DIR, 'data' => DATA_DIR]");
		$this->assertNotFalse($start);
		$block = substr($config, $start, 900);
		$this->assertStringContainsString('error_log($storageProblem);', $block);
		$webExit = substr($block, (int) strpos($block, 'http_response_code(500);'));
		$this->assertStringNotContainsString('$dir', $webExit, 'the web answer names no path');
	}
}
