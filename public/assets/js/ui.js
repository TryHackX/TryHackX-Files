/**
 * Shared UI helpers (Faza 5 · #3).
 *
 * `FHUi.toast(message, options)` is the one toast renderer for public pages and the panel.
 * Independent messages stack; a stable `key` coalesces noisy autosave bursts, while `target`
 * anchors feedback at the control that caused it. Content is always assigned as text.
 *
 * Load after csrf_head and before the page script.
 */
(function () {
	'use strict';

	var MAX_VISIBLE = 4;
	var stack = null;
	var activeItems = [];

	function template(explicitId) {
		return (explicitId && document.getElementById(explicitId))
			|| document.getElementById('toast')
			|| document.getElementById('dlToast')
			|| document.getElementById('notification');
	}

	function ensureStack() {
		if (stack && stack.isConnected) return stack;
		stack = document.createElement('div');
		stack.className = 'toast-stack';
		// Added at run time, so the live language switch must not expect it in a fresh page.
		stack.setAttribute('data-fh-runtime', '');
		document.body.appendChild(stack);
		return stack;
	}

	function removeItem(item, immediate) {
		if (!item) return;
		if (item._fhToastTimer) {
			clearTimeout(item._fhToastTimer);
			item._fhToastTimer = null;
		}
		if (item._fhToastCleanup) {
			item._fhToastCleanup();
			item._fhToastCleanup = null;
		}
		item.classList.remove('show');
		var index = activeItems.indexOf(item);
		if (index !== -1) activeItems.splice(index, 1);
		var detach = function () {
			if (item.parentNode) item.parentNode.removeChild(item);
		};
		if (immediate) detach();
		else setTimeout(detach, 350);
	}

	function textNode(item) {
		return item.querySelector('[id$="Text"]')
			|| item.querySelector('[data-toast-text]')
			|| item;
	}

	function applyType(item, type) {
		['success', 'error', 'copy', 'info'].forEach(function (name) {
			item.classList.remove(name);
		});
		item.classList.add(type);
		item.setAttribute('role', type === 'error' ? 'alert' : 'status');
		item.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');
		item.setAttribute('aria-atomic', 'true');
		item.setAttribute('tabindex', '0');

		var icon = item.querySelector('.modern-notification-icon');
		if (icon) {
			var icons = {
				success: 'fa-circle-check',
				error: 'fa-circle-xmark',
				copy: 'fa-copy',
				info: 'fa-circle-info'
			};
			while (icon.firstChild) icon.removeChild(icon.firstChild);
			var glyph = document.createElement('i');
			glyph.className = 'fa-solid ' + (icons[type] || icons.info);
			glyph.setAttribute('aria-hidden', 'true');
			icon.appendChild(glyph);
		}
	}

	function anchoredPosition(item, target) {
		if (!target || !target.isConnected) {
			removeItem(item);
			return;
		}
		var bounds = target.getBoundingClientRect();
		if (bounds.bottom < 0 || bounds.top > window.innerHeight
			|| bounds.right < 0 || bounds.left > window.innerWidth) {
			removeItem(item);
			return;
		}
		var left = bounds.left + bounds.width / 2 - item.offsetWidth / 2;
		var top = bounds.top - item.offsetHeight - 8;
		left = Math.max(5, Math.min(left, window.innerWidth - item.offsetWidth - 5));
		if (top < 5) top = bounds.bottom + 8;
		item.style.left = left + 'px';
		item.style.top = top + 'px';
	}

	function scheduleRemoval(item, duration) {
		if (item._fhToastTimer) clearTimeout(item._fhToastTimer);
		item._fhToastTimer = setTimeout(function () {
			removeItem(item);
		}, duration);
	}

	function toast(message, opts) {
		// Older call sites pass the type as a bare string — toast(msg, 'error') — and some a
		// third options object. Both still mean what they say instead of silently becoming "info".
		if (typeof opts === 'string') opts = { type: opts };
		opts = opts || {};
		var tpl = template(opts.el);
		if (!tpl) return;

		var type = ['success', 'error', 'copy', 'info'].indexOf(opts.type) !== -1
			? opts.type
			: 'info';
		var key = typeof opts.key === 'string' ? opts.key.slice(0, 80) : '';
		var duration = Number(opts.duration) > 0
			? Number(opts.duration)
			: (type === 'error' ? 4200 : 2600);

		if (key) {
			for (var existingIndex = 0; existingIndex < activeItems.length; existingIndex++) {
				var existing = activeItems[existingIndex];
				if (existing.getAttribute('data-toast-key') === key && existing.parentNode) {
					textNode(existing).textContent = String(message);
					applyType(existing, type);
					scheduleRemoval(existing, duration);
					return existing;
				}
			}
		}

		var item = tpl.cloneNode(true);
		item.classList.remove('show');
		item.classList.add('toast-item');
		item.setAttribute('data-fh-runtime', '');
		var itemText = textNode(item);
		itemText.setAttribute('data-toast-text', '');
		itemText.textContent = String(message);
		applyType(item, type);
		if (key) item.setAttribute('data-toast-key', key);
		item.removeAttribute('id');
		Array.prototype.forEach.call(item.querySelectorAll('[id]'), function (el) {
			el.removeAttribute('id');
		});
		item.addEventListener('keydown', function (event) {
			if (event.key === 'Escape') removeItem(item);
		});

		var host = opts.target ? document.body : ensureStack();
		host.appendChild(item);
		activeItems.push(item);

		if (opts.target) {
			item.classList.add('floating-mode');
			item.style.left = '-9999px';
			item.style.top = '-9999px';
			item.classList.add('show');
			anchoredPosition(item, opts.target);
			var followTarget = function () {
				if (item.parentNode) anchoredPosition(item, opts.target);
			};
			window.addEventListener('scroll', followTarget, { passive: true, capture: true });
			window.addEventListener('resize', followTarget, { passive: true });
			item._fhToastCleanup = function () {
				window.removeEventListener('scroll', followTarget, { capture: true });
				window.removeEventListener('resize', followTarget);
			};
		} else {
			while (host.children.length > MAX_VISIBLE) removeItem(host.firstChild, true);
			requestAnimationFrame(function () {
				item.classList.add('show');
			});
		}

		scheduleRemoval(item, duration);
		return item;
	}

	function dismissLast() {
		removeItem(activeItems[activeItems.length - 1]);
	}

	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape' && activeItems.length) dismissLast();
	});

	/**
	 * The one theme switch. The `theme` cookie is the single source of truth: the server reads it
	 * to render `<body class="light">`, so nothing has to be re-applied from storage on load
	 * (which is what used to flip the page back after the panel changed only the cookie).
	 */
	function toggleTheme() {
		document.body.classList.toggle('light');
		var theme = document.body.classList.contains('light') ? 'light' : 'dark';
		document.cookie = 'theme=' + theme + '; path=/; max-age=31536000; SameSite=Lax';
		// Older builds also kept a copy in localStorage and let it override the cookie.
		if (window.FHStorage) window.FHStorage.local.remove('theme');
		syncThemeIcons();
		return theme;
	}

	/** Sun in the dark theme, moon in the light one — not every stylesheet carries that rule. */
	function syncThemeIcons() {
		if (!document.body || typeof document.querySelectorAll !== 'function') return;
		var light = document.body.classList.contains('light');
		Array.prototype.forEach.call(document.querySelectorAll('.sun'), function (el) {
			el.style.display = light ? 'none' : 'block';
		});
		Array.prototype.forEach.call(document.querySelectorAll('.moon'), function (el) {
			el.style.display = light ? 'block' : 'none';
		});
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', syncThemeIcons);
	} else {
		syncThemeIcons();
	}

	/**
	 * Run `task` unless the same `key` is already running, and mark `control` busy meanwhile.
	 *
	 * The API does not de-duplicate writes, so a double click on "Create" used to create two
	 * collections / API keys / reports. Returns the task's promise, or a resolved `undefined`
	 * when the call was swallowed.
	 */
	var inFlight = Object.create(null);
	function singleFlight(key, task, control) {
		if (inFlight[key]) return Promise.resolve(undefined);
		inFlight[key] = true;
		var target = control && control.nodeType === 1 ? control : null;
		if (!target && typeof document !== 'undefined') {
			var active = document.activeElement;
			if (active && active.tagName === 'BUTTON') target = active;
		}
		var wasDisabled = target ? target.disabled : false;
		if (target) {
			target.disabled = true;
			target.setAttribute('aria-busy', 'true');
		}
		var release = function () {
			delete inFlight[key];
			if (target) {
				target.disabled = wasDisabled;
				target.removeAttribute('aria-busy');
			}
		};
		var result;
		try {
			result = Promise.resolve(task());
		} catch (error) {
			release();
			return Promise.reject(error);
		}
		return result.then(
			function (value) { release(); return value; },
			function (error) { release(); throw error; }
		);
	}

	document.addEventListener('click', function (event) {
		var trigger = event.target.closest ? event.target.closest('[data-fh-action]') : null;
		if (!trigger) return;
		if (trigger.getAttribute('data-fh-action') === 'toggle-theme') {
			toggleTheme();
		} else if (trigger.getAttribute('data-fh-action') === 'open-auth'
			&& typeof window.showAuthModal === 'function') {
			window.showAuthModal();
		}
	});

	window.FHUi = window.FHUi || {};
	window.FHUi.toast = toast;
	window.FHUi.dismissLastToast = dismissLast;
	window.FHUi.toggleTheme = toggleTheme;
	window.FHUi.singleFlight = singleFlight;
	// Kept as a global for the panel's command palette and its declarative actions.
	window.toggleTheme = toggleTheme;
})();

