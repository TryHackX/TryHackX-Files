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
