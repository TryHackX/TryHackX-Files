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

test('a dismissed banner crop lets go of its image, and the same file can be picked again', () => {
	const elements = {};
	const element = id => {
		const listeners = {};
		const node = {
			id, value: '', files: [], textContent: '', src: '', style: {}, dataset: {},
			parentElement: { clientWidth: 400 },
			classList: { add() {}, remove() {}, contains: () => false },
			addEventListener(type, handler) { (listeners[type] ||= []).push(handler); },
			dispatch(type, event = {}) { (listeners[type] || []).forEach(handler => handler(Object.assign({ type }, event))); },
			setPointerCapture() {}
		};
		elements[id] = node;
		return node;
	};
	['adFormDrop', 'adFormFile', 'adFormDropIdle', 'adFormDropDone', 'adFormDropThumb', 'adCropModal', 'adCropFrame', 'adCropZoom', 'adCropImg', 'adCropModalDims'].forEach(element);
	const revoked = [];
	const opened = [];
	const closed = [];
	const context = {
		document: {
			getElementById: id => elements[id] || null,
			querySelector: () => null,
			querySelectorAll: () => []
		},
		FHUtil: { esc: v => String(v), safeHttpUrl: v => String(v || ''), formatDate: v => String(v) },
		t: key => key,
		openModal: id => opened.push(id),
		closeModal: id => closed.push(id),
		showNotification() {},
		URL: { createObjectURL: () => 'blob:banner-1', revokeObjectURL: url => revoked.push(url) },
		Image: class { set src(value) { this._src = value; this.naturalWidth = 1200; this.naturalHeight = 300; this.onload(); } },
		requestAnimationFrame: cb => cb(),
		setTimeout,
		clearTimeout
	};
	context.window = context;
	vm.createContext(context);
	vm.runInContext(source, context, { filename: 'panel-ads.js' });

	context.FHPanelAds.initAdUploader('adForm', () => ({ w: 960, h: 120 }));
	const input = elements.adFormFile;
	input.files = [{ name: 'banner.png', type: 'image/png' }];
	input.value = 'C:\\fakepath\\banner.png';
	input.dispatch('change');
	assert.equal(input.value, '', 'the input is cleared, so picking the same file again fires change');
	assert.deepEqual(opened, ['adCropModal']);

	// Escape or the backdrop: the panel announces the dismissal on the modal itself.
	elements.adCropModal.dispatch('fh:modal-dismissed');
	assert.deepEqual(revoked, ['blob:banner-1'], 'the object URL is released');
	context.FHPanelAds.adCropApply();
	assert.equal(elements.adFormDropThumb.src, '', 'a crop that was dismissed cannot be applied afterwards');
	assert.deepEqual(closed, ['adCropModal']);
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
