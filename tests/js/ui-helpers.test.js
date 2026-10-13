'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const jsDir = path.resolve(__dirname, '../../public/assets/js');
const read = name => fs.readFileSync(path.join(jsDir, name), 'utf8');

function classList(owner) {
	const names = new Set();
	owner.classes = names;
	return {
		add: (...n) => n.forEach(x => names.add(x)),
		remove: (...n) => n.forEach(x => names.delete(x)),
		contains: x => names.has(x),
		toggle(x) { if (names.has(x)) { names.delete(x); return false; } names.add(x); return true; }
	};
}

function element(tag = 'div') {
	const el = {
		nodeType: 1, tagName: tag.toUpperCase(), style: {}, children: [], attributes: {},
		setAttribute(k, v) { this.attributes[k] = String(v); },
		getAttribute(k) { return Object.prototype.hasOwnProperty.call(this.attributes, k) ? this.attributes[k] : null; },
		removeAttribute(k) { delete this.attributes[k]; },
		appendChild(c) { this.children.push(c); c.parentNode = this; return c; },
		cloneNode() { return element(tag); },
		querySelector() { return null; },
		querySelectorAll() { return []; },
		addEventListener() {},
		textContent: ''
	};
	el.classList = classList(el);
	return el;
}

function loadUi({ withToastTemplate = true, storage } = {}) {
	const body = element('body');
	const sun = element('svg');
	const moon = element('svg');
	const document = {
		body,
		cookie: '',
		readyState: 'complete',
		createElement: element,
		getElementById: id => (withToastTemplate && id === 'toast' ? element('div') : null),
		querySelector: () => null,
		querySelectorAll: selector => (selector === '.sun' ? [sun] : selector === '.moon' ? [moon] : []),
		addEventListener() {},
		activeElement: null
	};
	const removed = [];
	const context = {
		document,
		window: {
			innerHeight: 900, innerWidth: 1200, addEventListener() {}, removeEventListener() {},
			FHStorage: storage || { local: { remove: key => removed.push(key) } }
		},
		requestAnimationFrame: cb => cb(),
		setTimeout: () => 1,
		clearTimeout() {},
		DOMParser: class {},
		CustomEvent: class {},
		Promise
	};
	context.window.window = context.window;
	vm.createContext(context);
	vm.runInContext(read('ui.js'), context, { filename: 'ui.js' });
	return { context, document, body, sun, moon, removed };
}

test('toast() understands the legacy "type as a string" call shape', () => {
	const { context } = loadUi();
	const item = context.window.FHUi.toast('failed', 'error');
	assert.ok(item, 'a toast should have been created');
	assert.equal(item.getAttribute('role'), 'alert');
	assert.ok(item.classes.has('error'));
});

test('toast() is a silent no-op without a template — which is why pages must ship one', () => {
	const { context } = loadUi({ withToastTemplate: false });
	assert.equal(context.window.FHUi.toast('lost', { type: 'error' }), undefined);
});

test('every page that shows toasts ships a template element', () => {
	const root = path.resolve(__dirname, '../../public');
	const page = name => fs.readFileSync(path.join(root, name), 'utf8');
	for (const [file, pattern] of [
		['index.php', /id="toast"/],
		['download.php', /id="dlToast"/],
		['collection.php', /id="dlToast"/]
	]) {
		assert.match(page(file), pattern, file + ' has no toast template');
	}
	assert.match(
		fs.readFileSync(path.resolve(__dirname, '../../src/includes/panel/modals_toast.php'), 'utf8'),
		/id="notification"/
	);
});

test('no caller passes a bare type string or a third options argument to the toast API any more', () => {
	for (const file of fs.readdirSync(jsDir).filter(name => name.endsWith('.js') && name !== 'ui.js')) {
		assert.doesNotMatch(
			read(file),
			/FHUi\??\.toast\([^)]*,\s*'(?:error|info|success|copy)'/,
			file + ' still uses toast(message, \'type\')'
		);
	}
});

test('toggleTheme() flips the body class, writes the cookie and drops the legacy storage copy', () => {
	const { context, document, body, sun, moon, removed } = loadUi();
	assert.equal(context.window.FHUi.toggleTheme(), 'light');
	assert.ok(body.classes.has('light'));
	assert.match(document.cookie, /^theme=light;/);
	assert.deepEqual(removed, ['theme']);
	assert.equal(sun.style.display, 'none');
	assert.equal(moon.style.display, 'block');
	assert.equal(context.window.FHUi.toggleTheme(), 'dark');
	assert.equal(body.classes.has('light'), false);
	assert.match(document.cookie, /^theme=dark;/);
	assert.equal(sun.style.display, 'block');
	assert.equal(context.window.toggleTheme, context.window.FHUi.toggleTheme);
});

test('no page script reads the theme back from web storage any more', () => {
	for (const file of fs.readdirSync(jsDir).filter(name => name.endsWith('.js'))) {
		assert.doesNotMatch(read(file), /(?:local|session)Storage\.(?:get|set)Item\(\s*'theme'/, file);
		assert.doesNotMatch(read(file), /\.local\.(?:get|set)\(\s*'theme'/, file);
	}
	assert.doesNotMatch(read('index.js'), /function toggleTheme/);
	assert.doesNotMatch(read('auth.js'), /function toggleTheme|window\.toggleTheme\s*=/);
	assert.doesNotMatch(read('panel.js'), /document\.cookie\s*=/);
});

test('singleFlight() runs a task once while it is in flight and marks the control busy', async () => {
	const { context } = loadUi();
	const button = element('button');
	button.disabled = false;
	let release;
	let runs = 0;
	const task = () => { runs += 1; return new Promise(resolve => { release = resolve; }); };

	const first = context.window.FHUi.singleFlight('create', task, button);
	const second = context.window.FHUi.singleFlight('create', task, button);
	assert.equal(runs, 1, 'the second call must be swallowed');
	assert.equal(button.disabled, true);
	assert.equal(button.getAttribute('aria-busy'), 'true');
	assert.equal(await second, undefined);

	release('done');
	assert.equal(await first, 'done');
	assert.equal(button.disabled, false);
	assert.equal(button.getAttribute('aria-busy'), null);

	// after it settles the same key can run again
	const again = context.window.FHUi.singleFlight('create', () => Promise.resolve('again'), button);
	assert.equal(await again, 'again');
});

test('singleFlight() releases the key and the control when the task throws', async () => {
	const { context } = loadUi();
	const button = element('button');
	button.disabled = false;
	await assert.rejects(
		context.window.FHUi.singleFlight('boom', () => Promise.reject(new Error('nope')), button),
		/nope/
	);
	assert.equal(button.disabled, false);
	await assert.rejects(
		context.window.FHUi.singleFlight('boom', () => { throw new Error('sync'); }, button),
		/sync/
	);
	assert.equal(await context.window.FHUi.singleFlight('boom', () => 'ok', button), 'ok');
});
