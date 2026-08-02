'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const source = fs.readFileSync(
	path.join(__dirname, '../../public/assets/js/collection.js'),
	'utf8'
);
const page = fs.readFileSync(
	path.join(__dirname, '../../public/collection.php'),
	'utf8'
);
const panelCss = fs.readFileSync(
	path.join(__dirname, '../../public/assets/css/panel.css'),
	'utf8'
);

test('public collection password modal follows shared dismissal and focus behaviour', () => {
	assert.match(page, /id="collMembersModal"[^>]*role="dialog"[^>]*aria-modal="true"[^>]*aria-hidden="true"/);
	assert.match(page, /#collMembersModal\s*\{[^}]*align-items:\s*flex-start/s);
	assert.match(source, /event\.key\s*!==\s*'Escape'/);
	assert.match(source, /event\.key\s*!==\s*'Tab'/);
	assert.match(source, /addEventListener\('mousedown'/);
	assert.match(source, /addEventListener\('mouseup'/);
	assert.match(source, /memberModalPreviousFocus\?\.focus/);
});

test('panel collection member password controls use full-size inputs', () => {
	const rule = panelCss.match(/\.collection-password-row input\s*\{([^}]*)\}/);
	assert.ok(rule);
	assert.match(rule[1], /width:\s*100%/);
	assert.match(rule[1], /min-height:\s*42px/);
});