/**
 * Live language switching (pkt 1).
 *
 * Clicking a language in the header used to reload the whole page. It now fetches the same URL
 * with `?lang=…`, and copies the translated text out of the response into the page that is
 * already open — so the upload queue, an open modal, scroll position and every bound event
 * handler survive the switch.
 *
 * Why copying text rather than swapping nodes: every page here is server-rendered, and the
 * scripts bind listeners to specific elements at load (drop zone, file input, search boxes).
 * Replacing those nodes would silently break them. Walking both trees in lockstep and writing
 * only text nodes and the handful of attributes that carry copy leaves the DOM identity — and
 * therefore the listeners — untouched.
 *
 * Where the two trees disagree the subtree is skipped rather than forced: the live page has
 * JS-rendered tables the freshly fetched one still shows as "Loading…", and those must not be
 * clobbered. They pick the new language up on their next refresh, because `window.I18N` is
 * swapped too. Anything unexpected falls back to a normal navigation, which always works.
 *
 * Nodes added at run time — the toast stack, the copy tip, the password prompt (all marked
 * `data-fh-runtime`), captcha and ad frames, whatever a browser extension inserts — exist only
 * in the live page. They are passed over when the two trees are lined up; comparing raw child
 * counts used to make the whole <body> look "different" after the first toast, so nothing below
 * it was translated any more.
 */
