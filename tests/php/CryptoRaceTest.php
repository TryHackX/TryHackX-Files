<?php

use PHPUnit\Framework\TestCase;

final class CryptoRaceTest extends TestCase
{
	/** @var list<string> */
	private array $directories = [];

	protected function tearDown(): void
	{
		foreach ($this->directories as $directory) {
			if (!is_dir($directory)) {
				continue;
			}
			foreach (scandir($directory) ?: [] as $entry) {
				if ($entry !== '.' && $entry !== '..') {
					@unlink($directory . DIRECTORY_SEPARATOR . $entry);
				}
			}
			@rmdir($directory);
		}
		$this->directories = [];
	}

	private function directory(): string
	{
		$directory = DATA_DIR . DIRECTORY_SEPARATOR . 'crypto-' . bin2hex(random_bytes(8));
		$this->assertTrue(mkdir($directory, 0700), 'Cannot create crypto test directory.');
		$this->directories[] = $directory;
		return $directory;
	}

	/**
	 * @param list<string> $arguments
	 * @return array{status:int,stdout:string,stderr:string}
	 */
	private function runWorker(array $arguments): array
	{
		$command = escapeshellarg(PHP_BINARY)
			. ' -d xdebug.mode=off -d xdebug.log='
			. ' ' . escapeshellarg(__DIR__ . '/CryptoProcessWorker.php');
		foreach ($arguments as $argument) {
			$command .= ' ' . escapeshellarg($argument);
		}
		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];
		$process = proc_open($command, $descriptors, $pipes);
		$this->assertIsResource($process);
		fclose($pipes[0]);
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$status = proc_close($process);
		return ['status' => $status, 'stdout' => $stdout, 'stderr' => $stderr];
	}

	public function testProductionFailsClosedWithoutConfiguredSecret(): void
	{
		$result = $this->runWorker([$this->directory(), 'production-missing']);

		$this->assertSame(70, $result['status']);
		$this->assertStringContainsString('APP_SECRET_KEY is required', $result['stderr']);
		$this->assertFileDoesNotExist($this->directories[0] . DIRECTORY_SEPARATOR . '.appkey');
	}

	public function testProductionEnvironmentSecretRoundTripsWithoutCreatingAFile(): void
	{
		$directory = $this->directory();
		$secret = bin2hex(random_bytes(32));
		$encrypted = $this->runWorker([$directory, 'production-env', $secret, 'production-value']);

		$this->assertSame(0, $encrypted['status'], $encrypted['stderr']);
		$this->assertStringStartsWith('enc:v1:', $encrypted['stdout']);

		$decrypted = $this->runWorker([
			$directory,
			'production-env',
			$secret,
			$encrypted['stdout'],
		]);
		$this->assertSame(0, $decrypted['status'], $decrypted['stderr']);
		$this->assertSame('production-value', $decrypted['stdout']);
		$this->assertFileDoesNotExist($directory . DIRECTORY_SEPARATOR . '.appkey');
	}

	public function testConcurrentFallbackCreatorsAllUseThePersistedWinner(): void
	{
		$directory = $this->directory();
		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];
		$workers = [];
		for ($i = 0; $i < 8; $i++) {
			$command = escapeshellarg(PHP_BINARY)
				. ' -d xdebug.mode=off -d xdebug.log='
				. ' ' . escapeshellarg(__DIR__ . '/CryptoProcessWorker.php')
				. ' ' . escapeshellarg($directory)
				. ' encrypt-fallback';
			$process = proc_open($command, $descriptors, $pipes);
			$this->assertIsResource($process);
			fclose($pipes[0]);
			$workers[] = [$process, $pipes];
		}

		$encryptedValues = [];
		foreach ($workers as [$process, $pipes]) {
			$stdout = stream_get_contents($pipes[1]);
			$stderr = stream_get_contents($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			$status = proc_close($process);
			$this->assertSame(0, $status, $stderr);
			$this->assertStringStartsWith('enc:v1:', $stdout);
			$encryptedValues[] = $stdout;
		}

		$keyFile = $directory . DIRECTORY_SEPARATOR . '.appkey';
		$this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\R?\z/D', (string) file_get_contents($keyFile));
		foreach ($encryptedValues as $encrypted) {
			$decrypted = $this->runWorker([$directory, 'decrypt-fallback', $encrypted]);
			$this->assertSame(0, $decrypted['status'], $decrypted['stderr']);
			$this->assertSame('race-probe', $decrypted['stdout']);
		}

		if (DIRECTORY_SEPARATOR !== '\\') {
			clearstatcache(true, $keyFile);
			$this->assertSame(0600, fileperms($keyFile) & 0777);
		}
	}

	public function testMalformedPersistedKeyIsNeverSilentlyRegenerated(): void
	{
		$directory = $this->directory();
		$keyFile = $directory . DIRECTORY_SEPARATOR . '.appkey';
		file_put_contents($keyFile, 'broken-key');

		$result = $this->runWorker([$directory, 'encrypt-fallback']);

		$this->assertSame(70, $result['status']);
		$this->assertStringContainsString('invalid format', $result['stderr']);
		$this->assertSame('broken-key', file_get_contents($keyFile));
	}

	public function testLegacyFileKeyCanBeMovedToManagedSecretWithoutChangingItsBytes(): void
	{
		$directory = $this->directory();
		$encrypted = $this->runWorker([$directory, 'encrypt-fallback']);
		$this->assertSame(0, $encrypted['status'], $encrypted['stderr']);
		$legacyHex = trim((string) file_get_contents($directory . DIRECTORY_SEPARATOR . '.appkey'));

		$decrypted = $this->runWorker([
			$directory,
			'production-env',
			'hex:' . $legacyHex,
			$encrypted['stdout'],
		]);
		$this->assertSame(0, $decrypted['status'], $decrypted['stderr']);
		$this->assertSame('race-probe', $decrypted['stdout']);
	}
}
