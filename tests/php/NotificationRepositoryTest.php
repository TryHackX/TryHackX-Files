<?php

/**
 * Notifications: the two behaviours everything else depends on — stacking (a hundred downloads
 * are one line that counts up, and reading it closes the stack) and the three-way preference
 * decision (operator veto → account choice → default).
 */
final class NotificationRepositoryTest extends RepoTestCase
{
	private int $userId;

	protected function setUp(): void
	{
		$this->truncate('mail_outbox', 'notifications', 'notification_prefs');
		// Back to the suite's baseline (all types on, no mail): tests below change the defaults
		// on purpose, and a veto left behind would silently disable the next one.
		applyTestNotificationDefaults();
		$this->userId = $this->makeUser();
	}

	private function makeUser(): int
	{
		$name = 'notifuser' . bin2hex(random_bytes(3));
		$res = Database::registerUser($name, $name . '@example.com', 'Str0ng!pass');
		$this->assertTrue($res['success'], $res['error'] ?? '');
		return (int) Database::loginUser($name, 'Str0ng!pass')['user']['id'];
	}

	public function testRepeatsOfTheSameSubjectStackIntoOneRow(): void
	{
		for ($i = 0; $i < 5; $i++) {
			NotificationRepository::push($this->userId, 'file.downloaded', 'a.png', '', [], 'file.downloaded:a');
		}

		$res = NotificationRepository::browse($this->userId, 10);
		$this->assertCount(1, $res['items']);
		$this->assertSame(5, (int) $res['items'][0]['count']);
		$this->assertSame(1, $res['unread']);
	}

	public function testDifferentSubjectsDoNotStack(): void
	{
		NotificationRepository::push($this->userId, 'file.downloaded', 'a.png', '', [], 'file.downloaded:a');
		NotificationRepository::push($this->userId, 'file.downloaded', 'b.png', '', [], 'file.downloaded:b');

		$this->assertSame(2, NotificationRepository::browse($this->userId, 10)['total']);
	}

	/** Reading is what closes a stack: the next event is news again, not a bigger old number. */
	public function testReadingClosesTheStack(): void
	{
		NotificationRepository::push($this->userId, 'file.downloaded', 'a.png', '', [], 'file.downloaded:a');
		NotificationRepository::markAllRead($this->userId);
		NotificationRepository::push($this->userId, 'file.downloaded', 'a.png', '', [], 'file.downloaded:a');

		$res = NotificationRepository::browse($this->userId, 10);
		$this->assertSame(2, $res['total']);
		$this->assertSame(1, $res['unread']);
		$this->assertSame(1, (int) $res['items'][0]['count']);
	}

	public function testEmptyGroupKeyNeverStacks(): void
	{
		NotificationRepository::push($this->userId, 'plan.granted', 'Pro');
		NotificationRepository::push($this->userId, 'plan.granted', 'Pro');

		$this->assertSame(2, NotificationRepository::browse($this->userId, 10)['total']);
	}

	/** A sweep that removed forty files says so once, with the count, not forty times. */
	public function testBulkBumpRaisesTheCountInOneCall(): void
	{
		NotificationRepository::push($this->userId, 'file.deleted', 'old.zip', '', [], 'file.deleted:retention', 40);

		$items = NotificationRepository::browse($this->userId, 10)['items'];
		$this->assertCount(1, $items);
		$this->assertSame(40, (int) $items[0]['count']);
	}

	public function testSilentRowsAreWrittenAlreadyRead(): void
	{
		NotificationRepository::push($this->userId, 'plan.expiring', 'Pro', '', [], 'plan.expiring:1', 1, true);

		$res = NotificationRepository::browse($this->userId, 10);
		$this->assertSame(1, $res['total']);
		$this->assertSame(0, $res['unread'], 'a silent row must not light up the bell');
	}

	public function testExistsSeesBothReadAndUnreadRows(): void
	{
		$this->assertFalse(NotificationRepository::exists($this->userId, 'plan.expiring:1'));
		NotificationRepository::push($this->userId, 'plan.expiring', 'Pro', '', [], 'plan.expiring:1');
		$this->assertTrue(NotificationRepository::exists($this->userId, 'plan.expiring:1'));

		NotificationRepository::markAllRead($this->userId);
		$this->assertTrue(
			NotificationRepository::exists($this->userId, 'plan.expiring:1'),
			'a warning already given stays given after it is read'
		);
	}

	public function testMarkReadAndDeleteAreScopedToTheOwner(): void
	{
		$other = $this->makeUser();
		NotificationRepository::push($this->userId, 'plan.granted', 'Pro');
		$mine = NotificationRepository::browse($this->userId, 10)['items'][0]['id'];

		$this->assertSame(0, NotificationRepository::markRead($other, [(int) $mine]));
		$this->assertSame(1, NotificationRepository::browse($this->userId, 10)['unread']);

		$this->assertSame(0, NotificationRepository::delete($other, [(int) $mine]));
		$this->assertSame(1, NotificationRepository::browse($this->userId, 10)['total']);
	}

	public function testClearReadOnlyKeepsWhatHasNotBeenDealtWith(): void
	{
		NotificationRepository::push($this->userId, 'plan.granted', 'Pro');
		NotificationRepository::markAllRead($this->userId);
		NotificationRepository::push($this->userId, 'plan.revoked', 'Pro');

		$this->assertSame(1, NotificationRepository::clear($this->userId, true));
		$res = NotificationRepository::browse($this->userId, 10);
		$this->assertSame(1, $res['total']);
		$this->assertSame(1, $res['unread']);
	}

