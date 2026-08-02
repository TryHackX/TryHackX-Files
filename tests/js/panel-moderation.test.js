'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(
	path.join(__dirname, '..', '..', 'public', 'assets', 'js', 'panel-moderation.js'),
	'utf8'
);

function loadModule() {
	const bootstrap = { dataset: { config: JSON.stringify({
		appUrl: 'http://pon.localhost',
		apiUrl: 'http://pon.localhost/api.php'
	}) } };
	const context = {
		document: {
			getElementById: (id) => id === 'panelBootstrap' ? bootstrap : null
		},
		FHUtil: {
			esc: (value) => String(value),
			formatSize: (value) => String(value),
			formatDate: (value) => String(value)
		},
		t: (key) => key,
		setTimeout,
		clearTimeout,
		URL
	};
	context.window = context;
	vm.createContext(context);
	vm.runInContext(source, context, { filename: 'panel-moderation.js' });
	return context;
}

test('moderation module exposes a frozen bans, reports and audit API', () => {
	const context = loadModule();
	assert.equal(Object.isFrozen(context.FHPanelModeration), true);
	for (const action of [
		'loadIPBans',
		'executeAddBan',
		'loadReports',
		'showReportDetails',
		'confirmRejectReport',
		'confirmDeleteReported',
		'loadAuditLog',
		'refreshReports'
	]) {
		assert.equal(typeof context.FHPanelModeration[action], 'function', action);
	}
});

test('report and audit loaders are no-ops outside moderation markup', async () => {
	const { FHPanelModeration } = loadModule();
	await FHPanelModeration.loadReports();
	await FHPanelModeration.loadAuditLog();
});

test('moderation templates use declarative event attributes', () => {
	assert.doesNotMatch(
		source,
		/(?<!\.)\bon(?:click|submit|change|input|error|load)\s*=/i
	);
	assert.match(source, /data-fh-click=/);
});
