<?php
/**
 * TryHackX Files durable e-mail outbox worker.
 *
 * One batch (cron / Task Scheduler):
 *   php scripts/mail-worker.php --limit=25
 *
 * Long-running service:
 *   php scripts/mail-worker.php --loop --limit=25 --sleep=5
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit("This script is CLI-only.\n");
}

// The CLI SAPI always fills argv in, but `register_argc_argv` is Off by default in Debian's
// php.ini, and static analysis believes the ini rather than the SAPI: `composer analyse` then
// fails with "Variable $argv might not be defined" on exactly the platform this ships on.
// Taking it from $_SERVER states the same value in a form that is true on every host.
$argv = $_SERVER['argv'] ?? [];

$projectRoot = dirname(__DIR__);
$configLocal = $projectRoot . '/config/config.local.php';
if (!is_file($configLocal)) {
	fwrite(STDERR, "config.local.php not found — is the app installed?\n");
	exit(1);
}
require_once $configLocal;

if (!defined('PROJECT_ROOT')) {
	define('PROJECT_ROOT', $projectRoot);
}
if (!defined('APP_VERSION')) {
	define('APP_VERSION', '2.77.0');
}
if (!defined('APP_URL')) {
	$canonical = defined('APP_CANONICAL_URL')
		? trim((string) APP_CANONICAL_URL)
		: trim((string) (getenv('FILEHOST_CANONICAL_URL') ?: ''));
	if ($canonical === '') {
		fwrite(STDERR, "APP_CANONICAL_URL is required.\n");
		exit(1);
	}
	define('APP_URL', rtrim($canonical, '/'));
}

require_once $projectRoot . '/src/includes/Database.php';
$pdo = Database::getInstance();
if (!$pdo) {
	fwrite(STDERR, "Cannot connect to the database.\n");
	exit(1);
}
Database::invalidateSettingsCache();
if ((int) Database::getSetting('schema_version', 0) !== Database::CURRENT_SCHEMA_VERSION
	|| (string) Database::getSetting('schema_ready', '0') !== '1') {
	fwrite(STDERR, "Database schema is not ready for this worker.\n");
	exit(2);
}

$loop = in_array('--loop', $argv, true);
$minimalLogs = in_array(
	strtolower(trim((string) getenv('FILEHOST_MINIMAL_LOGS'))),
	['1', 'true', 'yes', 'on'],
	true
);
$quiet = $minimalLogs || in_array('--quiet', $argv, true);
$limit = 25;
$sleepSeconds = 5;
foreach ($argv as $arg) {
	if (preg_match('/^--limit=(\d+)$/', $arg, $match)) {
		$limit = max(1, min(100, (int) $match[1]));
	} elseif (preg_match('/^--sleep=(\d+)$/', $arg, $match)) {
		$sleepSeconds = max(1, min(60, (int) $match[1]));
	}
}
$workerId = (gethostname() ?: 'host') . ':' . getmypid() . ':' . bin2hex(random_bytes(8));
$lastPruneDay = '';
$watchdog = MailWorkerWatchdog::fromEnvironment();
$heartbeat = static function () use ($watchdog): void {
	$watchdog->ping();
};

$lastTransportWarningAt = 0;
$lastErrorSignature = '';
$lastErrorAt = 0;
$lastSettingsRefresh = time();

do {
	// Settings live for one request on the web side; this process lives for weeks. Re-reading
	// them keeps a transport changed in the panel from needing a service restart.
	if ($loop && time() - $lastSettingsRefresh >= 15) {
		Database::forgetLocalSettingsCache();
		$lastSettingsRefresh = time();
	}
	// One warning per hour for the one combination nothing can rescue: the administrator has
	// turned the safeguard off, so mail() is about to be called on a host where a setgid
	// postdrop cannot run. Every other combination either works or fails with its own message.
	if (MailService::method() === 'php'
		&& MailService::noNewPrivileges()
		&& MailService::phpMailGuard() === 'off'
		&& time() - $lastTransportWarningAt >= 3600) {
		$lastTransportWarningAt = time();
		fwrite(
			STDERR,
			'[' . date('Y-m-d H:i:s') . '] mail-worker: calling PHP mail() under NoNewPrivileges'
			. ' with the safeguard disabled. If this host delivers through a setgid postdrop,'
			. ' the call will not return and this worker will be restarted by its watchdog'
			. ' until the transport or the safeguard changes.' . PHP_EOL
		);
	}

	$watchdog->ping();
	$result = MailService::processBatch(
		$workerId,
		$limit,
		MailService::DEFAULT_LEASE_SECONDS,
		null,
		$heartbeat
	);
	$today = date('Y-m-d');
	if ($lastPruneDay !== $today) {
		$result['pruned'] = MailService::pruneSent(30);
		$lastPruneDay = $today;
	}
	$result['queue'] = MailService::stats();
	$watchdog->ping();
	$watchdog->status(sprintf(
		'pending=%d sending=%d dead=%d',
		$result['queue']['pending'],
		$result['queue']['sending'],
		$result['queue']['dead']
	));

	// systemd sees the status line; the panel cannot. NoNewPrivileges is a property of one
	// process tree, so PHP-FPM answers 0 while this process answers 1, and there is no socket
	// to ask over. Leaving a snapshot in the data directory is how Settings -> E-mail can show
	// what is actually in force instead of guessing.
	MailService::publishRuntime($result['queue']);

	// Failures are reported once, not once per attempt: the transport outage in August 2026
	// produced a warning every ten seconds for four days and left a 3.4 GB journal behind.
	// A dead letter is different — each message reaches that state exactly once, and losing
	// one silently is worse than the line it costs.
	$error = (string) ($result['error'] ?? '');
	if ($result['dead'] > 0) {
		fwrite(
			STDERR,
			'[' . date('Y-m-d H:i:s') . '] mail-worker gave up on ' . $result['dead']
			. ' message(s) after their retry budget: ' . $error . PHP_EOL
		);
		$lastErrorSignature = '';
		$lastErrorAt = time();
	} elseif ($error !== '') {
		$signature = hash('sha256', $error);
		if ($signature !== $lastErrorSignature || time() - $lastErrorAt >= 300) {
			$lastErrorSignature = $signature;
			$lastErrorAt = time();
			fwrite(
				STDERR,
				'[' . date('Y-m-d H:i:s') . '] mail-worker could not deliver '
				. $result['retried'] . ' of ' . $result['claimed'] . ' claimed message(s), '
				. 'retrying with a growing delay: ' . $error . PHP_EOL
			);
		}
	}

	if (!$quiet) {
		fwrite(
			STDOUT,
			'[' . date('Y-m-d H:i:s') . '] mail-worker '
			. json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL
		);
	}
	if ($loop) {
		sleep($result['claimed'] > 0 ? 1 : $sleepSeconds);
	}
} while ($loop);

/**
 * systemd's watchdog, spoken directly.
 *
 * `WatchdogSec=` makes systemd expect a `WATCHDOG=1` datagram and restart the service when one
 * stops arriving. It is the only mechanism that would have caught the August 2026 outage: the
 * worker sat blocked inside a single mail() call for four days while systemd, `systemctl
 * status` and every dashboard reported "active (running)".
 *
 * Everything here is optional. Outside systemd — Windows, cron, a container supervisor — the
 * environment carries no socket and every method is a no-op.
 */
