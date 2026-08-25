/**
 * FHCaptcha — one front-end API for the four supported providers.
 *
 * reCAPTCHA v2, reCAPTCHA v3, Cloudflare Turnstile and hCaptcha all expose an
 * explicit-render SDK with the same shape (`render(container, options)` returning a widget
 * id, plus `getResponse(id)` and `reset(id)`), so the pages that show a challenge do not
 * need to care which one the operator picked. They call FHCaptcha and get the grecaptcha
 * verbs they were already written against.
 *
 * reCAPTCHA v3 is the one that is genuinely different: no visible widget, and the token
 * comes from `execute()` rather than from someone clicking a checkbox. It is folded into the
 * same API by hiding the container and running execute() where the others would render —
 * the caller's `callback` still fires with a token, so no call site needs a special case.
 * Its tokens expire after two minutes, so reset() re-executes instead of clearing a widget.
 */
(function () {
	'use strict';

	if (window.FHCaptcha) return;

	var PROVIDERS = {
		recaptcha_v2: {
			sdk: 'grecaptcha',
			script: 'https://www.google.com/recaptcha/api.js',
			invisible: false
		},
		recaptcha_v3: {
			sdk: 'grecaptcha',
			script: 'https://www.google.com/recaptcha/api.js',
			invisible: true
		},
		turnstile: {
			sdk: 'turnstile',
			script: 'https://challenges.cloudflare.com/turnstile/v0/api.js',
			invisible: false
		},
		hcaptcha: {
			sdk: 'hcaptcha',
			script: 'https://js.hcaptcha.com/1/api.js',
			invisible: false
		}
	};

	var DEFAULT_PROVIDER = 'recaptcha_v2';

	var state = {
		provider: DEFAULT_PROVIDER,
		siteKey: '',
		action: 'submit',
		loading: false,
		loaded: false,
		readyQueue: []
	};

	/* v3 has no widget object of its own, so we keep our own registry of pseudo-widgets. */
	var v3Widgets = Object.create(null);
	var v3NextId = 1;

	function spec() {
		return PROVIDERS[state.provider] || PROVIDERS[DEFAULT_PROVIDER];
	}

	function sdk() {
		return window[spec().sdk];
	}

	function isInvisible() {
		return spec().invisible === true;
	}

	/**
	 * Point the module at a provider and site key. Safe to call repeatedly; the config
	 * usually arrives from `api.php?action=config`, which every caller fetches anyway.
	 */
	function configure(config) {
		if (!config) return;
		var provider = config.captcha_provider || config.captchaProvider;
		if (provider && PROVIDERS[provider]) state.provider = provider;
		var siteKey = config.recaptcha_site_key || config.captchaSiteKey || config.siteKey;
		if (typeof siteKey === 'string') state.siteKey = siteKey;
		var action = config.captcha_action || config.captchaAction;
		if (typeof action === 'string' && action) state.action = action;
		return state.provider;
	}

	function provider() {
		return state.provider;
	}

	function siteKey() {
		return state.siteKey;
	}

	function isReady() {
		var api = sdk();
		return !!(api && (api.render || api.execute));
	}

	function flushReady() {
		state.loaded = true;
		state.loading = false;
		var queued = state.readyQueue.slice();
		state.readyQueue.length = 0;
		queued.forEach(function (fn) {
			try {
				fn();
			} catch (error) {
				console.error('Captcha ready handler failed:', error);
			}
		});
	}

	/**
	 * Load the provider SDK once and call `onReady` when it can render.
	 *
	 * v3 wants the site key baked into the loader URL (`?render=KEY`); the other three are
	 * loaded in explicit-render mode so nothing is auto-rendered behind our back.
	 */
	function load(onReady) {
		if (typeof onReady === 'function') {
			if (isReady()) {
				onReady();
				return;
			}
			state.readyQueue.push(onReady);
		}
		if (isReady()) {
			flushReady();
			return;
		}
		if (state.loading) return;
		if (!state.siteKey) return;

		state.loading = true;
		var url = spec().script;
		var script = document.createElement('script');
		script.src = isInvisible()
			? url + '?render=' + encodeURIComponent(state.siteKey)
			: url + '?onload=__fhCaptchaOnLoad&render=explicit';
		script.async = true;
		script.defer = true;
		script.addEventListener('error', function () {
			state.loading = false;
			console.error('Captcha SDK failed to load:', script.src);
		});

		if (isInvisible()) {
			// No onload callback in this mode: grecaptcha.ready() is the documented hook and
			// it fires whether or not we were listening when the script finished.
			script.addEventListener('load', function () {
				var api = sdk();
				if (api && typeof api.ready === 'function') {
					api.ready(flushReady);
				} else {
					flushReady();
				}
			});
		}
		document.head.appendChild(script);
	}

	/* Explicit-render loaders call this global; all three use the same entry point. */
	window.__fhCaptchaOnLoad = function () {
		flushReady();
	};

	function fireError(options) {
		var handler = options && (options['error-callback'] || options.errorCallback);
		if (typeof handler === 'function') handler();
	}

	function executeInvisible(id, options) {
		var api = sdk();
		if (!api || typeof api.execute !== 'function') {
			fireError(options);
			return;
		}
		var run = function () {
			try {
				var result = api.execute(state.siteKey, { action: state.action });
				if (!result || typeof result.then !== 'function') {
					fireError(options);
					return;
				}
				result.then(function (token) {
					v3Widgets[id] = token || '';
					if (!token) {
						fireError(options);
						return;
					}
					if (typeof options.callback === 'function') options.callback(token);
				}, function () {
					v3Widgets[id] = '';
					fireError(options);
				});
			} catch (error) {
				console.error('Captcha execute failed:', error);
				fireError(options);
			}
		};
		if (typeof api.ready === 'function') {
			api.ready(run);
		} else {
			run();
		}
	}

	/**
	 * Render a challenge into `container` (an id or an element).
	 *
	 * Returns a widget handle to hand back to getResponse()/reset(), or null when the SDK is
	 * not up yet — callers already treat a null id as "not rendered".
	 */
	function render(container, options) {
		options = options || {};
		var element = typeof container === 'string' ? document.getElementById(container) : container;
		if (!element || !state.siteKey) return null;

		if (isInvisible()) {
			// Nothing to click: keep the placeholder out of the layout and fetch a token.
			element.style.display = 'none';
			var id = 'v3-' + (v3NextId++);
			v3Widgets[id] = '';
			executeInvisible(id, options);
			return id;
		}

		var api = sdk();
		if (!api || typeof api.render !== 'function') return null;

		var params = {
			sitekey: state.siteKey,
			theme: options.theme || 'dark'
		};
		if (typeof options.callback === 'function') params.callback = options.callback;
		if (typeof options['error-callback'] === 'function') {
			params['error-callback'] = options['error-callback'];
		}
		if (state.provider === 'turnstile' && state.action) params.action = state.action;

		try {
			return api.render(element, params);
		} catch (error) {
			console.error('Captcha render error:', error);
			return null;
		}
	}

	/** The solved token for a widget, or '' when there is none yet. */
	function getResponse(id) {
		if (id === null || id === undefined) return '';
		if (typeof id === 'string' && id.indexOf('v3-') === 0) return v3Widgets[id] || '';
		var api = sdk();
		if (!api || typeof api.getResponse !== 'function') return '';
		try {
			return api.getResponse(id) || '';
		} catch (error) {
			return '';
		}
	}

	/** Clear a widget so the visitor can try again (v3: fetch a fresh token). */
	function reset(id, options) {
		if (id === null || id === undefined) return;
		if (typeof id === 'string' && id.indexOf('v3-') === 0) {
			v3Widgets[id] = '';
			executeInvisible(id, options || {});
			return;
		}
		var api = sdk();
		if (!api || typeof api.reset !== 'function') return;
		try {
			api.reset(id);
		} catch (error) {
			/* A widget the SDK has already forgotten is nothing to report. */
		}
	}

	window.FHCaptcha = Object.freeze({
		configure: configure,
		provider: provider,
		siteKey: siteKey,
		isInvisible: isInvisible,
		isReady: isReady,
		load: load,
		render: render,
		getResponse: getResponse,
		reset: reset
	});
}());
