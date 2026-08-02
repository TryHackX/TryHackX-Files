<?php

use PHPUnit\Framework\TestCase;

final class InlineEventPolicyTest extends TestCase
{
	public function testRenderedSourceDoesNotDefineInlineEventAttributes(): void
	{
		$roots = [
			PROJECT_ROOT . '/public',
			PROJECT_ROOT . '/src',
		];
		$violations = [];
		$pattern = '/(?<!data-fh-)\bon(?:click|change|input|submit|load|error|focus|blur|keydown|keyup|mouseover|mouseout)\s*=/i';

		foreach ($roots as $root) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
			);
			foreach ($iterator as $file) {
				if (!$file->isFile() || !in_array($file->getExtension(), ['php', 'html'], true)) {
					continue;
				}
				$contents = file_get_contents($file->getPathname());
				if ($contents !== false && preg_match($pattern, $contents)) {
					$violations[] = str_replace(PROJECT_ROOT . DIRECTORY_SEPARATOR, '', $file->getPathname());
				}
			}
		}

		$this->assertSame([], $violations, 'Inline event attributes violate strict CSP.');
	}
}
