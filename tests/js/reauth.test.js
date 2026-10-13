'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(
	path.join(__dirname, '../../public/assets/js/reauth.js'),
	'utf8'
);

/** Just enough DOM for the dialog: elements, attributes, children, listeners and classes. */
function fakeDocument() {
	const byId = new Map();
	function element(tag) {
		const listeners = {};
		const classes = new Set();
		const node = {
			tagName: tag.toUpperCase(),
			attributes: {},
			children: [],
			parent: null,
			textContent: '',
			value: '',
			disabled: false,
			classList: {
				add: name => classes.add(name),
				remove: name => classes.delete(name),
				contains: name => classes.has(name),
			},
			set className(value) {
				classes.clear();
				String(value).split(/\s+/).filter(Boolean).forEach(name => classes.add(name));
			},
			get className() {
				return [...classes].join(' ');
			},
			setAttribute(name, value) {
				this.attributes[name] = String(value);
				if (name === 'id') byId.set(String(value), this);
				if (name === 'class') this.className = value;
			},
			appendChild(child) {
				child.parent = this;
				this.children.push(child);
				return child;
			},
			remove() {
				if (this.parent) {
					this.parent.children = this.parent.children.filter(c => c !== this);
				}
				if (this.attributes.id) byId.delete(this.attributes.id);
			},
			addEventListener(type, handler) {
				(listeners[type] = listeners[type] || []).push(handler);
			},
			dispatch(type, extra = {}) {
				const event = Object.assign({ type, target: this, preventDefault() {} }, extra);
				(listeners[type] || []).forEach(handler => handler(event));
			},
			focus() {},
		};
		return node;
	}
	const body = element('body');
	return {
		body,
		createElement: element,
		getElementById: id => byId.get(id) || null,
	};
}

function load(responses) {
	const document = fakeDocument();
	const calls = [];
	const window = {
		document,
		t: key => key,
		FHApi: {
			get(action, params) {
				calls.push(['get', action, params]);
				return Promise.resolve(responses.shift());
			},
			post(action, body) {
				calls.push(['post', action, body]);
				return Promise.resolve(responses.shift());
			},
			postForm(action) {
				calls.push(['postForm', action]);
				return Promise.resolve(responses.shift());
			},
		},
	};
	window.window = window;
	vm.runInNewContext(source, {
		window,
		document,
		Promise,
		setTimeout: fn => { fn(); return 1; },
	}, { filename: 'reauth.js' });
	return { window, document, calls };
}

const tick = () => new Promise(resolve => setImmediate(resolve));

test('ordinary answers pass straight through', async () => {
	const { window, document, calls } = load([{ success: true, n: 1 }]);
	const data = await window.FHApi.post('admin_group_save', { id: 1 });
	assert.deepEqual(JSON.parse(JSON.stringify(data)), { success: true, n: 1 });
	assert.equal(calls.length, 1);
	assert.equal(document.getElementById('reauthModal'), null);
});

test('recent_auth_required asks for the password, confirms it and retries once', async () => {
	const { window, document, calls } = load([
		{ success: false, code: 'recent_auth_required', error: 'again' },
		{ success: true }, // user_reauth
		{ success: true, saved: 1 }, // the retried action
	]);
	const pending = window.FHApi.post('admin_update_user', { user_id: 5, role: 'admin' });
	await tick();

	const dialog = document.getElementById('reauthModal');
	assert.ok(dialog, 'the password dialog opens');
	assert.equal(dialog.classList.contains('show'), true);
	document.getElementById('reauthModalPassword').value = 'Secret1!';
	document.getElementById('reauthModalForm').dispatch('submit');

	const data = await pending;
	assert.deepEqual(JSON.parse(JSON.stringify(data)), { success: true, saved: 1 });
	assert.deepEqual(JSON.parse(JSON.stringify(calls)), [
		['post', 'admin_update_user', { user_id: 5, role: 'admin' }],
		['post', 'user_reauth', { password: 'Secret1!' }],
		['post', 'admin_update_user', { user_id: 5, role: 'admin' }],
	]);
	assert.equal(document.getElementById('reauthModal'), null, 'the dialog is removed afterwards');
});

test('a wrong password keeps the dialog open with the server message', async () => {
	const { window, document } = load([
		{ success: false, code: 'recent_auth_required', error: 'again' },
		{ success: false, error: 'Wrong password' },
	]);
	window.FHApi.post('admin_premium_token', {});
	await tick();
	document.getElementById('reauthModalPassword').value = 'nope';
	document.getElementById('reauthModalForm').dispatch('submit');
	await tick();
	await tick();

	assert.ok(document.getElementById('reauthModal'));
	assert.equal(document.getElementById('reauthModalMessage').textContent, 'Wrong password');
});

test('cancelling hands the original refusal back to the caller', async () => {
	const refusal = { success: false, code: 'recent_auth_required', error: 'again' };
	const { window, document, calls } = load([refusal]);
	const pending = window.FHApi.get('anything');
	await tick();
	const dialog = document.getElementById('reauthModal');
	dialog.dispatch('keydown', { key: 'Escape' });

	const data = await pending;
	assert.equal(data.code, 'recent_auth_required');
	assert.equal(calls.length, 1, 'nothing is retried');
});
