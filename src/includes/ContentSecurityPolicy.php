<?php
/**
 * The Content-Security-Policy this application serves, as a string.
 *
 * Kept out of config.php so it can be required — and asserted on — without booting the whole
 * web layer: `header()` is a no-op under the CLI SAPI the tests run on, so a function that
 * only ever emits could not be checked at all, and "which origins does Turnstile add" is
 * exactly the kind of thing that should not have to be verified by hand on a live server.
 *
 * This must be the ONLY policy on a document response. A browser handed two
 * Content-Security-Policy headers enforces both, i.e. their intersection, so a second one
 * would quietly veto whatever this builds on top — the ad origins, or the selected captcha
 * provider. public/.htaccess is scoped to static file extensions for exactly that reason.
 */

function buildContentSecurityPolicy(array $settings): string
{
	$strictScripts = defined('FILEHOST_CSP_STRICT_SCRIPTS')
		&& FILEHOST_CSP_STRICT_SCRIPTS === true;
	$script = "'self'" . ($strictScripts ? '' : " 'unsafe-inline'");
	// blob: is same-origin object URLs only — the panel's banner cropper previews the
	// picked file through URL.createObjectURL, which img-src would otherwise block.
	$img = "'self' data: blob:";
	$connect = "'self'";
	$frame = "'self'";

	// Captcha (2.79.0): only the selected provider's origins are admitted, and only while the
	// captcha is switched on. Picking Turnstile must not leave Google's script hosts
	// whitelisted, and switching the feature off must not leave anyone's.
	foreach (CaptchaService::cspOrigins($settings) as $origin) {
		$script .= ' ' . $origin;
		$frame .= ' ' . $origin;
		$connect .= ' ' . $origin;
	}

	$adsOn = ($settings['ads_enabled'] ?? '0') === '1';
	if ($adsOn && ($settings['ads_adsense_active'] ?? '0') === '1') {
		$script .= ' https://pagead2.googlesyndication.com https://googleads.g.doubleclick.net'
			. ' https://tpc.googlesyndication.com https://www.googletagservices.com'
			. ' https://ep2.adtrafficquality.google';
		$img .= ' https://*.googlesyndication.com https://*.doubleclick.net https://*.google.com'
			. ' https://*.google.pl https://ep1.adtrafficquality.google';
		$connect .= ' https://pagead2.googlesyndication.com https://*.doubleclick.net'
			. ' https://*.google.com https://ep1.adtrafficquality.google';
		$frame .= ' https://googleads.g.doubleclick.net https://tpc.googlesyndication.com'
			. ' https://*.doubleclick.net https://ep2.adtrafficquality.google';
	}
	if ($adsOn) {
		// Operator-supplied origins for other networks. Stored pre-sanitised (see
		// AdsController::sanitizeCspExtra), re-filtered here so a hand-edited DB row still
		// cannot smuggle a directive into the header.
		$extra = '';
		foreach (preg_split('/\s+/', trim((string) ($settings['ads_csp_extra'] ?? ''))) ?: [] as $token) {
			if ($token !== '' && preg_match('~^https://[a-z0-9*][a-z0-9.*-]*(:\d{1,5})?$~i', $token)) {
				$extra .= ' ' . $token;
			}
		}
		if ($extra !== '') {
			$script .= $extra;
			$img .= $extra;
			$connect .= $extra;
			$frame .= $extra;
		}
	}

	return "default-src 'self'; script-src {$script}; "
		. ($strictScripts ? "script-src-attr 'none'; " : '')
		. "style-src 'self' 'unsafe-inline'; img-src {$img}; connect-src {$connect}; "
		. "font-src 'self'; frame-src {$frame}; object-src 'none'; base-uri 'self'; "
		. "form-action 'self'; frame-ancestors 'self'";
}
