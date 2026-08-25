<?php

/**
 * Outbox delivery over SMTP: what reaches the wire, and what a refusal leaves behind.
 *
 * The `local` transport exists because PHP mail() cannot submit through Postfix's setgid
 * postdrop under `NoNewPrivileges` — it blocks in the helper instead of failing, which stopped
 * this application's mail for four days in August 2026. Everything here is therefore about
 * bounded failure as much as about successful delivery.
 */
final class MailTransportTest extends RepoTestCase
{
	/** @var list<array{0:resource,1:array<int,resource>}> */
	private array $servers = [];
	/** @var list<string> */
	private array $scratch = [];

	protected function setUp(): void
	{
		$this->truncate('mail_outbox');
		Database::setSetting('email_from', 'noreply@example.test');
		Database::setSetting('email_from_name', 'Frozen Sender');
		Database::setSetting('email_method', 'local');
	}

	protected function tearDown(): void
	{
		foreach ($this->servers as [$process, $pipes]) {
			foreach ($pipes as $pipe) {
				if (is_resource($pipe)) {
					fclose($pipe);
				}
			}
			if (is_resource($process)) {
				proc_terminate($process);
				proc_close($process);
			}
		}
		$this->servers = [];
		foreach ($this->scratch as $path) {
			if (is_file($path)) {
				unlink($path);
			}
		}
		$this->scratch = [];
		putenv('FILEHOST_LOCAL_MTA');
		Database::setSetting('email_method', 'php');
	}

	public function testConfiguredMethodFallsBackToPhpForAnythingUnknown(): void
	{
		Database::setSetting('email_method', 'sendgrid');
		$this->assertSame('php', MailService::method());
		Database::setSetting('email_method', 'LOCAL');
		$this->assertSame('local', MailService::method());
		Database::setSetting('email_method', 'smtp');
		$this->assertSame('smtp', MailService::method());
	}

	public function testLocalTransportSubmitsTheFrozenMessageToTheLoopbackMta(): void
	{
		$transcriptPath = $this->startStubServer();
		$this->assertTrue(MailService::send(
			'user@example.test',
			'Delivered subject',
			"First line\nSecond line",
			'local-success-key'
		));

		$result = MailService::processBatch('worker-local', 5);
		$this->assertSame(1, $result['sent'], (string) ($result['error'] ?? ''));
		$this->assertArrayNotHasKey('error', $result);

		$transcript = $this->readTranscript($transcriptPath);
		$this->assertSame('MAIL FROM:<noreply@example.test>', $transcript['mail_from']);
		$this->assertSame('RCPT TO:<user@example.test>', $transcript['rcpt_to']);
		$this->assertContains('QUIT', $transcript['commands']);

		[$headers, $body] = explode("\r\n\r\n", $transcript['data'], 2);
		$this->assertStringContainsString('To: <user@example.test>', $headers);
		$this->assertStringContainsString('Subject: =?UTF-8?B?', $headers);
		$this->assertStringContainsString('Content-type: text/html; charset=UTF-8', $headers);
		$this->assertStringContainsString('Content-Transfer-Encoding: base64', $headers);
		$this->assertMatchesRegularExpression('/^Message-ID: <[0-9a-f]{32}@example\.test>\r?$/m', $headers);

		// Base64 keeps the 8-bit HTML body and its long style attributes legal on the wire.
		foreach (explode("\r\n", trim($body)) as $line) {
			$this->assertLessThanOrEqual(998, strlen($line));
		}
		$decoded = (string) base64_decode(str_replace("\r\n", '', trim($body)), true);
		$this->assertStringContainsString('First line<br />', $decoded);
		$this->assertStringContainsString('Delivered subject', $decoded);

		$row = Database::getInstance()->query(
			"SELECT `status`,`last_error` FROM `" . Database::table('mail_outbox') . "`"
		)->fetch(PDO::FETCH_ASSOC);
		$this->assertSame('sent', $row['status']);
		$this->assertNull($row['last_error']);
	}

