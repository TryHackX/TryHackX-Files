<?php
/**
 * SettingsRepository.
 *
 * Owns the settings table plus request-local and cross-request caching. The persisted cache
 * is data-only JSON. All readers, writers and invalidators use one process lock, so an older
 * DB snapshot cannot be published after a newer setting was committed.
 */
final class SettingsRepository
{
	private const CACHE_FORMAT_VERSION = 1;

	/** All settings, cached for the whole request. */
	private static ?array $cache = null;

	private static function dataDirectory(): string
	{
		return defined('DATA_DIR') ? DATA_DIR : __DIR__ . '/../../data';
	}

	private static function cachePath(): string
	{
		return self::dataDirectory() . DIRECTORY_SEPARATOR . 'settings.cache.json';
	}

	private static function legacyCachePath(): string
	{
		return self::dataDirectory() . DIRECTORY_SEPARATOR . 'settings.cache.php';
	}

	private static function lockPath(): string
	{
		return self::dataDirectory() . DIRECTORY_SEPARATOR . 'settings.cache.lock';
	}

	private static function ensureDataDirectory(): void
	{
		$directory = self::dataDirectory();
		if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
			throw new RuntimeException('Cannot create the settings cache directory.');
		}
		if (!is_dir($directory) || is_link($directory)) {
			throw new RuntimeException('Settings cache directory is not a regular directory.');
		}
	}

	private static function protectFile(string $path): void
	{
		// chmod does not model Windows ACLs. The data directory ACL is authoritative there.
		if (DIRECTORY_SEPARATOR === '\\') {
			@chmod($path, 0600);
			return;
		}
		if (!chmod($path, 0600)) {
			throw new RuntimeException('Cannot restrict settings cache permissions to 0600.');
		}
		clearstatcache(true, $path);
		$mode = fileperms($path);
		if ($mode === false || (($mode & 0777) !== 0600)) {
			throw new RuntimeException('Settings cache permissions are not 0600.');
		}
	}

	/** @param resource $handle */
	private static function writeFully($handle, string $payload): void
	{
		$offset = 0;
		$length = strlen($payload);
		while ($offset < $length) {
			$written = fwrite($handle, substr($payload, $offset));
			if ($written === false || $written === 0) {
				throw new RuntimeException('Cannot write the settings cache.');
			}
			$offset += $written;
		}
		if (!fflush($handle)) {
			throw new RuntimeException('Cannot flush the settings cache.');
		}
		if (function_exists('fsync') && !fsync($handle)) {
			throw new RuntimeException('Cannot sync the settings cache to disk.');
		}
	}

	/**
	 * Serialize every cache read/write/invalidation.
	 *
	 * @template T
	 * @param callable():T $operation
	 * @return T
	 */
	private static function withCacheLock(callable $operation)
	{
		self::ensureDataDirectory();
		$lockPath = self::lockPath();
		$lock = fopen($lockPath, 'c+b');
		if ($lock === false) {
			throw new RuntimeException('Cannot open the settings cache lock.');
		}

		try {
			self::protectFile($lockPath);
			if (!flock($lock, LOCK_EX)) {
				throw new RuntimeException('Cannot lock the settings cache.');
			}
			return $operation();
		} finally {
			if (is_resource($lock)) {
				@flock($lock, LOCK_UN);
				fclose($lock);
			}
		}
	}

	private static function removeRegularFile(string $path, string $label): void
	{
		if (!file_exists($path) && !is_link($path)) {
			return;
		}
		if (!is_file($path) || is_link($path)) {
			throw new RuntimeException($label . ' path is not a regular file.');
		}
		if (!unlink($path)) {
			throw new RuntimeException('Cannot remove ' . $label . '.');
		}
	}

	private static function invalidateUnlocked(): void
	{
		self::$cache = null;
		self::removeRegularFile(self::cachePath(), 'settings cache');
		self::removeRegularFile(self::legacyCachePath(), 'legacy settings cache');
	}

	private static function readCacheUnlocked(): ?array
	{
		$path = self::cachePath();
		if (!file_exists($path) && !is_link($path)) {
			return null;
		}
		if (!is_file($path) || is_link($path)) {
			throw new RuntimeException('Settings cache path is not a regular file.');
		}

		$raw = file_get_contents($path);
		if ($raw === false) {
			throw new RuntimeException('Cannot read the settings cache.');
		}
		try {
			$payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw new RuntimeException('Settings cache contains invalid JSON.', 0, $e);
		}

		if (!is_array($payload)
			|| ($payload['format'] ?? null) !== self::CACHE_FORMAT_VERSION
			|| !is_int($payload['generated_at'] ?? null)
			|| !is_string($payload['generation'] ?? null)
			|| !preg_match('/\A[0-9a-f]{32}\z/D', $payload['generation'])
			|| !is_array($payload['settings'] ?? null)) {
			throw new RuntimeException('Settings cache envelope is invalid.');
		}

		return $payload['settings'];
	}

	private static function writeCacheUnlocked(array $settings): void
	{
		$payload = [
			'format' => self::CACHE_FORMAT_VERSION,
			'generation' => bin2hex(random_bytes(16)),
			'generated_at' => time(),
			'settings' => $settings,
		];
		try {
			$json = json_encode(
				$payload,
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
			);
		} catch (JsonException $e) {
			throw new RuntimeException('Cannot encode the settings cache.', 0, $e);
		}

		$target = self::cachePath();
		$temp = $target . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(8));
		$handle = fopen($temp, 'x+b');
		if ($handle === false) {
			throw new RuntimeException('Cannot exclusively create a temporary settings cache.');
		}

		try {
			self::protectFile($temp);
			self::writeFully($handle, $json . PHP_EOL);
			fclose($handle);
			$handle = null;

			// POSIX replaces atomically. On Windows the guarded fallback removes a previous
			// target first; cooperating readers cannot observe the gap because they hold this lock.
			if (!rename($temp, $target)) {
				if (file_exists($target) || is_link($target)) {
					self::removeRegularFile($target, 'previous settings cache');
				}
				if (!rename($temp, $target)) {
					throw new RuntimeException('Cannot publish the settings cache atomically.');
				}
			}
			self::protectFile($target);
		} finally {
			if (is_resource($handle)) {
				fclose($handle);
			}
			if (file_exists($temp) && is_file($temp)) {
				@unlink($temp);
			}
		}
	}

	/**
	 * Load every setting once per request. A malformed cache is reported, discarded and
	 * rebuilt from the database. The former executable PHP cache is never included.
	 */
	private static function load(): array
	{
		if (self::$cache !== null) {
			return self::$cache;
		}

		return self::withCacheLock(static function (): array {
			if (self::$cache !== null) {
				return self::$cache;
			}

			self::removeRegularFile(self::legacyCachePath(), 'legacy settings cache');

			try {
				$cached = self::readCacheUnlocked();
			} catch (RuntimeException $e) {
				error_log('Settings cache rejected: ' . $e->getMessage());
				self::removeRegularFile(self::cachePath(), 'invalid settings cache');
				$cached = null;
			}
			if ($cached !== null) {
				return self::$cache = $cached;
			}

			$pdo = Database::getInstance();
			if (!$pdo) {
				return [];
			}

			$table = Database::table('settings');
			try {
				$stmt = $pdo->query("SELECT * FROM `{$table}`");
				$settings = [];
				while ($row = $stmt->fetch()) {
					$settings[$row['setting_key']] = $row['setting_value'];
				}
			} catch (PDOException $e) {
				return [];
			}

			self::writeCacheUnlocked($settings);
			return self::$cache = $settings;
		});
	}

	/** Drop both request-local and persisted cache copies, reporting every I/O failure. */
	public static function invalidateCache(): void
	{
		self::withCacheLock(static function (): void {
			self::invalidateUnlocked();
		});
	}

	public static function get(string $key, $default = null)
	{
		$settings = self::load();
		return array_key_exists($key, $settings) ? $settings[$key] : $default;
	}

	/**
	 * Read a secret setting, transparently decrypting it. Legacy plaintext is returned as-is.
	 */
	public static function getSecret(string $key, $default = null)
	{
		$raw = self::get($key, null);
		if ($raw === null || $raw === '') {
			return $default;
		}
		require_once __DIR__ . '/../Crypto.php';
		return Crypto::decrypt((string) $raw);
	}

	/** Store a secret setting encrypted at rest. Empty value clears it. */
	public static function setSecret(string $key, string $value): bool
	{
		require_once __DIR__ . '/../Crypto.php';
		return self::set($key, $value === '' ? '' : Crypto::encrypt($value));
	}

	public static function set(string $key, $value): bool
	{
		$pdo = Database::getInstance();
		if (!$pdo) {
			return false;
		}

		try {
			return self::withCacheLock(static function () use ($pdo, $key, $value): bool {
				$table = Database::table('settings');
				$stmt = $pdo->prepare(
					"INSERT INTO `{$table}` (`setting_key`, `setting_value`) VALUES (?, ?)
					 ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)"
				);
				$ok = $stmt->execute([$key, $value]);
				if ($ok) {
					self::invalidateUnlocked();
				}
				return $ok;
			});
		} catch (PDOException $e) {
			return false;
		}
	}

	public static function all(): array
	{
		return self::load();
	}
}
