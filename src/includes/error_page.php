<?php
/**
 * Minimal, self-contained error/notice page (ban, maintenance, premium switched off).
 * Expects: $pageTitle, $pageIcon, $pageHeading, $pageMessage (all plain text; $pageIcon is a
 * Font Awesome icon NAME such as "fa-ban", not markup), optional $pageMeta (array of
 * [label => value], values shown as text), optional $pageLink (['href', 'label', 'icon']: one
 * way onward, such as back to the home page — never on a page that IS the home page) and
 * optional $pageSignIn (true: the maintenance notice with the sign-in dialog, which then also
 * needs $appUrl and $currentUser, like every page that includes auth_modal.php).
 * Sends no external requests (the icon font is the self-hosted copy); follows the visitor's
 * theme cookie like every other page.
 */
if (!defined('APP_ROOT')) {
	exit;
}
$metaRows = $pageMeta ?? [];
// A bare icon name: letters, digits and dashes only, whatever a caller passes.
$pageIconName = preg_replace('/[^a-z0-9-]/i', '', (string) ($pageIcon ?? '')) ?: 'fa-circle-info';
$pageTheme = ($_COOKIE['theme'] ?? 'dark') === 'light' ? 'light' : '';
$pageLinkRow = isset($pageLink) && is_array($pageLink) && is_string($pageLink['href'] ?? null)
	? [
		'href' => (string) $pageLink['href'],
		'label' => (string) ($pageLink['label'] ?? ''),
		'icon' => preg_replace('/[^a-z0-9-]/i', '', (string) ($pageLink['icon'] ?? '')) ?: 'fa-arrow-left',
	]
	: null;
$pageSignIn = !empty($pageSignIn);
$pageAssetVersion = defined('APP_VERSION') ? htmlspecialchars(APP_VERSION) : '';
$pageBaseUrl = htmlspecialchars(defined('APP_URL') ? APP_URL : '');
?>
<!DOCTYPE html>
<html lang="<?= class_exists('Lang') ? Lang::current() : 'pl' ?>">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="robots" content="noindex, nofollow">
	<title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars(defined('APP_NAME') ? APP_NAME : (defined('PRODUCT_NAME') ? PRODUCT_NAME : 'TryHackX Files')) ?></title>
	<link rel="stylesheet" href="<?= $pageBaseUrl ?>/assets/fontawesome/css/all.min.css?v=<?= $pageAssetVersion ?>">
	<?php if ($pageSignIn): ?>
		<?php /* The sign-in dialog is the site's own, so it brings the site's stylesheet, token
		         and dictionary; the notice card below keeps its own look (the rules after this
		         link win). */ ?>
		<link rel="stylesheet" href="<?= $pageBaseUrl ?>/assets/css/base.css?v=<?= $pageAssetVersion ?>">
		<?php require __DIR__ . '/csrf_head.php'; ?>
		<?php require __DIR__ . '/i18n_head.php'; ?>
	<?php endif; ?>
	<style>
		:root {
			color-scheme: dark;
			--page-bg: #0b0c10;
			--card-bg: #15171f;
			--card-line: rgba(255, 255, 255, .08);
			--card-shadow: 0 24px 60px rgba(0, 0, 0, .45);
			--text: #e6e8ef;
			--muted: #9aa1b5;
			--panel-bg: rgba(255, 255, 255, .03);
			--panel-line: rgba(255, 255, 255, .06);
			--row-line: rgba(255, 255, 255, .05);
		}
		body.light {
			color-scheme: light;
			--page-bg: #fafafa;
			--card-bg: #ffffff;
			--card-line: #e4e4e7;
			--card-shadow: 0 24px 60px rgba(0, 0, 0, .10);
			--text: #09090b;
			--muted: #52525b;
			--panel-bg: #f4f4f5;
			--panel-line: #e4e4e7;
			--row-line: #e4e4e7;
		}
		* { margin: 0; padding: 0; box-sizing: border-box; }
		body {
			font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
			background: var(--page-bg);
			color: var(--text);
			min-height: 100vh;
			display: flex;
			align-items: center;
			justify-content: center;
			padding: 24px;
		}
		.box {
			background: var(--card-bg);
			border: 1px solid var(--card-line);
			border-radius: 18px;
			padding: 40px;
			max-width: 480px;
			width: 100%;
			text-align: center;
			box-shadow: var(--card-shadow);
		}
		.icon { font-size: 44px; margin-bottom: 14px; }
		h1 { font-size: 22px; margin-bottom: 10px; }
		p { color: var(--muted); line-height: 1.6; }
		.meta {
			margin-top: 22px;
			text-align: left;
			background: var(--panel-bg);
			border: 1px solid var(--panel-line);
			border-radius: 12px;
			padding: 14px 16px;
		}
		.meta div { display: flex; justify-content: space-between; gap: 12px; padding: 5px 0; font-size: 14px; }
		.meta div + div { border-top: 1px solid var(--row-line); }
		.meta span { color: var(--muted); }
		.meta b { color: var(--text); font-weight: 600; }
		.onward {
			display: inline-flex;
			align-items: center;
			gap: 8px;
			margin-top: 22px;
			color: var(--text);
			font-size: 15px;
			text-decoration: none;
			border-bottom: 1px solid var(--card-line);
		}
		.onward:hover, .onward:focus-visible { border-bottom-color: currentColor; }
	</style>
</head>

<body class="<?= $pageTheme ?>">
	<div class="box">
		<div class="icon"><i class="fa-solid <?= htmlspecialchars($pageIconName) ?>" aria-hidden="true"></i></div>
		<h1><?= htmlspecialchars($pageHeading) ?></h1>
		<p><?= htmlspecialchars($pageMessage) ?></p>
		<?php if ($metaRows): ?>
			<div class="meta">
				<?php foreach ($metaRows as $label => $value): ?>
					<div><span><?= htmlspecialchars($label) ?></span><b><?= htmlspecialchars($value) ?></b></div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<?php if ($pageLinkRow !== null): ?>
			<a class="onward" href="<?= htmlspecialchars($pageLinkRow['href']) ?>"><i class="fa-solid <?= htmlspecialchars($pageLinkRow['icon']) ?>" aria-hidden="true"></i><?= htmlspecialchars($pageLinkRow['label']) ?></a>
		<?php endif; ?>
	</div>
	<?php if ($pageSignIn): ?>
		<?php require __DIR__ . '/auth_modal.php'; ?>
		<script src="<?= $pageBaseUrl ?>/assets/js/api.js?v=<?= $pageAssetVersion ?>"></script>
		<?php require __DIR__ . '/auth_scripts.php'; ?>
	<?php endif; ?>
</body>

</html>
