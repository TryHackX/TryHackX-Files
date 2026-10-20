'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(
	path.join(__dirname, '../../public/assets/js/panel-users.js'),
	'utf8'
);
const formsCss = fs.readFileSync(
	path.join(__dirname, '../../public/assets/css/panel-forms.css'),
	'utf8'
);

function loadModule() {
	const bootstrap = { dataset: { config: JSON.stringify({ apiUrl: '/api.php' }) } };
	const document = {
		getElementById(id) {
			return id === 'panelBootstrap' ? bootstrap : null;
		},
		querySelectorAll() { return []; }
	};
	const window = {
		FHUtil: {
			esc: value => String(value ?? ''),
			formatSize: value => String(value),
			formatDate: value => String(value)
		},
		t: key => key,
		document
	};
	const context = { console, document, setTimeout() { return 1; }, window };
	window.window = window;
	vm.runInNewContext(source, context, { filename: 'panel-users.js' });
	return window.FHPanelUsers;
}

test('user module exposes a frozen administration API', () => {
	const users = loadModule();

	assert.ok(users);
	assert.equal(Object.isFrozen(users), true);
	for (const action of [
		'loadUsers',
		'sortUsers',
		'userAction',
		'executeUserAction',
		'openBanModal',
		'executeAdvancedBan',
		'showCreateUserModal',
		'createUser',
		'refreshCurrentPage'
	]) {
		assert.equal(typeof users[action], 'function', action);
	}
});

test('user state snapshots cannot mutate module state', () => {
	const users = loadModule();
	const snapshot = users.getUsers();
	snapshot.push({ id: 1 });

	assert.equal(users.getUsers().length, 0);
});

test('user loader is a no-op outside the users tab', async () => {
	const users = loadModule();
	await users.loadUsers();
});

test('the search box narrows the list on the server, debounced, and takes ?search= from the URL', async () => {
	const urls = [];
	const timers = [];
	const box = { value: '', listeners: {}, addEventListener(type, fn) { this.listeners[type] = fn; } };
	const tbody = { innerHTML: '' };
	const bootstrap = { dataset: { config: JSON.stringify({ apiUrl: '/api.php' }) } };
	const document = {
		getElementById: id => ({ panelBootstrap: bootstrap, userSearch: box, usersBody: tbody })[id] || null,
		querySelectorAll() { return []; }
	};
	const window = {
		FHUtil: { esc: v => String(v ?? ''), jsArg: v => "'" + v + "'", formatSize: v => String(v), formatDate: v => String(v) },
		FHPanelCore: {
			fetchLive: async url => { urls.push(url); return { data: { success: true, users: [], total: 0 } }; },
			showSkeleton() {}, finishSkeleton() {}
		},
		location: { search: '?tab=users&search=198.51.100.7' },
		t: key => key,
		document
	};
	window.window = window;
	vm.runInNewContext(source, {
		console, document, window, URLSearchParams,
		setTimeout: fn => { timers.push(fn); return timers.length; },
		clearTimeout: id => { if (id) timers[id - 1] = null; }
	}, { filename: 'panel-users.js' });

	await window.FHPanelUsers.initUsersTab();
	assert.equal(box.value, '198.51.100.7', 'the Tracking view\'s "find user" link fills the box');
	assert.equal(new URL(urls[0], 'http://x').searchParams.get('search'), '198.51.100.7');

	box.value = 'j';
	box.listeners.input();
	box.value = 'ja';
	box.listeners.input();
	box.value = 'jan';
	box.listeners.input();
	assert.equal(urls.length, 1, 'nothing is requested per keystroke');
	timers.filter(Boolean).forEach(fn => fn());
	await new Promise(resolve => setImmediate(resolve));
	assert.equal(urls.length, 2, 'one request once typing pauses');
	const sent = new URL(urls[1], 'http://x').searchParams;
	assert.equal(sent.get('search'), 'jan');
	assert.equal(sent.get('page'), '1');
});

test('a refused user list shows the server\'s reason instead of "Loading…"', async () => {
	const tbody = { innerHTML: 'common.loading' };
	const bootstrap = { dataset: { config: JSON.stringify({ apiUrl: '/api.php' }) } };
	const document = {
		getElementById: id => ({ panelBootstrap: bootstrap, usersBody: tbody })[id] || null,
		querySelectorAll() { return []; }
	};
	const window = {
		FHUtil: { esc: v => String(v ?? ''), jsArg: v => "'" + v + "'", formatSize: v => String(v), formatDate: v => String(v) },
		FHPanelCore: {
			fetchLive: async () => ({ status: 429, data: { success: false, error: 'Too many requests, wait a minute' } }),
			showSkeleton() {}, finishSkeleton() {}
		},
		t: key => key,
		document
	};
	window.window = window;
	vm.runInNewContext(source, { console, document, window, URLSearchParams, setTimeout() { return 1; }, clearTimeout() {} }, { filename: 'panel-users.js' });

	await window.FHPanelUsers.loadUsers(1, true);
	assert.equal(tbody.innerHTML, 'common.loading', 'a background refresh stays quiet');
	await window.FHPanelUsers.loadUsers(1);
	assert.match(tbody.innerHTML, /Too many requests, wait a minute/);
});

test('user templates and sort selectors use declarative actions', () => {
	assert.match(source, /data-fh-click="userAction\(/);
	assert.match(source, /th\[data-fh-click\^="sortUsers"\]/);
	assert.doesNotMatch(source, /\[onclick/);
	assert.doesNotMatch(source, /\son(?:click|change|input|submit|load)\s*=/i);
});

test('plan and moderator group badges are separated, wrapped and free of a redundant icon', () => {
	assert.match(source, /class="user-group-badges"/);
	assert.doesNotMatch(source, /fa-user-shield/);
	assert.match(formsCss, /\.user-group-badges\s*\{[^}]*flex-direction:\s*row;[^}]*flex-wrap:\s*wrap;[^}]*justify-content:\s*center;[^}]*gap:\s*5px;/s);
});
