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

/**
 * Just enough of a table for syncRows(): rows parsed out of innerHTML, and a `cells` collection
 * that is live like the DOM's — moving a cell out of a row renumbers the cells after it.
 */
class TableNode {
	constructor(tag, html = '') {
		this.tagName = tag.toUpperCase();
		this.html = html;
		this.parentNode = null;
		this.children = [];
		this.dataset = {};
	}

	get outerHTML() { return this.html; }
	get cells() { return this.children; }
	get textContent() { return this.html.replace(/<[^>]*>/g, ''); }

	set innerHTML(html) {
		this.replaceChildren();
		for (const rowHtml of String(html).match(/<tr\b[\s\S]*?<\/tr>/g) || []) {
			const row = new TableNode('tr', rowHtml);
			for (const cellHtml of rowHtml.match(/<td\b[\s\S]*?<\/td>/g) || []) row.appendChild(new TableNode('td', cellHtml));
			this.appendChild(row);
		}
	}

	detach(node) {
		const at = this.children.indexOf(node);
		if (at >= 0) this.children.splice(at, 1);
		node.parentNode = null;
	}

	appendChild(node) {
		if (node.parentNode) node.parentNode.detach(node);
		node.parentNode = this;
		this.children.push(node);
		return node;
	}

	replaceWith(node) {
		const parent = this.parentNode;
		if (node.parentNode) node.parentNode.detach(node);
		parent.children.splice(parent.children.indexOf(this), 1, node);
		node.parentNode = parent;
		this.parentNode = null;
	}

	remove() { if (this.parentNode) this.parentNode.detach(this); }
	replaceChildren() { this.children.slice().forEach(node => this.detach(node)); }
}

test('an upload row whose progress and running time both changed keeps one kill button', async () => {
	const uploads = new TableNode('tbody');
	const downloads = new TableNode('tbody');
	const responses = [];
	const document = {
		getElementById(id) {
			if (id === 'panelBootstrap') return { dataset: { config: JSON.stringify({ apiUrl: '/api.php', tab: 'dashboard' }) } };
			return { activeUploadsBody: uploads, activeDownloadsBody: downloads }[id] || null;
		},
		createElement: tag => new TableNode(tag),
		querySelector() { return null; },
		querySelectorAll() { return []; },
		visibilityState: 'visible'
	};
	const window = {
		FHUtil: { esc: v => String(v ?? ''), formatSize: v => String(v) + ' B', formatDate: v => String(v) },
		t: key => key,
		document
	};
	window.window = window;
	vm.runInNewContext(source, {
		console,
		document,
		localStorage: { getItem() { return null; }, setItem() {} },
		setInterval() { return 1; },
		clearInterval() {},
		FHApi: { get: async () => responses.shift() },
		window
	}, { filename: 'panel-dashboard.js' });

	const upload = (received, now) => ({
		success: true, now, downloads: [],
		uploads: [{ id: 7, filename: 'big.iso', username: 'bob', ip_address: '1.2.3.4', size: 1000, received, started_at: 990 }]
	});
	responses.push(upload(100, 1000));
	await window.FHPanelDashboard.loadActiveDownloads();
	const row = uploads.children[0];
	assert.match(row.cells[4].outerHTML, />10s</);

	responses.push(upload(400, 1003));
	await window.FHPanelDashboard.loadActiveDownloads();
	assert.equal(uploads.children[0], row, 'the row node is kept, only its cells change');
	assert.equal(row.cells.length, 6);
	assert.match(row.cells[3].outerHTML, /40%/);
	assert.match(row.cells[4].outerHTML, />13s</, 'the running time is still in its own column');
	assert.equal(row.cells.filter(cell => /killUpload/.test(cell.outerHTML)).length, 1, 'exactly one kill button');
	assert.match(row.cells[5].outerHTML, /killUpload\(7\)/);
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

async function renderTrafficAt(clientWidth) {
	const holder = { clientWidth, innerHTML: '', textContent: '' };
	const series = Array.from({ length: 30 }, (_, i) => ({
		date: `2026-09-${String(i + 1).padStart(2, '0')}`,
		upload: 1000 * (i + 1),
		download: 500 * (i + 1)
	}));
	const document = {
		getElementById(id) {
			if (id === 'panelBootstrap') {
				return { dataset: { config: JSON.stringify({ apiUrl: '/api.php', tab: 'dashboard' }) } };
			}
			return id === 'trafficChart' ? holder : null;
		},
		querySelector() { return null; },
		querySelectorAll() { return []; },
		visibilityState: 'visible'
	};
	const window = {
		FHUtil: { esc: v => String(v ?? ''), formatSize: v => String(v), formatDate: v => String(v) },
		t: key => key,
		document
	};
	window.window = window;
	vm.runInNewContext(source, {
		console,
		document,
		localStorage: { getItem() { return null; }, setItem() {} },
		setInterval() { return 1; },
		clearInterval() {},
		URLSearchParams,
		FHApi: { get: async () => ({ success: true, from: 0, to: 0, bucket: 'day', series }) },
		window
	}, { filename: 'panel-dashboard.js' });
	await window.FHPanelDashboard.loadTraffic();
	return holder.innerHTML;
}

test('traffic chart is drawn at a phone card\'s real width with readable labels', async () => {
	const svg = await renderTrafficAt(285);
	assert.match(svg, /viewBox="0 0 300 210"/);
	assert.doesNotMatch(svg, /font-size="(?:9|10)"/);
	assert.match(svg, /font-size="11"/);
	// 30 daily buckets on a narrow card: no more than a handful of date labels.
	assert.ok((svg.match(/text-anchor="middle"/g) || []).length <= 8);
});

test('traffic chart keeps the desktop 640-unit layout on wide cards', async () => {
	const svg = await renderTrafficAt(1100);
	assert.match(svg, /viewBox="0 0 640 210"/);
	assert.match(svg, /font-size="9"/);
	assert.match(svg, /font-size="10"/);
});
