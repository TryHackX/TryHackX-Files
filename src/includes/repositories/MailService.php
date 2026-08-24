<?php
/**
 * Durable transactional e-mail outbox.
 *
 * Request paths render and enqueue an immutable envelope. A CLI worker owns the slow SMTP/
 * mail() delivery, with a database lease, exponential retry and a terminal dead-letter state.
 */
final class MailService
{
	private const DEFAULT_MAX_ATTEMPTS = 8;
	public const DEFAULT_LEASE_SECONDS = 120;

	/** Where the `local` transport submits when nothing overrides it. */
	private const DEFAULT_LOCAL_MTA = '127.0.0.1:25';
	/** Loopback submission to this host's MTA: fail fast, the outbox owns the retry. */
	private const LOCAL_MTA_CONNECT_TIMEOUT = 5;
	private const LOCAL_MTA_IO_TIMEOUT = 15;
	private const LOCAL_MTA_DEADLINE = 30;
	/** A relay across the network may be slower, but never unbounded. */
	private const SMTP_CONNECT_TIMEOUT = 10;
	private const SMTP_IO_TIMEOUT = 30;
	private const SMTP_DEADLINE = 60;

	/**
	 * Render and enqueue an e-mail.
	 *
	 * A caller-provided idempotency key may be retried safely. Reusing it for different content
	 * is rejected instead of silently dropping the newer message.
	 */
	public static function send(
		string $to,
		string $subject,
		string $message,
		?string $idempotencyKey = null
	): bool {
		return self::enqueue(
			self::renderEnvelope($to, $subject, $message),
			$idempotencyKey
		);
	}

	/**
	 * Enqueue an administrator-authored HTML message.
	 *
	 * The fragment is sanitized before it is frozen in the outbox. Scripts, remote images,
	 * forms, event handlers, inline styles and unsafe links are deliberately not supported.
	 */
	public static function sendHtml(
		string $to,
		string $subject,
		string $html,
		?string $idempotencyKey = null
	): bool {
		return self::enqueue(
			self::renderEnvelope($to, $subject, self::sanitizeHtmlFragment($html), true),
			$idempotencyKey
		);
	}

