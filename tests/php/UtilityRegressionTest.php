<?php

require_once PROJECT_ROOT . '/src/includes/Lang.php';
require_once PROJECT_ROOT . '/src/includes/Markdown.php';

final class UtilityRegressionTest extends RepoTestCase
{
	public function testBundledLanguagesHaveIdenticalKeySets(): void
	{
		$sets = [];
		foreach (['en', 'es', 'pl', 'zh'] as $code) {
			$strings = require PROJECT_ROOT . "/src/lang/{$code}.php";
			$this->assertIsArray($strings);
			$keys = array_keys($strings);
			sort($keys);
			$sets[$code] = $keys;
		}

		foreach (['es', 'pl', 'zh'] as $code) {
			$this->assertSame($sets['en'], $sets[$code], "Translation keys differ for {$code}");
		}
	}

	public function testAcceptLanguageHonoursQualityAndExplicitRejection(): void
	{
		$previous = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null;
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en;q=0.2, es;q=0, pl;q=0.9';
		try {
			$method = new ReflectionMethod(Lang::class, 'fromAcceptLanguage');
			$this->assertSame('pl', $method->invoke(null));
		} finally {
			if ($previous === null) {
				unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
			} else {
				$_SERVER['HTTP_ACCEPT_LANGUAGE'] = $previous;
			}
		}
	}

	public function testMarkdownRejectsProtocolRelativeExternalLinks(): void
	{
		$html = Markdown::render('[external](//evil.example/path)');
		$this->assertStringNotContainsString('<a ', $html);
		$this->assertStringContainsString('//evil.example/path', $html);
	}

	public function testExplicitLocaleTranslationDoesNotDependOnRequestLanguage(): void
	{
		$this->assertSame('Sign in', Lang::translateFor('en', 'nav.login'));
		$this->assertSame('Zaloguj się', Lang::translateFor('pl', 'nav.login'));
		$this->assertSame(
			'Unknown translation key',
			Lang::translateFor('en', 'Unknown translation key')
		);
	}

	/**
	 * The panel rewrites UPLOADS_PATH in config.local.php. Whatever an administrator types
	 * must come out as that literal string: a `$0` used to be expanded by preg_replace() and
	 * leave a file that no longer parsed, taking every page down with it.
	 */
	public function testLocalConfigWriterStoresAnyValueLiterally(): void
	{
		require_once PROJECT_ROOT . '/src/includes/LocalConfig.php';
		$file = DATA_DIR . DIRECTORY_SEPARATOR . 'config-writer-' . bin2hex(random_bytes(4)) . '.php';
		file_put_contents($file, "<?php\ndefine('DB_NAME', 'files');\ndefine('UPLOADS_PATH', '/old/place');\n");
		$tricky = "/mnt/x\$0 \\1 it's \"quoted\" \${1}";
		try {
			$this->assertTrue(filehostWriteLocalConstant('UPLOADS_PATH', $tricky, $file));
			$this->assertTrue(filehostWriteLocalConstant('NEW_CONSTANT', 'appended', $file));

			$probe = sprintf(
				'require %s; echo json_encode([UPLOADS_PATH, DB_NAME, NEW_CONSTANT]);',
				var_export($file, true)
			);
			$output = [];
			$status = 1;
			exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($probe), $output, $status);
			$this->assertSame(0, $status, 'the rewritten file still parses');
			$this->assertSame([$tricky, 'files', 'appended'], json_decode(implode('', $output), true));
			$this->assertSame(1, substr_count((string) file_get_contents($file), "'UPLOADS_PATH'"));
		} finally {
			@unlink($file);
		}
	}

	/** PHP 8.5 deprecates curl_close(); production runs 8.5, CI does not yet. */
	public function testNoDeprecatedCurlCloseCalls(): void
	{
		$hits = [];
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PROJECT_ROOT . '/src'));
		foreach ($files as $item) {
			if ($item->isFile() && str_ends_with($item->getFilename(), '.php')
				&& preg_match('/\bcurl_close\s*\(/', (string) file_get_contents($item->getPathname()))) {
				$hits[] = $item->getPathname();
			}
		}
		$this->assertSame([], $hits);
	}

	/**
	 * Every notification type is listed in the preferences and the notification list with a
	 * name and a description, and rendered with a message — in every shipped language. A
	 * missing key shows up raw (`notif.desc.file.removed` did).
	 */
	public function testEveryNotificationTypeHasItsNameDescriptionAndMessage(): void
	{
		$missing = [];
		foreach (['en', 'pl', 'es', 'zh'] as $code) {
			$strings = require PROJECT_ROOT . "/src/lang/{$code}.php";
			foreach (array_keys(Notifications::TYPES) as $type) {
				foreach (['notif.type.', 'notif.desc.', 'notif.msg.'] as $prefix) {
					if (trim((string) ($strings[$prefix . $type] ?? '')) === '') {
						$missing[] = $code . ': ' . $prefix . $type;
					}
				}
			}
		}
		$this->assertSame([], $missing);
	}
}