	/* ---- preferences ---- */

	public function testUnknownTypeIsNeverAllowed(): void
	{
		$this->assertFalse(Notifications::allows($this->userId, 'nope.nothing'));
	}

	public function testAccountPreferenceOverridesTheDefault(): void
	{
		$this->assertTrue(Notifications::allows($this->userId, 'file.downloaded'));

		Notifications::saveUserPrefs($this->userId, ['file.downloaded' => ['app' => false]]);
		$this->assertFalse(Notifications::allows($this->userId, 'file.downloaded'));
	}

	/** The operator's veto is not a default — no account preference can lift it. */
	public function testOperatorVetoBeatsTheAccountPreference(): void
	{
		Notifications::saveUserPrefs($this->userId, ['file.downloaded' => ['app' => true]]);
		Notifications::saveDefaults(['file.downloaded' => ['enabled' => false, 'app' => true]]);

		$this->assertFalse(Notifications::allows($this->userId, 'file.downloaded'));
		$this->assertSame(
			[],
			array_filter(Notifications::userMatrix($this->userId), fn($r) => $r['type'] === 'file.downloaded'),
			'a vetoed type is not offered as a choice either'
		);
	}

	public function testMailIsRefusedForTypesThatAreNotMailable(): void
	{
		Notifications::saveUserPrefs($this->userId, ['file.downloaded' => ['app' => true, 'mail' => true]]);
		$this->assertFalse(
			Notifications::allows($this->userId, 'file.downloaded', 'mail'),
			'a repeating event must not become e-mail however it is configured'
		);
	}

	public function testSendWritesNothingForAMutedType(): void
	{
		Notifications::saveUserPrefs($this->userId, ['plan.granted' => ['app' => false, 'mail' => false]]);
		Notifications::send($this->userId, 'plan.granted', ['subject' => 'Pro']);

		$this->assertSame(0, NotificationRepository::browse($this->userId, 10)['total']);
	}

	public function testChannelRestrictionPreventsAnAppOnlyBroadcastFromQueuingMail(): void
	{
		Notifications::saveDefaults([
			'system.announcement' => ['enabled' => true, 'app' => true, 'mail' => true],
		]);
		$this->assertTrue(Notifications::send($this->userId, 'system.announcement', [
			'subject' => 'App only',
			'channels' => ['app'],
		]));

		$this->assertSame(1, NotificationRepository::browse($this->userId, 10)['total']);
		$this->assertSame(
			0,
			(int) Database::getInstance()->query(
				"SELECT COUNT(*) FROM `" . Database::table('mail_outbox') . "`"
			)->fetchColumn()
		);
	}

	/**
	 * Embeds ship off.
	 *
	 * An image hotlinked into a forum post fires on every page view by every reader, so this
	 * type has to be something an owner opts into rather than something they discover by
	 * finding four thousand unread lines.
	 */
	public function testEmbedNotificationsAreOffUntilAskedFor(): void
	{
		// The suite's baseline turns every type on, so ask the catalogue rather than the
		// installation: what matters here is what a fresh install would do.
		$this->assertFalse(Notifications::TYPES['file.embedded']['app']);
		$this->assertFalse(Notifications::TYPES['file.embedded']['mailable']);

		Notifications::saveDefaults([]);   // nothing enabled anywhere
		$this->assertFalse(Notifications::allows($this->userId, 'file.embedded'));

		applyTestNotificationDefaults();
		Notifications::saveUserPrefs($this->userId, ['file.embedded' => ['app' => true]]);
		$this->assertTrue(Notifications::allows($this->userId, 'file.embedded'));
		$this->assertFalse(
			Notifications::allows($this->userId, 'file.embedded', 'mail'),
			'an event that repeats per page view must never become e-mail'
		);
	}

	/** A warning is said once, however many times the sweep re-notices the condition. */
	public function testOnceSuppressesRepeats(): void
	{
		for ($i = 0; $i < 3; $i++) {
			Notifications::send($this->userId, 'plan.expiring', [
				'subject' => 'Pro',
				'group' => 'plan.expiring:12345',
				'once' => true,
			]);
		}

		$res = NotificationRepository::browse($this->userId, 10);
		$this->assertSame(1, $res['total']);
		$this->assertSame(1, (int) $res['items'][0]['count']);
	}

	/**
	 * The shape a row is rendered into. The sentence itself is not asserted: this suite runs
	 * without the i18n layer (bootstrap stubs `__()` to return the key), which is deliberate —
	 * these are DB tests. What matters here is that every field the list needs comes back.
	 */
	public function testRenderProducesTheFieldsTheListNeeds(): void
	{
		NotificationRepository::push($this->userId, 'file.downloaded', 'raport.pdf', '/x', [], 'g', 3);
		$row = NotificationRepository::browse($this->userId, 1)['items'][0];
		$out = Notifications::render($row);

		$this->assertSame('file.downloaded', $out['type']);
		$this->assertSame('fa-download', $out['icon']);
		$this->assertSame('/x', $out['link']);
		$this->assertSame(3, $out['count']);
		$this->assertTrue($out['unread']);
		$this->assertIsInt($out['at']);
	}
}
