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
}
