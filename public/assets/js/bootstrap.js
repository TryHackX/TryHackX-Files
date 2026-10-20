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

	/**
	 * Translate a key. `:name` placeholders are replaced in ONE pass, so a parameter called `n`
	 * can never eat the front of `:name` (the old sequential split/join turned
	 * "belonging to :name" into "belonging to 12ame" when both were supplied).
	 */
	window.t = function (key, params) {
		var value = (window.I18N && window.I18N[key]) || key;
		if (params) {
			value = value.replace(/:([A-Za-z_][A-Za-z0-9_]*)/g, function (match, name) {
				return Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : match;
			});
		}
		return value;
	};

	/**
	 * Web storage that never throws.
	 *
	 * With site data blocked (some privacy settings, sandboxed frames) merely *reading*
	 * `window.localStorage` raises SecurityError, which used to abort whole page scripts at load.
	 * Each area falls back to an in-memory map for the life of the page.
	 */
	function safeArea(name) {
		var memory = Object.create(null);
		function area() {
			try {
				return window[name] || null;
			} catch (error) {
				return null;
			}
		}
		return {
			get: function (key) {
				var store = area();
				if (store) {
					try {
						var value = store.getItem(key);
						if (value !== null && value !== undefined) return value;
					} catch (error) { /* fall through to memory */ }
				}
				return Object.prototype.hasOwnProperty.call(memory, key) ? memory[key] : null;
			},
			set: function (key, value) {
				var store = area();
				if (store) {
					try {
						store.setItem(key, String(value));
						return true;
					} catch (error) { /* fall through to memory */ }
				}
				memory[key] = String(value);
				return false;
			},
			remove: function (key) {
				delete memory[key];
				var store = area();
				if (store) {
					try { store.removeItem(key); } catch (error) { /* nothing to remove */ }
				}
			}
		};
	}
	window.FHStorage = { local: safeArea('localStorage'), session: safeArea('sessionStorage') };

	// The upload token is what the upload server attributes files by. The upload page keeps it
	// in sessionStorage and in memory; signing in or out changes whose token it must be, so the
	// sign-in and sign-out code goes through here and the upload page subscribes. Before, a
	// token survived sign-out and later uploads in the same tab went to the previous account.
	var uploadSessionListeners = [];
	function announceUploadSession(token, expiresAtMs) {
		uploadSessionListeners.forEach(function (listener) {
			try { listener(token, expiresAtMs); } catch (error) { /* one listener cannot stop the rest */ }
		});
	}
	window.FHUploadSession = {
		replace: function (token, expiresAtMs) {
			window.FHStorage.session.set('uploadToken', token);
			window.FHStorage.session.set('uploadTokenExpiry', String(expiresAtMs));
			announceUploadSession(token, expiresAtMs);
		},
		clear: function () {
			window.FHStorage.session.remove('uploadToken');
			window.FHStorage.session.remove('uploadTokenExpiry');
			announceUploadSession('', 0);
		},
		/**
		 * Same token, new owner: signing in hands the tab's upload session to the account, whose
		 * cap is not the guest's. Subscribers read the session again.
		 */
		refresh: function () {
			var token = window.FHStorage.session.get('uploadToken') || '';
			announceUploadSession(token, token ? Number(window.FHStorage.session.get('uploadTokenExpiry')) || 0 : 0);
		},
		current: function () {
			return window.FHStorage.session.get('uploadToken') || '';
		},
		subscribe: function (listener) {
			if (typeof listener === 'function') uploadSessionListeners.push(listener);
		}
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
