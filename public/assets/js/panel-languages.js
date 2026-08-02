(function () {
	'use strict';

	const t = (key, params) => window.t(key, params);
	const esc = window.FHUtil.esc;
	const showModal = (id) => window.showModal(id);
	const closeModal = (id) => window.closeModal(id);
	const showNotification = (message, type = 'success', anchor = null) =>
		window.showNotification(message, type, anchor);

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
	/* ------------------------------------------------------------------ *
	 * Language management (Faza 6 · #3) — install / enable / remove UI languages
	 * ------------------------------------------------------------------ */
	let languages = [];
	let knownLanguages = [];   // code → display-name table, for the add-language hints
	let pendingLangStrings = null; // parsed JSON from the picked file, until it is submitted

	async function loadLanguages() {
		const body = document.getElementById('languagesBody');
		if (!body) return;
		try {
			const d = await FHApi.get('admin_languages');
			languages = (d.success && d.languages) ? d.languages : [];
			knownLanguages = d.known || [];
			renderLanguages(d.default || 'pl');
			renderHeaderLangSwitch();
		} catch (e) {
			body.innerHTML = `<tr><td colspan="7" class="empty">${esc(t('common.connection_error'))}</td></tr>`;
		}
	}

	/**
	 * pt 2: repaint the header's own `.lang-switch` from the list we just loaded.
	 *
	 * The switcher is server-rendered from `Lang::forSwitcher()`, so flipping "In the header"
	 * used to change nothing visible until the next page load — the one place where the effect
	 * of the switch is supposed to be immediate. The freshly fetched rows already carry the
	 * post-save state (the endpoint invalidates the cache before answering), so this is a
	 * repaint of saved truth, not an optimistic guess.
	 *
	 * Mirrors header_ui.php: same order, same `?lang=` links, same `active` marker — and
	 * `ui.js` keeps handling the clicks, since it delegates from `document`.
	 */
	function renderHeaderLangSwitch() {
		const box = document.querySelector('.lang-switch');
		if (!box || !languages.length) return;

		const shown = languages.filter(l => l.enabled && l.switcher);
		if (!shown.length) return; // the server refuses to empty the list; don't invent an empty one

		const current = window.LANG;
		const url = new URL(window.location.href);
		box.innerHTML = shown.map(l => {
			url.searchParams.set('lang', l.code);
			return `<a href="${esc(url.pathname + url.search)}" class="lang-opt${l.code === current ? ' active' : ''}"
				hreflang="${esc(l.code)}" title="${esc(l.name)}">${esc(l.code.toUpperCase())}</a>`;
		}).join('');
	}

	function renderLanguages(defaultCode) {
		const body = document.getElementById('languagesBody');
		if (!body) return;
		if (!languages.length) {
			body.innerHTML = `<tr><td colspan="7" class="empty">—</td></tr>`;
			return;
		}
		// pt 6: one switch shape, three lists — installed/enabled, shown in the header switcher,
		// and offerable to users (which also bounds automatic Accept-Language matching).
		// `event.currentTarget` is passed on so the confirmation tooltip can be anchored to the
		// switch that was flipped, like every other bit of feedback in the panel (pkt 2).
		const sw = (code, scope, on) => `<label class="lang-toggle"><input type="checkbox" ${on ? 'checked' : ''}
			data-fh-change="toggleLanguage('${esc(code)}', this.checked, '${scope}')"><span></span></label>`;

		body.innerHTML = languages.map(l => {
			const badges =
				(l.builtIn ? ` <span class="badge badge-muted">${esc(t('panel.lang.builtin'))}</span>` : '') +
				(l.code === defaultCode ? ` <span class="badge badge-success">${esc(t('panel.lang.default'))}</span>` : '');
			// A built-in can be neither switched off nor removed — the UI would have nothing to
			// fall back to. Show a padlock rather than a control that always errors. Where it is
			// *shown* is a presentation choice, so those two columns stay editable for it.
			const toggle = l.builtIn
				? `<span class="action-btn locked" title="${esc(t('panel.lang.builtin_lock'))}"><i class="fa-solid fa-lock"></i></span>`
				: sw(l.code, 'enabled', l.enabled);
			const del = l.builtIn
				? ''
				: `<button class="action-btn del" data-fh-click="askDeleteLanguage('${esc(l.code)}', '${esc(l.name).replace(/'/g, "\\'")}')" title="${esc(t('common.delete'))}"><i class="fa-solid fa-trash"></i></button>`;
			return `<tr>
				<td><strong>${esc(l.name)}</strong>${badges}</td>
				<td><code>${esc(l.code)}</code></td>
				<td><div class="lang-coverage" title="${l.strings} / 100%">
						<i style="width:${Math.min(100, l.coverage)}%"></i></div>
					<small style="color:var(--text-muted)">${l.coverage}%</small></td>
				<td>${toggle}</td>
				<td>${l.enabled ? sw(l.code, 'switcher', l.switcher) : '<span class="lang-na">—</span>'}</td>
				<td>${l.enabled ? sw(l.code, 'users', l.users) : '<span class="lang-na">—</span>'}</td>
				<td><div class="actions">
					<button class="action-btn" data-fh-click="askDuplicateLanguage('${esc(l.code)}')" title="${esc(t('panel.lang.duplicate'))}"><i class="fa-solid fa-copy"></i></button>
					<button class="action-btn" data-fh-click="exportLanguage('${esc(l.code)}')" title="${esc(t('panel.lang.export'))}"><i class="fa-solid fa-download"></i></button>
					${del}
				</div></td>
			</tr>`;
		}).join('');
	}

	/**
	 * Flip one of a language's three lists. The switch itself is the feedback — the row is
	 * reloaded from the server afterwards, so what you see is the saved state. Only a failure
	 * is worth interrupting for.
	 */
	async function toggleLanguage(code, enabled, scope = 'enabled') {
		let error = null;
		try {
			const d = await FHApi.post('admin_language_toggle', { code, enabled, scope });
			if (!d.success) error = d.error || t('common.error');
		} catch (e) {
			error = t('common.connection_error');
		}
		await loadLanguages();
		if (error) showNotification(error, 'error');
	}

	/**
	 * pt 6: copy a language to a free code. This is the supported way to change the wording of a
	 * built-in — PL and EN are never overwritten, because every other translation falls back to
	 * them and a partial upload would hollow that fallback out.
	 */
	let duplicateLanguageSource = null;
	function askDuplicateLanguage(source) {
		duplicateLanguageSource = source;
		document.getElementById('ldSource').textContent = source.toUpperCase();
		document.getElementById('ldSourceBadge').textContent = source.toUpperCase();
		document.getElementById('ldCode').value = '';
		const msg = document.getElementById('languageDuplicateMessage');
		if (msg) { msg.textContent = ''; msg.className = 'auth-message'; }
		showModal('languageDuplicateModal');
		setTimeout(() => document.getElementById('ldCode').focus(), 60);
	}

	async function submitDuplicateLanguage() {
		const code = (document.getElementById('ldCode').value || '').trim().toLowerCase();
		if (!/^[a-z]{2,3}$/.test(code)) {
			flashMessage('languageDuplicateMessage', t('panel.lang.code_invalid'), 'error');
			return;
		}
		try {
			const d = await FHApi.post('admin_language_duplicate', { source: duplicateLanguageSource, code });
			if (d.success) {
				closeModal('languageDuplicateModal');
				showNotification(t('panel.lang.duplicated', { code: code.toUpperCase() }), 'success');
				loadLanguages();
			} else {
				flashMessage('languageDuplicateMessage', d.error || t('common.error'), 'error');
			}
		} catch (e) {
			flashMessage('languageDuplicateMessage', t('common.connection_error'), 'error');
		}
	}

	function askDeleteLanguage(code, name) {
		showConfirm(t('panel.lang.del_title'), t('panel.lang.del_confirm', { name: name }), async () => {
			try {
				const d = await FHApi.post('admin_language_delete', { code });
				if (d.success) showNotification(t('panel.lang.deleted'), 'success');
				else showNotification(d.error || t('common.error'), 'error');
			} catch (e) { showNotification(t('common.connection_error'), 'error'); }
			loadLanguages();
		});
	}

	/** Download a language as JSON — the starting point for translating it. */
	async function exportLanguage(code) {
		try {
			const d = await FHApi.get('admin_language_export', { code });
			if (!d || !d.success) { showNotification((d && d.error) || t('common.error'), 'error'); return; }
			const blob = new Blob([JSON.stringify(d.strings, null, 2)], { type: 'application/json' });
			const a = document.createElement('a');
			a.href = URL.createObjectURL(blob);
			a.download = `tryhackx-files-lang-${code}.json`;
			document.body.appendChild(a);
			a.click();
			document.body.removeChild(a);
			setTimeout(() => URL.revokeObjectURL(a.href), 1000);
		} catch (e) { showNotification(t('common.connection_error'), 'error'); }
	}

	function downloadLanguageTemplate() { exportLanguage('en'); }

	function openLanguageUpload() {
		document.getElementById('langCode').value = '';
		document.getElementById('langFile').value = '';
		document.getElementById('langFileInfo').textContent = t('panel.lang.file_hint');
		document.getElementById('langCodeName').textContent = '';
		pendingLangStrings = null;
		const msg = document.getElementById('languageUploadMessage');
		if (msg) { msg.textContent = ''; msg.className = 'auth-message'; }
		renderLanguageSuggestions();
		showModal('languageUploadModal');
	}

	/**
	 * Offer the codes the app already has a display name for and that are not installed yet.
	 * A code outside this list is still accepted — it just shows as its uppercased code in the
	 * switcher (Lang::available falls back to that), which the hint below makes explicit.
	 */
	function renderLanguageSuggestions() {
		const holder = document.getElementById('langSuggestions');
		if (!holder) return;
		const free = (knownLanguages || []).filter(l => !l.installed);
		if (!free.length) { holder.innerHTML = ''; return; }
		holder.innerHTML = `<span class="lang-suggest-label">${esc(t('panel.lang.suggestions'))}</span>` +
			free.map(l => `<button type="button" class="chip" data-fh-click="pickLanguageCode('${esc(l.code)}')">
				<strong>${esc(l.code)}</strong> ${esc(l.name)}</button>`).join('');
	}

	function pickLanguageCode(code) {
		document.getElementById('langCode').value = code;
		onLanguageCodeInput();
	}

	/** Resolve the typed code to a display name as the admin types it. */
	function onLanguageCodeInput() {
		const code = document.getElementById('langCode').value.trim().toLowerCase();
		const out = document.getElementById('langCodeName');
		if (!out) return;
		if (!code) { out.textContent = ''; out.className = 'lang-code-name'; return; }

		const known = (knownLanguages || []).find(l => l.code === code);
		// pt 6: the built-ins are never replaced by an upload — say so at the point of typing,
		// not after the file has been picked and sent.
		if ((languages || []).some(l => l.code === code && l.builtIn)) {
			out.textContent = t('panel.lang.code_builtin');
			out.className = 'lang-code-name warn';
		} else if (known && known.installed) {
			out.textContent = t('panel.lang.code_installed', { name: known.name });
			out.className = 'lang-code-name warn';
		} else if (known) {
			out.textContent = '→ ' + known.name;
			out.className = 'lang-code-name ok';
		} else if (/^[a-z]{2,3}$/.test(code)) {
			// Accepted, but it will display as the bare code until someone adds a name for it.
			out.textContent = t('panel.lang.code_unknown', { code: code.toUpperCase() });
			out.className = 'lang-code-name';
		} else {
			out.textContent = t('panel.lang.bad_code');
			out.className = 'lang-code-name warn';
		}
	}

	/** Parse the picked file in the browser so problems surface before anything is sent. */
	function onLanguageFilePicked() {
		const input = document.getElementById('langFile');
		const info = document.getElementById('langFileInfo');
		const file = input.files && input.files[0];
		pendingLangStrings = null;
		if (!file) { info.textContent = t('panel.lang.file_hint'); return; }

		const reader = new FileReader();
		reader.onload = () => {
			try {
				const parsed = JSON.parse(String(reader.result));
				// Accept either a bare map of strings or an export wrapped as {code, strings}.
				const strings = (parsed && typeof parsed === 'object' && parsed.strings) ? parsed.strings : parsed;
				const keys = Object.keys(strings || {});
				if (!keys.length) throw new Error('empty');
				pendingLangStrings = strings;
				info.textContent = t('panel.lang.file_ok', { n: keys.length });
				// A wrapped export carries its own code — save the admin retyping it.
				if (parsed && parsed.code && !document.getElementById('langCode').value) {
					document.getElementById('langCode').value = String(parsed.code).toLowerCase();
				}
			} catch (e) {
				info.textContent = t('panel.lang.file_bad');
			}
		};
		reader.readAsText(file);
	}

	async function submitLanguageUpload() {
		const code = document.getElementById('langCode').value.trim().toLowerCase();
		if (!/^[a-z]{2,3}$/.test(code)) { flashMessage('languageUploadMessage', t('panel.lang.bad_code'), 'error'); return; }
		if (!pendingLangStrings) { flashMessage('languageUploadMessage', t('panel.lang.need_file'), 'error'); return; }
		try {
			const d = await FHApi.post('admin_language_upload', { code, strings: pendingLangStrings });
			if (d.success) {
				showNotification(t('panel.lang.installed', { n: d.strings }), 'success');
				closeModal('languageUploadModal');
				loadLanguages();
			} else {
				flashMessage('languageUploadMessage', d.error || t('common.error'), 'error');
			}
		} catch (e) {
			flashMessage('languageUploadMessage', t('common.connection_error'), 'error');
		}
	}

	/** Account tab: persist the signed-in user's interface language. */
	async function saveUserLanguage() {
		const select = document.getElementById('acctLanguage');
		const language = select.value;
		try {
			const d = await FHApi.post('user_set_language', { language });
			// Anchored over the select, like every other confirmation in the panel, instead of
			// the default bottom-centre toast.
			if (d.success) {
				showNotification(t('panel.acct.language_saved'), 'success', select);
				// The whole UI is server-rendered, so reload to pick the new strings up.
				setTimeout(() => window.location.reload(), 900);
			} else {
				showNotification(d.error || t('common.error'), 'error', select);
			}
		} catch (e) { showNotification(t('common.connection_error'), 'error', select); }
	}

	window.FHPanelLanguages = Object.freeze({
		loadLanguages, toggleLanguage, askDeleteLanguage, exportLanguage,
		downloadLanguageTemplate, askDuplicateLanguage, submitDuplicateLanguage,
		openLanguageUpload, onLanguageFilePicked, submitLanguageUpload,
		saveUserLanguage, onLanguageCodeInput, pickLanguageCode
	});
}());
