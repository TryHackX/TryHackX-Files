'use strict';

/**
 * Empty tables on phones: the sortable headers never wrap, which kept an empty table as wide as its
 * header row and cut the "nothing here yet" line (API keys, webhooks) at a 375px screen edge.
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

test('an empty table on a phone lets its headers wrap, so the empty-state text is not clipped', () => {
	const css = fs.readFileSync(path.resolve(__dirname, '../../public/assets/css/panel.css'), 'utf8');
	const start = css.indexOf('@media (max-width: 768px)');
	assert.ok(start >= 0);
	const block = css.slice(start, css.indexOf('\n}\n', start));
	assert.match(
		block,
		/\.table-wrap table\.table-stable:has\(> tbody > tr:only-child > td\.empty\) th\s*\{\s*white-space:\s*normal;/
	);
});
