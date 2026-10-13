'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(
	path.resolve(__dirname, '../../public/assets/js/panel.js'),
	'utf8'
);

/** Any property is a no-op function: stands in for the sibling panel modules panel.js destructures. */
const anyModule = () => new Proxy({}, { get: () => () => {} });

function listeners() {
	const map = {};
	return {
		add: (type, fn) => { (map[type] ||= []).push(fn); },
		fire: (type, event = {}) => (map[type] || []).forEach(fn => fn(event))
	};
}

function control(extra = {}) {
	const handlers = listeners();
	const classes = new Set();
	return {
		value: '', minLength: 8, style: {}, className: '', textContent: '',
		classList: {
			add: (...n) => n.forEach(x => classes.add(x)),
			remove: (...n) => n.forEach(x => classes.delete(x)),
			toggle: (x, force) => { const on = force === undefined ? !classes.has(x) : Boolean(force); if (on) classes.add(x); else classes.delete(x); return on; },
			contains: x => classes.has(x)
		},
		classes,
		addEventListener: handlers.add,
		fire: handlers.fire,
		...extra
	};
}

function loadPanel({ elements = {}, documentExtra = {} } = {}) {
	const bootstrap = { dataset: { config: JSON.stringify({ apiUrl: '/api.php', appUrl: '/app', tab: 'myfiles', isAdmin: false }) } };
	const document = {
		readyState: 'loading',
		visibilityState: 'visible',
		getElementById: id => (id === 'panelBootstrap' ? bootstrap : (elements[id] || null)),
		querySelector: () => null,
		querySelectorAll: () => [],
		addEventListener() {},
		body: control(),
		...documentExtra
	};
	const window = {
		FHUtil: {
			esc: v => String(v ?? ''), formatSize: String, formatDate: String,
			copyText: async () => true, safeHttpUrl: String, attr: String, jsArg: String
		},
		FHUi: { toast() {}, toggleTheme: () => 'light' },
		FHPanelFiles: anyModule(),
		FHPanelAccountTools: anyModule(),
		FHPanelUsers: anyModule(),
		FHPanelPremium: anyModule(),
		FHPanelLanguages: anyModule(),
		FHPanelGroups: anyModule(),
		FHPanelModeration: anyModule(),
		FHPanelDashboard: anyModule(),
		FHPanelSettings: anyModule(),
		FHPanelAds: anyModule(),
		t: key => key,
		document
	};
	window.window = window;
	const context = {
		window, document, console, t: key => key, FHApi: {}, MutationObserver: class {}, WeakMap, setTimeout() { return 1; },
		clearTimeout() {}, setInterval() { return 1; }, requestAnimationFrame() {}, URLSearchParams, URL, Headers: class {}
	};
	vm.createContext(context);
	vm.runInContext(source, context, { filename: 'panel.js' });
	return window;
}

test('password strength meter evaluates a typed password (it used to throw ReferenceError)', () => {
	const pass = control();
	const confirm = control();
	const bar = control();
	const match = control();
	const checks = { len: control(), upper: control(), digit: control(), spec: control() };
	const window = loadPanel({
		elements: { p: pass, c: confirm, bar, m: match, l: checks.len, u: checks.upper, d: checks.digit, s: checks.spec }
	});
	const reset = window.FHPanelCore.wirePwStrength({
		pass: 'p', confirm: 'c', bar: 'bar', match: 'm', len: 'l', upper: 'u', digit: 'd', spec: 's'
	});
	assert.equal(typeof reset, 'function');

	pass.value = 'abc';
	pass.fire('input');
	assert.equal(checks.len.classes.has('valid'), false, 'too short');
	assert.equal(bar.style.width, '0%');

	pass.value = 'Abcdef1!';
	pass.fire('input');
	for (const key of ['len', 'upper', 'digit', 'spec']) {
		assert.ok(checks[key].classes.has('valid'), key + ' should be satisfied');
	}
	assert.equal(bar.style.width, '100%');

	// the length rule follows the field's own minLength
	pass.minLength = 12;
	pass.fire('input');
	assert.equal(checks.len.classes.has('valid'), false);

	confirm.value = 'zzz';
	confirm.fire('input');
	assert.ok(confirm.classes.has('error'), 'mismatch is flagged on the confirm field');
	confirm.value = 'Abcdef1!';
	confirm.fire('input');
	assert.ok(confirm.classes.has('success'), 'a matching repeat is flagged as such');
	reset();
	assert.equal(bar.style.width, '0%');
});

/** A tiny element tree: enough surface for bindLabels(). */
function node(tag, { id = '', type = '', children = [] } = {}) {
	const n = {
		tag, id, type, children, parentNode: null, htmlFor: undefined, attrs: {},
		hasAttribute(name) { return name in this.attrs; },
		matches(selector) {
			if (selector === 'label') return tag === 'label';
			// the one compound selector bindLabels uses for controls
			if (selector.startsWith('input:not(')) {
				if (tag === 'select' || tag === 'textarea') return true;
				return tag === 'input' && !['hidden', 'checkbox', 'radio'].includes(type);
			}
			return false;
		},
		querySelector(selector) { return this.querySelectorAll(selector)[0] || null; },
		querySelectorAll(selector) {
			const out = [];
			const walk = el => el.children.forEach(child => {
				if (selector.includes('input') && (child.tag === 'input' || child.tag === 'select' || child.tag === 'textarea')) {
					if (selector.startsWith('input, select') || child.matches(selector)) out.push(child);
				} else if (selector === 'label:not([for])' && child.tag === 'label' && !('for' in child.attrs) && child.htmlFor === undefined) {
					out.push(child);
				}
				walk(child);
			});
			walk(this);
			return out;
		},
		closest(selector) {
			let cur = this;
			while (cur) { if (selector === '.form-group' && cur.cls === 'form-group') return cur; cur = cur.parentNode; }
			return null;
		},
		get nextElementSibling() {
			const siblings = this.parentNode ? this.parentNode.children : [];
			return siblings[siblings.indexOf(this) + 1] || null;
		}
	};
	children.forEach(child => { child.parentNode = n; });
	return n;
}

test('bindLabels() ties label/field pairs together but leaves ambiguous ones alone', () => {
	const window = loadPanel();
	const labelA = node('label');
	const inputA = node('input', { type: 'text', id: 'named' });
	const labelB = node('label');
	const inputB = node('input', { type: 'password' });           // no id yet
	const labelC = node('label');
	const radioA = node('input', { type: 'radio' });
	const radioB = node('input', { type: 'radio' });
	const group = node('div', { children: [labelA, inputA, labelB, inputB, labelC, radioA, radioB] });
	group.cls = 'form-group';
	const root = node('div', { children: [group] });
	// make querySelectorAll('label:not([for])') see the three labels
	const labels = [labelA, labelB, labelC];
	root.querySelectorAll = selector => (selector === 'label:not([for])' ? labels : []);
	labels.forEach(label => { label.querySelector = () => null; });

	window.FHPanelCore.bindLabels(root);

	assert.equal(labelA.htmlFor, 'named');
	assert.match(labelB.htmlFor, /^fl_\d+$/);
	assert.equal(inputB.id, labelB.htmlFor, 'a missing id is generated');
	assert.equal(labelC.htmlFor, undefined, 'a label before a radio group is not guessed');
});

test('theme toggling in the panel is the shared FHUi implementation', () => {
	const window = loadPanel();
	assert.equal(window.FHPanelActions.toggleTheme(), 'light');
	assert.equal(typeof window.FHPanelActions.toggleUserDropdown, 'undefined');
});
