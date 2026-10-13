'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(
	path.resolve(__dirname, '../../public/assets/js/bootstrap.js'),
	'utf8'
);

function load({ dictionary = {}, storage = {} } = {}) {
	const i18n = { textContent: JSON.stringify(dictionary) };
	const document = {
		querySelector: () => null,
		getElementById: id => (id === 'i18n-data' ? i18n : null),
		documentElement: { getAttribute: () => 'en' }
	};
	const window = { ...storage };
	window.window = window;
	const context = { document, window, Object, String, Headers: class {}, URL };
	vm.createContext(context);
	vm.runInContext(source, context, { filename: 'bootstrap.js' });
	return window;
}

test('t() replaces every placeholder in one pass, so ":n" cannot eat the front of ":name"', () => {
	const window = load({
		dictionary: { 'panel.ua.clean_msg': 'Really delete :n file(s) belonging to “:name”?' }
	});
	assert.equal(
		window.t('panel.ua.clean_msg', { n: 12, name: 'bob' }),
		'Really delete 12 file(s) belonging to “bob”?'
	);
	// the other order, and a value that itself contains a placeholder-looking token
	assert.equal(
		window.t('panel.ua.clean_msg', { name: 'bob', n: 3 }),
		'Really delete 3 file(s) belonging to “bob”?'
	);
	assert.equal(
		window.t('panel.ua.clean_msg', { n: 1, name: 'x:n' }),
		'Really delete 1 file(s) belonging to “x:n”?'
	);
});

test('t() leaves unknown placeholders and missing keys alone', () => {
	const window = load({ dictionary: { a: 'Hello :who, :other' } });
	assert.equal(window.t('a', { who: 'you' }), 'Hello you, :other');
	assert.equal(window.t('no.such.key'), 'no.such.key');
	assert.equal(window.t('a'), 'Hello :who, :other');
	assert.equal(window.t('a', { who: 0 }), 'Hello 0, :other');
});

test('every ":name" token the shipped strings use is read the way the PHP side reads it', () => {
	// No placeholder may be followed directly by letters/digits that belong to the text
	// ("…:nMB"), because the one-pass reader takes the whole identifier as the name.
	const strings = fs.readFileSync(path.resolve(__dirname, '../../src/lang/en.php'), 'utf8');
	const offenders = [];
	for (const match of strings.matchAll(/'([a-z0-9_.]+)' => '((?:[^'\\]|\\.)*)'/g)) {
		for (const token of match[2].matchAll(/:([A-Za-z_][A-Za-z0-9_]*)/g)) {
			if (/^(?:[A-Z]|[a-z]*[0-9])/.test(token[1])) offenders.push(match[1] + ' :' + token[1]);
		}
	}
	assert.deepEqual(offenders, []);
});

test('FHStorage keeps working when the browser refuses web storage', () => {
	const throwing = {};
	for (const name of ['localStorage', 'sessionStorage']) {
		Object.defineProperty(throwing, name, {
			get() { throw new Error('SecurityError: access is denied'); }
		});
	}
	const window = load({ storage: throwing });
	assert.equal(window.FHStorage.session.get('uploadToken'), null);
	window.FHStorage.session.set('uploadToken', 'abc');
	assert.equal(window.FHStorage.session.get('uploadToken'), 'abc', 'falls back to memory');
	window.FHStorage.session.remove('uploadToken');
	assert.equal(window.FHStorage.session.get('uploadToken'), null);
	assert.equal(window.FHStorage.local.get('theme'), null);
});

test('FHStorage uses the real storage when it works and survives a quota error', () => {
	const backing = new Map();
	const good = {
		getItem: key => (backing.has(key) ? backing.get(key) : null),
		setItem: (key, value) => backing.set(key, String(value)),
		removeItem: key => backing.delete(key)
	};
	const full = { ...good, setItem() { throw new Error('QuotaExceededError'); } };
	let window = load({ storage: { localStorage: good, sessionStorage: good } });
	window.FHStorage.local.set('k', 7);
	assert.equal(backing.get('k'), '7');
	assert.equal(window.FHStorage.local.get('k'), '7');
	window.FHStorage.local.remove('k');
	assert.equal(backing.has('k'), false);

	window = load({ storage: { localStorage: full, sessionStorage: full } });
	assert.equal(window.FHStorage.session.set('k', 'v'), false);
	assert.equal(window.FHStorage.session.get('k'), 'v');
});

test('page scripts no longer touch web storage directly at load', () => {
	const dir = path.resolve(__dirname, '../../public/assets/js');
	for (const file of ['index.js', 'auth.js']) {
		assert.doesNotMatch(
			fs.readFileSync(path.join(dir, file), 'utf8'),
			/(?<![A-Za-z.])(?:localStorage|sessionStorage)\.(?:getItem|setItem|removeItem)\(/,
			file + ' reads or writes web storage without the safe wrapper'
		);
	}
});
