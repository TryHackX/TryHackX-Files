<?php

use PHPUnit\Framework\TestCase;

require_once PROJECT_ROOT . '/src/includes/Lang.php';

/**
 * Rendering / template rules for the pages visitors see: the notice pages, the translation
 * dictionary each page ships, and a few markup contracts the scripts rely on.
 */
final class FrontendTemplatesTest extends TestCase
{
	/** Scripts loaded on the public pages (not the panel). Must match what the templates include. */
	private const PUBLIC_SCRIPTS = [
		'bootstrap', 'api', 'util', 'ui', 'notifications', 'file-icons', 'captcha', 'index', 'auth',
		'download', 'collection', 'premium', 'ads', 'ads-consent', 'adsense-slots', 'site-footer',
		'reauth-captcha', 'invoice',
	];

	private function source(string $relative): string
	{
		$contents = file_get_contents(PROJECT_ROOT . '/' . $relative);
		$this->assertIsString($contents, $relative);
		return $contents;
	}

	private function renderErrorPage(string $theme, string $icon = 'fa-ban'): string
	{
		$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/render_error_page.php')
			. ' ' . escapeshellarg($theme) . ' ' . escapeshellarg($icon);
		$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$this->assertIsResource($process);
		$out = (string) stream_get_contents($pipes[1]);
		$err = (string) stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$this->assertSame(0, proc_close($process), $err);
		$this->assertSame('', $err);
		return $out;
	}

	public function testNoticePageShowsAnIconNotEscapedMarkup(): void
	{
		$html = $this->renderErrorPage('dark');
		$this->assertStringContainsString('<i class="fa-solid fa-ban" aria-hidden="true"></i>', $html);
		$this->assertStringNotContainsString('&lt;i', $html, 'the icon must not be printed as text');
		$this->assertStringContainsString('/assets/fontawesome/css/all.min.css', $html);
		// values are still escaped
		$this->assertStringNotContainsString('<script>alert(1)</script>', $html);
		$this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
	}

	public function testNoticePageOnlyAcceptsABareIconName(): void
	{
		$html = $this->renderErrorPage('dark', '"><script>alert(1)</script>');
		$this->assertStringNotContainsString('<script>alert(1)</script>', $html);
		$this->assertStringContainsString('<i class="fa-solid scriptalert1script" aria-hidden="true"></i>', $html);
		$html = $this->renderErrorPage('dark', '');
		$this->assertStringContainsString('fa-circle-info', $html, 'a missing icon falls back to a neutral one');
	}

	public function testNoticePageFollowsTheThemeCookie(): void
	{
		$this->assertStringContainsString('<body class="light">', $this->renderErrorPage('light'));
		$this->assertStringContainsString('<body class="">', $this->renderErrorPage('dark'));
		$this->assertStringContainsString('<body class="">', $this->renderErrorPage('something-else'));
	}

	public function testCallersPassIconNamesNotMarkup(): void
	{
		foreach (['public/index.php', 'public/premium.php', 'src/includes/MaintenanceMode.php'] as $file) {
			$source = $this->source($file);
			$this->assertDoesNotMatchRegularExpression('/\$pageIcon\s*=\s*\'<i /', $source, $file);
			$this->assertMatchesRegularExpression('/\$pageIcon\s*=\s*\'fa-[a-z0-9-]+\';/', $source, $file);
		}
	}

	/**
	 * The site name is administrator-chosen text that goes into every page head and header. A
	 * template that echoes it raw turned `</title><meta http-equiv="refresh" …>` into a redirect
	 * on every page, which the CSP does not stop.
	 */
	public function testEveryTemplateEscapesTheSiteName(): void
	{
		$files = array_merge(
			glob(PROJECT_ROOT . '/public/*.php') ?: [],
			glob(PROJECT_ROOT . '/src/includes/*.php') ?: [],
			glob(PROJECT_ROOT . '/src/includes/panel/*.php') ?: []
		);
		$echoes = 0;
		foreach ($files as $file) {
			$source = (string) file_get_contents($file);
			preg_match_all('/<\?=((?:(?!\?>).)*APP_NAME(?:(?!\?>).)*)\?>/s', $source, $matches);
			foreach ($matches[1] as $echo) {
				$echoes++;
				$this->assertStringContainsString(
					'htmlspecialchars(',
					$echo,
					basename($file) . ' prints the site name unescaped: <?=' . $echo . '?>'
				);
			}
		}
		$this->assertGreaterThanOrEqual(9, $echoes, 'the scan should find the titles and the header');
	}

