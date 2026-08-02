<?php
/**
 * Minimal i18n (Faza 4.3) — no dependencies.
 *
 * Strings live in `src/lang/<code>.php` as a flat map of dotted keys. Lookups fall back
 * from the active language to the default one and finally to the key itself, so a missing
 * translation degrades to something readable rather than a blank page.
 *
 * Language is resolved once per request, in order: `?lang=` (which also remembers the
 * choice in a cookie) → cookie → the site's `default_language` setting → Accept-Language
 * → 'pl'.
 */
class Lang
{
	/**
	 * Native names for known language codes — used to label the switcher. Any
	 * `src/lang/<code>.php` file is a valid language even if it's not listed here
	 * (it then shows under its uppercased code), so admins/users can drop in a new
	 * translation without touching this class. See available().
	 */
	public const NAMES = [
		'pl' => 'Polski', 'en' => 'English', 'de' => 'Deutsch', 'fr' => 'Français',
		'es' => 'Español', 'it' => 'Italiano', 'nl' => 'Nederlands', 'pt' => 'Português',
		'ru' => 'Русский', 'uk' => 'Українська', 'cs' => 'Čeština', 'sk' => 'Slovenčina',
		'ja' => '日本語', 'zh' => '中文', 'ko' => '한국어', 'tr' => 'Türkçe', 'ar' => 'العربية',
	];

	private const FALLBACK = 'pl';
	private const COOKIE = 'lang';

	/**
	 * The two languages shipped with the app. They are always offered and can never be
	 * switched off or deleted from the panel — turning off every language, or removing the
	 * one the fallback chain ends at, would leave the UI with nothing to render.
	 */
	public const BUILT_IN = ['pl', 'en'];

	/**
	 * Visibility lists layered on top of `enabled_languages` (pt 6).
	 *
	 * `enabled` decides which languages exist for visitors at all. Within that, an admin can
	 * narrow two surfaces separately: what the header switcher offers, and what a user may pick
	 * for their account (which is also what Accept-Language is allowed to resolve to). A
	 * language kept out of both is still reachable by an explicit `?lang=` link — hiding a
	 * control is not the same as withdrawing the translation.
	 */
	public const LIST_SWITCHER = 'switcher_languages';
	public const LIST_USERS = 'user_languages';

	private static string $current = self::FALLBACK;
	private static array $strings = [];
	private static array $fallbackStrings = [];
	private static bool $booted = false;
	private static ?array $available = null;
	private static ?array $enabled = null;
	/** Cached allow-lists, keyed by setting name. */
	private static array $lists = [];
	/** Translation maps used outside the current request locale (for example recipient mail). */
	private static array $localeStrings = [];

	/**
	 * Resolve and load the active language. Safe to call repeatedly; only the first call
	 * does the work. Must run before output (it may set the language cookie).
	 *
	 * @param string|null $configured The site default from settings, when available.
	 */
	public static function init(?string $configured = null): void
	{
		if (self::$booted) {
			return;
		}
		self::$booted = true;

		$lang = null;

		// An explicit ?lang= wins and is remembered for next time.
		if (isset($_GET['lang']) && self::supported((string) $_GET['lang'])) {
			$lang = (string) $_GET['lang'];
			if (!headers_sent()) {
				setcookie(self::COOKIE, $lang, [
					'expires' => time() + 31536000,
					'path' => '/',
					'secure' => function_exists('isRequestSecure') ? isRequestSecure() : false,
					'httponly' => false, // the JS bridge reads it too
					'samesite' => 'Lax',
				]);
			}
			$_COOKIE[self::COOKIE] = $lang;
		}

		// A signed-in user's saved choice outranks the cookie, so the language follows the
		// account to a new browser instead of being whatever that browser last happened to use.
		if (!$lang && !empty($_SESSION['user_language']) && self::supported((string) $_SESSION['user_language'])) {
			$lang = (string) $_SESSION['user_language'];
		}

		if (!$lang && isset($_COOKIE[self::COOKIE]) && self::supported((string) $_COOKIE[self::COOKIE])) {
			$lang = (string) $_COOKIE[self::COOKIE];
		}
		if (!$lang && $configured && self::supported($configured)) {
			$lang = $configured;
		}
		if (!$lang) {
			$lang = self::fromAcceptLanguage();
		}

		self::$current = $lang ?: self::FALLBACK;
		self::$strings = self::load(self::$current);
		self::$fallbackStrings = (self::$current === self::FALLBACK)
			? self::$strings
			: self::load(self::FALLBACK);
	}

