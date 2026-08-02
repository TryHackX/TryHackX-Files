<?php

declare(strict_types=1);

/**
 * Normalize and validate the public base URL used by TryHackX Files.
 *
 * The result contains a scheme, an ASCII host, an optional non-default port and an optional
 * deployment path. Query strings, fragments, credentials and ambiguous path forms are refused
 * because this value is also the trust anchor for password-reset and activation links.
 *
 * @throws InvalidArgumentException
 */
function filehostNormalizeCanonicalUrl(string $url): string
{
	$url = trim($url);
	if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
		|| strpbrk($url, "\"'<>`") !== false) {
		throw new InvalidArgumentException('Canonical URL is empty or contains forbidden characters.');
	}

	$parts = parse_url($url);
	if (!is_array($parts)
		|| !isset($parts['scheme'], $parts['host'])
		|| isset($parts['user']) || isset($parts['pass'])
		|| isset($parts['query']) || isset($parts['fragment'])) {
		throw new InvalidArgumentException('Canonical URL must contain only scheme, host, optional port and path.');
	}

	$scheme = strtolower((string) $parts['scheme']);
	if (!in_array($scheme, ['http', 'https'], true)) {
		throw new InvalidArgumentException('Canonical URL scheme must be http or https.');
	}

	$host = strtolower((string) $parts['host']);
	$host = trim($host, '[]');
	if ($host === '' || str_ends_with($host, '.')) {
		throw new InvalidArgumentException('Canonical URL host is invalid.');
	}
	$isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
	$isHostname = filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
	if (!$isIp && !$isHostname) {
		throw new InvalidArgumentException('Canonical URL host must be a valid IP address or ASCII hostname.');
	}

	$port = isset($parts['port']) ? (int) $parts['port'] : null;
	if ($port !== null && ($port < 1 || $port > 65535)) {
		throw new InvalidArgumentException('Canonical URL port is invalid.');
	}
	if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
		$port = null;
	}

	$path = (string) ($parts['path'] ?? '');
	if ($path !== '') {
		if ($path[0] !== '/' || str_contains($path, '//')) {
			throw new InvalidArgumentException('Canonical URL path is invalid.');
		}
		foreach (explode('/', $path) as $segment) {
			$decoded = rawurldecode($segment);
			if ($decoded === '.' || $decoded === '..'
				|| str_contains($decoded, '/') || str_contains($decoded, '\\')
				|| preg_match('/[\x00-\x1f\x7f]/', $decoded)) {
				throw new InvalidArgumentException('Canonical URL path contains an unsafe segment.');
			}
		}
		$path = rtrim($path, '/');
	}

	$displayHost = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
		? '[' . $host . ']'
		: $host;

	return $scheme . '://' . $displayHost . ($port !== null ? ':' . $port : '') . $path;
}

/**
 * Return only the normalized origin (scheme + host + optional non-default port).
 *
 * @throws InvalidArgumentException
 */
function filehostCanonicalOrigin(string $url): string
{
	$normalized = filehostNormalizeCanonicalUrl($url);
	$parts = parse_url($normalized);
	$host = trim((string) ($parts['host'] ?? ''), '[]');
	$displayHost = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
		? '[' . strtolower($host) . ']'
		: strtolower($host);
	$port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

	return strtolower((string) $parts['scheme']) . '://' . $displayHost . $port;
}

/**
 * Normalize a browser Origin/Referer value to its origin, or return null when it is invalid.
 */
function filehostOriginOrNull(string $url): ?string
{
	$url = trim($url);
	if ($url === '' || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
		|| strpbrk($url, "\"'<>`") !== false) {
		return null;
	}
	$parts = parse_url($url);
	if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
		|| isset($parts['user']) || isset($parts['pass'])) {
		return null;
	}

	$host = trim((string) $parts['host'], '[]');
	$displayHost = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
		? '[' . $host . ']'
		: $host;
	$candidate = (string) $parts['scheme'] . '://' . $displayHost
		. (isset($parts['port']) ? ':' . (int) $parts['port'] : '');

	try {
		return filehostCanonicalOrigin($candidate);
	} catch (InvalidArgumentException) {
		return null;
	}
}

/**
 * Build and validate the origin of the current HTTP request.
 *
 * @throws InvalidArgumentException
 */
function filehostRequestOrigin(bool $secure, string $hostHeader): string
{
	if ($hostHeader === '' || strlen($hostHeader) > 255
		|| preg_match('/[\x00-\x20\x7f,\/\\\\@]/', $hostHeader)) {
		throw new InvalidArgumentException('Invalid Host header.');
	}

	return filehostCanonicalOrigin(($secure ? 'https://' : 'http://') . $hostHeader);
}

/**
 * Compare scheme, hostname and normalized port. The canonical deployment path is intentionally
 * not part of an HTTP origin.
 */
function filehostRequestMatchesCanonicalOrigin(bool $secure, string $hostHeader, string $canonicalUrl): bool
{
	try {
		return hash_equals(
			filehostCanonicalOrigin($canonicalUrl),
			filehostRequestOrigin($secure, $hostHeader)
		);
	} catch (InvalidArgumentException) {
		return false;
	}
}
