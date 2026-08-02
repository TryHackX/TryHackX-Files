/*
 * Impression beacon (Faza 8). Emitted only on pages that actually rendered at least one
 * ad (see AdRenderer::scripts), so its mere presence means there is something to count.
 *
 * One POST per page view with every rendered ad id, throttled per ad in sessionStorage so
 * a reload within half an hour does not double-count. The server keeps its own session
 * throttle — this one only saves the request.
 */
(function () {
	'use strict';

	var TTL_MS = 30 * 60 * 1000;

	function seenRecently(id) {
		try {
			var t = parseInt(sessionStorage.getItem('fh_ad_seen_' + id) || '0', 10);
			return t > Date.now() - TTL_MS;
		} catch (e) {
			return false; // storage unavailable — let the server throttle decide
		}
	}

	function markSeen(id) {
		try {
			sessionStorage.setItem('fh_ad_seen_' + id, String(Date.now()));
		} catch (e) { }
	}

	function collect() {
		var events = [];
		var ids = [];
		var nodes = document.querySelectorAll('[data-ad-id][data-ad-impression]');
		for (var i = 0; i < nodes.length; i++) {
			// A hidden slot (e.g. an AdSense unit waiting behind the consent bar) was not
			// seen by anyone — it must not count as an impression.
			if (nodes[i].offsetParent === null) {
				continue;
			}
			var id = parseInt(nodes[i].getAttribute('data-ad-id'), 10);
			var token = nodes[i].getAttribute('data-ad-impression') || '';
			if (id > 0 && token && ids.indexOf(id) === -1 && !seenRecently(id)) {
				ids.push(id);
				events.push({ id: id, token: token });
			}
		}
		return events;
	}

	function send() {
		var events = collect();
		if (!events.length) {
			return;
		}
		var apiUrl = (window.APP && window.APP.apiUrl) || (window.API_BASE ? window.API_BASE : 'api.php');
		// csrf_head.php patches window.fetch to attach the token on same-origin POSTs.
		fetch(apiUrl + '?action=ad_track', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ events: events }),
			keepalive: true
		}).then(function () {
			events.forEach(function (event) { markSeen(event.id); });
		}).catch(function () { /* metrics must never bother the visitor */ });
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', send);
	} else {
		send();
	}
})();
