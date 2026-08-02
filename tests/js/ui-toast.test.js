'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

class TestElement {
	constructor(tagName) {
		this.nodeType = 1;
		this.nodeName = String(tagName).toUpperCase();
		this.children = [];
		this.childNodes = this.children;
		this.parentNode = null;
		this.attributes = {};
		this.className = '';
		this.style = {};
		this.textContent = '';
		this.listeners = {};
		this.offsetWidth = 180;
		this.offsetHeight = 36;
		this.classList = {
			add: (...names) => {
				const classes = new Set(this.className.split(/\s+/).filter(Boolean));
				names.forEach((name) => classes.add(name));
				this.className = Array.from(classes).join(' ');
			},
			remove: (...names) => {
				const removed = new Set(names);
				this.className = this.className.split(/\s+/)
					.filter((name) => name && !removed.has(name)).join(' ');
			},
			contains: (name) => this.className.split(/\s+/).includes(name)
		};
	}

	get id() {
		return this.attributes.id || '';
	}

	set id(value) {
		this.attributes.id = String(value);
	}

	get firstChild() {
		return this.children[0] || null;
	}

	get isConnected() {
		return this.nodeName === 'BODY' || Boolean(this.parentNode && this.parentNode.isConnected);
	}

	appendChild(child) {
		if (child.parentNode) child.parentNode.removeChild(child);
		child.parentNode = this;
		this.children.push(child);
		return child;
	}

	removeChild(child) {
		const index = this.children.indexOf(child);
		if (index !== -1) this.children.splice(index, 1);
		child.parentNode = null;
		return child;
	}

	cloneNode(deep) {
		const clone = new TestElement(this.nodeName);
		clone.className = this.className;
		clone.attributes = { ...this.attributes };
		clone.textContent = this.textContent;
		clone.offsetWidth = this.offsetWidth;
		clone.offsetHeight = this.offsetHeight;
		if (deep) this.children.forEach((child) => clone.appendChild(child.cloneNode(true)));
		return clone;
	}

	setAttribute(name, value) {
		this.attributes[name] = String(value);
	}

	getAttribute(name) {
		return Object.prototype.hasOwnProperty.call(this.attributes, name)
			? this.attributes[name]
			: null;
	}

	hasAttribute(name) {
		return Object.prototype.hasOwnProperty.call(this.attributes, name);
	}

	removeAttribute(name) {
		delete this.attributes[name];
	}

	addEventListener(type, callback) {
		(this.listeners[type] ||= []).push(callback);
	}

	dispatch(type, event) {
		(this.listeners[type] || []).forEach((callback) => callback(event));
	}

	querySelector(selector) {
		return this.querySelectorAll(selector)[0] || null;
	}

	querySelectorAll(selector) {
		const found = [];
		const matches = (element) => {
			if (selector === '[id]') return element.hasAttribute('id');
			if (selector === '[id$="Text"]') return element.id.endsWith('Text');
			if (selector === '[data-toast-text]') return element.hasAttribute('data-toast-text');
			if (selector === '.modern-notification-icon') {
				return element.classList.contains('modern-notification-icon');
			}
			return false;
		};
		const walk = (element) => {
			element.children.forEach((child) => {
				if (matches(child)) found.push(child);
				walk(child);
			});
		};
		walk(this);
		return found;
	}

	getBoundingClientRect() {
		return { left: 100, right: 140, top: 100, bottom: 130, width: 40, height: 30 };
	}
}

function loadUi() {
	const body = new TestElement('body');
	const template = new TestElement('div');
	template.id = 'notification';
	template.className = 'modern-notification';
	const icon = new TestElement('span');
	icon.id = 'notificationIcon';
	icon.className = 'modern-notification-icon';
	const text = new TestElement('span');
	text.id = 'notificationText';
	template.appendChild(icon);
	template.appendChild(text);
	body.appendChild(template);

	const documentListeners = {};
	const document = {
		body,
		createElement: (name) => new TestElement(name),
		getElementById: (id) => {
			if (body.id === id) return body;
			const all = [];
			const walk = (element) => {
				element.children.forEach((child) => {
					all.push(child);
					walk(child);
				});
			};
			walk(body);
			return all.find((element) => element.id === id) || null;
		},
		addEventListener: (type, callback) => {
			(documentListeners[type] ||= []).push(callback);
		},
		querySelector: () => null
	};
	let timerId = 0;
	const context = {
		Array,
		Math,
		Number,
		String,
		document,
		window: {
			innerHeight: 900,
			innerWidth: 1200,
			addEventListener() {},
			removeEventListener() {}
		},
		requestAnimationFrame: (callback) => callback(),
		setTimeout: () => ++timerId,
		clearTimeout() {},
		DOMParser: class {},
		CustomEvent: class {}
	};
	context.window.window = context.window;
	vm.createContext(context);
	const source = fs.readFileSync(
		path.resolve(__dirname, '../../public/assets/js/ui.js'),
		'utf8'
	);
	vm.runInContext(source, context, { filename: 'ui.js' });
	return { context, body };
}

test('success toast uses safe text and polite status semantics', () => {
	const { context } = loadUi();
	const item = context.window.FHUi.toast('<b>saved</b>', {
		el: 'notification',
		type: 'success'
	});
	assert.equal(item.getAttribute('role'), 'status');
	assert.equal(item.getAttribute('aria-live'), 'polite');
	assert.equal(item.querySelector('[data-toast-text]')?.textContent
		|| item.children[1].textContent, '<b>saved</b>');
	assert.equal(item.children[1].children.length, 0);
	assert.ok(item.classList.contains('success'));
});

test('error toast is assertive and has the error icon', () => {
	const { context } = loadUi();
	const item = context.window.FHUi.toast('failed', {
		el: 'notification',
		type: 'error'
	});
	assert.equal(item.getAttribute('role'), 'alert');
	assert.equal(item.getAttribute('aria-live'), 'assertive');
	assert.ok(item.children[0].children[0].className.includes('fa-circle-xmark'));
});

test('rapid autosave messages coalesce and generic bursts stay bounded', () => {
	const { context, body } = loadUi();
	const ui = context.window.FHUi;
	ui.toast('saved 1', { el: 'notification', type: 'success', key: 'autosave' });
	ui.toast('saved 2', { el: 'notification', type: 'success', key: 'autosave' });
	const latest = ui.toast('saved 3', {
		el: 'notification',
		type: 'success',
		key: 'autosave'
	});
	const stack = body.children.find((element) => element.className === 'toast-stack');
	assert.equal(stack.children.length, 1);
	assert.equal(latest.children[1].textContent, 'saved 3');

	for (let index = 0; index < 6; index += 1) {
		ui.toast(`message ${index}`, { el: 'notification', type: 'info' });
	}
	assert.equal(stack.children.length, 4);
});

test('Escape dismisses a focused toast', () => {
	const { context } = loadUi();
	const item = context.window.FHUi.toast('dismiss me', {
		el: 'notification',
		type: 'info'
	});
	assert.ok(item.classList.contains('show'));
	item.dispatch('keydown', { key: 'Escape' });
	assert.equal(item.classList.contains('show'), false);
});