(function () {
	'use strict';

	// Attributes that hold user-visible copy. `value` is deliberately absent: it would
	// overwrite what someone has typed.
	var TEXT_ATTRS = ['placeholder', 'title', 'aria-label', 'alt'];
	// Walked into, never through: their content is code.
	var SKIP_TAGS = { SCRIPT: 1, STYLE: 1 };
	// Their copy attributes (a placeholder) follow the language; their content never does: it is
	// what someone typed, or the default that typing replaces.
	var VALUE_TAGS = { TEXTAREA: 1 };
	// Not part of the comparison at all. The server puts no copy in them, and third-party
	// widgets and the browser add them at run time.
	var IGNORED_TAGS = { SCRIPT: 1, STYLE: 1, LINK: 1, NOSCRIPT: 1, TEMPLATE: 1, IFRAME: 1, INS: 1 };

	/** Does this child belong to the server-rendered shape the two trees are compared on? */
	function comparable(node) {
		if (node.nodeType === 3) return /\S/.test(node.nodeValue || '');
		if (node.nodeType !== 1 || IGNORED_TAGS[node.nodeName]) return false;
		return !node.hasAttribute('data-fh-runtime');
	}

	/**
	 * What a child is, for lining it up: element, id and leading class (scripts only append
	 * classes). An id a script gave the node after load (`data-fh-runtime-id`: the dialog
	 * headings named for aria-labelledby) is not in the server's copy, so it does not count.
	 */
	function identity(node) {
		if (node.nodeType === 3) return '#text';
		var leading = String(node.getAttribute('class') || '').trim().split(/\s+/)[0];
		var id = node.hasAttribute('data-fh-runtime-id') ? '' : (node.getAttribute('id') || '');
		return node.nodeName + '#' + id + '.' + leading;
	}

	/**
	 * Pair the live children with the freshly rendered ones: [[live, fresh], …], or null when the
	 * two cannot be lined up.
	 *
	 * Same count: in order, as the page rendered them (a pair whose identity differs is left out
	 * rather than written into the wrong element). Different counts: the shorter list has to fit
	 * into the longer one in order — something was added at run time, or a script removed a
	 * node — and the leftovers are passed over. Anything else is a subtree the page rendered
	 * itself.
	 */
	function align(live, fresh) {
		var liveKids = Array.prototype.filter.call(live.childNodes, comparable);
		var freshKids = Array.prototype.filter.call(fresh.childNodes, comparable);
		var pairs = [];
		var i;
		if (liveKids.length === freshKids.length) {
			for (i = 0; i < liveKids.length; i++) {
				if (identity(liveKids[i]) === identity(freshKids[i])) pairs.push([liveKids[i], freshKids[i]]);
			}
			return pairs;
		}
		var liveLonger = liveKids.length > freshKids.length;
		var shorter = liveLonger ? freshKids : liveKids;
		var longer = liveLonger ? liveKids : freshKids;
		var at = 0;
		for (i = 0; i < shorter.length; i++) {
			var wanted = identity(shorter[i]);
			while (at < longer.length && identity(longer[at]) !== wanted) at++;
			if (at === longer.length) return null;
			pairs.push(liveLonger ? [longer[at], shorter[i]] : [shorter[i], longer[at]]);
			at++;
		}
		return pairs;
	}

	/**
	 * "Loading…" is only ever a placeholder. Whatever the live page shows in its place by now —
	 * a table, or "nothing here" — is newer than that text, so it is never copied over.
	 */
	function isPlaceholder(text) {
		var loading = window.I18N && window.I18N['common.loading'];
		return Boolean(loading) && String(text).trim() === loading;
	}

	function sync(live, fresh) {
		if (live.nodeType === 3 && fresh.nodeType === 3) {
			if (live.nodeValue !== fresh.nodeValue && !isPlaceholder(fresh.nodeValue)) {
				live.nodeValue = fresh.nodeValue;
			}
			return;
		}
		if (live.nodeType !== 1 || fresh.nodeType !== 1) return;
		if (live.nodeName !== fresh.nodeName || SKIP_TAGS[live.nodeName]) return;

		TEXT_ATTRS.forEach(function (a) {
			if (fresh.hasAttribute(a) && live.getAttribute(a) !== fresh.getAttribute(a)) {
				live.setAttribute(a, fresh.getAttribute(a));
			}
		});
		if (VALUE_TAGS[live.nodeName]) return;

		// No pairing = a subtree the page rendered itself. Leave it alone.
		var pairs = align(live, fresh);
		if (!pairs) return;
		pairs.forEach(function (pair) { sync(pair[0], pair[1]); });
	}

	/**
	 * Accessible names the page derived from a `title` at load (icon-only buttons) are not in
	 * the fetched page, so they are derived again from the title that has just been translated.
	 */
	function refreshDerivedLabels() {
		Array.prototype.forEach.call(document.querySelectorAll('[data-fh-aria-from-title]'), function (el) {
			var title = el.getAttribute('title');
			if (title) el.setAttribute('aria-label', title);
		});
	}

	function apply(html, href) {
		var doc = new DOMParser().parseFromString(html, 'text/html');
		var data = doc.getElementById('i18n-data');
		if (!data || !doc.body) throw new Error('unexpected response');
		// The top level of every page is server output. If even that cannot be lined up, the
		// caller falls back to a normal navigation — before anything here was changed.
		var top = align(document.body, doc.body);
		if (!top) throw new Error('page structure changed');

		// New dictionary first, so anything re-rendered afterwards is already translated.
		window.I18N = JSON.parse(data.textContent);
		window.LANG = doc.documentElement.getAttribute('lang') || window.LANG;
		document.documentElement.setAttribute('lang', window.LANG);

		top.forEach(function (pair) { sync(pair[0], pair[1]); });
		refreshDerivedLabels();

		// The switcher's own active state and links are pure server output — take them whole.
		var freshSwitch = doc.querySelector('.lang-switch');
		var liveSwitch = document.querySelector('.lang-switch');
		if (freshSwitch && liveSwitch) liveSwitch.innerHTML = freshSwitch.innerHTML;

		var title = doc.querySelector('title');
		if (title) document.title = title.textContent;

		// Drop ?lang= from the address bar: the choice now lives in the cookie, and a reload
		// should not look like a fresh switch. A password-reset token (and the action that
		// opens its form) never goes back into the address bar either: auth.js removed it on
		// purpose, so the link cannot be shared or kept in history by accident.
		try {
			var url = new URL(href, window.location.href);
			['lang', 'token', 'action'].forEach(function (name) { url.searchParams.delete(name); });
			window.history.replaceState({}, '', url.pathname + url.search + url.hash);
		} catch (e) { /* address bar is cosmetic here */ }

		document.dispatchEvent(new CustomEvent('fh:languagechange', { detail: { lang: window.LANG } }));
	}

	window.FHLang = Object.freeze({ align: align, sync: sync, apply: apply });

	document.addEventListener('click', function (e) {
		var link = e.target.closest ? e.target.closest('.lang-switch .lang-opt') : null;
		if (!link || e.metaKey || e.ctrlKey || e.shiftKey || e.button > 0) return;
		if (link.classList.contains('active')) { e.preventDefault(); return; }

		e.preventDefault();
		var href = link.getAttribute('href');
		fetch(href, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
			.then(function (r) {
				if (!r.ok) throw new Error('HTTP ' + r.status);
				return r.text();
			})
			.then(function (html) { apply(html, href); })
			.catch(function () { window.location.href = href; });
	});
})();
