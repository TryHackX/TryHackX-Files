'use strict';

/**
 * The panel's modal stack (panel.js): Escape dismisses the top-most modal, except when a dialog
 * on top already handled that Escape itself, and every dismissal is announced to the dialog so
 * one with state of its own (the password re-check prompt, the banner crop) can settle.
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const panelSource = fs.readFileSync(path.join(__dirname, '../../public/assets/js/panel.js'), 'utf8');

class FakeModal {
	constructor(id) {
		this.id = id;
		this.classes = new Set(['modal-bg']);
		this.classList = {
			add: name => this.classes.add(name),
			remove: name => this.classes.delete(name),
			contains: name => this.classes.has(name)
		};
		this.style = {};
		this.listeners = {};
		this.dispatched = [];
	}

	setAttribute() {}
	getAttribute() { return null; }
	querySelector() { return null; }
	querySelectorAll() { return []; }
	addEventListener(type, handler) { (this.listeners[type] ||= []).push(handler); }
	removeEventListener(type, handler) {
		this.listeners[type] = (this.listeners[type] || []).filter(fn => fn !== handler);
	}
	dispatchEvent(event) {
		this.dispatched.push(event.type);
		(this.listeners[event.type] || []).forEach(handler => handler(event));
	}
}

function loadPanel() {
	const modals = { planModal: new FakeModal('planModal'), confirmModal: new FakeModal('confirmModal') };
	const bootstrap = { dataset: { config: JSON.stringify({ tab: 'test' }) } };
	const listeners = {};
	const document = {
		readyState: 'complete',
		activeElement: null,
		body: { style: {} },
		getElementById: id => (id === 'panelBootstrap' ? bootstrap : modals[id] || null),
		createElement: () => new FakeModal(''),
		querySelector: () => null,
		querySelectorAll: () => [],
		addEventListener(type, handler) { (listeners[type] ||= []).push(handler); }
	};
	const inert = new Proxy({}, { get: () => () => {} });
	const window = {
		document,
		location: { search: '', href: '' },
		FHUtil: { esc: v => String(v ?? ''), formatSize: v => String(v), formatDate: v => String(v) },
		addEventListener() {}
	};
	['FHPanelFiles', 'FHPanelAccountTools', 'FHPanelUsers', 'FHPanelPremium', 'FHPanelLanguages',
		'FHPanelGroups', 'FHPanelModeration', 'FHPanelDashboard', 'FHPanelSettings', 'FHPanelAds']
		.forEach(name => { window[name] = inert; });
	window.window = window;
	vm.runInNewContext(panelSource, {
		window, document, console, URLSearchParams, URL, Math,
		CustomEvent: class { constructor(type) { this.type = type; } },
		MutationObserver: class { observe() {} disconnect() {} },
		setTimeout: () => 1, clearTimeout() {}, setInterval: () => 1, clearInterval() {},
		t: key => key
	}, { filename: 'panel.js' });
	const escape = extra => (listeners.keydown || []).forEach(handler => handler(Object.assign({
		key: 'Escape', repeat: false, defaultPrevented: false, preventDefault() {}
	}, extra)));
	return { window, modals, escape };
}

test('Escape that a dialog on top already handled leaves the modal beneath open', () => {
	const { window, modals, escape } = loadPanel();
	window.openModal('planModal');
	escape({ defaultPrevented: true });
	assert.equal(modals.planModal.classList.contains('show'), true, 'the form being filled stays open');

	escape();
	assert.equal(modals.planModal.classList.contains('show'), false, 'an ordinary Escape still closes it');
});

test('every dismissal of an open modal is announced to it, once', () => {
	const { window, modals, escape } = loadPanel();
	window.openModal('planModal');
	window.openModal('confirmModal');

	escape();
	assert.deepEqual(modals.confirmModal.dispatched, ['fh:modal-dismissed']);
	assert.deepEqual(modals.planModal.dispatched, [], 'only the top-most one');

	window.closeModal('planModal');
	window.closeModal('planModal');
	assert.deepEqual(modals.planModal.dispatched, ['fh:modal-dismissed'], 'closing a closed modal says nothing');
});
