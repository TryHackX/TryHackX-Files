/**
 * Executable page bootstrap kept out of PHP templates so script-src does not need
 * unsafe-inline. Tokens and translations stay in inert meta/JSON elements.
 */
(function () {
	'use strict';

	var csrfMeta = document.querySelector('meta[name="csrf-token"]');
	var apiMeta = document.querySelector('meta[name="api-base"]');
	var token = csrfMeta ? csrfMeta.getAttribute('content') || '' : '';
	window.CSRF_TOKEN = token;
	window.API_BASE = apiMeta ? apiMeta.getAttribute('content') || '/api.php' : '/api.php';

	var data = document.getElementById('i18n-data');
	window.LANG = document.documentElement.getAttribute('lang') || 'en';
	window.I18N = data ? JSON.parse(data.textContent) : {};
	window.t = function (key, params) {
		var value = (window.I18N && window.I18N[key]) || key;
		if (params) {
			for (var name in params) {
				if (Object.prototype.hasOwnProperty.call(params, name)) {
					value = value.split(':' + name).join(params[name]);
				}
			}
		}
		return value;
	};

	if (window.fetch && !window.fetch.__fhCsrfWrapped) {
		var originalFetch = window.fetch;
		var wrappedFetch = function (input, init) {
			init = init || {};
			var method = (
				init.method
				|| (input && typeof input === 'object' && input.method)
				|| 'GET'
			).toUpperCase();

			if (method !== 'GET' && method !== 'HEAD') {
				var url = typeof input === 'string' ? input : (input && input.url) || '';
				var sameOrigin = false;
				try {
					sameOrigin = url === ''
						|| new URL(url, window.location.href).origin === window.location.origin;
				} catch (error) {
					sameOrigin = false;
				}
				if (sameOrigin) {
					var headers = new Headers(
						init.headers
						|| (input && typeof input === 'object' && input.headers)
						|| {}
					);
					if (!headers.has('X-CSRF-Token')) headers.set('X-CSRF-Token', token);
					init.headers = headers;
				}
			}
			return originalFetch.call(this, input, init);
		};
		wrappedFetch.__fhCsrfWrapped = true;
		window.fetch = wrappedFetch;
	}
})();
