'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(
	path.join(__dirname, '../../public/assets/js/panel-settings.js'),
	'utf8'
);

function loadModule() {
	const bootstrap = {
		dataset: {
			config: JSON.stringify({ host: 'files.example.test' })
		}
	};
	const document = {
		getElementById(id) {
			return id === 'panelBootstrap' ? bootstrap : null;
		}
	};
	const window = {
		FHUtil: { formatSize: value => String(value) },
		t: key => key,
		document
	};
	const context = {
		console,
		document,
		FormData: class {},
		location: { reload() {} },
		setTimeout() { return 1; },
		window
	};
	window.window = window;
	vm.runInNewContext(source, context, { filename: 'panel-settings.js' });
	return window.FHPanelSettings;
}

test('settings module exposes a frozen settings and self-service API', () => {
	const settings = loadModule();

	assert.ok(settings);
	assert.equal(Object.isFrozen(settings), true);
	for (const action of [
		'toggleEmailFields',
		'toggleRecaptchaFields',
		'confirmCleanup',
		'previewCleanup',
		'initPanelValidation',
		'loadUserStats',
		'submitPasswordConfirm',
		'changeUserPassword',
		'changeUserEmail',
		'confirmDeleteAllFiles',
		'confirmDeleteAccount'
	]) {
		assert.equal(typeof settings[action], 'function', action);
	}
});

test('settings initializers are harmless outside their server-rendered tabs', () => {
	const settings = loadModule();

	settings.toggleEmailFields();
	settings.toggleRecaptchaFields();
	settings.initPanelValidation();
	settings.loadUserStats();
});

test('settings module contains no inline event attributes', () => {
	assert.doesNotMatch(source, /\son(?:click|change|input|submit|load|keydown)\s*=/i);
});

test('account password meter reads the actual password input bounds', () => {
	assert.match(source, /Number\(passInput\.minLength\)/);
	assert.doesNotMatch(source, /Number\(pass\.minLength\)/);
});
