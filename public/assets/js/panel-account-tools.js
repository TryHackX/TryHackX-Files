(function () {
	'use strict';

	const bootstrap = document.getElementById('panelBootstrap');
	let panel = {};
	try {
		panel = JSON.parse(bootstrap?.dataset.config || '{}');
	} catch {
		panel = {};
	}

	const t = (key, params) => window.t(key, params);
	const formatDate = window.FHUtil.formatDate;
	const api = () => window.FHApi;
	const showModal = (id) => window.showModal(id);
	const closeModal = (id) => window.closeModal(id);
	const notify = (message, type = 'success', anchor = null) =>
		window.showNotification(message, type, anchor);

	let apiKeys = [];
	let lastCreatedKey = null;
	let webhooks = [];
	let lastRecoveryCodes = [];
	let qrObjectUrl = null;

	function flashMessage(id, text, type, timeout = 3000) {
		const element = document.getElementById(id);
		if (!element) return;
		element.textContent = String(text || '');
		element.className = 'auth-message ' + (type || '');
		if (timeout > 0) {
			setTimeout(() => {
				element.textContent = '';
				element.className = 'auth-message';
			}, timeout);
		}
	}

	function emptyRow(columns, message) {
		const row = document.createElement('tr');
		const cell = document.createElement('td');
		cell.colSpan = columns;
		cell.className = 'empty';
		cell.textContent = String(message || '');
		row.append(cell);
		return row;
	}

	function textCell(value) {
		const cell = document.createElement('td');
		cell.textContent = String(value ?? '');
		return cell;
	}

	function mutedText(value) {
		const span = document.createElement('span');
		span.style.color = 'var(--text-muted)';
		span.textContent = String(value ?? '');
		return span;
	}

	function actionButton(action, title, data = {}) {
		const button = document.createElement('button');
		button.type = 'button';
		button.className = 'action-btn del';
		button.title = String(title || '');
		button.setAttribute('data-fh-click', action + '(this)');
		for (const [key, value] of Object.entries(data)) {
			button.dataset[key] = String(value ?? '');
		}
		const icon = document.createElement('i');
		icon.className = 'fa-solid fa-trash';
		icon.setAttribute('aria-hidden', 'true');
		button.append(icon);
		return button;
	}

	function actionCell(button) {
		const cell = document.createElement('td');
		const actions = document.createElement('div');
		actions.className = 'actions';
		actions.append(button);
		cell.append(actions);
		return cell;
	}

	function openCreateApiKey() {
		document.getElementById('akLabel').value = '';
		document.getElementById('akFormView').style.display = '';
		document.getElementById('akResultView').style.display = 'none';
		const message = document.getElementById('createApiKeyMessage');
		if (message) {
			message.textContent = '';
			message.className = 'auth-message';
		}
		lastCreatedKey = null;
		showModal('createApiKeyModal');
		setTimeout(() => document.getElementById('akLabel')?.focus(), 60);
	}

	async function submitCreateApiKey() {
		const label = document.getElementById('akLabel').value.trim();
		try {
			const result = await api().post('user_create_api_key', { label });
			if (!result.success) {
				flashMessage(
					'createApiKeyMessage',
					result.error || t('panel.apikey.create_failed'),
					'error'
				);
				return;
			}
			lastCreatedKey = {
				key: result.key,
				endpoint: result.endpoint,
				urlTemplate: result.url_template,
				embedTemplate: result.embed_template,
				deleteTemplate: result.delete_template,
				label: result.label || ''
			};
			document.getElementById('akKey').value = result.key;
			document.getElementById('akFormView').style.display = 'none';
			document.getElementById('akResultView').style.display = '';
			await loadApiKeys();
		} catch {
			flashMessage('createApiKeyMessage', t('common.connection_error'), 'error');
		}
	}

	function copyApiKey() {
		const input = document.getElementById('akKey');
		input.select();
		navigator.clipboard.writeText(input.value).then(
			() => notify(t('panel.apikey.copied')),
			() => notify(t('panel.coll.copy_failed'), 'error')
		);
	}

	function downloadSharexConfig() {
		if (!lastCreatedKey) return;
		let host = window.location.host || 'local';
		try {
			host = new URL(lastCreatedKey.endpoint).host;
		} catch {
			// Keep the neutral filename when the server returned an invalid endpoint.
		}
		const config = {
			Version: '14.1.0',
			Name: 'TryHackX Files — ' + host,
			DestinationType: 'ImageUploader, TextUploader, FileUploader',
			RequestMethod: 'POST',
			RequestURL: lastCreatedKey.endpoint,
			Headers: {
				Authorization: 'Bearer ' + lastCreatedKey.key,
				'X-Filename': '{filename}'
			},
			Body: 'Binary',
			URL: lastCreatedKey.urlTemplate + '$json:id$',
			ThumbnailURL: lastCreatedKey.embedTemplate + '$json:id$',
			DeletionURL: lastCreatedKey.deleteTemplate
				+ '$json:id$&token=$json:delete_token$',
			ErrorMessage: '$json:detail$'
		};
		downloadBlob(
			new Blob([JSON.stringify(config, null, 2)], { type: 'application/json' }),
			'tryhackx-files-' + host.replace(/[^a-z0-9.-]/gi, '_') + '.sxcu'
		);
		notify(t('panel.apikey.sharex_downloaded'));
	}

	function downloadBlob(blob, filename) {
		const link = document.createElement('a');
		const objectUrl = URL.createObjectURL(blob);
		link.href = objectUrl;
		link.download = filename;
		document.body.append(link);
		link.click();
		link.remove();
		setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
	}

	async function loadApiKeys() {
		const body = document.getElementById('apiKeysBody');
		if (!body) return;
		try {
			const result = await api().get('user_api_keys');
			apiKeys = result.success && Array.isArray(result.keys) ? result.keys : [];
		} catch {
			apiKeys = [];
		}
		renderApiKeys();
	}

	function renderApiKeys() {
		const body = document.getElementById('apiKeysBody');
		if (!body) return;
		if (!apiKeys.length) {
			body.replaceChildren(emptyRow(5, t('panel.apikey.none')));
			return;
		}

		const rows = apiKeys.map((key) => {
			const row = document.createElement('tr');
			const label = document.createElement('td');
			label.append(key.label ? document.createTextNode(key.label) : mutedText(
				t('panel.apikey.no_label')
			));

			const prefix = document.createElement('td');
			const code = document.createElement('code');
			code.style.fontSize = '.8rem';
			code.textContent = String(key.prefix || '') + '…';
			prefix.append(code);

			const lastUsed = document.createElement('td');
			lastUsed.append(key.lastUsedAt
				? document.createTextNode(formatDate(key.lastUsedAt))
				: mutedText(t('panel.apikey.never')));

			row.append(
				label,
				prefix,
				textCell(formatDate(key.createdAt)),
				lastUsed,
				actionCell(actionButton(
					'askRevokeApiKey',
					t('panel.apikey.revoke_tooltip'),
					{
						keyId: key.id,
						keyLabel: key.label || String(key.prefix || '') + '…'
					}
				))
			);
			return row;
		});
		body.replaceChildren(...rows);
	}

	function askRevokeApiKey(source, label = '') {
		const id = source instanceof Element ? source.dataset.keyId : source;
		const shownLabel = source instanceof Element ? source.dataset.keyLabel : label;
		document.getElementById('revokeKeyId').value = String(id || '');
		document.getElementById('revokeKeyLabel').textContent = String(shownLabel || '');
		showModal('revokeApiKeyModal');
	}

	async function confirmRevokeApiKey() {
		const id = Number.parseInt(document.getElementById('revokeKeyId').value, 10) || 0;
		try {
			const result = await api().post('user_revoke_api_key', { id });
			closeModal('revokeApiKeyModal');
			if (result.success) {
				notify(t('panel.apikey.revoked'));
				await loadApiKeys();
			} else {
				notify(result.error || t('panel.apikey.revoke_failed'), 'error');
			}
		} catch {
			closeModal('revokeApiKeyModal');
			notify(t('common.connection_error'), 'error');
		}
	}

	function openCreateWebhook() {
		document.getElementById('whUrl').value = '';
		document.getElementById('whEvUpload').checked = true;
		document.getElementById('whEvDownload').checked = true;
		document.getElementById('whEvDelete').checked = true;
		document.getElementById('whFormView').style.display = '';
		document.getElementById('whResultView').style.display = 'none';
		const message = document.getElementById('createWebhookMessage');
		if (message) {
			message.textContent = '';
			message.className = 'auth-message';
		}
		showModal('createWebhookModal');
		setTimeout(() => document.getElementById('whUrl')?.focus(), 60);
	}

	async function submitCreateWebhook() {
		const url = document.getElementById('whUrl').value.trim();
		const events = [];
		if (document.getElementById('whEvUpload').checked) events.push('upload');
		if (document.getElementById('whEvDownload').checked) events.push('download');
		if (document.getElementById('whEvDelete').checked) events.push('delete');
		if (!url) {
			flashMessage('createWebhookMessage', t('panel.wh.need_url'), 'error');
			return;
		}
		if (!events.length) {
			flashMessage('createWebhookMessage', t('panel.wh.need_event'), 'error');
			return;
		}

		try {
			const result = await api().post('user_create_webhook', { url, events });
			if (!result.success) {
				flashMessage(
					'createWebhookMessage',
					result.error || t('panel.wh.create_failed'),
					'error'
				);
				return;
			}
			document.getElementById('whSecret').value = result.secret;
			document.getElementById('whFormView').style.display = 'none';
			document.getElementById('whResultView').style.display = '';
			await loadWebhooks();
		} catch {
			flashMessage('createWebhookMessage', t('common.connection_error'), 'error');
		}
	}

	function copyWebhookSecret() {
		const input = document.getElementById('whSecret');
		input.select();
		navigator.clipboard.writeText(input.value).then(
			() => notify(t('panel.wh.copied')),
			() => notify(t('panel.coll.copy_failed'), 'error')
		);
	}

	async function loadWebhooks() {
		const body = document.getElementById('webhooksBody');
		if (!body) return;
		try {
			const result = await api().get('user_webhooks');
			webhooks = result.success && Array.isArray(result.webhooks)
				? result.webhooks
				: [];
		} catch {
			webhooks = [];
		}
		renderWebhooks();
	}

	function renderWebhooks() {
		const body = document.getElementById('webhooksBody');
		if (!body) return;
		if (!webhooks.length) {
			body.replaceChildren(emptyRow(5, t('panel.wh.none')));
			return;
		}

		const rows = webhooks.map((webhook) => {
			const row = document.createElement('tr');
			const urlCell = document.createElement('td');
			urlCell.style.maxWidth = '280px';
			urlCell.style.overflow = 'hidden';
			urlCell.style.textOverflow = 'ellipsis';
			urlCell.style.whiteSpace = 'nowrap';
			const urlCode = document.createElement('code');
			urlCode.style.fontSize = '.8rem';
			urlCode.title = String(webhook.url || '');
			urlCode.textContent = String(webhook.url || '');
			urlCell.append(urlCode);

			const eventsCell = document.createElement('td');
			for (const eventName of Array.isArray(webhook.events) ? webhook.events : []) {
				const badge = document.createElement('span');
				badge.className = 'file-badge';
				badge.textContent = String(eventName);
				eventsCell.append(badge, document.createTextNode(' '));
			}

			const lastDelivery = document.createElement('td');
			lastDelivery.append(webhook.lastDeliveryAt
				? document.createTextNode(formatDate(webhook.lastDeliveryAt))
				: mutedText(t('panel.wh.never')));
			const status = document.createElement('td');
			status.append(webhook.lastStatus
				? document.createTextNode(String(webhook.lastStatus))
				: mutedText('—'));

			row.append(
				urlCell,
				eventsCell,
				lastDelivery,
				status,
				actionCell(actionButton(
					'askDeleteWebhook',
					t('panel.wh.delete_tooltip'),
					{ webhookId: webhook.id }
				))
			);
			return row;
		});
		body.replaceChildren(...rows);
	}

	function askDeleteWebhook(source) {
		const id = source instanceof Element ? source.dataset.webhookId : source;
		const webhook = webhooks.find((item) => String(item.id) === String(id));
		document.getElementById('delWebhookId').value = String(id || '');
		document.getElementById('delWebhookUrl').textContent = webhook
			? String(webhook.url || '')
			: '#' + id;
		showModal('deleteWebhookModal');
	}

	async function confirmDeleteWebhook() {
		const id = Number.parseInt(document.getElementById('delWebhookId').value, 10) || 0;
		try {
			const result = await api().post('user_delete_webhook', { id });
			closeModal('deleteWebhookModal');
			if (result.success) {
				notify(t('panel.wh.deleted'));
				await loadWebhooks();
			} else {
				notify(result.error || t('panel.wh.delete_failed'), 'error');
			}
		} catch {
			closeModal('deleteWebhookModal');
			notify(t('common.connection_error'), 'error');
		}
	}

	function show2faState(state) {
		const states = {
			loading: 'twofaLoading',
			off: 'twofaOff',
			enroll: 'twofaEnroll',
			on: 'twofaOn'
		};
		for (const [key, id] of Object.entries(states)) {
			const element = document.getElementById(id);
			if (element) element.style.display = key === state ? '' : 'none';
		}
	}

	async function load2faStatus() {
		const loading = document.getElementById('twofaLoading');
		if (!loading) return;
		show2faState('loading');
		try {
			const result = await api().get('user_2fa_status');
			show2faState(result.success && result.enabled ? 'on' : 'off');
			const count = document.getElementById('twofaRcCount');
			if (count && result.success && result.enabled) {
				const remaining = Number(result.recovery_remaining) || 0;
				count.textContent = t('panel.2fa.rc_left', { n: remaining });
				count.classList.toggle('recovery-low', remaining <= 2);
			}
		} catch {
			loading.textContent = t('panel.2fa.status_failed');
		}
	}

	function clearQrObjectUrl() {
		if (!qrObjectUrl) return;
		URL.revokeObjectURL(qrObjectUrl);
		qrObjectUrl = null;
	}

	async function start2faSetup(button = null) {
		const passwordInput = document.getElementById('twofaSetupPass');
		const password = passwordInput ? passwordInput.value : '';
		if (!password) {
			notify(t('panel.2fa.need_pw'), 'error', button);
			return;
		}
		if (button) button.disabled = true;

		try {
			const result = await api().post('user_2fa_setup', { password });
			if (passwordInput) passwordInput.value = '';
			if (!result.success) {
				notify(result.error || t('panel.2fa.setup_failed'), 'error', button);
				return;
			}

			show2faState('enroll');
			document.getElementById('twofaCode').value = '';
			const message = document.getElementById('twofaEnrollMsg');
			if (message) {
				message.textContent = '';
				message.className = 'auth-message';
			}
			const qr = document.getElementById('twofaQr');
			clearQrObjectUrl();
			qr.textContent = t('panel.2fa.generating');
			document.getElementById('twofaSecret').textContent = result.secret;

			try {
				const response = await fetch(`${panel.appUrl}/api/qr`, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({ data: result.uri, scale: 5 })
				});
				if (!response.ok) throw new Error('QR request failed.');
				const svgBlob = await response.blob();
				qrObjectUrl = URL.createObjectURL(svgBlob);
				const image = document.createElement('img');
				image.src = qrObjectUrl;
				image.alt = 'QR';
				qr.replaceChildren(image);
				document.getElementById('twofaCode').focus();
			} catch {
				clearQrObjectUrl();
				qr.textContent = t('panel.2fa.qr_failed');
			}
		} catch {
			notify(t('common.connection_error'), 'error', button);
		} finally {
			if (button) button.disabled = false;
		}
	}

	function cancel2faSetup() {
		clearQrObjectUrl();
		void load2faStatus();
	}

	async function confirm2faSetup(button = null) {
		const code = document.getElementById('twofaCode').value.trim();
		try {
			const result = await api().post('user_2fa_confirm', { code });
			if (!result.success) {
				flashMessage(
					'twofaEnrollMsg',
					result.error || t('panel.2fa.bad_code'),
					'error'
				);
				return;
			}
			clearQrObjectUrl();
			notify(t('panel.2fa.enabled'), 'success', button);
			show2faState('on');
			if (Array.isArray(result.recovery_codes) && result.recovery_codes.length) {
				showRecoveryCodes(result.recovery_codes);
			}
			await load2faStatus();
		} catch {
			flashMessage('twofaEnrollMsg', t('common.connection_error'), 'error');
		}
	}

	function showRecoveryCodes(codes) {
		lastRecoveryCodes = Array.isArray(codes) ? codes.map(String) : [];
		const holder = document.getElementById('rcCodes');
		holder.replaceChildren(...lastRecoveryCodes.map((codeValue) => {
			const code = document.createElement('code');
			code.textContent = codeValue;
			return code;
		}));
		document.getElementById('rcConfirmView').style.display = 'none';
		document.getElementById('rcListView').style.display = '';
		const message = document.getElementById('recoveryCodesMessage');
		if (message) {
			message.textContent = '';
			message.className = 'auth-message';
		}
		showModal('recoveryCodesModal');
	}

	function openRecoveryCodes() {
		document.getElementById('rcPassword').value = '';
		document.getElementById('rcConfirmView').style.display = '';
		document.getElementById('rcListView').style.display = 'none';
		const message = document.getElementById('recoveryCodesMessage');
		if (message) {
			message.textContent = '';
			message.className = 'auth-message';
		}
		showModal('recoveryCodesModal');
	}

	async function submitRecoveryCodes() {
		const password = document.getElementById('rcPassword').value;
		if (!password) {
			flashMessage('recoveryCodesMessage', t('panel.2fa.need_password'), 'error');
			return;
		}
		try {
			const result = await api().post('user_2fa_recovery_codes', { password });
			if (result.success) {
				showRecoveryCodes(result.recovery_codes || []);
			} else {
				flashMessage(
					'recoveryCodesMessage',
					result.error || t('common.error'),
					'error'
				);
			}
		} catch {
			flashMessage('recoveryCodesMessage', t('common.connection_error'), 'error');
		}
	}

	function copyRecoveryCodes() {
		navigator.clipboard.writeText(lastRecoveryCodes.join('\n')).then(
			() => notify(t('common.copied')),
			() => notify(t('panel.coll.copy_failed'), 'error')
		);
	}

	function downloadRecoveryCodes() {
		const header = `${panel.host} — ${t('panel.2fa.rc_title')}\n`
			+ `${new Date().toISOString().slice(0, 10)}\n\n`;
		downloadBlob(
			new Blob([header + lastRecoveryCodes.join('\n') + '\n'], { type: 'text/plain' }),
			'tryhackx-files-recovery-codes.txt'
		);
	}

	async function disable2fa(button = null) {
		const password = document.getElementById('twofaOffPass').value;
		if (!password) {
			flashMessage('twofaDisableMsg', t('panel.2fa.need_pw'), 'error');
			return;
		}
		try {
			const result = await api().post('user_2fa_disable', { password });
			if (result.success) {
				clearQrObjectUrl();
				notify(t('panel.2fa.disabled'), 'success', button);
				document.getElementById('twofaOffPass').value = '';
				show2faState('off');
			} else {
				flashMessage(
					'twofaDisableMsg',
					result.error || t('panel.2fa.disable_failed'),
					'error'
				);
			}
		} catch {
			flashMessage('twofaDisableMsg', t('common.connection_error'), 'error');
		}
	}

	window.FHPanelAccountTools = Object.freeze({
		openCreateApiKey,
		submitCreateApiKey,
		copyApiKey,
		downloadSharexConfig,
		loadApiKeys,
		askRevokeApiKey,
		confirmRevokeApiKey,
		openCreateWebhook,
		submitCreateWebhook,
		copyWebhookSecret,
		loadWebhooks,
		askDeleteWebhook,
		confirmDeleteWebhook,
		load2faStatus,
		start2faSetup,
		cancel2faSetup,
		confirm2faSetup,
		disable2fa,
		openRecoveryCodes,
		submitRecoveryCodes,
		copyRecoveryCodes,
		downloadRecoveryCodes
	});
}());
