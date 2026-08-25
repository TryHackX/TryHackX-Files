/**
 * TryHackX Files — panel (admin / moderator / user)
 *
 * Configuration is provided by the inert `#panelBootstrap` element:
 *   { appUrl, apiUrl, host, tab, subTab, isAdmin, isMod, loggedIn }
 */
'use strict';

(function () {
	const bootstrap = document.getElementById('panelBootstrap');
	let PANEL = {};
	try {
		PANEL = JSON.parse(bootstrap?.dataset.config || '{}');
	} catch (_error) {
		PANEL = {};
	}
	const apiUrl = PANEL.apiUrl;
	const appUrl = PANEL.appUrl;

	/* ------------------------------------------------------------------ *
	 * Shared state
	 * ------------------------------------------------------------------ */
	let pendingConfirmAction = null;

	window.FHPanelState = Object.freeze({
		getUsers: () => window.FHPanelUsers.getUsers(),
		isRootAdmin: (userId) => window.FHPanelUsers.isRootAdmin(userId)
	});
	window.FHPanelCore = Object.freeze({
		fetchLive,
		showSkeleton,
		finishSkeleton,
		renderPager: (...args) => window.FHPanelFiles.renderPager(...args),
		refreshUsers: () => window.FHPanelUsers.refreshCurrentPage(),
		resetCreateUserValidation: () => {
			if (resetNewPw) resetNewPw();
			if (resetNewEmail) resetNewEmail();
		},
		resetManageUserPasswordValidation: () => {
			if (resetMuPw) resetMuPw();
		},
		reloadFiles: () => window.FHPanelFiles.loadFiles(1),
		resetCollectionValidation: (kind) => {
			if (kind === 'create' && resetCcPw) resetCcPw();
			if (kind === 'settings' && resetCsPw) resetCsPw();
		}
	});

	/* ------------------------------------------------------------------ *
	 * Helpers (shared: assets/js/util.js)
	 * ------------------------------------------------------------------ */
	const esc = window.FHUtil.esc;
	const formatSize = window.FHUtil.formatSize;
	const formatDate = window.FHUtil.formatDate;
	const attr = value => String(value ?? '').replace(/[&<>"']/g, ch => ({
		'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
	})[ch]);

	function copyUrl(e, id) {
		navigator.clipboard.writeText(appUrl + '/download.php?id=' + id);
		showNotification(t('common.copied'), 'copy', e ? e.target : null);
	}

	// ETag-aware GET for the live auto-refresh (A6). Stores the last ETag per
	// list `key` and re-sends it as `If-None-Match`; a `304 Not Modified` means
	// the data is unchanged, so the caller skips re-rendering (no flicker, and
	// the server ships no body). `cache: 'no-store'` keeps the browser from
	// swallowing the 304 on its own.
	const liveEtags = {};
	// `useEtag` is only set for silent background polls: they may get a cheap 304 and skip the
	// re-render. Explicit user loads pass useEtag=false so they always get a fresh 200 and render
	// (never leaving a loading skeleton stuck because nothing changed since the last poll).
	async function fetchLive(url, key, useEtag = true) {
		const headers = {};
		if (useEtag && key && liveEtags[key]) headers['If-None-Match'] = liveEtags[key];
		const res = await fetch(url, { headers, cache: 'no-store' });
		if (res.status === 304) return { notModified: true };
		if (key) {
			const et = res.headers.get('ETag');
			if (et) liveEtags[key] = et;
		}
		return { data: await res.json() };
	}

	// Initial table loader. The old implementation put placeholder cells inside the real
	// table. With `table-layout:auto` that gave the browser three visibly different sets of
	// column widths: headings, placeholder cells, and finally the real (often long) values.
	// This loader is an overlay. Real rows settle underneath it, and the table is revealed
	// after two animation frames. Existing rows stay visible during later refreshes.
	const tableLoadStates = new WeakMap();
	const nextFrame = callback => {
		if (typeof window.requestAnimationFrame === 'function') {
			window.requestAnimationFrame(callback);
		} else {
			setTimeout(callback, 0);
		}
	};

	function removeTableLoader(tbody, state) {
		state.observer?.disconnect();
		if (state.timer) clearTimeout(state.timer);
		state.table.classList.remove('table-loading-target');
		state.table.removeAttribute('aria-busy');
		// Let the wrapper assume the real table's natural height immediately. The compact
		// overlay can still fade above it without preserving an obsolete placeholder height.
		state.wrapper.style.setProperty('--table-loader-height', '0px');
		state.overlay.classList.add('is-leaving');
		setTimeout(() => {
			state.overlay.remove();
			if (!state.wrapper.querySelector('.table-loading-overlay')) {
				state.wrapper.classList.remove('table-loading');
				state.wrapper.style.removeProperty('--table-loader-height');
			}
			tableLoadStates.delete(tbody);
		}, 180);
	}

	/**
	 * pt 8: a skeleton belongs on an *empty* table, not on one that already has rows.
	 *
	 * Pressing Refresh on the all-files list wiped the table to six shimmer rows and then put
	 * the same rows back — the whole list jumped even when nothing had changed. "My files"
	 * never did this simply because it never called this function, which is why one tab felt
	 * calm and the other did not.
	 *
	 * So: cover only a table that has nothing real to show yet. A refresh over existing data
	 * leaves it exactly where it is until replacement rows are ready.
	 */
	function showSkeleton(tbodyId) {
		const tbody = document.getElementById(tbodyId);
		if (!tbody) return false;
		const hasRealRows = [...tbody.querySelectorAll('tr')].some(
			tr => !tr.querySelector('td.empty')
		);
		if (hasRealRows) return false;
		if (tableLoadStates.has(tbody)) return true;

		const table = tbody.closest('table');
		const wrapper = table?.closest('.table-wrap');
		if (!table || !wrapper) return false;
		const overlay = document.createElement('div');
		overlay.className = 'table-loading-overlay';
		overlay.setAttribute('aria-hidden', 'true');
		overlay.innerHTML = '<span class="skel table-loading-indicator"></span>';

		wrapper.classList.add('table-loading');
		wrapper.style.setProperty('--table-loader-height', '104px');
		wrapper.appendChild(overlay);
		table.classList.add('table-loading-target');
		table.setAttribute('aria-busy', 'true');

		const state = { table, wrapper, overlay, observer: null, timer: null, finishing: false };
		if (typeof MutationObserver === 'function') {
			state.observer = new MutationObserver(() => finishSkeleton(tbodyId));
			state.observer.observe(tbody, { childList: true, subtree: true });
		}
		// A failed/custom loader must never leave the table inaccessible forever.
		state.timer = setTimeout(() => finishSkeleton(tbodyId), 20000);
		tableLoadStates.set(tbody, state);
		return true;
	}

	function finishSkeleton(tbodyId) {
		const tbody = typeof tbodyId === 'string' ? document.getElementById(tbodyId) : tbodyId;
		const state = tbody && tableLoadStates.get(tbody);
		if (!state || state.finishing) return false;
		state.finishing = true;
		state.observer?.disconnect();
		nextFrame(() => nextFrame(() => removeTableLoader(tbody, state)));
		return true;
	}

	function initTableSkeletons() {
		document.querySelectorAll('.table-wrap tbody[id]').forEach(tbody => {
			const placeholder = tbody.querySelector(':scope > tr:only-child > td.empty');
			if (placeholder) showSkeleton(tbody.id);
		});
	}

	/* ------------------------------------------------------------------ *
	 * Modals — single, unified implementation using the `.show` class
	 * (matches `.modal-bg.show { display: flex }` in panel.css).
	 *
	 * A11y (U3): opening a modal traps Tab focus inside it and moves focus to the
	 * first field; closing it (button, Esc, or click-outside) returns focus to the
	 * element that had it before. One trap is active at a time.
	 * ------------------------------------------------------------------ */
	let focusTrap = { modal: null, prevFocus: null, handler: null };

	function getFocusable(container) {
		return Array.from(container.querySelectorAll(
			'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
		)).filter(el => el.offsetWidth > 0 || el.offsetHeight > 0 || el === document.activeElement);
	}

	function trapFocus(modal) {
		releaseFocusTrap(false); // never stack traps
		focusTrap.modal = modal;
		focusTrap.prevFocus = document.activeElement;
		const focusables = getFocusable(modal);
		const target = focusables.find(el => /^(INPUT|SELECT|TEXTAREA)$/.test(el.tagName)) || focusables[0];
		if (target) setTimeout(() => { try { target.focus(); } catch (e) { /* ignore */ } }, 50);
		focusTrap.handler = function (e) {
			if (e.key !== 'Tab') return;
			const f = getFocusable(modal);
			if (!f.length) return;
			const first = f[0], last = f[f.length - 1];
			if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
			else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
		};
		modal.addEventListener('keydown', focusTrap.handler);
	}

	function releaseFocusTrap(restore = true) {
		if (focusTrap.modal && focusTrap.handler) {
			focusTrap.modal.removeEventListener('keydown', focusTrap.handler);
		}
		if (restore && focusTrap.prevFocus && typeof focusTrap.prevFocus.focus === 'function') {
			try { focusTrap.prevFocus.focus(); } catch (e) { /* ignore */ }
		}
		focusTrap = { modal: null, prevFocus: null, handler: null };
	}

	// A8: stack of currently-open modals (most-recent last). Lets Esc / backdrop
	// clicks dismiss only the top-most modal — returning to the one beneath instead
	// of closing everything — and lets a stacked modal paint above the one that
	// opened it even when it sits earlier in the DOM (e.g. confirmModal opened from
	// within groupsModal). Base .modal-bg z-index is 1000 (panel.css).
	let modalStack = [];

	// Reset handles for the create-user (B3) / manage-user (B2) / create-collection (C2)
	// password validators, wired in init().
	let resetNewPw = null, resetNewEmail = null, resetMuPw = null, resetCcPw = null, resetCsPw = null;

	function openModal(id) {
		const m = document.getElementById(id);
		if (!m) return;
		m.classList.add('show');
		modalStack = modalStack.filter(x => x !== m); // no duplicate entries on re-open
		modalStack.push(m);
		m.style.zIndex = String(1000 + modalStack.length * 10);
		document.body.style.overflow = 'hidden';
		trapFocus(m);
	}

	// Dismiss one modal element and hand control back to the modal beneath it (if any).
	function dismissModal(m) {
		if (!m) return;
		m.classList.remove('show');
		m.style.zIndex = '';
		modalStack = modalStack.filter(x => x !== m);
		if (focusTrap.modal === m) releaseFocusTrap(true);
		const below = modalStack[modalStack.length - 1];
		if (below && below.classList.contains('show')) {
			trapFocus(below); // re-arm focus trap + Tab cycling on the modal underneath
		} else {
			document.body.style.overflow = '';
		}
	}

	function closeModal(id) {
		dismissModal(document.getElementById(id));
	}

	// Top-most open modal: prefer the tracked stack, fall back to DOM order for any
	// modal opened outside openModal (equal z-index → last in DOM paints on top).
	function topModal() {
		for (let i = modalStack.length - 1; i >= 0; i--) {
			if (modalStack[i].classList.contains('show')) return modalStack[i];
		}
		const shown = document.querySelectorAll('.modal-bg.show, .auth-modal.show');
		return shown.length ? shown[shown.length - 1] : null;
	}

	const showModal = openModal;

	function initModalDismissal() {
		document.addEventListener('keydown', function (e) {
			if (e.key !== 'Escape') return;
			// Ignore key auto-repeat: holding Esc must not cascade through the modal
			// stack — one press dismisses one modal, then the key must be released.
			if (e.repeat) return;
			const top = topModal();
			if (!top) return;
			e.preventDefault();
			dismissModal(top);
		});

		// The account modal (header person icon) is an `.auth-modal`, not a `.modal-bg`, so it
		// used to miss out on backdrop dismissal entirely — the only way out was the × button.
		// Both selectors are wired here, and dismissModal() works for either since it only
		// touches the `show` class and the stack.
		let downTarget = null;
		document.addEventListener('mousedown', e => { downTarget = e.target; });
		document.querySelectorAll('.modal-bg, .auth-modal').forEach(modal => {
			modal.addEventListener('mouseup', function (e) {
				if (e.target === this && downTarget === this) dismissModal(this);
			});
		});
	}

	// A11y (U3): tag static modals as dialogs (labelled by their heading) and give
	// icon-only controls an accessible name from their `title`. One pass at load — the
	// static markup; dynamically-rendered action buttons already carry a `title`.
	function initA11y() {
		document.querySelectorAll('.modal-bg, .auth-modal').forEach(modal => {
			modal.setAttribute('role', 'dialog');
			modal.setAttribute('aria-modal', 'true');
			const h = modal.querySelector('.modal-header h3, h3');
			if (h) {
				if (!h.id) h.id = 'mh_' + Math.random().toString(36).slice(2, 9);
				modal.setAttribute('aria-labelledby', h.id);
			}
		});
		document.querySelectorAll('button[title], a[title]').forEach(el => {
			if (!el.getAttribute('aria-label') && !el.textContent.trim()) {
				el.setAttribute('aria-label', el.getAttribute('title'));
			}
		});
	}

	function showNotification(text, type = 'success', targetEl = null) {
		if (!window.FHUi || typeof window.FHUi.toast !== 'function') return;
		const autosaveKey = !targetEl && type === 'success' && text === t('panel.ctl.saved')
			? 'panel-autosave'
			: '';
		window.FHUi.toast(text, {
			el: 'notification',
			type: type,
			target: targetEl,
			key: autosaveKey,
			duration: type === 'error' ? 4200 : 2200
		});
	}

	// Small inline message helper for messages rendered inside modals.
	function flashMessage(id, text, type, timeout = 3000) {
		const el = document.getElementById(id);
		if (!el) return;
		el.textContent = text;
		el.className = 'auth-message show ' + type;
		if (el._timer) clearTimeout(el._timer);
		if (timeout) {
			el._timer = setTimeout(() => {
				el.classList.remove('show');
				el.textContent = '';
			}, timeout);
		}
	}

	function submitPanelOnEnter(event, action) {
		if (event.key !== 'Enter') return;
		const allowed = Object.freeze({
			duplicateLanguage: submitDuplicateLanguage,
			fileDownload: submitFileDownloadPassword,
			collectionDownload: submitCollectionZipPassword
		});
		if (!Object.hasOwn(allowed, action)) return;
		event.preventDefault();
		allowed[action]();
	}

	/* ------------------------------------------------------------------ *
	 * Theme + header
	 * ------------------------------------------------------------------ */
	function toggleTheme() {
		document.body.classList.toggle('light');
		const theme = document.body.classList.contains('light') ? 'light' : 'dark';
		document.cookie = `theme=${theme};path=/;max-age=31536000`;
	}

	function toggleUserDropdown(e) {
		if (e) e.stopPropagation();
		document.getElementById('userDropdown')?.classList.toggle('show');
	}

	async function handleLogout(e) {
		if (e) e.preventDefault();
		try {
			await FHApi.post('user_logout', {});
		} finally {
			window.location.href = appUrl + '/?logout=1';
		}
	}

	/* ------------------------------------------------------------------ *
	 * Auth modal (header person icon)
	 * ------------------------------------------------------------------ */
	// Goes through the shared stack like every other modal, so it gets the same focus trap,
	// scroll lock, Esc handling and backdrop dismissal instead of its own half-implementation
	// (which is why it could not be closed by clicking outside it).
	function showAuthModal() {
		openModal('authModal');
		fetchAuthStats();
		fetchSessionInfo();
	}

	function hideAuthModal() {
		dismissModal(document.getElementById('authModal'));
	}

	document.addEventListener('click', event => {
		const trigger = event.target.closest?.('[data-panel-action]');
		if (!trigger) return;
		const action = trigger.dataset.panelAction;
		if (action === 'close-auth') {
			hideAuthModal();
		} else if (action === 'logout') {
			handleLogout(event);
		}
	});

	async function fetchAuthStats() {
		const statsRow = document.getElementById('userStats');
		if (!statsRow) return;
		try {
			const data = await FHApi.get('user_stats');
			if (data.success && data.stats) {
				document.getElementById('statFiles').textContent = data.stats.files_count;
				document.getElementById('statSize').textContent = formatSize(data.stats.total_size);
				document.getElementById('statDownloads').textContent = data.stats.total_downloads;
				statsRow.style.display = 'grid';
			}
		} catch (e) { /* ignore */ }
	}

	async function fetchSessionInfo() {
		try {
			const data = await FHApi.get('token_info');
			if (data.success && data.expires_in) {
				document.getElementById('sessionTime').textContent = Math.ceil(data.expires_in / 60) + ' min';
				document.getElementById('sessionInfo').style.display = 'block';
			}
		} catch (e) { /* ignore */ }
	}

	/* Files, filters, downloads and collections live in panel-files.js. */
	const {
		loadFiles, sortBy, goPage, showDeleteFile, executeFileDelete,
		toggleFileSelect, toggleSelectAllFiles, bulkDeleteFiles,
		sortMyFiles, goMyPage, deleteMyFile, executeMyFileDelete, loadMyFiles,
		openFileOptions, saveFileOptions,
		toggleMyFileSelect, toggleSelectAllMyFiles, bulkDeleteMyFiles,
		toggleMyCollectionSelect, toggleSelectAllMyCollections, bulkDeleteMyCollections,
		openCreateCollection, submitCreateCollection, copyCollectionResult,
		openAddToCollection, renderAddToCollectionList, pickAddToCollection,
		submitAddToCollection, editCreatedCollection,
		loadCollections, copyCollectionUrl, askDeleteCollection, confirmDeleteCollection,
		loadAdminCollections, copyAdminCollectionUrl,
		openCollectionSettings, saveCollectionSettings, downloadCollection,
		submitCollectionZipPassword, csMoveFile, csRemoveFile,
		downloadFile, submitFileDownloadPassword,
		goCollectionsPage, goAdminCollectionsPage,
		openCollectionFromAll, continueLockedFiles,
		openFiltersModal, applyFilters, clearAllFilters, removeFilter,
		filterChips, toggleChip, reloadScopedList,
		openMyFiltersModal, applyMyFilters, clearAllMyFilters, removeMyFilter,
		setMyFilterScope, onMyEmptyCollectionsToggle,
		setFilterScope, onEmptyCollectionsToggle,
		toggleCollectionSelect, toggleSelectAllCollections, bulkDeleteCollections,
		bindFileControls, initFilesTab, initMyFilesTab
	} = window.FHPanelFiles;

	/* Account tools: API keys, webhooks and 2FA live in panel-account-tools.js. */
	const {
		openCreateApiKey, submitCreateApiKey, copyApiKey, downloadSharexConfig,
		loadApiKeys, askRevokeApiKey, confirmRevokeApiKey,
		openCreateWebhook, submitCreateWebhook, copyWebhookSecret,
		loadWebhooks, askDeleteWebhook, confirmDeleteWebhook,
		load2faStatus, start2faSetup, cancel2faSetup, confirm2faSetup, disable2fa,
		openRecoveryCodes, submitRecoveryCodes, copyRecoveryCodes, downloadRecoveryCodes
	} = window.FHPanelAccountTools;

	/* Generic live password strength meter + requirement checklist + match indicator.
	   Reused by the manage-user (B2) and create-user (B3) forms so their password reset
	   matches the rest of the site (fileOptions / account tab). `o` = { pass, bar, len,
	   upper, digit, spec, confirm, match }. Returns a reset() function. */
	function wirePwStrength(o) {
		const pass = document.getElementById(o.pass);
		if (!pass) return null;
		const bar = o.bar && document.getElementById(o.bar);
		const confirm = o.confirm && document.getElementById(o.confirm);
		const matchEl = o.match && document.getElementById(o.match);
		const evalMatch = () => {
			if (!confirm || !matchEl) return;
			const p2 = confirm.value;
			if (!p2) { matchEl.className = 'field-status'; matchEl.textContent = ''; confirm.classList.remove('error', 'success'); return; }
			const ok = pass.value === p2;
			matchEl.textContent = ok ? t('pwd.match_ok') : t('pwd.match_bad');
			matchEl.className = 'field-status show ' + (ok ? 'status-ok' : 'status-bad');
			confirm.classList.toggle('success', ok);
			confirm.classList.toggle('error', !ok);
		};
		const evalStrength = () => {
			const val = pass.value;
			let score = 0;
			[[o.len, val.length >= (Number(input.minLength) || 8)], [o.upper, /[A-Z]/.test(val)], [o.digit, /\d/.test(val)], [o.spec, /[^a-zA-Z0-9]/.test(val)]]
				.forEach(([id, ok]) => { const el = id && document.getElementById(id); if (el) el.classList.toggle('valid', ok); if (ok) score++; });
			if (bar) { const pct = (score / 4) * 100; bar.style.width = pct + '%'; bar.style.backgroundColor = pct < 25 ? 'var(--danger)' : pct < 50 ? 'var(--warning)' : pct < 75 ? '#f59e0b' : 'var(--success)'; }
			evalMatch();
		};
		pass.addEventListener('input', evalStrength);
		if (confirm) confirm.addEventListener('input', evalMatch);
		return () => {
			if (bar) bar.style.width = '0%';
			[o.len, o.upper, o.digit, o.spec].forEach(id => { const el = id && document.getElementById(id); if (el) el.classList.remove('valid'); });
			if (matchEl) { matchEl.className = 'field-status'; matchEl.textContent = ''; }
			pass.classList.remove('error', 'success');
			if (confirm) confirm.classList.remove('error', 'success');
		};
	}

	// Live email-format indicator for the create-user form (B3). Returns a reset() function.
	function wireEmailValidation(inputId, statusId) {
		const input = document.getElementById(inputId);
		const status = document.getElementById(statusId);
		if (!input || !status) return null;
		const check = () => {
			const v = input.value.trim();
			if (!v) { status.className = 'field-status'; status.textContent = ''; input.classList.remove('error', 'success'); return; }
			const ok = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
			status.textContent = ok ? t('panel.modal.email_ok') : t('panel.modal.email_invalid');
			status.className = 'field-status show ' + (ok ? 'status-ok' : 'status-bad');
			input.classList.toggle('success', ok);
			input.classList.toggle('error', !ok);
		};
		input.addEventListener('input', check);
		return () => { status.className = 'field-status'; status.textContent = ''; input.classList.remove('error', 'success'); };
	}

	/* User administration lives in panel-users.js. */
	const {
		loadUsers, sortUsers, userAction, executeUserAction,
		openBanModal, executeAdvancedBan, showCreateUserModal, createUser
	} = window.FHPanelUsers;

	/* Premium plans, payments and subscriptions live in panel-premium.js. */
	const {
		loadPlans, loadPaymentPlugins, loadPromoCodes, loadMyPremium,
		openPlanForm, savePlan, askDeletePlan, togglePlanEnabled,
		onPlanCheckoutTypeChange, onPlanKindChange, onPlanLimitsToggle,
		setPremiumRange, loadPremiumPayments, loadPremiumSubscribers, loadPremiumOverview,
		openBulkPlanGrant, updateBulkSourceFields, previewBulkPlanGrant, executeBulkPlanGrant,
		onGrantPlanChange, askPremiumRefund,
		openPromoForm, onPromoScopeChange, savePromoForm, askDeletePromo,
		savePremiumSettings, regeneratePremiumToken, copyPremiumToken,
		openPlanGrant, grantPlan, revokePlan,
		openPlugin, savePlugin, testPaymentPlugin, schedulePremiumSearch
	} = window.FHPanelPremium;

	/* ------------------------------------------------------------------ *
	 * Documentation viewer — render a shipped .md file in a modal so an admin can
	 * read it in place instead of opening the raw file off disk.
	 * ------------------------------------------------------------------ */

	function openDocModal(name) {
		return window.FHPanelDocs?.openDocModal(name);
	}

	/* Language management lives in panel-languages.js. */
	const {
		loadLanguages, toggleLanguage, askDeleteLanguage, exportLanguage,
		downloadLanguageTemplate, askDuplicateLanguage, submitDuplicateLanguage,
		openLanguageUpload, onLanguageFilePicked, submitLanguageUpload,
		saveUserLanguage, onLanguageCodeInput, pickLanguageCode
	} = window.FHPanelLanguages;

	/* Group management lives in panel-groups.js. */
	const {
		loadGroups, loadSettingsGroups, openGroupForm, saveGroup, deleteGroup,
		goGroupsPage, onPermToggle, onGroupRetentionToggle, openSetUserGroup,
		confirmSetUserGroup, renderGroupPreview, openManageUser, saveManageUser,
		onManageRoleChange
	} = window.FHPanelGroups;

	/* Bans, reports and audit live in panel-moderation.js. */
	const {
		loadIPBans, showAddBanForm, updateBanInputPlaceholder, executeAddBan,
		unbanIP, cancelUnban, confirmUnban, loadReports, showReportDetails,
		showRejectReport, confirmRejectReport, showDeleteReported,
		confirmDeleteReported, rejectFromDetails, deleteFromDetails, loadAuditLog,
		refreshReports, refreshAuditLog
	} = window.FHPanelModeration;

	/* Dashboard widgets and live transfers live in panel-dashboard.js. */
	const {
		loadTopFilesPref, loadTrafficPref, loadDashboard, loadTopFiles,
		openTopFilesSettings, onTopFilesPeriodChange, applyTopFilesSettings,
		setTrafficRange, openTrafficRange, applyTrafficRange, loadTraffic,
		loadActiveDownloads, killDownload, killUpload, initActiveDownloadsLive
	} = window.FHPanelDashboard;

	/* Settings and account self-service live in panel-settings.js. */
	const {
		toggleEmailFields, syncEmailFromPrefix, syncEmailFromFull, revokeRememberDevices,
		toggleRecaptchaFields, confirmCleanup, previewCleanup,
		initPanelValidation, loadUserStats, submitPasswordConfirm,
		changeUserPassword, changeUserEmail,
		confirmDeleteAllFiles, confirmDeleteAccount
	} = window.FHPanelSettings;

	/**
	 * Shared confirmation dialog.
	 *
	 * `opts` lets a caller say the action is not destructive (pkt 4): most confirmations here
	 * are deletions, but "issue a new token" is not one, and a red button on it reads as a
	 * warning that isn't there. Defaults stay as they were, so existing callers are unaffected.
	 */
	function showConfirm(title, message, callback, opts = {}) {
		document.getElementById('confirmTitle').textContent = title;
		document.getElementById('confirmMessage').textContent = message;

		const danger = opts.danger !== false;
		const btn = document.getElementById('confirmBtn');
		btn.textContent = opts.confirmLabel || t('panel.modal.confirm');
		btn.className = 'btn ' + (danger ? 'btn-danger' : 'btn-primary');
		const icon = document.getElementById('confirmIcon');
		if (icon) icon.className = 'fa-solid ' + (opts.icon || (danger ? 'fa-triangle-exclamation' : 'fa-circle-question'));

		pendingConfirmAction = callback;
		showModal('confirmModal');
	}

	function confirmAction() {
		if (pendingConfirmAction) { pendingConfirmAction(); pendingConfirmAction = null; }
		closeModal('confirmModal');
	}

	/* Advertising is isolated in panel-ads.js; the orchestrator retains only its public API. */
	const {
		loadAdsSettings, saveAdsSettings, loadAdsManager,
		openAdForm, adFormTypeChanged, saveAdForm, adAction, askDeleteAd,
		openZoneAssign, assignAdToZone, loadAdsQueue, approveAd,
		openAdReject, confirmAdReject, loadAdsPackages, openPackageForm,
		packageKindChanged, savePackageForm, askDeletePackage,
		setAdsRange, loadAdsStats, loadMyAds, buyPackage, editMyAd,
		saveMyAdForm, payMyAd, toggleMyAdMetrics, openMyAdBoost,
		buyMyAdBoost, myAdAddonToggled, myAdAddonFilePicked, toggleMyAdPause,
		openMyAdRenew, confirmMyAdRenew, adCropClear, adCropApply,
		adCropCancel, adCropCenter, adUploaderRecrop, initAdUploader,
		adFormDims, myAdFormDims
	} = window.FHPanelAds;

	/* ------------------------------------------------------------------ *
	 * Init / dispatch
	 * ------------------------------------------------------------------ */
	function initEmailSettings() {
		const real = document.getElementById('emailFromReal');
		if (!real) return;
		const realVal = real.value || '';
		if (document.getElementById('emailFromFull')) document.getElementById('emailFromFull').value = realVal;
		const prefixEl = document.getElementById('emailFromPrefix');
		if (prefixEl) {
			const parts = realVal.split('@');
			prefixEl.value = (parts.length === 2 && parts[1] === PANEL.host) ? parts[0] : 'noreply';
		}
		prefixEl?.addEventListener('input', syncEmailFromPrefix);
		document.getElementById('emailFromFull')?.addEventListener('input', syncEmailFromFull);
		toggleEmailFields();
	}

	/* ------------------------------------------------------------------ *
	 * Live auto-refresh (A6): poll the active tab's list/dashboard so the
	 * view keeps itself current without a manual "Refresh". Polling pauses
	 * when the tab is hidden (Page Visibility API) or a modal is open, and
	 * fires immediately when the tab regains focus/visibility. The server's
	 * ETag/304 keeps an unchanged poll cheap; explicit user actions still do
	 * their own optimistic reloads.
	 * ------------------------------------------------------------------ */
	const LIVE_INTERVAL = 20000; // base poll cadence (ms)
	let liveTimer = null, liveLastRun = 0;

	function liveRefresher() {
		switch (PANEL.tab) {
			case 'dashboard': return () => loadDashboard(true);
			// Whichever list the filter scope currently has on screen (pt 4).
			case 'files': return document.getElementById('filesBody')
				? () => window.FHPanelFiles.refreshAdmin(true)
				: null;
			case 'users': return () => window.FHPanelUsers.refreshCurrentPage(true);
			case 'myfiles': return () => window.FHPanelFiles.refreshMy(true);
			case 'moderate': return PANEL.modTab === 'audit'
				? refreshAuditLog
				: PANEL.modTab === 'reports' ? refreshReports : null;
			default: return null; // 'user' / 'settings' have nothing to poll
		}
	}

	function liveTick() {
		if (document.visibilityState !== 'visible') return;                     // tab in background
		if (document.querySelector('.modal-bg.show, .auth-modal.show')) return; // don't disrupt an open modal
		if (Date.now() - liveLastRun < 2000) return;                            // never hammer (focus bursts)
		const r = liveRefresher();
		if (!r) return;
		liveLastRun = Date.now();
		r();
	}

	function initLiveRefresh() {
		if (!liveRefresher()) return; // nothing to poll on this tab
		liveTimer = setInterval(liveTick, LIVE_INTERVAL);
		document.addEventListener('visibilitychange', () => {
			if (document.visibilityState === 'visible') liveTick();
		});
		window.addEventListener('focus', liveTick);
	}

	/* ------------------------------------------------------------------ *
	 * Command palette + keyboard shortcuts (Faza 2.3)
	 * ------------------------------------------------------------------ */
	let cmdFiltered = [], cmdIndex = 0;

	function paletteCommands() {
		const nav = (tab) => () => { window.location.href = 'panel.php?tab=' + tab; };
		// Run an action if we're already on the tab that has it; otherwise just navigate there.
		const runOrNav = (fn, tab) => () => {
			if (PANEL.tab === tab && typeof window[fn] === 'function') window[fn]();
			else window.location.href = 'panel.php?tab=' + tab;
		};
		const goto = (target) => t('panel.cmd.goto', { target: target });
		const c = [];
		if (PANEL.isAdmin) {
			c.push({ label: goto(t('panel.nav.dashboard')), icon: '<i class="fa-solid fa-gauge-high"></i>', run: nav('dashboard') });
			c.push({ label: goto(t('panel.nav.files')), icon: '<i class="fa-solid fa-folder"></i>', run: nav('files') });
			c.push({ label: goto(t('panel.nav.users')), icon: '<i class="fa-solid fa-users"></i>', run: nav('users') });
			c.push({ label: goto(t('panel.nav.settings')), icon: '<i class="fa-solid fa-gear"></i>', run: nav('settings') });
			c.push({ label: goto(t('panel.nav.audit')), icon: '<i class="fa-solid fa-scroll"></i>', run: nav('audit') });
		}
		if (PANEL.isMod) c.push({ label: goto(t('panel.nav.moderate')), icon: '<i class="fa-solid fa-shield-halved"></i>', run: nav('moderate') });
		c.push({ label: goto(t('panel.nav.myfiles')), icon: '<i class="fa-solid fa-folder-open"></i>', run: nav('myfiles') });
		c.push({ label: goto(t('panel.nav.account')), icon: '<i class="fa-solid fa-user"></i>', run: nav('user') });
		if (PANEL.isAdmin) {
			c.push({ label: t('panel.cmd.add_user'), icon: '<i class="fa-solid fa-plus"></i>', run: runOrNav('showCreateUserModal', 'users') });
			c.push({ label: t('panel.cmd.groups'), icon: '<i class="fa-solid fa-users"></i>', run: () => { window.location.href = 'panel.php?tab=settings&stab=groups'; } });
			c.push({ label: t('panel.cmd.ip_bans'), icon: '<i class="fa-solid fa-ban"></i>', run: runOrNav('loadIPBans', 'users') });
		}
		c.push({ label: t('panel.cmd.theme'), icon: '<i class="fa-solid fa-circle-half-stroke"></i>', run: toggleTheme });
		c.push({ label: t('panel.cmd.shortcuts'), icon: '<i class="fa-solid fa-keyboard"></i>', run: () => showModal('shortcutsModal') });
		c.push({ label: t('panel.cmd.logout'), icon: '<i class="fa-solid fa-right-from-bracket"></i>', run: () => handleLogout() });
		return c;
	}

	function openCmdPalette() {
		const input = document.getElementById('cmdInput');
		if (!input) return;
		input.value = '';
		renderCmdResults('');
		showModal('cmdPalette');
		setTimeout(() => input.focus(), 60);
	}

	function renderCmdResults(q) {
		const all = paletteCommands();
		q = (q || '').trim().toLowerCase();
		cmdFiltered = q ? all.filter(c => c.label.toLowerCase().includes(q)) : all;
		cmdIndex = 0;
		const ul = document.getElementById('cmdResults');
		if (!ul) return;
		if (!cmdFiltered.length) { ul.innerHTML = `<li class="cmd-empty">${esc(t('panel.cmd.empty'))}</li>`; return; }
		ul.innerHTML = cmdFiltered.map((c, i) =>
			`<li class="cmd-item ${i === 0 ? 'active' : ''}" data-i="${i}"><span class="cmd-ico">${c.icon}</span>${esc(c.label)}</li>`
		).join('');
		ul.querySelectorAll('.cmd-item').forEach(li => li.addEventListener('click', () => runCmd(parseInt(li.dataset.i))));
	}

	function moveCmd(delta) {
		if (!cmdFiltered.length) return;
		cmdIndex = (cmdIndex + delta + cmdFiltered.length) % cmdFiltered.length;
		const ul = document.getElementById('cmdResults');
		ul.querySelectorAll('.cmd-item').forEach((li, i) => li.classList.toggle('active', i === cmdIndex));
		const active = ul.querySelector('.cmd-item.active');
		if (active) active.scrollIntoView({ block: 'nearest' });
	}

	function runCmd(i) {
		const c = cmdFiltered[i != null ? i : cmdIndex];
		if (!c) return;
		closeModal('cmdPalette');
		setTimeout(() => c.run(), 50);
	}

	function focusSearch() {
		const el = document.getElementById('search') || document.getElementById('userSearch') || document.getElementById('mySearch');
		if (el) { el.focus(); if (el.select) el.select(); }
	}

	function initShortcuts() {
		let gPending = false, gTimer = null;
		document.getElementById('cmdInput')?.addEventListener('input', e => renderCmdResults(e.target.value));

		document.addEventListener('keydown', (e) => {
			// Ctrl/Cmd+K → command palette (works anywhere).
			if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
				e.preventDefault();
				openCmdPalette();
				return;
			}
			// While the palette is open, drive it with the keyboard.
			const pal = document.getElementById('cmdPalette');
			if (pal && pal.classList.contains('show')) {
				if (e.key === 'ArrowDown') { e.preventDefault(); moveCmd(1); }
				else if (e.key === 'ArrowUp') { e.preventDefault(); moveCmd(-1); }
				else if (e.key === 'Enter') { e.preventDefault(); runCmd(); }
				return;
			}
			// Bare-key shortcuts are ignored while typing or when another modal is open.
			const ae = document.activeElement;
			const typing = ae && (/^(INPUT|TEXTAREA|SELECT)$/.test(ae.tagName) || ae.isContentEditable);
			if (typing || document.querySelector('.modal-bg.show, .auth-modal.show')) return;

			if (e.key === '/') { e.preventDefault(); focusSearch(); return; }
			if (e.key === '?') { e.preventDefault(); showModal('shortcutsModal'); return; }
			if (e.key === 'g') { gPending = true; clearTimeout(gTimer); gTimer = setTimeout(() => { gPending = false; }, 1000); return; }
			if (gPending) {
				gPending = false;
				const map = { d: 'dashboard', f: 'files', u: 'users', m: 'moderate', s: 'settings', a: 'audit' };
				const t = map[(e.key || '').toLowerCase()];
				if (t) { e.preventDefault(); window.location.href = 'panel.php?tab=' + t; }
			}
		});
	}

	function init() {
		initModalDismissal();
		initA11y();
		initShortcuts();
		initEmailSettings();
		// Install overlays before any tab loader starts, so intermediate auto-layout passes
		// never expose shifting headings, names or badges to the user.
		initTableSkeletons();

		document.addEventListener('click', e => {
			if (!e.target.closest('.user-dropdown')) document.getElementById('userDropdown')?.classList.remove('show');
		});

		// One box, whichever list the scope is on — the term carries across a scope switch,
		// because "szukam tego" does not stop being true when the object type changes.
		bindFileControls();
		// pt 6: same debounce for the purchases list.
		document.getElementById('premSearch')?.addEventListener('input', schedulePremiumSearch);
		document.getElementById('userSearch')?.addEventListener('input', () => loadUsers(1));

		// Live strength meter + requirement checklist + match for the manage-user (B2) and
		// create-user (B3) password fields — matching the account-tab / file-options UX.
		resetMuPw = wirePwStrength({ pass: 'muPassword', bar: 'muPwdBar', len: 'muReqLen', upper: 'muReqUpper', digit: 'muReqDigit', spec: 'muReqSpec', confirm: 'muPassword2', match: 'muPassMatch' });
		resetNewPw = wirePwStrength({ pass: 'newPassword', bar: 'newPwdBar', len: 'newReqLen', upper: 'newReqUpper', digit: 'newReqDigit', spec: 'newReqSpec', confirm: 'newPassword2', match: 'newPassMatch' });
		resetNewEmail = wireEmailValidation('newEmail', 'newEmailStatus');
		resetCcPw = wirePwStrength({ pass: 'ccPassword', bar: 'ccPwdBar', len: 'ccReqLen', upper: 'ccReqUpper', digit: 'ccReqDigit', spec: 'ccReqSpec', confirm: 'ccPassword2', match: 'ccPassMatch' });
		resetCsPw = wirePwStrength({ pass: 'csPassword', bar: 'csPwdBar', len: 'csReqLen', upper: 'csReqUpper', digit: 'csReqDigit', spec: 'csReqSpec', confirm: 'csPassword2', match: 'csPassMatch' });

		// Surface email-change results carried back as query params.
		const params = new URLSearchParams(window.location.search);
		if (params.get('msg') === 'email_changed') showNotification(t('panel.acct.email_changed'), 'success');
		if (params.has('err')) showNotification(t('panel.modal.bans_error', { msg: params.get('err') }), 'error');

		switch (PANEL.tab) {
			case 'dashboard':
				loadTopFilesPref();
				loadTrafficPref();
				loadDashboard();
				initActiveDownloadsLive();
				break;
			case 'files':
				initFilesTab();
				break;
			case 'users':
				loadUsers();
				break;
			case 'myfiles':
				initMyFilesTab();
				loadApiKeys();
				loadWebhooks();
				break;
			case 'notifications':
				window.FHPanelNotifications?.bindActions();
				window.FHPanelNotifications?.loadNotifications(1);
				window.FHPanelNotifications?.loadNotificationPrefs();
				break;
			case 'moderate':
				// One tab, five views — the sub-tab says which of them is on screen.
				if (PANEL.modTab === 'audit') loadAuditLog();
				else if (PANEL.modTab === 'reports') loadReports();
				else if (PANEL.modTab === 'premium') {
					// The admin's premium views, relocated here (runda 3). Each loader
					// no-ops unless its markup is on the page, so the sub-tab decides.
					loadPremiumOverview();
					loadPremiumPayments(1);
					loadPremiumSubscribers();
					loadMyPremium();
				}
				else if (PANEL.modTab === 'ads') {
					// The manager loads first: it carries the zone catalogue the other
					// renderers use for their labels.
					loadAdsManager().then(() => { loadAdsQueue(); loadAdsPackages(); });
					loadAdsStats();
					initAdUploader('adForm', adFormDims);
				}
				break;
			case 'premium':
				// Each loader no-ops unless its markup is on the page, so the sub-tab decides.
				loadPremiumOverview();
				loadPremiumPayments(1);
				loadPremiumSubscribers();
				loadMyPremium();
				break;
			case 'settings':
				if (document.getElementById('settingsGroupsBody')) loadSettingsGroups();
				if (document.getElementById('languagesBody')) loadLanguages();
				if (document.getElementById('plansBody')) { loadPlans(); loadPaymentPlugins(); loadPromoCodes(); }
				if (document.getElementById('notifDefaultsBody')) {
					window.FHPanelNotifications?.bindActions();
					window.FHPanelNotifications?.loadNotificationDefaults();
				}
				if (document.getElementById('adsEnabled')) loadAdsSettings();
				break;
			case 'myads':
				loadMyAds();
				initAdUploader('myAdForm', myAdFormDims);
				break;
			case 'user':
				loadUserStats();
				initPanelValidation();
				load2faStatus();
				break;
		}

		// pkt 1: the header can change language without a reload, but the tables on this page are
		// rendered by JS and still hold the old strings. Re-render the active tab once the new
		// dictionary is in place.
		document.addEventListener('fh:languagechange', () => {
			const refresh = liveRefresher();
			if (refresh) refresh();
		});

		initLiveRefresh();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	/* ------------------------------------------------------------------ *
	 * Explicit action surface consumed by the declarative event dispatcher.
	 * ------------------------------------------------------------------ */
	const publicActions = {
		openModal, closeModal, showModal, showNotification, flashMessage, toggleTheme, toggleUserDropdown, handleLogout,
		showAuthModal, hideAuthModal, copyUrl,
		sortBy, goPage, showDeleteFile, executeFileDelete,
		toggleFileSelect, toggleSelectAllFiles, bulkDeleteFiles,
		sortMyFiles, goMyPage, deleteMyFile, executeMyFileDelete, loadMyFiles, openFileOptions, saveFileOptions,
		loadUsers, sortUsers, userAction, executeUserAction, openBanModal, executeAdvancedBan,
		showCreateUserModal, createUser, loadIPBans, showAddBanForm, updateBanInputPlaceholder,
		openDocModal,
		submitPanelOnEnter,
		loadPlans, openPlanForm, savePlan, askDeletePlan, togglePlanEnabled, onPlanCheckoutTypeChange, onPlanKindChange, onPlanLimitsToggle,
		setPremiumRange, loadPremiumPayments, loadPremiumSubscribers, loadPremiumOverview, onGrantPlanChange, askPremiumRefund,
		openBulkPlanGrant, updateBulkSourceFields, previewBulkPlanGrant, executeBulkPlanGrant,
		loadPromoCodes, openPromoForm, onPromoScopeChange, savePromoForm, askDeletePromo,
		savePremiumSettings, regeneratePremiumToken, copyPremiumToken, openPlanGrant, grantPlan, revokePlan,
		loadPaymentPlugins, openPlugin, savePlugin, testPaymentPlugin,
		loadLanguages, toggleLanguage, askDeleteLanguage, exportLanguage, downloadLanguageTemplate,
		askDuplicateLanguage, submitDuplicateLanguage,
		openLanguageUpload, onLanguageFilePicked, submitLanguageUpload, saveUserLanguage,
		onLanguageCodeInput, pickLanguageCode,
		loadGroups, openGroupForm, saveGroup, deleteGroup, goGroupsPage, onPermToggle, onGroupRetentionToggle,
		openSetUserGroup, confirmSetUserGroup, renderGroupPreview, openManageUser, saveManageUser,
		onManageRoleChange,
		killDownload, killUpload, loadActiveDownloads, openCmdPalette,
		executeAddBan, unbanIP, cancelUnban, confirmUnban, loadFiles,
		loadReports, showReportDetails, showRejectReport, confirmRejectReport, showDeleteReported, confirmDeleteReported,
		rejectFromDetails, deleteFromDetails,
		loadAuditLog, loadDashboard,
		openTopFilesSettings, onTopFilesPeriodChange, applyTopFilesSettings, loadTopFiles,
		setTrafficRange, openTrafficRange, applyTrafficRange, loadTraffic,
		toggleEmailFields, syncEmailFromPrefix, syncEmailFromFull, revokeRememberDevices, toggleRecaptchaFields, confirmCleanup, previewCleanup, showConfirm, confirmAction,
		changeUserPassword, changeUserEmail, submitPasswordConfirm, confirmDeleteAllFiles, confirmDeleteAccount,
		toggleMyFileSelect, toggleSelectAllMyFiles, bulkDeleteMyFiles,
		toggleMyCollectionSelect, toggleSelectAllMyCollections, bulkDeleteMyCollections,
		openCreateCollection, submitCreateCollection, copyCollectionResult,
		openAddToCollection, renderAddToCollectionList, pickAddToCollection, submitAddToCollection,
		editCreatedCollection,
		loadCollections, copyCollectionUrl, askDeleteCollection, confirmDeleteCollection,
		loadAdminCollections, copyAdminCollectionUrl,
		openCollectionSettings, saveCollectionSettings, downloadCollection, submitCollectionZipPassword,
		csMoveFile, csRemoveFile,
		downloadFile, submitFileDownloadPassword, goCollectionsPage, goAdminCollectionsPage,
		openCollectionFromAll, continueLockedFiles,
		openFiltersModal, applyFilters, clearAllFilters, removeFilter, filterChips, toggleChip, reloadScopedList,
		openMyFiltersModal, applyMyFilters, clearAllMyFilters, removeMyFilter,
		setMyFilterScope, onMyEmptyCollectionsToggle,
		setFilterScope, onEmptyCollectionsToggle,
		toggleCollectionSelect, toggleSelectAllCollections, bulkDeleteCollections,
		openCreateApiKey, submitCreateApiKey, copyApiKey, downloadSharexConfig, loadApiKeys, askRevokeApiKey, confirmRevokeApiKey,
		openCreateWebhook, submitCreateWebhook, copyWebhookSecret, loadWebhooks, askDeleteWebhook, confirmDeleteWebhook,
		load2faStatus, start2faSetup, cancel2faSetup, confirm2faSetup, disable2fa,
		openRecoveryCodes, submitRecoveryCodes, copyRecoveryCodes, downloadRecoveryCodes,
		saveAdsSettings, loadAdsSettings, setAdsRange, loadAdsStats, loadAdsManager,
		openAdForm, adFormTypeChanged, saveAdForm, adAction, askDeleteAd,
		openZoneAssign, assignAdToZone, loadAdsQueue, approveAd, openAdReject, confirmAdReject,
		loadAdsPackages, openPackageForm, packageKindChanged, savePackageForm, askDeletePackage,
		loadMyAds, buyPackage, editMyAd, saveMyAdForm, payMyAd, toggleMyAdMetrics,
		openMyAdBoost, buyMyAdBoost,
		adCropClear, adCropApply, adCropCancel, adCropCenter, adUploaderRecrop,
		myAdAddonToggled, myAdAddonFilePicked, toggleMyAdPause,
		openMyAdRenew, confirmMyAdRenew
	};
	Object.assign(window, publicActions);
	window.FHPanelActions = Object.freeze(publicActions);
})();
