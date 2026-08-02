'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const panelSource = fs.readFileSync(
	path.join(__dirname, '../../public/assets/js/panel.js'),
	'utf8'
);
const filesSource = fs.readFileSync(
	path.join(__dirname, '../../public/assets/js/panel-files.js'),
	'utf8'
);

class FakeClassList {
	constructor(initial = []) {
		this.values = new Set(initial);
	}
	add(...names) { names.forEach(name => this.values.add(name)); }
	remove(...names) { names.forEach(name => this.values.delete(name)); }
	contains(name) { return this.values.has(name); }
	toggle(name, force) {
		const enabled = force === undefined ? !this.contains(name) : Boolean(force);
		if (enabled) this.add(name); else this.remove(name);
		return enabled;
	}
}

class FakeStyle {
	constructor() {
		this.values = new Map();
	}
	setProperty(name, value) { this.values.set(name, String(value)); }
	removeProperty(name) { this.values.delete(name); }
	getPropertyValue(name) { return this.values.get(name) || ''; }
}

class FakeElement {
	constructor(tagName = 'div', classes = []) {
		this.tagName = tagName.toUpperCase();
		this.classList = new FakeClassList(classes);
		this.style = new FakeStyle();
		this.attributes = new Map();
		this.children = [];
		this.parentNode = null;
		this.innerHTML = '';
	}
	set className(value) {
		this.classList = new FakeClassList(String(value).split(/\s+/).filter(Boolean));
	}
	get className() { return [...this.classList.values].join(' '); }
	setAttribute(name, value) { this.attributes.set(name, String(value)); }
	removeAttribute(name) { this.attributes.delete(name); }
	getAttribute(name) { return this.attributes.get(name) || null; }
	appendChild(child) {
		child.parentNode = this;
		this.children.push(child);
		return child;
	}
	remove() {
		if (!this.parentNode) return;
		this.parentNode.children = this.parentNode.children.filter(child => child !== this);
		this.parentNode = null;
	}
	querySelector(selector) {
		if (selector === '.table-loading-overlay') {
			return this.children.find(child => child.classList.contains('table-loading-overlay')) || null;
		}
		return null;
	}
	querySelectorAll() { return []; }
	addEventListener() {}
	removeEventListener() {}
}

function inertModule() {
	return new Proxy({}, {
		get() { return () => {}; }
	});
}

function createHarness({ realRows = false, autoScan = false, columns = 3 } = {}) {
	const bootstrap = { dataset: { config: JSON.stringify({ tab: 'test', host: 'localhost' }) } };
	const wrapper = new FakeElement('div', ['table-wrap']);
	wrapper.clientWidth = 720;
	const table = new FakeElement('table');
	table.scrollWidth = 720;
	const headers = Array.from({ length: columns }, (_, index) => {
		const classes = index === 0 ? ['col-primary'] : (index === columns - 1 ? ['col-actions'] : []);
		const header = new FakeElement('th', classes);
		header.getBoundingClientRect = () => ({ width: index === 0 ? 280 : 220 });
		return header;
	});
	table.tHead = { rows: [{ cells: headers }] };
	table.closest = selector => selector === '.table-wrap' ? wrapper : null;
	wrapper.appendChild(table);

	const emptyCell = { colSpan: columns };
	const row = {
		querySelector(selector) {
			return selector === 'td.empty' && !realRows ? emptyCell : null;
		}
	};
	const tbody = new FakeElement('tbody');
	tbody.id = 'fixtureBody';
	tbody.innerHTML = '<tr><td class="empty">server placeholder</td></tr>';
	tbody.closest = selector => selector === 'table' ? table : null;
	tbody.querySelectorAll = selector => selector === 'tr' ? [row] : [];
	tbody.querySelector = selector => {
		if (selector === ':scope > tr:only-child > td.empty' && !realRows) return emptyCell;
		return null;
	};
	table.appendChild(tbody);

	const listeners = new Map();
	const document = {
		readyState: 'loading',
		activeElement: null,
		body: new FakeElement('body'),
		getElementById(id) {
			if (id === 'panelBootstrap') return bootstrap;
			if (id === tbody.id) return tbody;
			return null;
		},
		createElement(tagName) { return new FakeElement(tagName); },
		querySelector() { return null; },
		querySelectorAll(selector) {
			if (selector === '.table-wrap tbody[id]' && autoScan) return [tbody];
			return [];
		},
		addEventListener(type, callback) {
			if (!listeners.has(type)) listeners.set(type, []);
			listeners.get(type).push(callback);
		}
	};

	let timerSequence = 0;
	const timers = [];
	const setTimeout = (callback, delay = 0) => {
		const timer = { id: ++timerSequence, callback, delay, cleared: false };
		timers.push(timer);
		return timer.id;
	};
	const clearTimeout = id => {
		const timer = timers.find(candidate => candidate.id === id);
		if (timer) timer.cleared = true;
	};
	const frames = [];
	const observers = [];
	class FakeMutationObserver {
		constructor(callback) {
			this.callback = callback;
			this.disconnected = false;
			observers.push(this);
		}
	observe() {}
		disconnect() { this.disconnected = true; }
	}

	const moduleNames = [
		'FHPanelFiles', 'FHPanelAccountTools', 'FHPanelUsers', 'FHPanelPremium',
		'FHPanelLanguages', 'FHPanelGroups', 'FHPanelModeration', 'FHPanelDashboard',
		'FHPanelSettings', 'FHPanelAds'
	];
	const window = {
		document,
		location: { search: '', href: '' },
		FHUtil: {
			esc: value => String(value ?? ''),
			formatSize: value => String(value),
			formatDate: value => String(value)
		},
		requestAnimationFrame(callback) { frames.push(callback); },
		addEventListener() {}
	};
	moduleNames.forEach(name => { window[name] = inertModule(); });
	window.window = window;

	const context = {
		clearInterval() {},
		clearTimeout,
		console,
		document,
		fetch: async () => { throw new Error('not used'); },
		FormData: class {},
		Math,
		MutationObserver: FakeMutationObserver,
		navigator: { clipboard: { writeText: async () => {} } },
		setInterval() { return 1; },
		setTimeout,
		t: key => key,
		URL,
		URLSearchParams,
		window
	};
	vm.runInNewContext(panelSource, context, { filename: 'panel.js' });

	return {
		core: window.FHPanelCore,
		document,
		frames,
		listeners,
		observers,
		runFrame() {
			const callback = frames.shift();
			assert.ok(callback, 'expected a queued animation frame');
			callback();
		},
		runTimer(delay) {
			const timer = timers.find(candidate => candidate.delay === delay && !candidate.cleared);
			assert.ok(timer, `expected an active ${delay} ms timer`);
			timer.callback();
			return timer;
		},
		table,
		tbody,
		timers,
		wrapper
	};
}

