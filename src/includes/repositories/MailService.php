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
	private const DEFAULT_LEASE_SECONDS = 120;

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
	 * workers omit it and use the configured SMTP/native transport.
	 *
	 * @return array{claimed:int,sent:int,retried:int,dead:int}
	 */
	public static function processBatch(
		string $workerId,
		int $limit = 25,
		int $leaseSeconds = self::DEFAULT_LEASE_SECONDS,
		?callable $delivery = null
	): array {
		$result = ['claimed' => 0, 'sent' => 0, 'retried' => 0, 'dead' => 0];
		$rows = self::claimBatch($workerId, $limit, $leaseSeconds);
		$result['claimed'] = count($rows);
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
		$pdo = Database::getInstance();
		if (!$pdo) {
			return [];
		}
		$table = Database::table('mail_outbox');
		$worker = hash('sha256', $workerId);
		$limit = max(1, min(100, $limit));
		$now = time();
		$leaseUntil = $now + max(30, min(900, $leaseSeconds));
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
			$claim->execute(array_merge([$worker, $leaseUntil, $now], array_map('intval', $ids)));
			$read = $pdo->prepare(
				"SELECT * FROM `{$table}` WHERE `id` IN ({$placeholders})
				 AND `status` = 'sending' AND `lease_owner` = ? ORDER BY `id`"
			);
			$read->execute(array_merge(array_map('intval', $ids), [$worker]));
			$rows = $read->fetchAll(PDO::FETCH_ASSOC);
			$pdo->commit();
			return $rows;
		} catch (Throwable $e) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}
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
		$pdo = Database::getInstance();
		if (!$pdo) {
			return '';
		}
		$table = Database::table('mail_outbox');
		$now = time();
		try {
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
			$cleanError = mb_substr(
				preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $error) ?? 'Mail transport failure.',
				0,
				1000
			);
			$update = $pdo->prepare(
				"UPDATE `{$table}` SET `status` = ?, `next_attempt_at` = ?,
				 `lease_owner` = NULL, `lease_until` = NULL, `last_error` = ?,
				 `updated_at` = ?
				 WHERE `id` = ? AND `status` = 'sending' AND `lease_owner` = ?"
			);
			$update->execute([
				$dead ? 'dead' : 'pending',
				$dead ? $now : $now + $delay,
				$cleanError,
				$now,
				$id,
				$worker,
			]);
			if ($update->rowCount() !== 1) {
				return '';
			}
			return $dead ? 'dead' : 'retried';
		} catch (Throwable $e) {
			error_log('Mail outbox finalization failed: ' . $e->getMessage());
			return '';
		}
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
		if (Database::getSetting('email_method', 'php') === 'smtp') {
			return self::sendSmtp($to, $from, $subject, $headers, $body);
		}
		return @mail($to, $subject, $body, $headers, '-f' . $from);
	}

	private static function sendSmtp(
		string $to,
		string $from,
		string $subject,
		string $headers,
		string $body
	): bool {
		$host = trim((string) Database::getSetting('smtp_host', ''));
		$port = max(1, min(65535, (int) Database::getSetting('smtp_port', 587)));
		$user = trim((string) Database::getSetting('smtp_user', ''));
		$password = (string) Database::getSecretSetting('smtp_pass', '');
		$encryption = strtolower(trim((string) Database::getSetting('smtp_encryption', 'tls')));
		if ($host === '' || !in_array($encryption, ['', 'tls', 'ssl'], true)) {
			return false;
		}
		$transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
		$socket = @stream_socket_client(
			$transport . $host . ':' . $port,
			$errno,
			$error,
			5,
			STREAM_CLIENT_CONNECT
		);
		if (!is_resource($socket)) {
			error_log('SMTP connect failed: ' . $errno);
			return false;
		}
		stream_set_timeout($socket, 10);
		try {
			if (!self::smtpExpect($socket, [220])) {
				return false;
			}
			$hello = (string) (parse_url(defined('APP_URL') ? APP_URL : '', PHP_URL_HOST) ?: 'localhost');
			if (!self::smtpCommand($socket, 'EHLO ' . $hello, [250])) {
				return false;
			}
			if ($encryption === 'tls') {
				if (!self::smtpCommand($socket, 'STARTTLS', [220])
					|| !@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)
					|| !self::smtpCommand($socket, 'EHLO ' . $hello, [250])) {
					return false;
				}
			}
			if ($user !== '') {
				if (!self::smtpCommand($socket, 'AUTH LOGIN', [334])
					|| !self::smtpCommand($socket, base64_encode($user), [334])
					|| !self::smtpCommand($socket, base64_encode($password), [235])) {
					return false;
				}
			}
			if (!self::smtpCommand($socket, 'MAIL FROM:<' . $from . '>', [250])
				|| !self::smtpCommand($socket, 'RCPT TO:<' . $to . '>', [250, 251])
				|| !self::smtpCommand($socket, 'DATA', [354])) {
				return false;
			}
			$hostPart = substr(strrchr($from, '@') ?: '@localhost', 1);
			$messageHeaders = 'Date: ' . date(DATE_RFC2822) . "\r\n"
				. 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $hostPart . ">\r\n"
				. "To: <{$to}>\r\n"
				. "Subject: {$subject}\r\n"
				. $headers;
			$payload = str_replace(["\r\n", "\r"], "\n", $messageHeaders . "\r\n\r\n" . $body);
			$payload = preg_replace('/^\./m', '..', $payload) ?? $payload;
			if (fwrite($socket, str_replace("\n", "\r\n", $payload) . "\r\n.\r\n") === false
				|| !self::smtpExpect($socket, [250])) {
				return false;
			}
			self::smtpCommand($socket, 'QUIT', [221]);
			return true;
		} catch (Throwable $e) {
			error_log('SMTP delivery failed: ' . $e->getMessage());
			return false;
		} finally {
			fclose($socket);
		}
	}

	private static function smtpCommand($socket, string $command, array $expected): bool
	{
		if (fwrite($socket, $command . "\r\n") === false) {
			return false;
		}
		return self::smtpExpect($socket, $expected);
	}

	private static function smtpExpect($socket, array $expected): bool
	{
		$code = 0;
		do {
			$line = fgets($socket, 4096);
			if ($line === false) {
				return false;
			}
			$code = (int) substr($line, 0, 3);
		} while (isset($line[3]) && $line[3] === '-');
		return in_array($code, $expected, true);
	}
}
