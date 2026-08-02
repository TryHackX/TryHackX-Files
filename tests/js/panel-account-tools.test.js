'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(
	path.join(__dirname, '..', '..', 'public', 'assets', 'js', 'panel-account-tools.js'),
	'utf8'
);

class TestText {
	constructor(value) {
		this.textContent = String(value);
		this.children = [];
	}
}

class TestElement {
	constructor(tagName = 'div') {
		this.tagName = String(tagName).toUpperCase();
		this.attributes = {};
		this.children = [];
		this.dataset = {};
		this.style = {};
		this.className = '';
		this.textContent = '';
		this.title = '';
	}

	append(...children) {
		this.children.push(...children);
	}

	replaceChildren(...children) {
		this.children = children;
	}

	setAttribute(name, value) {
		this.attributes[name] = String(value);
	}
}

function textOf(node) {
	return String(node.textContent || '') + (node.children || []).map(textOf).join('');
}

function descendants(node) {
	return [node, ...(node.children || []).flatMap(descendants)];
}

function createHarness(responses) {
	const elements = {
		panelBootstrap: new TestElement(),
		apiKeysBody: new TestElement('tbody'),
		webhooksBody: new TestElement('tbody')
	};
	elements.panelBootstrap.dataset.config = JSON.stringify({
		appUrl: 'http://pon.localhost',
		host: 'pon.localhost'
	});

	const context = {
		document: {
			body: new TestElement('body'),
			getElementById: (id) => elements[id] || null,
			createElement: (tagName) => new TestElement(tagName),
			createTextNode: (value) => new TestText(value)
		},
		Element: TestElement,
		FHUtil: { formatDate: (value) => 'date:' + value },
		FHApi: {
			get: async (action) => responses[action],
			post: async () => ({ success: false })
		},
		t: (key) => key,
		setTimeout,
		clearTimeout
	};
	context.window = context;
	vm.createContext(context);
	vm.runInContext(source, context, { filename: 'panel-account-tools.js' });
	return { context, elements };
}

test('account tools expose a bounded action API and do not use innerHTML', () => {
	const { context } = createHarness({});
	assert.equal(Object.isFrozen(context.FHPanelAccountTools), true);
	assert.equal(typeof context.FHPanelAccountTools.loadApiKeys, 'function');
	assert.equal(typeof context.FHPanelAccountTools.loadWebhooks, 'function');
	assert.equal(typeof context.FHPanelAccountTools.load2faStatus, 'function');
	assert.doesNotMatch(source, /\.innerHTML\b/);
});

test('2FA enrolment validates the password before exposing the QR container', () => {
	const setupStart = source.indexOf('async function start2faSetup(button = null)');
	const setupEnd = source.indexOf('\n\tfunction cancel2faSetup()', setupStart);
	const setup = source.slice(setupStart, setupEnd);
	const apiCall = setup.indexOf("api().post('user_2fa_setup'");
	const failureBranch = setup.indexOf('if (!result.success)', apiCall);
	const enrolState = setup.indexOf("show2faState('enroll')", failureBranch);

	assert.ok(setupStart >= 0);
	assert.ok(apiCall >= 0);
	assert.ok(failureBranch > apiCall);
	assert.ok(enrolState > failureBranch);
	assert.match(setup, /notify\(result\.error \|\| t\('panel\.2fa\.setup_failed'\), 'error', button\)/);
	assert.match(setup, /finally \{\s*if \(button\) button\.disabled = false;/);
});

test('API key rows preserve hostile labels as text and emit declarative actions', async () => {
	const hostile = '<img src=x onerror=alert(1)>';
	const { context, elements } = createHarness({
		user_api_keys: {
			success: true,
			keys: [{
				id: 7,
				label: hostile,
				prefix: 'abc',
				createdAt: 100,
				lastUsedAt: null
			}]
		}
	});

	await context.FHPanelAccountTools.loadApiKeys();
	assert.equal(elements.apiKeysBody.children.length, 1);
	assert.match(textOf(elements.apiKeysBody), /<img src=x onerror=alert\(1\)>/);
	const nodes = descendants(elements.apiKeysBody);
	const button = nodes.find((node) => node.tagName === 'BUTTON');
	assert.ok(button);
	assert.equal(button.attributes.onclick, undefined);
	assert.equal(button.attributes['data-fh-click'], 'askRevokeApiKey(this)');
	assert.equal(button.dataset.keyLabel, hostile);
});

test('webhook URLs, events and status are rendered as text nodes', async () => {
	const { context, elements } = createHarness({
		user_webhooks: {
			success: true,
			webhooks: [{
				id: 4,
				url: 'https://example.test/<script>',
				events: ['upload', '<img>'],
				lastDeliveryAt: 200,
				lastStatus: '<b>500</b>'
			}]
		}
	});

	await context.FHPanelAccountTools.loadWebhooks();
	const renderedText = textOf(elements.webhooksBody);
	assert.match(renderedText, /https:\/\/example\.test\/<script>/);
	assert.match(renderedText, /<img>/);
	assert.match(renderedText, /<b>500<\/b>/);
	const button = descendants(elements.webhooksBody)
		.find((node) => node.tagName === 'BUTTON');
	assert.equal(button.attributes['data-fh-click'], 'askDeleteWebhook(this)');
	assert.equal(button.attributes.onclick, undefined);
});
