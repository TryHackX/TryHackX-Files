<?php

use PHPUnit\Framework\TestCase;

require_once PROJECT_ROOT . '/src/includes/RateLimiter.php';
require_once PROJECT_ROOT . '/src/includes/api/AuthController.php';

/**
 * Request-level tests (runda 10): the API exercised over REAL HTTP.
 *
 * Everything the repository suite cannot see happens between the socket and the controller —
 * routing, session cookies, the CSRF gate, permission gates answering with proper status
 * codes. So a `php -S` instance is started against the SAME throwaway database this suite
 * already built (config.php honours FH_TEST_MODE + TEST_DB_*), and the assertions talk to it
 * with a cookie jar like a browser would.
 *
 * The server is one process for the whole class — starting PHP per test would dominate the
 * runtime — and is torn down even when assertions fail (tearDownAfterClass always runs).
 */
final class HttpApiTest extends TestCase
{
	private static $proc = null;
	private static string $base = '';
	private static array $cookies = [];
	private static string $csrf = '';
	private static string $apiKeyOne = '';
	private static string $apiKeyTwo = '';
	private static int $apiKeyOneId = 0;
	private static int $apiKeyTwoId = 0;

	private static function resetHttpTestDirectory(string $directory): void
	{
		$expectedParent = realpath(PROJECT_ROOT . '/tests/php');
		$parent = realpath(dirname($directory));
		if ($expectedParent === false || $parent !== $expectedParent
			|| !in_array(basename($directory), ['.tmp-data-http', '.tmp-uploads-http'], true)) {
			throw new RuntimeException('Refusing to reset an unexpected HTTP test directory.');
		}

		if (is_dir($directory)) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ($iterator as $item) {
				$path = $item->getPathname();
				if ($item->isLink() || $item->isFile()) {
					if (!unlink($path)) {
						throw new RuntimeException('Cannot remove HTTP test artifact: ' . $path);
					}
				} elseif ($item->isDir() && !rmdir($path)) {
					throw new RuntimeException('Cannot remove HTTP test directory: ' . $path);
				}
			}
			if (!rmdir($directory)) {
				throw new RuntimeException('Cannot reset HTTP test root: ' . $directory);
			}
		}
		if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
			throw new RuntimeException('Cannot create HTTP test directory: ' . $directory);
		}
	}

	public static function setUpBeforeClass(): void
	{
		// Seed the accounts the scenarios sign in with. is_active/verified flags are forced
		// so the login test exercises the SESSION mechanics, not the verification policy.
		$pdo = Database::getInstance();
		$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
		foreach ([
			'api_keys',
			'recovery_tokens',
			'recovery_attempts',
			'mail_outbox',
			'users',
			'rate_limits',
			'notifications',
			'collections',
			'promo_codes',
		] as $t) {
			$pdo->exec('TRUNCATE TABLE `' . Database::table($t) . '`');
		}
		$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
		Database::createAdmin('httpadmin', 'HttpAdmin1!', 'httpadmin@example.pl');
		Database::registerUser('httpuser', 'httpuser@example.pl', 'HttpUser1!');
		$buyerGroup = Database::saveGroup(null, [
			'name' => 'HTTP ads buyers',
			'permissions' => ['ads.buy'],
		]);
		if (empty($buyerGroup['success'])) {
			self::fail('Could not create isolated buyer group for the HTTP scenarios');
		}
		$httpUser = Database::getUserByEmailOrUsername('httpuser');
		if (!$httpUser || !Database::setUserGroup((int) $httpUser['id'], (int) $buyerGroup['id'])) {
			self::fail('Could not assign the HTTP scenario user to its buyer group');
		}
		self::$apiKeyOne = 'fh_' . bin2hex(random_bytes(24));
		self::$apiKeyTwo = 'fh_' . bin2hex(random_bytes(24));
		self::$apiKeyOneId = (int) Database::createApiKey(
			(int) $httpUser['id'],
			hash('sha256', self::$apiKeyOne),
			substr(self::$apiKeyOne, 0, 11),
			'HTTP key one'
		);
		self::$apiKeyTwoId = (int) Database::createApiKey(
			(int) $httpUser['id'],
			hash('sha256', self::$apiKeyTwo),
			substr(self::$apiKeyTwo, 0, 11),
			'HTTP key two'
		);
		if (self::$apiKeyOneId < 1 || self::$apiKeyTwoId < 1) {
			self::fail('Could not create isolated API keys for the HTTP scenarios');
		}
		$pdo->exec("UPDATE `" . Database::table('users') . "` SET `is_active` = 1");
		// The HTTP process has its own isolated cache directory. Seed every setting required
		// by these scenarios before it starts, so no test depends on settings left by an
		// earlier repository class or on cross-directory cache invalidation.
		Database::setSetting('app_name', 'FileHostTest');
		Database::setSetting('email_from', 'noreply@example.test');
		Database::setSetting('email_from_name', 'FileHostTest');
		try {
			$pdo->exec("UPDATE `" . Database::table('users') . "` SET `email_verified` = 1");
		} catch (PDOException $e) {
			// column absent on this schema — nothing to force
		}

		$port = self::reserveFreePort();
		self::$base = 'http://127.0.0.1:' . $port;
		foreach ([PROJECT_ROOT . '/tests/php/.tmp-data-http', PROJECT_ROOT . '/tests/php/.tmp-uploads-http'] as $dir) {
			self::resetHttpTestDirectory($dir);
		}
		$env = array_merge(self::inheritedEnv(), [
			'FH_TEST_MODE' => '1',
			'TEST_DB_HOST' => DB_HOST,
			'TEST_DB_USER' => DB_USER,
			'TEST_DB_PASS' => DB_PASS,
			'TEST_DB_NAME' => DB_NAME,
			'FILEHOST_CANONICAL_URL' => self::$base,
			'TEST_TRUSTED_PROXIES' => '127.0.0.1',
		]);
		$cmd = [
			PHP_BINARY,
			'-S',
			'127.0.0.1:' . $port,
			'-t',
			PROJECT_ROOT . '/public',
			PROJECT_ROOT . '/tests/php/http-router.php',
		];
		self::$proc = proc_open($cmd, [
			0 => ['pipe', 'r'],
			1 => ['file', PROJECT_ROOT . '/tests/php/.tmp-data-http/server.log', 'w'],
			2 => ['file', PROJECT_ROOT . '/tests/php/.tmp-data-http/server.err.log', 'w'],
		], $pipes, PROJECT_ROOT . '/public', $env, ['bypass_shell' => true]);
		if (!is_resource(self::$proc)) {
			self::fail('Could not start the php -S test server');
		}
		// Wait until it answers; ~5 s is plenty on a local socket.
		//
		// "Answered at all" is deliberately not the test. This used to accept any status > 0,
		// which meant that if something else already held the port, its replies were mistaken
		// for ours: the suite started against a foreign server and reported ~20 unrelated
		// assertion failures instead of one clear "port busy". Insist on our own health payload.
		$deadline = microtime(true) + 5.0;
		$last = 'no response';
		do {
			usleep(150000);
			$res = self::rawRequest('GET', '/api.php?action=health');
			if ($res['status'] === 200) {
				// `action=health` answers {success, php:{database_connected}, python, python_running}.
				// Match on that shape, not on a bare 200, so a stranger's page cannot pass.
				$decoded = json_decode((string) ($res['body'] ?? ''), true);
				if (is_array($decoded)
					&& is_array($decoded['php'] ?? null)
					&& array_key_exists('database_connected', $decoded['php'])
					&& array_key_exists('python_running', $decoded)
				) {
					return;
				}
				$last = 'HTTP 200 but the body is not this app\'s health JSON: '
					. substr(preg_replace('/\s+/', ' ', (string) ($res['body'] ?? '')) ?? '', 0, 300);
			} elseif ($res['status'] > 0) {
				$last = 'HTTP ' . $res['status'] . ' from something else on this port';
			}
		} while (microtime(true) < $deadline);

		// PHPUnit skips tearDownAfterClass when setUpBeforeClass throws, and an orphaned
		// `php -S` still holding its stdin pipe stops the runner from exiting at all — the
		// failure has to be reported, not turned into a hang.
		self::stopServer();

		$errLog = PROJECT_ROOT . '/tests/php/.tmp-data-http/server.err.log';
		$detail = is_file($errLog) ? trim((string) file_get_contents($errLog)) : '';
		self::fail(
			'Test server did not come up on ' . self::$base . ' (' . $last . ').'
			. ($detail !== '' ? "\nServer stderr:\n" . $detail : '')
		);
	}

	/** Terminate the `php -S` child if it is still running. Safe to call more than once. */
	private static function stopServer(): void
	{
		if (is_resource(self::$proc)) {
			proc_terminate(self::$proc);
			proc_close(self::$proc);
			self::$proc = null;
		}
	}

	/**
	 * Ask the OS for an unused loopback port instead of hardcoding one.
	 *
	 * The fixed port this used to carry (8765) is a popular default, so on a developer machine
	 * running anything else on it `php -S` could not bind and the whole class failed in a way
	 * that pointed at the application rather than at the port.
	 */
	private static function reserveFreePort(): int
	{
		$socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
		if ($socket === false) {
			self::fail("Could not reserve a loopback port for the test server: {$errstr} ({$errno})");
		}
		$name = (string) stream_socket_get_name($socket, false);
		fclose($socket);

		$port = (int) substr($name, (int) strrpos($name, ':') + 1);
		if ($port < 1024) {
			self::fail('Reserved an implausible test-server port: ' . $name);
		}
		return $port;
	}

	public static function tearDownAfterClass(): void
	{
		self::stopServer();
		foreach ([PROJECT_ROOT . '/tests/php/.tmp-data-http', PROJECT_ROOT . '/tests/php/.tmp-uploads-http'] as $dir) {
			try {
				self::resetHttpTestDirectory($dir);
				@rmdir($dir);
			} catch (Throwable $e) {
				error_log('HTTP test cleanup failed: ' . $e->getMessage());
			}
		}
	}

	/** getenv() without arguments is the inheritable environment on both CLI SAPIs. */
	private static function inheritedEnv(): array
	{
		$env = getenv();
		return is_array($env) ? $env : [];
	}

	/**
	 * One HTTP request with the class-level cookie jar. Streams only — the WAMP CLI is not
	 * guaranteed to ship curl, and a raw context keeps the harness dependency-free.
	 *
	 * @return array{status: int, body: string, headers: array}
	 */
	private static function rawRequest(string $method, string $path, ?string $body = null, array $headers = []): array
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
			'ignore_errors' => true, // 4xx/5xx are answers to assert on, not failures
		]]);
		// Load-bearing, do not delete — see the note in PayU::request(). Without it PHP 8.5
		// deprecates the implicit variable at compile time, and phpunit.xml runs with
		// failOnWarning, so the whole suite would go red the day the matrix adds 8.5.
		$http_response_header = [];
		$out = @file_get_contents(self::$base . $path, false, $ctx);
		$status = 0;
		$respHeaders = [];
		foreach ($http_response_header as $line) {
			if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
				$status = (int) $m[1];
			} else {
				$respHeaders[] = $line;
			}
			if (stripos($line, 'Set-Cookie:') === 0) {
				if (preg_match('/Set-Cookie:\s*([^=]+)=([^;]*)/i', $line, $c)) {
					self::$cookies[trim($c[1])] = trim($c[2]);
				}
			}
		}
		return ['status' => $status, 'body' => (string) $out, 'headers' => $respHeaders];
	}

	private static function getJson(string $path): array
	{
		$res = self::rawRequest('GET', $path);
		return ['status' => $res['status']] + (json_decode($res['body'], true) ?: []);
	}

	private static function postJson(
		string $path,
		array $payload,
		bool $withCsrf = true,
		array $extraHeaders = []
	): array
	{
		$headers = array_merge(['Content-Type: application/json'], $extraHeaders);
		if ($withCsrf && self::$csrf !== '') {
			$headers[] = 'X-CSRF-Token: ' . self::$csrf;
		}
		$res = self::rawRequest('POST', $path, json_encode($payload), $headers);
		return ['status' => $res['status']] + (json_decode($res['body'], true) ?: []);
	}

	/** Fetch the home page and lift the CSRF token out of its meta tag, like a browser. */
	private static function refreshCsrf(): void
	{
		$res = self::rawRequest('GET', '/index.php');
		self::assertSame(200, $res['status'], 'home page should render');
		self::assertMatchesRegularExpression('/name="csrf-token" content="([a-f0-9]{64})"/', $res['body']);
		preg_match('/name="csrf-token" content="([a-f0-9]{64})"/', $res['body'], $m);
		self::$csrf = $m[1];
	}

	public function testHealthAnswers(): void
	{
		$d = self::getJson('/api.php?action=health');
		$this->assertSame(200, $d['status']);
	}

	public function testPublicHealthSaysUpOrDownAndNothingElse(): void
	{
		$res = self::rawRequest('GET', '/api.php?action=health');
		$this->assertSame(200, $res['status']);
		$body = (string) ($res['body'] ?? '');
		$decoded = json_decode($body, true);
		$this->assertIsArray($decoded);
		$this->assertArrayNotHasKey('version', $decoded['php']);
		$this->assertStringNotContainsString('uploads_dir', $body);
		$this->assertStringNotContainsString('config_exists', $body);
	}

	/** The inline dictionary of a public page is the small public subset; the live language switch reads the same block. */
	public function testHomePageShipsOnlyThePublicDictionary(): void
	{
		$res = self::rawRequest('GET', '/index.php?lang=en');
		$this->assertSame(200, $res['status']);
		$this->assertSame(
			1,
			preg_match('#<script type="application/json" id="i18n-data">(.*?)</script>#s', $res['body'], $m)
		);
		$dictionary = json_decode($m[1], true);
		$this->assertIsArray($dictionary);
		$this->assertArrayHasKey('up.session_failed', $dictionary);
		$this->assertArrayHasKey('js.resend_confirm', $dictionary);
		$this->assertArrayNotHasKey('panel.users.th_email', $dictionary, 'panel text must not ride along on the home page');
		$this->assertLessThan(30000, strlen($m[1]), 'the public dictionary should stay small');
	}

	public function testHomePageEnforcesTheInlineScriptFreeCspIsland(): void
	{
		$res = self::rawRequest('GET', '/index.php');
		$this->assertSame(200, $res['status']);
		$cspHeaders = preg_grep('/^Content-Security-Policy:/i', $res['headers']);
		$this->assertCount(1, $cspHeaders);
		$csp = (string) reset($cspHeaders);
		$this->assertStringContainsString("script-src 'self'", $csp);
		$this->assertStringContainsString("script-src-attr 'none'", $csp);
		$this->assertDoesNotMatchRegularExpression(
			"/script-src[^;]*'unsafe-inline'/",
			$csp
		);
		$this->assertDoesNotMatchRegularExpression(
			'/<[^>]+\son(?:click|submit|change|input|load|error)\s*=/i',
			$res['body']
		);
		$this->assertDoesNotMatchRegularExpression(
			'/<script(?![^>]*\bsrc=)(?![^>]*type="application\/json")[^>]*>/i',
			$res['body']
		);
		foreach (['bootstrap.js', 'auth.js', 'index.js'] as $asset) {
			$this->assertStringContainsString('/assets/js/' . $asset, $res['body']);
		}
	}

	public function testMethodGateRunsBeforeHandlersAndReturnsAllow(): void
	{
		foreach (['user_logout', 'get_download_token', 'collection_zip_token', 'checkout'] as $action) {
			$res = self::rawRequest('GET', '/api.php?action=' . $action);
			$this->assertSame(405, $res['status'], $action);
			$this->assertContains('Allow: POST', $res['headers'], $action);
			$body = json_decode($res['body'], true);
			$this->assertFalse($body['success'] ?? true, $action);
		}

		// POST is the right verb, but a browser mutation still needs the session CSRF token.
		$res = self::rawRequest(
			'POST',
			'/api.php?action=user_logout',
			'{}',
			['Content-Type: application/json']
		);
		$this->assertSame(403, $res['status']);

		$preflight = self::rawRequest(
			'OPTIONS',
			'/api.php?action=user_logout',
			null,
			[
				'Origin: ' . self::$base,
				'Access-Control-Request-Method: POST',
			]
		);
		$this->assertSame(204, $preflight['status']);
		$this->assertContains('Access-Control-Allow-Origin: ' . self::$base, $preflight['headers']);
		$this->assertContains('Access-Control-Allow-Methods: POST', $preflight['headers']);

		$badPreflight = self::rawRequest(
			'OPTIONS',
			'/api.php?action=user_logout',
			null,
			['Access-Control-Request-Method: GET']
		);
		$this->assertSame(405, $badPreflight['status']);
		$this->assertContains('Allow: POST', $badPreflight['headers']);
	}

	public function testDeleteCapabilityGetOnlyRendersNoStorePostForm(): void
	{
		$id = 'httpdel_' . substr(bin2hex(random_bytes(8)), 0, 12);
		$token = 'delete-capability-' . bin2hex(random_bytes(12));
		$uploadRoot = PROJECT_ROOT . '/tests/php/.tmp-uploads-http';
		$dir = $uploadRoot . '/' . $id;
		if (!is_dir($dir)) {
			mkdir($dir, 0777, true);
		}
		file_put_contents($dir . '/delete-me.bin', 'delete-link fixture');
		Database::getInstance()->prepare(
			'INSERT INTO `' . Database::table('files') . '`
			 (`id`, `original_name`, `mime_type`, `size`, `delete_token`, `uploaded_at`, `uploaded_ip`, `downloads`, `user_id`)
			 VALUES (?, ?, ?, ?, ?, ?, ?, 0, NULL)'
		)->execute([
			$id,
			'delete-me.bin',
			'application/octet-stream',
			19,
			password_hash($token, PASSWORD_DEFAULT),
			time(),
			'203.0.113.10',
		]);

		try {
			$get = self::rawRequest(
				'GET',
				'/api.php?action=delete_link&id=' . rawurlencode($id) . '&token=' . rawurlencode($token)
			);
			$this->assertSame(200, $get['status']);
			$this->assertContains('Cache-Control: no-store, private, max-age=0', $get['headers']);
			$this->assertStringContainsString('<form method="post"', $get['body']);
			$this->assertStringNotContainsString('confirm=1', $get['body']);
			$this->assertMatchesRegularExpression('/name="nonce" value="([a-f0-9]{64})"/', $get['body']);
			preg_match('/name="nonce" value="([a-f0-9]{64})"/', $get['body'], $match);

			$count = Database::getInstance()->prepare(
				'SELECT COUNT(*) FROM `' . Database::table('files') . '` WHERE `id` = ?'
			);
			$count->execute([$id]);
			$this->assertSame(1, (int) $count->fetchColumn(), 'GET/prefetch must never delete');

			$postBody = http_build_query([
				'id' => $id,
				'token' => $token,
				'nonce' => $match[1],
			]);
			$post = self::rawRequest(
				'POST',
				'/api.php?action=delete_link',
				$postBody,
				['Content-Type: application/x-www-form-urlencoded']
			);
			$this->assertSame(200, $post['status']);
			$count->execute([$id]);
			$this->assertSame(0, (int) $count->fetchColumn());
			$this->assertDirectoryDoesNotExist($dir);

			$replay = self::rawRequest(
				'POST',
				'/api.php?action=delete_link',
				$postBody,
				['Content-Type: application/x-www-form-urlencoded']
			);
			$this->assertNotSame(200, $replay['status']);
		} finally {
			if (is_dir($dir)) {
				@unlink($dir . '/payload.bin');
				@rmdir($dir);
			}
			Database::getInstance()->prepare(
				'DELETE FROM `' . Database::table('files') . '` WHERE `id` = ?'
			)->execute([$id]);
		}
	}

	public function testCanonicalHostPortAndSchemeAreEnforcedBeforeRouting(): void
	{
		$foreignHost = self::rawRequest(
			'GET',
			'/api.php?action=health',
			null,
			['Host: attacker.example']
		);
		$this->assertSame(421, $foreignHost['status']);
		$this->assertSame('Misdirected Request', $foreignHost['body']);

		$foreignPort = self::rawRequest(
			'GET',
			'/api.php?action=health',
			null,
			['Host: 127.0.0.1:9999']
		);
		$this->assertSame(421, $foreignPort['status']);

		$foreignScheme = self::rawRequest(
			'GET',
			'/api.php?action=health',
			null,
			['X-Forwarded-Proto: https']
		);
		$this->assertSame(421, $foreignScheme['status']);
	}

	public function testAdminEndpointRefusesAnonymous(): void
	{
		$d = self::getJson('/api.php?action=admin_users');
		$this->assertNotSame(200, $d['status'], 'admin listing must not answer 200 to a stranger');
		$this->assertFalse($d['success'] ?? true);
	}

	public function testPostWithoutCsrfIsRejected(): void
	{
		$d = self::postJson('/api.php?action=user_login', ['username' => 'httpuser', 'password' => 'HttpUser1!'], false);
		$this->assertFalse($d['success'] ?? true, 'the CSRF gate must refuse a tokenless POST');
	}

	public function testPostWithForeignOriginIsRejectedEvenWithValidCsrf(): void
	{
		self::refreshCsrf();
		$d = self::postJson(
			'/api.php?action=user_login',
			['username' => 'httpuser', 'password' => 'HttpUser1!'],
			true,
			['Origin: http://127.0.0.1:9999']
		);
		$this->assertSame(403, $d['status']);
		$this->assertFalse($d['success'] ?? true);
	}

	public function testLoginFlowWithCsrfAndSession(): void
	{
		self::refreshCsrf();
		$d = self::postJson('/api.php?action=user_login', ['username' => 'httpuser', 'password' => 'HttpUser1!']);
		$this->assertTrue($d['success'] ?? false, 'seeded user should sign in: ' . json_encode($d));

		// The session cookie now authenticates follow-up requests.
		$count = self::getJson('/api.php?action=notification_count');
		$this->assertTrue($count['success'] ?? false);
		$this->assertArrayHasKey('unread', $count);
	}

	public function testRegularUserCannotReachAdminSurface(): void
	{
		// Runs after the login test (methods run in declaration order within a class).
		$d = self::getJson('/api.php?action=admin_users');
		$this->assertNotSame(200, $d['status']);
		$this->assertFalse($d['success'] ?? true);
	}

	public function testAuthenticatedPanelUsesStrictCspAndDeclarativeEvents(): void
	{
		$res = self::rawRequest('GET', '/panel.php?tab=user');
		$this->assertSame(200, $res['status']);
		$cspHeaders = preg_grep('/^Content-Security-Policy:/i', $res['headers']);
		$this->assertCount(1, $cspHeaders);
		$csp = (string) reset($cspHeaders);
		$this->assertStringContainsString("script-src-attr 'none'", $csp);
		$this->assertDoesNotMatchRegularExpression(
			"/script-src[^;]*'unsafe-inline'/",
			$csp
		);
		$this->assertDoesNotMatchRegularExpression(
			'/<[^>]+\son(?:click|submit|change|input|load|error|keydown|keyup)\s*=/i',
			$res['body']
		);
		$this->assertStringContainsString('data-fh-submit=', $res['body']);
		// The shared foundation comes first so the panel files can override details of it.
		$basePos = strpos($res['body'], '/assets/css/base.css');
		$this->assertNotFalse($basePos);
		$this->assertLessThan(strpos($res['body'], '/assets/css/panel.css'), $basePos);
		$this->assertStringContainsString('/assets/js/panel-tabs.js', $res['body']);
		$this->assertStringContainsString('/assets/css/panel-forms.css', $res['body']);
		$this->assertStringContainsString('/assets/css/panel-shell.css', $res['body']);
		$this->assertStringContainsString('/assets/css/panel-features.css', $res['body']);
		$this->assertStringContainsString('/assets/css/panel-premium.css', $res['body']);
		$this->assertStringContainsString('/assets/css/panel-ads-admin.css', $res['body']);
		$this->assertStringContainsString('/assets/js/panel-docs.js', $res['body']);
		$this->assertStringContainsString('/assets/js/panel-account-tools.js', $res['body']);
		$this->assertStringContainsString('/assets/js/panel-files.js', $res['body']);
		$this->assertStringContainsString('/assets/js/panel-settings.js', $res['body']);
		$this->assertStringContainsString('/assets/js/panel-languages.js', $res['body']);
		$this->assertStringContainsString('/assets/js/panel-premium.js', $res['body']);
		$this->assertStringContainsString('/assets/js/panel-events.js', $res['body']);
		// An ordinary account is not sent the scripts of screens it cannot open.
		foreach (['panel-users', 'panel-groups', 'panel-moderation', 'panel-dashboard', 'panel-ads'] as $adminOnly) {
			$this->assertStringNotContainsString('/assets/js/' . $adminOnly . '.js', $res['body'], $adminOnly);
		}
	}

	public function testPromoCheckRequiresNothingButRejectsJunk(): void
	{
		$d = self::getJson('/api.php?action=promo_check&code=NO-SUCH-CODE');
		$this->assertTrue($d['success'] ?? false);
		$this->assertFalse($d['valid'] ?? true);
	}

	public function testInvoiceIs404WhileDisabled(): void
	{
		$res = self::rawRequest('GET', '/api.php?action=invoice&order=fh-nothing');
		$this->assertSame(404, $res['status']);
	}

	public function testCollectionZipTokenForMissingCollection(): void
	{
		$d = self::getJson('/api.php?action=collection_zip_token&id=deadbeefdeadbeef');
		$this->assertFalse($d['success'] ?? true);
	}

	public function testAdsBuyerSurfaceReportsDisabledCleanly(): void
	{
		// Existing buyers retain access to their creatives while new sales are disabled.
		$d = self::getJson('/api.php?action=my_ads');
		$this->assertSame(200, $d['status']);
		$this->assertTrue($d['success'] ?? false);
		$this->assertIsArray($d['ads'] ?? null);
	}

	public function testApiV1UsesPublicRouteAndRejectsSurplusOrEmptySegments(): void
	{
		$headers = ['Authorization: Bearer ' . self::$apiKeyOne];
		$whoami = self::rawRequest('GET', '/api/v1/whoami', null, $headers);
		$this->assertSame(200, $whoami['status']);
		$body = json_decode($whoami['body'], true);
		$this->assertSame('httpuser', $body['user']['username'] ?? null);

		foreach ([
			'/api/v1/files//anything',
			'/api/v1/files/valid-file-id/extra',
			'/api/v1/whoami/extra',
		] as $path) {
			$res = self::rawRequest('GET', $path, null, $headers);
			$this->assertSame(404, $res['status'], $path);
			$this->assertSame('Unknown resource', json_decode($res['body'], true)['error'] ?? null, $path);
		}
	}

	public function testApiV1RateLimitsEachKeyIndependently(): void
	{
		$first = self::rawRequest(
			'GET',
			'/api/v1/stats',
			null,
			['Authorization: Bearer ' . self::$apiKeyOne]
		);
		$second = self::rawRequest(
			'GET',
			'/api/v1/stats',
			null,
			['X-API-Key: ' . self::$apiKeyTwo]
		);
		$this->assertSame(200, $first['status']);
		$this->assertSame(200, $second['status']);

		$table = Database::table('rate_limits');
		$stmt = Database::getInstance()->prepare(
			"SELECT `bucket` FROM `{$table}` WHERE `bucket` IN (?, ?)"
		);
		$stmt->execute([
			'key:' . self::$apiKeyOneId . '|api',
			'key:' . self::$apiKeyTwoId . '|api',
		]);
		$buckets = $stmt->fetchAll(PDO::FETCH_COLUMN);
		sort($buckets);
		$expected = [
			'key:' . self::$apiKeyOneId . '|api',
			'key:' . self::$apiKeyTwoId . '|api',
		];
		sort($expected);
		$this->assertSame($expected, $buckets);
	}

	public function testRecoveryMailUsesRecipientsSavedLanguage(): void
	{
		$user = Database::getUserByEmailOrUsername('httpuser');
		$this->assertNotNull($user);
		$pdo = Database::getInstance();
		$pdo->prepare(
			"UPDATE `" . Database::table('users') . "` SET `language` = 'en' WHERE `id` = ?"
		)->execute([(int) $user['id']]);

		self::refreshCsrf();
		$response = self::postJson('/api.php?action=recover_password', ['input' => 'httpuser']);
		$this->assertSame(200, $response['status']);
		$this->assertTrue($response['success'] ?? false, json_encode($response));

		$stmt = $pdo->prepare(
			"SELECT `subject`,`html_body` FROM `" . Database::table('mail_outbox')
			. "` WHERE `to_email` = ? ORDER BY `id` DESC LIMIT 1"
		);
		$stmt->execute(['httpuser@example.pl']);
		$mail = $stmt->fetch(PDO::FETCH_ASSOC);
		$this->assertIsArray($mail);
		$this->assertSame('Password reset - FileHostTest', $mail['subject']);
		$this->assertStringContainsString('We received a request to reset', $mail['html_body']);
		$this->assertStringNotContainsString('Otrzymaliśmy prośbę', $mail['html_body']);
	}

	public function testDeactivatedBrowserSessionLosesAccessOnTheNextRequest(): void
	{
		$user = Database::getUserByEmailOrUsername('httpuser');
		$this->assertNotNull($user);
		$this->assertTrue(Database::updateUserStatus((int) $user['id'], 0));

		$count = self::getJson('/api.php?action=notification_count');
		$this->assertFalse($count['success'] ?? true);
		$this->assertNotSame(200, $count['status']);
	}

	public function testAdminLoginAndUsersList(): void
	{
		self::$cookies = [];
		self::$csrf = '';
		self::refreshCsrf();
		$d = self::postJson('/api.php?action=user_login', ['username' => 'httpadmin', 'password' => 'HttpAdmin1!']);
		$this->assertTrue($d['success'] ?? false, 'seeded admin should sign in: ' . json_encode($d));

		$users = self::getJson('/api.php?action=admin_users');
		$this->assertTrue($users['success'] ?? false, 'admin listing should answer the admin');

		// The admin's panel does carry the scripts of the screens an ordinary account never gets.
		$panel = self::rawRequest('GET', '/panel.php?tab=users');
		$this->assertSame(200, $panel['status']);
		foreach (['panel-users', 'panel-groups', 'panel-moderation', 'panel-dashboard', 'panel-ads'] as $module) {
			$this->assertStringContainsString('/assets/js/' . $module . '.js', $panel['body'], $module);
		}
	}

	/**
	 * Coming back on a "stay signed in" cookie restores the session but not the password.
	 *
	 * This is the interaction the whole feature rests on, and it cannot be proved by a unit
	 * test: the browser is closed (its session cookie dropped) and only the device credential
	 * remains. The panel must recognise the account and still refuse to open, because
	 * possession of a device is not knowledge of a password.
	 */
	public function testADeviceCookieRestoresTheSessionButNotThePanel(): void
	{
		Database::setSetting('login_remember_enabled', '1');
		Database::setSetting('panel_reauth_minutes', 30);
		Database::setSetting('panel_reauth_scope', 'staff');

		self::$cookies = [];
		self::$csrf = '';
		self::refreshCsrf();
		$signedIn = self::postJson('/api.php?action=user_login', [
			'username' => 'httpadmin',
			'password' => 'HttpAdmin1!',
			'remember' => 3600,
		]);
		$this->assertTrue($signedIn['success'] ?? false, json_encode($signedIn));
		$this->assertArrayHasKey('fh_remember', self::$cookies, 'a duration must issue a cookie');
		$this->assertStringContainsString(
			'id="dashFiles"',
			self::rawRequest('GET', '/panel.php?tab=dashboard')['body'],
			'a fresh password opens the panel'
		);

		// Close the browser: the session cookie goes, the device credential stays.
		unset(self::$cookies[session_name()], self::$cookies['PHPSESSID']);

		$restored = self::rawRequest('GET', '/panel.php?tab=dashboard');
		$this->assertSame(200, $restored['status'], 'the cookie should restore the session');
		$this->assertStringNotContainsString('id="dashFiles"', $restored['body'], 'but not open the panel');
		$this->assertStringContainsString('value="panel_reauth"', $restored['body']);

		// The gate is the one page a visitor is asked to type a password into, so it has to
		// look like the rest of the product: the site's own stylesheet and background layer,
		// the sign-in modal's card and controls, and the same raised-not-centred placement.
		$this->assertStringContainsString('/assets/css/base.css', $restored['body']);
		$this->assertStringContainsString('/assets/css/index.css', $restored['body']);
		$this->assertStringContainsString('class="bg-wrap"', $restored['body']);
		// A phone's password manager needs the account name next to the password to offer the
		// saved credential; the form must not switch autofill off either.
		$this->assertMatchesRegularExpression('/name="username"[^>]*autocomplete="username"/', $restored['body']);
		$this->assertDoesNotMatchRegularExpression('/<form method="post" autocomplete="off"/', $restored['body']);
		$this->assertStringContainsString('class="auth-box reauth-box"', $restored['body']);
		$this->assertStringContainsString('class="auth-input"', $restored['body']);
		$this->assertStringContainsString('class="auth-submit"', $restored['body']);
		$this->assertMatchesRegularExpression(
			'/\.reauth-shell\s*\{[^}]*align-items:\s*flex-start/',
			$restored['body'],
			'the card sits below the top edge, not dead centre'
		);

		self::refreshCsrf();
		$wrong = self::rawRequest(
			'POST',
			'/panel.php',
			http_build_query([
				'action' => 'panel_reauth',
				'_csrf' => self::$csrf,
				'password' => 'NotThePassword1!',
			]),
			['Content-Type: application/x-www-form-urlencoded']
		);
		$this->assertStringContainsString('value="panel_reauth"', $wrong['body'], 'a wrong password opens nothing');

		$right = self::rawRequest(
			'POST',
			'/panel.php',
			http_build_query([
				'action' => 'panel_reauth',
				'_csrf' => self::$csrf,
				'password' => 'HttpAdmin1!',
			]),
			['Content-Type: application/x-www-form-urlencoded']
		);
		// The stream wrapper follows the redirect, so success looks like the panel itself.
		$this->assertSame(200, $right['status']);
		$this->assertStringContainsString('id="dashFiles"', $right['body'], 'the right password opens it');
		$this->assertStringContainsString(
			'id="dashFiles"',
			self::rawRequest('GET', '/panel.php?tab=dashboard')['body']
		);
	}

	public function testAdminPanelViewsRenderAfterTemplateSplit(): void
	{
		$views = [
			'/panel.php?tab=dashboard' => 'id="dashFiles"',
			'/panel.php?tab=files' => 'id="filesBody"',
			'/panel.php?tab=users' => 'id="usersBody"',
			'/panel.php?tab=moderate&mstab=reports' => 'id="reportsBody"',
			'/panel.php?tab=moderate&mstab=tracking' => 'name="threshold"',
			'/panel.php?tab=moderate&mstab=audit' => 'id="auditBody"',
			'/panel.php?tab=myfiles' => 'id="myFilesBody"',
			'/panel.php?tab=settings&stab=general' => 'name="app_name"',
			'/panel.php?tab=settings&stab=storage' => 'name="uploads_path"',
			'/panel.php?tab=settings&stab=security' => 'name="registration_enabled"',
			'/panel.php?tab=settings&stab=email' => 'id="emailMethod"',
			'/panel.php?tab=settings&stab=groups' => 'id="settingsGroupsBody"',
			'/panel.php?tab=settings&stab=languages' => 'id="languagesBody"',
			'/panel.php?tab=settings&stab=premium' => 'id="plansBody"',
			'/panel.php?tab=settings&stab=ads' => 'id="adsSettingsMessage"',
			'/panel.php?tab=settings&stab=notifications' => 'id="notifDefaultsBody"',
			'/panel.php?tab=settings&stab=system' => 'name="cron_enabled"',
			'/panel.php?tab=notifications' => 'id="notifPageList"',
			'/panel.php?tab=user' => 'id="userStatsContainer"',
		];

		foreach ($views as $path => $marker) {
			$response = self::rawRequest('GET', $path);
			$this->assertSame(200, $response['status'], $path);
			$this->assertStringContainsString($marker, $response['body'], $path);
		}

		$legacyPermissions = self::rawRequest(
			'GET',
			'/panel.php?tab=moderate&mstab=permissions'
		);
		$this->assertSame(200, $legacyPermissions['status']);
		$this->assertStringContainsString('id="reportsBody"', $legacyPermissions['body']);
		$this->assertStringNotContainsString(
			'href="?tab=moderate&mstab=permissions"',
			$legacyPermissions['body']
		);

		$premiumSettings = self::rawRequest('GET', '/panel.php?tab=settings&stab=premium');
		$this->assertStringContainsString('id="promoFormScope"', $premiumSettings['body']);
		$this->assertStringContainsString('id="promoFormPlan"', $premiumSettings['body']);
	}

	/**
	 * Settings -> Security offers all four captcha providers, each with its own key pair in
	 * the form (that is what makes switching non-destructive), and no secret input is ever
	 * prefilled — a blank one means "keep the stored value".
	 */
	public function testSecurityTabOffersEveryCaptchaProviderWithoutPrefillingSecrets(): void
	{
		$response = self::rawRequest('GET', '/panel.php?tab=settings&stab=security');
		$this->assertSame(200, $response['status']);
		$body = $response['body'];

		$this->assertStringContainsString('name="captcha_provider"', $body);
		$this->assertStringContainsString('name="recaptcha_min_score"', $body);

		foreach ([
			'turnstile',
			'recaptcha_v3',
			'recaptcha_v2',
			'hcaptcha',
		] as $provider) {
			$this->assertStringContainsString(
				'data-captcha-provider="' . $provider . '"',
				$body,
				$provider . ' has no key block'
			);
			$this->assertStringContainsString(
				'name="' . CaptchaService::siteKeySetting($provider) . '"',
				$body,
				$provider . ' has no site-key field'
			);
			$this->assertStringContainsString(
				'name="' . CaptchaService::secretKeySetting($provider) . '" value=""',
				$body,
				$provider . ' prefills its secret into the page'
			);
		}
	}

	/**
	 * The gate is reachable only with a valid session, so whoever is typing at it already got
	 * past the sign-in form. That is exactly the person who must not be allowed to sit there
	 * guessing: a shared machine, or a stolen session cookie. Wrong passwords are capped.
	 */
	public function testTheGateStopsAcceptingGuessesAfterTooManyAttempts(): void
	{
		$limits = Database::getInstance();
		$limits->exec('TRUNCATE TABLE `' . Database::table('rate_limits') . '`');

		Database::setSetting('login_remember_enabled', '1');
		Database::setSetting('panel_reauth_minutes', 30);
		Database::setSetting('panel_reauth_scope', 'staff');

		try {
			self::$cookies = [];
			self::$csrf = '';
			self::refreshCsrf();
			$signedIn = self::postJson('/api.php?action=user_login', [
				'username' => 'httpadmin',
				'password' => 'HttpAdmin1!',
				'remember' => 3600,
			]);
			$this->assertTrue($signedIn['success'] ?? false, json_encode($signedIn));

			// Drop the session so the device cookie restores it without panel authorization.
			unset(self::$cookies[session_name()], self::$cookies['PHPSESSID']);
			$gate = self::rawRequest('GET', '/panel.php?tab=dashboard');
			$this->assertStringContainsString('value="panel_reauth"', $gate['body']);
			self::refreshCsrf();

			$wrong = static function (): array {
				return self::rawRequest(
					'POST',
					'/panel.php',
					http_build_query([
						'action' => 'panel_reauth',
						'_csrf' => self::$csrf,
						'password' => 'DefinitelyNotIt1!',
					]),
					['Content-Type: application/x-www-form-urlencoded']
				);
			};

			// The 'auth' bucket allows ten in a five-minute window, but the windows are aligned
			// to the clock: a run that starts just before a boundary gets its counter reset
			// mid-way. So keep going until the door shuts rather than assuming attempt eleven
			// is the one — the cap is what matters, not the exact number.
			$body = '';
			$spent = 0;
			for ($attempt = 1; $attempt <= 30; $attempt++) {
				$body = $wrong()['body'];
				$spent = $attempt;
				if (!str_contains($body, 'value="panel_reauth"')) {
					break;
				}
			}
			$this->assertLessThan(30, $spent, 'the gate never stopped accepting guesses');
			$this->assertStringNotContainsString(
				'value="panel_reauth"',
				$body,
				'once the limit is spent the form itself is withdrawn'
			);
			// Language-neutral: the error block is rendered rather than the form.
			$this->assertStringContainsString('class="auth-message error show"', $body);

			// And the correct password is refused too — the cap is on the address, not on luck.
			$right = self::rawRequest(
				'POST',
				'/panel.php',
				http_build_query([
					'action' => 'panel_reauth',
					'_csrf' => self::$csrf,
					'password' => 'HttpAdmin1!',
				]),
				['Content-Type: application/x-www-form-urlencoded']
			);
			$this->assertStringNotContainsString('id="dashFiles"', $right['body']);

			// Clearing the window lets the owner back in.
			$limits->exec('TRUNCATE TABLE `' . Database::table('rate_limits') . '`');
			self::refreshCsrf();
			$reopened = self::rawRequest(
				'POST',
				'/panel.php',
				http_build_query([
					'action' => 'panel_reauth',
					'_csrf' => self::$csrf,
					'password' => 'HttpAdmin1!',
				]),
				['Content-Type: application/x-www-form-urlencoded']
			);
			$this->assertStringContainsString('id="dashFiles"', $reopened['body']);
		} finally {
			$limits->exec('TRUNCATE TABLE `' . Database::table('rate_limits') . '`');
		}
	}

	public function testUnknownActionIsAnError(): void
	{
		$d = self::getJson('/api.php?action=definitely_not_a_route');
		$this->assertFalse($d['success'] ?? true);
	}

	/** An active account of its own, so a scenario does not depend on what earlier ones did. */
	private static function freshUser(string $name, string $password = 'Fresh1Pass!'): int
	{
		$result = Database::registerUser($name, $name . '@example.pl', $password);
		self::assertTrue($result['success'] ?? false, json_encode($result));
		$id = (int) Database::getUserByEmailOrUsername($name)['id'];
		Database::getInstance()->exec(
			"UPDATE `" . Database::table('users') . "` SET `is_active` = 1 WHERE `id` = " . $id
		);
		return $id;
	}

	/** The server process keeps its own settings cache; drop it after changing a setting. */
	private static function serverSettingsChanged(): void
	{
		$cache = PROJECT_ROOT . '/tests/php/.tmp-data-http/settings.cache.json';
		if (is_file($cache)) {
			unlink($cache);
		}
	}

	private static function signIn(string $username, string $password, int $remember = 0): array
	{
		self::$cookies = [];
		self::$csrf = '';
		self::refreshCsrf();
		return self::postJson('/api.php?action=user_login', [
			'username' => $username,
			'password' => $password,
			'remember' => $remember,
		]);
	}

	/**
	 * "Stay signed in" has to work wherever the visitor comes back, not only on the panel:
	 * the home page and every API call restore the session from the device cookie.
	 */
	public function testADeviceCookieRestoresTheSessionOnPublicPagesAndTheApi(): void
	{
		Database::setSetting('login_remember_enabled', '1');
		self::serverSettingsChanged();
		self::freshUser('httpremember');
		$signedIn = self::signIn('httpremember', 'Fresh1Pass!', 3600);
		$this->assertTrue($signedIn['success'] ?? false, json_encode($signedIn));
		$device = self::$cookies['fh_remember'] ?? '';
		$this->assertNotSame('', $device);

		// The browser was closed: only the device cookie is left.
		unset(self::$cookies[session_name()], self::$cookies['PHPSESSID']);
		$count = self::getJson('/api.php?action=notification_count');
		$this->assertSame(200, $count['status'], 'an API call restores the session');
		$this->assertTrue($count['success'] ?? false);
		$this->assertNotSame($device, self::$cookies['fh_remember'] ?? '', 'and rotates the device secret');

		unset(self::$cookies[session_name()], self::$cookies['PHPSESSID']);
		$home = self::rawRequest('GET', '/index.php');
		$this->assertSame(200, $home['status']);
		$this->assertNotSame('', self::$cookies['PHPSESSID'] ?? (self::$cookies[session_name()] ?? ''));
		$this->assertTrue(self::getJson('/api.php?action=notification_count')['success'] ?? false);
	}

	/**
	 * A session restored from a device cookie has not proved who is at the keyboard. The
	 * actions that create administrators, take accounts over or rewire payments ask for the
	 * password first — in the API, not only on the panel page — and accept it from the panel's
	 * inline prompt (`user_reauth`).
	 */
	public function testPrivilegeGrantingActionsNeedAFreshPassword(): void
	{
		Database::setSetting('login_remember_enabled', '1');
		self::serverSettingsChanged();
		$signedIn = self::signIn('httpadmin', 'HttpAdmin1!', 3600);
		$this->assertTrue($signedIn['success'] ?? false, json_encode($signedIn));
		unset(self::$cookies[session_name()], self::$cookies['PHPSESSID']);
		self::refreshCsrf(); // restores the session from the device cookie

		$payload = [
			'username' => 'httpgranted',
			'email' => 'httpgranted@example.pl',
			'password' => 'Granted1Pass!',
			'role' => 'admin',
			'auto_activate' => true,
		];
		$refused = self::postJson('/api.php?action=admin_create_user', $payload);
		$this->assertSame(403, $refused['status']);
		$this->assertSame('recent_auth_required', $refused['code'] ?? null);
		$this->assertNull(Database::getUserByEmailOrUsername('httpgranted'));

		$wrong = self::postJson('/api.php?action=user_reauth', ['password' => 'NotIt1Pass!']);
		$this->assertFalse($wrong['success'] ?? true);
		$right = self::postJson('/api.php?action=user_reauth', ['password' => 'HttpAdmin1!']);
		$this->assertTrue($right['success'] ?? false, json_encode($right));

		$created = self::postJson('/api.php?action=admin_create_user', $payload);
		$this->assertTrue($created['success'] ?? false, json_encode($created));
		$account = Database::getUserByEmailOrUsername('httpgranted');
		$this->assertNotNull($account);

		// No hidden per-account quota: the account gets what its group allows, like any other.
		$this->assertSame(0, (int) $account['storage_limit']);
		$group = Database::getUserEffectiveGroup((int) $account['id']);
		$this->assertSame(
			((int) ($group['storage_quota_mb'] ?? 0)) * 1024 * 1024,
			StorageEnforcer::limitsFor((int) $account['id'])['quota']
		);
		// And creating an account, an administrator here, is in the audit log.
		$audit = Database::getInstance()->prepare(
			"SELECT `details`, `username` FROM `" . Database::table('audit_log') . "`
			 WHERE `action` = 'user_created' ORDER BY `id` DESC LIMIT 1"
		);
		$audit->execute();
		$row = $audit->fetch(PDO::FETCH_ASSOC);
		$this->assertIsArray($row, 'no user_created audit row');
		$this->assertStringContainsString('#' . (int) $account['id'], (string) $row['details']);
		$this->assertStringContainsString('role admin', (string) $row['details']);
		$this->assertSame('httpadmin', $row['username'], 'the row names who created the account');
	}

	/**
	 * "Sign out other devices" ends the other browsers' sessions, not only their device
	 * tokens: a laptop that is signed in right now is the case the button exists for.
	 */
	public function testSigningOutOtherDevicesEndsTheirLiveSessions(): void
	{
		self::freshUser('httptwodevices', 'TwoDevices1!');
		$laptop = self::signIn('httptwodevices', 'TwoDevices1!');
		$this->assertTrue($laptop['success'] ?? false, json_encode($laptop));
		$laptopJar = self::$cookies;

		$phone = self::signIn('httptwodevices', 'TwoDevices1!');
		$this->assertTrue($phone['success'] ?? false);
		$revoked = self::postJson('/api.php?action=user_remember_revoke', ['password' => 'TwoDevices1!']);
		$this->assertTrue($revoked['success'] ?? false, json_encode($revoked));
		$this->assertTrue(
			self::getJson('/api.php?action=notification_count')['success'] ?? false,
			'the device that pressed the button stays signed in'
		);

		$phoneJar = self::$cookies;
		self::$cookies = $laptopJar;
		$this->assertNotSame(200, self::getJson('/api.php?action=notification_count')['status']);
		self::$cookies = $phoneJar;
	}

	/** Changing the password keeps the browser that did it signed in, like the 2FA flows do. */
	public function testChangingThePasswordKeepsThisBrowserSignedIn(): void
	{
		self::freshUser('httppwchange', 'Before1Pass!');
		$this->assertTrue(self::signIn('httppwchange', 'Before1Pass!')['success'] ?? false);
		$changed = self::postJson('/api.php?action=user_change_password', [
			'current_password' => 'Before1Pass!',
			'new_password' => 'After1Pass!',
		]);
		$this->assertTrue($changed['success'] ?? false, json_encode($changed));
		$this->assertTrue(self::getJson('/api.php?action=notification_count')['success'] ?? false);
	}

	/** Maintenance keeps every step of signing in open, or a 2FA administrator is locked out. */
	public function testMaintenanceLeavesTheWholeSignInOpen(): void
	{
		self::$cookies = [];
		self::refreshCsrf();
		Database::setSetting('maintenance_mode', '1');
		self::serverSettingsChanged();
		try {
			$this->assertSame(200, self::getJson('/api.php?action=config')['status']);
			$this->assertSame(200, self::getJson('/api.php?action=captcha_config')['status']);
			$twoFactor = self::postJson('/api.php?action=user_2fa_login', ['code' => '123456']);
			$this->assertSame(200, $twoFactor['status'], 'the 2FA step is reachable');
			$this->assertSame(503, self::getJson('/api.php?action=check_username&username=someone')['status']);
		} finally {
			Database::setSetting('maintenance_mode', '0');
			self::serverSettingsChanged();
		}
	}

	/** Sign in from the maintenance notice's own sign-in page (the home page is closed then). */
	private static function signInDuringMaintenance(string $username, string $password): array
	{
		self::$cookies = [];
		self::$csrf = '';
		$page = self::rawRequest('GET', '/?action=login');
		self::assertMatchesRegularExpression('/name="csrf-token" content="([a-f0-9]{64})"/', $page['body']);
		preg_match('/name="csrf-token" content="([a-f0-9]{64})"/', $page['body'], $m);
		self::$csrf = $m[1];
		return self::postJson('/api.php?action=user_login', ['username' => $username, 'password' => $password]);
	}

	/**
	 * Maintenance closes every page, the panel and both APIs to anyone but an administrator,
	 * and leaves a way in: the notice links to `?action=login`, which shows it with the sign-in
	 * dialog. Payment notifications are still answered.
	 */
	public function testMaintenanceClosesTheSiteButLeavesAWayIn(): void
	{
		$keys = [];
		foreach (['admin' => (int) Database::getUserByEmailOrUsername('httpadmin')['id'],
			'user' => self::freshUser('httpmaintuser', 'MaintUser1!')] as $who => $ownerId) {
			$keys[$who] = 'fh_' . bin2hex(random_bytes(24));
			Database::createApiKey($ownerId, hash('sha256', $keys[$who]), substr($keys[$who], 0, 11), 'HTTP ' . $who . ' key');
		}
		Database::setSetting('maintenance_mode', '1');
		self::serverSettingsChanged();
		try {
			self::$cookies = [];
			$home = self::rawRequest('GET', '/');
			$this->assertSame(503, $home['status']);
			$this->assertStringContainsString('href="' . self::$base . '/?action=login"', $home['body']);
			$this->assertStringNotContainsString('href="' . self::$base . '/"', $home['body'], 'no link back to itself');
			$this->assertStringNotContainsString('id="loginForm"', $home['body']);

			$signInPage = self::rawRequest('GET', '/?action=login');
			$this->assertSame(503, $signInPage['status']);
			$this->assertStringContainsString('id="loginForm"', $signInPage['body']);
			foreach (['/assets/js/api.js', '/assets/js/auth.js', '/assets/css/base.css'] as $asset) {
				$this->assertStringContainsString($asset, $signInPage['body']);
			}

			foreach (['/download.php?id=doesnotexist1', '/collection.php?id=doesnotexist1', '/premium.php'] as $page) {
				$this->assertSame(503, self::rawRequest('GET', $page)['status'], $page);
			}
			// Payment notifications are answered (refused here for lacking a signature), not 503.
			$notify = self::rawRequest('POST', '/api.php?action=payu_notify', '{}', ['Content-Type: application/json']);
			$this->assertNotSame(503, $notify['status']);
			// The key-authenticated API answers only an administrator's key.
			$this->assertSame(503, self::rawRequest('GET', '/api/v1/whoami', null, ['Authorization: Bearer ' . $keys['user']])['status']);
			$this->assertSame(200, self::rawRequest('GET', '/api/v1/whoami', null, ['Authorization: Bearer ' . $keys['admin']])['status']);

			// An ordinary account may sign in, but the panel is closed to it as well.
			$this->assertTrue(self::signInDuringMaintenance('httpmaintuser', 'MaintUser1!')['success'] ?? false);
			$panel = self::rawRequest('GET', '/panel.php?tab=myfiles');
			$this->assertSame(503, $panel['status']);
			$this->assertStringNotContainsString('id="myFilesBody"', $panel['body']);

			// The administrator signs in from the notice and works as usual.
			$this->assertTrue(self::signInDuringMaintenance('httpadmin', 'HttpAdmin1!')['success'] ?? false);
			$this->assertSame(200, self::rawRequest('GET', '/')['status']);
			$adminPanel = self::rawRequest('GET', '/panel.php?tab=dashboard');
			$this->assertSame(200, $adminPanel['status']);
			$this->assertStringContainsString('id="dashFiles"', $adminPanel['body']);
		} finally {
			Database::setSetting('maintenance_mode', '0');
			self::serverSettingsChanged();
			self::$cookies = [];
		}
	}

	/** A notice page is not a dead end: the switched-off plans page links back home. */
	public function testTheSwitchedOffPlansPageLinksBackHome(): void
	{
		self::$cookies = [];
		$page = self::rawRequest('GET', '/premium.php');
		$this->assertSame(404, $page['status']);
		$this->assertStringContainsString('class="onward" href="' . self::$base . '/"', $page['body']);
	}

	/** Pages print outcome messages they own, chosen by code; never the query string itself. */
	public function testOutcomeMessagesComeFromCodesNotFromTheQueryString(): void
	{
		$verified = self::rawRequest('GET', '/index.php?msg=verified');
		$this->assertStringContainsString('class="auth-message show success"', $verified['body']);
		$forged = self::rawRequest('GET', '/index.php?msg=' . rawurlencode('<b>Call +48 000</b>'));
		$this->assertStringNotContainsString('auth-message show', $forged['body']);
		$this->assertStringNotContainsString('Call +48 000', $forged['body']);

		$failed = self::rawRequest('GET', '/api.php?action=verify_email&token=' . str_repeat('a', 64));
		$this->assertStringContainsString('class="auth-message show error"', $failed['body']);

		self::freshUser('httpnotice');
		$this->assertTrue(self::signIn('httpnotice', 'Fresh1Pass!')['success'] ?? false);
		$tab = self::rawRequest('GET', '/panel.php?tab=user&err=' . rawurlencode('Your account is suspended, call us'));
		$this->assertSame(200, $tab['status']);
		$this->assertStringNotContainsString('Your account is suspended', $tab['body']);
	}

	/** A second recovery request moments later leaves the first link alone and mails nothing. */
	public function testRecoveryIsNotResentForTheSameAccountWithinMinutes(): void
	{
		self::freshUser('httprecover');
		$mails = static fn(): int => (int) Database::getInstance()->query(
			"SELECT COUNT(*) FROM `" . Database::table('mail_outbox') . "` WHERE `to_email` = 'httprecover@example.pl'"
		)->fetchColumn();
		self::$cookies = [];
		self::refreshCsrf();
		$first = self::postJson('/api.php?action=recover_password', ['input' => 'httprecover']);
		$this->assertTrue($first['success'] ?? false, json_encode($first));
		$this->assertSame(1, $mails());
		$second = self::postJson('/api.php?action=recover_password', ['input' => 'httprecover']);
		$this->assertTrue($second['success'] ?? false, 'the answer looks the same');
		$this->assertSame(1, $mails(), 'but nothing new was queued');
	}

	/** Availability checks typed live in the sign-up form have their own, looser budget. */
	public function testAvailabilityChecksDoNotSpendTheSignInBudget(): void
	{
		Database::getInstance()->exec('TRUNCATE TABLE `' . Database::table('rate_limits') . '`');
		for ($i = 0; $i < 12; $i++) {
			$check = self::getJson('/api.php?action=check_username&username=someone' . $i);
			$this->assertSame(200, $check['status'], 'check ' . $i);
		}
		$this->assertNotSame(429, self::signIn('httpadmin', 'HttpAdmin1!')['status']);
	}

	/** A JSON POST that arrives through the trusted test proxy on behalf of $ip. */
	private static function postJsonFrom(string $ip, string $path, array $payload): array
	{
		return self::postJson($path, $payload, true, ['X-Forwarded-For: ' . $ip]);
	}

	/**
	 * Wrong passwords from a stranger cannot keep the owner out. Without a captcha the network
	 * that ran out of guesses waits out the window for that account — the right password
	 * included — while the owner, signing in from anywhere else, gets straight in.
	 */
	public function testAStrangerCannotLockAnAccountOutOfSigningIn(): void
	{
		$pdo = Database::getInstance();
		$limits = Database::table('rate_limits');
		$pdo->exec("TRUNCATE TABLE `{$limits}`");
		Database::setSetting('recaptcha_enabled', '0');
		self::serverSettingsChanged();
		$victimId = self::freshUser('httplockvictim', 'Victim1Pass!');

		try {
			self::$cookies = [];
			self::$csrf = '';
			self::refreshCsrf();
			// The per-address `auth` budget (10 per 5 minutes) would answer 429 on its own first,
			// so its rows are cleared as the run goes: what is measured is the account limit.
			// The 15-minute windows are aligned to the clock, so keep going until the network is
			// shut out rather than assuming which attempt does it.
			$refused = null;
			for ($attempt = 1; $attempt <= 25 && $refused === null; $attempt++) {
				$pdo->exec("DELETE FROM `{$limits}` WHERE `bucket` LIKE 'ip:%'");
				$answer = self::postJsonFrom('203.0.113.7', '/api.php?action=user_login', [
					'username' => 'httplockvictim',
					'password' => 'Wrong' . $attempt . 'Pass!',
				]);
				if ($answer['status'] === 429) {
					$refused = $attempt;
				}
			}
			$this->assertNotNull($refused, 'the guessing network was never shut out');
			$this->assertGreaterThan(10, $refused, 'ten wrong passwords are allowed first');
			$pdo->exec("DELETE FROM `{$limits}` WHERE `bucket` LIKE 'ip:%'");
			$sameNetwork = self::postJsonFrom('203.0.113.7', '/api.php?action=user_login', [
				'username' => 'httplockvictim',
				'password' => 'Victim1Pass!',
			]);
			$this->assertSame(429, $sameNetwork['status'], 'the guessing network waits, right password or not');
			$this->assertTrue(RateLimiter::exceeded(AuthController::loginAccountBucket($victimId), 'login_account'));

			// The owner, in a browser of their own, from their own network.
			self::$cookies = [];
			self::$csrf = '';
			self::refreshCsrf();
			$owner = self::postJsonFrom('198.51.100.9', '/api.php?action=user_login', [
				'username' => 'httplockvictim',
				'password' => 'Victim1Pass!',
			]);
			$this->assertSame(200, $owner['status'], json_encode($owner));
			$this->assertTrue($owner['success'] ?? false, json_encode($owner));
		} finally {
			$pdo->exec("TRUNCATE TABLE `{$limits}`");
		}
	}

	/**
	 * html, svg, xml and friends stay blocked whatever is saved: they are kept on the list at
	 * save time instead of being put back silently by the schema check minutes later.
	 */
	public function testSavingStorageSettingsKeepsActiveDocumentTypesBlocked(): void
	{
		$original = [
			'blocked_extensions' => (string) Database::getSetting('blocked_extensions', ''),
			'system_max_file_size_mb' => (string) Database::getSetting('system_max_file_size_mb', '5120'),
		];
		$this->assertTrue(self::signIn('httpadmin', 'HttpAdmin1!')['success'] ?? false);
		try {
			$saved = self::rawRequest(
				'POST',
				'/panel.php?tab=settings&stab=storage',
				http_build_query([
					'action' => 'save_settings',
					'setting_group' => 'storage',
					'_csrf' => self::$csrf,
					'blocked_extensions' => 'exe, .SVG',
					'max_upload_folder_mb' => '0',
					'upload_folder_unit' => 'MB',
					'system_max_file_size_mb' => '10240',
					'system_max_unit' => 'MB',
					'auto_delete_days' => '0',
					'thumbnail_max_px' => (string) Database::getSetting('thumbnail_max_px', 400),
				]),
				['Content-Type: application/x-www-form-urlencoded']
			);
			$this->assertSame(200, $saved['status']);
			Database::invalidateSettingsCache();
			$blocked = explode(',', (string) Database::getSetting('blocked_extensions', ''));
			foreach (array_merge(['exe', 'svg'], Database::ACTIVE_DOCUMENT_EXTENSIONS) as $extension) {
				$this->assertContains($extension, $blocked);
			}
		} finally {
			foreach ($original as $key => $value) {
				Database::setSetting($key, $value);
			}
			self::serverSettingsChanged();
		}
	}

	/** The panel gate sends the administrator back to the view they asked for. */
	public function testThePanelGateReturnsToTheRequestedView(): void
	{
		Database::setSetting('login_remember_enabled', '1');
		self::serverSettingsChanged();
		$this->assertTrue(self::signIn('httpadmin', 'HttpAdmin1!', 3600)['success'] ?? false);
		unset(self::$cookies[session_name()], self::$cookies['PHPSESSID']);
		$gate = self::rawRequest('GET', '/panel.php?tab=users');
		$this->assertStringContainsString('value="panel_reauth"', $gate['body']);
		self::refreshCsrf();
		$back = self::rawRequest(
			'POST',
			'/panel.php?tab=users',
			http_build_query(['action' => 'panel_reauth', '_csrf' => self::$csrf, 'password' => 'HttpAdmin1!']),
			['Content-Type: application/x-www-form-urlencoded']
		);
		$this->assertSame(200, $back['status']);
		$this->assertStringContainsString('id="usersBody"', $back['body']);

		// And a GET can no longer sign anyone out: logging out is a CSRF-protected POST.
		$stillIn = self::rawRequest('GET', '/panel.php?logout=1&tab=users');
		$this->assertStringContainsString('id="usersBody"', $stillIn['body']);
	}

	/**
	 * `name[]=x` turns a parameter into an array. The public pages and the availability checks
	 * used to answer that with an uncaught TypeError (500 and a "PHP Fatal error" line), and the
	 * language parameter or cookie with a warning on every page. Such a field now reads as absent.
	 */
	public function testArrayShapedParametersReadAsMissing(): void
	{
		$errLog = PROJECT_ROOT . '/tests/php/.tmp-data-http/server.err.log';
		$logBefore = is_file($errLog) ? (int) filesize($errLog) : 0;
		self::$cookies = [];

		foreach (['check_username&username%5B%5D=x', 'check_email&email%5B%5D=x'] as $query) {
			$check = self::getJson('/api.php?action=' . $query);
			$this->assertSame(200, $check['status'], $query);
			$this->assertFalse($check['available'] ?? true, $query . ' is answered as an empty value');
		}
		$phpError = '/TypeError|Array to string conversion|\b(?:Warning|Fatal error|Deprecated|Notice)\b(?:<\/b>)?:\s/';
		foreach ([
			'/download.php?id%5B%5D=x',
			'/collection.php?id%5B%5D=x',
			'/index.php?lang%5B%5D=x',
			'/premium.php?err%5B%5D=x&order%5B%5D=y',
			'/api.php?action=health&lang%5B%5D=x',
		] as $path) {
			$page = self::rawRequest('GET', $path);
			$this->assertLessThan(500, $page['status'], $path);
			$this->assertDoesNotMatchRegularExpression($phpError, $page['body'], $path);
		}
		// A cookie can carry the same shape.
		self::$cookies = ['lang[]' => 'x'];
		$home = self::rawRequest('GET', '/index.php');
		$this->assertSame(200, $home['status']);
		$this->assertDoesNotMatchRegularExpression($phpError, $home['body']);
		self::$cookies = [];
		// ...and so can every other reader, whoever wrote it: a file id, the panel's sub-tab and
		// the account tab's outcome code.
		$this->assertLessThan(500, self::rawRequest('GET', '/api.php?action=preview&id%5B%5D=x')['status']);
		self::freshUser('httparrayshape', 'ArrayShape1!');
		$this->assertTrue(self::signIn('httparrayshape', 'ArrayShape1!')['success'] ?? false);
		foreach (['/panel.php?tab=moderate&mstab%5B%5D=x', '/panel.php?tab=user&msg%5B%5D=x&err%5B%5D=y'] as $path) {
			$panel = self::rawRequest('GET', $path);
			$this->assertSame(200, $panel['status'], $path);
			$this->assertDoesNotMatchRegularExpression($phpError, $panel['body'], $path);
		}

		clearstatcache();
		$logged = is_file($errLog) ? (string) file_get_contents($errLog, false, null, $logBefore) : '';
		foreach (['TypeError', 'Array to string conversion'] as $symptom) {
			$this->assertStringNotContainsString($symptom, $logged);
		}
	}

	/**
	 * The site name is plain text in every page head and header — whether it was stored before
	 * this check existed or is saved from Settings -> General now.
	 */
	public function testTheSiteNameIsAlwaysPlainText(): void
	{
		$original = [];
		foreach (['app_name', 'default_language', 'maintenance_mode', 'maintenance_message', 'audit_retention_days'] as $key) {
			$original[$key] = (string) Database::getSetting($key, '');
		}
		$hostile = '</title><meta http-equiv="refresh" content="0"> & Co';
		Database::setSetting('app_name', $hostile);
		self::serverSettingsChanged();
		try {
			self::$cookies = [];
			foreach (['/index.php', '/download.php?id=doesnotexist1', '/collection.php?id=doesnotexist1'] as $page) {
				$body = self::rawRequest('GET', $page)['body'];
				$this->assertStringNotContainsString('<meta http-equiv="refresh"', $body, $page);
				$this->assertStringContainsString(
					'/title meta http-equiv=&quot;refresh&quot; content=&quot;0&quot; &amp; Co',
					$body,
					$page
				);
			}

			$this->assertTrue(self::signIn('httpadmin', 'HttpAdmin1!')['success'] ?? false);
			$saved = self::postPanelForm('/panel.php?tab=settings&stab=general', [
				'action' => 'save_settings',
				'setting_group' => 'general',
				'app_name' => "<b>Files</b>\r\n" . str_repeat('x', 60),
				'default_language' => 'en',
				'audit_retention_days' => '30',
			]);
			$this->assertSame(200, $saved['status']);
			$this->assertStringContainsString('alert alert-success', $saved['body']);
			Database::invalidateSettingsCache();
			$stored = (string) Database::getSetting('app_name');
			$this->assertSame(InputLimits::appName("<b>Files</b>\r\n" . str_repeat('x', 60)), $stored);
			$this->assertStringNotContainsString('<', $stored);
			$this->assertSame(InputLimits::APP_NAME_MAX, mb_strlen($stored));
		} finally {
			foreach ($original as $key => $value) {
				Database::setSetting($key, $value);
			}
			self::serverSettingsChanged();
		}
	}

	/**
	 * A stolen session (or device cookie) used to get a separate guessing budget at every action
	 * that checks the account password, per address and per fresh session. Wrong passwords now
	 * count against one budget per account, across all of them; signing in is not affected.
	 */
	public function testInSessionPasswordGuessesShareOneBudgetPerAccount(): void
	{
		$pdo = Database::getInstance();
		$limits = Database::table('rate_limits');
		$pdo->exec("TRUNCATE TABLE `{$limits}`");
		self::freshUser('httpguessed', 'Guessed1Pass!');
		try {
			$this->assertTrue(self::signIn('httpguessed', 'Guessed1Pass!')['success'] ?? false);
			$actions = ['user_2fa_setup', 'user_remember_revoke', 'user_2fa_disable', 'user_delete_account'];
			$refusedAfter = null;
			for ($attempt = 1; $attempt <= 25 && $refusedAfter === null; $attempt++) {
				// Only the account budget is measured, not the per-address one of each action.
				$pdo->exec("DELETE FROM `{$limits}` WHERE `bucket` LIKE 'ip:%' OR `bucket` LIKE 'reauth:%'");
				$answer = self::postJson('/api.php?action=' . $actions[$attempt % count($actions)], [
					'password' => 'NotIt' . $attempt . 'Pass!',
				]);
				if ($answer['status'] === 429) {
					$refusedAfter = $attempt;
				} else {
					$this->assertFalse($answer['success'] ?? true, 'a wrong password was accepted');
				}
			}
			$this->assertNotNull($refusedAfter, 'the account never ran out of guesses');
			$this->assertGreaterThan(10, $refusedAfter, 'ten wrong passwords are allowed first');

			// The right password is refused too, at every action, the re-confirmation prompt included.
			$pdo->exec("DELETE FROM `{$limits}` WHERE `bucket` LIKE 'ip:%' OR `bucket` LIKE 'reauth:%'");
			$this->assertSame(429, self::postJson('/api.php?action=user_reauth', ['password' => 'Guessed1Pass!'])['status']);
			$this->assertSame(429, self::postJson('/api.php?action=user_2fa_setup', ['password' => 'Guessed1Pass!'])['status']);

			// Signing in has its own budget: the owner can still get in from a fresh browser.
			$this->assertTrue(self::signIn('httpguessed', 'Guessed1Pass!')['success'] ?? false);
		} finally {
			$pdo->exec("TRUNCATE TABLE `{$limits}`");
		}
	}

	/** Make this browser's last password check look $seconds older (edits its session file). */
	private static function ageRecentAuthentication(int $seconds): void
	{
		$id = self::$cookies[session_name()] ?? (self::$cookies['PHPSESSID'] ?? '');
		self::assertNotSame('', $id, 'the browser holds a session');
		$file = PROJECT_ROOT . '/tests/php/.tmp-data-http/sessions/sess_' . $id;
		self::assertFileExists($file);
		$aged = preg_replace_callback(
			'/(recent_auth_at(?:\||";)i:)(\d+);/',
			static fn(array $m): string => $m[1] . ((int) $m[2] - $seconds) . ';',
			(string) file_get_contents($file),
			1,
			$count
		);
		self::assertSame(1, $count, 'the session records a password check');
		file_put_contents($file, (string) $aged);
	}

	/** A classic panel form POST with this browser's CSRF token. */
	private static function postPanelForm(string $path, array $fields): array
	{
		return self::rawRequest(
			'POST',
			$path,
			http_build_query($fields + ['_csrf' => self::$csrf]),
			['Content-Type: application/x-www-form-urlencoded']
		);
	}

	/**
	 * The panel's own settings forms follow the API's rule for sensitive changes: a password
	 * typed in the last few minutes, not merely an open panel window. Rerouting mail, switching
	 * the panel gate off or naming the binary the upload server runs is refused, and nothing is
	 * stored, until the password has been confirmed again.
	 */
	public function testPanelSettingsNeedAFreshPassword(): void
	{
		$keys = [
			'email_method', 'email_from', 'email_from_name', 'email_resend_cooldown', 'smtp_host',
			'smtp_port', 'smtp_user', 'smtp_encryption', 'email_php_mail_guard',
			'panel_reauth_minutes', 'panel_reauth_scope', 'cron_enabled', 'cron_php_binary',
		];
		$original = [];
		foreach ($keys as $key) {
			$original[$key] = (string) Database::getSetting($key, '');
		}
		Database::setSetting('smtp_host', 'smtp.example.test');
		Database::setSetting('panel_reauth_minutes', '30');
		Database::setSetting('panel_reauth_scope', 'staff');
		Database::setSetting('cron_php_binary', '');
		self::serverSettingsChanged();
		$mailForm = [
			'action' => 'save_settings',
			'setting_group' => 'email',
			'email_method' => 'smtp',
			'email_from' => 'noreply@example.test',
			'email_from_name' => 'FileHostTest',
			'email_resend_cooldown' => '30',
			'smtp_port' => '587',
			'smtp_user' => '',
			'smtp_encryption' => 'tls',
			'email_php_mail_guard' => 'fail',
		];

		try {
			$this->assertTrue(self::signIn('httpadmin', 'HttpAdmin1!')['success'] ?? false);
			// The panel window is open; the last password check is an hour old.
			$this->assertStringContainsString(
				'id="emailMethod"',
				self::rawRequest('GET', '/panel.php?tab=settings&stab=email')['body']
			);
			self::ageRecentAuthentication(3600);

			$mail = self::postPanelForm(
				'/panel.php?tab=settings&stab=email',
				$mailForm + ['smtp_host' => 'mail.attacker.example']
			);
			$this->assertSame(403, $mail['status']);
			$this->assertStringContainsString('value="panel_reauth"', $mail['body']);
			$this->assertStringContainsString('class="reauth-notice"', $mail['body']);
			$gateOff = self::postPanelForm('/panel.php?tab=settings&stab=security', [
				'action' => 'save_settings',
				'setting_group' => 'security',
				'panel_reauth_minutes' => '0',
			]);
			$this->assertSame(403, $gateOff['status']);
			$binary = self::postPanelForm('/panel.php?tab=settings&stab=system', [
				'action' => 'save_cron',
				'cron_enabled' => '1',
				'cron_php_binary' => '/tmp/not-php',
			]);
			$this->assertSame(403, $binary['status']);
			Database::invalidateSettingsCache();
			$this->assertSame('smtp.example.test', (string) Database::getSetting('smtp_host'));
			$this->assertSame('30', (string) Database::getSetting('panel_reauth_minutes'));
			$this->assertSame('', (string) Database::getSetting('cron_php_binary', ''));

			// Confirming the password returns to the same view, and the change then goes through.
			$back = self::postPanelForm('/panel.php?tab=settings&stab=email', [
				'action' => 'panel_reauth',
				'password' => 'HttpAdmin1!',
			]);
			$this->assertSame(200, $back['status']);
			$this->assertStringContainsString('id="emailMethod"', $back['body']);
			$saved = self::postPanelForm(
				'/panel.php?tab=settings&stab=email',
				$mailForm + ['smtp_host' => 'smtp2.example.test']
			);
			$this->assertSame(200, $saved['status']);
			Database::invalidateSettingsCache();
			$this->assertSame('smtp2.example.test', (string) Database::getSetting('smtp_host'));
		} finally {
			foreach ($original as $key => $value) {
				Database::setSetting($key, $value);
			}
			self::serverSettingsChanged();
		}
	}

	/** Key-authenticated API calls open no browser session. */
	public function testTheKeyAuthenticatedApiSetsNoSessionCookie(): void
	{
		self::$cookies = [];
		$res = self::rawRequest('GET', '/api/v1/whoami', null, ['Authorization: Bearer ' . self::$apiKeyTwo]);
		$this->assertContains($res['status'], [200, 401]);
		$this->assertSame([], preg_grep('/^Set-Cookie:/i', $res['headers']));
	}

	private static function uploadTokenOwner(string $token): ?int
	{
		$stmt = Database::getInstance()->prepare(
			"SELECT `user_id` FROM `" . Database::table('upload_tokens') . "` WHERE `token` = ?"
		);
		$stmt->execute([$token]);
		$value = $stmt->fetchColumn();
		return $value === false || $value === null ? null : (int) $value;
	}

	/**
	 * The upload token decides whose an upload is. Signing out revokes the tab's token, and
	 * signing in while the tab still holds another account's token hands out a new one, so
	 * later uploads in the same tab are never attributed to the previous account.
	 */
	public function testTheUploadTokenFollowsWhoeverIsSignedInInTheTab(): void
	{
		$first = self::freshUser('httptokenfirst');
		$second = self::freshUser('httptokensecond');

		$this->assertTrue(self::signIn('httptokenfirst', 'Fresh1Pass!')['success'] ?? false);
		$token = (string) (self::postJson('/api.php?action=verify_captcha', [])['token'] ?? '');
		$this->assertNotSame('', $token);
		$this->assertSame($first, self::uploadTokenOwner($token));

		// The second person signs in in the same tab, which still holds the first one's token.
		self::refreshCsrf();
		$switched = self::postJson('/api.php?action=user_login', [
			'username' => 'httptokensecond',
			'password' => 'Fresh1Pass!',
			'upload_token' => $token,
		]);
		$this->assertTrue($switched['success'] ?? false, json_encode($switched));
		$handed = (string) ($switched['upload_token'] ?? '');
		$this->assertNotSame('', $handed, 'a token of the new account is handed back');
		$this->assertNotSame($token, $handed);
		$this->assertSame($second, self::uploadTokenOwner($handed));
		$this->assertGreaterThan(time(), (int) ($switched['upload_token_expires_at'] ?? 0));

		// Signing out takes the tab's token with it.
		$this->assertTrue(self::postJson('/api.php?action=user_logout', ['upload_token' => $handed])['success'] ?? false);
		$this->assertNull(self::uploadTokenOwner($handed));

		// An anonymous token is still simply claimed on sign-in.
		self::$cookies = [];
		self::refreshCsrf();
		$guest = (string) (self::postJson('/api.php?action=verify_captcha', [])['token'] ?? '');
		$this->assertNull(self::uploadTokenOwner($guest));
		$claimed = self::postJson('/api.php?action=user_login', [
			'username' => 'httptokenfirst',
			'password' => 'Fresh1Pass!',
			'upload_token' => $guest,
		]);
		$this->assertTrue($claimed['success'] ?? false);
		$this->assertArrayNotHasKey('upload_token', $claimed, 'the page keeps the one it has');
		$this->assertSame($first, self::uploadTokenOwner($guest));
	}

	/** Changing the password keeps this browser remembered, not only signed in. */
	public function testChangingThePasswordKeepsThisBrowserRemembered(): void
	{
		Database::setSetting('login_remember_enabled', '1');
		self::serverSettingsChanged();
		$userId = self::freshUser('httpkeepremembered', 'Before1Pass!');
		$this->assertTrue(self::signIn('httpkeepremembered', 'Before1Pass!', 3600)['success'] ?? false);
		$device = self::$cookies['fh_remember'] ?? '';
		$this->assertNotSame('', $device);

		$changed = self::postJson('/api.php?action=user_change_password', [
			'current_password' => 'Before1Pass!',
			'new_password' => 'After1Pass!',
		]);
		$this->assertTrue($changed['success'] ?? false, json_encode($changed));
		$this->assertNotSame($device, self::$cookies['fh_remember'] ?? '', 'a new device token replaces the revoked one');

		$rows = Database::getInstance()->prepare(
			"SELECT `expires_at` FROM `" . Database::table('remember_tokens') . "` WHERE `user_id` = ?"
		);
		$rows->execute([$userId]);
		$expires = $rows->fetchAll(PDO::FETCH_COLUMN);
		$this->assertCount(1, $expires);
		$this->assertLessThanOrEqual(time() + 3600, (int) $expires[0], 'for no longer than the old one had left');

		// The browser closes: the new device token brings the session back.
		unset(self::$cookies[session_name()], self::$cookies['PHPSESSID']);
		$this->assertTrue(self::getJson('/api.php?action=notification_count')['success'] ?? false);
	}

	/**
	 * Every view of an embedded image counts as a download, so embed codes for a one-time or
	 * download-capped image would burn the link before anyone pressed Download.
	 */
	public function testEmbedCodesAreNotOfferedForOneTimeOrCappedImages(): void
	{
		$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
		$cases = ['plain' => [0, null], 'once' => [1, null], 'capped' => [0, 5]];
		$pages = [];
		foreach ($cases as $label => [$oneTime, $maxDownloads]) {
			$id = 'httpembed' . $label . bin2hex(random_bytes(4));
			$dir = PROJECT_ROOT . '/tests/php/.tmp-uploads-http/' . $id;
			mkdir($dir, 0777, true);
			file_put_contents($dir . '/pixel.png', $png);
			Database::getInstance()->prepare(
				'INSERT INTO `' . Database::table('files') . '`
				 (`id`, `original_name`, `mime_type`, `size`, `delete_token`, `uploaded_at`, `uploaded_ip`,
				  `downloads`, `user_id`, `one_time`, `max_downloads`)
				 VALUES (?, ?, ?, ?, ?, ?, ?, 0, NULL, ?, ?)'
			)->execute([
				$id, 'pixel.png', 'image/png', strlen($png), password_hash('x', PASSWORD_DEFAULT),
				time(), '203.0.113.10', $oneTime, $maxDownloads,
			]);
			$pages[$label] = self::rawRequest('GET', '/download.php?id=' . $id);
		}

		$this->assertSame(200, $pages['plain']['status']);
		$this->assertStringContainsString('id="embedToggleBtn"', $pages['plain']['body']);
		foreach (['once', 'capped'] as $label) {
			$this->assertSame(200, $pages[$label]['status'], $label);
			$this->assertStringNotContainsString('id="embedToggleBtn"', $pages[$label]['body'], $label);
			$this->assertStringNotContainsString('action=embed', $pages[$label]['body'], $label);
		}
	}
}
