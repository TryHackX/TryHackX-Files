<?php

use PHPUnit\Framework\TestCase;

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
				// `action=health` answers {success, php:{version,...}, python, python_running}.
				// Match on that shape, not on a bare 200, so a stranger's page cannot pass.
				$decoded = json_decode((string) ($res['body'] ?? ''), true);
				if (is_array($decoded) && isset($decoded['php']['version'])) {
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
		$this->assertStringContainsString('/assets/css/panel-forms.css', $res['body']);
		$this->assertStringContainsString('/assets/css/panel-shell.css', $res['body']);
		$this->assertStringContainsString('/assets/css/panel-features.css', $res['body']);
		$this->assertStringContainsString('/assets/css/panel-premium.css', $res['body']);
		$this->assertStringContainsString('/assets/css/panel-ads-admin.css', $res['body']);
		$this->assertStringContainsString('/assets/js/panel-docs.js', $res['body']);
		$this->assertStringContainsString('/assets/js/panel-account-tools.js', $res['body']);
		$this->assertStringContainsString('/assets/js/panel-files.js', $res['body']);
		$this->assertStringContainsString('/assets/js/panel-users.js', $res['body']);
		$this->assertStringContainsString('/assets/js/panel-settings.js', $res['body']);
		$this->assertStringContainsString('/assets/js/panel-languages.js', $res['body']);
		$this->assertStringContainsString('/assets/js/panel-groups.js', $res['body']);
		$this->assertStringContainsString('/assets/js/panel-moderation.js', $res['body']);
		$this->assertStringContainsString('/assets/js/panel-dashboard.js', $res['body']);
		$this->assertStringContainsString('/assets/js/panel-premium.js', $res['body']);
		$this->assertStringContainsString('/assets/js/panel-ads.js', $res['body']);
		$this->assertStringContainsString('/assets/js/panel-events.js', $res['body']);
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

	public function testUnknownActionIsAnError(): void
	{
		$d = self::getJson('/api.php?action=definitely_not_a_route');
		$this->assertFalse($d['success'] ?? true);
	}
}
