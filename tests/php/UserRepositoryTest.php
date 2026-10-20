<?php

/**
 * UserRepository: registration, login, password/availability checks, admin edit, TOTP, delete.
 * Registration runs in the default 'auto' activation mode (no settings row → account active,
 * no email sent), so these stay DB-only.
 */
require_once PROJECT_ROOT . '/src/includes/Totp.php';

final class UserRepositoryTest extends RepoTestCase
{
	protected function setUp(): void
	{
		$this->truncate(
			'api_keys',
			'upload_tokens',
			'download_tokens',
			'recovery_tokens',
			'totp_recovery_codes',
			'users'
		);
		Database::invalidateSettingsCache();
	}

	private function register(string $u = 'alice', string $e = 'alice@example.pl', string $p = 'Passw0rd!'): array
	{
		return Database::registerUser($u, $e, $p);
	}

	private function authVersion(int $userId): int
	{
		$stmt = Database::getInstance()->prepare(
			"SELECT `session_version` FROM `" . Database::table('users') . "` WHERE `id` = ?"
		);
		$stmt->execute([$userId]);
		return (int) $stmt->fetchColumn();
	}

	private function seedBearerCredentials(int $userId): void
	{
		$pdo = Database::getInstance();
		$now = time();
		$pdo->prepare("INSERT INTO `" . Database::table('api_keys') . "`
			(`user_id`, `key_hash`, `key_prefix`, `label`, `created_at`) VALUES (?, ?, 'fh_test', 'test', ?)")
			->execute([$userId, hash('sha256', 'key-' . $userId), $now]);
		$pdo->prepare("INSERT INTO `" . Database::table('upload_tokens') . "`
			(`token`, `ip_address`, `user_id`, `created_at`) VALUES (?, '127.0.0.1', ?, ?)")
			->execute([str_pad('u' . $userId, 64, 'u'), $userId, $now]);
		$pdo->prepare("INSERT INTO `" . Database::table('download_tokens') . "`
			(`token`, `file_id`, `ip_address`, `user_id`, `created_at`) VALUES (?, 'file', '127.0.0.1', ?, ?)")
			->execute([str_pad('d' . $userId, 64, 'd'), $userId, $now]);
		$pdo->prepare("INSERT INTO `" . Database::table('recovery_tokens') . "`
			(`token`, `user_id`, `created_at`, `expires_at`) VALUES (?, ?, ?, ?)")
			->execute([str_pad('r' . $userId, 64, 'r'), $userId, $now, $now + 900]);
	}

	public function testRegisterThenLogin(): void
	{
		$res = $this->register();
		$this->assertTrue($res['success']);

		$this->assertFalse(Database::isUsernameAvailable('alice'));
		$this->assertFalse(Database::isEmailAvailable('alice@example.pl'));
		$this->assertTrue(Database::isUsernameAvailable('bob'));

		$login = Database::loginUser('alice', 'Passw0rd!');
		$this->assertTrue($login['success']);
		$this->assertSame('user', $login['user']['role']);
		$this->assertSame(1, $login['user']['session_version']);

		$this->assertFalse(Database::loginUser('alice', 'wrong')['success']);
	}

	public function testRegisterValidatesInput(): void
	{
		$this->assertFalse($this->register('al', 'x@y.pl')['success']);            // username too short
		$this->assertFalse($this->register(str_repeat('a', 33), 'x@y.pl')['success']); // username too long
		$this->assertFalse($this->register('validname', 'not-an-email')['success']); // bad email
		$this->assertFalse($this->register('validname', 'v@e.pl', 'short')['success']); // weak password
		$this->assertFalse($this->register('validname', 'v@e.pl', str_repeat('A', 1025))['success']);
	}

	public function testDuplicateRegistrationRejected(): void
	{
		$this->assertTrue($this->register()['success']);
		$this->assertFalse($this->register('alice', 'other@example.pl')['success']); // username taken
		$this->assertFalse($this->register('alice2', 'alice@example.pl')['success']); // email taken
	}

	public function testDuplicateBootstrapAdminUsesTypedDomainException(): void
	{
		$this->assertTrue(
			Database::createAdmin('owner', 'OwnerPass1!', 'owner@example.pl')['success']
		);
		$this->expectException(AccountAlreadyExistsException::class);
		Database::createAdmin('owner', 'OtherPass1!', 'other@example.pl');
	}

	public function testPasswordUpdateAndVerify(): void
	{
		$this->register();
		$uid = (int) Database::getUserByEmailOrUsername('alice')['id'];
		$before = $this->authVersion($uid);
		$this->seedBearerCredentials($uid);

		$this->assertTrue(Database::verifyUserPassword($uid, 'Passw0rd!'));
		$this->assertFalse(Database::verifyUserPassword($uid, str_repeat('x', 1025)));
		$this->assertFalse(Database::loginUser('alice', str_repeat('x', 1025))['success']);
		$this->assertTrue(Database::updateUserPassword($uid, 'NewPass9@'));
		$this->assertTrue(Database::verifyUserPassword($uid, 'NewPass9@'));
		$this->assertFalse(Database::verifyUserPassword($uid, 'Passw0rd!'));
		$this->assertSame($before + 1, $this->authVersion($uid));
		foreach (['api_keys', 'upload_tokens', 'download_tokens', 'recovery_tokens'] as $table) {
			$stmt = Database::getInstance()->prepare(
				"SELECT COUNT(*) FROM `" . Database::table($table) . "` WHERE `user_id` = ?"
			);
			$stmt->execute([$uid]);
			$this->assertSame(0, (int) $stmt->fetchColumn(), $table . ' should be revoked');
		}
	}

	public function testAdminUpdateRole(): void
	{
		$this->register();
		$uid = (int) Database::getUserByEmailOrUsername('alice')['id'];
		$before = $this->authVersion($uid);

		$this->assertTrue(Database::adminUpdateUser($uid, ['role' => 'moderator'])['success']);
		$this->assertSame('moderator', Database::getUserForAdmin($uid)['role']);
		$this->assertSame($before + 1, $this->authVersion($uid));
		$this->assertFalse(Database::adminUpdateUser($uid, ['role' => 'wizard'])['success']); // invalid role
	}

	public function testModeratorSystemGroupIsAutomaticAndClearedWithRole(): void
	{
		$this->register();
		$uid = (int) Database::getUserByEmailOrUsername('alice')['id'];
		$unrelated = Database::saveGroup(null, [
			'name' => 'Unrelated profile ' . bin2hex(random_bytes(3)),
			'permissions' => ['moderation.reports.view'],
		]);
		$moderator = GroupRepository::getBySlug('moderator');
		$this->assertNotNull($moderator);

		// Legacy/client-supplied profile ids cannot substitute the role-bound system group.
		$assigned = Database::adminUpdateUser($uid, [
			'role' => 'moderator',
			'staff_group_id' => (int) $unrelated['id'],
		]);
		$this->assertTrue($assigned['success']);
		$this->assertSame(
			(int) $moderator['id'],
			(int) Database::getUserForAdmin($uid)['staff_group_id']
		);
		$this->assertTrue(Database::setUserGroup($uid, (int) $unrelated['id']));
		$listed = Database::getAllUsers(1, 50);
		$row = array_values(array_filter(
			$listed['users'],
			fn(array $candidate): bool => (int) $candidate['id'] === $uid
		))[0] ?? null;
		$this->assertNotNull($row);
		$this->assertSame(
			Database::getGroupById((int) $unrelated['id'])['name'],
			$row['group_name']
		);
		$this->assertSame($moderator['name'], $row['staff_group_name']);

		$this->assertTrue(Database::adminUpdateUser($uid, ['role' => 'user'])['success']);
		$this->assertSame('user', Database::getUserForAdmin($uid)['role']);
		$this->assertNull(Database::getUserForAdmin($uid)['staff_group_id']);
		$this->assertSame(
			(int) $unrelated['id'],
			(int) Database::getUserGroup($uid)['id']
		);
	}

	/** The Users tab's search: names and e-mails taken literally, addresses matched exactly. */
	public function testAdminListSearchesNamesEmailsAndAddresses(): void
	{
		$this->truncate('files');
		$this->assertTrue($this->register('alice', 'alice@example.pl')['success']);
		$this->assertTrue($this->register('bob_smith', 'bob@example.org')['success']);
		$this->assertTrue($this->register('carol', 'carol@example.pl')['success']);
		$pdo = Database::getInstance();
		$id = static function (string $name) use ($pdo): int {
			$stmt = $pdo->prepare("SELECT `id` FROM `" . Database::table('users') . "` WHERE `username` = ?");
			$stmt->execute([$name]);
			return (int) $stmt->fetchColumn();
		};
		$pdo->prepare("UPDATE `" . Database::table('users') . "` SET `registered_ip` = '203.0.113.9' WHERE `id` = ?")
			->execute([$id('bob_smith')]);
		$this->insertFile('searchfile0001', $id('carol'), ['uploaded_ip' => '2001:db8::7']);

		$names = static function (array $result): array {
			$list = array_map(static fn(array $user): string => (string) $user['username'], $result['users']);
			sort($list);
			return $list;
		};
		$this->assertSame(['alice'], $names(UserRepository::all(1, 20, 'created_at', 'desc', [], 'ALI')));
		$this->assertSame(['alice', 'carol'], $names(UserRepository::all(1, 20, 'created_at', 'desc', [], 'example.pl')));
		$this->assertSame(2, UserRepository::all(1, 20, 'created_at', 'desc', [], 'example.pl')['total'], 'the total counts the matches');
		$this->assertSame(['bob_smith'], $names(UserRepository::all(1, 20, 'created_at', 'desc', [], 'b_s')), '"_" is a character, not a wildcard');
		$this->assertSame([], $names(UserRepository::all(1, 20, 'created_at', 'desc', [], '%')), '"%" is a character, not a wildcard');
		$this->assertSame(['bob_smith'], $names(UserRepository::all(1, 20, 'created_at', 'desc', [], '203.0.113.9')), 'registered from the address');
		$this->assertSame(['carol'], $names(UserRepository::all(1, 20, 'created_at', 'desc', [], '2001:db8::7')), 'uploaded from the address');
		$this->assertSame(3, UserRepository::all(1, 20, 'created_at', 'desc', [], '   ')['total'], 'a blank search lists everyone');
	}

	public function testDeactivationRevokesCredentialsAndAdvancesSessionVersion(): void
	{
		$this->register();
		$uid = (int) Database::getUserByEmailOrUsername('alice')['id'];
		$before = $this->authVersion($uid);
		$this->seedBearerCredentials($uid);

		$this->assertTrue(Database::updateUserStatus($uid, 0));
		$this->assertSame($before + 1, $this->authVersion($uid));
		$this->assertSame(0, (int) Database::getUserById($uid)['is_active']);
		$this->assertNull(Database::resolveApiKey('key-' . $uid));
	}

	public function testLastActiveAdministratorCannotBeDeactivatedOrDemoted(): void
	{
		$this->register('onlyadmin', 'onlyadmin@example.pl');
		$uid = (int) Database::getUserByEmailOrUsername('onlyadmin')['id'];
		$pdo = Database::getInstance();
		$pdo->prepare(
			"UPDATE `" . Database::table('users') . "` SET `role` = 'admin', `is_active` = 1 WHERE `id` = ?"
		)->execute([$uid]);

		$this->assertFalse(Database::updateUserStatus($uid, 0));
		$this->assertFalse(Database::adminUpdateUser($uid, ['role' => 'moderator'])['success']);
		$this->assertSame('admin', Database::getUserForAdmin($uid)['role']);
		$this->assertSame(1, (int) Database::getUserForAdmin($uid)['is_active']);
	}

	public function testInstallOwnerRemainsActiveAdminEvenWhenAnotherAdminExists(): void
	{
		$this->assertTrue(
			Database::createAdmin('owner', 'OwnerPass1!', 'owner@example.pl')['success']
		);
		$ownerId = (int) Database::getUserByEmailOrUsername('owner')['id'];
		$this->register('secondadmin', 'secondadmin@example.pl');
		$secondId = (int) Database::getUserByEmailOrUsername('secondadmin')['id'];
		$this->assertTrue(Database::adminUpdateUser($secondId, ['role' => 'admin'])['success']);

		$this->assertFalse(Database::updateUserStatus($ownerId, 0));
		$demotion = Database::adminUpdateUser($ownerId, ['role' => 'moderator']);
		$this->assertFalse($demotion['success']);
		$this->assertSame('admin', Database::getUserForAdmin($ownerId)['role']);
		$this->assertSame(1, (int) Database::getUserForAdmin($ownerId)['is_active']);
	}

	public function testTotpLifecycle(): void
	{
		$this->register();
		$uid = (int) Database::getUserByEmailOrUsername('alice')['id'];
		$secret = 'JBSWY3DPEHPK3PXP';

		$this->assertFalse(Database::getTotpState($uid)['enabled']);
		$this->assertTrue(Database::setTotpSecret($uid, $secret));
		$stmt = Database::getInstance()->prepare(
			"SELECT `totp_secret` FROM `" . Database::table('users') . "` WHERE `id` = ?"
		);
		$stmt->execute([$uid]);
		$stored = (string) $stmt->fetchColumn();
		$this->assertNotSame($secret, $stored);
		$this->assertTrue(Crypto::isEncrypted($stored));
		$this->assertSame($secret, Database::getTotpState($uid)['secret']);

		$this->assertTrue(Database::setTotpEnabled($uid, true));
		$this->assertTrue(Database::getTotpState($uid)['enabled']);

		Database::setTotpEnabled($uid, false); // disabling also clears the secret
		$this->assertNull(Database::getTotpState($uid)['secret']);
	}

	public function testTotpEnableAndRecoveryCodesCommitTogether(): void
	{
		$this->register();
		$uid = (int) Database::getUserByEmailOrUsername('alice')['id'];
		$before = $this->authVersion($uid);

		$this->assertTrue(Database::setTotpSecret($uid, 'JBSWY3DPEHPK3PXP'));
		$codes = Database::enableTotpWithRecoveryCodes($uid);

		$this->assertCount(RecoveryCodeRepository::BATCH_SIZE, $codes);
		$this->assertTrue(Database::getTotpState($uid)['enabled']);
		$this->assertSame(RecoveryCodeRepository::BATCH_SIZE, Database::countRecoveryCodes($uid));
		$this->assertSame($before + 1, $this->authVersion($uid));

		$this->assertTrue(Database::setTotpEnabled($uid, false));
		$this->assertSame(0, Database::countRecoveryCodes($uid));
	}

	/**
	 * 2FA fails closed: an account with 2FA on whose secret cannot be read (key changed, data
	 * damaged) is reported as "enabled, unavailable" — never as "disabled", which used to let
	 * it sign in with the password alone.
	 */
	public function testUnreadableTotpSecretKeepsTwoFactorEnabled(): void
	{
		$this->register();
		$uid = (int) Database::getUserByEmailOrUsername('alice')['id'];
		$this->assertTrue(Database::setTotpSecret($uid, 'JBSWY3DPEHPK3PXP'));
		$this->assertTrue(Database::setTotpEnabled($uid, true));

		// Ciphertext that no key opens: what a changed APP_SECRET_KEY looks like.
		Database::getInstance()->prepare(
			"UPDATE `" . Database::table('users') . "` SET `totp_secret` = ? WHERE `id` = ?"
		)->execute(['enc:v1:' . base64_encode(random_bytes(48)), $uid]);

		$state = Database::getTotpState($uid);
		$this->assertTrue($state['enabled']);
		$this->assertTrue($state['unavailable']);
		$this->assertNull($state['secret']);

		// A pending (not yet enabled) setup with an unreadable secret just starts over.
		Database::getInstance()->prepare(
			"UPDATE `" . Database::table('users') . "` SET `totp_enabled` = 0 WHERE `id` = ?"
		)->execute([$uid]);
		$pending = Database::getTotpState($uid);
		$this->assertFalse($pending['enabled']);
		$this->assertNull($pending['secret']);
	}

	/** An accepted authenticator code cannot be used again within its drift window. */
	public function testTotpTimeStepIsAcceptedOnlyOnce(): void
	{
		$this->register();
		$uid = (int) Database::getUserByEmailOrUsername('alice')['id'];
		$secret = 'JBSWY3DPEHPK3PXP';
		$now = 1_700_000_000;
		$code = Totp::codeAt($secret, $now);
		$step = Totp::match($secret, $code, 1, $now);
		$this->assertSame(intdiv($now, Totp::PERIOD), $step);
		$this->assertSame($step, Totp::match($secret, $code, 1, $now + Totp::PERIOD), 'drift still matches');
		$this->assertNull(Totp::match($secret, '12345', 1, $now), 'five digits is not a code');

		$this->assertTrue(UserRepository::claimTotpCounter($uid, $step));
		$this->assertFalse(UserRepository::claimTotpCounter($uid, $step), 'replay of the same step');
		$this->assertFalse(UserRepository::claimTotpCounter($uid, $step - 1), 'or an older one');
		$this->assertTrue(UserRepository::claimTotpCounter($uid, $step + 1));
		$this->assertTrue(RecoveryCodeRepository::looksLikeCode('7kfd-q2wm'));
		$this->assertFalse(RecoveryCodeRepository::looksLikeCode('123456'));
	}

	/**
	 * Setting an administrator's password is taking that administrator over. Only the install
	 * owner may do it to another admin, nobody may do it to the owner, and anyone setting a
	 * password for someone is held to the full password policy.
	 */
	public function testOnlyTheOwnerMaySetAnotherAdministratorsPassword(): void
	{
		$this->assertTrue(Database::createAdmin('owner', 'OwnerPass1!', 'owner@example.pl')['success']);
		$ownerId = (int) Database::getUserByEmailOrUsername('owner')['id'];
		foreach (['second', 'third', 'plain'] as $name) {
			$this->register($name, $name . '@example.pl');
		}
		$second = (int) Database::getUserByEmailOrUsername('second')['id'];
		$third = (int) Database::getUserByEmailOrUsername('third')['id'];
		$plain = (int) Database::getUserByEmailOrUsername('plain')['id'];
		$this->assertTrue(Database::adminUpdateUser($second, ['role' => 'admin'], $ownerId)['success']);
		$this->assertTrue(Database::adminUpdateUser($third, ['role' => 'admin'], $ownerId)['success']);

		$takeover = Database::adminUpdateUser($ownerId, ['password' => 'Taken0ver!'], $second);
		$this->assertFalse($takeover['success']);
		$this->assertSame('api.admin_password_owner_only', $takeover['error']);
		$this->assertTrue(Database::verifyUserPassword($ownerId, 'OwnerPass1!'), 'the owner keeps the password');

		$this->assertFalse(Database::adminUpdateUser($third, ['password' => 'Taken0ver!'], $second)['success']);
		$this->assertTrue(Database::adminUpdateUser($third, ['password' => 'ByOwner1!'], $ownerId)['success']);
		$this->assertTrue(Database::adminUpdateUser($plain, ['password' => 'ByAdmin1!'], $second)['success']);
		$this->assertTrue(Database::adminUpdateUser($second, ['password' => 'MyOwn123!'], $second)['success']);

		$weak = Database::adminUpdateUser($plain, ['password' => 'nouppercase1!'], $ownerId);
		$this->assertFalse($weak['success'], 'the full policy applies to a password chosen for someone');
		$this->assertSame('api.password_uppercase', $weak['error']);
	}

	public function testIdForUsernameAndBannedAddressCannotBeTakenByAnEmailChange(): void
	{
		$this->register();
		$uid = (int) Database::getUserByEmailOrUsername('alice')['id'];
		$this->assertSame($uid, UserRepository::idForUsername('alice'));
		$this->assertSame(0, UserRepository::idForUsername('nobody-here'));

		$this->truncate('blacklists');
		$this->assertTrue(Database::addToBlacklist('email', 'banned@example.pl', null, 'test'));
		$change = Database::requestEmailChange($uid, 'banned@example.pl');
		$this->assertFalse($change['success']);
		$this->assertSame('api.email_blocked', $change['error']);
		$this->truncate('blacklists');
	}

	public function testDeleteUser(): void
	{
		// id 1 is the protected primary admin slot, so register a filler first (TRUNCATE in
		// setUp resets AUTO_INCREMENT to 1) and delete the second account.
		$this->register('primary', 'primary@example.pl');
		$this->register();
		$uid = (int) Database::getUserByEmailOrUsername('alice')['id'];
		$this->assertGreaterThan(1, $uid);
		$this->assertTrue(Database::deleteUser($uid));
		$this->assertNull(Database::getUserById($uid));
		$this->assertFalse(Database::deleteUser(1)); // primary admin is protected
	}
}
