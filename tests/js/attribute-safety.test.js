'use strict';

/**
 * Stored text must never become markup in the panel.
 *
 * The panel renders tables with innerHTML and wires buttons through `data-fh-*` attributes that
 * panel-events.js executes. A collection name or an ad's alt text containing `"` used to close
 * the attribute and add one of those handlers. These tests render the REAL modules with hostile
 * strings and check three things: the set of tags/attributes is the same as for a harmless
 * string, every handler still parses, and the string reaches its handler as one intact argument.
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const { createLooseDocument } = require('./support/loose-dom');
const { parseTags, shapeOf, runHandler } = require('./support/html-attrs');

const jsDir = path.join(__dirname, '..', '..', 'public', 'assets', 'js');
const read = name => fs.readFileSync(path.join(jsDir, name), 'utf8');

const HOSTILE = [
	'x" data-fh-click="evil()',
	"x' data-fh-click='evil()",
	'x" style="position:fixed;inset:0" data-fh-load="evil(1)',
	"a'b",
	'back\\slash',
	'trailing\\',
	"\\'); evil(1); (\\'",
	'line\nbreak',
	'<img src=x onerror=evil()>',
	'&amp; &quot; &#39;'
];

/** Load util.js + a panel module into one sandbox and hand back the sandbox. */
function sandbox(moduleFile, { perms = [], tab = 'files', isAdmin = true, api = {}, core = {} } = {}) {
	const bootstrap = {
		dataset: { config: JSON.stringify({ apiUrl: '/api.php', appUrl: '/app', tab, isAdmin, perms }) }
	};
	const document = createLooseDocument({ panelBootstrap: bootstrap });
	const window = {
		t: (key, params) => (params ? key + JSON.stringify(params) : key),
		showNotification() {},
		showModal() {},
		closeModal() {},
		openModal() {},
		showConfirm() {},
		flashMessage() {},
		location: { search: '', href: 'http://panel.test/panel.php', origin: 'http://panel.test' },
		history: { replaceState() {} },
		FHPanelCore: {
			fetchLive: async () => ({ notModified: true }),
			showSkeleton: () => false,
			finishSkeleton: () => false,
			renderPager: () => {},
			...core
		},
		document
	};
	window.window = window;
	const context = {
		console,
		document,
		window,
		FHApi: {
			get: async action => (Object.prototype.hasOwnProperty.call(api, action) ? api[action] : { success: false }),
			post: async () => ({ success: true })
		},
		FormData: class {},
		URLSearchParams,
		URL,
		navigator: {},
		sessionStorage: { getItem() { return null; }, setItem() {}, removeItem() {} },
		localStorage: { getItem() { return null; }, setItem() {}, removeItem() {} },
		setTimeout() { return 1; },
		clearTimeout() {},
		setInterval() { return 1; },
		clearInterval() {},
		requestAnimationFrame() {},
		MutationObserver: class { observe() {} disconnect() {} }
	};
	vm.createContext(context);
	vm.runInContext(read('util.js'), context, { filename: 'util.js' });
	vm.runInContext(read(moduleFile), context, { filename: moduleFile });
	return { window, document, context };
}

/** Handler attributes of every tag in `html`, run through the real dispatcher. */
function runAllHandlers(html) {
	const results = [];
	for (const tag of parseTags(html)) {
		for (const attr of tag.attrs) {
			if (/^data-fh-/.test(attr.name)) results.push({ attr, ...runHandler(attr.name, attr.value) });
		}
	}
	return results;
}

function assertRenderingIsInert(render, label) {
	const baseShape = shapeOf(render('Holiday'));
	for (const hostile of HOSTILE) {
		const html = render(hostile);
		const tag = label + ' ← ' + JSON.stringify(hostile);
		assert.deepEqual(shapeOf(html), baseShape, 'tags/attributes changed: ' + tag);
		for (const result of runAllHandlers(html)) {
			assert.deepEqual(result.rejected, [], 'handler refused: ' + tag + ' :: ' + result.attr.value);
			assert.ok(!result.calls.some(c => c.name === 'evil'), 'injected call ran: ' + tag);
		}
	}
}

/** The name must arrive, verbatim, as an argument of some handler call. */
function assertNameReachesHandler(html, hostile, functionName) {
	const hits = runAllHandlers(html)
		.flatMap(result => result.calls)
		.filter(call => call.name === functionName);
	assert.ok(hits.length > 0, functionName + ' was never called for ' + JSON.stringify(hostile));
	assert.ok(
		hits.some(call => call.args.includes(hostile)),
		functionName + ' did not receive ' + JSON.stringify(hostile) + ': ' + JSON.stringify(hits)
	);
}

function collection(name) {
	return {
		id: 'abc123', name, fileCount: 2, totalSize: 1024, downloads: 3, createdAt: 1700000000,
		userId: 5, owner: 'bob', ownerState: 'ok', url: 'https://files.example/collection.php?id=abc123'
	};
}

test('esc() escapes quotes so a value cannot leave an attribute', () => {
	const { context } = sandbox('panel-files.js');
	const { esc } = context.window.FHUtil;
	assert.equal(esc('a"b\'c<d>&'), 'a&quot;b&#39;c&lt;d&gt;&amp;');
	assert.equal(esc(null), '');
	assert.equal(esc(0), '0');
});

