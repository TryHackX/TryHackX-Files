<?php
/**
 * Symmetric encryption for secrets stored in the database (S12).
 *
 * Threat model: a leaked database dump. Secrets (e.g. the SMTP password) live in
 * the `settings` table, so a dump would expose them in cleartext. Here they are
 * encrypted with AES-256-GCM using a key that is *not* in the database — so a
 * DB-only leak cannot decrypt them.
 *
 * Key source, in order of preference:
 *   1. `APP_SECRET_KEY` from the process environment or config/config.local.php.
 *   2. A persisted random key file `data/.appkey`, but only when APP_ENV explicitly
 *      names a non-production environment.
 *
 * Production deliberately has no implicit key generation. Losing or replacing the key
 * makes existing encrypted settings unreadable, so it must be durable secret material.
 *
 * Stored format: `enc:v1:` + base64(iv[12] . tag[16] . ciphertext). Values without
 * the prefix are treated as legacy plaintext and returned as-is by decrypt(), so
 * the change is backward-compatible and can migrate data lazily.
 */
final class Crypto
{
	private const PREFIX = 'enc:v1:';
	private const CIPHER = 'aes-256-gcm';
	private const KEY_HEX_LENGTH = 64;
	private const NON_PRODUCTION_ENVIRONMENTS = [
		'dev',
		'development',
		'local',
		'test',
		'testing',
	];

	private static ?string $key = null;

	/**
	 * Resolve an environment/constant secret without silently accepting two different keys.
	 */
	private static function configuredSecret(): ?string
	{
		$environmentSecret = getenv('APP_SECRET_KEY');
		$environmentSecret = $environmentSecret === false ? '' : trim((string) $environmentSecret);
		$constantSecret = defined('APP_SECRET_KEY') ? trim((string) APP_SECRET_KEY) : '';

		if ($environmentSecret !== '' && $constantSecret !== ''
			&& !hash_equals($environmentSecret, $constantSecret)) {
			throw new RuntimeException(
				'APP_SECRET_KEY differs between the process environment and application config.'
			);
		}

		$secret = $environmentSecret !== '' ? $environmentSecret : $constantSecret;
		if ($secret === '') {
			return null;
		}
		if (strlen($secret) < 32) {
			throw new RuntimeException('APP_SECRET_KEY must contain at least 32 bytes.');
		}
		return $secret;
	}

	private static function deriveConfiguredKey(string $secret): string
	{
		// Compatibility bridge for an older data/.appkey: that file stores the raw key as
		// hexadecimal, whereas ordinary configured secrets are deliberately KDF-hashed.
		if (str_starts_with($secret, 'hex:')) {
			$hex = substr($secret, 4);
			if (!preg_match('/\A[0-9a-fA-F]{64}\z/D', $hex)) {
				throw new RuntimeException('hex: APP_SECRET_KEY must contain exactly 64 hex digits.');
			}
			$key = hex2bin($hex);
			if ($key === false || strlen($key) !== 32) {
				throw new RuntimeException('hex: APP_SECRET_KEY is invalid.');
			}
			return $key;
		}
		return hash('sha256', $secret, true);
	}

	/**
	 * The secure default is production. File fallback must be opted into explicitly.
	 */
	private static function environment(): string
	{
		$value = defined('APP_ENV') ? (string) APP_ENV : (string) (getenv('APP_ENV') ?: '');
		if ($value === '') {
			$value = (string) (getenv('FILEHOST_ENV') ?: 'production');
		}
		return strtolower(trim($value));
	}

	private static function dataDirectory(): string
	{
		return defined('DATA_DIR') ? DATA_DIR : __DIR__ . '/../../data';
	}

	/**
	 * Read and validate a persisted key. A malformed file is an error, never a signal to
	 * generate a replacement that would orphan already-encrypted data.
	 */
	private static function readKeyFile(string $file): ?string
	{
		if (!file_exists($file)) {
			return null;
		}
		if (!is_file($file) || is_link($file)) {
			throw new RuntimeException('Crypto key path is not a regular file.');
		}

		$raw = file_get_contents($file);
		if ($raw === false) {
			throw new RuntimeException('Cannot read the persisted crypto key.');
		}
		$hex = trim($raw);
		if (!preg_match('/\A[0-9a-fA-F]{' . self::KEY_HEX_LENGTH . '}\z/D', $hex)) {
			throw new RuntimeException('Persisted crypto key has an invalid format.');
		}
		$key = hex2bin($hex);
		if ($key === false || strlen($key) !== 32) {
			throw new RuntimeException('Persisted crypto key has an invalid length.');
		}
		return $key;
	}

	/** @param resource $handle */
	private static function writeFully($handle, string $payload): void
	{
		$offset = 0;
		$length = strlen($payload);
		while ($offset < $length) {
			$written = fwrite($handle, substr($payload, $offset));
			if ($written === false || $written === 0) {
				throw new RuntimeException('Cannot persist the generated crypto key.');
			}
			$offset += $written;
		}
		if (!fflush($handle)) {
			throw new RuntimeException('Cannot flush the generated crypto key.');
		}
		if (function_exists('fsync') && !fsync($handle)) {
			throw new RuntimeException('Cannot sync the generated crypto key to disk.');
		}
	}

