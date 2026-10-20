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

/**
 * The live language switch, run against two small element trees: the page as it is now (`live`,
 * with whatever scripts added since it loaded) and the same page fetched in the new language.
 */
function loadLanguageSwitch({ live, fresh, location = 'https://files.example/' }) {
	const { createMiniDocument } = require('./support/mini-dom');
	const document = createMiniDocument();
	document.documentElement.setAttribute('lang', 'pl');
	document.title = 'Strona';
	document.cookie = '';
	document.dispatched = [];
	document.dispatchEvent = event => { document.dispatched.push(event); };
	live(document);

	const freshDoc = createMiniDocument();
	freshDoc.documentElement.setAttribute('lang', 'en');
	const data = freshDoc.createElement('script');
	data.id = 'i18n-data';
	data.textContent = JSON.stringify({ 'common.loading': 'Loading…', greeting: 'Hello' });
	fresh(freshDoc);
	freshDoc.body.appendChild(data);
	const findInFresh = freshDoc.querySelector;
	freshDoc.querySelector = selector => (selector === 'title' ? { textContent: 'Page' } : findInFresh(selector));

	const replaced = [];
	const window = {
		innerHeight: 900, innerWidth: 1200,
		addEventListener() {}, removeEventListener() {},
		history: { replaceState: (_state, _title, url) => replaced.push(url) },
		location: { href: location },
		I18N: { 'common.loading': 'Ładowanie…', greeting: 'Witaj' },
		LANG: 'pl'
	};
	window.window = window;
	const context = {
		document,
		window,
		requestAnimationFrame: cb => cb(),
		setTimeout: () => 1,
		clearTimeout() {},
		DOMParser: class { parseFromString() { return freshDoc; } },
		CustomEvent: class { constructor(type, init) { this.type = type; this.detail = init && init.detail; } },
		URL,
		fetch: async () => ({ ok: true, text: async () => '<html>' }),
		Promise
	};
	vm.createContext(context);
	vm.runInContext(read('ui.js'), context, { filename: 'ui.js' });
	return { window, document, replaced };
}

/** The bits of a page both trees share: a header with the switcher, a greeting, a close button. */
function pageSkeleton(doc, { greeting, close }) {
	const header = doc.add('header');
	const sw = doc.createElement('div');
	sw.className = 'lang-switch';
	header.appendChild(sw);
	const p = doc.add('p', 'greeting');
	p.textContent = greeting;
	const button = doc.add('button', 'closeBtn');
	button.setAttribute('title', close);
	return { header, p, button };
}

test('the live language switch still translates the page after scripts added nodes to <body>', () => {
	let livePage;
	const { window, document, replaced } = loadLanguageSwitch({
		location: 'https://files.example/?action=reset&token=SECRET',
		live(doc) {
			livePage = pageSkeleton(doc, { greeting: 'Witaj', close: 'Zamknij' });
			livePage.button.setAttribute('aria-label', 'Zamknij');
			livePage.button.setAttribute('data-fh-aria-from-title', '');
			// What the page and the world add after load: our toast stack, a captcha frame and
			// whatever a browser extension appends (no marker on that one).
			const stack = doc.add('div');
			stack.className = 'toast-stack';
			stack.setAttribute('data-fh-runtime', '');
			stack.textContent = 'Skopiowano';
			doc.add('iframe');
			doc.add('div').className = 'extension-widget';
		},
		fresh(doc) { pageSkeleton(doc, { greeting: 'Hello', close: 'Close' }); }
	});

	window.FHLang.apply('<html>', '/?lang=en&action=reset&token=SECRET');

	assert.equal(livePage.p.textContent, 'Hello', 'text below <body> is translated');
	assert.equal(livePage.button.getAttribute('title'), 'Close');
	assert.equal(livePage.button.getAttribute('aria-label'), 'Close', 'a label derived from the title follows it');
	assert.equal(window.I18N.greeting, 'Hello');
	assert.equal(window.LANG, 'en');
	assert.equal(document.documentElement.getAttribute('lang'), 'en');
	assert.equal(document.querySelector('.toast-stack').textContent, 'Skopiowano', 'run-time nodes are left alone');
	assert.deepEqual(replaced, ['/'], 'neither lang nor the reset token goes back into the address bar');
	assert.equal(document.dispatched[0].type, 'fh:languagechange');
});

