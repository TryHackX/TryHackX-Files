'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(
	path.join(__dirname, '..', '..', 'public', 'assets', 'js', 'util.js'),
	'utf8'
);

function loadUtil() {
	const document = {
		createElement() {
			let value = '';
			return {
				set textContent(next) { value = String(next); },
				get innerHTML() {
					return value.replace(/[&<>"]/g, character => ({
						'&': '&amp;',
						'<': '&lt;',
						'>': '&gt;',
						'"': '&quot;'
					})[character]);
				}
			};
		}
	};
	const context = { document };
	context.window = context;
	vm.createContext(context);
	vm.runInContext(source, context, { filename: 'util.js' });
	return context.FHUtil;
}

test('safeHttpUrl accepts HTTP(S) and ordinary relative application paths', () => {
	const { safeHttpUrl } = loadUtil();
	assert.equal(safeHttpUrl('https://cdn.example.test/banner.png'), 'https://cdn.example.test/banner.png');
	assert.equal(safeHttpUrl('/collection.php?id=abc'), '/collection.php?id=abc');
	assert.equal(safeHttpUrl('panel.php?tab=files'), 'panel.php?tab=files');
	assert.equal(safeHttpUrl('../download.php'), '../download.php');
});

test('safeHttpUrl rejects executable, protocol-relative and ambiguous URLs', () => {
	const { safeHttpUrl } = loadUtil();
	for (const unsafe of [
		'javascript:alert(1)',
		'data:text/html,<script>alert(1)</script>',
		'vbscript:msgbox(1)',
		'//evil.example/path',
		'\\\\evil.example\\path',
		'https://safe.example/\njavascript:alert(1)'
	]) {
		assert.equal(safeHttpUrl(unsafe), '', unsafe);
	}
});