	private static function protectFile(string $file): void
	{
		// Windows ACLs are not represented faithfully by chmod(). The containing data
		// directory ACL remains authoritative there.
		if (DIRECTORY_SEPARATOR === '\\') {
			@chmod($file, 0600);
			return;
		}
		if (!chmod($file, 0600)) {
			throw new RuntimeException('Cannot restrict crypto key permissions to 0600.');
		}
		clearstatcache(true, $file);
		$mode = fileperms($file);
		if ($mode === false || (($mode & 0777) !== 0600)) {
			throw new RuntimeException('Crypto key permissions are not 0600.');
		}
	}

	/**
	 * Development-only fallback, serialized by a companion lock file. The key itself is
	 * created with O_EXCL semantics, flushed and re-read from disk before use.
	 */
	private static function persistedDevelopmentKey(): string
	{
		$dir = self::dataDirectory();
		if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
			throw new RuntimeException('Cannot create the crypto data directory.');
		}
		if (!is_dir($dir) || is_link($dir)) {
			throw new RuntimeException('Crypto data directory is not a regular directory.');
		}

		$file = $dir . DIRECTORY_SEPARATOR . '.appkey';
		$lockFile = $dir . DIRECTORY_SEPARATOR . '.appkey.lock';
		$lock = fopen($lockFile, 'c+b');
		if ($lock === false) {
			throw new RuntimeException('Cannot open the crypto key lock file.');
		}

		try {
			self::protectFile($lockFile);
			if (!flock($lock, LOCK_EX)) {
				throw new RuntimeException('Cannot lock crypto key creation.');
			}

			$existing = self::readKeyFile($file);
			if ($existing !== null) {
				return $existing;
			}

			$bytes = random_bytes(32);
			$writer = fopen($file, 'x+b');
			if ($writer === false) {
				// A non-cooperating creator may have won between the read and exclusive create.
				$winner = self::readKeyFile($file);
				if ($winner !== null) {
					return $winner;
				}
				throw new RuntimeException('Cannot exclusively create the crypto key file.');
			}

			try {
				self::protectFile($file);
				self::writeFully($writer, bin2hex($bytes) . PHP_EOL);
			} catch (Throwable $e) {
				fclose($writer);
				$writer = null;
				if (file_exists($file) && !unlink($file)) {
					throw new RuntimeException(
						'Crypto key persistence failed and the partial key could not be removed.',
						0,
						$e
					);
				}
				throw $e;
			} finally {
				if (is_resource($writer)) {
					fclose($writer);
				}
			}

			$persisted = self::readKeyFile($file);
			if ($persisted === null || !hash_equals($bytes, $persisted)) {
				throw new RuntimeException('Persisted crypto key verification failed.');
			}
			return $persisted;
		} finally {
			if (is_resource($lock)) {
				@flock($lock, LOCK_UN);
				fclose($lock);
			}
		}
	}

	private static function key(): string
	{
		if (self::$key !== null) {
			return self::$key;
		}

		$secret = self::configuredSecret();
		if ($secret !== null) {
			return self::$key = self::deriveConfiguredKey($secret);
		}

		$environment = self::environment();
		if (!in_array($environment, self::NON_PRODUCTION_ENVIRONMENTS, true)) {
			throw new RuntimeException(
				'APP_SECRET_KEY is required outside an explicitly non-production APP_ENV.'
			);
		}

		return self::$key = self::persistedDevelopmentKey();
	}

	/** True if $v is one of our encrypted blobs (vs. legacy plaintext). */
	public static function isEncrypted(string $v): bool
	{
		return strncmp($v, self::PREFIX, strlen(self::PREFIX)) === 0;
	}

	/** Encrypt a plaintext string. Empty input returns empty (nothing to protect). */
	public static function encrypt(string $plain): string
	{
		if ($plain === '') {
			return '';
		}
		$iv = random_bytes(12);
		$tag = '';
		$ct = openssl_encrypt($plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
		if ($ct === false) {
			// Extremely unlikely with valid params; never silently store cleartext.
			throw new RuntimeException('Crypto::encrypt failed');
		}
		return self::PREFIX . base64_encode($iv . $tag . $ct);
	}

	/** Decrypt a stored value. Legacy plaintext (no prefix) is returned unchanged. */
	public static function decrypt(string $stored): string
	{
		if (!self::isEncrypted($stored)) {
			return $stored; // legacy plaintext
		}
		$blob = base64_decode(substr($stored, strlen(self::PREFIX)), true);
		if ($blob === false || strlen($blob) < 28) {
			return '';
		}
		$iv = substr($blob, 0, 12);
		$tag = substr($blob, 12, 16);
		$ct = substr($blob, 28);
		$pt = openssl_decrypt($ct, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
		return $pt === false ? '' : $pt;
	}

	/** Domain-separated HMAC for opaque application URLs and short-lived capabilities. */
	public static function sign(string $domain, string $payload): string
	{
		if ($domain === '' || strlen($domain) > 100) {
			throw new InvalidArgumentException('Crypto signature domain is invalid.');
		}
		return hash_hmac('sha256', $domain . "\0" . $payload, self::key());
	}

	public static function verify(string $domain, string $payload, string $signature): bool
	{
		return preg_match('/\A[0-9a-f]{64}\z/D', $signature) === 1
			&& hash_equals(self::sign($domain, $payload), $signature);
	}
}
