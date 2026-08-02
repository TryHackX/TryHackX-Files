'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(
	path.join(__dirname, '../../public/assets/js/panel-files.js'),
	'utf8'
);

function loadModule() {
	const bootstrap = {
		dataset: {
			config: JSON.stringify({
				apiUrl: '/api.php',
				appUrl: '/pon',
				perms: []
			})
		}
	};
	const document = {
		getElementById(id) {
			return id === 'panelBootstrap' ? bootstrap : null;
		},
		querySelectorAll() { return []; }
	};
	const window = {
		FHUtil: {
			esc: value => String(value ?? ''),
			safeHttpUrl: value => /^javascript:/i.test(String(value || '')) ? '' : String(value || ''),
			formatSize: value => String(value),
			formatDate: value => String(value)
		},
		t: key => key,
		document
	};
	const context = {
		clearTimeout() {},
		console,
		document,
		FormData: class {},
		navigator: { clipboard: { writeText: async () => {} } },
		sessionStorage: { getItem() { return null; }, setItem() {} },
		setTimeout() { return 1; },
		window
	};
	window.window = window;
	vm.runInNewContext(source, context, { filename: 'panel-files.js' });
	return window.FHPanelFiles;
}

test('file module exposes a frozen file, filter and collection API', () => {
	const files = loadModule();

	assert.ok(files);
	assert.equal(Object.isFrozen(files), true);
	for (const action of [
		'loadFiles',
		'loadMyFiles',
		'openFiltersModal',
		'openMyFiltersModal',
		'openCreateCollection',
		'openCollectionSettings',
		'downloadFile',
		'downloadCollection',
		'bindFileControls',
		'initFilesTab',
		'initMyFilesTab',
		'refreshAdmin',
		'refreshMy'
	]) {
		assert.equal(typeof files[action], 'function', action);
	}
});

test('file list and pager loaders are harmless outside their tabs', async () => {
	const files = loadModule();
	const pager = { innerHTML: '' };

	await files.loadFiles();
	files.renderPager(pager, 1, 0, 1, 'loadFiles');
	assert.match(pager.innerHTML, /page-info/);
});

test('file templates and sort selectors use declarative actions', () => {
	assert.match(source, /data-fh-click="downloadFile\(/);
	assert.match(source, /th\[data-fh-click\^="sortBy"\]/);
	assert.match(source, /th\[data-fh-click\^="sortMyFiles"\]/);
	assert.doesNotMatch(source, /\[onclick/);
	assert.doesNotMatch(source, /decodeURIComponent\(f\.name/);
	assert.doesNotMatch(source, /href="\$\{(?:esc\()?c\.url/);
	assert.match(source, /safeHttpUrl\(c\.url\)/);
	assert.doesNotMatch(source, /\son(?:click|change|input|submit|load|keydown)\s*=/i);
	assert.match(source, /function writeSizeBound\(/);
	assert.match(source, /function readSizeBound\(/);
	assert.match(source, /querySelectorAll\('#filesBody \.file-check'\)/);
	assert.match(source, /querySelectorAll\('#adminCollectionsBody \.coll-check'\)/);
});

test('shared panel surface publishes the inline modal message helper', () => {
	const panelSource = fs.readFileSync(
		path.join(__dirname, '../../public/assets/js/panel.js'),
		'utf8'
	);
	assert.match(panelSource, /showNotification, flashMessage, toggleTheme/);
});
