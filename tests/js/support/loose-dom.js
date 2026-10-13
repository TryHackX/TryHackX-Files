'use strict';

/**
 * A forgiving DOM stand-in for rendering tests.
 *
 * The panel modules build their tables with innerHTML and then poke at the surrounding DOM
 * (bulk bars, sort icons, pagers…). The tests only care about the markup that ends up in a
 * table body, so every element here is a Proxy: `innerHTML`/`textContent`/`value` behave like
 * plain properties, `querySelectorAll` yields nothing, and anything else is a harmless no-op
 * that returns another loose value. Elements are created on first `getElementById`.
 */

function looseValue() {
	const fn = function () { return looseValue(); };
	return new Proxy(fn, {
		get(_target, prop) {
			if (prop === 'then' || typeof prop === 'symbol') return undefined;
			if (prop === 'length') return 0;
			return looseValue();
		},
		set() { return true; }
	});
}

function looseElement(id) {
	const store = { id, innerHTML: '', textContent: '', value: '', checked: false, disabled: false, hidden: false };
	const dataset = {};
	const classes = new Set();
	const style = {};
	const listeners = [];
	return new Proxy(store, {
		get(target, prop) {
			if (prop in target) return target[prop];
			if (typeof prop === 'symbol') return undefined;
			if (prop === 'then') return undefined;
			if (prop === 'dataset') return dataset;
			if (prop === 'style') return style;
			if (prop === 'classList') {
				return {
					add: (...names) => names.forEach(name => classes.add(name)),
					remove: (...names) => names.forEach(name => classes.delete(name)),
					toggle: (name, force) => {
						const on = force === undefined ? !classes.has(name) : Boolean(force);
						if (on) classes.add(name); else classes.delete(name);
						return on;
					},
					contains: name => classes.has(name)
				};
			}
			if (prop === 'querySelectorAll') return () => [];
			if (prop === 'querySelector') return () => null;
			if (prop === 'closest') return () => null;
			if (prop === 'addEventListener') return (type, handler) => listeners.push({ type, handler });
			if (prop === 'getAttribute') return () => null;
			if (prop === 'children' || prop === 'childNodes') return [];
			return looseValue();
		},
		set(target, prop, value) {
			target[prop] = value;
			// Like a real element: assigning text makes innerHTML its serialisation, which escapes
			// & < > — but NOT quotes. (util.js used to rely on exactly this.)
			if (prop === 'textContent') {
				target.innerHTML = String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
			}
			return true;
		}
	});
}

function createLooseDocument(extra = {}) {
	const elements = new Map();
	const document = {
		readyState: 'complete',
		visibilityState: 'visible',
		getElementById(id) {
			if (Object.prototype.hasOwnProperty.call(extra, id)) return extra[id];
			if (!elements.has(id)) elements.set(id, looseElement(id));
			return elements.get(id);
		},
		querySelectorAll() { return []; },
		querySelector() { return null; },
		createElement(tag) { return looseElement('<' + tag + '>'); },
		createTextNode(text) { return { textContent: String(text) }; },
		addEventListener() {},
		body: looseElement('body'),
		documentElement: looseElement('html')
	};
	return document;
}

module.exports = { createLooseDocument, looseElement, looseValue };
