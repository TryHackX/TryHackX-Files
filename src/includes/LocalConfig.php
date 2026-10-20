<?php

/**
 * A storage root as config/config.local.php names it (UPLOADS_PATH, DATA_PATH): an absolute path
 * (`/mnt/storage/data`, `D:/files`, `//nas/share`) is used as-is, a relative one resolves against
 * the project root, and an unset or empty one is $default. The web app, the cron scripts and the
 * workers all resolve it here, so they agree on where the uploads, the settings cache and its
 * lock live — a worker reading another directory's cache never sees the panel's changes.
 */
function filehostResolveStoragePath(?string $configured, string $default, string $projectRoot): string
{
	$path = rtrim(str_replace('\\', '/', trim((string) $configured)), '/');
	if ($path === '') {
		return $default;
	}
	return preg_match('#^(//|/|[A-Za-z]:/)#', $path) === 1 ? $path : $projectRoot . '/' . ltrim($path, '/');
}

/**
 * Write (or replace) a string constant in config/config.local.php.
 *
 * The upload location lives there — not in the DB — because it is the one file that both PHP
 * and the Python upload server read, and PHP needs it before any DB connection exists. Editing
 * it from the panel therefore means rewriting that constant.
 */
function filehostWriteLocalConstant(string $name, string $value, ?string $file = null): bool
{
	$file ??= PROJECT_ROOT . '/config/config.local.php';
	$current = @file_get_contents($file);
	if ($current === false) {
		return false;
	}
	// var_export() writes a PHP string literal that is exact for any input (quotes, backslashes,
	// newlines). The replacement goes through a callback: as a preg_replace() replacement string
	// a `$0` or `\1` in the value was expanded, splicing the old define into the new one and
	// leaving config.local.php unparsable — every page then failed until it was fixed by hand.
	$line = "define('" . $name . "', " . var_export($value, true) . ');';
	$pattern = "/^[ \t]*define\s*\(\s*['\"]" . preg_quote($name, '/') . "['\"].*$/m";
	$updated = preg_match($pattern, $current)
		? preg_replace_callback($pattern, static fn(): string => $line, $current, 1)
		: rtrim($current, "\r\n") . "\n" . $line . "\n";
	if (!is_string($updated)) {
		return false;
	}
	// Publish atomically where the directory allows it, so a failed write cannot leave half a
	// configuration behind; otherwise (config/ not writable, only the file) write in place.
	$temp = $file . '.tmp.' . bin2hex(random_bytes(6));
	if (@file_put_contents($temp, $updated, LOCK_EX) === strlen($updated)) {
		$mode = @fileperms($file);
		if ($mode !== false) {
			@chmod($temp, $mode & 0777);
		}
		if (@rename($temp, $file)) {
			return true;
		}
	}
	@unlink($temp);
	return @file_put_contents($file, $updated, LOCK_EX) !== false;
}
