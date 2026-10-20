'use strict';

/**
 * A small but real element tree for behavioural tests (as opposed to loose-dom.js, which only
 * captures markup). Supports what the page scripts use: building nodes, text, classes, simple
 * selectors (`.class`, `#id`, `tag`, `[attr]`, `tag[attr="v"]`, comma lists), events and lookup
 * by id through the tree.
 */

class MiniText {
	constructor(text) {
		this.nodeType = 3;
		this.data = String(text);
		this.parentNode = null;
	}

	get nodeName() { return '#text'; }
	get nodeValue() { return this.data; }
	set nodeValue(value) { this.data = String(value); }
	get textContent() { return this.data; }
}

class MiniElement {
	constructor(tagName, ownerDocument) {
		this.nodeType = 1;
		this.tagName = String(tagName).toUpperCase();
		this.ownerDocument = ownerDocument;
		this.children = [];
		this.parentNode = null;
		this.attributes = {};
		this.dataset = {};
		this.style = {};
		this.listeners = {};
		this.value = '';
		this.checked = false;
		this.disabled = false;
		this.hidden = false;
		this.title = '';
		this._classes = new Set();
		this._html = '';
		const self = this;
		this.classList = {
			add: (...names) => names.forEach(name => self._classes.add(name)),
			remove: (...names) => names.forEach(name => self._classes.delete(name)),
			contains: name => self._classes.has(name),
			toggle(name, force) {
				const on = force === undefined ? !self._classes.has(name) : Boolean(force);
				if (on) self._classes.add(name); else self._classes.delete(name);
				return on;
			}
		};
	}

	get className() { return Array.from(this._classes).join(' '); }
	set className(value) { this._classes = new Set(String(value).split(/\s+/).filter(Boolean)); }
	get id() { return this.attributes.id || ''; }
	set id(value) { this.attributes.id = String(value); }
	get nodeName() { return this.tagName; }
	/** Text and element children together, like the DOM's childNodes (this tree keeps both in `children`). */
	get childNodes() { return this.children; }
	get isConnected() {
		let node = this;
		while (node) {
			if (node === this.ownerDocument.body) return true;
			node = node.parentNode;
		}
		return false;
	}

	get firstChild() { return this.children[0] || null; }
	get nextElementSibling() {
		if (!this.parentNode) return null;
		const list = this.parentNode.children.filter(node => node.nodeType === 1);
		return list[list.indexOf(this) + 1] || null;
	}

	get textContent() { return this.children.map(node => node.textContent).join(''); }
	set textContent(value) {
		this.children.forEach(node => { node.parentNode = null; });
		this.children = String(value) === '' ? [] : [Object.assign(new MiniText(value), { parentNode: this })];
	}

	get innerHTML() { return this._html; }
	set innerHTML(value) { this._html = String(value); this.children = []; }

	setAttribute(name, value) {
		if (name === 'class') { this.className = value; return; }
		this.attributes[name] = String(value);
	}
	getAttribute(name) {
		if (name === 'class') return this._classes.size ? this.className : null;
		return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null;
	}
	hasAttribute(name) {
		if (name === 'class') return this._classes.size > 0;
		return Object.prototype.hasOwnProperty.call(this.attributes, name);
	}
	removeAttribute(name) {
		if (name === 'class') { this._classes = new Set(); return; }
		delete this.attributes[name];
	}

	appendChild(node) {
		if (node.parentNode) node.parentNode.removeChild(node);
		node.parentNode = this;
		this.children.push(node);
		return node;
	}

	append(...nodes) {
		nodes.forEach(node => this.appendChild(typeof node === 'string' ? new MiniText(node) : node));
	}

	removeChild(node) {
		const index = this.children.indexOf(node);
		if (index >= 0) this.children.splice(index, 1);
		node.parentNode = null;
		return node;
	}

	replaceChildren(...nodes) {
		this.children.slice().forEach(node => this.removeChild(node));
		this.append(...nodes);
	}

	remove() { if (this.parentNode) this.parentNode.removeChild(this); }

	addEventListener(type, handler) { (this.listeners[type] ||= []).push(handler); }
	removeEventListener(type, handler) {
		this.listeners[type] = (this.listeners[type] || []).filter(fn => fn !== handler);
	}

	dispatchEvent(event) {
		event.target ||= this;
		event.currentTarget = this;
		(this.listeners[event.type] || []).slice().forEach(handler => handler(event));
	}

	click() {
		const event = { type: 'click', target: this, preventDefault() {}, stopPropagation() {} };
		this.dispatchEvent(event);
	}

	focus() { this.ownerDocument.activeElement = this; }
	select() {}
	getBoundingClientRect() { return { left: 10, right: 50, top: 10, bottom: 40, width: 40, height: 30 }; }

	matchesSimple(selector) {
		const s = selector.trim();
		const m = s.match(/^([a-z0-9]*)((?:[.#][\w-]+|\[[^\]]+\])*)$/i);
		if (!m) return false;
		if (m[1] && this.tagName.toLowerCase() !== m[1].toLowerCase()) return false;
		for (const part of m[2].match(/[.#][\w-]+|\[[^\]]+\]/g) || []) {
			if (part[0] === '.') { if (!this._classes.has(part.slice(1))) return false; }
			else if (part[0] === '#') { if (this.id !== part.slice(1)) return false; }
			else {
				const a = part.slice(1, -1).match(/^([\w-]+)(?:=["']?([^"']*)["']?)?$/);
				if (!a) return false;
				const actual = a[1] === 'type' && this.attributes.type === undefined ? this.type : this.getAttribute(a[1]);
				if (actual === null || actual === undefined) return false;
				if (a[2] !== undefined && String(actual) !== a[2]) return false;
			}
		}
		return true;
	}

	matches(selector) { return selector.split(',').some(part => this.matchesSimple(part)); }

	querySelectorAll(selector) {
		const out = [];
		const walk = node => node.children.forEach(child => {
			if (child.nodeType !== 1) return;
			if (child.matches(selector)) out.push(child);
			walk(child);
		});
		walk(this);
		return out;
	}

	querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }

	closest(selector) {
		let node = this;
		while (node && node.nodeType === 1) {
			if (node.matches(selector)) return node;
			node = node.parentNode;
		}
		return null;
	}
}

function createMiniDocument() {
	const document = {
		readyState: 'complete',
		visibilityState: 'visible',
		activeElement: null,
		listeners: {},
		createElement: tag => new MiniElement(tag, document),
		createElementNS: (_ns, tag) => new MiniElement(tag, document),
		createTextNode: text => new MiniText(text),
		addEventListener(type, handler) { (document.listeners[type] ||= []).push(handler); },
		querySelector: selector => document.body.querySelector(selector),
		querySelectorAll: selector => document.body.querySelectorAll(selector),
		getElementById: id => {
			const find = node => {
				for (const child of node.children) {
					if (child.nodeType !== 1) continue;
					if (child.id === id) return child;
					const hit = find(child);
					if (hit) return hit;
				}
				return null;
			};
			return find(document.body);
		},
		/** Add a static element (with an id) to the page, the way server-rendered markup would. */
		add(tag, id, props = {}) {
			const el = document.createElement(tag);
			if (id) el.id = id;
			Object.assign(el, props);
			document.body.appendChild(el);
			return el;
		}
	};
	document.body = new MiniElement('body', document);
	document.documentElement = new MiniElement('html', document);
	return document;
}

module.exports = { MiniElement, MiniText, createMiniDocument };
