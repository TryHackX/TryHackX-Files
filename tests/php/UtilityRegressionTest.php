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

}
