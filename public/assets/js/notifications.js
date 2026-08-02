/**
 * The notification bell.
 *
 * Loaded on every page that renders the shared header, panel included, so it has to stand on
 * its own: it uses only FHApi (api.js) and t() (i18n_head), keeps everything inside an IIFE and
 * exports exactly one symbol for the panel's history page to reuse.
 *
 * The popover is positioned by CSS anchored to the button rather than by a positioning library:
 * it is one element, always attached to the same trigger, and the only clever part — staying on
 * screen on a narrow viewport — is a media query. Pulling in Popper for that would be more code
 * shipped than the whole feature.
 */
(function () {
	'use strict';

	var btn = document.getElementById('notifBtn');
	var pop = document.getElementById('notifPop');
	var badge = document.getElementById('notifBadge');
	var list = document.getElementById('notifList');
	if (!btn || !pop || !list) return;   // signed out — the include rendered nothing

	/** How often the badge re-checks while the tab is visible. */
	var POLL_MS = 60000;
	var pollTimer = null;
	var loading = false;

	function setBadge(n) {
		if (!badge) return;
		badge.textContent = n > 99 ? '99+' : String(n);
		badge.hidden = !n;
		btn.classList.toggle('has-unread', !!n);
	}

	/** "3 min temu" — relative for the recent stuff, a date once that stops being useful. */
	function ago(ts) {
		var s = Math.max(0, Math.floor(Date.now() / 1000) - ts);
		if (s < 60) return t('notif.ago_now');
		if (s < 3600) return t('notif.ago_min', { n: Math.floor(s / 60) });
		if (s < 86400) return t('notif.ago_hour', { n: Math.floor(s / 3600) });
		if (s < 7 * 86400) return t('notif.ago_day', { n: Math.floor(s / 86400) });
		return new Date(ts * 1000).toLocaleDateString();
	}

	function makeNode(tag, className, text) {
		var node = document.createElement(tag);
		if (className) node.className = className;
		if (text !== undefined) node.textContent = String(text);
		return node;
	}

	function safeLink(value) {
		if (!value) return '';
		try {
			var url = new URL(String(value), window.location.origin);
			return url.origin === window.location.origin
				&& (url.protocol === 'http:' || url.protocol === 'https:')
				? url.href
				: '';
		} catch (error) {
			return '';
		}
	}

	/**
	 * One row. The repeat count is a badge rather than "[x30]" inside the sentence: it stays
	 * legible when the sentence is long, and it does not have to be translated.
	 */
	function itemElement(n) {
		var cls = 'notif-item' + (n.unread ? ' is-unread' : '');
		var link = safeLink(n.link);
		var row = makeNode(link ? 'a' : 'div', cls);
		if (link) row.href = link;
		row.dataset.id = String(Number(n.id) || 0);

		var iconName = /^fa-[a-z0-9-]+$/i.test(String(n.icon || ''))
			? String(n.icon)
			: 'fa-circle-info';
		var icon = makeNode('i', 'fa-solid ' + iconName + ' notif-icon');
		icon.setAttribute('aria-hidden', 'true');
		var text = makeNode('span', 'notif-text');
		var message = makeNode('span', 'notif-msg', n.message);
		if (Number(n.count) > 1) {
			message.appendChild(makeNode('span', 'notif-count', '×' + Number(n.count)));
		}
		text.append(
			message,
			makeNode('span', 'notif-meta', String(n.title || '') + ' · ' + ago(Number(n.at) || 0))
		);
		row.append(icon, text);
		return row;
	}

	async function refreshCount() {
		try {
			var d = await FHApi.get('notification_count');
			// `fresh` = unread arrivals since the popover was last opened; the badge is a
			// "something new" light, not a to-do counter (runda 4, pt 4).
			if (d && d.success) setBadge(d.fresh != null ? d.fresh : (d.unread || 0));
		} catch (e) { /* offline or signed out — the badge simply stops moving */ }
	}

	async function loadList() {
		if (loading) return;
		loading = true;
		try {
			var d = await FHApi.get('notifications', { scope: 'bell' });
			if (!d || !d.success) throw new Error('bad response');
			setBadge(d.fresh != null ? d.fresh : (d.unread || 0));
			list.replaceChildren(...(
				d.items.length
					? d.items.map(itemElement)
					: [makeNode('div', 'notif-empty', t('notif.empty'))]
			));
		} catch (e) {
			list.replaceChildren(
				makeNode('div', 'notif-empty', t('common.connection_error'))
			);
		} finally {
			loading = false;
		}
	}

	function open() {
		pop.hidden = false;
		btn.setAttribute('aria-expanded', 'true');
		loadList();
		// Opening the popover means the account has SEEN what's new — the badge goes out,
		// but nothing is marked read: the rows keep their unread styling until clicked.
		FHApi.post('notification_seen', {})
			.then(function (d) { if (d && d.success) setBadge(0); })
			.catch(function () { });
	}

	function close() {
		pop.hidden = true;
		btn.setAttribute('aria-expanded', 'false');
	}

	btn.addEventListener('click', function (e) {
		e.preventDefault();
		if (pop.hidden) open(); else close();
	});

	// Clicking a row follows its link, and on the way marks that one read — the click is the
	// act of dealing with it, so nothing else has to be pressed.
	list.addEventListener('click', function (e) {
		var row = e.target.closest('[data-id]');
		if (!row || !row.classList.contains('is-unread')) return;
		row.classList.remove('is-unread');
		FHApi.post('notification_read', { ids: [Number(row.dataset.id)] })
			.then(function () { refreshCount(); }) // the badge tracks fresh, not unread
			.catch(function () { });
	});

	var readAll = document.getElementById('notifReadAll');
	if (readAll) {
		readAll.addEventListener('click', async function () {
			try {
				var d = await FHApi.post('notification_read', { all: true });
				if (d && d.success) {
					setBadge(0);
					list.querySelectorAll('.is-unread').forEach(function (el) { el.classList.remove('is-unread'); });
				}
			} catch (e) { /* leave the list as it is */ }
		});
	}

	document.addEventListener('click', function (e) {
		if (!pop.hidden && !e.target.closest('#notifWrap')) close();
	});
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && !pop.hidden) { close(); btn.focus(); }
	});

	// Polling stops with the tab: a background tab asking every minute forever is the kind of
	// thing that shows up on someone's battery, and there is nothing to see while it is hidden.
	function startPolling() {
		stopPolling();
		pollTimer = setInterval(refreshCount, POLL_MS);
	}
	function stopPolling() {
		if (pollTimer) clearInterval(pollTimer);
		pollTimer = null;
	}
	document.addEventListener('visibilitychange', function () {
		if (document.hidden) {
			stopPolling();
		} else {
			refreshCount();
			startPolling();
		}
	});

	refreshCount();
	startPolling();

	// The panel's history page shows the same rows and wants the same wording.
	window.FHNotify = {
		itemElement: itemElement,
		ago: ago,
		refreshCount: refreshCount,
		setBadge: setBadge
	};
})();