	/**
	 * Available languages as [code => display name], auto-discovered from the
	 * `src/lang/*.php` files. Drop in `src/lang/de.php` and German appears in the
	 * switcher automatically — no code change needed. Result is cached per request.
	 */
	public static function available(): array
	{
		if (self::$available !== null) {
			return self::$available;
		}
		$out = [];
		foreach (glob(dirname(__DIR__) . '/lang/*.php') ?: [] as $file) {
			$code = strtolower(basename($file, '.php'));
			if (preg_match('/^[a-z]{2,3}$/', $code)) {
				$out[$code] = self::NAMES[$code] ?? strtoupper($code);
			}
		}
		if (!isset($out[self::FALLBACK])) {
			$out[self::FALLBACK] = self::NAMES[self::FALLBACK] ?? strtoupper(self::FALLBACK);
		}
		// Keep the default first, then the rest as discovered.
		$ordered = [self::FALLBACK => $out[self::FALLBACK]];
		foreach ($out as $k => $v) {
			if ($k !== self::FALLBACK) {
				$ordered[$k] = $v;
			}
		}
		return self::$available = $ordered;
	}

	/**
	 * Languages actually offered to visitors: the installed ones minus any the admin has
	 * switched off (Settings → Languages). The built-ins are always kept, so the list can
	 * never end up empty and the fallback chain always resolves.
	 *
	 * The `enabled_languages` setting holds a comma-separated allow-list; empty means
	 * "everything installed", which is the behaviour from before this was configurable.
	 */
	public static function enabled(): array
	{
		if (self::$enabled !== null) {
			return self::$enabled;
		}
		$all = self::available();

		$raw = '';
		if (class_exists('Database')) {
			$raw = (string) Database::getSetting('enabled_languages', '');
		}
		if (trim($raw) === '') {
			return self::$enabled = $all;
		}

		$allow = array_filter(array_map('trim', explode(',', $raw)));
		$allow = array_merge($allow, self::BUILT_IN);

		$out = [];
		foreach ($all as $code => $name) {
			if (in_array($code, $allow, true)) {
				$out[$code] = $name;
			}
		}
		return self::$enabled = ($out ?: $all);
	}

	/**
	 * One of the visibility subsets of enabled() — see LIST_SWITCHER / LIST_USERS.
	 * An empty (or entirely stale) setting means "everything enabled", so the surface can
	 * never end up with no language to offer.
	 */
	public static function subset(string $listKey): array
	{
		if (isset(self::$lists[$listKey])) {
			return self::$lists[$listKey];
		}
		$all = self::enabled();

		$raw = '';
		if (class_exists('Database')) {
			$raw = (string) Database::getSetting($listKey, '');
		}
		if (trim($raw) === '') {
			return self::$lists[$listKey] = $all;
		}

		$allow = array_filter(array_map('trim', explode(',', $raw)));
		$out = [];
		foreach ($all as $code => $name) {
			if (in_array($code, $allow, true)) {
				$out[$code] = $name;
			}
		}
		return self::$lists[$listKey] = ($out ?: $all);
	}

	/** Languages offered by the header switcher. */
	public static function forSwitcher(): array
	{
		return self::subset(self::LIST_SWITCHER);
	}

	/** Languages a user may set on their account, and that Accept-Language may resolve to. */
	public static function forUsers(): array
	{
		return self::subset(self::LIST_USERS);
	}

	/** Forget the cached language lists (after the admin edits them). */
	public static function invalidateCache(): void
	{
		self::$available = null;
		self::$enabled = null;
		self::$lists = [];
	}

	/** A language a visitor may actually select. Disabled ones are not switchable via ?lang=. */
	public static function supported(string $code): bool
	{
		return isset(self::enabled()[$code]);
	}

	/** Installed regardless of the enabled/disabled flag — for the admin management screen. */
	public static function installed(string $code): bool
	{
		return isset(self::available()[$code]);
	}

	public static function current(): string
	{
		self::init();
		return self::$current;
	}

	private static function load(string $code): array
	{
		$file = dirname(__DIR__) . '/lang/' . $code . '.php';
		if (!is_file($file)) {
			return [];
		}
		$data = require $file;
		return is_array($data) ? $data : [];
	}

