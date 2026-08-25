<?php
/**
 * The panel's second gate: a password, asked again after the authorization window closes.
 *
 * Being signed in is not the same as having just proved it. A browser left open, a shared
 * machine, a "stay signed in" cookie restoring a session weeks later — all of those produce a
 * perfectly valid session with no recent evidence that the person at the keyboard is the
 * account owner. The public site is content with that; the panel is not.
 *
 * The page dresses itself out of the site's own stylesheet rather than a private copy of the
 * palette: same background layer, same card, same input and button classes as the sign-in
 * modal, and the same cookie-driven light theme the panel itself uses. A gate that looks
 * like a different product is a gate people hesitate to type their password into.
 *
 * Expects: $reauthError (string), $reauthUsername (string), $appUrl (string),
 * $reauthLocked (bool), $reauthCaptchaRequired (bool).
 */
if (!defined('APP_ROOT')) {
	exit;
}
$reauthTheme = ($_COOKIE['theme'] ?? 'dark') === 'light' ? 'light' : '';
$reauthLocked = !empty($reauthLocked);
$reauthCaptchaRequired = !empty($reauthCaptchaRequired);
$reauthCaptchaConfig = $reauthCaptchaRequired
	? json_encode([
		'captcha_provider' => CaptchaService::provider(),
		'recaptcha_site_key' => CaptchaService::siteKey(),
	], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
	: '{}';
if ($reauthCaptchaConfig === false) {
	$reauthCaptchaConfig = '{}';
}
?>
<!DOCTYPE html>
<html lang="<?= class_exists('Lang') ? Lang::current() : 'pl' ?>">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="robots" content="noindex, nofollow">
	<title><?= _h('panel.reauth.title') ?> - <?= htmlspecialchars(defined('APP_NAME') ? APP_NAME : (defined('PRODUCT_NAME') ? PRODUCT_NAME : 'TryHackX Files'), ENT_QUOTES, 'UTF-8') ?></title>
	<link rel="stylesheet" href="<?= htmlspecialchars($appUrl, ENT_QUOTES, 'UTF-8') ?>/assets/css/index.css?v=<?= APP_VERSION ?>">
	<link rel="stylesheet" href="<?= htmlspecialchars($appUrl, ENT_QUOTES, 'UTF-8') ?>/assets/css/background.css?v=<?= APP_VERSION ?>">
	<style>
		/*
		 * Everything visual comes from index.css. What is left here is the one thing that
		 * file cannot provide: this card is a whole page, not an overlay, so it needs its own
		 * shell. The offsets copy .auth-modal deliberately — raised toward the top on
		 * desktop, centred on phones — so the gate sits exactly where the sign-in box does.
		 */
		.reauth-shell {
			min-height: 100vh;
			display: flex;
			align-items: flex-start;
			justify-content: center;
			padding: 6vh 16px 24px;
		}

		.reauth-box {
			max-width: 440px;
		}

		.reauth-who {
			color: var(--text);
			font-weight: 600;
		}

		.reauth-back {
			display: block;
			text-align: center;
			margin-top: 18px;
			font-size: 0.85rem;
			color: var(--text-secondary);
			text-decoration: none;
			transition: color 0.2s;
		}

		.reauth-back:hover {
			color: var(--text);
		}

		@media (max-width: 600px) {
			.reauth-shell {
				align-items: center;
				padding: 16px;
			}
		}
	</style>
</head>

<body class="<?= $reauthTheme ?>">
	<?php require __DIR__ . '/bg_decoration.php'; ?>

	<main class="reauth-shell">
		<div class="auth-box reauth-box">
			<h3><?= _h('panel.reauth.title') ?></h3>
			<p><?= _h('panel.reauth.intro') ?>
				<span class="reauth-who"><?= htmlspecialchars($reauthUsername, ENT_QUOTES, 'UTF-8') ?></span></p>

			<?php if ($reauthError !== ''): ?>
				<div class="auth-message error show"><?= htmlspecialchars($reauthError, ENT_QUOTES, 'UTF-8') ?></div>
			<?php endif; ?>

			<?php if (!$reauthLocked): ?>
				<form method="post" autocomplete="off">
					<input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
					<input type="hidden" name="action" value="panel_reauth">
					<div class="form-group">
						<label for="reauthPassword"><?= _h('panel.reauth.password') ?></label>
						<input class="auth-input" type="password" id="reauthPassword" name="password" required autofocus
							autocomplete="current-password" maxlength="<?= (int) InputLimits::accountPasswordMax() ?>">
					</div>
					<?php if ($reauthCaptchaRequired): ?>
						<?php /* Shown from the third wrong password on: by then this is not a typo. */ ?>
						<div class="form-group">
							<label><?= _h('panel.reauth.captcha') ?></label>
							<input type="hidden" name="captcha_response" id="reauthCaptchaResponse">
							<div id="reauthCaptcha"></div>
						</div>
					<?php endif; ?>
					<button class="auth-submit" type="submit"><?= _h('panel.reauth.submit') ?></button>
				</form>
			<?php endif; ?>

			<a class="reauth-back"
				href="<?= htmlspecialchars($appUrl, ENT_QUOTES, 'UTF-8') ?>/"><?= _h('panel.reauth.back') ?></a>
		</div>
	</main>

	<?php if ($reauthCaptchaRequired && !$reauthLocked): ?>
		<?php /* Inline scripts are refused here (script-src-attr 'none'), so the widget is
		         configured from a data attribute by an external boot file. */ ?>
		<div id="reauthCaptchaBootstrap" hidden
			data-config="<?= htmlspecialchars($reauthCaptchaConfig, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></div>
		<script src="<?= htmlspecialchars($appUrl, ENT_QUOTES, 'UTF-8') ?>/assets/js/captcha.js?v=<?= APP_VERSION ?>"></script>
		<script src="<?= htmlspecialchars($appUrl, ENT_QUOTES, 'UTF-8') ?>/assets/js/reauth-captcha.js?v=<?= APP_VERSION ?>"></script>
	<?php endif; ?>
</body>

</html>
