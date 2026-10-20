<?php
/**
 * The HTML side of checkout: what an operator's "HTML block" may contain, and the page that
 * hands a buyer over to the payment provider.
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

	/**
	 * Whether a provider's payment URL may be handed to the browser: an absolute https URL
	 * without credentials (plain http only on a loopback host, which can only ever point at the
	 * visitor's own machine — a local stand-in for the provider).
	 */
	public static function isHandOffUrl(string $url): bool
	{
		if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url) === 1
			|| filter_var($url, FILTER_VALIDATE_URL) === false) {
			return false;
		}
		$parts = parse_url($url);
		if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
			return false;
		}
		$scheme = strtolower((string) ($parts['scheme'] ?? ''));
		$host = strtolower(trim((string) $parts['host'], '[]'));
		return $scheme === 'https'
			|| ($scheme === 'http' && in_array($host, ['127.0.0.1', 'localhost', '::1'], true));
	}

	/**
	 * The page that takes the buyer to the provider.
	 *
	 * A form POST answered with a redirect to another origin is checked against the page's
	 * `form-action 'self'` at every hop, and Chromium refuses the hop to the provider: the buyer
	 * pressed "Buy" and nothing happened. A navigation started by a script is not governed by
	 * form-action, so the checkout answers 200 with this same-origin page, whose script file
	 * moves on at once — and whose link does the same by hand.
	 *
	 * @return string|null the HTML document, or null when the URL may not be handed over
	 */
	public static function handOffPage(string $url): ?string
	{
		if (!self::isHandOffUrl($url)) {
			return null;
		}
		$h = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$appUrl = defined('APP_URL') ? (string) APP_URL : '';
		$version = defined('APP_VERSION') ? (string) APP_VERSION : '';
		$appName = defined('APP_NAME') ? (string) APP_NAME : (defined('PRODUCT_NAME') ? (string) PRODUCT_NAME : '');
		$lang = class_exists('Lang') ? Lang::current() : 'en';
		$theme = ($_COOKIE['theme'] ?? 'dark') === 'light' ? 'light' : '';
		return '<!DOCTYPE html><html lang="' . $h($lang) . '"><head><meta charset="UTF-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
			. '<meta name="robots" content="noindex, nofollow">'
			. '<title>' . $h(__('checkout.handoff_title')) . ($appName !== '' ? ' - ' . $h($appName) : '') . '</title>'
			. '<style>'
			. ':root{color-scheme:dark;--bg:#0b0c10;--card:#15171f;--line:rgba(255,255,255,.08);--text:#e6e8ef;--muted:#9aa1b5;--accent:#3b82f6;}'
			. 'body.light{color-scheme:light;--bg:#fafafa;--card:#fff;--line:#e4e4e7;--text:#09090b;--muted:#52525b;}'
			. '*{box-sizing:border-box;margin:0;padding:0;}'
			. 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;}'
			. '.box{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:36px;max-width:440px;width:100%;text-align:center;}'
			. 'h1{font-size:20px;margin-bottom:10px;}p{color:var(--muted);line-height:1.6;margin-bottom:22px;}'
			. 'a.btn{display:inline-block;padding:12px 26px;border-radius:10px;background:var(--accent);color:#fff;text-decoration:none;font-weight:600;}'
			. '</style></head><body class="' . $theme . '"><main class="box">'
			. '<h1>' . $h(__('checkout.handoff_title')) . '</h1>'
			. '<p>' . $h(__('checkout.handoff_body')) . '</p>'
			. '<a class="btn" id="checkoutContinue" href="' . $h($url) . '">' . $h(__('checkout.handoff_continue')) . '</a>'
			. '</main><script src="' . $h($appUrl) . '/assets/js/checkout-continue.js?v=' . $h($version) . '" defer></script>'
			. '</body></html>';
	}

	/**
	 * Answer a successful checkout with handOffPage(): 200, never a redirect (the route
	 * pre-sets 303 for its error paths). False when the URL may not be handed over, in which
	 * case nothing has been sent and the caller reports a provider error instead.
	 */
	public static function sendHandOff(string $url): bool
	{
		$page = self::handOffPage($url);
		if ($page === null) {
			return false;
		}
		http_response_code(200);
		header('Content-Type: text/html; charset=utf-8');
		header('Cache-Control: no-store, private');
		header('X-Robots-Tag: noindex, nofollow');
		echo $page;
		return true;
	}
}
