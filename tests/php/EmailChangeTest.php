<?php

/**
 * Changing the address on an account, confirmed from both ends.
 *
 * The old flow asked only the new address, which meant a session someone else was holding
 * could move an account away and the real owner would never hear about it. Now the address on
 * file has to agree first — that message is also the warning — and only then does the new one
 * get its own link. When it lands, both mailboxes are told what happened.
 */
final class EmailChangeTest extends RepoTestCase
{
	private int $userId = 0;
	private string $oldEmail = '';
	private string $newEmail = '';

	protected function setUp(): void
	{
		$this->truncate('mail_outbox', 'email_reservations');
		Database::setSetting('email_from', 'noreply@example.test');
		Database::setSetting('email_change_token_lifetime', '15');

		$name = 'change' . bin2hex(random_bytes(4));
		$this->oldEmail = $name . '@old.test';
		$this->newEmail = $name . '@new.test';
		$registered = Database::registerUser($name, $this->oldEmail, 'Str0ng!pass');
		$this->assertTrue($registered['success'], (string) ($registered['error'] ?? ''));
		$this->userId = (int) Database::getUserByEmailOrUsername($name)['id'];
		Database::getInstance()->exec(
			"UPDATE `" . Database::table('users') . "` SET `is_active` = 1, `last_email_change_at` = 0
			 WHERE `id` = " . $this->userId
		);
	}

	/** @return list<array<string,mixed>> */
	private function queued(): array
	{
		return Database::getInstance()->query(
			"SELECT `to_email`, `subject`, `html_body` FROM `" . Database::table('mail_outbox') . "`
			 ORDER BY `id`"
		)->fetchAll(PDO::FETCH_ASSOC);
	}

	/** The confirmation link the application put in the most recent message. */
	private function tokenFromLastMail(): string
	{
		$rows = $this->queued();
		$this->assertNotSame([], $rows, 'nothing was queued');
		$body = (string) end($rows)['html_body'];
		// The outbox escapes the rendered body, so the query separator arrives as `&amp;`.
		$this->assertSame(
			1,
			preg_match('/user_verify_email_change&(?:amp;)?token=([a-f0-9]{64})/', $body, $match),
			'no confirmation link in the message'
		);
		return $match[1];
	}

	private function stage(): ?string
	{
		$value = Database::getInstance()->query(
			"SELECT `email_change_stage` FROM `" . Database::table('users') . "`
			 WHERE `id` = " . $this->userId
		)->fetchColumn();
		return $value === false || $value === null ? null : (string) $value;
	}

	public function testTheFirstLinkGoesToTheAddressOnFileAndChangesNothing(): void
	{
		$result = Database::requestEmailChange($this->userId, $this->newEmail);
		$this->assertTrue($result['success'], (string) ($result['error'] ?? ''));

		$queued = $this->queued();
		$this->assertCount(1, $queued);
		$this->assertSame(
			$this->oldEmail,
			$queued[0]['to_email'],
			'the warning has to reach the mailbox that still owns the account'
		);
		// The translator is stubbed in this harness, so the copy is not assertable — but the
		// link is, and it has to arrive as a real anchor rather than escaped markup.
		$this->assertStringContainsString(
			"<a href='http://localhost/api.php?action=user_verify_email_change&amp;token=",
			(string) $queued[0]['html_body'],
			'the approval link must be clickable'
		);

		$this->assertSame('old', $this->stage());
		$this->assertSame(
			$this->oldEmail,
			Database::getUserById($this->userId)['email'],
			'nothing moves on a request alone'
		);
	}

