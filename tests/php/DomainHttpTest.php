<?php

use PHPUnit\Framework\TestCase;

/**
 * Request-level checks for the file, collection and moderation pages, over real HTTP against a
 * `php -S` instance on the suite's throwaway database (same mechanics as HttpApiTest, which
 * owns the shared .tmp-*-http directories after this class has cleaned them up).
 */
final class DomainHttpTest extends TestCase
{
	private static $proc = null;
	private static string $base = '';
	private static array $cookies = [];
	private static string $csrf = '';
	private static array $ids = [];

	private const HTTP_DIRS = ['/tests/php/.tmp-data-http', '/tests/php/.tmp-uploads-http'];

	public static function setUpBeforeClass(): void
	{
		$pdo = Database::getInstance();
		$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
		foreach (['users', 'files', 'collections', 'collection_files', 'ads', 'rate_limits', 'security_events', 'notifications'] as $t) {
			$pdo->exec('TRUNCATE TABLE `' . Database::table($t) . '`');
		}
		$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
		foreach (self::HTTP_DIRS as $dir) {
			self::resetDirectory(PROJECT_ROOT . $dir);
		}

		// Settings the served process will cache: all of them before it starts.
		Database::setSetting('app_name', 'FileHostTest');
		Database::setSetting('recaptcha_enabled', '0');
		Database::setSetting('ads_enabled', '1');
		Database::setSetting('ads_zones_disabled', '');

		Database::registerUser('httpowner', 'httpowner@example.pl', 'HttpOwner1!');
		Database::registerUser('httpmod', 'httpmod@example.pl', 'HttpMod1!x');
		$pdo->exec("UPDATE `" . Database::table('users') . "` SET `is_active` = 1");
		$moderator = Database::getUserByEmailOrUsername('httpmod');
		$res = Database::adminUpdateUser((int) $moderator['id'], ['role' => 'moderator']);
		if (empty($res['success'])) {
			self::fail('Could not make the HTTP moderator: ' . json_encode($res));
		}
		$owner = Database::getUserByEmailOrUsername('httpowner');

		// D-F9: a name with `+` and `%41` must reach the page exactly as stored.
		self::$ids['plain'] = self::storeFile('a+b %41.png', 'image/png', 'plain-bytes');
		self::$ids['once'] = self::storeFile('once.png', 'image/png', 'one-time-bytes', ['one_time' => 1]);
		self::$ids['locked'] = self::storeFile('locked.png', 'image/png', 'locked-bytes', [
			'password_hash' => password_hash('right-password', PASSWORD_DEFAULT),
		]);
		self::$ids['owned'] = self::storeFile('owned.bin', 'application/octet-stream', 'owned-bytes', [
			'user_id' => (int) $owner['id'],
		]);
		Database::createAdmin('httpboss', 'HttpBoss1!x', 'httpboss@example.pl');
		$pdo->exec("UPDATE `" . Database::table('users') . "` SET `is_active` = 1");
		self::$ids['collection'] = 'c' . bin2hex(random_bytes(6));
		$made = Database::createCollectionWithFiles(
			self::$ids['collection'],
			'Open collection',
			(int) $owner['id'],
			password_hash('t', PASSWORD_DEFAULT),
			[self::$ids['plain'], self::$ids['once']],
			null,
			[],
			2
		);
		if (empty($made['success'])) {
			self::fail('Could not create the HTTP collection');
		}
		AdRepository::save(null, [
			'name' => 'Collection footer ad',
			'type' => 'image',
			'zone' => 'collection_bottom',
			'status' => 'active',
			'image_url' => 'https://ads.example.test/banner.png',
			'target_url' => 'https://ads.example.test/',
			'alt_text' => 'COLLECTION-BOTTOM-AD',
		]);

		$port = self::reserveFreePort();
		self::$base = 'http://127.0.0.1:' . $port;
		$env = array_merge(is_array(getenv()) ? getenv() : [], [
			'FH_TEST_MODE' => '1',
			'TEST_DB_HOST' => DB_HOST,
			'TEST_DB_USER' => DB_USER,
			'TEST_DB_PASS' => DB_PASS,
			'TEST_DB_NAME' => DB_NAME,
			'FILEHOST_CANONICAL_URL' => self::$base,
			'TEST_TRUSTED_PROXIES' => '127.0.0.1',
		]);
		self::$proc = proc_open([
			PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', PROJECT_ROOT . '/public',
			PROJECT_ROOT . '/tests/php/http-router.php',
		], [
			0 => ['pipe', 'r'],
			1 => ['file', PROJECT_ROOT . '/tests/php/.tmp-data-http/server.log', 'w'],
			2 => ['file', PROJECT_ROOT . '/tests/php/.tmp-data-http/server.err.log', 'w'],
		], $pipes, PROJECT_ROOT . '/public', $env, ['bypass_shell' => true]);
		if (!is_resource(self::$proc)) {
			self::fail('Could not start the php -S test server');
		}
		$deadline = microtime(true) + 5.0;
		do {
			usleep(150000);
			$res = self::request('GET', '/api.php?action=health');
			$decoded = json_decode($res['body'], true);
			if ($res['status'] === 200 && is_array($decoded) && isset($decoded['php']['version'])) {
				return;
			}
		} while (microtime(true) < $deadline);
		self::stopServer();
		self::fail('Test server did not come up on ' . self::$base);
	}

