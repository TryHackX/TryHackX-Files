<?php

/**
 * Fail-closed validation for the destructive PHPUnit database bootstrap.
 *
 * This class has no database dependency, so its rules can be tested without touching MySQL.
 */
final class TestDatabaseGuard
{
	private const DEFAULT_ALLOWED_HOSTS = ['localhost', '127.0.0.1', '::1'];

	/**
	 * @param array<string, string> $environment
	 * @return array{host:string,user:string,pass:string,name:string,nonce:string}
	 */
	public static function validate(array $environment, string $productionConfigPath): array
	{
		if (($environment['ALLOW_DESTRUCTIVE_TEST_DB'] ?? '') !== 'YES') {
			throw new RuntimeException(
				'Refusing destructive test bootstrap: set ALLOW_DESTRUCTIVE_TEST_DB=YES explicitly.'
			);
		}

		$nonce = strtolower(trim($environment['TEST_DB_NONCE'] ?? ''));
		if (!preg_match('/\A[0-9a-f]{16,64}\z/D', $nonce)) {
			throw new RuntimeException(
				'TEST_DB_NONCE must be a fresh random hexadecimal value (16-64 characters).'
			);
		}

		$name = trim($environment['TEST_DB_NAME'] ?? '');
		$expectedSuffix = '_test_' . $nonce;
		if (strlen($name) > 64
			|| !preg_match('/\A[A-Za-z][A-Za-z0-9_]*\z/D', $name)
			|| !str_ends_with(strtolower($name), $expectedSuffix)) {
			throw new RuntimeException(
				'TEST_DB_NAME must be an identifier ending exactly in _test_<TEST_DB_NONCE>.'
			);
		}

		$host = self::normalizeHost($environment['TEST_DB_HOST'] ?? '');
		if ($host === '') {
			throw new RuntimeException('TEST_DB_HOST must be set explicitly.');
		}
		$allowedHosts = self::allowedHosts($environment['TEST_DB_ALLOWED_HOSTS'] ?? '');
		if (!in_array($host, $allowedHosts, true)) {
			throw new RuntimeException(
				'TEST_DB_HOST is not allowlisted. Use loopback or list it in TEST_DB_ALLOWED_HOSTS.'
			);
		}

		$user = trim($environment['TEST_DB_USER'] ?? '');
		if ($user === '') {
			throw new RuntimeException('TEST_DB_USER must be set explicitly.');
		}

		$production = self::readLiteralDatabaseConfig($productionConfigPath);
		if (($production['DB_NAME'] ?? '') !== ''
			&& strcasecmp($name, (string) $production['DB_NAME']) === 0) {
			throw new RuntimeException('TEST_DB_NAME resolves to the configured production database.');
		}

		// If both coordinates point at the same server, give an especially clear diagnostic.
		$productionHost = self::normalizeHost((string) ($production['DB_HOST'] ?? ''));
		if ($productionHost !== '' && $productionHost === $host
			&& ($production['DB_NAME'] ?? '') !== ''
			&& strcasecmp($name, (string) $production['DB_NAME']) === 0) {
			throw new RuntimeException('Test and production database coordinates are identical.');
		}

		return [
			'host' => $host,
			'user' => $user,
			'pass' => (string) ($environment['TEST_DB_PASS'] ?? ''),
			'name' => $name,
			'nonce' => $nonce,
		];
	}

	/**
	 * Read literal define('DB_*', '...') calls without executing a production config file.
	 *
	 * @return array<string, string>
	 */
	public static function readLiteralDatabaseConfig(string $path): array
	{
		if ($path === '' || !is_file($path)) {
			return [];
		}
		$source = file_get_contents($path);
		if ($source === false) {
			throw new RuntimeException('Cannot read production config for test DB comparison.');
		}

		$tokens = token_get_all($source);
		$result = [];
		$count = count($tokens);
		for ($i = 0; $i < $count; $i++) {
			$token = $tokens[$i];
			if (!is_array($token) || $token[0] !== T_STRING
				|| strcasecmp($token[1], 'define') !== 0) {
				continue;
			}

			$nameToken = self::nextMeaningfulToken($tokens, $i + 1, $nameIndex, '(');
			if (!is_array($nameToken) || $nameToken[0] !== T_CONSTANT_ENCAPSED_STRING) {
				continue;
			}
			$name = self::decodeLiteral($nameToken[1]);
			if (!in_array($name, ['DB_HOST', 'DB_NAME', 'DB_USER'], true)) {
				continue;
			}

			$valueToken = self::nextMeaningfulToken($tokens, $nameIndex + 1, $valueIndex, ',');
			if (!is_array($valueToken) || $valueToken[0] !== T_CONSTANT_ENCAPSED_STRING) {
				continue;
			}
			$result[$name] = self::decodeLiteral($valueToken[1]);
			$i = $valueIndex;
		}
		return $result;
	}

	/**
	 * @param array<int, array|string> $tokens
	 * @return array|string|null
	 */
	private static function nextMeaningfulToken(
		array $tokens,
		int $start,
		?int &$foundIndex,
		string $separator
	) {
		$seenSeparator = false;
		$count = count($tokens);
		for ($i = $start; $i < $count; $i++) {
			$token = $tokens[$i];
			if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
				continue;
			}
			if (!$seenSeparator) {
				if ($token !== $separator) {
					return null;
				}
				$seenSeparator = true;
				continue;
			}
			$foundIndex = $i;
			return $token;
		}
		return null;
	}

	private static function decodeLiteral(string $literal): string
	{
		$quote = $literal[0] ?? '';
		$value = substr($literal, 1, -1);
		if ($quote === "'") {
			return str_replace(["\\\\", "\\'"], ["\\", "'"], $value);
		}
		return stripcslashes($value);
	}

	/**
	 * @return list<string>
	 */
	private static function allowedHosts(string $configured): array
	{
		$hosts = self::DEFAULT_ALLOWED_HOSTS;
		if (trim($configured) !== '') {
			foreach (preg_split('/[\s,]+/', trim($configured)) ?: [] as $candidate) {
				$normalized = self::normalizeHost($candidate);
				if ($normalized === '' || str_contains($normalized, '*')) {
					throw new RuntimeException('TEST_DB_ALLOWED_HOSTS accepts exact hosts only.');
				}
				$hosts[] = $normalized;
			}
		}
		return array_values(array_unique($hosts));
	}

	private static function normalizeHost(string $host): string
	{
		$host = strtolower(trim($host));
		if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
			$host = substr($host, 1, -1);
		}
		return rtrim($host, '.');
	}
}
