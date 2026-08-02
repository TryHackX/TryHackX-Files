<?php
/**
 * WebhookRepository (Faza 5 · #2).
 *
 * User-registered endpoints (Faza 4.1) notified on their own file events. Firing just
 * enqueues a delivery row per matching webhook (cheap, non-blocking); the upload
 * server's worker POSTs them with an HMAC-SHA256 signature and retries. Extracted from
 * the Database god-object; the matching Database::* methods delegate here.
 */
final class WebhookRepository
{
	private const EVENTS = ['upload', 'download', 'delete'];

	public static function create(int $userId, string $url, string $secret, string $events): int|false
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$table = Database::table('webhooks');
		try {
			$stmt = $pdo->prepare("INSERT INTO `{$table}` (`user_id`, `url`, `secret`, `events`, `is_active`, `created_at`) VALUES (?, ?, ?, ?, 1, ?)");
			$stmt->execute([$userId, $url, $secret, $events, time()]);
			return (int) $pdo->lastInsertId();
		} catch (PDOException $e) {
			return false;
		}
	}

	public static function forUser(int $userId): array
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return [];
		}
		$table = Database::table('webhooks');
		try {
			$stmt = $pdo->prepare("SELECT `id`, `url`, `events`, `is_active`, `created_at`, `last_status`, `last_delivery_at` FROM `{$table}` WHERE `user_id` = ? ORDER BY `created_at` DESC");
			$stmt->execute([$userId]);
			return $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (PDOException $e) {
			return [];
		}
	}

	public static function countForUser(int $userId): int
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return 0;
		}
		$table = Database::table('webhooks');
		try {
			$stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `user_id` = ?");
			$stmt->execute([$userId]);
			return (int) $stmt->fetchColumn();
		} catch (PDOException $e) {
			return 0;
		}
	}

	public static function delete(int $id, int $userId): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}
		$table = Database::table('webhooks');
		$delTable = Database::table('webhook_deliveries');
		try {
			$stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE `id` = ? AND `user_id` = ?");
			$stmt->execute([$id, $userId]);
			if ($stmt->rowCount() === 0) {
				return false;
			}
			$pdo->prepare("DELETE FROM `{$delTable}` WHERE `webhook_id` = ?")->execute([$id]);
			return true;
		} catch (PDOException $e) {
			return false;
		}
	}

	/**
	 * Enqueue a delivery for every active webhook of $userId subscribed to $event.
	 * Cheap and non-blocking (one indexed lookup + inserts); the worker does the POSTs.
	 * Returns the number of deliveries queued.
	 */
	public static function enqueueEvent(int $userId, string $event, array $payload): int
	{
		if (!in_array($event, self::EVENTS, true)) {
			return 0;
		}
		$pdo = Database::getInstance();
		if (!$pdo) {
			return 0;
		}
		$table = Database::table('webhooks');
		$delTable = Database::table('webhook_deliveries');
		try {
			$stmt = $pdo->prepare("SELECT `id`, `events` FROM `{$table}` WHERE `user_id` = ? AND `is_active` = 1");
			$stmt->execute([$userId]);
			$hooks = $stmt->fetchAll(PDO::FETCH_ASSOC);
			if (!$hooks) {
				return 0;
			}

			$body = json_encode(array_merge(['event' => $event, 'timestamp' => time()], $payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			$ins = $pdo->prepare(
				"INSERT INTO `{$delTable}`
				 (`webhook_id`, `event_id`, `event`, `payload`, `attempts`, `status`,
				  `next_attempt_at`, `created_at`)
				 VALUES (?, ?, ?, ?, 0, 'pending', ?, ?)"
			);
			$now = time();
			$count = 0;
			foreach ($hooks as $h) {
				$subs = array_map('trim', explode(',', (string) $h['events']));
				if (!in_array($event, $subs, true)) {
					continue;
				}
				$ins->execute([
					(int) $h['id'],
					bin2hex(random_bytes(16)),
					$event,
					$body,
					$now,
					$now,
				]);
				$count++;
			}
			return $count;
		} catch (PDOException $e) {
			return 0;
		}
	}
}
