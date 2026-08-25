<?php
/**
 * The panel's second gate: a password, asked again after the authorization window closes.
 *
 * Being signed in is not the same as having just proved it. A browser left open, a shared
 * machine, a "stay signed in" cookie restoring a session weeks later — all of those produce a
 * perfectly valid session with no recent evidence that the person at the keyboard is the
 * account owner. The public site is content with that; the panel is not.
 *
 * Expects: $reauthError (string), $reauthUsername (string), $appUrl (string).
 */
if (!defined('APP_ROOT')) {
	exit;
}
?>
<!DOCTYPE html>
<html lang="<?= class_exists('Lang') ? Lang::current() : 'pl' ?>">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="robots" content="noindex, nofollow">
	<title><?= _h('panel.reauth.title') ?> - <?= htmlspecialchars(defined('APP_NAME') ? APP_NAME : (defined('PRODUCT_NAME') ? PRODUCT_NAME : 'TryHackX Files'), ENT_QUOTES, 'UTF-8') ?></title>
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
			padding: 36px;
			max-width: 420px;
			width: 100%;
			box-shadow: 0 24px 60px rgba(0, 0, 0, .45);
		}
		h1 { font-size: 20px; margin-bottom: 8px; }
		p { color: #9aa1b1; font-size: 14px; line-height: 1.55; margin-bottom: 20px; }
		label { display: block; font-size: 13px; color: #9aa1b1; margin-bottom: 6px; }
		input[type=password] {
			width: 100%;
			padding: 12px 14px;
			border-radius: 10px;
			border: 1px solid rgba(255, 255, 255, .12);
			background: #0f1118;
			color: #e6e8ef;
			font-size: 15px;
		}
		input[type=password]:focus { outline: 2px solid #6366f1; outline-offset: 1px; }
		button {
			width: 100%;
			margin-top: 16px;
			padding: 12px 14px;
			border: 0;
			border-radius: 10px;
			background: #6366f1;
			color: #fff;
			font-size: 15px;
			font-weight: 600;
			cursor: pointer;
		}
		button:hover { background: #4f52e0; }
		.error {
			background: rgba(239, 68, 68, .12);
			border: 1px solid rgba(239, 68, 68, .35);
			color: #fca5a5;
			padding: 10px 12px;
			border-radius: 10px;
			font-size: 14px;
			margin-bottom: 16px;
		}
		.who { color: #e6e8ef; font-weight: 600; }
		.back { display: block; text-align: center; margin-top: 18px; font-size: 13px; color: #9aa1b1; text-decoration: none; }
		.back:hover { color: #e6e8ef; }
	</style>
</head>

<body>
	<div class="box">
		<h1><?= _h('panel.reauth.title') ?></h1>
		<p><?= _h('panel.reauth.intro') ?>
			<span class="who"><?= htmlspecialchars($reauthUsername, ENT_QUOTES, 'UTF-8') ?></span></p>
		<?php if ($reauthError !== ''): ?>
			<div class="error"><?= htmlspecialchars($reauthError, ENT_QUOTES, 'UTF-8') ?></div>
		<?php endif; ?>
		<form method="post" autocomplete="off">
			<input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
			<input type="hidden" name="action" value="panel_reauth">
			<label for="reauthPassword"><?= _h('panel.reauth.password') ?></label>
			<input type="password" id="reauthPassword" name="password" required autofocus
				autocomplete="current-password" maxlength="<?= (int) InputLimits::accountPasswordMax() ?>">
			<button type="submit"><?= _h('panel.reauth.submit') ?></button>
		</form>
		<a class="back" href="<?= htmlspecialchars($appUrl, ENT_QUOTES, 'UTF-8') ?>/"><?= _h('panel.reauth.back') ?></a>
	</div>
</body>

</html>