test('jsArg() produces a literal the declarative dispatcher reads back unchanged', () => {
	const { context } = sandbox('panel-files.js');
	for (const value of HOSTILE.concat(['', 'plain', 'ünïcödé 日本語'])) {
		const literal = context.window.FHUtil.jsArg(value);
		// what the browser hands to panel-events.js after decoding the attribute
		const decoded = literal.replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
		const { calls, rejected } = runHandler('data-fh-click', 'pick(' + decoded + ')');
		assert.deepEqual(rejected, [], JSON.stringify(value));
		assert.deepEqual(calls, [{ name: 'pick', args: [value] }], JSON.stringify(value));
	}
});

test('admin collection rows keep hostile collection names inside their attributes', async () => {
	const render = name => {
		const { window, document } = sandbox('panel-files.js', {
			api: { admin_collections: { success: true, collections: [collection(name)], total: 1 } }
		});
		return window.FHPanelFiles.loadAdminCollections().then(() => document.getElementById('adminCollectionsBody').innerHTML);
	};
	const baseline = await render('Holiday');
	const baseShape = shapeOf(baseline);
	assert.ok(baseShape.length > 5, 'rendering produced no markup');
	for (const hostile of HOSTILE) {
		const html = await render(hostile);
		assert.deepEqual(shapeOf(html), baseShape, JSON.stringify(hostile));
		for (const result of runAllHandlers(html)) {
			assert.deepEqual(result.rejected, [], JSON.stringify(hostile) + ' :: ' + result.attr.value);
			assert.ok(!result.calls.some(c => c.name === 'evil'), 'injected call ran for ' + JSON.stringify(hostile));
		}
		assertNameReachesHandler(html, hostile, 'askDeleteCollection');
	}
});

test('own collection rows keep hostile collection names inside their attributes', async () => {
	const render = name => {
		const { window, document } = sandbox('panel-files.js', {
			tab: 'myfiles',
			api: { user_collections: { success: true, collections: [collection(name)] } }
		});
		return window.FHPanelFiles.loadCollections().then(() => document.getElementById('collectionsBody').innerHTML);
	};
	const baseShape = shapeOf(await render('Holiday'));
	assert.ok(baseShape.length > 5, 'rendering produced no markup');
	for (const hostile of HOSTILE) {
		const html = await render(hostile);
		assert.deepEqual(shapeOf(html), baseShape, JSON.stringify(hostile));
		for (const result of runAllHandlers(html)) {
			assert.deepEqual(result.rejected, [], JSON.stringify(hostile) + ' :: ' + result.attr.value);
			assert.ok(!result.calls.some(c => c.name === 'evil'));
		}
		assertNameReachesHandler(html, hostile, 'askDeleteCollection');
	}
});

test('user rows keep hostile names inside their attributes', async () => {
	const render = name => {
		const { window, document } = sandbox('panel-users.js', {
			tab: 'users',
			core: {
				fetchLive: async () => ({
					data: {
						success: true, total: 1, rootId: 1,
						users: [{
							id: 7, username: name, email: 'a@b.c', role: 'user', is_active: 1, files_count: 2,
							storage_used: 10, created_at: 1700000000, group_id: 2, last_ip: name
						}]
					}
				})
			}
		});
		return window.FHPanelUsers.loadUsers(1).then(() => document.getElementById('usersBody').innerHTML);
	};
	const baseShape = shapeOf(await render('bob'));
	assert.ok(baseShape.length > 5, 'rendering produced no markup');
	for (const hostile of HOSTILE) {
		const html = await render(hostile);
		assert.deepEqual(shapeOf(html), baseShape, JSON.stringify(hostile));
		for (const result of runAllHandlers(html)) {
			assert.deepEqual(result.rejected, [], JSON.stringify(hostile) + ' :: ' + result.attr.value);
			assert.ok(!result.calls.some(c => c.name === 'evil'));
		}
		assertNameReachesHandler(html, hostile, 'userAction');
	}
});

test('the ad approval queue cannot be given extra attributes through alt text or names', async () => {
	const render = alt => {
		const { window, document } = sandbox('panel-ads.js', {
			tab: 'moderate',
			api: {
				admin_ad_queue: {
					success: true,
					queue: [{
						id: 77, name: alt, zone: 'home_top', status: 'pending', type: 'image',
						imageUrl: 'https://cdn.example/banner.png', targetUrl: 'https://example.org/',
						altText: alt, ownerName: alt, packageName: alt, orderId: 'ORD1', createdAt: 1700000000,
						children: []
					}]
				}
			}
		});
		return window.FHPanelAds.loadAdsQueue().then(() => document.getElementById('adsQueueList').innerHTML);
	};
	const baseShape = shapeOf(await render('Banner'));
	assert.ok(baseShape.includes('img[src,alt]'), 'queue card has no preview image: ' + baseShape.join(' '));
	for (const hostile of HOSTILE) {
		const html = await render(hostile);
		assert.deepEqual(shapeOf(html), baseShape, JSON.stringify(hostile));
		assert.equal(
			parseTags(html).filter(tag => tag.attrs.some(attr => /^data-fh-(load|error)$/.test(attr.name))).length,
			0,
			'a load/error handler was injected for ' + JSON.stringify(hostile)
		);
	}
});

test('no panel module builds handler arguments by hand any more', () => {
	for (const file of fs.readdirSync(jsDir).filter(name => /^panel.*\.js$/.test(name))) {
		const source = read(file);
		assert.doesNotMatch(source, /replace\(\/'\/g, "\\\\'"\)/, file + ' escapes quotes by hand');
		assert.doesNotMatch(source, /data-fh-[a-z]+="[^"\n]*'\$\{(?!(?:f|c|u|g|p|b|d|a|r)\.id\b|scope\b)[^}\n]*\}'/, file + ' interpolates a value inside quotes in a handler');
	}
});
