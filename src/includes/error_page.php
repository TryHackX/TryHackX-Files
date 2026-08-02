<?php
/**
 * Minimal, self-contained error/notice page (ban, maintenance).
 * Expects: $pageTitle, $pageIcon, $pageHeading, $pageMessage (all plain text),
 * optional $pageMeta (array of [label => value], values shown as text).
 * Sends no external requests; consistent dark theme.
 */
if (!defined('APP_ROOT')) {
	exit;
}
$metaRows = $pageMeta ?? [];
?>
<!DOCTYPE html>
<html lang="<?= class_exists('Lang') ? Lang::current() : 'pl' ?>">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="robots" content="noindex, nofollow">
	<title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars(defined('APP_NAME') ? APP_NAME : (defined('PRODUCT_NAME') ? PRODUCT_NAME : 'TryHackX Files')) ?></title>
	<style>
		:root { color-scheme: dark; }
		* { margin: 0; padding: 0; box-sizing: border-box; }
		body {
			font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
			background: #0b0c10;
			color: #e6e8ef;
			min-height: 100vh;
			display: flex;
			align-items: center;
			justify-content: center;
			padding: 24px;
		}
		.box {
			background: #15171f;
			border: 1px solid rgba(255, 255, 255, .08);
			border-radius: 18px;
			padding: 40px;
			max-width: 480px;
			width: 100%;
			text-align: center;
			box-shadow: 0 24px 60px rgba(0, 0, 0, .45);
		}
		.icon { font-size: 44px; margin-bottom: 14px; }
		h1 { font-size: 22px; margin-bottom: 10px; }
		p { color: #9aa1b5; line-height: 1.6; }
		.meta {
			margin-top: 22px;
			text-align: left;
			background: rgba(255, 255, 255, .03);
			border: 1px solid rgba(255, 255, 255, .06);
			border-radius: 12px;
			padding: 14px 16px;
		}
		.meta div { display: flex; justify-content: space-between; gap: 12px; padding: 5px 0; font-size: 14px; }
		.meta div + div { border-top: 1px solid rgba(255, 255, 255, .05); }
		.meta span { color: #9aa1b5; }
		.meta b { color: #e6e8ef; font-weight: 600; }
	</style>
</head>

<body>
	<div class="box">
		<div class="icon"><?= htmlspecialchars($pageIcon) ?></div>
		<h1><?= htmlspecialchars($pageHeading) ?></h1>
		<p><?= htmlspecialchars($pageMessage) ?></p>
		<?php if ($metaRows): ?>
			<div class="meta">
				<?php foreach ($metaRows as $label => $value): ?>
					<div><span><?= htmlspecialchars($label) ?></span><b><?= htmlspecialchars($value) ?></b></div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
</body>

</html>
