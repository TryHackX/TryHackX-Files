'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(
	path.join(__dirname, '..', '..', 'public', 'assets', 'js', 'panel-languages.js'),
	'utf8'
);

function loadModule() {
	const context = {
		document: {
			getElementById: () => null,
			querySelector: () => null
		},
		FHUtil: { esc: (value) => String(value) },
		t: (key) => key,
		setTimeout,
		clearTimeout
	};
	context.window = context;
	vm.createContext(context);
	vm.runInContext(source, context, { filename: 'panel-languages.js' });
	return context;
}

test('language module exposes a frozen language-management API', () => {
	const context = loadModule();
	assert.equal(Object.isFrozen(context.FHPanelLanguages), true);
	for (const action of [
		'loadLanguages',
		'toggleLanguage',
		'exportLanguage',
		'openLanguageUpload',
		'submitLanguageUpload',
		'saveUserLanguage'
	]) {
		assert.equal(typeof context.FHPanelLanguages[action], 'function', action);
	}
});

test('language loader is a no-op outside language settings', async () => {
	await loadModule().FHPanelLanguages.loadLanguages();
});

test('language templates use declarative event attributes', () => {
	assert.doesNotMatch(
		source,
		/(?<!\.)\bon(?:click|submit|change|input|error|load)\s*=/i
	);
	assert.match(source, /data-fh-click=/);
	assert.match(source, /data-fh-change=/);
});
