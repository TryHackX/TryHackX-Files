<?php

declare(strict_types=1);

/**
 * The installer is open only when neither installed-state artifact exists.
 *
 * Configuration is authoritative: restoring or deleting the lock volume alone must never
 * expose an already configured deployment.
 */
function filehostInstallerState(bool $configExists, bool $lockExists): string
{
	if ($configExists) {
		return 'configured';
	}
	if ($lockExists) {
		return 'locked';
	}
	return 'open';
}

/**
 * Reject empty, short and well-known placeholder bootstrap secrets.
 */
function filehostInstallerSecretIsStrong(string $secret): bool
{
	$secret = trim($secret);
	if (strlen($secret) < 32 || strlen($secret) > 1024) {
		return false;
	}
	if (count(array_unique(str_split($secret))) < 8) {
		return false;
	}

	$lower = strtolower($secret);
	foreach (['change-me', 'changeme', 'password', 'installer-token', 'replace-'] as $placeholder) {
		if (str_contains($lower, $placeholder)) {
			return false;
		}
	}

	return true;
}

/**
 * Match an IP against an exact address or an IPv4/IPv6 CIDR.
 */
function filehostInstallerIpMatchesRule(string $ip, string $rule): bool
{
	if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
		return false;
	}

	$rule = trim($rule);
	if ($rule === '') {
		return false;
	}
	if (!str_contains($rule, '/')) {
		if (filter_var($rule, FILTER_VALIDATE_IP) === false) {
			return false;
		}
		return @inet_pton($ip) === @inet_pton($rule);
	}

	[$network, $prefixRaw] = array_pad(explode('/', $rule, 2), 2, '');
	if (filter_var($network, FILTER_VALIDATE_IP) === false || !ctype_digit($prefixRaw)) {
		return false;
	}

	$ipPacked = @inet_pton($ip);
	$networkPacked = @inet_pton($network);
	if ($ipPacked === false || $networkPacked === false || strlen($ipPacked) !== strlen($networkPacked)) {
		return false;
	}

	$maxBits = strlen($ipPacked) * 8;
	$prefix = (int) $prefixRaw;
	if ($prefix < 0 || $prefix > $maxBits) {
		return false;
	}

	$fullBytes = intdiv($prefix, 8);
	$remainingBits = $prefix % 8;
	if ($fullBytes > 0
		&& substr($ipPacked, 0, $fullBytes) !== substr($networkPacked, 0, $fullBytes)) {
		return false;
	}
	if ($remainingBits === 0) {
		return true;
	}

	$mask = (0xff << (8 - $remainingBits)) & 0xff;
	return (ord($ipPacked[$fullBytes]) & $mask) === (ord($networkPacked[$fullBytes]) & $mask);
}

/**
 * Loopback is always available; every other peer must be explicitly allowlisted.
 */
function filehostInstallerIpIsAllowed(string $ip, string $allowlist): bool
{
	if (in_array($ip, ['127.0.0.1', '::1'], true)) {
		return true;
	}

	foreach (explode(',', $allowlist) as $rule) {
		if (filehostInstallerIpMatchesRule($ip, $rule)) {
			return true;
		}
	}
	return false;
}

/**
 * Executable/server-active formats refused by a fresh installation.
 */
function filehostInstallerDefaultBlockedExtensions(): array
{
	return [
		'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
		'exe', 'bat', 'cmd', 'sh', 'bash', 'cgi', 'pl', 'py', 'rb',
		'asp', 'aspx', 'jsp', 'jspx', 'cfm', 'htaccess', 'htpasswd',
		'html', 'htm', 'xhtml', 'svg', 'shtml', 'xml',
	];
}
