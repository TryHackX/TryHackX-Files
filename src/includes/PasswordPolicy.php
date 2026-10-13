<?php

final class PasswordPolicy
{
	public const MAX_LENGTH = InputLimits::ACCOUNT_PASSWORD_MAX;

	public static function violation(string $password): ?string
	{
		if (strlen($password) < InputLimits::accountPasswordMin()) {
			return 'length';
		}
		if (strlen($password) > InputLimits::accountPasswordMax()) {
			return 'maximum';
		}
		if (!preg_match('/[A-Z]/', $password)) {
			return 'uppercase';
		}
		if (!preg_match('/[0-9]/', $password)) {
			return 'digit';
		}
		if (!preg_match('/[^a-zA-Z0-9]/', $password)) {
			return 'special';
		}
		return null;
	}

	public static function isValid(string $password): bool
	{
		return self::violation($password) === null;
	}

	/**
	 * The message for a violation() result, or null when there is none. One mapping for every
	 * place a password is set — sign-up, reset, and an administrator setting one for someone.
	 */
	public static function errorMessage(?string $violation): ?string
	{
		return match ($violation) {
			null => null,
			'length' => __('api.pass_min_configured', ['min' => InputLimits::accountPasswordMin()]),
			'maximum' => __('api.password_too_long'),
			'uppercase' => __('api.password_uppercase'),
			'digit' => __('api.password_digit'),
			'special' => __('api.password_special'),
			default => __('api.pass_weak'),
		};
	}
}
