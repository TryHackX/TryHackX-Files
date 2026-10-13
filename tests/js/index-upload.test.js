'use strict';

/**
 * Behaviour of the upload page script (index.js), run against a small real DOM:
 *  - uploads wait for the upload session, so a signed-in user's files are never sent anonymously;
 *  - one shared, backing-off status poll instead of one 750 ms timer per file;
 *  - a rate-limit answer is not mistaken for "your token is invalid";
 *  - deleting a finished upload takes a second click; leaving mid-upload asks first.
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');
const { webcrypto } = require('node:crypto');

const { createMiniDocument } = require('./support/mini-dom');

const jsDir = path.resolve(__dirname, '../../public/assets/js');
const read = name => fs.readFileSync(path.join(jsDir, name), 'utf8');

const flush = async (rounds = 12) => {
	for (let i = 0; i < rounds; i += 1) await new Promise(resolve => setImmediate(resolve));
};

function deferred() {
	let resolve;
	let reject;
	const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
	return { promise, resolve, reject };
}

class FakeXhr {
	constructor() {
		this.upload = {};
		this.headers = {};
		this.status = 0;
		this.responseText = '';
		FakeXhr.instances.push(this);
	}

	open(method, url) { this.method = method; this.url = url; }
	setRequestHeader(name, value) { this.headers[name] = value; }
	send(file) { this.sent = file; }
	// like a real XHR: aborting a request that already completed does nothing
	abort() { if (this.done) return; this.aborted = true; if (this.onabort) this.onabort(); }
	finish(status, body) { this.done = true; this.status = status; this.responseText = body; return this.onload(); }
}
FakeXhr.instances = [];

function loadIndex({ loggedIn = true, captcha = false, api = {}, maxFiles = 15 } = {}) {
	FakeXhr.instances = [];
	const document = createMiniDocument();
	const config = {
		appUrl: '/app', uploadUrl: '/api/upload', guestLimitMB: 250, userLimitMB: 5120,
		guestLimitFiles: 5, userLimitFiles: maxFiles, maxSizeMB: 5120, maxFiles, blockedExtensions: [],
		loggedIn, collUploadExempt: true
	};
	document.add('div', 'indexBootstrap', { dataset: { config: JSON.stringify(config) } });
	const dropZone = document.add('div', 'dropZone');
	const fileInput = document.add('input', 'fileInput');
	document.add('span', 'browseBtn');
	for (const id of ['fileList', 'sessionInfo', 'captchaOverlay', 'captchaLoading', 'captchaWidget', 'captchaError', 'results', 'resultList', 'copyAll']) {
		document.add('div', id);
	}

	const timers = [];
	let timerId = 0;
	const listeners = {};
	const storageBacking = new Map();
	const sessionStore = {
		getItem: key => (storageBacking.has(key) ? storageBacking.get(key) : null),
		setItem: (key, value) => storageBacking.set(key, String(value)),
		removeItem: key => storageBacking.delete(key)
	};
	const calls = { get: [], post: [], postForm: [] };
	const verify = api.verify_captcha || (async () => ({ success: true, token: 'TOK' }));
	const window = {
		innerWidth: 1000, innerHeight: 800,
		crypto: webcrypto,
		sessionStorage: sessionStore,
		localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
		addEventListener: (type, handler) => { (listeners[type] ||= []).push(handler); },
		FHCaptcha: { configure() {}, isReady: () => false, load() {}, render: () => 1, reset() {} },
		FHUi: { toast() {}, singleFlight: (_key, task) => task() },
		FHApi: {
			get: async (action, params) => {
				calls.get.push({ action, params });
				if (action === 'config') return api.config || { recaptcha_enabled: captcha, recaptcha_site_key: captcha ? 'KEY' : '' };
				const handler = api[action];
				return handler ? handler(params) : { success: false };
			},
			post: async (action, body) => {
				calls.post.push({ action, body });
				return action === 'verify_captcha' ? verify(body) : { success: true };
			},
			postForm: async (action, form) => {
				calls.postForm.push({ action, form });
				return { success: true };
			}
		},
		t: (key, params) => (params ? key + ' ' + JSON.stringify(params) : key),
		document
	};
	window.window = window;
	const context = {
		window, document, console, Promise, Array, Math, Date, JSON, Map, Set, Number, String, Object, Boolean, parseInt,
		FormData: class { constructor() { this.entries = []; } append(k, v) { this.entries.push([k, v]); } },
		XMLHttpRequest: FakeXhr,
		URLSearchParams, encodeURIComponent,
		setTimeout: (fn, ms) => { timers.push({ id: ++timerId, fn, ms, done: false }); return timerId; },
		clearTimeout: id => { const t = timers.find(x => x.id === id); if (t) t.done = true; },
		setInterval: () => 0,
		clearInterval() {},
		navigator: {},
		location: { reload() {} }
	};
	context.t = window.t;
	context.FHApi = window.FHApi;
	context.FHCaptcha = window.FHCaptcha;
	vm.createContext(context);
	vm.runInContext(read('util.js'), context, { filename: 'util.js' });
	vm.runInContext(read('bootstrap.js'), context, { filename: 'bootstrap.js' });
	// bootstrap.js replaced window.t with its own and reads the (absent) i18n element: keep the stub
	window.t = context.t;
	vm.runInContext(read('index.js'), context, { filename: 'index.js' });

	const pickFiles = (...names) => {
		fileInput.files = names.map((name, index) => ({ name, size: 10 + index, lastModified: 1000 + index }));
		fileInput.dispatchEvent({ type: 'change' });
	};
	const pendingTimers = () => timers.filter(t => !t.done);
	const runNextTimer = async () => {
		const next = pendingTimers()[0];
		assert.ok(next, 'no timer is pending');
		next.done = true;
		await next.fn();
		await flush();
	};
	return { window, document, calls, pickFiles, listeners, pendingTimers, runNextTimer, sessionStore, dropZone };
}

test('files picked before the upload session is ready wait for it instead of going out anonymously', async () => {
	const verify = deferred();
	const page = loadIndex({ api: { verify_captcha: () => verify.promise, token_info: () => ({ success: true, valid: true, expires_at: 4102444800 }) } });
	await flush();

	page.pickFiles('a.txt', 'b.txt');
	await flush();
	assert.equal(FakeXhr.instances.length, 0, 'nothing may be sent while the token is still being fetched');
	assert.equal(page.document.getElementById('fileList').children.length, 2, 'the rows are shown as waiting');

	verify.resolve({ success: true, token: 'TOK' });
	await flush();
	assert.equal(FakeXhr.instances.length, 2);
	for (const xhr of FakeXhr.instances) {
		assert.equal(xhr.headers['X-Upload-Token'], 'TOK');
		assert.ok(xhr.sent, 'the file body was sent');
	}
});

test('a signed-in user whose session token cannot be obtained gets an error row, not an anonymous upload', async () => {
	let attempts = 0;
	const page = loadIndex({
		api: {
			// the page's own start-up attempt and the pick-time attempt both fail; the retry succeeds
			verify_captcha: async () => (++attempts <= 2 ? { success: false } : { success: true, token: 'LATE' }),
			token_info: () => ({ success: true, valid: true, expires_at: 4102444800 })
		}
	});
	await flush();

	page.pickFiles('a.txt');
	await flush();
	assert.equal(FakeXhr.instances.length, 0);
	const row = page.document.getElementById('fileList').children[0];
	assert.equal(row.querySelector('.file-status-main').textContent, 'up.session_failed');
	const retry = row.querySelector('.retry');
	assert.ok(retry, 'the row offers a retry');

	retry.click();
	await flush();
	assert.equal(FakeXhr.instances.length, 1, 'the retry fetched a token and uploaded');
	assert.equal(FakeXhr.instances[0].headers['X-Upload-Token'], 'LATE');
});

test('a guest still uploads when the tracking token is unavailable', async () => {
	const page = loadIndex({ loggedIn: false, api: { verify_captcha: async () => ({ success: false }) } });
	await flush();
	page.pickFiles('a.txt');
	await flush();
	assert.equal(FakeXhr.instances.length, 1);
	assert.equal(FakeXhr.instances[0].headers['X-Upload-Token'], undefined);
});

test('one shared status poll serves every running upload and backs off when rate limited', async () => {
	const statusCalls = [];
	let answer = { success: true, status: 'running' };
	const page = loadIndex({
		api: {
			token_info: () => ({ success: true, valid: true, expires_at: 4102444800 }),
			upload_status: params => { statusCalls.push(params.id); return answer; }
		}
	});
	await flush();
	page.pickFiles('a.txt', 'b.txt', 'c.txt');
	await flush();
	assert.equal(FakeXhr.instances.length, 3);

	const pending = page.pendingTimers();
	assert.equal(pending.length, 1, 'a single timer, not one per file');
	assert.ok(pending[0].ms >= 3000, 'polled every few seconds, not every 750 ms: ' + pending[0].ms);

	await page.runNextTimer();
	assert.equal(statusCalls.length, 3, 'each running upload is asked about once per round');
	assert.equal(page.pendingTimers().length, 1, 'the loop re-arms itself once');
	const base = page.pendingTimers()[0].ms;

	// an admin cut the second transfer
	statusCalls.length = 0;
	answer = { success: true, status: 'cancelled' };
	await page.runNextTimer();
	assert.ok(FakeXhr.instances.every(xhr => xhr.aborted), 'cancelled uploads are aborted');

	// rate limited: wait at least as long as the server says
	const limited = loadIndex({
		api: {
			token_info: () => ({ success: true, valid: true, expires_at: 4102444800 }),
			upload_status: () => ({ success: false, error: 'slow down', retry_after: 20 })
		}
	});
	await flush();
	limited.pickFiles('a.txt');
	await flush();
	await limited.runNextTimer();
	assert.ok(limited.pendingTimers()[0].ms >= 20000, 'backs off for retry_after: ' + limited.pendingTimers()[0].ms);
	assert.ok(base < 20000);
});

test('the polling loop stops once nothing is uploading any more', async () => {
	const page = loadIndex({ api: { token_info: () => ({ success: true, valid: true, expires_at: 4102444800 }), upload_status: () => ({ success: true, status: 'running' }) } });
	await flush();
	page.pickFiles('a.txt');
	await flush();
	await FakeXhr.instances[0].finish(200, 'fileid:deletetoken');
	await flush();
	await page.runNextTimer();
	assert.equal(page.pendingTimers().length, 0, 'no timer is left behind');
});

test('a rate-limit answer from token_info does not throw the upload session away', async () => {
	const page = loadIndex({
		api: { token_info: () => ({ success: false, error: 'Too many requests', retry_after: 5 }) }
	});
	await flush();
	assert.equal(page.sessionStore.getItem('uploadToken'), 'TOK', 'token kept on an unknown answer');
	page.pickFiles('a.txt');
	await flush();
	assert.equal(FakeXhr.instances.length, 1);
});

test('only an explicit "invalid" answer from token_info clears the token', async () => {
	const page = loadIndex({ api: { token_info: () => ({ success: false, valid: false }) } });
	await flush();
	assert.equal(page.sessionStore.getItem('uploadToken'), null);
});

test('removing a finished upload needs a second click; a running one is dropped at once', async () => {
	const page = loadIndex({ api: { token_info: () => ({ success: true, valid: true, expires_at: 4102444800 }), upload_status: () => ({ success: true, status: 'running' }) } });
	await flush();
	page.pickFiles('keep.txt', 'running.txt');
	await flush();
	const [doneXhr] = FakeXhr.instances;
	await doneXhr.finish(200, 'fileid1:deletetoken1');
	await flush();

	const [doneRow, runningRow] = page.document.getElementById('fileList').children;
	const remove = doneRow.querySelector('.remove');
	assert.equal(remove.title, 'up.remove_delete', 'the button says it deletes');

	remove.click();
	await flush();
	assert.equal(page.calls.postForm.length, 0, 'the first click must not delete anything');
	assert.equal(remove.title, 'up.remove_confirm');

	remove.click();
	await flush();
	assert.equal(page.calls.postForm.length, 1);
	assert.equal(page.calls.postForm[0].action, 'delete');

	runningRow.querySelector('.remove').click();
	await flush();
	assert.equal(FakeXhr.instances[1].aborted, true);
	assert.equal(page.calls.postForm.length, 1, 'cancelling a running upload deletes no stored file');
});

test('leaving the page while an upload is running asks for confirmation', async () => {
	const page = loadIndex({ api: { token_info: () => ({ success: true, valid: true, expires_at: 4102444800 }), upload_status: () => ({ success: true, status: 'running' }) } });
	await flush();
	const guard = page.listeners.beforeunload[0];
	let prevented = false;
	const event = { preventDefault() { prevented = true; }, returnValue: undefined };

	guard(event);
	assert.equal(prevented, false, 'idle page leaves freely');

	page.pickFiles('big.bin');
	await flush();
	guard(event);
	assert.equal(prevented, true);

	await FakeXhr.instances[0].finish(200, 'id:token');
	prevented = false;
	guard(event);
	assert.equal(prevented, false, 'a finished upload no longer blocks navigation');
});

test('signing out or in switches the upload session the next upload uses', async () => {
	const tokens = ['FIRST', 'GUEST'];
	const page = loadIndex({
		api: {
			verify_captcha: async () => ({ success: true, token: tokens.shift() }),
			token_info: () => ({ success: true, valid: true, expires_at: 4102444800 })
		}
	});
	await flush();
	page.pickFiles('a.txt');
	await flush();
	assert.equal(FakeXhr.instances[0].headers['X-Upload-Token'], 'FIRST');

	// Signed out in the account modal: the previous account's token must not be reused.
	page.window.FHUploadSession.clear();
	assert.equal(page.sessionStore.getItem('uploadToken'), null, 'and it is gone from sessionStorage');
	page.pickFiles('b.txt');
	await flush();
	assert.equal(FakeXhr.instances[1].headers['X-Upload-Token'], 'GUEST', 'a fresh session is fetched');

	// Signed in again: the server handed over a token of the new account.
	page.window.FHUploadSession.replace('HANDED', Date.now() + 3600000);
	page.pickFiles('c.txt');
	await flush();
	assert.equal(FakeXhr.instances[2].headers['X-Upload-Token'], 'HANDED');
	assert.equal(page.sessionStore.getItem('uploadToken'), 'HANDED');
});
