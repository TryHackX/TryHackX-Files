'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(
	path.join(__dirname, '..', '..', 'public', 'assets', 'js', 'panel-ads.js'),
	'utf8'
);

function loadModule() {
	const context = {
		document: {
			getElementById: () => null,
			querySelector: () => null,
			querySelectorAll: () => []
		},
		FHUtil: {
			esc: (value) => String(value),
			safeHttpUrl: (value) => /^javascript:/i.test(String(value || '')) ? '' : String(value || ''),
			formatDate: (value) => String(value)
		},
		t: (key) => key,
		setTimeout,
		clearTimeout
	};
	context.window = context;
	vm.createContext(context);
	vm.runInContext(source, context, { filename: 'panel-ads.js' });
	return context;
}

test('advertising module exposes a frozen, tab-scoped API', () => {
	const context = loadModule();
	assert.equal(Object.isFrozen(context.FHPanelAds), true);
	for (const action of [
		'loadAdsSettings',
		'loadAdsManager',
		'loadAdsQueue',
		'loadAdsPackages',
		'loadAdsStats',
		'loadMyAds',
		'openAdForm',
		'openPackageForm',
		'openMyAdRenew',
		'initAdUploader'
	]) {
		assert.equal(typeof context.FHPanelAds[action], 'function', action);
	}
});

test('advertising loaders are harmless when their tab markup is absent', async () => {
	const { FHPanelAds } = loadModule();
	await FHPanelAds.loadAdsSettings();
	await FHPanelAds.loadAdsManager();
	await FHPanelAds.loadAdsQueue();
	await FHPanelAds.loadAdsPackages();
	await FHPanelAds.loadAdsStats();
	await FHPanelAds.loadMyAds();
	FHPanelAds.initAdUploader('missing', () => ({ w: 1, h: 1 }));
});

test('advertising templates use declarative event attributes', () => {
	assert.doesNotMatch(
		source,
		/(?<!\.)\bon(?:click|submit|change|input|error|load)\s*=/i
	);
	assert.match(source, /data-fh-click=/);
	assert.match(source, /data-fh-change=/);
	assert.match(source, /safeHttpUrl\(a\.targetUrl\)/);
	assert.match(source, /safeHttpUrl\(a\.imageUrl\)/);
	assert.match(source, /class="admin-ad-thumb"/);
	assert.match(source, /mine\.invoicesEnabled/);
	assert.match(source, /action=invoice&order=/);
});