	public function testApprovingFromTheOldAddressOnlyIssuesASecondLink(): void
	{
		Database::requestEmailChange($this->userId, $this->newEmail);
		$firstToken = $this->tokenFromLastMail();

		$advanced = Database::confirmEmailChange($firstToken);
		$this->assertTrue($advanced['success'], (string) ($advanced['error'] ?? ''));
		$this->assertSame('new', $advanced['stage']);
		$this->assertSame('new', $this->stage());
		$this->assertSame(
			$this->oldEmail,
			Database::getUserById($this->userId)['email'],
			'the address does not move halfway through'
		);

		$queued = $this->queued();
		$this->assertCount(2, $queued);
		$this->assertSame($this->newEmail, $queued[1]['to_email'], 'stage two goes to the new mailbox');

		// The first link is spent: replaying it must not walk the flow backwards or forwards.
		$replayed = Database::confirmEmailChange($firstToken);
		$this->assertFalse($replayed['success']);
		$this->assertSame('new', $this->stage(), 'a replay leaves the flow where it was');
	}

	public function testTheSecondLinkCompletesTheChangeAndTellsBothAddresses(): void
	{
		Database::requestEmailChange($this->userId, $this->newEmail);
		Database::confirmEmailChange($this->tokenFromLastMail());
		$secondToken = $this->tokenFromLastMail();

		$done = Database::confirmEmailChange($secondToken);
		$this->assertTrue($done['success'], (string) ($done['error'] ?? ''));
		$this->assertSame('done', $done['stage']);
		$this->assertSame($this->newEmail, $done['email']);
		$this->assertSame($this->oldEmail, $done['previous_email']);

		$user = Database::getUserById($this->userId);
		$this->assertSame($this->newEmail, $user['email']);
		$this->assertNull($this->stage());
		$this->assertNull($user['pending_email']);
		$this->assertNull($user['email_change_token']);

		// Two more messages after the two links, one to each address. This harness stubs the
		// translator, so the copy itself is not assertable here — who receives it is.
		$queued = $this->queued();
		$this->assertCount(4, $queued);
		$announced = array_slice($queued, 2);
		$this->assertSame(
			[$this->oldEmail, $this->newEmail],
			array_column($announced, 'to_email'),
			'the mailbox losing the account is told first, and the new one too'
		);
		$this->assertSame(
			$announced[0]['subject'],
			$announced[1]['subject'],
			'both get the same announcement'
		);
		$this->assertNotSame(
			$queued[0]['subject'],
			$announced[0]['subject'],
			'and it is not the approval request again'
		);
	}

	public function testCompletingTheChangeSignsEveryCredentialOut(): void
	{
		$before = (int) Database::getUserById($this->userId)['session_version'];
		RememberTokenRepository::issue($this->userId, 86400, '203.0.113.9', 'Laptop/1.0');

		Database::requestEmailChange($this->userId, $this->newEmail);
		Database::confirmEmailChange($this->tokenFromLastMail());
		Database::confirmEmailChange($this->tokenFromLastMail());

		$this->assertGreaterThan(
			$before,
			(int) Database::getUserById($this->userId)['session_version'],
			'moving the address must not leave old sessions valid'
		);
		$this->assertSame([], RememberTokenRepository::devices($this->userId));
	}

	public function testAnExpiredFirstLinkLeavesTheAccountAlone(): void
	{
		Database::requestEmailChange($this->userId, $this->newEmail);
		$token = $this->tokenFromLastMail();
		Database::getInstance()->exec(
			"UPDATE `" . Database::table('users') . "` SET `email_change_expires_at` = " . (time() - 1)
			. " WHERE `id` = " . $this->userId
		);

		$result = Database::confirmEmailChange($token);

		$this->assertFalse($result['success']);
		$this->assertSame($this->oldEmail, Database::getUserById($this->userId)['email']);
	}

	public function testRequestingTheAddressAlreadyInUseOnThisAccountIsRejected(): void
	{
		$result = Database::requestEmailChange($this->userId, strtoupper($this->oldEmail));

		$this->assertFalse($result['success']);
		$this->assertSame([], $this->queued(), 'and nothing is sent about it');
		$this->assertNull($this->stage());
	}
}
