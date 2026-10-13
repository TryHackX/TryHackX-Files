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

test('muted text meets 4.5:1 on the card colour in both themes', () => {
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
	const root = base.match(/:root\s*\{([^}]*)\}/)[1];
	const light = base.match(/\.light\s*\{([^}]*)\}/)[1];
	const token = (block, name) => hex(block.match(new RegExp(`--${name}:\\s*([^;]+);`))[1]);
	assert.ok(ratio(token(root, 'text-muted'), token(root, 'bg-card')) >= 4.5, 'dark');
	assert.ok(ratio(token(light, 'text-muted'), token(light, 'bg-card')) >= 4.5, 'light');
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
	assert.match(base, /@media \(max-width: 768px\)\s*\{\s*input:not\(\[type="checkbox"\]\)[^{]*select,\s*textarea\s*\{\s*font-size:\s*16px;/);
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
