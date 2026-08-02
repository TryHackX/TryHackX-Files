'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(
	path.join(__dirname, '..', '..', 'public', 'assets', 'js', 'panel-docs.js'),
	'utf8'
);

function escapeHtml(value) {
	return String(value).replace(/[&<>"']/g, (character) => ({
		'&': '&amp;',
		'<': '&lt;',
		'>': '&gt;',
		'"': '&quot;',
		"'": '&#39;'
	})[character]);
}

function loadModule() {
	const context = {
		document: {},
		FHUtil: { esc: escapeHtml },
		t: (key) => key
	};
	context.window = context;
	vm.createContext(context);
	vm.runInContext(source, context, { filename: 'panel-docs.js' });
	return context.FHPanelDocs;
}

test('documentation renderer escapes HTML before emitting supported markup', () => {
	const docs = loadModule();
	const html = docs.renderMarkdown(
		'# Heading <img src=x onerror=alert(1)>\n\n'
		+ '**bold** and `code`\n\n'
		+ '```html\n<script>alert(1)</script>\n```'
	);

	assert.match(html, /<h1>Heading &lt;img src=x onerror=alert\(1\)&gt;<\/h1>/);
	assert.match(html, /<strong>bold<\/strong>/);
	assert.match(html, /<code>code<\/code>/);
	assert.match(html, /&lt;script&gt;alert\(1\)&lt;\/script&gt;/);
	assert.doesNotMatch(html, /<script>/);
});

test('documentation renderer links only absolute http(s) destinations', () => {
	const docs = loadModule();
	const html = docs.renderMarkdown(
		'[safe](https://example.com/docs) '
		+ '[local](MIGRATION.md) '
		+ '[blocked](javascript:alert(1))'
	);

	assert.match(html, /href="https:\/\/example\.com\/docs"/);
	assert.match(html, /<span class="doc-ref">local<\/span>/);
	assert.doesNotMatch(html, /href="javascript:/);
	assert.match(html, /\[blocked\]\(javascript:alert\(1\)\)/);
});

test('documentation renderer closes lists and tables deterministically', () => {
	const docs = loadModule();
	const html = docs.renderMarkdown(
		'- one\n- two\n\n'
		+ '| A | B |\n|---|---|\n| 1 | 2 |'
	);

	assert.match(html, /<ul>\s*<li>one<\/li>\s*<li>two<\/li>\s*<\/ul>/);
	assert.match(html, /<thead><tr><th>A<\/th><th>B<\/th><\/tr><\/thead>/);
	assert.match(html, /<tbody>\s*<tr><td>1<\/td><td>2<\/td><\/tr>\s*<\/tbody>/);
});
