<?php

final class InputLimitsTest extends RepoTestCase
{
	private array $original = [];

	protected function setUp(): void
	{
		foreach ([
			'input_username_min' => InputLimits::USERNAME_MIN,
			'input_username_max' => InputLimits::USERNAME_MAX,
			'input_email_max' => InputLimits::EMAIL_MAX,
			'input_password_min' => InputLimits::ACCOUNT_PASSWORD_MIN,
			'input_password_max' => InputLimits::ACCOUNT_PASSWORD_MAX,
		] as $key => $default) {
			$this->original[$key] = Database::getSetting($key, $default);
		}
	}

	protected function tearDown(): void
	{
		foreach ($this->original as $key => $value) {
			Database::setSetting($key, $value);
		}
		Database::invalidateSettingsCache();
	}

	public function testConfiguredIdentityBoundsAreEnforced(): void
	{
		Database::setSetting('input_username_min', 5);
		Database::setSetting('input_username_max', 10);
		Database::setSetting('input_email_max', 64);
		Database::setSetting('input_password_min', 12);
		Database::setSetting('input_password_max', 20);

		$this->assertFalse(InputLimits::validUsername('four'));
		$this->assertTrue(InputLimits::validUsername('five5'));
		$this->assertFalse(InputLimits::validUsername('elevenchars'));
		$this->assertFalse(InputLimits::validEmail(str_repeat('a', 60) . '@example.test'));
		$this->assertSame('length', PasswordPolicy::violation('Short1!'));
		$this->assertSame('maximum', PasswordPolicy::violation('VeryLongPassword123!xx'));
		$this->assertNull(PasswordPolicy::violation('Configured1!'));
	}

	public function testLoweringUsernameLimitDoesNotLockOutAnExistingAccount(): void
	{
		$this->truncate('api_keys', 'upload_tokens', 'download_tokens', 'recovery_tokens', 'users');
		Database::setSetting('input_username_max', 5);
		$password = 'ExistingPass1!';
		Database::getInstance()->prepare(
			"INSERT INTO `" . Database::table('users') . "`
			 (`username`, `email`, `password_hash`, `role`, `is_active`, `created_at`)
			 VALUES ('legacy-user', 'legacy-user@example.test', ?, 'user', 1, ?)"
		)->execute([password_hash($password, PASSWORD_DEFAULT), time()]);

		$this->assertTrue(Database::loginUser('legacy-user', $password)['success']);
		$this->assertFalse(InputLimits::validUsername('legacy-user'));
	}
}
