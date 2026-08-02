/**
 * Shared API client (Faza 5 · #3).
 *
 * One place to call `api.php?action=…`: builds the URL, performs the request, and
 * returns the parsed JSON. CSRF is handled globally by csrf_head.php (it patches
 * window.fetch to attach X-CSRF-Token to same-origin writes), so this layer stays
 * focused on URL building + JSON/error handling. Load after csrf_head (which sets
 * window.API_BASE) and before the page script.
 *
 * Usage:
 *   const data = await FHApi.get('info', { id });          // GET  ?action=info&id=…
 *   const data = await FHApi.post('user_login', { … });    // POST JSON body
 *   const data = await FHApi.postForm('delete', formData); // POST multipart/form-data
 *   const url  = FHApi.url('download', { token });          // just the URL string
 *
 * Each call resolves to the parsed response object (success and error alike, since
 * the API returns JSON for both). Network failures and non-JSON responses reject —
 * callers keep their own try/catch, exactly as before.
 */
(function () {
	'use strict';

	function base() {
		return window.API_BASE
			|| (window.APP && window.APP.apiUrl)
			|| (window.PANEL && window.PANEL.apiUrl)
			|| '/api.php';
	}

	function buildUrl(action, params) {
		var url = base() + '?action=' + encodeURIComponent(action);
		if (params) {
			Object.keys(params).forEach(function (k) {
				var v = params[k];
				if (v === undefined || v === null) return;
				url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(v);
			});
		}
		return url;
	}

	async function parse(res) {
		// The API returns JSON for success and error alike; parse regardless of status.
		var text = await res.text();
		if (text === '') {
			return {};
		}
		try {
			return JSON.parse(text);
		} catch (e) {
			throw new Error('Non-JSON API response (HTTP ' + res.status + ')');
		}
	}

	function get(action, params, opts) {
		var init = Object.assign({ cache: 'no-store' }, opts || {});
		return fetch(buildUrl(action, params), init).then(parse);
	}

	function post(action, body, opts) {
		var init = Object.assign({
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(body || {})
		}, opts || {});
		return fetch(buildUrl(action), init).then(parse);
	}

	function postForm(action, formData, opts) {
		var init = Object.assign({ method: 'POST', body: formData }, opts || {});
		return fetch(buildUrl(action), init).then(parse);
	}

	/**
	 * Perform a browser navigation with POST. Used for provider checkouts: normal fetch()
	 * follows the redirect in the background, whereas a form hands the browser to the provider.
	 */
	function navigatePost(action, params) {
		var form = document.createElement('form');
		form.method = 'POST';
		form.action = buildUrl(action, params);
		form.style.display = 'none';

		var csrf = document.createElement('input');
		csrf.type = 'hidden';
		csrf.name = '_csrf';
		csrf.value = window.CSRF_TOKEN || '';
		form.appendChild(csrf);

		var key = document.createElement('input');
		key.type = 'hidden';
		key.name = '_idempotency_key';
		if (window.crypto && typeof window.crypto.randomUUID === 'function') {
			key.value = window.crypto.randomUUID();
		} else {
			var bytes = new Uint8Array(16);
			window.crypto.getRandomValues(bytes);
			key.value = Array.from(bytes, function (b) { return b.toString(16).padStart(2, '0'); }).join('');
		}
		form.appendChild(key);
		document.body.appendChild(form);
		form.submit();
	}

	window.FHApi = {
		get: get,
		post: post,
		postForm: postForm,
		navigatePost: navigatePost,
		url: buildUrl,
		base: base
	};
})();
