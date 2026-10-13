<?php

declare(strict_types=1);

/**
 * Work out which address a request really came from.
 *
 * Without a configured proxy the direct peer (REMOTE_ADDR) is the answer and nothing a client
 * sends can change it. Behind a reverse proxy the proxy's forwarding header is consulted, but
 * only the part of it the proxy itself wrote:
 *
 *   - X-Forwarded-For is read from the RIGHT. A proxy appends the address it saw, so the
 *     right-most entry that is not one of our own proxies is the client; everything to the
 *     left of it was supplied by that client and proves nothing. Taking the left-most entry
 *     (as this used to) let anyone pick their own address with a single header.
 *   - CF-Connecting-IP and X-Real-IP are single values a proxy SETS, overwriting whatever the
 *     client sent — but only if the proxy is configured to. Which one to believe is therefore
 *     the operator's call (TRUSTED_PROXY_HEADER); none of them is believed by default.
 *
 * @param list<string> $trustedProxies exact proxy addresses (TRUSTED_PROXIES)
 * @param array<string, mixed> $server the request's $_SERVER
 */
function filehostResolveClientIp(array $server, array $trustedProxies, string $trustedHeader = ''): string
{
	$remote = trim((string) ($server['REMOTE_ADDR'] ?? ''));
	if ($remote === '') {
		return 'unknown';
	}
	$trustedProxies = array_values(array_filter(array_map('trim', $trustedProxies), 'strlen'));
	if ($trustedProxies === [] || !in_array($remote, $trustedProxies, true)) {
		return $remote;
	}

	$header = strtolower(trim($trustedHeader));
	if ($header === '' || $header === 'x-forwarded-for') {
		$chain = array_map('trim', explode(',', (string) ($server['HTTP_X_FORWARDED_FOR'] ?? '')));
		for ($i = count($chain) - 1; $i >= 0; $i--) {
			$candidate = $chain[$i];
			if ($candidate === '') {
				continue;
			}
			if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
				// Garbage where our proxy should have written an address: do not guess.
				return $remote;
			}
			if (!in_array($candidate, $trustedProxies, true)) {
				return $candidate;
			}
		}
		return $remote;
	}

	$serverKey = [
		'cf-connecting-ip' => 'HTTP_CF_CONNECTING_IP',
		'x-real-ip' => 'HTTP_X_REAL_IP',
	][$header] ?? null;
	if ($serverKey === null) {
		return $remote;
	}
	$value = trim((string) ($server[$serverKey] ?? ''));
	return filter_var($value, FILTER_VALIDATE_IP) !== false ? $value : $remote;
}

/**
 * The key an address is throttled and counted under.
 *
 * An IPv6 customer is routinely handed a whole /64, so counting single addresses gives one
 * person 2^64 fresh budgets. IPv6 is therefore counted per /64 network; IPv4 per address.
 */
function filehostThrottleKey(string $ip): string
{
	$packed = @inet_pton($ip);
	if ($packed === false || strlen($packed) !== 16) {
		return $ip;
	}
	// An IPv4-mapped IPv6 address is really the IPv4 one.
	if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
		$v4 = inet_ntop(substr($packed, 12));
		return $v4 !== false ? $v4 : $ip;
	}
	$network = inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8));
	return ($network !== false ? $network : $ip) . '/64';
}
