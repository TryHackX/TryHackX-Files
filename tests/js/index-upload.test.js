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

function loadIndex({ loggedIn = true, captcha = false, api = {}, maxFiles = 15, seedToken = null, captchaStub = null } = {}) {
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
	for (const id of ['fileList', 'sessionInfo', 'captchaOverlay', 'captchaLoading', 'captchaWidget', 'captchaError', 'results', 'resultList', 'copyAll', 'limitSize', 'limitFiles', 'limitCount']) {
		document.add('div', id);
	}

	const timers = [];
	let timerId = 0;
	const listeners = {};
	const storageBacking = new Map();
	if (seedToken) {
		storageBacking.set('uploadToken', seedToken);
		storageBacking.set('uploadTokenExpiry', String(Date.now() + 3600000));
	}
	const sessionStore = {
		getItem: key => (storageBacking.has(key) ? storageBacking.get(key) : null),
		setItem: (key, value) => storageBacking.set(key, String(value)),
		removeItem: key => storageBacking.delete(key)
	};
	const calls = { get: [], post: [], postForm: [] };
	const toasts = [];
	const verify = api.verify_captcha || (async () => ({ success: true, token: 'TOK' }));
	const window = {
		innerWidth: 1000, innerHeight: 800,
		crypto: webcrypto,
		sessionStorage: sessionStore,
		localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
		addEventListener: (type, handler) => { (listeners[type] ||= []).push(handler); },
		FHCaptcha: captchaStub || { configure() {}, isReady: () => false, load() {}, render: () => 1, reset() {} },
		FHUi: { toast: (message, options) => { toasts.push({ message, options }); }, singleFlight: (_key, task) => task() },
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
				return api.postForm ? api.postForm(action, form) : { success: true };
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

	let picked = 0;
	const pickFiles = (...names) => {
		fileInput.files = names.map(name => {
			picked += 1;
			return { name, size: 10 + picked, lastModified: 1000 + picked };
		});
		fileInput.dispatchEvent({ type: 'change' });
	};
	const pickFile = file => {
		fileInput.files = [file];
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
	const rows = () => document.getElementById('fileList').children;
	/** Swap the dictionary and announce it, as ui.js does after a live language switch. */
	const switchLanguage = translate => {
		context.t = translate;
		window.t = translate;
		(document.listeners['fh:languagechange'] || []).forEach(handler => handler({ type: 'fh:languagechange' }));
	};
	return { window, document, calls, toasts, pickFiles, pickFile, rows, listeners, pendingTimers, runNextTimer, sessionStore, dropZone, switchLanguage };
}

/** The visible text of a file row: main status, sub status, and whether it offers Retry. */
function rowState(row) {
	return {
		main: row.querySelector('.file-status-main').textContent,
		sub: row.querySelector('.file-status-sub').textContent,
		retry: Boolean(row.querySelector('.retry')),
		error: row.className.includes('error')
	};
}

const validSession = (extra = {}) => () => Object.assign({ success: true, valid: true, expires_at: 4102444800 }, extra);

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

// The upload server no longer opens an implicit guest session: an upload without a token is
// refused with 403, so a guest's upload waits for a token like everyone else's.
test('a guest whose upload token cannot be obtained gets an error row too, not a request bound to fail', async () => {
	let attempts = 0;
	const page = loadIndex({
		loggedIn: false,
		api: {
			verify_captcha: async () => (++attempts <= 2 ? { success: false } : { success: true, token: 'LATE' }),
			token_info: validSession()
		}
	});
	await flush();
	page.pickFiles('a.txt');
	await flush();
	assert.equal(FakeXhr.instances.length, 0, 'nothing is sent without a token');
	assert.deepEqual(rowState(page.rows()[0]), { main: 'up.session_failed', sub: 'up.click_retry', retry: true, error: true });

	page.rows()[0].querySelector('.retry').click();
	await flush();
	assert.equal(FakeXhr.instances.length, 1);
	assert.equal(FakeXhr.instances[0].headers['X-Upload-Token'], 'LATE');
});

test('without a captcha an upload refused for its token gets a fresh one from PHP and goes again once, with no dialog', async () => {
	const tokens = ['FIRST', 'SECOND', 'THIRD'];
	const page = loadIndex({
		loggedIn: false,
		api: { verify_captcha: async () => ({ success: true, token: tokens.shift() }), token_info: validSession() }
	});
	await flush();
	page.pickFiles('a.txt');
	await flush();
	assert.equal(FakeXhr.instances[0].headers['X-Upload-Token'], 'FIRST');

	const tokenRefused = '{"detail":"Invalid or missing upload token. Please verify reCAPTCHA."}';
	await FakeXhr.instances[0].finish(403, tokenRefused);
	await flush();
	assert.equal(FakeXhr.instances.length, 2, 'sent again');
	assert.equal(FakeXhr.instances[1].headers['X-Upload-Token'], 'SECOND', 'on a token fetched from PHP');
	assert.equal(page.document.getElementById('captchaOverlay').classList.contains('show'), false,
		'no verification dialog without a captcha');

	await FakeXhr.instances[1].finish(403, tokenRefused);
	await flush();
	assert.equal(FakeXhr.instances.length, 2, 'but only once');
	assert.deepEqual(rowState(page.rows()[0]), { main: 'up.session_failed', sub: 'up.click_retry', retry: true, error: true });
	assert.equal(page.document.getElementById('captchaOverlay').classList.contains('show'), false);

	// The other 403s are about the account or the address, and a new token changes nothing.
	page.pickFiles('b.txt');
	await flush();
	const sent = FakeXhr.instances.length;
	await FakeXhr.instances[sent - 1].finish(403, '{"detail":"User is inactive"}');
	await flush();
	assert.equal(FakeXhr.instances.length, sent, 'an inactive account is not retried');
	assert.deepEqual(rowState(page.rows()[1]), { main: 'up.refused', sub: 'up.account_inactive', retry: false, error: true });
});

test('a token renewed by one refused upload is reused by the next, not thrown away', async () => {
	const tokens = ['FIRST', 'SECOND', 'THIRD'];
	const page = loadIndex({
		api: { verify_captcha: async () => ({ success: true, token: tokens.shift() }), token_info: validSession() }
	});
	await flush();
	page.pickFiles('a.txt', 'b.txt');
	await flush();
	const tokenRefused = '{"detail":"Invalid or missing upload token. Please verify reCAPTCHA."}';
	await FakeXhr.instances[0].finish(403, tokenRefused);
	await flush();
	assert.equal(FakeXhr.instances[2].headers['X-Upload-Token'], 'SECOND');

	await FakeXhr.instances[1].finish(403, tokenRefused);   // sent with FIRST before the renewal
	await flush();
	assert.equal(FakeXhr.instances[3].headers['X-Upload-Token'], 'SECOND', 'goes again on the renewed token');
	assert.equal(page.calls.post.filter(call => call.action === 'verify_captcha').length, 2,
		'one token at start-up and one renewal, not one per refused upload');
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

test('signing in shows the account\'s files-per-session cap at once, not the guest\'s', async () => {
	let cap = 5;
	const page = loadIndex({
		loggedIn: false,
		api: { token_info: () => ({ success: true, valid: true, expires_at: 4102444800, files_uploaded: 0, files_limit: cap }) }
	});
	await flush();
	assert.match(page.document.getElementById('sessionInfo').textContent, /5\/5$/);

	// The sign-in kept this tab's token and handed it to the account (auth.js then calls refresh).
	cap = 15;
	page.window.FHUploadSession.refresh();
	await flush();
	assert.match(page.document.getElementById('sessionInfo').textContent, /15\/15$/);
	assert.equal(page.document.getElementById('limitCount').textContent, '15');
	assert.equal(page.calls.get.filter(call => call.action === 'token_info').at(-1).params.token, 'TOK', 'the same token, asked again');
});

test('refusals are explained in the page language, and Retry is offered only where it can help', async () => {
	const page = loadIndex({ api: { token_info: validSession() } });
	await flush();
	const refuse = async (status, body) => {
		page.pickFiles('f' + status + '.bin');
		await flush();
		await FakeXhr.instances[FakeXhr.instances.length - 1].finish(status, body);
		await flush();
		return rowState(page.rows()[page.rows().length - 1]);
	};

	assert.deepEqual(await refuse(507, '{"detail":"Storage quota exceeded"}'),
		{ main: 'up.no_space', sub: 'up.quota_full', retry: false, error: true });
	assert.deepEqual(await refuse(507, '{"detail":"Server storage limit reached"}'),
		{ main: 'up.no_space', sub: 'up.storage_full', retry: false, error: true });
	assert.deepEqual(await refuse(413, '{"detail":"File too large (Max 100 MiB)"}'),
		{ main: 'up.too_big', sub: 'up.max_size {"size":"100.00 MiB"}', retry: false, error: true });
	assert.deepEqual(await refuse(400, '{"detail":"Empty file"}'),
		{ main: 'up.empty_file', sub: 'up.empty_file_sub', retry: false, error: true });
	assert.deepEqual(await refuse(403, '{"detail":"User is inactive"}'),
		{ main: 'up.refused', sub: 'up.account_inactive', retry: false, error: true });
	assert.deepEqual(await refuse(403, '{"detail":"IP banned"}'),
		{ main: 'up.refused', sub: 'up.banned', retry: false, error: true });
	assert.deepEqual(await refuse(503, '{"detail":"Upload settings unavailable"}'),
		{ main: 'up.error', sub: 'up.try_again', retry: true, error: true });
	assert.deepEqual(await refuse(502, '<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN"><html>Bad Gateway</html>'),
		{ main: 'up.error', sub: 'up.try_again', retry: true, error: true });

	for (const row of page.rows()) assert.doesNotMatch(row.textContent, /detail|DOCTYPE/, 'no raw server body in a row');
	assert.equal(page.document.getElementById('captchaOverlay').classList.contains('show'), false,
		'an inactive or banned account is not sent round the captcha');
});

test('an empty file is refused on the page instead of being sent', async () => {
	const page = loadIndex({ api: { token_info: validSession() } });
	await flush();
	page.pickFile({ name: 'empty.txt', size: 0, lastModified: 5 });
	await flush();
	assert.equal(FakeXhr.instances.length, 0);
	assert.deepEqual(rowState(page.rows()[0]), { main: 'up.empty_file', sub: 'up.empty_file_sub', retry: false, error: true });
});

test('without a captcha a spent session is renewed instead of locking the page behind the verification dialog', async () => {
	const tokens = ['FIRST', 'SECOND', 'THIRD'];
	let uploaded = 0;
	const page = loadIndex({
		api: {
			verify_captcha: async () => ({ success: true, token: tokens.shift() }),
			token_info: () => ({ success: true, valid: true, expires_at: 4102444800, files_uploaded: uploaded, files_limit: 2 })
		}
	});
	await flush();
	page.pickFiles('a.txt', 'b.txt');
	await flush();
	assert.equal(FakeXhr.instances[1].headers['X-Upload-Token'], 'FIRST');

	const sessionChecks = () => page.calls.get.filter(call => call.action === 'token_info').length;
	const before = sessionChecks();
	uploaded = 1;
	await FakeXhr.instances[0].finish(200, 'id1:tok1');
	await flush();
	assert.equal(sessionChecks(), before + 1, 'the session line is refreshed after an upload, captcha or not');
	assert.match(page.document.getElementById('sessionInfo').textContent, /1\/2/);

	uploaded = 2;
	await FakeXhr.instances[1].finish(200, 'id2:tok2');
	await flush();
	assert.equal(page.document.getElementById('captchaOverlay').classList.contains('show'), false,
		'no empty "verification" dialog without a captcha');

	uploaded = 0;
	page.pickFiles('c.txt');
	await flush();
	assert.equal(FakeXhr.instances[2].headers['X-Upload-Token'], 'SECOND', 'the next upload simply starts a new session');
});

test('without a captcha a "session file limit" refusal is retried once with a new session', async () => {
	const tokens = ['FIRST', 'SECOND', 'THIRD'];
	const page = loadIndex({
		api: { verify_captcha: async () => ({ success: true, token: tokens.shift() }), token_info: validSession() }
	});
	await flush();
	page.pickFiles('a.txt');
	await flush();
	await FakeXhr.instances[0].finish(429, '{"detail":"Upload session file limit reached"}');
	await flush();
	assert.equal(FakeXhr.instances.length, 2, 'sent again');
	assert.equal(FakeXhr.instances[1].headers['X-Upload-Token'], 'SECOND');

	await FakeXhr.instances[1].finish(429, '{"detail":"Upload session file limit reached"}');
	await flush();
	assert.equal(FakeXhr.instances.length, 2, 'but only once');
	assert.deepEqual(rowState(page.rows()[0]), { main: 'up.session_failed', sub: 'up.click_retry', retry: true, error: true });
});

test('with a captcha a refused session waits for the verification and then resumes by itself', async () => {
	const captured = {};
	const captchaStub = {
		configure() {}, isReady: () => false, reset() {},
		load: callback => callback(),
		render: (_id, handlers) => { captured.handlers = handlers; return 1; }
	};
	const page = loadIndex({
		captcha: true,
		seedToken: 'SEEDED',
		captchaStub,
		api: { token_info: validSession({ files_limit: 0 }), verify_captcha: async () => ({ success: true, token: 'SOLVED' }) }
	});
	await flush();
	page.pickFiles('a.txt');
	await flush();
	assert.equal(FakeXhr.instances[0].headers['X-Upload-Token'], 'SEEDED');

	await FakeXhr.instances[0].finish(403, '{"detail":"Invalid or missing upload token. Please verify reCAPTCHA."}');
	await flush();
	assert.deepEqual(rowState(page.rows()[0]), { main: 'up.verify_expired', sub: 'up.verify_resume', retry: false, error: true });
	assert.equal(page.document.getElementById('captchaOverlay').classList.contains('show'), true);
	assert.equal(FakeXhr.instances.length, 1);

	await captured.handlers.callback('captcha-response');
	await flush();
	assert.equal(page.document.getElementById('captchaOverlay').classList.contains('show'), false);
	assert.equal(FakeXhr.instances.length, 2, 'the file went again after the captcha');
	assert.equal(FakeXhr.instances[1].headers['X-Upload-Token'], 'SOLVED');
});

test('a failed delete keeps the row, says so, and lets the user try again', async () => {
	let answer = { success: false, error: 'Too many requests' };
	const page = loadIndex({ api: { token_info: validSession(), postForm: () => answer } });
	await flush();
	page.pickFiles('keep.txt');
	await flush();
	await FakeXhr.instances[0].finish(200, 'fileid1:deletetoken1');
	await flush();

	const remove = page.rows()[0].querySelector('.remove');
	remove.click();
	remove.click();
	await flush();
	assert.equal(page.calls.postForm.length, 1);
	assert.equal(page.rows().length, 1, 'the row stays while the file is still online');
	assert.equal(page.toasts.at(-1).message, 'up.delete_failed — Too many requests');
	assert.equal(page.toasts.at(-1).options.type, 'error');
	assert.equal(remove.disabled, false);

	answer = { success: true };
	remove.click();
	await flush();
	assert.equal(page.calls.postForm.length, 2);
	assert.ok(page.rows()[0].className.includes('removing'), 'gone once the server confirmed');
});

test('a files-per-session cap of 0 means no cap, and the card shows the cap the server applies', async () => {
	const page = loadIndex({ maxFiles: 0, api: { token_info: validSession({ files_limit: 0 }) } });
	await flush();
	assert.equal(page.document.getElementById('limitFiles').hidden, true, 'no "/ 0 files" on the card');
	page.pickFiles(...Array.from({ length: 20 }, (_, i) => 'f' + i + '.txt'));
	await flush();
	assert.equal(FakeXhr.instances.length, 20);

	const capped = loadIndex({ maxFiles: 15, api: { token_info: validSession({ files_limit: 3 }) } });
	await flush();
	assert.equal(capped.document.getElementById('limitCount').textContent, '3');
	assert.equal(capped.document.getElementById('limitFiles').hidden, false);
});

test('the "at once" cap counts uploads still running, not finished rows', async () => {
	const page = loadIndex({ maxFiles: 2, api: { token_info: validSession({ files_limit: 2 }) } });
	await flush();
	page.pickFiles('a.txt', 'b.txt', 'c.txt');
	await flush();
	assert.equal(FakeXhr.instances.length, 2);
	assert.equal(page.toasts.at(-1).message, 'up.max_files {"n":2}');

	await FakeXhr.instances[0].finish(200, 'id1:tok1');
	await flush();
	page.pickFiles('d.txt');
	await flush();
	assert.equal(FakeXhr.instances.length, 3, 'a finished upload frees its place');
});

/**
 * The live language switch (ui.js) only rewrites what the server rendered. The rows, result
 * cards and session line this script wrote itself stayed in the old language until a reload.
 */
test('the rows, result cards and session line the page wrote follow the live language switch', async () => {
	const page = loadIndex({ api: { token_info: validSession({ files_uploaded: 1, files_limit: 5 }) } });
	await flush();
	page.pickFiles('done.txt');
	page.pickFile({ name: 'huge.bin', size: 6 * 1024 * 1024 * 1024, lastModified: 7 });
	await flush();
	await FakeXhr.instances[0].finish(200, 'id1:tok1');
	await flush();
	assert.equal(rowState(page.rows()[0]).main, 'up.done');

	page.switchLanguage((key, params) => 'EN ' + key + (params ? ' ' + JSON.stringify(params) : ''));

	const [done, huge] = page.rows();
	assert.deepEqual(rowState(done), { main: 'EN up.done', sub: 'EN up.uploaded', retry: false, error: false });
	assert.equal(done.querySelector('.remove').title, 'EN up.remove_delete');
	assert.equal(rowState(huge).main, 'EN up.too_big');
	assert.equal(rowState(huge).sub, 'EN up.max_size {"size":"5.00 GiB"}', 'with its parameters');
	const card = page.document.getElementById('resultList').children[0];
	assert.equal(card.querySelector('.token-label').textContent.trim(), 'EN up.delete_token_label');
	assert.equal(card.querySelector('.token-copy').textContent, 'EN common.copy');
	assert.match(page.document.getElementById('sessionInfo').textContent, /^EN up\.session \d+ EN up\.minutes \| EN up\.files_left 4\/5$/);
});