test('dialog titles named at run time and textarea placeholders follow the live language switch', () => {
	let live;
	const { window } = loadLanguageSwitch({
		live(doc) {
			pageSkeleton(doc, { greeting: 'Witaj', close: 'Zamknij' });
			const dialog = doc.add('div', 'reportModal');
			const heading = doc.createElement('h3');
			heading.textContent = 'Zgłoś naruszenie';
			// What auth.js and panel.js do at load, so the dialog can point aria-labelledby at it.
			heading.id = 'reportModal-title';
			heading.setAttribute('data-fh-runtime-id', '');
			dialog.appendChild(heading);
			const note = doc.createElement('textarea');
			note.id = 'reportInfo';
			note.setAttribute('placeholder', 'Opisz problem…');
			note.textContent = 'Domyślny opis';
			note.value = 'what the visitor typed';
			dialog.appendChild(note);
			live = { heading, note };
		},
		fresh(doc) {
			pageSkeleton(doc, { greeting: 'Hello', close: 'Close' });
			const dialog = doc.add('div', 'reportModal');
			const heading = doc.createElement('h3');
			heading.textContent = 'Report a violation';
			dialog.appendChild(heading);
			const note = doc.createElement('textarea');
			note.id = 'reportInfo';
			note.setAttribute('placeholder', 'Describe the problem…');
			note.textContent = 'Default description';
			dialog.appendChild(note);
		}
	});

	window.FHLang.apply('<html>', '/?lang=en');

	assert.equal(live.heading.textContent, 'Report a violation', 'the title is translated despite its run-time id');
	assert.equal(live.heading.id, 'reportModal-title', 'and keeps the id aria-labelledby points at');
	assert.equal(live.note.getAttribute('placeholder'), 'Describe the problem…');
	assert.equal(live.note.value, 'what the visitor typed', 'what someone typed is never touched');
	assert.equal(live.note.textContent, 'Domyślny opis', 'nor is the text a textarea starts with');
});

test('dialog headings get a stable id marked as added at run time, never a random one', () => {
	for (const file of ['auth.js', 'panel.js']) {
		const source = read(file);
		assert.doesNotMatch(source, /Math\.random\(\)\.toString\(36\)/, file + ' still names headings at random');
		assert.match(source, /\(modal\.id \|\| 'fh-dialog-' \+ index\) \+ '-title'/, file);
		assert.match(source, /setAttribute\('data-fh-runtime-id', ''\)/, file);
	}
});

test('the live language switch never copies a "Loading…" placeholder over what the page rendered', () => {
	let list;
	const { window } = loadLanguageSwitch({
		live(doc) {
			pageSkeleton(doc, { greeting: 'Witaj', close: 'Zamknij' });
			list = doc.add('div', 'myList');
			const empty = doc.createElement('p');
			empty.className = 'empty';
			empty.textContent = 'Brak plików';
			list.appendChild(empty);
		},
		fresh(doc) {
			pageSkeleton(doc, { greeting: 'Hello', close: 'Close' });
			const placeholder = doc.add('div', 'myList');
			const loading = doc.createElement('p');
			loading.className = 'empty';
			loading.textContent = 'Loading…';
			placeholder.appendChild(loading);
		}
	});

	window.FHLang.apply('<html>', '/?lang=en');
	assert.equal(list.textContent, 'Brak plików');
	assert.equal(window.LANG, 'en');
});

test('a page whose top level no longer matches falls back to a normal navigation, untouched', async () => {
	let livePage;
	const { window, document } = loadLanguageSwitch({
		live(doc) {
			livePage = pageSkeleton(doc, { greeting: 'Witaj', close: 'Zamknij' });
		},
		fresh(doc) {
			doc.add('main').textContent = 'Something else entirely';
			doc.add('aside');
			doc.add('footer');
			doc.add('nav');
		}
	});

	assert.throws(() => window.FHLang.apply('<html>', '/?lang=en'), /structure/);
	assert.equal(livePage.p.textContent, 'Witaj');
	assert.equal(window.I18N.greeting, 'Witaj', 'the dictionary is not swapped either');

	// Through the click handler: the failure becomes a plain navigation to the link.
	const link = { classList: { contains: () => false }, getAttribute: () => '/?lang=en' };
	const event = {
		target: { closest: selector => (selector === '.lang-switch .lang-opt' ? link : null) },
		button: 0,
		preventDefault() {}
	};
	(document.listeners.click || []).forEach(handler => handler(event));
	for (let i = 0; i < 5; i += 1) await new Promise(resolve => setImmediate(resolve));
	assert.equal(window.location.href, '/?lang=en');
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
