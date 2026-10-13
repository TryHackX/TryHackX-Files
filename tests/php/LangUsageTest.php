<?php

use PHPUnit\Framework\TestCase;

/**
 * Every translation key must be used by something. Unused keys are 4 lines each (one per
 * language) of text nobody sees and nobody keeps in sync; the last audit found 43 of them,
 * mostly from a settings screen that had been replaced.
 */
final class LangUsageTest extends TestCase
{
	/** @return string concatenated source of everything that can reference a key */
	private function codeBase(): string
	{
		$blob = '';
		foreach (['public', 'src', 'scripts'] as $dir) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator(PROJECT_ROOT . '/' . $dir, FilesystemIterator::SKIP_DOTS)
			);
			foreach ($iterator as $file) {
				$path = str_replace('\\', '/', $file->getPathname());
				if (!$file->isFile()
					|| str_contains($path, '/src/lang/')
					|| str_contains($path, '/fontawesome/')
					|| !in_array($file->getExtension(), ['php', 'js', 'py'], true)) {
					continue;
				}
				$blob .= "\n" . file_get_contents($path);
			}
		}
		return $blob . "\n" . (string) file_get_contents(PROJECT_ROOT . '/upload_server.py');
	}

	public function testEveryTranslationKeyIsReferencedSomewhere(): void
	{
		$english = require PROJECT_ROOT . '/src/lang/en.php';
		$code = $this->codeBase();

		// Keys are also built at run time ("premium.err_" . $code, 'panel.flt.share_' + state).
		// A string that ends in "." or "_" and is then concatenated counts as a prefix.
		$prefixes = [];
		preg_match_all('/[\'"`]([a-z0-9_]+(?:\.[a-z0-9_]+)*[._])[\'"`]\s*[+.]/i', $code, $concat);
		preg_match_all('/[\'"`]([a-z0-9_]+(?:\.[a-z0-9_]+)*[._])\$\{/i', $code, $template);
		foreach (array_merge($concat[1], $template[1]) as $prefix) {
			$prefixes[$prefix] = true;
		}

		$unused = [];
		foreach (array_keys($english) as $key) {
			if (str_contains($code, (string) $key)) {
				continue;
			}
			foreach (array_keys($prefixes) as $prefix) {
				if (str_starts_with((string) $key, (string) $prefix)) {
					continue 2;
				}
			}
			$unused[] = $key;
		}

		$this->assertSame(
			[],
			$unused,
			"These translation keys are not referenced by any PHP, JS or Python file.\n"
			. 'Delete them from all four src/lang files, or use them: ' . implode(', ', $unused)
		);
	}
}
