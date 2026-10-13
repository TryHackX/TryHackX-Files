<?php
/**
 * The "HTML block" checkout of a premium plan: what it may contain.
 *
 * The plans page runs under a strict Content-Security-Policy (script-src 'self', form-action
 * 'self', frame-src 'self'), so a pasted provider button — an inline or third-party script, a
 * form that posts to the provider, an iframe — is silently dead: it renders as an inert box.
 * Rather than let an operator save something that cannot work, the block is limited to what the
 * page can honour: text, links, images and basic layout. A payment *button* is a link (the
 * "Link" checkout type) or the built-in checkout.
 *
 * This is a usability check, not the security boundary — the CSP is that — but it also keeps
 * obviously executable markup out of a stored page fragment.
 */
final class CheckoutHtml
{
	/** Elements that are scripts, forms or frames (or can carry them) and so cannot work here. */
	private const BLOCKED_TAGS = [
		'script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input',
		'button', 'select', 'textarea', 'base', 'meta', 'link', 'style', 'svg', 'math',
		'template', 'noscript', 'video', 'audio', 'source', 'track', 'canvas', 'dialog',
	];

	/**
	 * Why this snippet is refused, as a short human-readable token (`<script>`, an event-handler attribute,
	 * `javascript: URL`), or null when it is acceptable.
	 */
	public static function problem(string $html): ?string
	{
		$tags = implode('|', self::BLOCKED_TAGS);
		if (preg_match('/<\s*\/?\s*(' . $tags . ')(?![a-z0-9-])/i', $html, $match) === 1) {
			return '<' . strtolower($match[1]) . '>';
		}
		if (preg_match('/<[^>]*\son[a-z]+\s*=/i', $html) === 1) {
			return 'on…= attribute';
		}
		if (preg_match(
			'/(?:href|src|action|formaction|data|poster|xlink:href)\s*=\s*["\']?\s*(?:(?:javascript|vbscript)\s*:|data\s*:(?!\s*image\/(?:png|jpe?g|gif|webp)[;,]))/i',
			$html
		) === 1) {
			return 'javascript:/data: URL';
		}
		return null;
	}

	/**
	 * The snippet as the public plans page may print it. A block saved before this rule existed
	 * and that no longer passes is not printed at all — it could not work under the page's policy
	 * anyway, and half a form is worse than none.
	 */
	public static function render(string $html): string
	{
		return self::problem($html) === null ? $html : '';
	}
}