	/** A conservative HTML subset suitable for an e-mail body authored in the admin panel. */
	public static function sanitizeHtmlFragment(string $html): string
	{
		$html = mb_substr($html, 0, 20000);
		$html = preg_replace(
			'~<(script|style|iframe|object|embed|form|input|button|link|meta|base|svg|math|video|audio)\b[^>]*>.*?</\1\s*>~is',
			'',
			$html
		) ?? '';
		$html = preg_replace(
			'~<(script|style|iframe|object|embed|form|input|button|link|meta|base|svg|math|video|audio)\b[^>]*/?\s*>~is',
			'',
			$html
		) ?? '';

		if (!class_exists('DOMDocument')) {
			return nl2br(htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
		}

		$document = new DOMDocument('1.0', 'UTF-8');
		$previous = libxml_use_internal_errors(true);
		$loaded = $document->loadHTML(
			'<?xml encoding="UTF-8"><div id="fh-mail-root">' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
		);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if (!$loaded) {
			return '';
		}

		$root = $document->getElementById('fh-mail-root');
		if (!$root) {
			return '';
		}
		$allowed = array_fill_keys([
			'div', 'span', 'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'a',
			'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'blockquote', 'code', 'pre',
			'hr', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
		], true);
		$elements = [];
		foreach ($root->getElementsByTagName('*') as $element) {
			$elements[] = $element;
		}
		foreach ($elements as $element) {
			$tag = strtolower($element->tagName);
			if (!isset($allowed[$tag])) {
				$parent = $element->parentNode;
				if ($parent) {
					while ($element->firstChild) {
						$parent->insertBefore($element->firstChild, $element);
					}
					$parent->removeChild($element);
				}
				continue;
			}

			$attributes = [];
			foreach ($element->attributes as $attribute) {
				$attributes[] = $attribute->name;
			}
			foreach ($attributes as $name) {
				$keep = ($tag === 'a' && in_array($name, ['href', 'title'], true))
					|| (in_array($tag, ['td', 'th'], true)
						&& in_array($name, ['colspan', 'rowspan'], true));
				if (!$keep) {
					$element->removeAttribute($name);
				}
			}
			if ($tag === 'a' && $element->hasAttribute('href')) {
				$href = html_entity_decode(trim($element->getAttribute('href')), ENT_QUOTES, 'UTF-8');
				if (preg_match('~^(https?://|mailto:)~i', $href) !== 1) {
					$element->removeAttribute('href');
				}
			}
		}

		$output = '';
		foreach ($root->childNodes as $child) {
			$output .= $document->saveHTML($child);
		}
		return $output;
	}

	/** @param array<string,string>|null $envelope */
	private static function enqueue(?array $envelope, ?string $idempotencyKey): bool
	{
		if ($envelope === null) {
			return false;
		}
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}

		$keyMaterial = $idempotencyKey ?? bin2hex(random_bytes(32));
		$key = hash('sha256', $keyMaterial);
		$contentHash = hash('sha256', implode("\0", [
			$envelope['to_email'],
			$envelope['from_email'],
			$envelope['subject'],
			$envelope['subject_header'],
			$envelope['headers'],
			$envelope['html_body'],
		]));
		$now = time();
		$table = Database::table('mail_outbox');
		try {
			$stmt = $pdo->prepare(
				"INSERT INTO `{$table}`
				 (`idempotency_key`,`content_hash`,`to_email`,`from_email`,`subject`,
				  `subject_header`,`headers`,`html_body`,`status`,`attempts`,`max_attempts`,
				  `next_attempt_at`,`created_at`,`updated_at`)
				 VALUES (?,?,?,?,?,?,?,?,'pending',0,?,?,?,?)"
			);
			$stmt->execute([
				$key,
				$contentHash,
				$envelope['to_email'],
				$envelope['from_email'],
				$envelope['subject'],
				$envelope['subject_header'],
				$envelope['headers'],
				$envelope['html_body'],
				self::DEFAULT_MAX_ATTEMPTS,
				$now,
				$now,
				$now,
			]);
			return true;
		} catch (PDOException $e) {
			if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
				error_log('Mail outbox enqueue failed: ' . $e->getMessage());
				return false;
			}
			try {
				$stmt = $pdo->prepare(
					"SELECT `content_hash` FROM `{$table}` WHERE `idempotency_key` = ?"
				);
				$stmt->execute([$key]);
				$existingHash = $stmt->fetchColumn();
				return is_string($existingHash) && hash_equals($existingHash, $contentHash);
			} catch (Throwable $lookupError) {
				error_log('Mail outbox idempotency lookup failed: ' . $lookupError->getMessage());
				return false;
			}
		}
	}

	/**
	 * Claim and deliver one bounded batch.
	 *
	 * The optional delivery callable is dependency injection for repository tests. Production
	 * workers omit it and use the configured transport. The optional progress callable runs
	 * after every message, so a supervised worker can pet its watchdog inside a long batch
	 * instead of looking wedged while it is making progress.
	 *
	 * The first failure of the batch is reported back in `error` so the caller can log one
	 * line about it. Individual failures are never logged from here: a transport outage
	 * affects every message in the queue at once, and one line per attempt is what filled
	 * a journal with gigabytes the last time this path broke.
	 *
	 * @return array{claimed:int,sent:int,retried:int,dead:int,error?:string}
	 */
	public static function processBatch(
		string $workerId,
		int $limit = 25,
		int $leaseSeconds = self::DEFAULT_LEASE_SECONDS,
		?callable $delivery = null,
		?callable $progress = null
	): array {
		$result = ['claimed' => 0, 'sent' => 0, 'retried' => 0, 'dead' => 0];
		$rows = self::claimBatch($workerId, $limit, $leaseSeconds);
		$result['claimed'] = count($rows);
		$firstError = '';
		foreach ($rows as $row) {
			$error = '';
			try {
				$delivered = $delivery !== null
					? (bool) $delivery($row)
					: self::deliverFrozen($row);
				if (!$delivered) {
					$error = 'Mail transport returned failure.';
				}
			} catch (Throwable $e) {
				$delivered = false;
				$error = get_class($e) . ': ' . $e->getMessage();
			}

			$state = self::finishAttempt(
				(int) $row['id'],
				(string) $row['lease_owner'],
				$delivered,
				$error
			);
			if (isset($result[$state])) {
				$result[$state]++;
			}
			if ($error !== '' && $firstError === '') {
				$firstError = '#' . (int) $row['id'] . ' -> ' . (string) $row['to_email'] . ': ' . $error;
			}
			if ($progress !== null) {
				$progress();
			}
		}
		if ($firstError !== '') {
			$result['error'] = self::sanitizeError($firstError);
		}
		return $result;
	}

	/**
	 * Queue telemetry for CLI status and Prometheus.
	 *
	 * @return array{pending:int,sending:int,sent:int,dead:int,oldest_pending_age:int}
	 */
	public static function stats(): array
	{
		$empty = ['pending' => 0, 'sending' => 0, 'sent' => 0, 'dead' => 0, 'oldest_pending_age' => 0];
		$pdo = Database::getInstance();
		if (!$pdo) {
			return $empty;
		}
		try {
			$table = Database::table('mail_outbox');
			$rows = $pdo->query(
				"SELECT `status`, COUNT(*) AS `count`, MIN(`created_at`) AS `oldest`
				 FROM `{$table}` GROUP BY `status`"
			)->fetchAll(PDO::FETCH_ASSOC);
			$stats = $empty;
			foreach ($rows as $row) {
				$status = (string) $row['status'];
				if (array_key_exists($status, $stats)) {
					$stats[$status] = (int) $row['count'];
				}
				if ($status === 'pending' && $row['oldest'] !== null) {
					$stats['oldest_pending_age'] = max(0, time() - (int) $row['oldest']);
				}
			}
			return $stats;
		} catch (Throwable $e) {
			return $empty;
		}
	}

	/** Keep operational history bounded without deleting live or dead-letter work. */
	public static function pruneSent(int $retentionDays = 30): int
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return 0;
		}
		try {
			$stmt = $pdo->prepare(
				"DELETE FROM `" . Database::table('mail_outbox') . "`
				 WHERE `status` = 'sent' AND `sent_at` < ?"
			);
			$stmt->execute([time() - max(1, min(3650, $retentionDays)) * 86400]);
			return $stmt->rowCount();
		} catch (Throwable $e) {
			return 0;
		}
	}

	/** @return list<array<string,mixed>> */
	private static function claimBatch(string $workerId, int $limit, int $leaseSeconds): array
	{
		$table = Database::table('mail_outbox');
		$worker = hash('sha256', $workerId);
		$limit = max(1, min(100, $limit));
		$now = time();
		$leaseUntil = $now + max(30, min(900, $leaseSeconds));
		try {
			return self::withLiveConnection(
				/** @return list<array<string,mixed>> */
				static function (PDO $pdo) use ($table, $worker, $limit, $now, $leaseUntil): array {
					try {
						$pdo->beginTransaction();
						$ids = $pdo->query(
							"SELECT `id` FROM `{$table}`
							 WHERE (`status` = 'pending' AND `next_attempt_at` <= {$now})
							    OR (`status` = 'sending' AND `lease_until` <= {$now})
							 ORDER BY `next_attempt_at`, `id`
							 LIMIT {$limit} FOR UPDATE SKIP LOCKED"
						)->fetchAll(PDO::FETCH_COLUMN);
						if ($ids === []) {
							$pdo->commit();
							return [];
						}
						$placeholders = implode(',', array_fill(0, count($ids), '?'));
						$claim = $pdo->prepare(
							"UPDATE `{$table}` SET `status` = 'sending', `attempts` = `attempts` + 1,
							 `lease_owner` = ?, `lease_until` = ?, `updated_at` = ?, `last_error` = NULL
							 WHERE `id` IN ({$placeholders})"
						);
						$claim->execute(
							array_merge([$worker, $leaseUntil, $now], array_map('intval', $ids))
						);
						$read = $pdo->prepare(
							"SELECT * FROM `{$table}` WHERE `id` IN ({$placeholders})
							 AND `status` = 'sending' AND `lease_owner` = ? ORDER BY `id`"
						);
						$read->execute(array_merge(array_map('intval', $ids), [$worker]));
						$rows = $read->fetchAll(PDO::FETCH_ASSOC);
						$pdo->commit();
						return $rows;
					} catch (Throwable $e) {
						try {
							if ($pdo->inTransaction()) {
								$pdo->rollBack();
							}
						} catch (Throwable) {
							// A dropped connection has already rolled the transaction back.
						}
						throw $e;
					}
				}
			);
		} catch (Throwable $e) {
			error_log('Mail outbox claim failed: ' . $e->getMessage());
			return [];
		}
	}

	/** Return the result key used by processBatch(). */
	private static function finishAttempt(
		int $id,
		string $worker,
		bool $delivered,
		string $error
	): string {
		$table = Database::table('mail_outbox');
		$now = time();
		try {
			return self::withLiveConnection(
				static function (PDO $pdo) use ($table, $now, $id, $worker, $delivered, $error): string {
					if ($delivered) {
						$stmt = $pdo->prepare(
							"UPDATE `{$table}` SET `status` = 'sent', `sent_at` = ?,
							 `lease_owner` = NULL, `lease_until` = NULL, `last_error` = NULL,
							 `updated_at` = ?
							 WHERE `id` = ? AND `status` = 'sending' AND `lease_owner` = ?"
						);
						$stmt->execute([$now, $now, $id, $worker]);
						return $stmt->rowCount() === 1 ? 'sent' : '';
					}

					$stmt = $pdo->prepare(
						"SELECT `attempts`, `max_attempts` FROM `{$table}`
						 WHERE `id` = ? AND `status` = 'sending' AND `lease_owner` = ?"
					);
					$stmt->execute([$id, $worker]);
					$row = $stmt->fetch(PDO::FETCH_ASSOC);
					if (!$row) {
						return '';
					}
					$attempts = (int) $row['attempts'];
					$dead = $attempts >= max(1, (int) $row['max_attempts']);
					$delay = min(3600, 30 * (2 ** min(7, max(0, $attempts - 1))));
					$update = $pdo->prepare(
						"UPDATE `{$table}` SET `status` = ?, `next_attempt_at` = ?,
						 `lease_owner` = NULL, `lease_until` = NULL, `last_error` = ?,
						 `updated_at` = ?
						 WHERE `id` = ? AND `status` = 'sending' AND `lease_owner` = ?"
					);
					$update->execute([
						$dead ? 'dead' : 'pending',
						$dead ? $now : $now + $delay,
						self::sanitizeError($error),
						$now,
						$id,
						$worker,
					]);
					if ($update->rowCount() !== 1) {
						return '';
					}
					return $dead ? 'dead' : 'retried';
				}
			);
		} catch (Throwable $e) {
			error_log('Mail outbox finalization failed: ' . $e->getMessage());
			return '';
		}
	}

	/**
	 * Run one database operation, retrying once on a fresh connection if the server dropped it.
	 *
	 * A looping worker keeps its connection open between batches, so MySQL's `wait_timeout`
	 * eventually closes an idle one. Losing the *result* of an attempt is worse than losing the
	 * attempt: the row stays `sending` until its lease expires and is then delivered a second
	 * time, so the recipient gets the message twice.
	 *
	 * @template T
	 * @param callable(PDO): T $operation
	 * @return T
	 */
	private static function withLiveConnection(callable $operation)
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			throw new RuntimeException('No database connection for the mail outbox.');
		}
		try {
			return $operation($pdo);
		} catch (PDOException $e) {
			if (!self::isConnectionLost($e)) {
				throw $e;
			}
			Database::resetInstance();
			$reconnected = Database::getInstance();
			if (!$reconnected) {
				throw $e;
			}
			return $operation($reconnected);
		}
	}

	/** MySQL/MariaDB's "this connection is no longer usable" family. */
	private static function isConnectionLost(PDOException $e): bool
	{
		if (in_array((int) ($e->errorInfo[1] ?? 0), [2006, 2013, 4031], true)) {
			return true;
		}
		$message = $e->getMessage();
		return str_contains($message, 'server has gone away')
			|| str_contains($message, 'Lost connection');
	}

	/** One log-safe and column-safe line: no control characters, bounded length. */
	private static function sanitizeError(string $error): string
	{
		return mb_substr(
			preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $error) ?? 'Mail transport failure.',
			0,
			1000
		);
	}

	/** @return array<string,string>|null */
	private static function renderEnvelope(
		string $to,
		string $subject,
		string $message,
		bool $messageIsHtml = false
	): ?array
	{
		$from = trim((string) Database::getSetting(
			'email_from',
			'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
		));
		$fromName = Database::getSetting(
			'email_from_name',
			defined('APP_NAME') ? APP_NAME : (defined('PRODUCT_NAME') ? PRODUCT_NAME : 'TryHackX Files')
		);
		if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
			return null;
		}
		$subject = preg_replace('/[\r\n]+/', ' ', trim($subject)) ?? '';
		$subject = mb_substr($subject, 0, 255);
		if ($subject === '') {
			return null;
		}
		$fromName = preg_replace('/[\r\n]+/', ' ', trim((string) $fromName)) ?? '';
		$subjectHeader = '=?UTF-8?B?' . base64_encode($subject) . '?=';
		$fromNameHeader = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
		$headers = "MIME-Version: 1.0\r\n";
		$headers .= "Content-type: text/html; charset=UTF-8\r\n";
		$headers .= "From: {$fromNameHeader} <{$from}>\r\n";
		$headers .= "Reply-To: {$from}\r\n";
		$headers .= 'X-Mailer: TryHackX-Files/' . (defined('APP_VERSION') ? APP_VERSION : '1.0');

		$appName = htmlspecialchars(
			defined('APP_NAME') ? APP_NAME : (defined('PRODUCT_NAME') ? PRODUCT_NAME : 'TryHackX Files'),
			ENT_QUOTES | ENT_SUBSTITUTE,
			'UTF-8'
		);
		$body = $messageIsHtml
			? $message
			: nl2br(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
		$htmlMessage = "
		<html>
		<body style='font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; background-color: #f6f9fc; padding: 40px 0; margin: 0; color: #333;'>
			<div style='max-width: 600px; margin: 0 auto; background: #ffffff; padding: 40px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); text-align: left;'>
				<h2 style='color: #1a1a1a; margin-top: 0; margin-bottom: 24px; font-size: 24px; font-weight: 600; text-align: center;'>" . htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</h2>
				<div style='color: #4a5568; line-height: 1.6; font-size: 16px;'>
					{$body}
				</div>
				<div style='margin-top: 40px; padding-top: 20px; border-top: 1px solid #e2e8f0; font-size: 14px; color: #718096; text-align: center;'>
					Wiadomość wysłana przez <strong>{$appName}</strong><br>
					<small>Jeśli nie spodziewałeś się tej wiadomości, zignoruj ją.</small>
				</div>
			</div>
		</body>
		</html>
		";

		return [
			'to_email' => strtolower(trim($to)),
			'from_email' => strtolower($from),
			'subject' => $subject,
			'subject_header' => $subjectHeader,
			'headers' => $headers,
			'html_body' => $htmlMessage,
		];
	}

	/** Deliver an already-rendered row without re-entering the outbox. */
	private static function deliverFrozen(array $row): bool
	{
		$to = (string) ($row['to_email'] ?? '');
		$from = (string) ($row['from_email'] ?? '');
		$subject = (string) ($row['subject_header'] ?? '');
		$headers = (string) ($row['headers'] ?? '');
		$body = (string) ($row['html_body'] ?? '');
		if (!filter_var($to, FILTER_VALIDATE_EMAIL)
			|| !filter_var($from, FILTER_VALIDATE_EMAIL)
			|| preg_match('/[\r\n]/', $to . $from) === 1) {
			return false;
		}
		$method = self::method();
		if ($method === 'php') {
			return @mail($to, $subject, $body, $headers, '-f' . $from);
		}
		self::submit(self::transport($method), $to, $from, $subject, $headers, $body);
		return true;
	}

	/**
	 * The configured transport, normalized to one of `php`, `local` or `smtp`.
	 *
	 * `local` submits to the MTA already running on this host over loopback SMTP. It is the
	 * only submission path a hardened unit can use: `NoNewPrivileges` strips the setgid bit
	 * from Postfix's `postdrop`, and `mail()` then blocks *forever* inside a helper that
	 * retries an unwritable maildrop every ten seconds — no error, no timeout, no delivery.
	 */
	public static function method(): string
	{
		$method = strtolower(trim((string) Database::getSetting('email_method', 'php')));
		return in_array($method, ['php', 'local', 'smtp'], true) ? $method : 'php';
	}

	/**
	 * Connection parameters for one transport.
	 *
	 * @return array{host:string,port:int,encryption:string,user:string,pass:string,connect:int,io:int,deadline:int}
	 */
	private static function transport(string $method): array
	{
		if ($method === 'local') {
			[$host, $port] = self::localMtaEndpoint();
			return [
				'host' => $host,
				'port' => $port,
				'encryption' => '',
				'user' => '',
				'pass' => '',
				'connect' => self::LOCAL_MTA_CONNECT_TIMEOUT,
				'io' => self::LOCAL_MTA_IO_TIMEOUT,
				'deadline' => self::LOCAL_MTA_DEADLINE,
			];
		}
		return [
			'host' => trim((string) Database::getSetting('smtp_host', '')),
			'port' => max(1, min(65535, (int) Database::getSetting('smtp_port', 587))),
			'encryption' => strtolower(trim((string) Database::getSetting('smtp_encryption', 'tls'))),
			'user' => trim((string) Database::getSetting('smtp_user', '')),
			'pass' => (string) Database::getSecretSetting('smtp_pass', ''),
			'connect' => self::SMTP_CONNECT_TIMEOUT,
			'io' => self::SMTP_IO_TIMEOUT,
			'deadline' => self::SMTP_DEADLINE,
		];
	}

	/**
	 * Where the local MTA listens.
	 *
	 * `FILEHOST_LOCAL_MTA=host:port` covers a container that reaches its relay under another
	 * name; the default is this host's own SMTP port.
	 *
	 * @return array{0:string,1:int}
	 */
	private static function localMtaEndpoint(): array
	{
		$configured = trim((string) (getenv('FILEHOST_LOCAL_MTA') ?: ''));
		if ($configured === '') {
			$configured = self::DEFAULT_LOCAL_MTA;
		}
		$host = $configured;
		$port = 25;
		if (preg_match('/\A(?<host>.+):(?<port>\d{1,5})\z/D', $configured, $match) === 1) {
			$host = $match['host'];
			$port = (int) $match['port'];
		}
		$host = trim($host, '[]');
		if ($host === '' || $port < 1 || $port > 65535) {
			return ['127.0.0.1', 25];
		}
		return [$host, $port];
	}

	/**
	 * Hand one frozen message to an SMTP server.
	 *
	 * Every step is bounded — the connect, each read, each write and the conversation as a
	 * whole — so a wedged server costs one message one attempt instead of stalling the worker.
	 * Failures throw with the server's own reply, which the outbox stores in `last_error`.
	 *
	 * @param array{host:string,port:int,encryption:string,user:string,pass:string,connect:int,io:int,deadline:int} $transport
	 */
	private static function submit(
		array $transport,
		string $to,
		string $from,
		string $subject,
		string $headers,
		string $body
	): void {
		if ($transport['host'] === '') {
			throw new RuntimeException('SMTP host is not configured.');
		}
		$encryption = $transport['encryption'];
		if (!in_array($encryption, ['', 'tls', 'ssl'], true)) {
			throw new RuntimeException('Unsupported SMTP encryption: ' . $encryption . '.');
		}
		$authority = (str_contains($transport['host'], ':')
			? '[' . $transport['host'] . ']'
			: $transport['host']) . ':' . $transport['port'];
		$deadline = microtime(true) + $transport['deadline'];
		$errno = 0;
		$error = '';
		$socket = @stream_socket_client(
			($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $authority,
			$errno,
			$error,
			$transport['connect'],
			STREAM_CLIENT_CONNECT
		);
		if (!is_resource($socket)) {
			throw new RuntimeException(sprintf(
				'SMTP connect to %s failed: %s (%d).',
				$authority,
				$error !== '' ? $error : 'unknown error',
				$errno
			));
		}
		stream_set_timeout($socket, $transport['io']);
		try {
			self::expect($socket, [220], 'greeting', $deadline);
			$hello = self::heloName($from);
			self::command($socket, 'EHLO ' . $hello, [250], 'EHLO', $deadline);
			if ($encryption === 'tls') {
				self::command($socket, 'STARTTLS', [220], 'STARTTLS', $deadline);
				if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
					throw new RuntimeException('SMTP STARTTLS negotiation with ' . $authority . ' failed.');
				}
				self::command($socket, 'EHLO ' . $hello, [250], 'EHLO after STARTTLS', $deadline);
			}
			if ($transport['user'] !== '') {
				self::command($socket, 'AUTH LOGIN', [334], 'AUTH LOGIN', $deadline);
				self::command($socket, base64_encode($transport['user']), [334], 'AUTH user', $deadline);
				self::command($socket, base64_encode($transport['pass']), [235], 'AUTH password', $deadline);
			}
			self::command($socket, 'MAIL FROM:<' . $from . '>', [250], 'MAIL FROM', $deadline);
			self::command($socket, 'RCPT TO:<' . $to . '>', [250, 251], 'RCPT TO', $deadline);
			self::command($socket, 'DATA', [354], 'DATA', $deadline);
			self::write($socket, self::dataPayload($to, $from, $subject, $headers, $body), $deadline);
			self::expect($socket, [250], 'end of DATA', $deadline);
			try {
				self::command($socket, 'QUIT', [221], 'QUIT', $deadline);
			} catch (RuntimeException) {
				// The server already accepted the message; a rude goodbye is not a failure.
			}
		} finally {
			fclose($socket);
		}
	}

	/** A syntactically valid EHLO name, which strict servers insist on. */
	private static function heloName(string $from): string
	{
		$host = defined('APP_URL')
			? (string) (parse_url((string) APP_URL, PHP_URL_HOST) ?: '')
			: '';
		if ($host === '') {
			$host = (string) substr(strrchr($from, '@') ?: '', 1);
		}
		return preg_match('/\A[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?\z/D', $host) === 1
			? $host
			: 'localhost';
	}

	/**
	 * The DATA block: RFC 5322 headers plus the frozen body.
	 *
	 * The stored body is 8-bit UTF-8 HTML whose lines can exceed the 998 octets SMTP allows —
	 * a local `sendmail` accepted that, a server on a socket does not have to. Encoding it
	 * here puts the very same bytes on the wire legally.
	 */
	private static function dataPayload(
		string $to,
		string $from,
		string $subject,
		string $headers,
		string $body
	): string {
		$domain = substr(strrchr($from, '@') ?: '@localhost', 1);
		$message = 'Date: ' . date(DATE_RFC2822) . "\r\n"
			. 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . ">\r\n"
			. "To: <{$to}>\r\n"
			. "Subject: {$subject}\r\n"
			. $headers;
		if (preg_match('/^Content-Transfer-Encoding:/mi', $message) !== 1) {
			$message .= "\r\nContent-Transfer-Encoding: base64";
			$body = chunk_split(base64_encode($body), 76, "\r\n");
		}
		$payload = str_replace(["\r\n", "\r"], "\n", $message . "\r\n\r\n" . $body);
		$payload = preg_replace('/^\./m', '..', $payload) ?? $payload;
		$payload = str_replace("\n", "\r\n", $payload);
		if (!str_ends_with($payload, "\r\n")) {
			$payload .= "\r\n";
		}
		return $payload . ".\r\n";
	}

	/**
	 * @param resource $socket
	 * @param list<int> $expected
	 */
	private static function command(
		$socket,
		string $command,
		array $expected,
		string $label,
		float $deadline
	): void {
		self::write($socket, $command . "\r\n", $deadline);
		self::expect($socket, $expected, $label, $deadline);
	}

	/**
	 * Write every byte, or fail saying how far it got. A socket write is allowed to be short.
	 *
	 * @param resource $socket
	 */
	private static function write($socket, string $payload, float $deadline): void
	{
		$offset = 0;
		$length = strlen($payload);
		while ($offset < $length) {
			if (microtime(true) > $deadline) {
				throw new RuntimeException('SMTP write exceeded the delivery deadline.');
			}
			$written = @fwrite($socket, substr($payload, $offset, 8192));
			if ($written === false || $written === 0) {
				throw new RuntimeException(
					'SMTP write failed after ' . $offset . ' of ' . $length . ' bytes.'
				);
			}
			$offset += $written;
		}
	}

	/**
	 * Read one complete (possibly multi-line) reply and require its status code.
	 *
	 * @param resource $socket
	 * @param list<int> $expected
	 */
	private static function expect($socket, array $expected, string $label, float $deadline): void
	{
		$code = 0;
		$text = [];
		do {
			if (microtime(true) > $deadline) {
				throw new RuntimeException('SMTP ' . $label . ' exceeded the delivery deadline.');
			}
			$line = fgets($socket, 4096);
			if ($line === false) {
				$meta = stream_get_meta_data($socket);
				throw new RuntimeException(sprintf(
					'SMTP %s got no reply (%s).',
					$label,
					empty($meta['timed_out']) ? 'connection closed' : 'timed out'
				));
			}
			$code = (int) substr($line, 0, 3);
			$text[] = trim(substr($line, 4));
			$continued = isset($line[3]) && $line[3] === '-';
		} while ($continued);
		if (!in_array($code, $expected, true)) {
			throw new RuntimeException(sprintf(
				'SMTP %s rejected: %d %s',
				$label,
				$code,
				self::sanitizeError(trim(implode(' ', array_filter($text))))
			));
		}
	}
}
