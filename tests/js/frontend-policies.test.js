'use strict';

/**
 * Source-level guards for front-end rules that are easy to regress and awkward to exercise in
 * a DOM: each assertion names the bug it prevents.
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const root = path.resolve(__dirname, '../..');
const read = relative => fs.readFileSync(path.join(root, relative), 'utf8');
const js = name => read('public/assets/js/' + name);

/** Source of `function name(` up to its closing brace at the same indent. */
function functionBody(source, name) {
	const at = source.search(new RegExp('(?:async\\s+)?function\\s+' + name + '\\s*\\('));
	assert.ok(at >= 0, name + ' not found');
	const open = source.indexOf('{', at);
	let depth = 0;
	for (let i = open; i < source.length; i += 1) {
		if (source[i] === '{') depth += 1;
		else if (source[i] === '}' && --depth === 0) return source.slice(open, i + 1);
	}
	throw new Error('unbalanced ' + name);
}

test('create/submit handlers run through FHUi.singleFlight so a double click cannot repeat them', () => {
	for (const [file, fn] of [
		['panel-files.js', 'submitCreateCollection'],
		['panel-users.js', 'createUser'],
		['panel-account-tools.js', 'submitCreateApiKey'],
		['panel-account-tools.js', 'submitCreateWebhook'],
		['download.js', 'doDownload'],
		['download.js', 'submitReport'],
		['index.js', 'submitCollectionHome'],
		['index.js', 'submitPwSet']
	]) {
		assert.match(functionBody(js(file), fn), /singleFlight\(/, file + ' ' + fn);
	}
});

test('clipboard writes report their real outcome', () => {
	for (const file of fs.readdirSync(path.join(root, 'public/assets/js')).filter(name => /^(panel|index)/.test(name))) {
		assert.doesNotMatch(
			js(file),
			/navigator\.clipboard\.writeText\(/,
			file + ' calls the clipboard directly; use FHUtil.copyText and look at its result'
		);
	}
});

test('the banner crop frees its object URLs and says so when an image cannot be read', () => {
	const source = js('panel-ads.js');
	assert.match(source, /probe\.onerror\s*=/);
	assert.match(source, /t\('panel\.ads\.crop_bad'\)/);
	assert.ok((source.match(/revokeObjectURL/g) || []).length >= 3, 'object URLs must be revoked on apply, cancel, error and clear');
});

test('sortable table headers can be reached and operated from the keyboard', () => {
	const source = js('panel-table-sort.js');
	assert.match(source, /setAttribute\('tabindex', '0'\)/);
	assert.match(source, /event\.key !== 'Enter' && event\.key !== ' '/);
});

test('the dead user-dropdown and parallax code is gone', () => {
	assert.doesNotMatch(js('panel.js'), /userDropdown|toggleUserDropdown/);
	assert.doesNotMatch(js('download.js'), /startParallax|orb1|orb2|mousemove/);
	assert.doesNotMatch(js('auth.js'), /resetPasswordMeter|updateThemeIcons/);
	assert.doesNotMatch(js('index.js'), /pwSetRequest|goToMyFiles|goToAdmin/);
});

test('user-visible strings in the scripts come from the dictionary, not from literals', () => {
	assert.doesNotMatch(js('auth.js'), /[ąćęłńóśźżĄĆĘŁŃÓŚŹŻ]/, 'auth.js still carries a Polish literal');
	assert.doesNotMatch(js('index.js'), /'Max '/);
	for (const file of ['index.js', 'download.js', 'panel-account-tools.js']) {
		assert.doesNotMatch(js(file), /\.alt\s*=\s*'[^']/, file + ' hard-codes an alt text');
	}
	assert.doesNotMatch(js('panel-moderation.js'), /detailCell\('E-mail'/);
});

test('the captcha widget follows the page theme', () => {
	assert.match(js('captcha.js'), /classList\.contains\('light'\)/);
	assert.doesNotMatch(js('captcha.js'), /theme:\s*options\.theme\s*\|\|\s*'dark'/);
});

test('"My files" asks for the collection list once', () => {
	const source = js('panel-files.js');
	assert.equal((source.match(/FHApi\.get\('user_collections'\)/g) || []).length, 1);
	assert.match(source, /myCollectionsRequest/);
});
