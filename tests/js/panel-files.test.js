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
				appUrl: '/app',
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

/** "My files" with a forgiving DOM and a scripted fetchLive. */
function loadMyFilesTab(answers) {
	const { createLooseDocument } = require('./support/loose-dom');
	const bootstrap = { dataset: { config: JSON.stringify({ apiUrl: '/api.php', appUrl: '/pon', perms: [] }) } };
	const document = createLooseDocument({ panelBootstrap: bootstrap });
	const calls = [];
	const window = {
		FHUtil: {
			esc: value => String(value ?? ''), jsArg: value => "'" + value + "'",
			safeHttpUrl: value => String(value || ''), formatSize: value => String(value), formatDate: value => String(value)
		},
		FHPanelCore: {
			fetchLive: async (url, key, useEtag) => { calls.push({ url, key, useEtag }); return answers.shift(); },
			showSkeleton() {}, finishSkeleton() {}
		},
		t: key => key,
		document
	};
	window.window = window;
	vm.runInNewContext(source, {
		clearTimeout() {}, console, document, FormData: class {},
		sessionStorage: { getItem() { return null; }, setItem() {}, removeItem() {} },
		setTimeout() { return 1; }, window
	}, { filename: 'panel-files.js' });
	return { files: window.FHPanelFiles, document, calls };
}

const someFiles = n => Array.from({ length: n }, (_, i) => ({
	id: 'f' + i, name: 'file' + i + '.txt', size: 10, downloads: 0, uploadedAt: 1000 + i, mime: 'text/plain'
}));

test('the silent refresh of "My files" keeps the page being read; an explicit load starts at page 1', async () => {
	const ok = { status: 200, data: { success: true, files: someFiles(70) } };
	const { files, document } = loadMyFilesTab([ok, ok, ok]);
	await files.loadMyFiles();
	files.goMyPage(3);
	const pager = () => document.getElementById('myFilesPagination').innerHTML;
	assert.match(pager(), /class="active" data-fh-click="goMyPage\(3\)"/);

	await files.refreshMy(true);
	assert.match(pager(), /class="active" data-fh-click="goMyPage\(3\)"/, 'a download elsewhere does not throw the reader back');

	await files.loadMyFiles();
	assert.match(pager(), /class="active" data-fh-click="goMyPage\(1\)"/);
});

test('a refused list says why instead of "Loading…" forever; a silent poll stays quiet', async () => {
	const refused = { status: 503, data: { success: false, error: 'Maintenance in progress' } };
	const { files, document } = loadMyFilesTab([refused, refused]);
	const body = document.getElementById('myFilesBody');
	body.innerHTML = '<tr><td colspan="6" class="empty">common.loading</td></tr>';
	await files.refreshMy(true);
	assert.match(body.innerHTML, /common\.loading/, 'a background poll does not replace the table');
	await files.loadMyFiles();
	assert.match(body.innerHTML, /Maintenance in progress/);
	assert.doesNotMatch(body.innerHTML, /common\.loading/);
});

test('shared panel surface publishes the inline modal message helper', () => {
	const panelSource = fs.readFileSync(
		path.join(__dirname, '../../public/assets/js/panel.js'),
		'utf8'
	);
	assert.match(panelSource, /showNotification, flashMessage, toggleTheme/);
});