	public function testRejectedRecipientIsRetriedAndRecordsTheServerReply(): void
	{
		$this->startStubServer('550 5.1.1 <user@example.test> Recipient rejected');
		$this->assertTrue(MailService::send(
			'user@example.test',
			'Rejected subject',
			'Rejected body',
			'local-rejected-key'
		));

		$result = MailService::processBatch('worker-local', 5);
		$this->assertSame(1, $result['retried']);
		$this->assertSame(0, $result['sent']);
		$this->assertStringContainsString('RCPT TO rejected: 550', (string) ($result['error'] ?? ''));

		$row = Database::getInstance()->query(
			"SELECT `status`,`attempts`,`last_error` FROM `" . Database::table('mail_outbox') . "`"
		)->fetch(PDO::FETCH_ASSOC);
		$this->assertSame('pending', $row['status']);
		$this->assertSame(1, (int) $row['attempts']);
		$this->assertStringContainsString('Recipient rejected', (string) $row['last_error']);
	}

	public function testUnreachableMtaFailsQuicklyInsteadOfBlockingTheWorker(): void
	{
		putenv('FILEHOST_LOCAL_MTA=127.0.0.1:' . $this->closedPort());
		$this->assertTrue(MailService::send(
			'user@example.test',
			'Unreachable subject',
			'Unreachable body',
			'local-unreachable-key'
		));

		$started = microtime(true);
		$result = MailService::processBatch('worker-local', 5);
		$elapsed = microtime(true) - $started;

		$this->assertSame(1, $result['retried']);
		$this->assertStringContainsString(
			'SMTP connect to 127.0.0.1:',
			(string) ($result['error'] ?? '')
		);
		$this->assertLessThan(15, $elapsed);

		$row = Database::getInstance()->query(
			"SELECT `status`,`next_attempt_at`,`last_error` FROM `"
			. Database::table('mail_outbox') . "`"
		)->fetch(PDO::FETCH_ASSOC);
		$this->assertSame('pending', $row['status']);
		$this->assertGreaterThan(time(), (int) $row['next_attempt_at']);
		$this->assertStringContainsString('SMTP connect', (string) $row['last_error']);
	}

	public function testExternalSmtpWithoutAHostIsRecordedAsAFailedAttempt(): void
	{
		Database::setSetting('email_method', 'smtp');
		Database::setSetting('smtp_host', '');
		$this->assertTrue(MailService::send(
			'user@example.test',
			'No host subject',
			'No host body',
			'smtp-no-host-key'
		));

		$result = MailService::processBatch('worker-smtp', 5);
		$this->assertSame(1, $result['retried']);
		$this->assertStringContainsString(
			'SMTP host is not configured.',
			(string) ($result['error'] ?? '')
		);
	}

	/** The one combination that needs a decision: `php` where mail() cannot work. */
	#[\PHPUnit\Framework\Attributes\DataProvider('guardedTransports')]
	public function testTheGuardDecidesWhatPhpMailMeansUnderNoNewPrivileges(
		string $method,
		bool $noNewPrivileges,
		string $guard,
		string $expectedTransport,
		bool $expectRefusal
	): void {
		[$transport, $refusal] = MailService::resolveTransport($method, $noNewPrivileges, $guard);

		$this->assertSame($expectedTransport, $transport);
		if ($expectRefusal) {
			$this->assertStringContainsString('NoNewPrivileges', $refusal);
		} else {
			$this->assertSame('', $refusal);
		}
	}

	/** @return array<string, array{0:string,1:bool,2:string,3:string,4:bool}> */
	public static function guardedTransports(): array
	{
		return [
			'unrestricted host keeps mail()' => ['php', false, 'fail', 'php', false],
			'restricted host refuses by default' => ['php', true, 'fail', '', true],
			'restricted host can divert to the local MTA' => ['php', true, 'local', 'local', false],
			'restricted host can be told to try anyway' => ['php', true, 'off', 'php', false],
			'an unknown guard value is treated as refuse' => ['php', true, 'nonsense', '', true],
			'the guard never touches the local transport' => ['local', true, 'fail', 'local', false],
			'the guard never touches an external relay' => ['smtp', true, 'fail', 'smtp', false],
		];
	}