	public function testDeleteLinkPageAlsoFollowsTheTheme(): void
	{
		$source = $this->source('src/includes/delete_link_page.php');
		$this->assertStringContainsString("\$_COOKIE['theme']", $source);
		$this->assertStringContainsString('body.light', $source);
	}

	/** @return list<string> first-argument string literals of every t(...) call in a script */
	private function translationKeysUsedBy(string $script): array
	{
		$code = $this->source('public/assets/js/' . $script . '.js');
		$keys = [];
		preg_match_all('/(?<![A-Za-z0-9_$])t\(/', $code, $calls, PREG_OFFSET_CAPTURE);
		foreach ($calls[0] as [$match, $offset]) {
			$depth = 1;
			$argument = '';
			for ($i = $offset + strlen($match), $n = strlen($code); $i < $n && $depth > 0; $i++) {
				$char = $code[$i];
				if ($char === '(') {
					$depth++;
				} elseif ($char === ')') {
					$depth--;
					if ($depth === 0) {
						break;
					}
				} elseif ($char === ',' && $depth === 1) {
					break;
				}
				$argument .= $char;
			}
			if (preg_match_all('/([\'"`])([a-z0-9_]+(?:\.[a-z0-9_]+)+)\1/i', $argument, $literals)) {
				foreach ($literals[2] as $key) {
					$keys[$key] = true;
				}
			}
		}
		return array_keys($keys);
	}

	public function testPublicDictionaryCoversEveryKeyThePublicScriptsAskFor(): void
	{
		$public = Lang::forBrowser('public');
		$english = require PROJECT_ROOT . '/src/lang/en.php';
		$missing = [];
		$total = 0;
		foreach (self::PUBLIC_SCRIPTS as $script) {
			foreach ($this->translationKeysUsedBy($script) as $key) {
				$total++;
				if (!array_key_exists($key, $english)) {
					$missing[] = "$script uses $key which does not exist";
				} elseif (!array_key_exists($key, $public)) {
					$missing[] = "$script uses $key which the public pages do not ship (add its prefix to Lang::PUBLIC_PREFIXES)";
				}
			}
		}
		$this->assertGreaterThan(80, $total, 'the scan should have found the scripts\' keys');
		$this->assertSame([], $missing);
	}

	public function testPublicDictionaryIsASmallSubsetAndFullStaysFull(): void
	{
		$full = Lang::forBrowser('full');
		$public = Lang::forBrowser('public');
		$this->assertSame(Lang::all(), $full);
		$this->assertSame(Lang::all(), Lang::forBrowser('anything-else'), 'unknown scopes get everything, never less');
		$this->assertNotEmpty($public);
		$this->assertLessThan(count($full) / 4, count($public), 'public pages must not ship the panel dictionary');
		foreach (array_keys($public) as $key) {
			$this->assertArrayHasKey($key, $full);
		}
		// panel-only text stays out
		$this->assertArrayNotHasKey('panel.users.th_email', $public);
		$this->assertArrayHasKey('up.remove_confirm', $public);
	}

	public function testPagesDeclareTheirDictionaryScope(): void
	{
		foreach (['index', 'download', 'collection', 'premium'] as $page) {
			$this->assertStringContainsString("\$i18nScope = 'public';", $this->source("public/$page.php"), $page);
		}
		$this->assertStringNotContainsString('i18nScope', $this->source('public/panel.php'));
		$head = $this->source('src/includes/i18n_head.php');
		$this->assertStringContainsString('Lang::forBrowser($i18nScope)', $head);
		$this->assertStringNotContainsString('Lang::all()', $head);
	}

	public function testPanelOnlyShipsTheAdminScriptsItCanUse(): void
	{
		$panel = $this->source('public/panel.php');
		foreach (['panel-users', 'panel-groups', 'panel-dashboard'] as $module) {
			$this->assertMatchesRegularExpression(
				'/<\?php if \(\$isAdmin\): \?>\s*<script src="[^"]*' . $module . '\.js[^"]*"><\/script>\s*<\?php endif; \?>/',
				$panel,
				"$module.js must be admin-only"
			);
		}
		$this->assertMatchesRegularExpression(
			'/if \(\$isAdmin \|\| \$canModerationPanel\): \?>\s*<script src="[^"]*panel-moderation\.js/',
			$panel
		);
		$this->assertMatchesRegularExpression(
			'/if \(\$isAdmin \|\| \$canAdsPanel \|\| \$tab === \'myads\'\): \?>\s*<script src="[^"]*panel-ads\.js/',
			$panel
		);
		// ...and panel.js copes when a module is absent
		$js = $this->source('public/assets/js/panel.js');
		foreach (['FHPanelUsers', 'FHPanelGroups', 'FHPanelModeration', 'FHPanelDashboard', 'FHPanelAds'] as $module) {
			$this->assertStringContainsString("} = window.$module || {};", $js);
		}
	}

