'use strict';

// Guards for the shared stylesheet split (base.css) and the phone layout rules it carries.

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const cssDir = path.join(__dirname, '../../public/assets/css');
const read = (name) => fs.readFileSync(path.join(cssDir, name), 'utf8');
const strip = (css) => css.replace(/\/\*[\s\S]*?\*\//g, '');
const base = strip(read('base.css'));
const pageFiles = fs.readdirSync(cssDir).filter((f) => f.endsWith('.css') && f !== 'base.css');

test('design tokens and the reset are defined once, in base.css', () => {
	assert.match(base, /:root\s*\{[^}]*--text-muted:/);
	assert.match(base, /\.light\s*\{[^}]*--text-muted:/);
	for (const file of pageFiles) {
		const css = strip(read(file));
		assert.doesNotMatch(css, /(^|\})\s*:root\s*\{/, `${file} redefines :root`);
		assert.doesNotMatch(css, /(^|\})\s*\.light\s*\{/, `${file} redefines the .light tokens`);
		assert.doesNotMatch(css, /(^|\})\s*\.lang-switch\s*\{/, `${file} copies the language switch`);
		assert.doesNotMatch(css, /(^|\})\s*\.auth-box\s*\{/, `${file} copies the account modal`);
		assert.doesNotMatch(css, /(^|\})\s*\.toast-stack\s*\{/, `${file} copies the toast stack`);
	}
});

const hex = (s) => s.match(/#([0-9a-f]{6})/i)[1];
const lum = (h) => {
	const c = [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16) / 255)
		.map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4));
	return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
};
const ratio = (a, b) => {
	const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p);
	return (x + 0.05) / (y + 0.05);
};
const rootTokens = base.match(/:root\s*\{([^}]*)\}/)[1];
const lightTokens = base.match(/\.light\s*\{([^}]*)\}/)[1];
const token = (block, name) => hex(block.match(new RegExp(`--${name}:\\s*([^;]+);`))[1]);

test('muted text meets 4.5:1 on the card colour in both themes', () => {
	assert.ok(ratio(token(rootTokens, 'text-muted'), token(rootTokens, 'bg-card')) >= 4.5, 'dark');
	assert.ok(ratio(token(lightTokens, 'text-muted'), token(lightTokens, 'bg-card')) >= 4.5, 'light');
});

test('error text meets 4.5:1 in the light theme, and white on a red button still does', () => {
	const danger = token(lightTokens, 'danger');
	assert.ok(ratio(danger, token(lightTokens, 'bg-card')) >= 4.5, 'on the card colour');
	assert.ok(ratio(danger, token(lightTokens, 'bg-secondary')) >= 4.5, 'on the field colour');
	assert.ok(ratio(danger, 'ffffff') >= 4.5, 'white text on a danger button');
});

test('the hidden attribute wins over any class that sets display', () => {
	assert.match(base, /(^|\})\s*\[hidden\]\s*\{\s*display:\s*none\s*!important;\s*\}/);
});

test('toasts are drawn above every dialog and backdrop', () => {
	const stack = Number(base.match(/\.toast-stack\s*\{[^}]*z-index:\s*(\d+)/)[1]);
	for (const file of fs.readdirSync(cssDir).filter((f) => f.endsWith('.css'))) {
		const css = strip(read(file));
		for (const rule of css.matchAll(/([^{}]+)\{([^{}]*z-index:\s*(\d+)[^{}]*)\}/g)) {
			const selector = rule[1].trim();
			// The toast templates themselves (cloned into the stack, or anchored at a button).
			if (/\.toast\b|\.toast-stack|\.modern-notification|#notification/.test(selector)) continue;
			assert.ok(Number(rule[3]) < stack, `${file}: "${selector}" (z-index ${rule[3]}) would cover the toasts`);
		}
	}
});

test('a red button keeps a red edge on hover', () => {
	assert.match(base, /\.btn\.btn-danger:hover\s*\{\s*border-color:\s*var\(--danger\);/);
});

test('the header wraps on phones instead of widening the page', () => {
	const phone = base.match(/@media \(max-width: 600px\) \{\s*header\s*\{([^}]*)\}\s*\.header-actions\s*\{([^}]*)\}/);
	assert.ok(phone, 'base.css keeps the phone header block');
	assert.match(phone[1], /flex-wrap:\s*wrap/);
	assert.match(phone[2], /flex-wrap:\s*wrap/);
	// The notification popover anchors to the header on phones.
	assert.match(base, /(^|\})\s*header\s*\{[^}]*position:\s*relative/);
});

test('form controls are at least 16px on phones (no iOS focus zoom)', () => {
	const phone = base.match(/@media \(max-width: 768px\)\s*\{\s*input:not\(\[type="checkbox"\]\)[^{]*\{\s*font-size:\s*16px;\s*\}\s*select,\s*textarea\s*\{\s*font-size:\s*16px\s*!important;\s*\}/);
	assert.ok(phone, 'inputs by specificity, selects and text areas with !important');
	// The page stylesheets size selects and text areas with class selectors that a bare
	// `select`/`textarea` in base.css (linked first) cannot beat without !important.
	assert.match(read('panel.css'), /\.form-group select,\s*\.form-group textarea|\.form-group textarea/);
});

test('no stylesheet animates every property', () => {
	for (const file of fs.readdirSync(cssDir).filter((f) => f.endsWith('.css'))) {
		assert.doesNotMatch(read(file), /transition:\s*all\b/, `${file} uses transition: all`);
	}
});

test('reduced motion still switches animation off everywhere', () => {
	assert.match(base, /@media \(prefers-reduced-motion: reduce\)\s*\{\s*\*,\s*\*::before,\s*\*::after\s*\{[^}]*animation-duration:\s*0\.01ms !important;[^}]*transition-duration:\s*0\.01ms !important;/);
});

test('every page that shows the site header links base.css before its own stylesheet', () => {
	const root = path.join(__dirname, '../..');
	const pages = {
		'public/index.php': 'index.css',
		'public/premium.php': 'index.css',
		'public/download.php': 'download.css',
		'public/collection.php': 'download.css',
		'public/panel.php': 'panel.css',
		'src/includes/panel_reauth_page.php': 'index.css'
	};
	for (const [page, own] of Object.entries(pages)) {
		const html = fs.readFileSync(path.join(root, page), 'utf8');
		const b = html.indexOf('/assets/css/base.css');
		assert.ok(b > 0, `${page} links base.css`);
		assert.ok(b < html.indexOf(`/assets/css/${own}`), `${page} links base.css before ${own}`);
		assert.doesNotMatch(html, /<style[\s>][\s\S]*?\.coll-file/, `${page} carries page rules inline`);
	}
});
