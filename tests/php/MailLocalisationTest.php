<?php

require_once dirname(__DIR__, 2) . '/src/includes/api/ReportController.php';

/**
 * Mail goes out in the recipient's language — footer included — and the notification mails
 * sent from cron are rendered at all, which needs the translator to be loaded there.
 */
final class MailLocalisationTest extends RepoTestCase
{
	protected function setUp(): void
	{
		$this->truncate('mail_outbox');
		Database::setSetting('email_from', 'noreply@example.test');
		Database::invalidateSettingsCache();
	}

	protected function tearDown(): void
	{
		$this->truncate('mail_outbox');
		Database::setSetting('default_language', 'pl');
		Database::invalidateSettingsCache();
	}

	private function lastBody(): string
	{
		return (string) Database::getInstance()->query(
			"SELECT `html_body` FROM `" . Database::table('mail_outbox') . "` ORDER BY `id` DESC LIMIT 1"
		)->fetchColumn();
	}

	public function testFooterFollowsTheRecipientsLanguage(): void
	{
		$this->assertTrue(MailService::send('reader@example.test', 'Hello', 'Body', null, 'en'));
		$body = $this->lastBody();
		$this->assertStringContainsString('Message sent by', $body);
		$this->assertStringNotContainsString('Wiadomość wysłana', $body);

		$this->assertTrue(MailService::send('czytelnik@example.test', 'Cześć', 'Treść', null, 'pl'));
		$this->assertStringContainsString('Wiadomość wysłana przez', $this->lastBody());
	}

	public function testReporterMailsAreTranslatedIntoTheDefaultLanguage(): void
	{
		Database::setSetting('default_language', 'en');
		Database::invalidateSettingsCache();
		$params = ['id' => 7, 'file' => 'C++ notes.pdf', 'title' => 'Copyright', 'reason' => 'Licensed', 'app' => 'FileHostTest'];
		$this->assertSame('Update on your report #7', ReportController::reporterText('report.mail_subject_rejected', $params));
		$body = ReportController::reporterText('report.mail_body_rejected', $params);
		$this->assertStringContainsString('C++ notes.pdf', $body);
		$this->assertStringContainsString("Reason: Licensed", $body);
		$this->assertStringNotContainsString('zgłoszenia', $body);
	}

	public function testTheCronBootstrapCanRenderNotificationMail(): void
	{
		// scripts/cleanup.php loads the repositories without config.php; Notifications::mail()
		// must still find the translator there, or every cron mail is silently dropped.
		$root = str_replace('\\', '/', dirname(__DIR__, 2));
		// Single quotes only: escapeshellarg() on Windows drops double quotes.
		$code = "define('PROJECT_ROOT', " . var_export($root, true) . ');'
			. "define('UPLOADS_DIR', sys_get_temp_dir()); define('DATA_DIR', sys_get_temp_dir());"
			. 'require ' . var_export($root . '/src/includes/Database.php', true) . ';'
			. "echo class_exists('Lang', false) ? 'lang' : 'none';";
		$output = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code));
		$this->assertSame('lang', trim((string) $output));
	}
}
