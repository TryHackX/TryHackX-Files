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

	/** Free-text labels keep markup-breaking characters out of the database. */
	public function testCleanLabelStripsWhatEndsAnAttributeOrAScriptString(): void
	{
		$this->assertSame('Holiday 2026', InputLimits::cleanLabel('  Holiday 2026  ', 255));
		$this->assertSame('x_ data-fh-click=_evil()', InputLimits::cleanLabel('x" data-fh-click="evil()', 255));
		$this->assertSame('_img src=x onerror=evil()_', InputLimits::cleanLabel('<img src=x onerror=evil()>', 255));
		$this->assertSame('back_slash', InputLimits::cleanLabel('back\\slash', 255));
		$this->assertSame('a b', InputLimits::cleanLabel("a\x00\r\nb", 255));
		$this->assertSame('', InputLimits::cleanLabel(" \x07 ", 255));
		// apostrophes and non-ASCII text are ordinary names and survive
		$this->assertSame("Ola's zdjęcia 日本", InputLimits::cleanLabel("Ola's zdjęcia 日本", 255));
		// invalid UTF-8 is repaired rather than stored
		$this->assertTrue(mb_check_encoding(InputLimits::cleanLabel("bad \xC3\x28 bytes", 255), 'UTF-8'));
		// length is counted in characters, after cleaning
		$this->assertSame(5, mb_strlen(InputLimits::cleanLabel('ąęśćż-more', 5)));
		$this->assertSame('ab', InputLimits::cleanLabel('ab  cd', 3));
	}
}
