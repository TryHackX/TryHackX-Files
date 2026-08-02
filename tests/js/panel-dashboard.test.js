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
