'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(
	path.join(__dirname, '../../public/assets/js/panel-dashboard.js'),
	'utf8'
);

function loadModule() {
	const bootstrap = { dataset: { config: JSON.stringify({ apiUrl: '/api.php', tab: 'files' }) } };
	const document = {
		getElementById(id) {
			return id === 'panelBootstrap' ? bootstrap : null;
		},
		querySelector() { return null; },
		querySelectorAll() { return []; },
		visibilityState: 'visible'
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
	const context = {
		console,
		document,
		localStorage: {
			getItem() { return null; },
			setItem() {}
		},
		setInterval() { return 1; },
		clearInterval() {},
		window
	};
	window.window = window;
	vm.runInNewContext(source, context, { filename: 'panel-dashboard.js' });
	return window.FHPanelDashboard;
}

test('dashboard module publishes a frozen, explicit action surface', () => {
	const dashboard = loadModule();

	assert.ok(dashboard);
	assert.equal(Object.isFrozen(dashboard), true);
	for (const action of [
		'loadDashboard',
		'loadTopFiles',
		'loadTraffic',
		'loadActiveDownloads',
		'killDownload',
		'killUpload',
		'initActiveDownloadsLive'
	]) {
		assert.equal(typeof dashboard[action], 'function', action);
	}
});

test('dashboard loaders are harmless when their panel markup is absent', async () => {
	const dashboard = loadModule();

	await dashboard.loadTopFiles();
	await dashboard.loadTraffic();
	await dashboard.loadActiveDownloads();
});

test('dashboard actions use delegated data attributes instead of inline handlers', () => {
	assert.match(source, /data-fh-click="killDownload\(/);
	assert.match(source, /data-fh-click="killUpload\(/);
	assert.doesNotMatch(source, /\son(?:click|change|input|submit|load)\s*=/i);
});

test('live transfer tables update only the cells that changed', () => {
	const { diffRows } = loadModule();
	const row = (key, ...cells) => ({ key, cells });
	const before = [row('d1', 'file-a', '1.2.3.4', '10s', '<button>kill</button>'), row('d2', 'file-b', '5.6.7.8', '3s', '<button>kill</button>')];

	// a tick: only the running-time cell of d1 differs, d2 is gone, d3 is new
	const plan = diffRows(before, [
		row('d1', 'file-a', '1.2.3.4', '11s', '<button>kill</button>'),
		row('d3', 'file-c', '9.9.9.9', '1s', '<button>kill</button>')
	]);
	assert.deepEqual(Array.from(plan.remove), ['d2']);
	assert.deepEqual(plan.rows.map(step => [step.key, step.op]), [['d1', 'update'], ['d3', 'add']]);
	assert.deepEqual(Array.from(plan.rows[0].changed), [2], 'the action button cell is left alone');

	// an unchanged snapshot touches nothing at all
	assert.deepEqual(
		diffRows(before, before).rows.map(step => step.op),
		['keep', 'keep']
	);
	// a different column count replaces the row rather than patching cells by index
	assert.equal(diffRows(before, [row('d1', 'a', 'b')]).rows[0].op, 'replace');
});

test('the transfer tables are polled every few seconds, not every second', () => {
	assert.match(source, /const ACTIVE_POLL_MS = (?:[3-9]\d{3}|\d{5,})/);
	assert.doesNotMatch(source, /\}, 1000\);/);
});

test('top files widget says when the server cut the period to the detailed log', async () => {
	const holder = { textContent: '', innerHTML: '' };
	const label = { textContent: '' };
	const bootstrap = { dataset: { config: JSON.stringify({ apiUrl: '/api.php', tab: 'dashboard' }) } };
	const elements = { panelBootstrap: bootstrap, topFiles: holder, topFilesPeriod: label };
	const document = {
		getElementById: id => elements[id] || null,
		querySelector() { return null; },
		querySelectorAll() { return []; },
		visibilityState: 'visible'
	};
	const window = {
		FHUtil: { esc: v => String(v ?? ''), formatSize: v => String(v), formatDate: v => String(v) },
		t: (key, params) => key + (params && params.days ? ':' + params.days : ''),
		document
	};
	window.window = window;
	vm.runInNewContext(source, {
		console,
		document,
		localStorage: {
			getItem() { return null; },
			setItem() {}
		},
		setInterval() { return 1; },
		clearInterval() {},
		FHApi: { get: async () => ({ success: true, top_files: [], clamped: true, detail_days: 30 }) },
		window
	}, { filename: 'panel-dashboard.js' });

	await window.FHPanelDashboard.loadTopFiles();
	assert.equal(label.textContent, 'panel.top.all panel.top.clamped:30');
});
