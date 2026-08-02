<?php

/**
 * ReportRepository: abuse-report submission + moderation, cascade removal, and the
 * per-IP / per-email report throttle.
 */
final class ReportRepositoryTest extends RepoTestCase
{
	protected function setUp(): void
	{
		$this->truncate('reports', 'files', 'security_events');
	}

	private function reportData(string $email = 'r@e.pl'): array
	{
		return ['name' => 'Reporter', 'email' => $email, 'entity' => 'Holder', 'org' => 'Org', 'title' => 'Copyright', 'info' => 'details'];
	}

	public function testAddRequiresExistingFile(): void
	{
		$this->assertFalse(Database::addReport('ghost_file', $this->reportData())['success']);

		$this->insertFile('rep_file');
		$this->assertTrue(Database::addReport('rep_file', $this->reportData())['success']);

		$listed = Database::getReportedFiles(1, 20);
		$this->assertSame(1, $listed['total']);
	}

	public function testDetailsRejectAndCascade(): void
	{
		$this->insertFile('rep_file');
		$rid = (int) Database::addReport('rep_file', $this->reportData())['report_id'];

		$this->assertSame('Copyright', Database::getReportDetails($rid)['report_title']);

		// Deleting the file closes its reports.
		$this->assertSame(1, Database::deleteReportsByFileIds(['rep_file']));
		$this->assertSame(0, Database::getReportedFiles(1, 20)['total']);
	}

	public function testRejectRemovesReport(): void
	{
		$this->insertFile('rep_file');
		$rid = (int) Database::addReport('rep_file', $this->reportData())['report_id'];
		$this->assertTrue(Database::rejectReport($rid));
		$this->assertNull(Database::getReportDetails($rid));
	}

	public function testSpamThreshold(): void
	{
		$this->insertFile('rep_file');
		for ($i = 0; $i < 5; $i++) {
			Database::addReport('rep_file', $this->reportData('spammer@e.pl'));
		}
		$this->assertFalse(Database::checkSpam('spammer@e.pl')); // 5 is not yet > 5
		Database::addReport('rep_file', $this->reportData('spammer@e.pl'));
		$this->assertTrue(Database::checkSpam('spammer@e.pl'));  // 6 > 5
		$this->assertFalse(Database::checkSpam('innocent@e.pl'));
	}

	public function testReportCountResetsAfterVerification(): void
	{
		$this->insertFile('rep_file');
		$ip = getClientIP(); // addReport stamps this IP
		Database::addReport('rep_file', $this->reportData());
		Database::addReport('rep_file', $this->reportData());
		$this->assertSame(2, Database::getReportCount($ip, 60));

		// A passed CAPTCHA marks the IP verified, resetting the rolling count to 0.
		Database::markReportVerified($ip);
		$this->assertSame(0, Database::getReportCount($ip, 60));
	}
}
