<?php

/**
 * Canonical bounds for identity and general browser input.
 *
 * HTML maxlength attributes are only a usability hint. These limits are also enforced at
 * repository/controller boundaries so a direct API caller cannot bypass them.
 */
final class InputLimits
{
	/** Defaults exposed in Settings -> Security. */
	public const USERNAME_MIN = 3;
	public const USERNAME_MAX = 32;
	public const EMAIL_MAX = 254;
	public const PASSWORD_MAX = 1024;
	public const ACCOUNT_PASSWORD_MIN = 8;
	public const ACCOUNT_PASSWORD_MAX = 72;

	/** Storage/protocol ceilings which an administrator cannot raise. */
	public const HARD_USERNAME_MAX = 50;
	public const HARD_EMAIL_MAX = 254;
	public const HARD_PASSWORD_MAX = 1024;
	public const RECOVERY_INPUT_MAX = 254;
	public const SHORT_TEXT_MAX = 255;
	public const LONG_TEXT_MAX = 10_000;
	public const HTML_MAX = 100_000;
	public const API_BODY_MAX = 16 * 1024 * 1024;
	/** The site name (installer, Settings -> General). */
	public const APP_NAME_MAX = 50;

	private static function configuredInt(string $key, int $default, int $minimum, int $maximum): int
	{
		$value = $default;
		if (class_exists('Database', false)) {
			try {
				$value = (int) Database::getSetting($key, $default);
			} catch (Throwable $e) {
				// Installer/bootstrap paths intentionally work without a ready database.
				$value = $default;
			}
		}
		return max($minimum, min($maximum, $value));
	}

	public static function usernameMin(): int
	{
		return self::configuredInt('input_username_min', self::USERNAME_MIN, 1, self::HARD_USERNAME_MAX);
	}

	public static function usernameMax(): int
	{
		return max(
			self::usernameMin(),
			self::configuredInt('input_username_max', self::USERNAME_MAX, 1, self::HARD_USERNAME_MAX)
		);
	}

	public static function emailMax(): int
	{
		return self::configuredInt('input_email_max', self::EMAIL_MAX, 64, self::HARD_EMAIL_MAX);
	}

	public static function accountPasswordMin(): int
	{
		return self::configuredInt('input_password_min', self::ACCOUNT_PASSWORD_MIN, 8, self::ACCOUNT_PASSWORD_MAX);
	}

	public static function accountPasswordMax(): int
	{
		return max(
			self::accountPasswordMin(),
			self::configuredInt(
				'input_password_max',
				self::ACCOUNT_PASSWORD_MAX,
				self::ACCOUNT_PASSWORD_MIN,
				self::ACCOUNT_PASSWORD_MAX
			)
		);
	}

	public static function recoveryInputMax(): int
	{
		return max(self::usernameMax(), self::emailMax());
	}

	public static function validUsername(string $username): bool
	{
		$minimum = self::usernameMin();
		$maximum = self::usernameMax();
		return preg_match('/^[A-Za-z0-9_.-]{' . $minimum . ',' . $maximum . '}$/D', $username) === 1;
	}

	public static function validEmail(string $email): bool
	{
		return strlen($email) <= self::emailMax()
			&& filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
	}

	public static function fits(string $value, int $max): bool
	{
		return mb_strlen($value, 'UTF-8') <= $max;
	}

	/**
	 * A short free-text label (a collection name, an ad's name or alt text) as it may be stored.
	 *
	 * Control characters become spaces, and the characters that end an HTML attribute or a quoted
	 * script string (`"`, `<`, `>`, `\`) become `_` — the same stand-in the upload server uses for file
	 * names. The panel escapes on output as well; this keeps hostile text out of the database so a
	 * single missed escape somewhere downstream has nothing to work with.
	 */
	public static function cleanLabel(string $value, int $max): string
	{
		$value = mb_scrub($value, 'UTF-8');
		$value = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value) ?? '';
		$value = str_replace(['"', '<', '>', '\\'], '_', $value);
		$value = trim($value);
		return mb_strlen($value, 'UTF-8') > $max ? rtrim(mb_substr($value, 0, $max, 'UTF-8')) : $value;
	}

	/**
	 * The site name as it may be stored and shown: one line of plain text. It goes into every
	 * page's <title> and header and into mail subjects and bodies, so `<` and `>`, control
	 * characters and the bidirectional overrides that can disguise a name are dropped, runs of
	 * whitespace become one space, and the result is cut to APP_NAME_MAX characters. Quotes and
	 * ampersands stay: they are ordinary in a name, and every page escapes it on output.
	 * Returns '' when nothing usable is left; the caller decides the fallback.
	 */
	public static function appName(string $value): string
	{
		$value = mb_scrub($value, 'UTF-8');
		$value = preg_replace('/[\p{Cc}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}<>]+/u', ' ', $value) ?? '';
		$value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
		return rtrim(mb_substr($value, 0, self::APP_NAME_MAX, 'UTF-8'));
	}

	/**
	 * Every query, form and cookie field this application reads is a single string. PHP turns
	 * `name[]=x` into an array instead, which string functions refuse with a TypeError (an
	 * uncaught 500 on a public page) and a cast turns into "Array" with a warning — either way
	 * a line in the error log for anyone with a crawler. Such values are dropped before
	 * anything reads them, so every reader sees the field as absent.
	 */
	public static function discardStructuredRequestInput(): void
	{
		foreach ($_GET as $key => $value) {
			if (!is_string($value)) {
				unset($_GET[$key]);
			}
		}
		foreach ($_POST as $key => $value) {
			if (!is_string($value)) {
				unset($_POST[$key]);
			}
		}
		foreach ($_COOKIE as $key => $value) {
			if (!is_string($value)) {
				unset($_COOKIE[$key]);
			}
		}
	}
}