	public function testCollectionPageShipsATemplateForItsToasts(): void
	{
		$this->assertStringContainsString('id="dlToast"', $this->source('public/collection.php'));
	}

	public function testReportFormLabelsAreBoundToTheirFields(): void
	{
		$source = $this->source('public/download.php');
		preg_match_all('/<label for="([A-Za-z]+)">/', $source, $labels);
		$this->assertCount(7, $labels[1]);
		foreach ($labels[1] as $id) {
			$this->assertStringContainsString('id="' . $id . '"', $source, "label for=$id has no field");
		}
		$this->assertDoesNotMatchRegularExpression('/<label>\s*<\?= _h\(\'report\./', $source);
	}

	/**
	 * The report form marks exactly the fields ReportController refuses to go without (it used to
	 * star three of six, and a reporter was sent back for fields shown as optional), and the text
	 * area stops at or below what the server keeps.
	 */
	public function testReportFormRequiresWhatTheServerRequires(): void
	{
		$controller = $this->source('src/includes/api/ReportController.php');
		$this->assertSame(1, preg_match('/foreach \(\[([^\]]+)\] as \$field\)/', $controller, $list));
		preg_match_all("/'([a-z]+)'/", $list[1], $required);
		$this->assertNotEmpty($required[1]);

		// Payload key -> form field name, as download.js builds the request.
		preg_match_all("/(\w+): fields\.get\('(\w+)'\)/", $this->source('public/assets/js/download.js'), $pairs);
		$formName = array_combine($pairs[1], $pairs[2]);

		$page = $this->source('public/download.php');
		preg_match('/<form id="reportForm">(.*?)<\/form>/s', $page, $form);
		$this->assertNotEmpty($form);
		preg_match_all('/<(?:input|textarea)\b[^>]*>/', $form[1], $controls);
		$requiredInForm = [];
		foreach ($controls[0] as $control) {
			if (preg_match('/\bname="(\w+)"/', $control, $name) && preg_match('/\srequired\b/', $control)) {
				$requiredInForm[] = $name[1];
			}
		}
		$expected = array_map(fn (string $field): string => $formName[$field], $required[1]);
		sort($expected);
		sort($requiredInForm);
		$this->assertSame($expected, $requiredInForm);
		// Each required field's label carries the star; the optional link's does not.
		$this->assertSame(count($expected), substr_count($form[1], '<span class="req-mark" aria-hidden="true">*</span></label>'));

		// The cap is a number or a class constant (`self::INFO_MAX`).
		$this->assertSame(1, preg_match("/'info' => self::text\(\\\$input\['info'\] \?\? '', ([^)]+)\)/", $controller, $cap));
		$limit = trim($cap[1]);
		if (preg_match('/^self::(\w+)$/', $limit, $constant)) {
			$this->assertSame(1, preg_match('/const ' . $constant[1] . ' = (\d+);/', $controller, $value));
			$limit = $value[1];
		}
		$this->assertMatchesRegularExpression('/^\d+$/', $limit);
		$this->assertSame(1, preg_match('/<textarea name="additional_info"[^>]*maxlength="(\d+)"/', $form[1], $max));
		$this->assertLessThanOrEqual((int) $limit, (int) $max[1]);
		foreach (['en', 'pl', 'es', 'zh'] as $code) {
			$strings = require PROJECT_ROOT . "/src/lang/$code.php";
			foreach (['report.name', 'report.email', 'report.obj_title'] as $key) {
				$this->assertStringNotContainsString('*', $strings[$key], "$code $key: the star is markup now");
			}
		}
	}

	public function testTwoFactorEnableAndDisableBlocksHaveTheirOwnPasswordLabel(): void
	{
		$source = $this->source('src/includes/panel/views_account.php');
		$this->assertMatchesRegularExpression('/<label for="twofaSetupPass"><\?= _h\(\'panel\.2fa\.on_label\'\) \?>/', $source);
		$this->assertMatchesRegularExpression('/<label for="twofaOffPass"><\?= _h\(\'panel\.2fa\.off_label\'\) \?>/', $source);
		foreach (['en', 'pl', 'es', 'zh'] as $code) {
			$strings = require PROJECT_ROOT . "/src/lang/$code.php";
			$this->assertNotSame($strings['panel.2fa.on_label'], $strings['panel.2fa.off_label'], $code);
		}
	}
}
