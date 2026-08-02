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
	// legacy localStorage copy from older builds.
	if (localStorage.getItem('uploadToken')) {
		try {
			if (!sessionStorage.getItem('uploadToken')) {
				sessionStorage.setItem('uploadToken', localStorage.getItem('uploadToken'));
				sessionStorage.setItem('uploadTokenExpiry', localStorage.getItem('uploadTokenExpiry') || '0');
			}
		} catch (e) { }
		localStorage.removeItem('uploadToken');
		localStorage.removeItem('uploadTokenExpiry');
	}
	let uploadToken = sessionStorage.getItem('uploadToken') || null;
	let uploadTokenExpiry = parseInt(sessionStorage.getItem('uploadTokenExpiry') || '0');
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
		image.alt = 'QR';
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

	function toggleTheme() {
		document.body.classList.toggle('light');
		const isLight = document.body.classList.contains('light');
		localStorage.setItem('theme', isLight ? 'light' : 'dark');
		document.cookie = 'theme=' + (isLight ? 'light' : 'dark') + '; path=/; max-age=31536000';
	}

	if (localStorage.getItem('theme') === 'light' && !document.body.classList.contains('light')) {
		document.body.classList.add('light');
	}

	function isTokenValid() {
		return uploadToken && uploadTokenExpiry > Date.now();
	}

	function persistToken(token) {
		uploadToken = token;
		uploadTokenExpiry = Date.now() + captchaTokenLifetimeMs;
		sessionStorage.setItem('uploadToken', uploadToken);
		sessionStorage.setItem('uploadTokenExpiry', uploadTokenExpiry.toString());
	}

	function clearToken() {
		uploadToken = null;
		sessionStorage.removeItem('uploadToken');
		sessionStorage.removeItem('uploadTokenExpiry');
	}

	/* ---------- captcha / upload session ---------- */
	async function initCaptcha() {
		try {
			const data = await FHApi.get('config');
			captchaEnabled = data.recaptcha_enabled;
			captchaSiteKey = data.recaptcha_site_key;
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
				if (!isTokenValid()) {
					try {
						const tData = await FHApi.post('verify_captcha', {});
						if (tData.success && tData.token) persistToken(tData.token);
					} catch (e) { console.error('Auto-token fetch failed', e); }
				}
				await updateSessionInfo();
			}
		} catch (e) {
			console.log('reCAPTCHA init failed:', e);
		}
	}

	function loadRecaptchaScript() {
		if (document.querySelector('script[src^="https://www.google.com/recaptcha/api.js"]')) {
			if (typeof grecaptcha !== 'undefined' && grecaptcha.render) onCaptchaLoad();
			return;
		}
		const script = document.createElement('script');
		script.src = 'https://www.google.com/recaptcha/api.js?onload=onCaptchaLoad&render=explicit';
		script.async = true;
		document.head.appendChild(script);
	}

	function onCaptchaLoad() {
		document.getElementById('captchaLoading').style.display = 'none';
		if (captchaWidgetId !== null) {
			try {
				grecaptcha.reset(captchaWidgetId);
				return;
			} catch (e) {
				captchaWidgetId = null;
			}
		}
		try {
			document.getElementById('captchaWidget').replaceChildren();
			captchaWidgetId = grecaptcha.render('captchaWidget', {
				sitekey: captchaSiteKey,
				theme: 'dark',
				callback: onCaptchaSuccess,
				'error-callback': onCaptchaError
			});
		} catch (e) {
			console.error('reCAPTCHA render error:', e);
		}
	}

	async function onCaptchaSuccess(response) {
		document.getElementById('captchaError').style.display = 'none';
		try {
			const data = await FHApi.post('verify_captcha', { captcha_response: response });
			if (data.success && data.token) {
				persistToken(data.token);
				document.getElementById('captchaOverlay').classList.remove('show');
				await updateSessionInfo();
			} else {
				onCaptchaError();
			}
		} catch (e) {
			onCaptchaError();
		}
	}

	function onCaptchaError() {
		document.getElementById('captchaError').style.display = 'block';
		if (captchaWidgetId !== null) grecaptcha.reset(captchaWidgetId);
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
			} else {
				showCaptchaRequired();
			}
		} catch (e) {
			console.log('Token info error:', e);
		}
	}

	function showCaptchaRequired() {
		clearToken();
		const loading = document.getElementById('captchaLoading');
		if (loading) loading.style.display = 'none';
		document.getElementById('captchaError').style.display = 'none';

		if (captchaWidgetId !== null) {
			try { grecaptcha.reset(captchaWidgetId); } catch (e) { captchaWidgetId = null; }
		}
		if (captchaWidgetId === null && captchaEnabled && captchaSiteKey) {
			if (typeof grecaptcha !== 'undefined' && grecaptcha.render) {
				onCaptchaLoad();
			} else if (!document.querySelector('script[src^="https://www.google.com/recaptcha/api.js"]')) {
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
		maxFiles = user ? userLimitFiles : guestLimitFiles;
		window.maxFileSize = maxFileSize;
		window.maxFiles = maxFiles;

		const sizeText = maxFileSizeMB >= 1024 ? (maxFileSizeMB / 1024).toFixed(2) + ' GiB' : maxFileSizeMB + ' MiB';
		document.querySelectorAll('#limitSize').forEach(el => el.textContent = sizeText);
		document.querySelectorAll('#limitCount').forEach(el => el.textContent = maxFiles);
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
			if (file.size > maxFileSize) {
				addFileItem(id, file.name, file.size, 'error', t('up.too_big'), 'Max ' + formatSize(maxFileSize));
				return;
			}
			if (uploadQueue.size >= maxFiles) {
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
			t('up.remove'),
			'fa-xmark',
			() => removeFile(id)
		));
		actions.replaceChildren(...buttons);
	}

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

	function removeFile(id) {
		const item = document.getElementById(id);
		if (!item) return;
		const queueItem = uploadQueue.get(id);
		if (queueItem && queueItem.xhr) queueItem.xhr.abort();

		if (queueItem && queueItem.status === 'success' && queueItem.serverId && queueItem.deleteToken) {
			const formData = new FormData();
			formData.append('id', queueItem.serverId);
			formData.append('token', queueItem.deleteToken);
			FHApi.postForm('delete', formData)
				.then(d => {
					if (d.success) {
						resultFiles.delete(queueItem.serverId);
						document.getElementById('r-' + queueItem.serverId)?.remove();
						if (resultFiles.size === 0) document.getElementById('results').classList.remove('show');
						if (resultFiles.size <= 1) document.getElementById('copyAll').style.display = 'none';
						updateCollectionButton();
					}
				})
				.catch(() => { });
		}
		uploadQueue.delete(id);
		item.classList.add('removing');
		setTimeout(() => item.remove(), 300);
	}

	function retryUpload(id) {
		const queueItem = uploadQueue.get(id);
		if (!queueItem || !queueItem.file) return;
		updateFileItem(id, 'pending', t('up.pending'), '', 0);
		uploadFile(id, queueItem.file);
	}

	function uploadFile(id, file) {
		if (captchaEnabled) {
			if (!isTokenValid() || (filesLimit > 0 && filesUploaded >= filesLimit)) {
				showCaptchaRequired();
				return;
			}
		}

		const xhr = new XMLHttpRequest();
		const random = new Uint8Array(16);
		window.crypto.getRandomValues(random);
		const clientId = Array.from(random, byte => byte.toString(16).padStart(2, '0')).join('');
		const queueEntry = {
			file,
			xhr,
			status: 'uploading',
			clientId,
			cancelledByAdmin: false,
			statusTimer: null
		};
		uploadQueue.set(id, queueEntry);
		updateFileItem(id, 'uploading', t('up.uploading'), '0%', 0);
		const stopStatusPolling = () => {
			if (queueEntry.statusTimer) {
				clearInterval(queueEntry.statusTimer);
				queueEntry.statusTimer = null;
			}
		};
		const checkAdminCancellation = async () => {
			if (queueEntry.status !== 'uploading') return;
			try {
				const status = await FHApi.get('upload_status', { id: clientId });
				if (status.success && status.status === 'cancelled') {
					queueEntry.cancelledByAdmin = true;
					xhr.abort();
				}
			} catch (e) { /* a transient status check must not fail the upload */ }
		};
		queueEntry.statusTimer = setInterval(checkAdminCancellation, 750);

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
			stopStatusPolling();
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
				if (captchaEnabled && uploadToken) {
					try { await updateSessionInfo(); } catch (e) { }
				}
				addResult({ id: fileId, name: file.name, url: appUrl + '/download.php?id=' + fileId, token: deleteToken });
			} else if (xhr.status === 403) {
				updateFileItem(id, 'error', t('up.verify_expired'), t('up.try_again'), 0);
				uploadQueue.delete(id);
				showCaptchaRequired();
			} else if (xhr.status === 409 && (xhr.responseText || '').indexOf('"cancelled"') >= 0) {
				// An administrator cut this transfer from the dashboard. Said plainly, because
				// "network error" would send the uploader off to check their connection.
				updateFileItem(id, 'error', t('up.cancelled_admin'), t('up.cancelled_admin_sub'), 0);
				uploadQueue.get(id).status = 'error';
			} else {
				// The server's own errors are short strings worth showing. A proxy error is a
				// whole HTML page — printing it verbatim put "<!DOCTYPE HTML PUBLIC…" in the
				// file row, which tells the uploader nothing.
				const body = (xhr.responseText || '').trim();
				const detail = (body === '' || body.charAt(0) === '<') ? t('up.try_again') : body;
				updateFileItem(id, 'error-network', t('up.error'), detail, 0);
				uploadQueue.get(id).status = 'error';
			}
		};

		xhr.onerror = () => {
			stopStatusPolling();
			if (queueEntry.cancelledByAdmin) {
				updateFileItem(id, 'error', t('up.cancelled_admin'), t('up.cancelled_admin_sub'), 0);
			} else {
				updateFileItem(id, 'error-network', t('up.net_error'), t('up.click_retry'), 0);
			}
			queueEntry.status = 'error';
		};
		xhr.onabort = () => {
			stopStatusPolling();
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

	function copy(e, id) {
		const f = resultFiles.get(id);
		if (f) {
			navigator.clipboard.writeText(f.url);
			showCopiedTip(copyBtnTarget(e), t('common.copied'));
		}
	}

	function copyTok(e, id) {
		const f = resultFiles.get(id);
		if (f && f.token) {
			navigator.clipboard.writeText(f.token);
			showCopiedTip(copyBtnTarget(e), t('common.copied'));
		}
	}

	function copyAll(e) {
		const urls = Array.from(resultFiles.values()).map(f => f.url).join('\n');
		navigator.clipboard.writeText(urls);
		showCopiedTip(copyBtnTarget(e), t('up.copied_all'));
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

	async function submitCollectionHome(ev) {
		if (ev) ev.preventDefault();
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
				: t('home.collection_hint');
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

		navigator.clipboard.writeText(url).catch(() => { });
		overlay.classList.add('show');
	}

	function copyQrLink(e) {
		if (!qrCurrentUrl) return;
		navigator.clipboard.writeText(qrCurrentUrl).catch(() => { });
		showCopiedTip(e ? e.currentTarget : null, t('home.toast_copied'));
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

	async function pwSetRequest(body) {
		const f = resultFiles.get(pwSetId);
		if (!f) return null;
		body.id = f.id;
		body.token = f.token;
		return FHApi.post('set_file_password', body);
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

	async function submitPwSet(e) {
		if (e) e.preventDefault();
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

	function goToMyFiles() { if (typeof currentUser !== 'undefined' && currentUser) window.location.href = 'panel.php?tab=myfiles'; }
	function goToAdmin() { if (typeof currentUser !== 'undefined' && currentUser && currentUser.is_admin) window.location.href = 'panel.php'; }

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
	initCaptcha();
	if (typeof updateAuthUI === 'function') updateAuthUI();

	// Cross-script hooks used by auth_scripts.php, the shared header and reCAPTCHA.
	Object.assign(window, {
		onCaptchaLoad, updateLimits, toggleTheme, showToast, goToMyFiles, goToAdmin
	});
})();