	public function testTheGuardSettingFallsBackToRefusing(): void
	{
		Database::setSetting('email_php_mail_guard', 'sometimes');
		$this->assertSame('fail', MailService::phpMailGuard());
		Database::setSetting('email_php_mail_guard', 'LOCAL');
		$this->assertSame('local', MailService::phpMailGuard());
		Database::setSetting('email_php_mail_guard', 'off');
		$this->assertSame('off', MailService::phpMailGuard());
	}

	/**
	 * The panel cannot read the worker's sandbox, so the worker has to write it down.
	 *
	 * NoNewPrivileges belongs to one process tree: PHP-FPM answers "no" however the worker is
	 * running, and there is no socket to ask over. The snapshot in the data directory is the
	 * only honest source, which also means a worker that stopped must read as stale rather
	 * than as good news.
	 */
	public function testTheWorkerSnapshotIsReadableAndGoesStale(): void
	{
		$path = DATA_DIR . '/mail-worker.json';
		$this->scratch[] = $path;
		$this->assertNull(MailService::runtime(), 'no worker has reported yet');

		MailService::publishRuntime(['pending' => 3, 'sending' => 1, 'dead' => 0]);

		$runtime = MailService::runtime();
		$this->assertIsArray($runtime);
		$this->assertFalse($runtime['stale']);
		$this->assertSame(getmypid(), $runtime['pid']);
		$this->assertSame(PHP_VERSION, $runtime['php']);
		$this->assertSame('local', $runtime['method']);
		$this->assertSame(3, $runtime['queue']['pending']);
		$this->assertIsBool($runtime['no_new_privs']);

		$aged = json_decode((string) file_get_contents($path), true);
		$aged['at'] = time() - MailService::RUNTIME_FRESH_SECONDS - 1;
		file_put_contents($path, json_encode($aged));
		$this->assertTrue(MailService::runtime()['stale']);

		file_put_contents($path, 'not json at all');
		$this->assertNull(MailService::runtime(), 'a damaged snapshot is no snapshot');
	}

	/** Start the stub, point the local transport at it, and return its transcript path. */
	private function startStubServer(string $rcptReply = '250 2.1.5 Ok'): string
	{
		$tag = bin2hex(random_bytes(8));
		$portPath = DATA_DIR . '/smtp-port-' . $tag;
		$transcriptPath = DATA_DIR . '/smtp-transcript-' . $tag;
		$this->scratch[] = $portPath;
		$this->scratch[] = $transcriptPath;

		$process = proc_open(
			[
				PHP_BINARY,
				PROJECT_ROOT . '/tests/php/fixtures/smtp_stub_server.php',
				$portPath,
				$transcriptPath,
				$rcptReply,
			],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			PROJECT_ROOT,
			null,
			['bypass_shell' => true]
		);
		$this->assertIsResource($process);
		fclose($pipes[0]);
		$this->servers[] = [$process, $pipes];

		$port = '';
		$deadline = microtime(true) + 10;
		while (microtime(true) < $deadline) {
			$port = is_file($portPath) ? trim((string) file_get_contents($portPath)) : '';
			if ($port !== '') {
				break;
			}
			usleep(2000);
		}
		$this->assertMatchesRegularExpression(
			'/\A[1-9][0-9]{2,4}\z/D',
			$port,
			'the stub SMTP server did not publish a port'
		);
		putenv('FILEHOST_LOCAL_MTA=127.0.0.1:' . $port);
		return $transcriptPath;
	}

	/** @return array{commands:list<string>,mail_from:string,rcpt_to:string,data:string} */
	private function readTranscript(string $path): array
	{
		$deadline = microtime(true) + 10;
		while (microtime(true) < $deadline && !is_file($path)) {
			usleep(2000);
		}
		$this->assertFileExists($path);
		$decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		$this->assertIsArray($decoded);
		return $decoded;
	}

	/** A loopback port nothing listens on, so the connect is refused rather than timing out. */
	private function closedPort(): int
	{
		$errno = 0;
		$error = '';
		$probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
		$this->assertIsResource($probe);
		$name = (string) stream_socket_get_name($probe, false);
		fclose($probe);
		return (int) substr($name, (int) strrpos($name, ':') + 1);
	}
}