final class MailWorkerWatchdog
{
	/** @var resource|null */
	private $socket = null;
	private float $interval = 0.0;
	private float $lastPingAt = 0.0;

	public static function fromEnvironment(): self
	{
		$watchdog = new self();
		$path = (string) (getenv('NOTIFY_SOCKET') ?: '');
		$microseconds = (int) (getenv('WATCHDOG_USEC') ?: '0');
		$owner = (string) (getenv('WATCHDOG_PID') ?: '');
		// A socket in the abstract namespace ("@/org/...") is not addressable through PHP's
		// stream layer; the system manager uses a filesystem path.
		if ($path === '' || $path[0] === '@' || $microseconds <= 0) {
			return $watchdog;
		}
		if ($owner !== '' && (int) $owner !== getmypid()) {
			return $watchdog;
		}
		$errno = 0;
		$error = '';
		$socket = @stream_socket_client('udg://' . $path, $errno, $error, 1);
		if (!is_resource($socket)) {
			fwrite(
				STDERR,
				'mail-worker: cannot open the systemd notify socket: '
				. ($error !== '' ? $error : 'unknown error') . PHP_EOL
			);
			return $watchdog;
		}
		$watchdog->socket = $socket;
		// Reporting twice per interval is the documented safe cadence.
		$watchdog->interval = $microseconds / 2000000;
		return $watchdog;
	}

	/** Report liveness, no more often than the configured interval needs. */
	public function ping(): void
	{
		if ($this->socket === null) {
			return;
		}
		$now = microtime(true);
		if ($this->lastPingAt > 0.0 && $now - $this->lastPingAt < $this->interval) {
			return;
		}
		$this->lastPingAt = $now;
		@fwrite($this->socket, "WATCHDOG=1\n");
	}

	/** The queue summary `systemctl status` shows under the unit description. */
	public function status(string $summary): void
	{
		if ($this->socket === null) {
			return;
		}
		@fwrite($this->socket, 'STATUS=' . str_replace(["\r", "\n"], ' ', $summary) . "\n");
	}
}