test('initial loader is one compact overlay and never replaces the server tbody placeholder', () => {
	const harness = createHarness();
	const originalBody = harness.tbody.innerHTML;

	assert.equal(harness.core.showSkeleton('fixtureBody', 3), true);
	assert.equal(harness.tbody.innerHTML, originalBody);
	assert.equal(harness.wrapper.children.length, 2);
	const overlay = harness.wrapper.querySelector('.table-loading-overlay');
	assert.ok(overlay);
	assert.match(overlay.innerHTML, /table-loading-indicator/);
	assert.equal((overlay.innerHTML.match(/class="skel/g) || []).length, 1);
	assert.equal(harness.wrapper.style.getPropertyValue('--table-loader-height'), '104px');
	assert.equal(overlay.style.getPropertyValue('--skeleton-columns'), '');
	assert.equal(overlay.style.getPropertyValue('--skeleton-table-width'), '');
	assert.equal(overlay.getAttribute('aria-hidden'), 'true');
	assert.equal(harness.table.getAttribute('aria-busy'), 'true');
});

test('automatic scan installs the overlay before tab-specific loaders run', () => {
	const harness = createHarness({ autoScan: true });
	const ready = harness.listeners.get('DOMContentLoaded') || [];
	assert.equal(ready.length, 1);

	ready[0]();

	assert.ok(harness.wrapper.querySelector('.table-loading-overlay'));
	assert.equal(harness.table.classList.contains('table-loading-target'), true);
	assert.equal(harness.table.getAttribute('aria-busy'), 'true');
});

test('finishing keeps aria-busy through two frames and removes the overlay after its fade', () => {
	const harness = createHarness();
	harness.core.showSkeleton('fixtureBody', 3);
	const fallback = harness.timers.find(timer => timer.delay === 20000);
	assert.ok(fallback);

	assert.equal(harness.core.finishSkeleton('fixtureBody'), true);
	assert.equal(harness.frames.length, 1);
	harness.runFrame();
	assert.equal(harness.table.getAttribute('aria-busy'), 'true');
	assert.equal(harness.frames.length, 1);
	harness.runFrame();

	assert.equal(harness.table.getAttribute('aria-busy'), null);
	assert.equal(harness.table.classList.contains('table-loading-target'), false);
	assert.equal(harness.wrapper.style.getPropertyValue('--table-loader-height'), '0px');
	assert.equal(fallback.cleared, true);
	assert.equal(harness.wrapper.querySelector('.table-loading-overlay').classList.contains('is-leaving'), true);

	harness.runTimer(180);
	assert.equal(harness.wrapper.querySelector('.table-loading-overlay'), null);
	assert.equal(harness.wrapper.classList.contains('table-loading'), false);
});

test('20 second fallback cannot leave an empty table hidden indefinitely', () => {
	const harness = createHarness();
	harness.core.showSkeleton('fixtureBody', 3);

	harness.runTimer(20000);
	harness.runFrame();
	harness.runFrame();
	harness.runTimer(180);

	assert.equal(harness.table.getAttribute('aria-busy'), null);
	assert.equal(harness.wrapper.querySelector('.table-loading-overlay'), null);
});

test('a refresh with real rows stays visible and receives no overlay', () => {
	const harness = createHarness({ realRows: true });

	assert.equal(harness.core.showSkeleton('fixtureBody', 3), false);
	assert.equal(harness.wrapper.querySelector('.table-loading-overlay'), null);
	assert.equal(harness.table.classList.contains('table-loading-target'), false);
	assert.equal(harness.table.getAttribute('aria-busy'), null);
});

test('file loader reserves ETags for silent polling and always settles an explicit loader', () => {
	const start = filesSource.indexOf('async function loadFiles(');
	const end = filesSource.indexOf('\n\tfunction sortBy(', start);
	assert.ok(start >= 0 && end > start);
	const loadFilesSource = filesSource.slice(start, end);

	assert.ok(
		loadFilesSource.includes("fetchLive(`${apiUrl}?${params}`, 'files', silent)"),
		'loadFiles must pass silent as fetchLive useEtag argument'
	);
	assert.match(loadFilesSource, /finally\s*\{\s*if\s*\(!silent\)\s*finishSkeleton\('filesBody'\);\s*\}/);
});
