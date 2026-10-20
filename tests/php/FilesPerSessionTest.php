<?php

require_once PROJECT_ROOT . '/src/includes/api/PremiumController.php';

/**
 * F-F3: "files per session" was three different numbers — "unlimited" on the plans page for a
 * group left at 0, the flat setting in the session line whatever the group, and what the
 * upload server applies: the group's cap, or the flat per-session setting where the group
 * leaves it at 0. All of them now ask GroupRepository::filesPerSession().
 */
final class FilesPerSessionTest extends RepoTestCase
{
	private int $cappedGroup = 0;
	private int $inheritingGroup = 0;
	private int $cappedUser = 0;
	private int $inheritingUser = 0;
	private ?array $guest = null;

	protected function setUp(): void
	{
		$this->truncate('upload_tokens', 'users');
		$this->cappedGroup = (int) Database::saveGroup(null, ['name' => 'Capped ' . bin2hex(random_bytes(3)), 'max_files_per_session' => 7])['id'];
		$this->inheritingGroup = (int) Database::saveGroup(null, ['name' => 'Inheriting ' . bin2hex(random_bytes(3)), 'max_files_per_session' => 0])['id'];
		$this->cappedUser = $this->user('capped', $this->cappedGroup);
		$this->inheritingUser = $this->user('inheriting', $this->inheritingGroup);
		$this->guest = GroupRepository::getBySlug('guest');
		$this->assertNotNull($this->guest);
		Database::setSetting('recaptcha_max_files_per_session_auth', '4');
		Database::setSetting('recaptcha_max_files_per_session_guest', '2');
		Database::invalidateSettingsCache();
	}

	protected function tearDown(): void
	{
		$this->setGuestCap((int) ($this->guest['max_files_per_session'] ?? 0));
		Database::setSetting('recaptcha_max_files_per_session_auth', '0');
		Database::setSetting('recaptcha_max_files_per_session_guest', '0');
		Database::invalidateSettingsCache();
		$this->truncate('upload_tokens', 'users');
		foreach ([$this->cappedGroup, $this->inheritingGroup] as $id) {
			if ($id > 0) {
				Database::deleteGroup($id);
			}
		}
	}

	private function user(string $name, int $groupId): int
	{
		$name .= bin2hex(random_bytes(3));
		$this->assertTrue(Database::registerUser($name, $name . '@example.test', 'Str0ng!pass')['success']);
		$id = (int) Database::getUserByEmailOrUsername($name)['id'];
		$this->assertTrue(Database::setUserGroup($id, $groupId));
		return $id;
	}

	private function setGuestCap(int $cap): void
	{
		Database::getInstance()->prepare(
			"UPDATE `" . Database::table('groups') . "` SET `max_files_per_session` = ? WHERE `id` = ?"
		)->execute([$cap, (int) $this->guest['id']]);
	}

	public function testTheGroupCapWinsAndZeroMeansTheFlatSetting(): void
	{
		$this->assertSame(7, GroupRepository::filesPerSession($this->cappedUser));
		$this->assertSame(4, GroupRepository::filesPerSession($this->inheritingUser), 'not unlimited');

		$this->setGuestCap(3);
		$this->assertSame(3, GroupRepository::filesPerSession(null));
		$this->setGuestCap(0);
		$this->assertSame(2, GroupRepository::filesPerSession(null));

		Database::setSetting('recaptcha_max_files_per_session_auth', '0');
		Database::invalidateSettingsCache();
		$this->assertSame(0, GroupRepository::filesPerSession($this->inheritingUser), 'no cap anywhere');
	}

	public function testTheSessionLineReportsTheCapOfTheTokensOwner(): void
	{
		$this->setGuestCap(0);
		$capped = (string) Database::createUploadToken('192.0.2.30', $this->cappedUser);
		$inheriting = (string) Database::createUploadToken('192.0.2.30', $this->inheritingUser);
		$guest = (string) Database::createUploadToken('192.0.2.30', null);
		Database::getInstance()->prepare(
			"UPDATE `" . Database::table('upload_tokens') . "` SET `files_count` = 2 WHERE `token` = ?"
		)->execute([$capped]);

		$info = Database::getTokenInfo($capped, '192.0.2.30');
		$this->assertSame(7, $info['files_limit']);
		$this->assertSame(5, $info['files_remaining']);
		$this->assertSame(4, Database::getTokenInfo($inheriting, '192.0.2.30')['files_limit']);
		$this->assertSame(2, Database::getTokenInfo($guest, '192.0.2.30')['files_limit']);
	}

	public function testThePlansPageShowsTheCapTheUploadServerApplies(): void
	{
		$files = static function (int $groupId): ?string {
			foreach (PremiumController::groupLimits($groupId) as $item) {
				if ($item['label'] === 'premium.limit_files') {
					return $item['value'];
				}
			}
			return null;
		};
		$this->assertSame('7', $files($this->cappedGroup));
		$this->assertSame('4', $files($this->inheritingGroup), 'a group left at 0 is not advertised as unlimited');
	}
}
