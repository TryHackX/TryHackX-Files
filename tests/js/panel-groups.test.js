'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(
	path.join(__dirname, '..', '..', 'public', 'assets', 'js', 'panel-groups.js'),
	'utf8'
);

function loadModule() {
	const context = {
		document: {},
		FHUtil: { esc: (value) => String(value) },
		t: (key) => key,
		setTimeout,
		clearTimeout
	};
	context.window = context;
	vm.createContext(context);
	vm.runInContext(source, context, { filename: 'panel-groups.js' });
	return context;
}

test('group module exposes a frozen group and user-management API', () => {
	const context = loadModule();
	assert.equal(Object.isFrozen(context.FHPanelGroups), true);
	for (const action of [
		'loadGroups',
		'openGroupForm',
		'saveGroup',
		'deleteGroup',
		'openSetUserGroup',
		'openManageUser',
		'saveManageUser',
		'onManageRoleChange'
	]) {
		assert.equal(typeof context.FHPanelGroups[action], 'function', action);
	}
});

test('group templates use declarative event attributes', () => {
	assert.doesNotMatch(
		source,
		/(?<!\.)\bon(?:click|submit|change|input|error|load)\s*=/i
	);
	assert.match(source, /data-fh-click=/);
	assert.match(source, /data-fh-change=/);
});

test('group module reads users through the read-only panel state bridge', () => {
	assert.match(source, /FHPanelState\.getUsers\(\)/);
	assert.doesNotMatch(source, /\busers\.find\(/);
	assert.match(source, /permCatalog\.myfiles\[p\]/);
	assert.match(source, /permCatalog\.mcfilter\[p\]/);
});

test('group module uses explicit pagination, validation and user refresh bridges', () => {
	assert.match(source, /FHPanelCore\.renderPager/);
	assert.match(source, /FHPanelCore\.resetManageUserPasswordValidation/);
	assert.match(source, /FHPanelUsers\.refreshCurrentPage/);
	assert.doesNotMatch(source, /\buserPage\b/);
	assert.doesNotMatch(source, /\bresetMuPw\b/);
	assert.doesNotMatch(source, /function writeSizeBound\(/);
});

test('moderator role uses the automatic system group instead of a selectable profile', () => {
	assert.doesNotMatch(source, /muStaffProfile/);
	assert.doesNotMatch(source, /staff_group_id\s*:/);
	assert.doesNotMatch(source, /fa-user-shield/);
	assert.match(source, /g\.slug !== 'guest' && g\.slug !== 'moderator'/);
	assert.match(source, /muModeratorGroupNote/);
	assert.match(source, /grpModeratorGroupNote/);
});
