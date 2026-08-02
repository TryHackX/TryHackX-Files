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
}
