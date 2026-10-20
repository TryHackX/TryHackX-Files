'use strict';

/**
 * Guards for the phone layout fixes measured in a browser at 360/375 px (round-2 UI audit):
 * each assertion names the defect it keeps from coming back.
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const root = path.resolve(__dirname, '../..');
const read = relative => fs.readFileSync(path.join(root, relative), 'utf8');
const css = name => read('public/assets/css/' + name).replace(/\/\*[\s\S]*?\*\//g, '');

/** The bodies of every `@media (<query>) { ... }` block in a stylesheet, joined. */
function mediaBlocks(source, query) {
	const blocks = [];
	let at = source.indexOf('@media (' + query + ')');
	while (at >= 0) {
		const open = source.indexOf('{', at);
		let depth = 0;
		for (let i = open; i < source.length; i += 1) {
			if (source[i] === '{') depth += 1;
			else if (source[i] === '}' && --depth === 0) { blocks.push(source.slice(open + 1, i)); break; }
		}
		at = source.indexOf('@media (' + query + ')', at + 1);
	}
	return blocks.join('\n');
}

test('ticking rows on a phone does not push the table down: the selection actions float', () => {
	const premium = css('panel-premium.css');
	assert.match(premium, /\.selection-bar\s*\{\s*display:\s*contents;/);
	const phone = mediaBlocks(premium, 'max-width: 600px');
	assert.match(phone, /\.selection-bar\s*\{[^}]*position:\s*fixed;[^}]*bottom:/);
	for (const view of ['src/includes/panel/views_files.php', 'src/includes/panel/views_myfiles.php']) {
		const markup = read(view);
		const buttons = markup.match(/class="btn [^"]*collect-slide"/g) || [];
		const wrapped = markup.match(/<div class="selection-bar">\s*(?:<\?php[^?]*\?>\s*)?<button class="btn [^"]*collect-slide"/g) || [];
		assert.ok(buttons.length > 0, view);
		assert.ok(wrapped.length > 0, view + ' keeps its selection actions outside a .selection-bar');
	}
});

test('the Tracking filter row wraps instead of widening the page to 654px', () => {
	const markup = read('src/includes/panel/views_moderate.php');
	assert.match(markup, /<div class="track-filter">/);
	assert.doesNotMatch(markup, /class="search" style="width/);
	const forms = css('panel-forms.css');
	assert.match(forms, /\.track-filter\s*\{[^}]*flex-wrap:\s*wrap;/);
	assert.match(forms, /\.track-filter \.search\s*\{[^}]*min-width:\s*0;/);
});

test('the notification preference matrix keeps its toggles on screen', () => {
	const phone = mediaBlocks(css('notifications.css'), 'max-width: 600px');
	assert.match(phone, /\.notif-prefs td:not\(:first-child\)\s*\{[^}]*width:\s*56px;/);
	assert.match(read('public/assets/js/panel-table-sort.js'), /\.shortcuts-table, \.notif-prefs/);
});

test('the download button is on the first screen of a 360px phone', () => {
	const phone = mediaBlocks(css('download.css'), 'max-width: 500px');
	assert.match(phone, /\.file-meta-grid\s*\{[^}]*repeat\(2, minmax\(0, 1fr\)\)/);
	assert.match(phone, /\.actions,\s*\.embed-section\s*\{[^}]*order:\s*-1;/);
	assert.match(phone, /\.preview iframe\s*\{[^}]*height:\s*min\(/);
});

test('the signed-in header fits one row at 360px (no lone account button)', () => {
	const phone = mediaBlocks(css('base.css'), 'max-width: 600px');
	assert.match(phone, /\.header-actions\s*\{[^}]*gap:\s*6px;/);
	assert.match(phone, /\.lang-switch\s*\{[^}]*margin-right:\s*0;/);
});

test('the banner cropper controls wrap at 360px', () => {
	const ads = css('panel-ads-admin.css');
	assert.match(ads, /\.ad-cropper-controls\s*\{[^}]*flex-wrap:\s*wrap;/);
	assert.match(ads, /\.ad-cropper-controls input\[type="range"\]\s*\{[^}]*min-width:\s*0;/);
});

test('the remaining small controls are finger-sized on touch screens', () => {
	assert.match(mediaBlocks(css('panel-features.css'), 'pointer: coarse'), /th input\[type="checkbox"\]\s*\{\s*width:\s*22px;/);
	assert.match(mediaBlocks(css('panel-features.css'), 'pointer: coarse'), /\.range-btn\s*\{[^}]*min-height:\s*40px;/);
	assert.match(mediaBlocks(css('index.css'), 'pointer: coarse'), /\.copy-all\s*\{[^}]*min-height:\s*40px;/);
});

test('four stat tiles make two rows of two on a phone', () => {
	assert.match(mediaBlocks(css('panel.css'), 'max-width: 600px'),
		/\.stats:has\(> \.stat:nth-child\(4\):last-child\)\s*\{[^}]*repeat\(2, minmax\(0, 1fr\)\)/);
});

test('a long uploads path breaks inside the System box', () => {
	assert.match(read('src/includes/panel/views_settings.php'), /<div class="sysinfo-box">/);
	assert.match(css('panel-forms.css'), /\.sysinfo-box p\s*\{[^}]*overflow-wrap:\s*anywhere;/);
});
