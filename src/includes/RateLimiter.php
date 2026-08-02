<?php
require_once __DIR__ . '/Database.php';

/**
 * Consistent request rate limiting (Faza 4.5).
 *
 * One fixed-window counter per bucket, kept in the `rate_limits` table so it works across
 * PHP workers (no shared memory needed). A bucket is "who + what", e.g. `ip:1.2.3.4|auth`
 * or `key:12|api`, letting us hold auth attempts to a tight budget while ordinary reads
 * get a loose one.
 *
 * Fixed windows can allow up to 2x the limit across a window boundary; that is a deliberate
 * trade for a single cheap upsert per request (a sliding log would cost a row per hit).
 */
class RateLimiter
{
	/** Limits as [requests, window seconds] per category. */
	private const LIMITS = [
		'auth' => [10, 300],   // login / register / 2FA / recovery — brute-force surface
		'write' => [60, 60],   // state-changing calls
		'read' => [300, 60],   // listings, polling, info
		'api' => [120, 60],    // REST API v1, counted per API key
	];

	/** Probability (1 in N) that a request also prunes expired rows — keeps the table small. */
	private const GC_CHANCE = 50;

	public static function limitsFor(string $category): array
	{
		return self::LIMITS[$category] ?? self::LIMITS['read'];
	}

	/**
	 * Count one hit against $bucket.
	 *
	 * @return array{allowed:bool,limit:int,remaining:int,reset:int} `reset` is a UNIX time.
	 *         Fails open (allowed) when the DB is unavailable — never lock users out of the
	 *         app because the limiter itself is broken.
	 */
	public static function hit(string $bucket, string $category): array
	{
		[$limit, $window] = self::limitsFor($category);
		$now = time();
		$open = ['allowed' => true, 'limit' => $limit, 'remaining' => $limit, 'reset' => $now + $window];

		$pdo = Database::getInstance();
		if (!$pdo) {
			return $open;
		}

		$key = substr($bucket . '|' . $category, 0, 190);
		$table = (defined('DB_PREFIX') ? DB_PREFIX : '') . 'rate_limits';
		$windowStart = intdiv($now, $window) * $window;

		try {
			if (random_int(1, self::GC_CHANCE) === 1) {
				$pdo->prepare("DELETE FROM `{$table}` WHERE `window_start` < ?")->execute([$now - 86400]);
			}

			// Aligned fixed windows make both the counter and reset timestamp derivable from
			// one atomic statement. LAST_INSERT_ID(expr) returns the resulting hit count on
			// both INSERT and UPDATE, avoiding a second hot-path SELECT.
			$stmt = $pdo->prepare(
				"INSERT INTO `{$table}` (`bucket`, `window_start`, `hits`)
				 VALUES (?, ?, LAST_INSERT_ID(1))
				 ON DUPLICATE KEY UPDATE
					`hits` = LAST_INSERT_ID(IF(`window_start` <> VALUES(`window_start`), 1, `hits` + 1)),
					`window_start` = VALUES(`window_start`)"
			);
			$stmt->execute([$key, $windowStart]);
			$hits = (int) $pdo->lastInsertId();
			if ($hits <= 0) {
				return $open;
			}

			return [
				'allowed' => $hits <= $limit,
				'limit' => $limit,
				'remaining' => max(0, $limit - $hits),
				'reset' => $windowStart + $window,
			];
		} catch (PDOException $e) {
			return $open;
		}
	}

	/**
	 * Apply a limit and, when exceeded, emit 429 + JSON and stop the request.
	 * Always sets the X-RateLimit-* headers so clients can self-throttle.
	 */
	public static function enforce(string $bucket, string $category): void
	{
		$r = self::hit($bucket, $category);

		header('X-RateLimit-Limit: ' . $r['limit']);
		header('X-RateLimit-Remaining: ' . $r['remaining']);
		header('X-RateLimit-Reset: ' . $r['reset']);

		if (!$r['allowed']) {
			$retry = max(1, $r['reset'] - time());
			header('Retry-After: ' . $retry);
			http_response_code(429);
			header('Content-Type: application/json');
			echo json_encode([
				'success' => false,
				'error' => 'Zbyt wiele żądań — spróbuj ponownie za ' . $retry . ' s.',
				'retry_after' => $retry,
			]);
			exit;
		}
	}
}
