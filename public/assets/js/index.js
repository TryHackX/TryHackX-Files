/**
 * TryHackX Files — strona główna (upload).
 * Konfiguracja z `window.APP`: { appUrl, apiUrl, uploadUrl, guestLimitMB, userLimitMB,
 * guestLimitFiles, userLimitFiles, maxSizeMB, maxFiles, blockedExtensions }.
 * `currentUser`, `updateAuthUI`, `showAuthModal` pochodzą z auth_scripts.php (ładowane wcześniej).
 */
'use strict';

(function () {
	const bootstrap = document.getElementById('indexBootstrap');
	const APP = JSON.parse(
		bootstrap && bootstrap.dataset.config ? bootstrap.dataset.config : '{}'
	);
	const appUrl = APP.appUrl;
	const uploadUrl = APP.uploadUrl;

	const guestLimitMB = APP.guestLimitMB;
	const userLimitMB = APP.userLimitMB;
	const guestLimitFiles = APP.guestLimitFiles;
	const userLimitFiles = APP.userLimitFiles;

	let maxFileSizeMB = APP.maxSizeMB;
	let maxFileSize = maxFileSizeMB * 1024 * 1024;
	let maxFiles = APP.maxFiles;

	const blockedExtensions = new Set(APP.blockedExtensions || []);

	const uploadQueue = new Map();
	const resultFiles = new Map();

	// Upload/captcha token lives in sessionStorage, not localStorage: it's a
	// short-lived, per-session credential, so it shouldn't outlive the tab or be
	// readable across sessions if the page is ever hit by XSS. Migrate + purge any
	// legacy localStorage copy from older builds. FHStorage (bootstrap.js) never throws, so
	// blocked site data degrades to an in-memory token instead of killing this whole script.
	const storage = window.FHStorage;
	if (storage.local.get('uploadToken')) {
		if (!storage.session.get('uploadToken')) {
			storage.session.set('uploadToken', storage.local.get('uploadToken'));
			storage.session.set('uploadTokenExpiry', storage.local.get('uploadTokenExpiry') || '0');
		}
		storage.local.remove('uploadToken');
		storage.local.remove('uploadTokenExpiry');
	}
	let uploadToken = storage.session.get('uploadToken') || null;
	let uploadTokenExpiry = parseInt(storage.session.get('uploadTokenExpiry') || '0');
	let captchaEnabled = false;
	let captchaSiteKey = '';
	let captchaTokenLifetimeMs = 120 * 60 * 1000;
	let captchaWidgetId = null;
	let filesUploaded = 0;
	let filesLimit = 0;

	/* ---------- helpers (shared: assets/js/util.js) ---------- */
	const formatSize = window.FHUtil.formatSize;
	const safeHttpUrl = window.FHUtil.safeHttpUrl;

	function makeNode(tag, className, text) {
		const node = document.createElement(tag);
		if (className) node.className = className;
		if (text !== undefined) node.textContent = String(text);
		return node;
	}

	function makeIcon(iconClass) {
		const icon = makeNode('i', 'fa-solid ' + iconClass);
		icon.setAttribute('aria-hidden', 'true');
		return icon;
	}

	function makeFileIcon(name, mime) {
		return typeof window.fileIconElement === 'function'
			? window.fileIconElement(name, mime)
			: makeIcon('fa-file');
	}

	function makeActionButton(className, title, iconClass, handler) {
		const button = makeNode('button', className);
		button.type = 'button';
		button.title = title;
		button.appendChild(makeIcon(iconClass));
		button.addEventListener('click', handler);
		return button;
	}

	function renderQrImage(holder, value) {
		const image = makeNode('img');
		image.alt = t('qr.alt');
		image.width = 240;
		image.height = 240;
		image.src = appUrl + '/api/qr?scale=6&data=' + encodeURIComponent(value);
		holder.replaceChildren(image);
	}

	// Whether a filename looks like an image/video, so we try a server thumbnail for it.
	function isMediaName(name) {
		const ext = (name.split('.').pop() || '').toLowerCase();
		return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'avif', 'mp4', 'webm', 'mov', 'avi', 'mkv', 'm4v'].includes(ext);
	}

	function showToast(text) {
		window.FHUi.toast(text);
	}

	function isTokenValid() {
		return uploadToken && uploadTokenExpiry > Date.now();
	}

	function persistToken(token) {
		uploadToken = token;
		uploadTokenExpiry = Date.now() + captchaTokenLifetimeMs;
		storage.session.set('uploadToken', uploadToken);
		storage.session.set('uploadTokenExpiry', uploadTokenExpiry.toString());
	}

	function clearToken() {
		uploadToken = null;
		storage.session.remove('uploadToken');
		storage.session.remove('uploadTokenExpiry');
	}

	// Signing in or out in the account modal changes whose upload session this tab holds
	// (bootstrap.js, FHUploadSession). Follow it, or the next upload is attributed by the token
	// this page loaded with: the previous account's after a sign-out or a switch of accounts.
	if (window.FHUploadSession) {
		window.FHUploadSession.subscribe((token, expiresAtMs) => {
			uploadToken = token || null;
			uploadTokenExpiry = token ? Number(expiresAtMs) || 0 : 0;
			filesUploaded = 0;
			filesLimit = 0;
			updateSessionInfo();
		});
	}

	/* ---------- captcha / upload session ---------- */

	/**
	 * Without a captcha the upload token is only a tracking token, handed out on request. It is
	 * also what ties an upload to the signed-in account, so an upload must never start without one.
	 * Returns whether a valid token is available afterwards.
	 */
	let trackingTokenRequest = null;
	async function ensureTrackingToken() {
		if (captchaEnabled || isTokenValid()) return Boolean(isTokenValid());
		// Several uploads can find the token gone at once (a spent session refused them all);
		// they share one request instead of each opening a session of its own.
		if (!trackingTokenRequest) {
			trackingTokenRequest = FHApi.post('verify_captcha', {})
				.then(tData => { if (tData && tData.success && tData.token) persistToken(tData.token); })
				.catch(e => { console.error('Auto-token fetch failed', e); })
				.finally(() => { trackingTokenRequest = null; });
		}
		await trackingTokenRequest;
		return Boolean(isTokenValid());
	}

	async function initCaptcha() {
		try {
			const data = await FHApi.get('config');
			captchaEnabled = data.recaptcha_enabled;
			captchaSiteKey = data.recaptcha_site_key;
			FHCaptcha.configure(data);
			if (data.recaptcha_token_lifetime) {
				captchaTokenLifetimeMs = data.recaptcha_token_lifetime * 60 * 1000;
			}

			if (captchaEnabled && captchaSiteKey) {
				if (isTokenValid()) {
					await updateSessionInfo();
					return;
				}
				clearToken();
				loadRecaptchaScript();
				document.getElementById('captchaOverlay').classList.add('show');

				setTimeout(() => {
					const loading = document.getElementById('captchaLoading');
					if (loading && loading.style.display !== 'none') {
						const reload = makeNode('button', 'btn-link', t('up.reload'));
						reload.type = 'button';
						reload.addEventListener('click', () => window.location.reload());
						loading.replaceChildren(
							document.createTextNode(t('up.slow_loading') + ' '),
							reload,
							document.createTextNode(' ' + t('up.or_check_conn'))
						);
						loading.style.color = '#ff6b6b';
					}
				}, 10000);
			} else {
				// Captcha disabled: obtain a tracking token automatically.
				await ensureTrackingToken();
				await updateSessionInfo();
			}
		} catch (e) {
			console.log('reCAPTCHA init failed:', e);
		}
	}

	function loadRecaptchaScript() {
		FHCaptcha.load(onCaptchaLoad);
	}

	const captchaHandlers = {
		callback: onCaptchaSuccess,
		'error-callback': onCaptchaError
	};

	function onCaptchaLoad() {
		document.getElementById('captchaLoading').style.display = 'none';
		if (captchaWidgetId !== null) {
			FHCaptcha.reset(captchaWidgetId, captchaHandlers);
			return;
		}
		document.getElementById('captchaWidget').replaceChildren();
		captchaWidgetId = FHCaptcha.render('captchaWidget', captchaHandlers);
	}

	async function onCaptchaSuccess(response) {
		document.getElementById('captchaError').style.display = 'none';
		try {
			const data = await FHApi.post('verify_captcha', { captcha_response: response });
			if (data.success && data.token) {
				persistToken(data.token);
				document.getElementById('captchaOverlay').classList.remove('show');
				await updateSessionInfo();
				waitingForCaptcha.splice(0).forEach(entry => startUpload(entry.id, entry.file));
			} else {
				onCaptchaError();
			}
		} catch (e) {
			onCaptchaError();
		}
	}

	function onCaptchaError() {
		document.getElementById('captchaError').style.display = 'block';
		if (captchaWidgetId !== null) FHCaptcha.reset(captchaWidgetId, captchaHandlers);
	}

	async function updateSessionInfo() {
		const sessionInfo = document.getElementById('sessionInfo');
		if (!uploadToken) {
			sessionInfo.replaceChildren();
			return;
		}
		try {
			const data = await FHApi.get('token_info', { token: uploadToken });
			if (data.success && data.valid) {
				filesUploaded = data.files_uploaded || 0;
				filesLimit = data.files_limit || 0;
				// The cap the upload server applies to this session (0 = none) is the one to show.
				if (typeof data.files_limit === 'number') showFilesCap(data.files_limit);
				const remaining = Math.max(0, Math.floor((data.expires_at * 1000 - Date.now()) / 60000));
				const parts = [
					document.createTextNode(t('up.session') + ' '),
					makeNode('span', '', remaining + ' ' + t('up.minutes'))
				];
				if (filesLimit > 0) {
					const filesRemaining = filesLimit - filesUploaded;
					parts.push(
						document.createTextNode(' | ' + t('up.files_left') + ' '),
						makeNode('span', '', filesRemaining + '/' + filesLimit)
					);
					if (filesRemaining <= 0) showCaptchaRequired();
				}
				sessionInfo.replaceChildren(...parts);
			} else if (data.valid === false) {
				// Only the server's explicit "this token is not valid" ends the session. A
				// rate-limit (429) or error body carries no `valid` flag: that is "unknown", and
				// clearing the token on it threw a working upload session — and its captcha
				// solve — away mid-batch.
				showCaptchaRequired();
			}
		} catch (e) {
			console.log('Token info error:', e);
		}
	}

	function showCaptchaRequired() {
		clearToken();
		// Without a captcha there is nothing to solve: the next upload fetches a fresh tracking
		// token (ensureTrackingToken), which is all a reload did. The overlay has no close button
		// and no widget in that mode, so showing it locked the page until a second reload.
		if (!captchaEnabled) return;
		const loading = document.getElementById('captchaLoading');
		if (loading) loading.style.display = 'none';
		document.getElementById('captchaError').style.display = 'none';

		if (captchaWidgetId !== null) {
			FHCaptcha.reset(captchaWidgetId, captchaHandlers);
		} else if (captchaEnabled && captchaSiteKey) {
			if (FHCaptcha.isReady()) {
				onCaptchaLoad();
			} else {
				loadRecaptchaScript();
				if (loading) loading.style.display = 'block';
			}
		}
		document.getElementById('captchaOverlay').classList.add('show');
	}

	/* ---------- limits ---------- */
	function updateLimits(user) {
		maxFileSizeMB = user ? userLimitMB : guestLimitMB;
		maxFileSize = maxFileSizeMB * 1024 * 1024;
		window.maxFileSize = maxFileSize;

		const sizeText = maxFileSizeMB >= 1024 ? (maxFileSizeMB / 1024).toFixed(2) + ' GiB' : maxFileSizeMB + ' MiB';
		document.querySelectorAll('#limitSize').forEach(el => el.textContent = sizeText);
		showFilesCap(user ? userLimitFiles : guestLimitFiles);
	}

	/**
	 * The files-per-session cap on the card, and the page's own "at once" check. 0 means no cap
	 * (the server's convention too), so the card then shows the size limit alone. The upload
	 * session reports the cap the upload server will actually apply (token_info), and that is
	 * the number shown from then on — signing in or out, or a group change, moves it.
	 */
	function showFilesCap(cap) {
		maxFiles = Math.max(0, Number(cap) || 0);
		window.maxFiles = maxFiles;
		document.querySelectorAll('#limitCount').forEach(el => { el.textContent = String(maxFiles); });
		document.querySelectorAll('#limitFiles').forEach(el => { el.hidden = maxFiles === 0; });
	}

	/** Files picked on this page that have not finished (or failed) yet. */
	function activeUploads() {
		let n = 0;
		uploadQueue.forEach(entry => {
			if (entry.status === 'pending' || entry.status === 'uploading' || entry.status === 'waiting') n += 1;
		});
		return n;
	}

	/* ---------- drag & drop ---------- */
	const dropZone = document.getElementById('dropZone');
	const fileInput = document.getElementById('fileInput');
	const browseBtn = document.getElementById('browseBtn');

	browseBtn.addEventListener('click', () => fileInput.click());
	dropZone.addEventListener('click', e => {
		if (e.target === dropZone || e.target.closest('.drop-zone-icon')) fileInput.click();
	});
	dropZone.addEventListener('dragover', e => {
		e.preventDefault();
		dropZone.classList.add('dragover');
	});
	dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
	dropZone.addEventListener('drop', e => {
		e.preventDefault();
		dropZone.classList.remove('dragover');
		handleFiles(e.dataTransfer.files);
	});
	fileInput.addEventListener('change', () => {
		handleFiles(fileInput.files);
		fileInput.value = '';
	});

	/**
	 * Is this file already in the queue (or already uploaded in this session)?
	 *
	 * Compared on name + size + lastModified: the File API gives no identity beyond that, and
	 * the three together are a good proxy for "the user picked the same thing twice".
	 */
	function isAlreadyQueued(file) {
		for (const entry of uploadQueue.values()) {
			const f = entry.file;
			if (f && f.name === file.name && f.size === file.size && f.lastModified === file.lastModified) {
				return true;
			}
		}
		return false;
	}

	function handleFiles(files) {
		if (captchaEnabled && !isTokenValid()) {
			showCaptchaRequired();
			return;
		}
		Array.from(files).forEach(file => {
			const id = 'f-' + Math.random().toString(36).substr(2, 9);
			const ext = file.name.split('.').pop().toLowerCase();

			// Picking the same file twice (re-opening the dialog, or dropping over an existing
			// selection) used to queue and upload it again. Name+size+mtime identifies a file
			// closely enough here — two genuinely different files sharing all three would be an
			// odd edge case, and the user can still upload one deliberately by renaming it.
			if (isAlreadyQueued(file)) {
				showToast(t('up.duplicate', { name: file.name }));
				return;
			}
			if (blockedExtensions.has(ext)) {
				addFileItem(id, file.name, file.size, 'error', t('up.blocked'), t('up.blocked_sub'));
				return;
			}
			// The upload server refuses an empty body; say so here instead of sending it.
			if (file.size === 0) {
				addFileItem(id, file.name, 0, 'error', t('up.empty_file'), t('up.empty_file_sub'));
				return;
			}
			if (file.size > maxFileSize) {
				addFileItem(id, file.name, file.size, 'error', t('up.too_big'), t('up.max_size', { size: formatSize(maxFileSize) }));
				return;
			}
			// "At once": finished and failed rows stay on the page and must not use up the cap.
			if (maxFiles > 0 && activeUploads() >= maxFiles) {
				showToast(t('up.max_files', { n: maxFiles }));
				return;
			}
			uploadQueue.set(id, { file, status: 'pending' });
			addFileItem(id, file.name, file.size, 'pending', t('up.pending'), '');
			uploadFile(id, file);
		});
	}

	function renderFileActions(actions, id, includeRetry) {
		const buttons = [];
		if (includeRetry) {
			buttons.push(makeActionButton(
				'file-btn retry',
				t('up.retry'),
				'fa-arrow-rotate-right',
				() => retryUpload(id)
			));
		}
		buttons.push(makeActionButton(
			'file-btn remove',
			removeTitle(id),
			'fa-xmark',
			event => onRemoveClick(id, event.currentTarget)
		));
		actions.replaceChildren(...buttons);
	}

	/** Does removing this row also delete the file from the server? (only finished uploads) */
	function deletesServerFile(id) {
		const entry = uploadQueue.get(id);
		return Boolean(entry && entry.status === 'success' && entry.serverId && entry.deleteToken);
	}

	function removeTitle(id) {
		return deletesServerFile(id) ? t('up.remove_delete') : t('up.remove');
	}

	/**
	 * For a finished upload the "×" is a delete: the link may already have been copied or sent.
	 * The first click arms the button (icon turns into a trash can, tip says so); only a second
	 * click within a few seconds deletes. Anything else just drops the row.
	 */
	function onRemoveClick(id, button) {
		if (!deletesServerFile(id)) {
			removeFile(id);
			return;
		}
		if (button.dataset.armed === '1') {
			removeFile(id, button);
			return;
		}
		button.dataset.armed = '1';
		button.title = t('up.remove_confirm');
		const glyph = button.querySelector('i');
		if (glyph) glyph.className = 'fa-solid fa-trash';
		showCopiedTip(button, t('up.remove_confirm'));
		setTimeout(() => {
			if (!button.isConnected) return;
			delete button.dataset.armed;
			button.title = removeTitle(id);
			const icon = button.querySelector('i');
			if (icon) icon.className = 'fa-solid fa-xmark';
		}, 4000);
	}

	// Closing the tab or following a link while a transfer is running throws it away without a
	// word; ask first (browsers show their own generic text).
	window.addEventListener('beforeunload', event => {
		for (const entry of uploadQueue.values()) {
			if (entry.status === 'uploading') {
				event.preventDefault();
				event.returnValue = '';
				return '';
			}
		}
		return undefined;
	});

	function addFileItem(id, name, size, status, statusMain, statusSub) {
		const list = document.getElementById('fileList');
		const item = makeNode('div', 'file-item ' + status);
		item.id = id;
		const progress = makeNode('div', 'file-progress');
		progress.style.width = '0%';
		const icon = makeNode('div', 'file-icon');
		icon.appendChild(makeFileIcon(name));
		const info = makeNode('div', 'file-info');
		const meta = makeNode('div', 'file-meta');
		meta.appendChild(makeNode('span', '', formatSize(size)));
		info.append(makeNode('div', 'file-name', name), meta);
		const statusBox = makeNode('div', 'file-status');
		statusBox.append(
			makeNode('span', 'file-status-main', statusMain),
			makeNode('span', 'file-status-sub', statusSub)
		);
		const actions = makeNode('div', 'file-actions');
		renderFileActions(actions, id, status === 'error-network');
		item.append(progress, icon, info, statusBox, actions);
		list.appendChild(item);
	}

	function updateFileItem(id, status, statusText, subText, progress) {
		const item = document.getElementById(id);
		if (!item) return;
		item.className = `file-item ${status}`;

		const statusEl = item.querySelector('.file-status-main');
		if (statusEl.textContent !== statusText) statusEl.textContent = statusText;
		const subEl = item.querySelector('.file-status-sub');
		if (subEl.textContent !== subText) subEl.textContent = subText;
		const progressEl = item.querySelector('.file-progress');
		if (progressEl) progressEl.style.width = `${progress}%`;

		const actions = item.querySelector('.file-actions');
		if (status === 'error-network') {
			if (!item.querySelector('.retry')) renderFileActions(actions, id, true);
		} else if (!item.querySelector('.remove') || item.querySelector('.retry')) {
			renderFileActions(actions, id, false);
		}
	}

	function removeFile(id, button = null) {
		const item = document.getElementById(id);
		if (!item) return;
		const queueItem = uploadQueue.get(id);
		if (queueItem && queueItem.xhr) queueItem.xhr.abort();

		if (queueItem && queueItem.status === 'success' && queueItem.serverId && queueItem.deleteToken) {
			// The row goes only once the server confirms: a failed delete (network, rate limit)
			// used to drop it anyway while the file stayed online with a live link.
			if (button) button.disabled = true;
			const formData = new FormData();
			formData.append('id', queueItem.serverId);
			formData.append('token', queueItem.deleteToken);
			const failed = error => {
				if (button) button.disabled = false;
				window.FHUi.toast(error ? t('up.delete_failed') + ' — ' + error : t('up.delete_failed'), { type: 'error' });
			};
			FHApi.postForm('delete', formData)
				.then(d => {
					if (!d || !d.success) {
						failed(d && d.error);
						return;
					}
					resultFiles.delete(queueItem.serverId);
					document.getElementById('r-' + queueItem.serverId)?.remove();
					if (resultFiles.size === 0) document.getElementById('results').classList.remove('show');
					if (resultFiles.size <= 1) document.getElementById('copyAll').style.display = 'none';
					updateCollectionButton();
					dropRow(id, item);
				})
				.catch(() => failed(t('common.connection_error')));
			return;
		}
		dropRow(id, item);
	}

	function dropRow(id, item) {
		uploadQueue.delete(id);
		waitingForCaptcha.splice(0, waitingForCaptcha.length, ...waitingForCaptcha.filter(entry => entry.id !== id));
		item.classList.add('removing');
		setTimeout(() => item.remove(), 300);
	}

	function retryUpload(id) {
		const queueItem = uploadQueue.get(id);
		if (!queueItem || !queueItem.file) return;
		queueItem.status = 'pending';
		updateFileItem(id, 'pending', t('up.pending'), '', 0);
		uploadFile(id, queueItem.file);
	}

	/*
	 * Has an administrator cut one of our transfers from the dashboard?
	 *
	 * One shared timer asks about every running upload in turn. It used to be one 750 ms
	 * interval per file — 80 requests a minute each — so a handful of parallel uploads spent the
	 * whole per-IP `read` budget (300/min) and the rest of the page started getting 429s. A cut
	 * transfer is noticed within a few seconds now, which is all that feature needs, and the
	 * loop backs off when the server says "slow down".
	 */
	const STATUS_POLL_MS = 4000;
	const STATUS_POLL_MAX_MS = 30000;
	const polledUploads = new Set();
	let statusPollMs = STATUS_POLL_MS;
	let statusPollTimer = null;

	function scheduleStatusPoll() {
		if (statusPollTimer === null) statusPollTimer = setTimeout(runStatusPoll, statusPollMs);
	}

	function startStatusPolling(entry) {
		polledUploads.add(entry);
		scheduleStatusPoll();
	}

	function stopStatusPolling(entry) {
		polledUploads.delete(entry);
	}

	async function runStatusPoll() {
		statusPollTimer = null;
		try {
			for (const entry of Array.from(polledUploads)) {
				if (entry.status !== 'uploading') {
					polledUploads.delete(entry);
					continue;
				}
				const status = await FHApi.get('upload_status', { id: entry.clientId });
				if (status && status.retry_after) {
					// Rate limited: wait at least as long as the server asks, then ease back.
					statusPollMs = Math.min(
						STATUS_POLL_MAX_MS,
						Math.max(statusPollMs * 2, Number(status.retry_after) * 1000 || 0)
					);
					break;
				}
				statusPollMs = STATUS_POLL_MS;
				if (status && status.success && status.status === 'cancelled' && entry.status === 'uploading') {
					entry.cancelledByAdmin = true;
					entry.xhr.abort();
				}
			}
		} catch (e) {
			/* a transient status check must not fail an upload */
			statusPollMs = Math.min(STATUS_POLL_MAX_MS, statusPollMs * 2);
		}
		if (polledUploads.size > 0) scheduleStatusPoll();
	}

	// Uploads that had to wait for the captcha: resumed when it is solved.
	const waitingForCaptcha = [];

	/**
	 * Start (or retry) one upload — but only once the upload session is settled. Files picked
	 * while the page was still fetching its config / token used to start immediately, carry no
	 * `X-Upload-Token` and be stored as a guest's even for a signed-in user.
	 */
	function uploadFile(id, file) {
		return sessionReady.then(() => startUpload(id, file));
	}

	async function startUpload(id, file) {
		if (!uploadQueue.has(id)) return; // removed while it waited
		if (captchaEnabled) {
			if (!isTokenValid() || (filesLimit > 0 && filesUploaded >= filesLimit)) {
				if (!waitingForCaptcha.some(entry => entry.id === id)) waitingForCaptcha.push({ id, file });
				showCaptchaRequired();
				return;
			}
		} else if (!isTokenValid() && !(await ensureTrackingToken()) && APP.loggedIn) {
			// Signed in but no session token: uploading now would silently make it an anonymous
			// upload (no quota, not in "My files"). Say so and let the user retry.
			updateFileItem(id, 'error-network', t('up.session_failed'), t('up.click_retry'), 0);
			const failed = uploadQueue.get(id);
			if (failed) failed.status = 'error';
			return;
		}
		if (!uploadQueue.has(id)) return;
		sendUpload(id, file);
	}

	/**
	 * What a refused upload means, in the page's language: { main, sub, retry } for the row, or
	 * { session: true } when the upload session itself is the problem.
	 *
	 * The upload server answers FastAPI's `{"detail": "…"}` in English, and the row used to print
	 * that body verbatim — with a Retry button even where retrying could never help (an empty
	 * file, a full quota). Every 403 also read as "verification expired", which sent a
	 * deactivated or banned account round a captcha loop.
	 */
	function describeUploadFailure(status, body) {
		let detail = '';
		try {
			const parsed = JSON.parse(body || '');
			detail = String((parsed && (parsed.detail || parsed.error)) || '');
		} catch (e) { /* not JSON: a proxy's HTML page, or nothing at all */ }

		if (status === 413) {
			// The server names the limit it applied (the account's group may allow less than
			// the page assumed); fall back to the page's own number.
			const max = detail.match(/max\s+(\d+(?:\.\d+)?)\s*MiB/i);
			const limit = max ? Number(max[1]) * 1024 * 1024 : maxFileSize;
			return { main: t('up.too_big'), sub: t('up.max_size', { size: formatSize(limit) }), retry: false };
		}
		if (status === 400 && /empty/i.test(detail)) {
			return { main: t('up.empty_file'), sub: t('up.empty_file_sub'), retry: false };
		}
		if (status === 507) {
			return { main: t('up.no_space'), sub: t(/quota/i.test(detail) ? 'up.quota_full' : 'up.storage_full'), retry: false };
		}
		if (status === 429 && /session/i.test(detail)) return { session: true };
		if (status === 429) return { main: t('up.error'), sub: t('up.too_many'), retry: true };
		if (status === 403) {
			if (/inactive/i.test(detail)) return { main: t('up.refused'), sub: t('up.account_inactive'), retry: false };
			if (/banned/i.test(detail)) return { main: t('up.refused'), sub: t('up.banned'), retry: false };
			// The token was missing or no longer valid; an empty body is what a proxy in front
			// of the upload server says about it.
			if (detail === '' || /token|captcha/i.test(detail)) return { session: true };
			return { main: t('up.refused'), sub: '', retry: false };
		}
		if (status >= 500 || status === 0) return { main: t('up.error'), sub: t('up.try_again'), retry: true };
		return { main: t('up.refused'), sub: '', retry: false };
	}

	/** Uploads that already went round once with a fresh tracking token (no captcha mode). */
	const renewedOnce = new Set();

	/**
	 * The upload session refused the file (token gone, or its file cap reached). With a captcha
	 * the file waits for it and resumes once it is solved; without one a new tracking token is
	 * fetched and the file goes again, once.
	 */
	function retryWithNewSession(id, file, reason) {
		const entry = uploadQueue.get(id);
		clearToken();
		if (captchaEnabled) {
			if (entry) entry.status = 'waiting';
			updateFileItem(id, 'error', t(reason === 403 ? 'up.verify_expired' : 'up.session_limit'), t('up.verify_resume'), 0);
			if (!waitingForCaptcha.some(waiting => waiting.id === id)) waitingForCaptcha.push({ id, file });
			showCaptchaRequired();
			return;
		}
		if (!renewedOnce.has(id)) {
			renewedOnce.add(id);
			if (entry) entry.status = 'pending';
			updateFileItem(id, 'pending', t('up.pending'), '', 0);
			uploadFile(id, file);
			return;
		}
		if (entry) entry.status = 'error';
		updateFileItem(id, 'error-network', t('up.session_failed'), t('up.click_retry'), 0);
	}

	function sendUpload(id, file) {
		const xhr = new XMLHttpRequest();
		const random = new Uint8Array(16);
		window.crypto.getRandomValues(random);
		const clientId = Array.from(random, byte => byte.toString(16).padStart(2, '0')).join('');
		const queueEntry = {
			file,
			xhr,
			status: 'uploading',
			clientId,
			cancelledByAdmin: false
		};
		uploadQueue.set(id, queueEntry);
		updateFileItem(id, 'uploading', t('up.uploading'), '0%', 0);
		const stopPolling = () => stopStatusPolling(queueEntry);
		startStatusPolling(queueEntry);

		// Upload speed + ETA readout.
		let startTime = Date.now();
		xhr.upload.onprogress = (e) => {
			if (!e.lengthComputable) return;
			const pct = Math.round((e.loaded / e.total) * 100);
			const elapsed = (Date.now() - startTime) / 1000;
			let sub = pct + '%';
			if (elapsed > 0.3) {
				const speed = e.loaded / elapsed;
				const eta = speed > 0 ? Math.max(0, (e.total - e.loaded) / speed) : 0;
				sub = pct + '% · ' + formatSize(speed) + '/s' + (eta > 1 ? ' · ' + Math.ceil(eta) + 's' : '');
			}
			updateFileItem(id, 'uploading', t('up.uploading'), sub, pct);
		};

		xhr.onload = async () => {
			stopPolling();
			if (xhr.status >= 200 && xhr.status < 300) {
				const parts = xhr.responseText.split(':');
				const fileId = parts[0];
				const deleteToken = parts[1] || '';
				const queueItem = uploadQueue.get(id);
				if (queueItem) {
					queueItem.status = 'success';
					queueItem.serverId = fileId;
					queueItem.deleteToken = deleteToken;
				}
				updateFileItem(id, 'success', t('up.done'), t('up.uploaded'), 100);
				renewedOnce.delete(id);
				const removeButton = document.getElementById(id)?.querySelector('.remove');
				if (removeButton && deletesServerFile(id)) removeButton.title = t('up.remove_delete');
				// The session line ("Pliki: 3/5") moves with every upload, captcha or not; with a
				// spent session the next upload then starts a new one instead of being refused.
				if (uploadToken) {
					try { await updateSessionInfo(); } catch (e) { }
				}
				addResult({ id: fileId, name: file.name, url: appUrl + '/download.php?id=' + fileId, token: deleteToken });
			} else if (xhr.status === 409 && (xhr.responseText || '').indexOf('"cancelled"') >= 0) {
				// An administrator cut this transfer from the dashboard. Said plainly, because
				// "network error" would send the uploader off to check their connection.
				updateFileItem(id, 'error', t('up.cancelled_admin'), t('up.cancelled_admin_sub'), 0);
				queueEntry.status = 'error';
			} else {
				const failure = describeUploadFailure(xhr.status, xhr.responseText);
				if (failure.session) {
					retryWithNewSession(id, file, xhr.status);
					return;
				}
				queueEntry.status = 'error';
				updateFileItem(id, failure.retry ? 'error-network' : 'error', failure.main, failure.sub, 0);
			}
		};

		xhr.onerror = () => {
			stopPolling();
			if (queueEntry.cancelledByAdmin) {
				updateFileItem(id, 'error', t('up.cancelled_admin'), t('up.cancelled_admin_sub'), 0);
			} else {
				updateFileItem(id, 'error-network', t('up.net_error'), t('up.click_retry'), 0);
			}
			queueEntry.status = 'error';
		};
		xhr.onabort = () => {
			stopPolling();
			queueEntry.status = 'error';
			updateFileItem(
				id,
				'error',
				t(queueEntry.cancelledByAdmin ? 'up.cancelled_admin' : 'up.cancelled'),
				queueEntry.cancelledByAdmin ? t('up.cancelled_admin_sub') : '',
				0
			);
		};

		xhr.open('POST', uploadUrl, true);
		xhr.setRequestHeader('X-Filename', encodeURIComponent(file.name));
		xhr.setRequestHeader('Content-Type', 'application/octet-stream');
		xhr.setRequestHeader('X-Upload-ID', clientId);
		if (uploadToken) xhr.setRequestHeader('X-Upload-Token', uploadToken);
		xhr.send(file);
	}

	function addResult(f) {
		const resultUrl = safeHttpUrl(f.url)
			|| `${appUrl}/download.php?id=${encodeURIComponent(f.id || '')}`;
		f = Object.assign({}, f, { url: resultUrl });
		resultFiles.set(f.id, f);
		document.getElementById('results').classList.add('show');
		if (resultFiles.size > 1) document.getElementById('copyAll').style.display = 'block';
		updateCollectionButton();

		const list = document.getElementById('resultList');
		const item = document.createElement('div');
		item.className = 'result-item';
		item.id = 'r-' + f.id;

		const main = makeNode('div', 'result-main');
		const icon = makeNode('div', 'result-icon');
		if (isMediaName(f.name)) {
			const thumb = makeNode('img', 'result-thumb');
			thumb.src = appUrl + '/api/thumb?id=' + encodeURIComponent(f.id);
			thumb.alt = '';
			thumb.loading = 'lazy';
			thumb.addEventListener('error', () => thumb.remove(), { once: true });
			icon.appendChild(thumb);
		}
		icon.appendChild(makeFileIcon(f.name));

		const info = makeNode('div', 'result-info');
		info.append(
			makeNode('div', 'result-name', f.name),
			makeNode('div', 'result-url', f.url)
		);

		const actions = makeNode('div', 'result-actions');
		actions.appendChild(makeActionButton(
			'result-btn',
			t('up.qr_title'),
			'fa-qrcode',
			() => showQr(f.id)
		));
		if (f.token) {
			const passwordButton = makeActionButton(
				'result-btn' + (f.hasPassword ? ' has-pw' : ''),
				t('up.pw_title_set'),
				'fa-lock',
				() => showPwSet(f.id)
			);
			passwordButton.id = 'pwbtn-' + f.id;
			actions.appendChild(passwordButton);
		}
		actions.appendChild(makeActionButton(
			'result-btn',
			t('common.copy'),
			'fa-copy',
			event => copy(event, f.id)
		));
		const open = makeNode('a', 'result-btn');
		open.href = f.url;
		open.target = '_blank';
		open.rel = 'noopener noreferrer';
		open.title = t('up.open');
		open.appendChild(makeIcon('fa-up-right-from-square'));
		actions.appendChild(open);
		main.append(icon, info, actions);
		item.appendChild(main);

		if (f.token) {
			const tokenBox = makeNode('div', 'result-token');
			const tokenLabel = makeNode('div', 'token-label');
			tokenLabel.append(
				makeIcon('fa-shield-halved'),
				document.createTextNode(' ' + t('up.delete_token_label'))
			);
			const tokenRow = makeNode('div', 'token-row');
			const tokenInput = makeNode('input', 'token-input');
			tokenInput.type = 'text';
			tokenInput.value = f.token;
			tokenInput.readOnly = true;
			const tokenCopy = makeNode('button', 'token-copy', t('common.copy'));
			tokenCopy.type = 'button';
			tokenCopy.addEventListener('click', event => copyTok(event, f.id));
			tokenRow.append(tokenInput, tokenCopy);
			tokenBox.append(tokenLabel, tokenRow);
			item.appendChild(tokenBox);
		}
		list.appendChild(item);
	}

	// Small "Skopiowano" tooltip anchored above the clicked button (self-hosted positioner,
	// no external lib). Falls back to the bottom toast if we don't have a target element.
	let copyTipEl = null, copyTipTimer = null, copyTipTarget = null;

	/** Place the tip above its target; shared by the initial show and the scroll tracker. */
	function positionCopyTip(targetEl) {
		const t = targetEl.getBoundingClientRect();
		const w = copyTipEl.offsetWidth, h = copyTipEl.offsetHeight;
		let left = t.left + t.width / 2 - w / 2;
		let top = t.top - h - 8;
		left = Math.max(5, Math.min(left, window.innerWidth - w - 5));
		if (top < 5) top = t.bottom + 8; // flip below if no room above
		copyTipEl.style.left = left + 'px';
		copyTipEl.style.top = top + 'px';
	}

	function showCopiedTip(targetEl, text) {
		if (!targetEl) { showToast(text); return; }
		if (!copyTipEl) {
			copyTipEl = document.createElement('div');
			copyTipEl.className = 'copy-tip';
			copyTipEl.setAttribute('data-fh-runtime', ''); // not in the server's page (ui.js, live language switch)
			document.body.appendChild(copyTipEl);
		}
		copyTipEl.textContent = text;
		copyTipEl.classList.add('show'); // lay out first so we can measure it
		positionCopyTip(targetEl);
		copyTipTarget = targetEl;
		clearTimeout(copyTipTimer);
		copyTipTimer = setTimeout(() => {
			copyTipEl.classList.remove('show');
			copyTipTarget = null;
		}, 1500);
	}

	/**
	 * The tip is `position: fixed`, so without this it stayed put while the page scrolled out
	 * from under it. Re-anchor as the page moves, and drop it once the target leaves the
	 * viewport — a tip pointing at something off-screen says nothing.
	 */
	function trackCopyTip() {
		if (!copyTipEl || !copyTipTarget) return;
		if (!copyTipTarget.isConnected) { copyTipEl.classList.remove('show'); copyTipTarget = null; return; }
		const t = copyTipTarget.getBoundingClientRect();
		if (t.bottom < 0 || t.top > window.innerHeight || t.right < 0 || t.left > window.innerWidth) {
			copyTipEl.classList.remove('show');
			copyTipTarget = null;
			return;
		}
		positionCopyTip(copyTipTarget);
	}

	window.addEventListener('scroll', trackCopyTip, { passive: true, capture: true });
	window.addEventListener('resize', trackCopyTip, { passive: true });

	function copyBtnTarget(e) {
		if (!e) return null;
		if (e.currentTarget && e.currentTarget.getBoundingClientRect) return e.currentTarget;
		return e.target && e.target.closest ? e.target.closest('button, a') : null;
	}

	/** Copy, then say so — and only say "copied" when the clipboard really took it. */
	async function copyWithTip(target, text, doneLabel) {
		const ok = await window.FHUtil.copyText(text);
		if (ok) showCopiedTip(target, doneLabel);
		else showToast(t('download.copy_failed'));
	}

	function copy(e, id) {
		const f = resultFiles.get(id);
		if (f) copyWithTip(copyBtnTarget(e), f.url, t('common.copied'));
	}

	function copyTok(e, id) {
		const f = resultFiles.get(id);
		if (f && f.token) copyWithTip(copyBtnTarget(e), f.token, t('common.copied'));
	}

	function copyAll(e) {
		const urls = Array.from(resultFiles.values()).map(f => f.url).join('\n');
		copyWithTip(copyBtnTarget(e), urls, t('up.copied_all'));
	}

	/* ---------- C1: create a collection from the just-uploaded files (logged-in only) ---------- */
	function updateCollectionButton() {
		const btn = document.getElementById('createCollectionHome');
		if (!btn) return; // only rendered for logged-in users
		btn.style.display = (APP.loggedIn && resultFiles.size >= 2) ? 'inline-flex' : 'none';
	}

	/**
	 * pt 16: open the collection form instead of creating one immediately.
	 *
	 * The button used to POST straight away with a timestamp for a name and no sharing
	 * options, which meant the only way to name a collection or protect it was to go to the
	 * panel and edit it afterwards. It now asks the same questions the panel's modal does.
	 */
	function createCollectionHome() {
		const ids = Array.from(resultFiles.keys());
		if (ids.length < 2) return;
		document.getElementById('collCount').textContent = ids.length;

		// pt 1: a collection serves its members' bytes as a ZIP, so a password-protected member
		// needs proof of that password. For files uploaded right here the delete token we still
		// hold is that proof (see FileController::partitionProtectedFiles) — unless the install
		// turned that exemption off, in which case ask for the passwords outright.
		const locked = APP.collUploadExempt ? [] : Array.from(resultFiles.values()).filter(f => f.hasPassword);
		const lockedBox = document.getElementById('collLocked');
		lockedBox.style.display = locked.length ? '' : 'none';
		const lockedList = document.getElementById('collLockedList');
		lockedList.replaceChildren(...locked.map(f => {
			const field = makeNode('div', 'coll-field');
			const label = makeNode('label', '', f.name);
			label.title = f.name;
			const input = makeNode('input', 'pw-set-input coll-pw');
			input.type = 'password';
			input.dataset.id = f.id;
			input.placeholder = t('panel.cc.locked_ph');
			input.autocomplete = 'off';
			field.append(label, input);
			return field;
		}));

		document.getElementById('collName').value = t('home.collection_default') + ' ' + new Date().toLocaleString();
		document.getElementById('collExpiry').value = '';
		document.getElementById('collMaxDl').value = '';
		document.getElementById('collLimitAction').value = 'keep';
		document.getElementById('collOneTime').checked = false;
		document.getElementById('collPassword').value = '';
		document.getElementById('collPassword2').value = '';
		if (resetCollPw) resetCollPw();
		const err = document.getElementById('collError');
		if (err) { err.style.display = 'none'; err.textContent = ''; }
		document.getElementById('collOverlay').classList.add('show');
		setTimeout(() => document.getElementById('collName').select(), 60);
	}

	function hideCollectionForm() {
		document.getElementById('collOverlay').classList.remove('show');
	}

	/** Form submit: one in flight at a time, so a double click cannot create two collections. */
	function submitCollectionHome(ev) {
		if (ev) ev.preventDefault();
		const button = (ev && ev.submitter) || document.querySelector('#collForm button[type="submit"]');
		return window.FHUi.singleFlight('index-collection-create', createCollectionFromForm, button);
	}

	async function createCollectionFromForm() {
		const ids = Array.from(resultFiles.keys());
		if (ids.length < 2) return;

		const err = document.getElementById('collError');
		const fail = (msg) => { err.textContent = msg; err.style.display = 'block'; };
		err.style.display = 'none';

		const password = document.getElementById('collPassword').value;
		const password2 = document.getElementById('collPassword2').value;
		if (password !== '') {
			if (password.length < 8) { fail(t('up.pw_too_short')); return; }
			if (password !== password2) { fail(t('up.pw_mismatch')); return; }
		}

		// pt 1: the delete tokens of the files in this result list. They are what proves these
		// uploads are ours, so the server can admit a protected one without its password being
		// retyped — when the install allows that.
		const tokens = {};
		resultFiles.forEach((f, id) => { if (f.token) tokens[id] = f.token; });
		const passwords = {};
		document.querySelectorAll('#collLockedList .coll-pw').forEach(inp => {
			if (inp.value !== '') passwords[inp.dataset.id] = inp.value;
		});

		const body = {
			name: document.getElementById('collName').value.trim(),
			file_ids: ids,
			expiry_days: parseInt(document.getElementById('collExpiry').value) || 0,
			max_downloads: parseInt(document.getElementById('collMaxDl').value) || 0,
			one_time: document.getElementById('collOneTime').checked,
			on_limit_action: document.getElementById('collLimitAction').value,
			tokens,
			passwords
		};
		if (password !== '') body.password = password;

		try {
			const d = await FHApi.post('user_create_collection', body);
			if (d && d.success && d.url) {
				hideCollectionForm();
				showCollectionResult(d.url, d.id, d.rejected || []);
			} else {
				let msg = (d && d.error) || t('home.collection_failed');
				if (d && (d.rejected || []).length) msg += ' — ' + t('panel.cc.rejected', { names: d.rejected.join(', ') });
				fail(msg);
			}
		} catch (e) {
			fail(t('common.connection_error'));
		}
	}

	// Show a created collection's link + QR in the shared overlay, and copy the link.
	/**
	 * Result of creating a collection: QR + link, with the same set of actions the collection
	 * row in "My Files" offers (open / copy / edit), instead of a lone Close button.
	 * `Edit` hands off to the panel, which is where collection settings actually live — the
	 * `coll` parameter makes it open that collection's settings straight away.
	 */
	function showCollectionResult(url, id, rejected = []) {
		url = safeHttpUrl(url)
			|| `${appUrl}/collection.php?id=${encodeURIComponent(id || '')}`;
		const overlay = document.getElementById('qrOverlay');
		const h = overlay.querySelector('h3'), p = overlay.querySelector('p');
		if (h) h.textContent = t('home.collection_created');
		// Files left out for want of a valid password are named here rather than in a toast —
		// otherwise the link appears with no explanation of why it is short a file.
		if (p) {
			p.textContent = rejected.length
				? t('panel.cc.rejected', { names: rejected.join(', ') })
				: t('home.collection_hint_copy');
			p.classList.toggle('qr-warn', rejected.length > 0);
		}
		renderQrImage(document.getElementById('qrHolder'), url);
		document.getElementById('qrLink').textContent = url;
		qrCurrentUrl = url;

		const open = document.getElementById('qrOpenBtn');
		const copyBtn = document.getElementById('qrCopyBtn');
		const edit = document.getElementById('qrEditBtn');
		open.href = url;
		open.style.display = '';
		copyBtn.style.display = '';
		// Editing needs an account (it is a panel screen), so only offer it when signed in.
		if (id && APP.loggedIn) {
			edit.href = appUrl + '/panel.php?tab=myfiles&coll=' + encodeURIComponent(id);
			edit.style.display = '';
		} else {
			edit.style.display = 'none';
		}

		// The link goes onto the clipboard straight away, and the dialog says so only once that
		// really happened: after the awaited create call the browser may refuse (Safari, a denied
		// permission), and the hint used to claim success regardless.
		window.FHUtil.copyText(url).then(copied => {
			if (copied && p && !rejected.length && qrCurrentUrl === url) p.textContent = t('home.collection_hint');
		});
		overlay.classList.add('show');
	}

	function copyQrLink(e) {
		if (!qrCurrentUrl) return;
		copyWithTip(e ? e.currentTarget : null, qrCurrentUrl, t('home.toast_copied'));
	}

	/* ---------- QR code (rendered server-side by the Python upload server) ---------- */
	// URL currently shown in the shared QR overlay, for its Copy action.
	let qrCurrentUrl = '';

	function showQr(id) {
		const f = resultFiles.get(id);
		if (!f) return;
		const overlay = document.getElementById('qrOverlay');
		// Reset the shared overlay's heading (showCollectionResult may have changed it).
		const h = overlay.querySelector('h3'), p = overlay.querySelector('p');
		if (h) h.textContent = t('qr.title');
		if (p) { p.textContent = t('qr.subtitle'); p.classList.remove('qr-warn'); }
		const holder = document.getElementById('qrHolder');
		renderQrImage(holder, f.url);
		document.getElementById('qrLink').textContent = f.url;
		qrCurrentUrl = f.url;

		// A single file already has its own row of actions, so the overlay stays minimal here —
		// only Open and Copy make sense, and Edit belongs to collections.
		const open = document.getElementById('qrOpenBtn');
		const copyBtn = document.getElementById('qrCopyBtn');
		open.href = f.url;
		open.style.display = '';
		copyBtn.style.display = '';
		document.getElementById('qrEditBtn').style.display = 'none';

		overlay.classList.add('show');
	}
	function hideQr() { document.getElementById('qrOverlay').classList.remove('show'); }

	// Dismiss the QR overlay with Escape or a click on the backdrop (mousedown+mouseup
	// on the overlay itself, so a drag that starts inside the box doesn't close it).
	(function wireQrDismiss() {
		const qrOverlay = document.getElementById('qrOverlay');
		if (!qrOverlay) return;
		let qrDown = null;
		qrOverlay.addEventListener('mousedown', (e) => { qrDown = e.target; });
		qrOverlay.addEventListener('mouseup', (e) => {
			if (e.target === qrOverlay && qrDown === qrOverlay) hideQr();
			qrDown = null;
		});
		document.addEventListener('keydown', (e) => {
			if (e.key === 'Escape' && qrOverlay.classList.contains('show')) hideQr();
		});
	})();

	/* ---------- per-file password (set/cleared from the upload result) ---------- */
	let pwSetId = null;
	function showPwSet(id) {
		const f = resultFiles.get(id);
		if (!f || !f.token) return;
		pwSetId = id;
		document.getElementById('pwSetFileName').textContent = f.name;
		document.getElementById('pwSetTitle').textContent = f.hasPassword ? t('up.pw_title_change') : t('up.pw_title_set');
		const input = document.getElementById('pwSetInput');
		input.value = '';
		document.getElementById('pwSetInput2').value = '';
		document.getElementById('pwSetError').style.display = 'none';
		document.getElementById('pwSetClearBtn').style.display = f.hasPassword ? '' : 'none';

		// "Apply to all" only makes sense with more than one uploaded file. The clear button
		// gets the same treatment, so a password set across the batch can be lifted in one go
		// instead of file by file.
		const multi = resultFiles.size > 1;
		const applyRow = document.getElementById('pwSetApplyAllRow');
		if (applyRow) {
			applyRow.style.display = multi ? '' : 'none';
			document.getElementById('pwSetApplyAll').checked = false;
		}
		const clearAllBtn = document.getElementById('pwSetClearAllBtn');
		if (clearAllBtn) {
			// Offer it only when some other file actually has a password to remove.
			const anyOtherProtected = Array.from(resultFiles.values()).some(x => x.id !== f.id && x.hasPassword);
			clearAllBtn.style.display = (multi && (f.hasPassword || anyOtherProtected)) ? '' : 'none';
		}

		resetPwSetValidation();
		document.getElementById('pwSetOverlay').classList.add('show');
		setTimeout(() => input.focus(), 100);
	}

	/**
	 * Live strength meter + requirement checklist + "passwords match" indicator, bound to a
	 * set of element ids (mirrors account creation / the panel). Returns a reset function.
	 * Used by the per-file password overlay and the collection form.
	 */
	function wireStrengthMeter(o) {
		const el = (id) => document.getElementById(id);
		const pass = el(o.pass), confirm = el(o.confirm);
		if (!pass) return function () { };

		function updateMatch() {
			const status = el(o.match);
			if (!status || !confirm) return;
			if (!confirm.value) { status.className = 'field-status'; status.textContent = ''; confirm.classList.remove('error', 'success'); return; }
			const ok = pass.value === confirm.value;
			status.textContent = ok ? t('pwd.match_ok') : t('pwd.match_bad');
			status.className = 'field-status show ' + (ok ? 'status-ok' : 'status-bad');
			confirm.classList.toggle('success', ok);
			confirm.classList.toggle('error', !ok);
		}

		function updateStrength() {
			const val = pass.value;
			const bar = el(o.bar);
			let score = 0;
			[[o.len, val.length >= 8], [o.upper, /[A-Z]/.test(val)], [o.digit, /\d/.test(val)], [o.spec, /[^a-zA-Z0-9]/.test(val)]]
				.forEach(([id, ok]) => {
					const li = el(id);
					if (li) li.classList.toggle('valid', ok);
					if (ok) score++;
				});
			if (bar) {
				const pct = (score / 4) * 100;
				bar.style.width = pct + '%';
				bar.style.backgroundColor = pct < 25 ? 'var(--danger)' : pct < 50 ? 'var(--warning)' : pct < 75 ? '#f59e0b' : 'var(--success)';
			}
			updateMatch();
		}

		pass.addEventListener('input', updateStrength);
		if (confirm) confirm.addEventListener('input', updateMatch);

		return function reset() {
			const bar = el(o.bar);
			if (bar) bar.style.width = '0%';
			[o.len, o.upper, o.digit, o.spec].forEach(id => { const li = el(id); if (li) li.classList.remove('valid'); });
			const status = el(o.match);
			if (status) { status.className = 'field-status'; status.textContent = ''; }
			pass.classList.remove('error', 'success');
			if (confirm) confirm.classList.remove('error', 'success');
		};
	}

	let resetPwSet = null, resetCollPw = null;
	function resetPwSetValidation() { if (resetPwSet) resetPwSet(); }
	function hidePwSet() { document.getElementById('pwSetOverlay').classList.remove('show'); pwSetId = null; }

	function pwSetError(text) {
		const err = document.getElementById('pwSetError');
		err.textContent = text;
		err.style.display = 'block';
	}

	function markPwBtn(id, on) {
		const btn = document.getElementById('pwbtn-' + id);
		if (!btn) return;
		btn.classList.toggle('has-pw', on);
		btn.title = on ? t('up.pw_title_change') : t('up.pw_title_set');
	}

	/**
	 * Apply a password change to a set of uploaded files.
	 *
	 * Each file is authorised by its own delete token, so a batch is just N independent calls —
	 * there is no server-side "set for all", and inventing one would mean trusting a single
	 * token for files it does not own. Returns how many succeeded.
	 */
	async function pwApplyToFiles(fileIds, body) {
		let ok = 0;
		for (const id of fileIds) {
			const f = resultFiles.get(id);
			if (!f || !f.token) continue;
			try {
				const data = await FHApi.post('set_file_password', Object.assign({ id: f.id, token: f.token }, body));
				if (data && data.success) {
					f.hasPassword = !body.clear;
					markPwBtn(id, !body.clear);
					ok++;
				}
			} catch (err) { /* keep going; the returned count reports the shortfall */ }
		}
		return ok;
	}

	function submitPwSet(e) {
		if (e) e.preventDefault();
		const button = (e && e.submitter) || document.querySelector('#pwSetForm button[type="submit"]');
		return window.FHUi.singleFlight('index-pw-set', applyPasswordFromForm, button);
	}

	async function applyPasswordFromForm() {
		const pw = document.getElementById('pwSetInput').value;
		const pw2 = document.getElementById('pwSetInput2').value;
		if (!pw) { pwSetError(t('up.pw_required')); return; }
		if (pw.length < 8) { pwSetError(t('up.pw_too_short')); return; }
		if (pw !== pw2) { pwSetError(t('up.pw_mismatch')); return; }
		const id = pwSetId;
		const applyAll = !!document.getElementById('pwSetApplyAll')?.checked;
		const anchor = document.getElementById('pwbtn-' + id);
		try {
			const targets = applyAll ? Array.from(resultFiles.keys()) : [id];
			const ok = await pwApplyToFiles(targets, { password: pw });
			if (ok > 0) {
				hidePwSet();
				// Anchored over the padlock that opened this, like every other tip on the page.
				showCopiedTip(anchor, applyAll ? t('up.pw_set_all_ok', { n: ok }) : t('up.pw_set_ok'));
			} else {
				pwSetError(t('up.pw_set_fail'));
			}
		} catch (err) { pwSetError(t('common.connection_error')); }
	}

	async function clearPwSet() {
		const anchor = document.getElementById('pwbtn-' + pwSetId);
		try {
			const ok = await pwApplyToFiles([pwSetId], { clear: true });
			if (ok > 0) {
				hidePwSet();
				showCopiedTip(anchor, t('up.pw_removed'));
			} else {
				pwSetError(t('up.pw_clear_fail'));
			}
		} catch (err) { pwSetError(t('common.connection_error')); }
	}

	/** Remove the password from every uploaded file that currently has one. */
	async function clearPwSetAll() {
		const anchor = document.getElementById('pwbtn-' + pwSetId);
		const targets = Array.from(resultFiles.entries()).filter(([, f]) => f.hasPassword).map(([id]) => id);
		if (!targets.length) { hidePwSet(); return; }
		try {
			const ok = await pwApplyToFiles(targets, { clear: true });
			if (ok > 0) {
				hidePwSet();
				showCopiedTip(anchor, t('up.pw_removed_all', { n: ok }));
			} else {
				pwSetError(t('up.pw_clear_fail'));
			}
		} catch (err) { pwSetError(t('common.connection_error')); }
	}

	// Dismiss an overlay with Escape or a backdrop click, and wire its strength meter.
	(function wireOverlays() {
		const dismissable = (id, hide) => {
			const ov = document.getElementById(id);
			if (!ov) return;
			let d = null;
			ov.addEventListener('mousedown', (e) => { d = e.target; });
			ov.addEventListener('mouseup', (e) => { if (e.target === ov && d === ov) hide(); d = null; });
			document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && ov.classList.contains('show')) hide(); });
		};
		dismissable('pwSetOverlay', hidePwSet);
		dismissable('collOverlay', hideCollectionForm);

		resetPwSet = wireStrengthMeter({
			pass: 'pwSetInput', bar: 'pwSetBar', len: 'pwSetReqLen', upper: 'pwSetReqUpper',
			digit: 'pwSetReqDigit', spec: 'pwSetReqSpec', confirm: 'pwSetInput2', match: 'pwSetMatch'
		});
		resetCollPw = wireStrengthMeter({
			pass: 'collPassword', bar: 'collPwBar', len: 'collReqLen', upper: 'collReqUpper',
			digit: 'collReqDigit', spec: 'collReqSpec', confirm: 'collPassword2', match: 'collMatch'
		});
	})();

	function wireStaticActions() {
		const click = (id, handler) => {
			const element = document.getElementById(id);
			if (element) element.addEventListener('click', handler);
		};
		click('homeAuthOpen', () => {
			if (typeof window.showAuthModal === 'function') window.showAuthModal();
		});
		click('createCollectionHome', createCollectionHome);
		click('copyAll', copyAll);
		click('qrCopyBtn', copyQrLink);
		click('qrCloseBtn', hideQr);
		click('pwSetCancelBtn', hidePwSet);
		click('pwSetClearBtn', clearPwSet);
		click('pwSetClearAllBtn', clearPwSetAll);
		click('collCancelBtn', hideCollectionForm);

		const passwordForm = document.getElementById('pwSetForm');
		if (passwordForm) passwordForm.addEventListener('submit', submitPwSet);
		const collectionForm = document.getElementById('collForm');
		if (collectionForm) collectionForm.addEventListener('submit', submitCollectionHome);
	}

	/* ---------- init ---------- */
	wireStaticActions();
	// Resolves once the config and (captcha-less) upload token have arrived; uploadFile() waits on it.
	const sessionReady = initCaptcha();
	if (typeof updateAuthUI === 'function') updateAuthUI();

	// Cross-script hook: auth.js re-applies the per-account limits after a sign-in or sign-out.
	window.updateLimits = updateLimits;
})();
