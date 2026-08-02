<?php

/**
 * Durable e-mail outbox: immutable rendering, idempotency, leases, retry and dead-letter.
 */
final class MailOutboxTest extends RepoTestCase
{
	protected function setUp(): void
	{
		$this->truncate('mail_outbox');
		Database::setSetting('email_from', 'noreply@example.test');
		Database::setSetting('email_from_name', 'Frozen Sender');
		Database::setSetting('email_method', 'php');
	}

	public function testEnqueueFreezesContentAndRejectsIdempotencyCollision(): void
	{
		$this->assertTrue(MailService::send(
			'user@example.test',
			'Subject',
			'Body <b>literal</b>',
			'stable-key'
		));
		$this->assertTrue(MailService::send(
			'user@example.test',
			'Subject',
			'Body <b>literal</b>',
			'stable-key'
		));
		$this->assertFalse(MailService::send(
			'user@example.test',
			'Different subject',
			'Body <b>literal</b>',
			'stable-key'
		));

		$pdo = Database::getInstance();
		$table = Database::table('mail_outbox');
		$this->assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn());
		$row = $pdo->query("SELECT * FROM `{$table}`")->fetch(PDO::FETCH_ASSOC);
		$this->assertSame('pending', $row['status']);
		$this->assertSame('noreply@example.test', $row['from_email']);
		$this->assertStringContainsString('Frozen Sender', base64_decode(
			preg_replace('/^.*From: =\\?UTF-8\\?B\\?([^?]+).*$/s', '$1', $row['headers'])
		) ?: '');
		$this->assertStringContainsString('Body &lt;b&gt;literal&lt;/b&gt;', $row['html_body']);

