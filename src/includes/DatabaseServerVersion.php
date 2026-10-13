<?php

declare(strict_types=1);

/**
 * Oldest database servers TryHackX Files can run on.
 *
 * The e-mail outbox claims rows with `SELECT ... FOR UPDATE SKIP LOCKED`, which MySQL has had
 * since 8.0.1 and MariaDB since 10.6. On an older server that statement is a syntax error, the
 * worker logs it and claims nothing, so every activation and password-reset message would sit in
 * the outbox forever while the rest of the site looked healthy. The installer therefore refuses
 * such a server up front instead of leaving the operator to find out from missing mail.
 */
const FILEHOST_MIN_MYSQL_VERSION = '8.0.1';
const FILEHOST_MIN_MARIADB_VERSION = '10.6.0';

/**
 * Why the server reported by `SELECT VERSION()` is too old, or null when it is acceptable.
 *
 * A string that cannot be recognised is accepted: forks and proxies report all kinds of version
 * strings, and refusing what we cannot identify would lock out working installations.
 */
function filehostDatabaseServerVersionProblem(string $reported): ?string
{
	$reported = trim($reported);
	$isMariaDb = stripos($reported, 'mariadb') !== false;

	if ($isMariaDb) {
		// Older client libraries prefix MariaDB's real version with the replication-compatible
		// "5.5.5-" (for example "5.5.5-10.5.23-MariaDB-0+deb11u1").
		$candidate = (string) preg_replace('/^5\.5\.5-(?=\d)/', '', $reported);
	} else {
		$candidate = $reported;
	}

	if (!preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?/', $candidate, $match)) {
		return null;
	}
	// A string without a patch number ("8.0.mysql_aurora.3.04") says nothing about the patch
	// level, so assume the newest one rather than rejecting the whole series.
	$version = $match[1] . '.' . $match[2] . '.' . ($match[3] ?? '999');

	if ($isMariaDb) {
		if (version_compare($version, FILEHOST_MIN_MARIADB_VERSION, '<')) {
			return 'MariaDB ' . $version . ' is too old - MariaDB 10.6 or newer is required '
				. '(the e-mail outbox needs SELECT ... FOR UPDATE SKIP LOCKED).';
		}
		return null;
	}

	if (version_compare($version, FILEHOST_MIN_MYSQL_VERSION, '<')) {
		return 'MySQL ' . $version . ' is too old - MySQL 8.0.1 or newer is required '
			. '(the e-mail outbox needs SELECT ... FOR UPDATE SKIP LOCKED).';
	}
	return null;
}
