(function () {
	'use strict';

	const bootstrap = document.getElementById('panelBootstrap');
	let PANEL = {};
	try {
		PANEL = JSON.parse(bootstrap?.dataset.config || '{}');
	} catch {
		PANEL = {};
	}
	const t = (key, params) => window.t(key, params);
	const formatSize = window.FHUtil.formatSize;
	const showModal = (id) => window.showModal(id);
	const closeModal = (id) => window.closeModal(id);
	const showConfirm = (...args) => window.showConfirm(...args);
	const showNotification = (...args) => window.showNotification(...args);
	const reloadFiles = () => window.FHPanelCore.reloadFiles();

	let pendingPasswordAction = null;
	/* ------------------------------------------------------------------ *
	 * Settings tab helpers
	 * ------------------------------------------------------------------ */
	function toggleEmailFields() {
		const method = document.getElementById('emailMethod')?.value;
		const smtpFields = document.getElementById('smtpFields');
		const phpMailGuard = document.getElementById('phpMailGuardField');
		const prefixGroup = document.getElementById('emailFromPrefixGroup');
		const fullInput = document.getElementById('emailFromFull');
		if (smtpFields) smtpFields.style.display = method === 'smtp' ? 'block' : 'none';
		// The safeguard only decides what mail() does; it is meaningless for the other two.
		if (phpMailGuard) phpMailGuard.style.display = method === 'php' ? 'block' : 'none';
		if (prefixGroup && fullInput) {
			// php and local both post through this host, so the sender is offered as a
			// prefix on the site's own domain; only an external relay takes a full address.
			if (method !== 'smtp') {
				prefixGroup.style.display = 'flex';
				fullInput.style.display = 'none';
				syncEmailFromPrefix();
			} else {
				prefixGroup.style.display = 'none';
				fullInput.style.display = 'block';
				syncEmailFromFull();
			}
		}
	}

	function syncEmailFromPrefix() {
		const prefix = document.getElementById('emailFromPrefix').value.trim();
		document.getElementById('emailFromReal').value = prefix ? (prefix + '@' + PANEL.host) : '';
	}

	function syncEmailFromFull() {
		document.getElementById('emailFromReal').value = document.getElementById('emailFromFull').value.trim();
	}

	function toggleRecaptchaFields() {
		const enabled = document.getElementById('recaptchaEnabled')?.checked;
		const fields = document.getElementById('recaptchaFields');
		if (fields) fields.style.display = enabled ? 'block' : 'none';
	}

	/*
	 * Show the key pair of the selected captcha provider and hide the rest.
	 *
	 * Every provider's inputs stay in the DOM — hidden fields still post, which is exactly
	 * what keeps the other providers' site keys from being wiped on save. The secret inputs
	 * are always blank, and a blank secret means "keep the stored one".
	 */
	function toggleCaptchaProviderFields() {
		const selected = document.getElementById('captchaProvider')?.value;
		if (!selected) return;
		document.querySelectorAll('[data-captcha-provider]').forEach(block => {
			block.style.display = block.dataset.captchaProvider === selected ? 'block' : 'none';
		});
	}

	function confirmCleanup() {
		showModal('cleanupModal');
	}

	async function previewCleanup(event) {
		event?.preventDefault();
		const form = document.getElementById('cleanupForm');
		if (!form) return;
		const data = new FormData(form);
		const days = Number(data.get('cleanup_days') || 0);
		let sizeMb = Number(data.get('cleanup_size') || 0);
		if (data.get('cleanup_unit') === 'GB') sizeMb *= 1024;
		try {
			const preview = await FHApi.post('admin_cleanup_preview', {
				older_than_days: days > 0 ? days : '',
				larger_than_mb: sizeMb > 0 ? sizeMb : ''
			});
			if (!preview.success) throw new Error(preview.error || t('api.invalid_request'));
			if (!preview.count) {
				showNotification(t('panel.modal.cleanup_empty'), 'info');
				return;
			}
			closeModal('cleanupModal');
			showConfirm(
				t('panel.modal.cleanup_confirm_title'),
				t('panel.modal.cleanup_confirm_body', {
					n: preview.count,
					size: formatSize(preview.total_size)
				}),
				async () => {
					try {
						const result = await FHApi.post('admin_cleanup_execute', { nonce: preview.nonce });
						if (!result.success) throw new Error(result.error || t('api.invalid_request'));
						showNotification(t('panel.ctl.cleanup_done', { n: result.deleted }), 'success');
						reloadFiles();
					} catch (error) {
						showNotification(error.message, 'error');
					}
				},
				{ confirmLabel: t('panel.modal.cleanup_confirm_button') }
			);
		} catch (error) {
			showNotification(error.message, 'error');
		}
	}

	/* ------------------------------------------------------------------ *
	 * User tab (self-service: password / email / danger zone)
	 * ------------------------------------------------------------------ */
	function initPanelValidation() {
		const passInput = document.getElementById('panelNewPass');
		const confirmInput = document.getElementById('panelNewPassConfirm');

		if (passInput) {
			passInput.addEventListener('input', function () {
				const val = this.value;
				const bar = document.getElementById('panelPwdBar');
				let score = 0;
				const checks = [['panelReqLen', val.length >= (Number(passInput.minLength) || 8)], ['panelReqUpper', /[A-Z]/.test(val)], ['panelReqDigit', /\d/.test(val)], ['panelReqSpec', /[^a-zA-Z0-9]/.test(val)]];
				checks.forEach(([id, ok]) => {
					const el = document.getElementById(id);
					if (el) el.classList.toggle('valid', ok);
					if (ok) score++;
				});
				if (bar) {
					const pct = (score / 4) * 100;
					bar.style.width = pct + '%';
					bar.style.backgroundColor = pct < 25 ? 'var(--danger)' : pct < 50 ? 'var(--warning)' : pct < 75 ? '#f59e0b' : 'var(--success)';
				}
				checkPanelPassMatch();
			});
		}
		if (confirmInput) confirmInput.addEventListener('input', checkPanelPassMatch);

		const emailInput = document.getElementById('panelNewEmail');
		const confirmEmailInput = document.getElementById('panelConfirmEmail');
		if (emailInput && confirmEmailInput) {
			const checkEmailMatch = () => {
				const e1 = emailInput.value.trim(), e2 = confirmEmailInput.value.trim();
				const status = document.getElementById('panelEmailMatchStatus');
				if (!e2) { status.className = 'field-status'; confirmEmailInput.classList.remove('error', 'success'); return; }
				const ok = e1 === e2 && e1.length > 0;
				status.textContent = ok ? t('panel.acct.emails_match') : t('panel.acct.emails_differ');
				status.className = 'field-status show ' + (ok ? 'status-ok' : 'status-bad');
				confirmEmailInput.classList.toggle('success', ok);
				confirmEmailInput.classList.toggle('error', !ok);
			};
			emailInput.addEventListener('input', checkEmailMatch);
			confirmEmailInput.addEventListener('input', checkEmailMatch);
		}
	}

	function checkPanelPassMatch() {
		const p1 = document.getElementById('panelNewPass').value;
		const p2 = document.getElementById('panelNewPassConfirm').value;
		const status = document.getElementById('panelPassMatchStatus');
		const confirmInput = document.getElementById('panelNewPassConfirm');
		if (!p2) { if (status) status.className = 'field-status'; confirmInput.classList.remove('error', 'success'); return; }
		const ok = p1 === p2;
		if (status) {
			status.textContent = ok ? t('pwd.match_ok') : t('pwd.match_bad');
			status.className = 'field-status show ' + (ok ? 'status-ok' : 'status-bad');
		}
		confirmInput.classList.toggle('success', ok);
		confirmInput.classList.toggle('error', !ok);
	}

	/**
	 * List the devices that can sign this account back in without a password.
	 *
	 * A persistent sign-in is a credential the owner never sees, so the only way it can be
	 * managed is if it is shown. Nothing secret is rendered here — the series and the secret
	 * stay on the server and in the cookie.
	 */
	function loadRememberDevices() {
		const host = document.getElementById('rememberDevices');
		if (!host) return;
		FHApi.get('user_remember_devices').then(data => {
			if (!data.success) {
				host.innerHTML = '';
				return;
			}
			if (!data.devices.length) {
				host.innerHTML = '<p style="color: var(--text-secondary);">'
					+ escapeText(host.dataset.empty || '') + '</p>';
				return;
			}
			host.innerHTML = data.devices.map(device => {
				const used = device.last_used_at
					? new Date(device.last_used_at * 1000).toLocaleString()
					: new Date(device.created_at * 1000).toLocaleString();
				const until = new Date(device.expires_at * 1000).toLocaleString();
				// The browser reading this page is labelled, never hidden: a list that omits
				// the device in your hand reads as "nothing is remembered".
				const badge = device.current
					? ' <span class="remember-current">' + escapeText(t('panel.acct.devices_this')) + '</span>'
					: '';
				return '<div class="remember-device' + (device.current ? ' is-current' : '') + '">'
					+ '<strong>' + escapeText(device.user_agent || '?') + badge + '</strong>'
					+ '<small>' + escapeText(device.last_ip || '?') + ' · '
					+ escapeText(used) + ' → ' + escapeText(until) + '</small>'
					+ '</div>';
			}).join('');
		}).catch(() => { host.innerHTML = ''; });
	}

	/** Escape before injecting a browser-supplied string into markup. */
	function escapeText(value) {
		const node = document.createElement('span');
		node.textContent = String(value ?? '');
		return node.innerHTML;
	}

	function revokeRememberDevices(btn) {
		promptForPassword(t('panel.acct.devices_revoke_title'), t('panel.acct.devices_revoke_msg'), (pass) => {
			FHApi.post('user_remember_revoke', { password: pass }).then(data => {
				if (data.success) {
					showNotification(t('panel.acct.devices_revoked', { n: data.revoked }), 'success', btn);
					loadRememberDevices();
					// Signing the other devices out does not sign this one out, so nothing
					// here needs to reload the page.
				} else {
					showNotification(data.error || t('api.invalid_request'), 'error', btn);
				}
			}).catch(error => showNotification(error.message, 'error', btn));
		});
	}

	function loadUserStats() {
		loadRememberDevices();
		if (!document.getElementById('uStatFiles')) return;
		FHApi.get('get_user_stats').then(data => {
			if (data.success) {
				document.getElementById('uStatFiles').textContent = data.stats.files;
				document.getElementById('uStatStorage').textContent = formatSize(data.stats.storage);
				document.getElementById('uStatDownloads').textContent = data.stats.downloads;
			}
		});
	}

	function promptForPassword(title, message, callback) {
		document.getElementById('pwdConfirmTitle').textContent = title;
		document.getElementById('pwdConfirmMessage').textContent = message;
		document.getElementById('confirmPasswordInput').value = '';
		pendingPasswordAction = callback;
		showModal('passwordConfirmModal');
		setTimeout(() => document.getElementById('confirmPasswordInput').focus(), 100);
	}

	function submitPasswordConfirm() {
		const pass = document.getElementById('confirmPasswordInput').value;
		if (!pass) return;
		closeModal('passwordConfirmModal');
		if (pendingPasswordAction) { pendingPasswordAction(pass); pendingPasswordAction = null; }
	}

	function changeUserPassword(form) {
		const newp = form.new_password.value;
		const confirm = form.new_password_confirm.value;
		const submitBtn = form.querySelector('button[type="submit"]');
		if (newp !== confirm) { showNotification(t('panel.acct.pw_mismatch'), 'error', submitBtn); return; }
		promptForPassword(t('panel.acct.pw_confirm_title'), t('panel.acct.pw_confirm_msg'), (currentPass) => {
			FHApi.post('user_change_password', { current_password: currentPass, new_password: newp }).then(data => {
				if (data.success) {
					showNotification(t('panel.acct.pw_updated'), 'success', submitBtn);
					form.reset();
					setTimeout(() => window.location.reload(), 1800);
				} else {
					showNotification(data.error || t('panel.acct.pw_error'), 'error', submitBtn);
				}
			}).catch(() => showNotification(t('common.connection_error'), 'error', submitBtn));
		});
	}

	function changeUserEmail(form) {
		const newEmail = form.new_email.value.trim();
		const confirmEmail = form.confirm_new_email.value.trim();
		const submitBtn = form.querySelector('button[type="submit"]');
		if (newEmail !== confirmEmail) { showNotification(t('panel.acct.email_mismatch'), 'error', submitBtn); return; }
		if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(newEmail)) { showNotification(t('panel.acct.email_bad'), 'error', submitBtn); return; }
		promptForPassword(t('panel.acct.email_confirm_title'), t('panel.acct.email_confirm_msg'), (currentPass) => {
			FHApi.post('user_request_email_change', { new_email: newEmail, password: currentPass }).then(data => {
				if (data.success) {
					showNotification(t('panel.acct.email_link_sent'), 'success', submitBtn);
					form.reset();
					setTimeout(() => location.reload(), 2200);
				} else {
					showNotification(data.error || t('panel.acct.email_error'), 'error', submitBtn);
				}
			}).catch(() => showNotification(t('common.connection_error'), 'error', submitBtn));
		});
	}

	function confirmDeleteAllFiles(btn) {
		promptForPassword(t('panel.acct.del_files_title'), t('panel.acct.del_files_msg'), (pass) => {
			FHApi.post('user_delete_files', { password: pass })
				.then(data => {
					if (data.success) {
						showNotification(t('panel.acct.del_files_ok', { n: data.deleted }), 'success', btn);
						setTimeout(() => location.reload(), 1800);
					} else {
						showNotification(data.error || t('panel.acct.del_files_failed'), 'error', btn);
					}
				});
		});
	}

	function confirmDeleteAccount(btn) {
		promptForPassword(t('panel.acct.del_account_title'), t('panel.acct.del_account_msg'), (pass) => {
			FHApi.post('user_delete_account', { password: pass })
				.then(data => {
					if (data.success) {
						showNotification(t('panel.acct.del_account_ok'), 'success', btn);
						setTimeout(() => window.location.href = 'index.php', 1800);
					} else {
						showNotification(data.error || t('panel.acct.del_account_failed'), 'error', btn);
					}
				});
		});
	}


	window.FHPanelSettings = Object.freeze({
		toggleEmailFields, syncEmailFromPrefix, syncEmailFromFull, revokeRememberDevices,
		toggleRecaptchaFields, toggleCaptchaProviderFields, confirmCleanup, previewCleanup,
		initPanelValidation, loadUserStats, submitPasswordConfirm,
		changeUserPassword, changeUserEmail,
		confirmDeleteAllFiles, confirmDeleteAccount
	});
}());
