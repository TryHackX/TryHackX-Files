'use strict';

/**
 * Counts in the panel's pagers and the bell: the labels carry their own number, so Polish does
 * not print "3 plików" / "‹ powiadomień ›", and relative times come in the browser's own words.
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const { createMiniDocument } = require('./support/mini-dom');

const jsDir = path.resolve(__dirname, '../../public/assets/js');
const read = name => fs.readFileSync(path.join(jsDir, name), 'utf8');

test('the notification history pager says which page of how many, and names its arrows', async () => {
	const document = createMiniDocument();
	document.add('div', 'notifPageList');
	const pager = document.add('div', 'notifPagination');
	const window = {
		t: (key, params) => (params ? key + ' ' + JSON.stringify(params) : key),
		FHApi: { get: async () => ({ success: true, unread: 0, total: 87, items: [{ id: 1 }] }) },
		FHNotify: { itemElement: () => document.createElement('div'), setBadge() {} },
		showNotification() {}
	};
	window.window = window;
	vm.runInNewContext(read('panel-notifications.js'), { window, document, console, Number, Math, String, Boolean });

	await window.FHPanelNotifications.loadNotifications(2);
	const [previous, label, next] = pager.children;
	assert.equal(label.textContent, 'notif.pager_label {"page":2,"pages":5,"total":87}');
	assert.equal(previous.getAttribute('aria-label'), 'collection.previous_page');
	assert.equal(next.getAttribute('aria-label'), 'collection.next_page');
});

test('panel pagers print the count through the label, not before a bare noun', () => {
	const pager = { innerHTML: '' };
	const window = {
		FHUtil: { esc: v => String(v ?? ''), jsArg: v => "'" + v + "'", safeHttpUrl: v => String(v || ''), formatSize: String, formatDate: String },
		t: key => ({ 'panel.files.pager_label': 'Plików: :n', 'panel.coll.pager_label': 'Kolekcji: :n' })[key] || key
	};
	const document = { getElementById: () => null, querySelectorAll: () => [] };
	window.window = window;
	window.document = document;
	vm.runInNewContext(read('panel-files.js'), {
		window, document, console, FormData: class {}, setTimeout: () => 1, clearTimeout() {},
		sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} }
	});
	window.FHPanelFiles.renderPager(pager, 1, 3, 1, 'goMyPage');
	assert.match(pager.innerHTML, />Plików: 3</);
	window.FHPanelFiles.renderPager(pager, 4, 70, 2, 'goCollectionsPage', 'Kolekcji: :n');
	assert.match(pager.innerHTML, />Kolekcji: 70</);
	// A label without the placeholder (an older custom language file) still reads "70 x".
	window.FHPanelFiles.renderPager(pager, 1, 70, 1, 'goMyPage', 'items');
	assert.match(pager.innerHTML, />70 items</);
});

test('the bell says "yesterday" and "3 days ago" in the page language', () => {
	const document = createMiniDocument();
	for (const id of ['notifBtn', 'notifPop', 'notifBadge', 'notifList']) document.add('div', id);
	const now = Math.floor(Date.now() / 1000);
	const run = lang => {
		const window = { LANG: lang, location: { origin: 'https://files.example' } };
		window.window = window;
		const context = {
			window, document, console, Intl, Date, Math, Object, String, Number, URL,
			t: (key, params) => (params ? key + ':' + params.n : key),
			FHApi: { get: async () => ({ success: false }), post: async () => ({ success: false }) },
			setInterval: () => 1, clearInterval() {}
		};
		vm.runInNewContext(read('notifications.js'), context);
		return window.FHNotify.ago;
	};
	const pl = run('pl');
	assert.equal(pl(now - 30), 'notif.ago_now');
	assert.equal(pl(now - 25 * 3600), 'wczoraj');
	assert.equal(pl(now - 3 * 86400 - 60), '3 dni temu');
	assert.equal(pl(now - 2 * 3600 - 5), '2 godz. temu');
	assert.equal(run('en')(now - 25 * 3600), 'yesterday');
	// A language code the browser does not know falls back to the dictionary.
	assert.equal(run('xx-unknown')(now - 3 * 86400 - 60), 'notif.ago_day:3');
});
