'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const scriptPath = path.join(__dirname, '../../public/assets/js/panel-tabs.js');
const cssPath = path.join(__dirname, '../../public/assets/css/panel.css');

function run({ scrollWidth, clientWidth, barLeft, tabLeft, tabWidth, active = true }) {
	const tab = {
		getBoundingClientRect: () => ({ left: tabLeft, width: tabWidth })
	};
	const bar = {
		scrollWidth,
		clientWidth,
		scrollLeft: 0,
		querySelector: (sel) => (sel === '.tab.active' && active ? tab : null),
		getBoundingClientRect: () => ({ left: barLeft })
	};
	const document = {
		readyState: 'complete',
		querySelector: (sel) => (sel === '.tabs' ? bar : null),
		addEventListener() {
			throw new Error('should run immediately once the document is parsed');
		}
	};
	const loadListeners = [];
	const window = { addEventListener: (type, fn) => { if (type === 'load') loadListeners.push(fn); } };
	vm.runInNewContext(fs.readFileSync(scriptPath, 'utf8'), { document, window, Math }, { filename: scriptPath });
	bar.loadListeners = loadListeners;
	return bar;
}

test('a scrolled-away active tab is centred in the phone tab strip', () => {
	// Bar 335px wide from x=20, content 900px; active tab at x=700, 98px wide.
	const bar = run({ scrollWidth: 900, clientWidth: 335, barLeft: 20, tabLeft: 700, tabWidth: 98 });
	assert.equal(bar.scrollLeft, 700 - 20 - (335 - 98) / 2);
});

test('the position is settled again once late fonts have widened the tabs', () => {
	const bar = run({ scrollWidth: 900, clientWidth: 335, barLeft: 20, tabLeft: 700, tabWidth: 98 });
	assert.equal(bar.loadListeners.length, 1);
	// After the icon font loads the tab sits further right; the load handler re-centres it.
	bar.getBoundingClientRect = () => ({ left: 20 });
	bar.querySelector = () => ({ getBoundingClientRect: () => ({ left: 760 - bar.scrollLeft, width: 110 }) });
	bar.loadListeners[0]();
	assert.equal(bar.scrollLeft, 760 - 20 - (335 - 110) / 2);
});

test('the bar is left alone when every tab already fits (desktop)', () => {
	const bar = run({ scrollWidth: 900, clientWidth: 900, barLeft: 20, tabLeft: 700, tabWidth: 98 });
	assert.equal(bar.scrollLeft, 0);
});

test('a tab near the start never scrolls the bar to a negative offset', () => {
	const bar = run({ scrollWidth: 900, clientWidth: 335, barLeft: 20, tabLeft: 40, tabWidth: 98 });
	assert.equal(bar.scrollLeft, 0);
});

test('the phone tab strip scrolls instead of wrapping', () => {
	const css = fs.readFileSync(cssPath, 'utf8');
	const block = css.match(/@media \(max-width: 600px\) \{([\s\S]*?)\n\}/);
	assert.ok(block, 'panel.css keeps a phone block');
	assert.match(block[1], /\.tabs\s*\{[^}]*flex-wrap:\s*nowrap;[^}]*overflow-x:\s*auto;/);
});
