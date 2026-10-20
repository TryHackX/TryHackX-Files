'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(
	path.join(__dirname, '..', '..', 'public', 'assets', 'js', 'util.js'),
	'utf8'
);

function loadUtil() {
	const document = {
		createElement() {
			let value = '';
			return {
				set textContent(next) { value = String(next); },
				get innerHTML() {
					return value.replace(/[&<>"]/g, character => ({
						'&': '&amp;',
						'<': '&lt;',
						'>': '&gt;',
						'"': '&quot;'
					})[character]);
				}
			};
		}
	};
	const context = { document };
	context.window = context;
	vm.createContext(context);
	vm.runInContext(source, context, { filename: 'util.js' });
	return context.FHUtil;
}

test('safeHttpUrl accepts HTTP(S) and ordinary relative application paths', () => {
	const { safeHttpUrl } = loadUtil();
	assert.equal(safeHttpUrl('https://cdn.example.test/banner.png'), 'https://cdn.example.test/banner.png');
	assert.equal(safeHttpUrl('/collection.php?id=abc'), '/collection.php?id=abc');
	assert.equal(safeHttpUrl('panel.php?tab=files'), 'panel.php?tab=files');
	assert.equal(safeHttpUrl('../download.php'), '../download.php');
});

test('safeHttpUrl rejects executable, protocol-relative and ambiguous URLs', () => {
	const { safeHttpUrl } = loadUtil();
	for (const unsafe of [
		'javascript:alert(1)',
		'data:text/html,<script>alert(1)</script>',
		'vbscript:msgbox(1)',
		'//evil.example/path',
		'\\\\evil.example\\path',
		'https://safe.example/\njavascript:alert(1)'
	]) {
		assert.equal(safeHttpUrl(unsafe), '', unsafe);
	}
});

test('date fields show a timestamp in the server time zone, not in UTC', () => {
	const { serverDate } = loadUtil();
	// Midnight in Warsaw is still the previous day in UTC: the old toISOString() reading put
	// 19 October into the field, and every save moved the ad a day earlier.
	const warsawMidnight = Date.UTC(2026, 9, 19, 22, 0, 0) / 1000;
	assert.equal(serverDate(warsawMidnight, 'Europe/Warsaw'), '2026-10-20');
	assert.equal(serverDate(warsawMidnight, 'UTC'), '2026-10-19');
	// A promo code saved to expire at 23:59:59 in New York is already the next day in UTC.
	const newYorkEndOfDay = Date.UTC(2026, 9, 21, 3, 59, 59) / 1000;
	assert.equal(serverDate(newYorkEndOfDay, 'America/New_York'), '2026-10-20');
	assert.equal(serverDate(0, 'Europe/Warsaw'), '');
	assert.equal(serverDate(warsawMidnight, 'Not/A_Zone'), '2026-10-19', 'an unknown zone falls back to UTC');
});

test('the ad and promo forms read their dates through the server-zone helper', () => {
	for (const name of ['panel-ads.js', 'panel-premium.js']) {
		const script = fs.readFileSync(path.join(__dirname, '..', '..', 'public', 'assets', 'js', name), 'utf8');
		assert.doesNotMatch(script, /toISOString\(\)\.slice\(0, 10\)/, name);
		assert.match(script, /FHUtil\.serverDate\(/, name);
	}
	const panelPage = fs.readFileSync(path.join(__dirname, '..', '..', 'public', 'panel.php'), 'utf8');
	assert.match(panelPage, /'timeZone' => date_default_timezone_get\(\)/);
});
