'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const scriptPath = path.join(__dirname, '../../public/assets/js/site-footer.js');
const partialPath = path.join(__dirname, '../../src/includes/site_footer.php');

function encodeAddress(address, key) {
	return Array.from(address, (character) => character.charCodeAt(0) ^ key).join('.');
}

function loadFooterScript() {
	const source = fs.readFileSync(scriptPath, 'utf8');
	const listeners = {};
	const location = { href: 'https://example.test/' };
	const document = {
		addEventListener(type, listener) {
			(listeners[type] ||= []).push(listener);
		}
	};
	const window = { location };

	vm.runInNewContext(source, {
		Array,
		Number,
		String,
		document,
		window
	}, { filename: scriptPath });

	return { listeners, location };
}

test('footer contact control decodes the address only after activation', () => {
	const key = 73;
	const address = ['contact', '@', 'tryhackx.org'].join('');
	const { listeners, location } = loadFooterScript();
	const trigger = {
		dataset: {
			contactKey: String(key),
			contactBytes: encodeAddress(address, key)
		}
	};
	let prevented = false;
	const event = {
		target: {
			closest(selector) {
				assert.equal(selector, '[data-footer-contact]');
				return trigger;
			}
		},
		preventDefault() {
			prevented = true;
		}
	};

	assert.equal(location.href, 'https://example.test/');
	assert.equal(listeners.click?.length, 1);
	listeners.click[0](event);

	assert.equal(prevented, true);
	assert.equal(location.href, `mailto:${address}`);
});

test('footer contact handler ignores clicks outside its control', () => {
	const { listeners, location } = loadFooterScript();
	let prevented = false;

	listeners.click[0]({
		target: { closest: () => null },
		preventDefault() {
			prevented = true;
		}
	});

	assert.equal(prevented, false);
	assert.equal(location.href, 'https://example.test/');
});

test('published footer sources do not contain the complete contact address', () => {
	const address = ['contact', '@', 'tryhackx.org'].join('');
	const sources = [
		fs.readFileSync(scriptPath, 'utf8'),
		fs.readFileSync(partialPath, 'utf8')
	];

	for (const source of sources) {
		assert.equal(source.includes(address), false);
		assert.equal(source.includes(`mailto:${address}`), false);
	}
});

test('all primary application pages load the shared footer and its assets', () => {
	const pages = ['index.php', 'download.php', 'collection.php', 'premium.php', 'panel.php'];

	for (const page of pages) {
		const source = fs.readFileSync(path.join(__dirname, '../../public', page), 'utf8');
		assert.equal(source.includes("includes/site_footer.php"), true, `${page} footer partial`);
		assert.equal(source.includes("includes/site_footer_assets.php"), true, `${page} footer assets`);
	}
});

test('product metadata keeps the public brand independent from installation settings', () => {
	const source = fs.readFileSync(path.join(__dirname, '../../src/brand.php'), 'utf8');
	assert.match(source, /define\('PRODUCT_NAME', 'TryHackX Files'\)/);
	assert.match(source, /define\('PRODUCT_AUTHOR', 'TryHackX'\)/);
	assert.match(source, /define\('PRODUCT_START_YEAR', 2026\)/);
});

test('footer copy is concise, translated and reserves monospace for the version badge', () => {
	const partial = fs.readFileSync(partialPath, 'utf8');
	const styles = fs.readFileSync(
		path.join(__dirname, '../../public/assets/css/site-footer.css'),
		'utf8'
	);

	assert.equal(partial.includes('Powered by'), false);
	assert.equal(partial.includes('Source available'), false);
	assert.match(partial, /footer\.created_by/);
	assert.match(partial, /footer\.licensing/);
	assert.match(styles, /\.site-footer\s*\{[^}]*font-family:\s*inherit;/s);
	assert.match(styles, /\.site-footer \.site-footer-version\s*\{[^}]*ui-monospace/s);
	assert.equal(styles.includes('width: 100%;'), false);
});
