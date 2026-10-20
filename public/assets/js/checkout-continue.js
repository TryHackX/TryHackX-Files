/**
 * Checkout hand-off page: move on to the payment provider at once.
 *
 * The checkout answers its form POST with a same-origin page instead of a redirect to the
 * provider, because Chromium checks such a redirect against the page's `form-action 'self'`
 * and refuses it. A navigation started by a script is not subject to form-action. The page's
 * link stays as the manual way on (scripts off, or the replace being blocked).
 */
'use strict';

(function () {
	var link = document.getElementById('checkoutContinue');
	if (link && link.href) {
		window.location.replace(link.href);
	}
}());
