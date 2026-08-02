'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(
	path.join(__dirname, '../../public/assets/js/panel-premium.js'),
	'utf8'
);
const cssSource = fs.readFileSync(
	path.join(__dirname, '../../public/assets/css/panel-premium.css'),
	'utf8'
);
const panelSource = fs.readFileSync(
	path.join(__dirname, '../../public/assets/js/panel.js'),
	'utf8'
);

function loadModule() {
	const bootstrap = {
		dataset: {
			config: JSON.stringify({
				apiUrl: '/api.php',
				appUrl: '/pon'
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
		navigator: { clipboard: { writeText: async () => {} } },
		setTimeout() { return 1; },
		window
	};
	window.window = window;
	vm.runInNewContext(source, context, { filename: 'panel-premium.js' });
	return window.FHPanelPremium;
}

test('premium module exposes a frozen, bounded action surface', () => {
	const premium = loadModule();

	assert.ok(premium);
	assert.equal(Object.isFrozen(premium), true);
	for (const action of [
		'loadPlans',
		'loadPaymentPlugins',
		'loadPromoCodes',
		'loadMyPremium',
		'loadPremiumOverview',
		'loadPremiumPayments',
		'loadPremiumSubscribers',
		'openPlanGrant',
		'onPlanLimitsToggle',
		'openBulkPlanGrant',
		'updateBulkSourceFields',
		'previewBulkPlanGrant',
		'executeBulkPlanGrant',
		'onPromoScopeChange',
		'schedulePremiumSearch'
	]) {
		assert.equal(typeof premium[action], 'function', action);
	}
});

test('every declarative bulk-plan action is registered by the panel dispatcher', () => {
	const publicActions = panelSource.match(/const publicActions\s*=\s*\{([\s\S]*?)\n\t\};/);
	assert.ok(publicActions, 'publicActions registry');
	for (const action of [
		'onPlanLimitsToggle',
		'openBulkPlanGrant',
		'updateBulkSourceFields',
		'previewBulkPlanGrant',
		'executeBulkPlanGrant'
	]) {
		assert.match(publicActions[1], new RegExp(`\\b${action}\\b`), action);
	}
});

test('premium loaders are no-ops when their server-rendered panels are absent', async () => {
	const premium = loadModule();

	await premium.loadPlans();
	await premium.loadPaymentPlugins();
	await premium.loadPromoCodes();
	await premium.loadMyPremium();
	await premium.loadPremiumOverview();
	await premium.loadPremiumPayments();
	await premium.loadPremiumSubscribers();
});

test('premium templates use declarative event attributes', () => {
	assert.match(source, /data-fh-click="openPlanForm\(/);
	assert.match(source, /data-fh-change="togglePlanEnabled\(/);
	assert.doesNotMatch(source, /\son(?:click|change|input|submit|load)\s*=/i);
});

test('payment provider flags share a wrapping header and are not absolutely stacked', () => {
	assert.match(source, /class="plugin-card-top"/);
	assert.match(source, /class="plugin-flags"/);
	const flagRule = cssSource.match(/\.plugin-flag\s*\{([^}]*)\}/);
	assert.ok(flagRule);
	assert.match(flagRule[1], /display:\s*inline-flex/);
	assert.doesNotMatch(flagRule[1], /position:\s*absolute/);
});
