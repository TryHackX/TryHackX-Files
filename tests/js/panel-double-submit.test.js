'use strict';

/**
 * Writes that are not idempotent run once per click burst: a second click while the first
 * request is still out is swallowed (FHUi.singleFlight, ui.js), and the action works again once
 * it settled. Each module is loaded with the real ui.js and a forgiving DOM.
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const { createLooseDocument } = require('./support/loose-dom');

const jsDir = path.resolve(__dirname, '../../public/assets/js');
const read = name => fs.readFileSync(path.join(jsDir, name), 'utf8');
const flush = async () => { for (let i = 0; i < 8; i += 1) await new Promise(resolve => setImmediate(resolve)); };

function loadModule(file, fields = {}) {
	const extra = {};
	for (const [id, value] of Object.entries(fields)) extra[id] = { id, value, checked: false, textContent: '', style: {}, dataset: {} };
	const document = createLooseDocument(extra);
	const posts = [];
	const pending = [];
	const window = {
		document,
		t: (key, params) => (params ? key + ' ' + JSON.stringify(params) : key),
		FHUtil: {
			esc: v => String(v ?? ''), jsArg: v => "'" + String(v ?? '') + "'", attr: v => String(v ?? ''),
			safeHttpUrl: v => String(v || ''), formatSize: v => String(v), formatDate: v => String(v)
		},
		FHApi: {
			get: async () => ({ success: false }),
			post: (action, body) => {
				posts.push({ action, body });
				return new Promise(resolve => pending.push(resolve));
			},
			postForm: async () => ({ success: true }),
			navigatePost() {}
		},
		FHPanelCore: { refreshUsers() {}, renderPager() {}, resetManageUserPasswordValidation() {} },
		FHPanelUsers: { refreshCurrentPage() {} },
		showModal() {}, closeModal() {}, openModal() {}, showConfirm() {},
		showNotification() {}, flashMessage() {},
		addEventListener() {}, removeEventListener() {}
	};
	window.window = window;
	const context = {
		window, document, console, Promise, URL, URLSearchParams, Map, Set, FormData: class { append() {} },
		setTimeout: () => 1, clearTimeout() {}, requestAnimationFrame: cb => cb()
	};
	context.FHApi = window.FHApi;
	vm.createContext(context);
	vm.runInContext(read('ui.js'), context, { filename: 'ui.js' });
	vm.runInContext(read(file), context, { filename: file });
	const settle = answer => { pending.splice(0).forEach(resolve => resolve(answer)); };
	return { window, posts, settle };
}

for (const [file, module, action, fields, expected] of [
	['panel-premium.js', 'FHPanelPremium', 'grantPlan', { pgPlan: '3', pgDays: '' }, 'admin_plan_grant'],
	['panel-premium.js', 'FHPanelPremium', 'savePlan', {}, 'admin_plan_save'],
	['panel-premium.js', 'FHPanelPremium', 'savePromoForm', { promoFormScope: 'all' }, 'admin_promo_code_save'],
	['panel-ads.js', 'FHPanelAds', 'saveAdForm', {}, 'admin_ad_save'],
	['panel-ads.js', 'FHPanelAds', 'savePackageForm', {}, 'admin_ad_package_save'],
	['panel-ads.js', 'FHPanelAds', 'saveMyAdForm', { myAdFormId: '5', myAdFormName: 'Spring sale' }, 'my_ad_save'],
	['panel-groups.js', 'FHPanelGroups', 'saveGroup', { grpName: 'Premium' }, 'admin_group_save'],
	['panel-moderation.js', 'FHPanelModeration', 'executeAddBan', { banTypeInput: 'ip', banValueInput: '203.0.113.7' }, 'admin_ban_ip']
]) {
	test(`${module}.${action}: a double click sends one request`, async () => {
		const { window, posts, settle } = loadModule(file, fields);
		window[module][action]();
		window[module][action]();
		await flush();
		assert.deepEqual(posts.map(p => p.action), [expected]);

		settle({ success: false, error: 'nope' });
		await flush();
		window[module][action]();
		await flush();
		assert.equal(posts.length, 2, 'once the first request settled the action works again');
	});
}

test('an IP ban takes IPv4, IPv6 and an IPv6 /64 network, and nothing else', async () => {
	const tries = {
		'203.0.113.7': true,
		'2001:db8::1': true,
		'2001:db8:1:2::/64': true,
		'::ffff:203.0.113.7': true,
		'2001:db8::zz': false,
		'999.1.1.1': false,
		'2001:db8::/48': false,
		'1.2.3.4/64': false,
		'not an address': false
	};
	for (const [value, accepted] of Object.entries(tries)) {
		const { window, posts } = loadModule('panel-moderation.js', { banTypeInput: 'ip', banValueInput: value });
		window.FHPanelModeration.executeAddBan();
		await flush();
		assert.equal(posts.length, accepted ? 1 : 0, value);
		if (accepted) assert.equal(posts[0].body.value, value);
	}
});
