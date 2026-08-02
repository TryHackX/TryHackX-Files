<?php

require_once PROJECT_ROOT . '/src/includes/api/ReportController.php';

final class ReportControllerSecurityTest extends RepoTestCase
{
	private function accepts(string $url): bool
	{
		$method = new ReflectionMethod(ReportController::class, 'safeHttpUrl');
		$method->setAccessible(true);
		return (bool) $method->invoke(null, $url);
	}

	public function testReportLinksAllowOnlyAbsoluteHttpAndHttps(): void
	{
		$this->assertTrue($this->accepts(''));
		$this->assertTrue($this->accepts('https://example.test/evidence?id=1'));
		$this->assertTrue($this->accepts('http://example.test/path'));

		foreach ([
			'javascript:alert(1)',
			'data:text/html,<script>alert(1)</script>',
			'file:///etc/passwd',
			'//example.test/path',
			'/relative/path',
			'https://user:password@example.test/',
			"https://example.test/\nheader",
		] as $url) {
			$this->assertFalse($this->accepts($url), $url);
		}
	}
}
