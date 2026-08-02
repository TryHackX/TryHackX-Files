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

	document.addEventListener('click', function (event) {
		var trigger = event.target.closest ? event.target.closest('[data-fh-action]') : null;
		if (!trigger) return;
		if (trigger.getAttribute('data-fh-action') === 'toggle-theme'
			&& typeof window.toggleTheme === 'function') {
			window.toggleTheme();
		} else if (trigger.getAttribute('data-fh-action') === 'open-auth'
			&& typeof window.showAuthModal === 'function') {
			window.showAuthModal();
		}
	});

	window.FHUi = window.FHUi || {};
	window.FHUi.toast = toast;
	window.FHUi.dismissLastToast = dismissLast;
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
 */
(function () {
	'use strict';

	// Attributes that hold user-visible copy. `value` is deliberately absent: it would
	// overwrite what someone has typed.
	var TEXT_ATTRS = ['placeholder', 'title', 'aria-label', 'alt'];
	var SKIP_TAGS = { SCRIPT: 1, STYLE: 1, TEXTAREA: 1 };

	function sync(live, fresh) {
		if (live.nodeType === 3 && fresh.nodeType === 3) {
			if (live.nodeValue !== fresh.nodeValue) {
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

		// Different shape = a subtree the page rendered itself. Leave it alone.
		if (live.childNodes.length !== fresh.childNodes.length) return;
		for (var i = 0; i < live.childNodes.length; i++) {
			sync(live.childNodes[i], fresh.childNodes[i]);
		}
	}

	function apply(html, href) {
		var doc = new DOMParser().parseFromString(html, 'text/html');
		var data = doc.getElementById('i18n-data');
		if (!data || !doc.body) throw new Error('unexpected response');

		// New dictionary first, so anything re-rendered afterwards is already translated.
		window.I18N = JSON.parse(data.textContent);
		window.LANG = doc.documentElement.getAttribute('lang') || window.LANG;
		document.documentElement.setAttribute('lang', window.LANG);

		sync(document.body, doc.body);

		// The switcher's own active state and links are pure server output — take them whole.
		var freshSwitch = doc.querySelector('.lang-switch');
		var liveSwitch = document.querySelector('.lang-switch');
		if (freshSwitch && liveSwitch) liveSwitch.innerHTML = freshSwitch.innerHTML;

		var title = doc.querySelector('title');
		if (title) document.title = title.textContent;

		// Drop ?lang= from the address bar: the choice now lives in the cookie, and a reload
		// should not look like a fresh switch.
		try {
			var url = new URL(href, window.location.href);
			url.searchParams.delete('lang');
			window.history.replaceState({}, '', url.pathname + url.search + url.hash);
		} catch (e) { /* address bar is cosmetic here */ }

		document.dispatchEvent(new CustomEvent('fh:languagechange', { detail: { lang: window.LANG } }));
	}

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