	/**
	 * First browser-requested language the site auto-switches to, honouring q weights.
	 * Limited to forUsers(): this is the automatic choice made *for* a visitor, so it must stay
	 * inside the set an admin marked as offerable, not merely inside what is installed.
	 */
	private static function fromAcceptLanguage(): ?string
	{
		$offerable = self::forUsers();
		$header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
		$candidates = [];
		foreach (explode(',', $header) as $position => $part) {
			$segments = array_map('trim', explode(';', $part));
			$tag = strtolower((string) array_shift($segments));
			$quality = 1.0;
			foreach ($segments as $parameter) {
				if (preg_match('/\Aq\s*=\s*(0(?:\.\d{0,3})?|1(?:\.0{0,3})?)\z/iD', $parameter, $match)) {
					$quality = (float) $match[1];
					break;
				}
			}
			if ($quality <= 0.0) {
				continue;
			}
			$code = $tag === '*' ? '' : substr($tag, 0, 2);
			if ($code !== '' && isset($offerable[$code])) {
				$candidates[] = ['code' => $code, 'q' => $quality, 'position' => $position];
			}
		}
		usort($candidates, static function (array $a, array $b): int {
			$quality = $b['q'] <=> $a['q'];
			return $quality !== 0 ? $quality : $a['position'] <=> $b['position'];
		});
		if ($candidates !== []) {
			return $candidates[0]['code'];
		}
		return null;
	}

	/**
	 * Translate $key, replacing `:name` placeholders from $params.
	 * Returns the raw string — use e() when writing into HTML.
	 */
	public static function t(string $key, array $params = []): string
	{
		self::init();
		$s = self::$strings[$key] ?? self::$fallbackStrings[$key] ?? $key;
		if ($params) {
			$repl = [];
			foreach ($params as $k => $v) {
				$repl[':' . $k] = (string) $v;
			}
			$s = strtr($s, $repl);
		}
		return $s;
	}

	/**
	 * Translate for an explicit locale without mutating the request/session language.
	 *
	 * Background jobs and administrator-triggered broadcasts must speak the recipient's
	 * language, not whichever language happened to initialize this PHP process.
	 */
	public static function translateFor(?string $language, string $key, array $params = []): string
	{
		$language = strtolower(trim((string) $language));
		if (!preg_match('/^[a-z]{2}$/', $language) || !isset(self::available()[$language])) {
			$language = self::FALLBACK;
		}
		foreach (array_unique([$language, self::FALLBACK]) as $code) {
			if (!array_key_exists($code, self::$localeStrings)) {
				$path = dirname(__DIR__) . '/lang/' . $code . '.php';
				$strings = is_file($path) ? require $path : [];
				self::$localeStrings[$code] = is_array($strings) ? $strings : [];
			}
		}
		$s = self::$localeStrings[$language][$key]
			?? self::$localeStrings[self::FALLBACK][$key]
			?? $key;
		if ($params) {
			$repl = [];
			foreach ($params as $name => $value) {
				$repl[':' . $name] = (string) $value;
			}
			$s = strtr($s, $repl);
		}
		return $s;
	}

	/**
	 * Is this key known at all? (pt 10)
	 *
	 * `t()` returns the key itself for a miss, which is right for output but useless for a
	 * decision. This is for the case where the key comes from outside — a status code in a URL,
	 * say — and "no such message" has to mean "show nothing" rather than print the code.
	 */
	public static function has(string $key): bool
	{
		self::init();
		return isset(self::$strings[$key]) || isset(self::$fallbackStrings[$key]);
	}

	/** Translate and HTML-escape — the default for template output. */
	public static function e(string $key, array $params = []): string
	{
		return htmlspecialchars(self::t($key, $params), ENT_QUOTES);
	}

	/** Active-language strings (with defaults filled in) — used by the JS bridge. */
	public static function all(): array
	{
		self::init();
		return self::$strings + self::$fallbackStrings;
	}
}

/** Shorthand: raw translated string. */
if (!function_exists('__')) {
	function __(string $key, array $params = []): string
	{
		return Lang::t($key, $params);
	}
}

/** Shorthand: translated + HTML-escaped, for echoing into templates. */
if (!function_exists('_h')) {
	function _h(string $key, array $params = []): string
	{
		return Lang::e($key, $params);
	}
}