	public static function tearDownAfterClass(): void
	{
		self::stopServer();
		Database::setSetting('ads_enabled', '0');
		$pdo = Database::getInstance();
		$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
		foreach (['files', 'collections', 'collection_files', 'ads', 'rate_limits', 'security_events', 'notifications', 'reports'] as $t) {
			$pdo->exec('TRUNCATE TABLE `' . Database::table($t) . '`');
		}
		$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
		Database::invalidateSettingsCache();
		foreach (self::HTTP_DIRS as $dir) {
			try {
				self::resetDirectory(PROJECT_ROOT . $dir);
				@rmdir(PROJECT_ROOT . $dir);
			} catch (Throwable $e) {
				error_log('Domain HTTP test cleanup failed: ' . $e->getMessage());
			}
		}
	}

	private static function stopServer(): void
	{
		if (is_resource(self::$proc)) {
			proc_terminate(self::$proc);
			proc_close(self::$proc);
			self::$proc = null;
		}
	}

	private static function resetDirectory(string $directory): void
	{
		$expectedParent = realpath(PROJECT_ROOT . '/tests/php');
		if ($expectedParent === false || realpath(dirname($directory)) !== $expectedParent
			|| !in_array(basename($directory), ['.tmp-data-http', '.tmp-uploads-http'], true)) {
			throw new RuntimeException('Refusing to reset an unexpected HTTP test directory.');
		}
		if (is_dir($directory)) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ($iterator as $item) {
				$item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
			}
			rmdir($directory);
		}
		mkdir($directory, 0700, true);
	}

	private static function reserveFreePort(): int
	{
		$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
		if ($socket === false) {
			self::fail("Could not reserve a loopback port: {$errstr}");
		}
		$name = (string) stream_socket_get_name($socket, false);
		fclose($socket);
		return (int) substr($name, (int) strrpos($name, ':') + 1);
	}

	/** A file row plus its bytes in the served uploads directory. */
	private static function storeFile(string $name, string $mime, string $bytes, array $extra = []): string
	{
		$id = bin2hex(random_bytes(8));
		$dir = PROJECT_ROOT . '/tests/php/.tmp-uploads-http/' . $id;
		mkdir($dir, 0700, true);
		file_put_contents($dir . '/' . $name, $bytes);
		$cols = array_merge([
			'id' => $id,
			'original_name' => $name,
			'mime_type' => $mime,
			'size' => strlen($bytes),
			'delete_token' => password_hash('tok', PASSWORD_DEFAULT),
			'uploaded_at' => time(),
			'uploaded_ip' => '203.0.113.1',
			'downloads' => 0,
		], $extra);
		Database::getInstance()->prepare(
			'INSERT INTO `' . Database::table('files') . '` (`' . implode('`,`', array_keys($cols)) . '`)
			 VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')'
		)->execute(array_values($cols));
		return $id;
	}

	/** @return array{status: int, body: string} */
	private static function request(string $method, string $path, ?string $body = null, array $headers = []): array
	{
		if (self::$cookies) {
			$headers[] = 'Cookie: ' . implode('; ', array_map(
				fn($k, $v) => $k . '=' . $v,
				array_keys(self::$cookies),
				array_values(self::$cookies)
			));
		}
		$ctx = stream_context_create(['http' => [
			'method' => $method,
			'header' => implode("\r\n", $headers),
			'content' => $body ?? '',
			'timeout' => 10,
			'ignore_errors' => true,
		]]);
		$http_response_header = [];
		$out = @file_get_contents(self::$base . $path, false, $ctx);
		$status = 0;
		foreach ($http_response_header as $line) {
			if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
				$status = (int) $m[1];
			}
			if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $line, $c)) {
				self::$cookies[trim($c[1])] = trim($c[2]);
			}
		}
		return ['status' => $status, 'body' => (string) $out];
	}

	private static function json(string $method, string $path, ?array $payload = null, string $ip = '203.0.113.10'): array
	{
		$headers = ['X-Forwarded-For: ' . $ip];
		if ($payload !== null) {
			$headers[] = 'Content-Type: application/json';
			$headers[] = 'X-CSRF-Token: ' . self::$csrf;
		}
		$res = self::request($method, $path, $payload !== null ? json_encode($payload) : null, $headers);
		return ['status' => $res['status']] + (json_decode($res['body'], true) ?: []);
	}

	private static function freshSession(): void
	{
		self::$cookies = [];
		$res = self::request('GET', '/index.php');
		preg_match('/name="csrf-token" content="([a-f0-9]{64})"/', $res['body'], $m);
		self::$csrf = $m[1] ?? '';
	}

	public function testDownloadPageShowsTheStoredNameUntouched(): void
	{
		$res = self::request('GET', '/download.php?id=' . self::$ids['plain']);
		$this->assertSame(200, $res['status']);
		$this->assertStringContainsString('<title>a+b %41.png', $res['body']);
		$this->assertStringNotContainsString('a b A.png', $res['body']);
	}

	public function testOneTimeFileGetsNoInlinePreviewOnThePageOrTheApi(): void
	{
		$plain = self::request('GET', '/download.php?id=' . self::$ids['plain']);
		$this->assertStringContainsString('action=preview&id=' . self::$ids['plain'] . '" alt=', $plain['body']);

		$once = self::request('GET', '/download.php?id=' . self::$ids['once']);
		$this->assertStringContainsString('preview-withheld', $once['body']);
		$this->assertStringNotContainsString('<img src="' . self::$base . '/api.php?action=preview', $once['body']);

		$api = self::json('GET', '/api.php?action=preview&id=' . self::$ids['once']);
		$this->assertSame(403, $api['status']);
		$this->assertSame('one_time', $api['preview_withheld'] ?? null);

		$bytes = self::request('GET', '/api.php?action=preview&id=' . self::$ids['plain'], null, ['X-Forwarded-For: 203.0.113.11']);
		$this->assertSame(200, $bytes['status']);
		$this->assertSame('plain-bytes', $bytes['body']);
		$this->assertSame(0, ActiveDownloadRepository::countPhpStreams(), 'a finished stream frees its slot');
	}

	public function testPreviewUnlockSharesTheFailedPasswordBudget(): void
	{
		self::freshSession();
		$ip = '203.0.113.77';
		for ($i = 0; $i < 20; $i++) {
			$wrong = self::json('POST', '/api.php?action=preview_auth', ['id' => self::$ids['locked'], 'pw' => 'guess-' . $i], $ip);
			$this->assertFalse($wrong['success'] ?? true);
			$this->assertTrue($wrong['require_password'] ?? false);
		}
		$blocked = self::json('POST', '/api.php?action=preview_auth', ['id' => self::$ids['locked'], 'pw' => 'right-password'], $ip);
		$this->assertSame(429, $blocked['status'], 'the right password no longer helps once the budget is spent');

		$other = self::json('POST', '/api.php?action=preview_auth', ['id' => self::$ids['locked'], 'pw' => 'right-password'], '203.0.113.78');
		$this->assertTrue($other['success'] ?? false, json_encode($other));
	}

	public function testModeratorsReachTheCollectionsTheyMayManage(): void
	{
		self::freshSession();
		$login = self::json('POST', '/api.php?action=user_login', ['username' => 'httpmod', 'password' => 'HttpMod1!x']);
		$this->assertTrue($login['success'] ?? false, json_encode($login));

		$list = self::json('GET', '/api.php?action=admin_collections');
		$this->assertSame(200, $list['status'], json_encode($list));
		$this->assertTrue($list['success'] ?? false);
		$this->assertSame(200, self::json('GET', '/api.php?action=collection_facets')['status']);
	}

	public function testCollectionFooterAdRendersOnAnOpenCollection(): void
	{
		self::$cookies = [];
		$res = self::request('GET', '/collection.php?id=' . self::$ids['collection']);
		$this->assertSame(200, $res['status']);
		$this->assertSame(1, substr_count($res['body'], 'COLLECTION-BOTTOM-AD'));
	}

	public function testCleaningAnAccountRemovesRowsAndBytesTogether(): void
	{
		self::freshSession();
		$login = self::json('POST', '/api.php?action=user_login', ['username' => 'httpboss', 'password' => 'HttpBoss1!x'], '203.0.113.50');
		$this->assertTrue($login['success'] ?? false, json_encode($login));
		$owner = Database::getUserByEmailOrUsername('httpowner');

		$res = self::json('POST', '/api.php?action=admin_user_action', [
			'user_id' => (int) $owner['id'],
			'action' => 'clean',
		], '203.0.113.50');
		$this->assertTrue($res['success'] ?? false, json_encode($res));
		$this->assertSame(0, (int) (Database::getUserStats((int) $owner['id'])['files_count'] ?? -1));
		$this->assertDirectoryDoesNotExist(PROJECT_ROOT . '/tests/php/.tmp-uploads-http/' . self::$ids['owned']);
	}

	public function testReportValidationIsLabelledByKindNotByWording(): void
	{
		self::freshSession();
		$res = self::json('POST', '/api.php?action=report_file', ['file_id' => self::$ids['plain'], 'name' => 'Jan'], '203.0.113.90');
		$this->assertFalse($res['success'] ?? true);
		$this->assertSame('validation', $res['error_kind'] ?? null);
	}
}
