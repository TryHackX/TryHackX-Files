'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(
	path.join(__dirname, '..', '..', 'public', 'assets', 'js', 'panel-events.js'),
	'utf8'
);

class TestElement {
	constructor(attributes = {}) {
		this.attributes = { ...attributes };
		this.children = [];
		this.parentNode = null;
		this.checked = false;
		this.hidden = false;
		this.selected = false;
		this.removed = false;
	}

	append(child) {
		child.parentNode = this;
		this.children.push(child);
	}

	hasAttribute(name) {
		return Object.hasOwn(this.attributes, name);
	}

	getAttribute(name) {
		return this.hasAttribute(name) ? this.attributes[name] : null;
	}

	setAttribute(name, value) {
		this.attributes[name] = String(value);
	}

	removeAttribute(name) {
		delete this.attributes[name];
	}

	matches(selector) {
		const attribute = selector.match(/^\[([a-z0-9-]+)\]$/i);
		return Boolean(attribute && this.hasAttribute(attribute[1]));
	}

	closest(selector) {
		let current = this;
		while (current) {
			if (current.matches(selector)) return current;
			current = current.parentNode;
		}
		return null;
	}

	querySelectorAll(selector) {
		const attributes = Array.from(selector.matchAll(/\[([a-z0-9-]+)\]/gi))
			.map((match) => match[1]);
		const found = [];
		const visit = (element) => {
			if (attributes.some((attribute) => element.hasAttribute(attribute))) {
				found.push(element);
			}
			element.children.forEach(visit);
		};
		this.children.forEach(visit);
		return found;
	}

	select() {
		this.selected = true;
	}

	remove() {
		this.removed = true;
	}
}

function createHarness(initialElements = []) {
	const listeners = {};
	const elementsById = {};
	const documentElement = new TestElement();
	initialElements.forEach((element) => documentElement.append(element));
	const errors = [];
	const toasts = [];

	const document = {
		documentElement,
		addEventListener(type, listener) {
			listeners[type] = listener;
		},
		querySelectorAll(selector) {
			return documentElement.querySelectorAll(selector);
		},
		getElementById(id) {
			return elementsById[id] || null;
		}
	};
	class TestMutationObserver {
		constructor() {}
		observe() {}
	}

	const context = {
		console: { error: (...args) => errors.push(args) },
		document,
		Element: TestElement,
		HTMLElement: TestElement,
		MutationObserver: TestMutationObserver,
		setTimeout,
		clearTimeout
	};
	context.window = context;
	context.FHUi = { toast: (...args) => toasts.push(args) };
	context.t = () => 'Błąd';
	vm.createContext(context);

	return {
		context,
		document,
		elementsById,
		errors,
		listeners,
		toasts,
		run() {
			vm.runInContext(source, context, { filename: 'panel-events.js' });
		},
		addDynamically(element) {
			documentElement.append(element);
		}
	};
}

function dispatch(listener, target, properties = {}) {
	const event = {
		target,
		defaultPrevented: false,
		propagationStopped: false,
		preventDefault() {
			this.defaultPrevented = true;
		},
		stopPropagation() {
			this.propagationStopped = true;
		},
		...properties
	};
	listener(event);
	return event;
}

test('declarative attributes invoke only explicit actions', () => {
	const button = new TestElement({
		'data-fh-click': "event.preventDefault(); sample('ok', 7, this.checked); return false"
	});
	button.checked = true;
	const calls = [];
	const harness = createHarness([button]);
	harness.context.FHPanelActions = Object.freeze({
		sample: (...args) => calls.push(args)
	});

	harness.run();
	assert.equal(button.getAttribute('data-fh-click'), (
		"event.preventDefault(); sample('ok', 7, this.checked); return false"
	));

	const event = dispatch(harness.listeners.click, button);
	assert.deepEqual(calls, [['ok', 7, true]]);
	assert.equal(event.defaultPrevented, true);
	assert.equal(event.propagationStopped, true);
	assert.deepEqual(harness.errors, []);
});

test('event delegation handles dynamically inserted declarative controls', () => {
	const calls = [];
	const harness = createHarness();
	harness.context.FHPanelActions = Object.freeze({
		dynamicAction: (value) => calls.push(value)
	});
	harness.run();

	const button = new TestElement({ 'data-fh-click': "dynamicAction('created later')" });
	harness.addDynamically(button);
	assert.equal(button.hasAttribute('onclick'), false);
	assert.equal(button.getAttribute('data-fh-click'), "dynamicAction('created later')");
	dispatch(harness.listeners.click, button);
	assert.deepEqual(calls, ['created later']);
});

test('keydown actions are delegated without inline JavaScript', () => {
	const input = new TestElement({
		'data-fh-keydown': "submitPanelOnEnter(event, 'fileDownload')"
	});
	const calls = [];
	const harness = createHarness([input]);
	harness.context.FHPanelActions = Object.freeze({
		submitPanelOnEnter: (...args) => calls.push(args)
	});
	harness.run();

	const event = dispatch(harness.listeners.keydown, input, { key: 'Enter' });
	assert.equal(calls.length, 1);
	assert.equal(calls[0][0], event);
	assert.equal(calls[0][1], 'fileDownload');
});

test('prototype functions and unsupported expressions are rejected', () => {
	const inherited = new TestElement({ 'data-fh-click': 'constructor()' });
	const unsupported = new TestElement({ 'data-fh-click': 'window.alert(1)' });
	const harness = createHarness([inherited, unsupported]);
	harness.context.FHPanelActions = Object.freeze({});
	harness.run();

	dispatch(harness.listeners.click, inherited);
	dispatch(harness.listeners.click, unsupported);
	assert.equal(harness.errors.length, 2);
	assert.equal(harness.toasts.length, 2);
});
