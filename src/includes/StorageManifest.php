<?php

/**
 * Exact on-disk identity for one uploaded object.
 *
 * Upload directories may contain maintenance artifacts, so callers must never select the first
 * directory entry. The database supplies the expected name and size; this manifest freezes
 * those values together with a SHA-256 computed while publishing (or during one legacy repair).
 */
final class StorageManifest
{
	public const FILE_NAME = '.filehost-storage-v1.json';
	private const VERSION = 1;
	private const MAX_BYTES = 4096;

	/**
	 * Resolve an exact regular file below $uploadsRoot.
	 *
	 * Legacy directories are repaired only when the exact database filename and size match.
	 * Normal request paths validate the manifest contract without hashing a multi-gigabyte file
	 * on every response; maintenance checks can request full checksum verification.
	 *
	 * @return array{path:string,sha256:string,repaired:bool}|null
	 */
	public static function resolve(
		string $uploadsRoot,
		string $fileId,
		string $expectedName,
		int $expectedSize,
		bool $verifyChecksum = false,
		bool $repairLegacy = true
	): ?array {
		if (!preg_match('/\A[A-Za-z0-9_-]{1,32}\z/D', $fileId)
			|| !self::validObjectName($expectedName)
			|| $expectedSize < 0) {
			return null;
		}

		$root = realpath($uploadsRoot);
		$dirCandidate = rtrim($uploadsRoot, '/\\') . DIRECTORY_SEPARATOR . $fileId;
		if ($root === false || is_link($dirCandidate) || !is_dir($dirCandidate)) {
			return null;
		}
		$dir = realpath($dirCandidate);
		if ($dir === false || !self::isDirectChild($root, $dir)) {
			return null;
		}

		$manifestPath = $dir . DIRECTORY_SEPARATOR . self::FILE_NAME;
		$repaired = false;
		if (!is_file($manifestPath)) {
			if (file_exists($manifestPath) || is_link($manifestPath)) {
				return null;
			}
			$legacyPath = self::exactObjectPath($dir, $expectedName, $expectedSize);
			if ($legacyPath === null) {
				return null;
			}
			if (!$repairLegacy) {
				return null;
			}
			$sha256 = hash_file('sha256', $legacyPath);
			if (!is_string($sha256) || !self::write($dir, $expectedName, $expectedSize, $sha256)) {
				if ($verifyChecksum) {
					return null;
				}
				// A read-only legacy store can still be served by exact DB identity; the
				// integrity checker will keep reporting its missing manifest.
				return ['path' => $legacyPath, 'sha256' => $sha256 ?: '', 'repaired' => false];
			}
			$repaired = true;
		}

		if (is_link($manifestPath)) {
			return null;
		}
		$manifestStat = @stat($manifestPath);
		if (!$manifestStat || (int) $manifestStat['size'] < 2 || (int) $manifestStat['size'] > self::MAX_BYTES) {
			return null;
		}
		$raw = @file_get_contents($manifestPath);
		$data = is_string($raw) ? json_decode($raw, true) : null;
		if (!is_array($data)
			|| ($data['version'] ?? null) !== self::VERSION
			|| ($data['name'] ?? null) !== $expectedName
			|| ($data['size'] ?? null) !== $expectedSize
			|| !is_string($data['sha256'] ?? null)
			|| !preg_match('/\A[a-f0-9]{64}\z/D', $data['sha256'])) {
			return null;
		}

		$path = self::exactObjectPath($dir, $data['name'], $data['size']);
		if ($path === null) {
			return null;
		}
		if ($verifyChecksum) {
			$actual = hash_file('sha256', $path);
			if (!is_string($actual) || !hash_equals($data['sha256'], $actual)) {
				return null;
			}
		}

		return ['path' => $path, 'sha256' => $data['sha256'], 'repaired' => $repaired];
	}

	public static function write(
		string $directory,
		string $name,
		int $size,
		string $sha256
	): bool {
		if (!self::validObjectName($name)
			|| $size < 0
			|| !preg_match('/\A[a-f0-9]{64}\z/D', $sha256)
			|| is_link($directory)
			|| !is_dir($directory)) {
			return false;
		}
		$json = json_encode([
			'version' => self::VERSION,
			'name' => $name,
			'size' => $size,
			'sha256' => $sha256,
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (!is_string($json)) {
			return false;
		}

		$temp = @tempnam($directory, '.filehost-manifest-');
		if (!is_string($temp)) {
			return false;
		}
		try {
			if (@file_put_contents($temp, $json . "\n", LOCK_EX) !== strlen($json) + 1) {
				return false;
			}
			return @rename($temp, $directory . DIRECTORY_SEPARATOR . self::FILE_NAME);
		} finally {
			if (is_file($temp) || is_link($temp)) {
				@unlink($temp);
			}
		}
	}

	private static function validObjectName(string $name): bool
	{
		return $name !== ''
			&& $name !== '.'
			&& $name !== '..'
			&& $name !== self::FILE_NAME
			&& strlen($name) <= 255
			&& !str_contains($name, '/')
			&& !str_contains($name, '\\')
			&& !preg_match('/[\x00-\x1f\x7f]/', $name);
	}

	private static function exactObjectPath(string $directory, string $name, int $size): ?string
	{
		if (!self::validObjectName($name)) {
			return null;
		}
		$candidate = $directory . DIRECTORY_SEPARATOR . $name;
		if (is_link($candidate) || !is_file($candidate)) {
			return null;
		}
		$real = realpath($candidate);
		$parent = $real !== false ? realpath(dirname($real)) : false;
		$actualSize = $real !== false ? @filesize($real) : false;
		return $real !== false && $parent === $directory && $actualSize === $size ? $real : null;
	}

	private static function isDirectChild(string $parent, string $child): bool
	{
		$normalise = static function (string $path): string {
			$path = rtrim(str_replace('\\', '/', $path), '/');
			return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
		};
		return dirname($normalise($child)) === $normalise($parent);
	}
}
