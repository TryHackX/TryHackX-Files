'use strict';

/**
 * Signing out from the account dialog (auth.js). The server session — and with it the page's
 * CSRF token and whatever was rendered for the account — is gone afterwards, so a public page
 * is loaded again rather than left with a dead token (the next sign-in from the same dialog
 * used to fail CSRF) and a notification bell polling a signed-out account.
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const { createMiniDocument } = require('./support/mini-dom');

const source = fs.readFileSync(path.join(__dirname, '../../public/assets/js/auth.js'), 'utf8');

function loadAuth(pathname, logoutAnswer) {
	const document = createMiniDocument();
	const modal = document.add('div', 'authModal');
	modal.className = 'auth-modal show';
	for (const id of ['authMessage', 'authGuest', 'authUser']) {
		const node = document.createElement('div');
		node.id = id;
		modal.appendChild(node);
	}
	const timers = [];
	const location = {
		pathname, search: '', href: 'https://files.example' + pathname, reloaded: 0,
		reload() { this.reloaded += 1; }
	};
	const posts = [];
	const window = {
		location,
		history: { replaceState() {} },
		FHUploadSession: { current: () => 'TOK', clear() { window.cleared = true; }, replace() {} }
	};
	window.window = window;
	const context = {
		window, document, console: { log() {}, error() {}, warn() {} }, URL, URLSearchParams, Promise, JSON,
		t: key => key,
		FHApi: {
			get: async () => ({ success: true }),
			post: async (action, body) => { posts.push({ action, body }); return logoutAnswer(); }
		},
		FHCaptcha: { configure() {}, load() {}, render: () => 1, reset() {}, getResponse: () => '' },
		setTimeout: (fn, ms) => { timers.push({ fn, ms }); return timers.length; },
		clearTimeout() {},
		setInterval: () => 1,
		clearInterval() {}
	};
	vm.createContext(context);
	vm.runInContext(source, context, { filename: 'auth.js' });
	return { context, window, location, posts, timers, document };
}

test('signing out on a public page loads the page again, once the message was shown', async () => {
	const { context, window, location, posts, timers, document } = loadAuth('/download.php', async () => ({ success: true }));
	await context.handleLogout();
	assert.deepEqual(posts.map(p => p.action), ['user_logout']);
	assert.equal(window.cleared, true, 'the upload session goes with the sign-in');
	assert.equal(document.getElementById('authMessage').textContent, 'js.logged_out');
	assert.equal(location.reloaded, 0, 'not before the message could be read');
	timers.forEach(timer => timer.fn());
	assert.equal(location.reloaded, 1);
});

test('a failed sign-out request still leaves no stale signed-in page behind', async () => {
	const { context, location, timers } = loadAuth('/', async () => { throw new Error('offline'); });
	await context.handleLogout();
	timers.forEach(timer => timer.fn());
	assert.equal(location.reloaded, 1);
});

test('signing out in the panel still goes to the home page', async () => {
	const { context, location, timers } = loadAuth('/panel.php', async () => ({ success: true }));
	await context.handleLogout();
	timers.forEach(timer => timer.fn());
	assert.equal(location.reloaded, 0);
	assert.notEqual(location.href, 'https://files.example/panel.php');
});