		Database::setSetting('email_from', 'changed@example.test');
		$storedFrom = $pdo->query("SELECT `from_email` FROM `{$table}`")->fetchColumn();
		$this->assertSame('noreply@example.test', $storedFrom);
	}

	public function testCustomHtmlBroadcastIsSanitizedBeforeItIsQueued(): void
	{
		$html = '<h2 onclick="alert(1)" style="color:red">Hello</h2>'
			. '<script>alert(2)</script><img src="https://tracker.example/pixel">'
			. '<a href="javascript:alert(3)">bad</a>'
			. '<a href="https://example.test/path" title="Safe">good</a>';
		$this->assertTrue(MailService::sendHtml(
			'user@example.test',
			'HTML subject',
			$html,
			'html-broadcast-key'
		));

		$body = (string) Database::getInstance()->query(
			"SELECT `html_body` FROM `" . Database::table('mail_outbox') . "`"
		)->fetchColumn();
		$this->assertStringContainsString('<h2>Hello</h2>', $body);
		$this->assertStringContainsString('href="https://example.test/path"', $body);
		$this->assertStringNotContainsStringIgnoringCase('<script', $body);
		$this->assertStringNotContainsStringIgnoringCase('<img', $body);
		$this->assertStringNotContainsStringIgnoringCase('javascript:', $body);
		$this->assertStringNotContainsStringIgnoringCase('onclick=', $body);
		$this->assertStringNotContainsStringIgnoringCase('color:red', $body);
	}

	public function testSuccessfulDeliveryIsClaimedAndFinalizedOnce(): void
	{
		$this->assertTrue(MailService::send(
			'user@example.test',
			'Success',
			'Queued body',
			'success-key'
		));
		$seen = [];
		$result = MailService::processBatch(
			'worker-a',
			10,
			120,
			static function (array $row) use (&$seen): bool {
				$seen[] = (int) $row['id'];
				return true;
			}
		);
		$this->assertSame(['claimed' => 1, 'sent' => 1, 'retried' => 0, 'dead' => 0], $result);
		$this->assertCount(1, $seen);
		$this->assertSame(
			['claimed' => 0, 'sent' => 0, 'retried' => 0, 'dead' => 0],
			MailService::processBatch('worker-b', 10, 120, static fn(): bool => true)
		);

		$row = Database::getInstance()->query(
			"SELECT `status`,`attempts`,`lease_owner`,`lease_until`,`sent_at`
			 FROM `" . Database::table('mail_outbox') . "`"
		)->fetch(PDO::FETCH_ASSOC);
		$this->assertSame('sent', $row['status']);
		$this->assertSame(1, (int) $row['attempts']);
		$this->assertNull($row['lease_owner']);
		$this->assertNull($row['lease_until']);
		$this->assertGreaterThan(0, (int) $row['sent_at']);
	}

	public function testFailureBacksOffThenBecomesDeadLetter(): void
	{
		$this->assertTrue(MailService::send(
			'user@example.test',
			'Retry',
			'Retry body',
			'retry-key'
		));
		$pdo = Database::getInstance();
		$table = Database::table('mail_outbox');
		$pdo->exec("UPDATE `{$table}` SET `max_attempts` = 2");

		$first = MailService::processBatch('worker-a', 1, 120, static fn(): bool => false);
		$this->assertSame(1, $first['retried']);
		$row = $pdo->query(
			"SELECT `status`,`attempts`,`next_attempt_at`,`last_error` FROM `{$table}`"
		)->fetch(PDO::FETCH_ASSOC);
		$this->assertSame('pending', $row['status']);
		$this->assertSame(1, (int) $row['attempts']);
		$this->assertGreaterThan(time(), (int) $row['next_attempt_at']);
		$this->assertNotSame('', (string) $row['last_error']);

		$pdo->exec("UPDATE `{$table}` SET `next_attempt_at` = 0");
		$second = MailService::processBatch('worker-b', 1, 120, static fn(): bool => false);
		$this->assertSame(1, $second['dead']);
		$row = $pdo->query("SELECT `status`,`attempts` FROM `{$table}`")->fetch(PDO::FETCH_ASSOC);
		$this->assertSame('dead', $row['status']);
		$this->assertSame(2, (int) $row['attempts']);
		$this->assertSame(1, MailService::stats()['dead']);
	}

	public function testOnlyExpiredLeaseCanBeRecovered(): void
	{
		$this->assertTrue(MailService::send(
			'user@example.test',
			'Lease',
			'Lease body',
			'lease-key'
		));
		$pdo = Database::getInstance();
		$table = Database::table('mail_outbox');
		$pdo->prepare(
			"UPDATE `{$table}` SET `status` = 'sending', `attempts` = 1,
			 `lease_owner` = 'abandoned', `lease_until` = ?"
		)->execute([time() + 300]);
		$this->assertSame(
			0,
			MailService::processBatch('worker-a', 1, 120, static fn(): bool => true)['claimed']
		);

		$pdo->exec("UPDATE `{$table}` SET `lease_until` = 0");
		$result = MailService::processBatch('worker-b', 1, 120, static fn(): bool => true);
		$this->assertSame(1, $result['sent']);
		$row = $pdo->query("SELECT `status`,`attempts` FROM `{$table}`")->fetch(PDO::FETCH_ASSOC);
		$this->assertSame('sent', $row['status']);
		$this->assertSame(2, (int) $row['attempts']);
	}

	public function testTwoWorkersCannotDeliverTheSameRow(): void
	{
		$this->assertTrue(MailService::send(
			'user@example.test',
			'Concurrent',
			'Concurrent body',
			'concurrent-key'
		));
		$tag = bin2hex(random_bytes(8));
		$go = DATA_DIR . '/mail-go-' . $tag;
		$ready = [
			DATA_DIR . '/mail-ready-a-' . $tag,
			DATA_DIR . '/mail-ready-b-' . $tag,
		];
		$output = [
			DATA_DIR . '/mail-result-a-' . $tag,
			DATA_DIR . '/mail-result-b-' . $tag,
		];
		$fixture = PROJECT_ROOT . '/tests/php/fixtures/mail_outbox_worker.php';
		$env = array_merge(is_array(getenv()) ? getenv() : [], [
			'TEST_DB_HOST' => DB_HOST,
			'TEST_DB_USER' => DB_USER,
			'TEST_DB_PASS' => DB_PASS,
			'TEST_DB_NAME' => DB_NAME,
			'PROJECT_ROOT' => PROJECT_ROOT,
		]);
		$processes = [];
		try {
			foreach ([0, 1] as $index) {
				$process = proc_open(
					[
						PHP_BINARY,
						$fixture,
						$ready[$index],
						$go,
						$output[$index],
						'worker-' . $index,
					],
					[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
					$pipes,
					PROJECT_ROOT,
					$env,
					['bypass_shell' => true]
				);
				$this->assertIsResource($process);
				fclose($pipes[0]);
				$processes[] = [$process, $pipes];
			}
			$deadline = microtime(true) + 10;
			while ((!is_file($ready[0]) || !is_file($ready[1])) && microtime(true) < $deadline) {
				usleep(1000);
			}
			$this->assertFileExists($ready[0]);
			$this->assertFileExists($ready[1]);
			$this->assertNotFalse(file_put_contents($go, 'go', LOCK_EX));

			foreach ($processes as [$process, $pipes]) {
				$stdout = stream_get_contents($pipes[1]);
				$stderr = stream_get_contents($pipes[2]);
				fclose($pipes[1]);
				fclose($pipes[2]);
				$this->assertSame(0, proc_close($process), "mail worker failed: {$stdout}\n{$stderr}");
			}
			$processes = [];
			$results = array_map(
				static fn(string $path): array => json_decode(
					(string) file_get_contents($path),
					true,
					512,
					JSON_THROW_ON_ERROR
				),
				$output
			);
			$this->assertSame(1, array_sum(array_column($results, 'claimed')));
			$this->assertSame(1, array_sum(array_column($results, 'sent')));
			$row = Database::getInstance()->query(
				"SELECT `status`,`attempts` FROM `" . Database::table('mail_outbox') . "`"
			)->fetch(PDO::FETCH_ASSOC);
			$this->assertSame('sent', $row['status']);
			$this->assertSame(1, (int) $row['attempts']);
		} finally {
			foreach ($processes as [$process, $pipes]) {
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
			foreach (array_merge([$go], $ready, $output) as $path) {
				if (is_file($path)) {
					unlink($path);
				}
			}
		}
	}
}
