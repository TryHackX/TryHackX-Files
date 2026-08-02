'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(
	path.join(__dirname, '../../public/assets/js/panel-table-sort.js'),
	'utf8'
);
const cssSource = fs.readFileSync(
	path.join(__dirname, '../../public/assets/css/panel.css'),
	'utf8'
);

function loadModule() {
	const document = {
		getElementById() { return null; },
		querySelectorAll() { return []; },
		addEventListener() {},
		body: {}
	};
	class MutationObserver { observe() {} }
	const window = { document, t: key => key };
	const context = { document, window, MutationObserver, Element: class Element {}, Intl, console };
	window.window = window;
	vm.runInNewContext(source, context, { filename: 'panel-table-sort.js' });
	return window.FHTableSort;
}

test('generic sorter cycles descending, ascending and removed', () => {
	const sorter = loadModule();
	let state = sorter.nextSorts([], 2, false, true);
	assert.deepEqual(JSON.parse(JSON.stringify(state)), [{ column: 2, dir: 'desc' }]);
	state = sorter.nextSorts(state, 2, false, true);
	assert.deepEqual(JSON.parse(JSON.stringify(state)), [{ column: 2, dir: 'asc' }]);
	state = sorter.nextSorts(state, 2, false, true);
	assert.deepEqual(JSON.parse(JSON.stringify(state)), []);
});

test('shift sorting keeps priorities and removes only the third-clicked column', () => {
	const sorter = loadModule();
	let state = sorter.nextSorts([{ column: 1, dir: 'desc' }], 3, true, true);
	state = sorter.nextSorts(state, 3, true, true);
	state = sorter.nextSorts(state, 3, true, true);
	assert.deepEqual(JSON.parse(JSON.stringify(state)), [{ column: 1, dir: 'desc' }]);
});

test('numeric parser compares storage sizes by bytes', () => {
	const sorter = loadModule();
	assert.equal(sorter.numericValue('1 GiB'), 1024 ** 3);
	assert.equal(sorter.numericValue('50%'), 50);
});

test('responsive tables keep natural column widths and an intact rounded edge', () => {
	assert.doesNotMatch(cssSource, /table-layout:\s*fixed/i);
	assert.doesNotMatch(cssSource, /overflow-wrap:\s*anywhere/i);
	const wrapperRule = cssSource.match(/\.table-wrap\s*\{([\s\S]*?)\n\}/);
	assert.ok(wrapperRule);
	assert.doesNotMatch(wrapperRule[1], /scrollbar-gutter|contain:\s*inline-size/i);
});

test('generic sorter appends its icon without a layout-changing spacer node', () => {
	assert.doesNotMatch(source, /createTextNode\(['"]\s+['"]\)/);
});
