'use strict';

/**
 * The upload token follows the account signed in in this tab (bootstrap.js FHUploadSession):
 * signing out drops it everywhere, signing in adopts the one the server hands back. Before, the
 * token survived sign-out and later uploads were attributed to the previous account.
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const jsDir = path.resolve(__dirname, '../../public/assets/js');
const read = name => fs.readFileSync(path.join(jsDir, name), 'utf8');

function loadBootstrap() {
	const backing = new Map();
	const sessionStorage = {
		getItem: key => (backing.has(key) ? backing.get(key) : null),
		setItem: (key, value) => backing.set(key, String(value)),
		removeItem: key => backing.delete(key)
	};
	const document = {
		querySelector: () => null,
		getElementById: () => null,
		documentElement: { getAttribute: () => 'en' }
	};
	const window = { sessionStorage, localStorage: sessionStorage };
	window.window = window;
	const context = { document, window, Object, String, Headers: class {}, URL };
	vm.createContext(context);
	vm.runInContext(read('bootstrap.js'), context, { filename: 'bootstrap.js' });
	return { window, backing };
}

test('FHUploadSession stores, announces and clears the tab\'s upload token', () => {
	const { window, backing } = loadBootstrap();
	const seen = [];
	window.FHUploadSession.subscribe((token, expires) => seen.push([token, expires]));

	window.FHUploadSession.replace('NEW', 1234);
	assert.equal(backing.get('uploadToken'), 'NEW');
	assert.equal(backing.get('uploadTokenExpiry'), '1234');
	assert.equal(window.FHUploadSession.current(), 'NEW');

	window.FHUploadSession.clear();
	assert.equal(backing.has('uploadToken'), false);
	assert.equal(backing.has('uploadTokenExpiry'), false);
	assert.equal(window.FHUploadSession.current(), '');
	assert.deepEqual(JSON.parse(JSON.stringify(seen)), [['NEW', 1234], ['', 0]]);
});

test('signing out sends the token to be revoked and drops it; signing in adopts a handed-over one', () => {
	const auth = read('auth.js');
	const logout = auth.slice(auth.indexOf('async function handleLogout'));
	assert.match(logout, /FHApi\.post\('user_logout',\s*\{\s*upload_token:/);
	assert.match(logout, /FHUploadSession\.clear\(\)/);
	assert.equal((auth.match(/^\s*adoptUploadToken\(data\);/gm) || []).length, 2, 'password and 2FA sign-in both adopt it');
	assert.match(auth, /FHUploadSession\.replace\(data\.upload_token/);

	const panel = read('panel.js');
	const panelLogout = panel.slice(panel.indexOf('async function handleLogout'));
	assert.match(panelLogout, /upload_token:/);
	assert.match(panelLogout, /FHUploadSession\.clear\(\)/);
});
