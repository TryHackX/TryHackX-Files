/**
 * Renders the captcha on the panel's re-authentication gate.
 *
 * The gate is a server-rendered page with a strict policy — `script-src-attr 'none'` and no
 * inline scripts — so the provider and site key arrive in a data attribute rather than in a
 * `<script>` block, and this file turns them into a widget. Everything provider-specific is
 * FHCaptcha's problem (captcha.js); here we only move the solved token into the hidden field
 * the form posts.
 *
 * Loaded only when the page decided a captcha is due, so it can assume its elements exist.
 */
(function () {
	'use strict';

	var bootstrap = document.getElementById('reauthCaptchaBootstrap');
	var container = document.getElementById('reauthCaptcha');
	var field = document.getElementById('reauthCaptchaResponse');
	if (!bootstrap || !container || !field || !window.FHCaptcha) return;

	var config;
	try {
		config = JSON.parse(bootstrap.dataset.config || '{}');
	} catch (error) {
		console.error('Captcha config is not valid JSON:', error);
		return;
	}
	if (!config.recaptcha_site_key) return;

	window.FHCaptcha.configure(config);

	var widget = null;
	var handlers = {
		callback: function (token) {
			field.value = token || '';
		},
		'error-callback': function () {
			// Clear the stale token: submitting it would fail verification anyway, and an
			// empty field gives the server the accurate "no captcha solved" answer.
			field.value = '';
		}
	};

	window.FHCaptcha.load(function () {
		if (widget === null) {
			widget = window.FHCaptcha.render(container, handlers);
		}
	});

	// A wrong password re-renders the page, but a widget solved before a *failed* submit is
	// spent. Refresh it when the visitor comes back to the field so they are not left holding
	// a token the provider has already retired.
	var password = document.getElementById('reauthPassword');
	if (password) {
		password.addEventListener('focus', function () {
			if (widget !== null && !field.value) {
				window.FHCaptcha.reset(widget, handlers);
			}
		}, { once: true });
	}
}());
