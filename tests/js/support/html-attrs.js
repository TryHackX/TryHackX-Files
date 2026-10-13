'use strict';

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const eventsSource = fs.readFileSync(
	path.join(__dirname, '..', '..', '..', 'public', 'assets', 'js', 'panel-events.js'),
	'utf8'
);

/** HTML character references the way a browser decodes them inside an attribute value. */
function decodeEntities(value) {
	return value
		.replace(/&quot;/g, '"')
		.replace(/&#0*39;|&apos;/g, "'")
		.replace(/&lt;/g, '<')
		.replace(/&gt;/g, '>')
		.replace(/&amp;/g, '&');
}

/**
 * Tokenise start tags and their attributes like an HTML parser would for well-formed markup:
 * quoted values end at their own quote, unquoted at whitespace. A stored string that closes
 * a quote early therefore shows up as extra attributes — exactly what the tests look for.
 */
function parseTags(html) {
	const tags = [];
	const tagRe = /<([a-zA-Z][a-zA-Z0-9-]*)((?:\s+[^\s"'<>\/=]+(?:\s*=\s*(?:"[^"]*"|'[^']*'|[^\s"'=<>`]+))?)*)\s*\/?>/g;
	const attrRe = /([^\s"'<>\/=]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?/g;
	let tag;
	while ((tag = tagRe.exec(html))) {
		const attrs = [];
		let attr;
		attrRe.lastIndex = 0;
		while ((attr = attrRe.exec(tag[2]))) {
			const raw = attr[2] ?? attr[3] ?? attr[4] ?? '';
			attrs.push({ name: attr[1].toLowerCase(), value: decodeEntities(raw) });
		}
		tags.push({ tag: tag[1].toLowerCase(), attrs });
	}
	return tags;
}

/** Every attribute name of every tag, in document order — the "shape" of a rendering. */
function shapeOf(html) {
	return parseTags(html).map(t => t.tag + '[' + t.attrs.map(a => a.name).join(',') + ']');
}

/**
 * Run a handler string through the real declarative dispatcher (panel-events.js) and report the
 * calls it makes: [{ name, args }]. Every function name that appears in the string is wired to
 * a recorder, so what is under test is the *arguments* and *which* functions get called.
 * `rejected` holds the dispatcher's own refusals (unbalanced quotes, unsupported syntax).
 */
function runHandler(attrName, source) {
	const calls = [];
	const rejected = [];
	const listeners = {};
	class FakeElement {}
	const element = new FakeElement();
	element.attributes = { [attrName]: source };
	element.getAttribute = function (name) {
		return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null;
	};
	element.matches = function (selector) { return selector === '[' + attrName + ']'; };
	element.closest = function (selector) { return this.matches(selector) ? this : null; };
	element.checked = false;
	element.parentNode = null;

	const actions = {};
	for (const match of source.matchAll(/([A-Za-z_$][\w$]*)\s*\(/g)) {
		const name = match[1];
		actions[name] = (...args) => { calls.push({ name, args }); };
	}
	const window = {
		FHPanelActions: actions,
		FHUi: { toast: () => {} },
		t: key => key
	};
	window.window = window;
	const context = {
		window,
		document: { addEventListener(type, handler) { listeners[type] = handler; } },
		Element: FakeElement,
		HTMLElement: FakeElement,
		console: { error: (...args) => rejected.push(args.map(String).join(' ')) }
	};
	vm.runInNewContext(eventsSource, context, { filename: 'panel-events.js' });
	const type = attrName.replace(/^data-fh-/, '');
	listeners[type]({ target: element, preventDefault() {}, stopPropagation() {} });
	return { calls, rejected };
}

module.exports = { decodeEntities, parseTags, shapeOf, runHandler };
