<?php
/**
 * Minimal Markdown → HTML (Faza 7 · pt 9).
 *
 * Just enough for admin-authored copy on the premium page: headings, paragraphs, lists,
 * emphasis, inline code, links and rules. No dependency, matching the project's no-Composer
 * rule, and deliberately a subset — this is not a CommonMark implementation.
 *
 * Safety: the source is escaped **first** and only the markup this class itself emits is ever
 * unescaped, so Markdown text cannot inject HTML. Link targets are restricted to http(s) and
 * site-relative paths, so no `javascript:` URL can be smuggled through. That matters because
 * the plan descriptions end up on a public page.
 *
 * An admin who wants real HTML sets the plan's format to `html` instead, which is passed
 * through untouched — the same trust already extended to other admin-authored settings.
 */
final class Markdown
{
	public static function render(string $md): string
	{
		$lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $md));
		$out = [];
		$listType = null;
		$para = [];

		$closeList = function () use (&$out, &$listType) {
			if ($listType !== null) {
				$out[] = '</' . $listType . '>';
				$listType = null;
			}
		};
		$closePara = function () use (&$out, &$para) {
			if ($para) {
				$out[] = '<p>' . self::inline(implode(' ', $para)) . '</p>';
				$para = [];
			}
		};

		foreach ($lines as $line) {
			$trimmed = trim($line);

			if ($trimmed === '') {
				$closePara();
				$closeList();
				continue;
			}
			if (preg_match('/^(#{1,4})\s+(.*)$/', $trimmed, $m)) {
				$closePara();
				$closeList();
				$level = strlen($m[1]) + 1; // h1 belongs to the page, not to a plan's copy
				$out[] = "<h{$level}>" . self::inline($m[2]) . "</h{$level}>";
				continue;
			}
			if (preg_match('/^(-{3,}|\*{3,})$/', $trimmed)) {
				$closePara();
				$closeList();
				$out[] = '<hr>';
				continue;
			}
			if (preg_match('/^[-*+]\s+(.*)$/', $trimmed, $m)) {
				$closePara();
				if ($listType !== 'ul') {
					$closeList();
					$out[] = '<ul>';
					$listType = 'ul';
				}
				$out[] = '<li>' . self::inline($m[1]) . '</li>';
				continue;
			}
			if (preg_match('/^\d+[.)]\s+(.*)$/', $trimmed, $m)) {
				$closePara();
				if ($listType !== 'ol') {
					$closeList();
					$out[] = '<ol>';
					$listType = 'ol';
				}
				$out[] = '<li>' . self::inline($m[1]) . '</li>';
				continue;
			}

			$closeList();
			$para[] = $trimmed;
		}

		$closePara();
		$closeList();

		return implode("\n", $out);
	}

	/** Inline spans, applied to already-escaped text. */
	private static function inline(string $s): string
	{
		$s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
		$s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s);
		$s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s);
		$s = preg_replace('/(^|[^*])\*([^*\n]+)\*/', '$1<em>$2</em>', $s);
		// http(s) and site-relative targets only.
		$s = preg_replace(
			'/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/',
			'<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>',
			$s
		);
		$s = preg_replace('/\[([^\]]+)\]\((\/(?!\/)[^)\s]*)\)/', '<a href="$2">$1</a>', $s);
		return $s;
	}
}
