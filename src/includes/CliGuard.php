<?php
/**
 * Keeps the maintenance scripts from running as root.
 *
 * config/ is readable only by the service account, so `sudo php scripts/...` is the obvious way
 * to run a maintenance script, and a cleanup line in root's crontab looks harmless. Whatever the
 * script then creates belongs to root, with the private modes the code uses (0700 directories,
 * 0600 files): a quarantine copy, a restored upload, a repaired manifest, a settings cache or the
 * mail worker's runtime snapshot. The upload service and PHP can no longer read or remove those
 * files, so the restored download fails, the cleanup's failed count never drains, and nothing
 * says why.
 *
 * The scripts therefore refuse root and print the command for the account that owns the
 * storage. `--allow-root` lets a deliberate root run through (its files then need a chown).
 * Platforms without POSIX user ids (Windows) are not affected.
 */
final class CliGuard
{
	/** The option that lets a deliberate root run through. */
	public const ALLOW_ROOT = '--allow-root';

	/** sysexits.h EX_NOPERM. */
	public const EXIT_REFUSED = 77;

	/**
	 * Stops the script when it runs as root without --allow-root.
	 *
	 * --allow-root is taken out of $_SERVER['argv'] either way, so the script's own option
	 * parsing never sees it. With $onlyWith only runs that pass that option are guarded, which
	 * keeps a read-only default mode available to root.
	 */
	public static function refuseRoot(?string $onlyWith = null): void
	{
		$euid = function_exists('posix_geteuid') ? posix_geteuid() : null;
		$arguments = array_values(array_map('strval', (array) ($_SERVER['argv'] ?? [])));
		[$arguments, $refusal] = self::evaluate(
			$arguments,
			$euid,
			$euid === 0 ? self::serviceAccount() : null,
			$onlyWith
		);
		$_SERVER['argv'] = $arguments;
		$_SERVER['argc'] = count($arguments);
		if ($refusal !== null) {
			fwrite(STDERR, $refusal);
			exit(self::EXIT_REFUSED);
		}
	}

	/**
	 * The decision for one invocation, without side effects so it can be tested with any user
	 * id: the arguments the script should parse (without --allow-root) and the refusal to
	 * print, or null when the script may run.
	 *
	 * @param list<string> $arguments argv, script path first
	 * @param ?int $euid the effective user id, null where POSIX ids do not exist
	 * @param ?string $account who owns the storage, null when unknown
	 * @return array{0: list<string>, 1: ?string}
	 */
	public static function evaluate(array $arguments, ?int $euid, ?string $account, ?string $onlyWith = null): array
	{
		$allowRoot = in_array(self::ALLOW_ROOT, $arguments, true);
		$arguments = array_values(array_filter(
			$arguments,
			static fn(string $argument): bool => $argument !== self::ALLOW_ROOT
		));
		if ($euid !== 0 || $allowRoot || ($onlyWith !== null && !in_array($onlyWith, $arguments, true))) {
			return [$arguments, null];
		}
		$script = $arguments[0] ?? 'the script';
		$user = $account !== null && $account !== '' && $account !== 'root' ? $account : '<service account>';
		$command = 'sudo -u ' . self::shellWord($user) . ' php '
			. implode(' ', array_map(static fn(string $argument): string => self::shellWord($argument), $arguments));
		return [$arguments, basename($script) . " refuses to run as root: the files it writes would belong to root,\n"
			. "and the upload service and PHP could no longer read or delete them. Run it as the\n"
			. "account that owns the storage instead:\n"
			. "  {$command}\n"
			. 'or add ' . self::ALLOW_ROOT . " and give what it creates back to that account with chown.\n"];
	}

	/**
	 * The account the services run as, told by who owns the storage: the uploads directory,
	 * then config/ (scripts/install-debian.sh hands both to the service account). A numeric
	 * `#uid` when the id has no name; null when only root or nobody could be found.
	 */
	public static function serviceAccount(): ?string
	{
		$root = dirname(__DIR__, 2);
		foreach ([defined('UPLOADS_DIR') ? (string) UPLOADS_DIR : '', $root . '/uploads', $root . '/config'] as $path) {
			$uid = $path === '' ? false : @fileowner($path);
			if ($uid === false || $uid === 0) {
				continue;
			}
			$entry = function_exists('posix_getpwuid') ? posix_getpwuid($uid) : false;
			return is_array($entry) && ($entry['name'] ?? '') !== '' ? (string) $entry['name'] : '#' . $uid;
		}
		return null;
	}

	/** One shell word: plain when it only has harmless characters, single-quoted otherwise. */
	private static function shellWord(string $word): string
	{
		return preg_match('~^[A-Za-z0-9_./=:,+@%-]+$~', $word) === 1
			? $word
			: "'" . str_replace("'", "'\\''", $word) . "'";
	}
}
