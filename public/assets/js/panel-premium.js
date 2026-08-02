(function () {
	'use strict';

	const bootstrap = document.getElementById('panelBootstrap');
	let PANEL = {};
	try {
		PANEL = JSON.parse(bootstrap?.dataset.config || '{}');
	} catch {
		PANEL = {};
	}
	const apiUrl = PANEL.apiUrl || '';
	const appUrl = PANEL.appUrl || '';
	const panelPermissions = new Set(Array.isArray(PANEL.perms) ? PANEL.perms : []);
	const canPremiumRefund = panelPermissions.has('premium.refunds');
	const canPremiumGrant = Boolean(PANEL.isAdmin || panelPermissions.has('premium.grants'));
	const perPage = 20;
	const t = (key, params) => window.t(key, params);
	const esc = window.FHUtil.esc;
	const safeHttpUrl = window.FHUtil.safeHttpUrl;
	const formatSize = window.FHUtil.formatSize;
	const formatDate = window.FHUtil.formatDate;
	const showModal = (id) => window.showModal(id);
	const closeModal = (id) => window.closeModal(id);
	const showConfirm = (...args) => window.showConfirm(...args);
	const showNotification = (...args) => window.showNotification(...args);
	const flashMessage = (...args) => window.flashMessage(...args);
	const renderPager = (...args) => window.FHPanelCore.renderPager(...args);
	const refreshUsers = () => window.FHPanelCore.refreshUsers();
	/* ------------------------------------------------------------------ *
	 * Premium plans (pt 9)
	 *
	 * A plan is "buy this, get that group". The app never handles a payment: each plan carries
	 * the operator's own checkout — a link to send the buyer to, or a snippet to render in
	 * place — and the group is granted afterwards, either by the provider calling the
	 * activation endpoint or by an admin doing it here.
	 * ------------------------------------------------------------------ */
	let plans = [];
	let planGroups = [];

	async function loadPlans() {
		const body = document.getElementById('plansBody');
		if (!body) return;
		try {
			const d = await FHApi.get('admin_plans');
			if (!d.success) { body.innerHTML = `<tr><td colspan="7" class="empty">${esc(d.error || t('common.error'))}</td></tr>`; return; }
			plans = d.plans || [];
			planGroups = d.groups || [];
			renderPlans();
			fillPremiumSettings(d.settings || {}, d.activateUrl || '', !!d.hasToken);
		} catch (e) {
			body.innerHTML = `<tr><td colspan="7" class="empty">${esc(t('common.connection_error'))}</td></tr>`;
		}
	}

	function fillPremiumSettings(s, activateUrl, hasToken) {
		const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = v || ''; };
		const check = (id, v) => { const el = document.getElementById(id); if (el) el.checked = v === '1'; };
		check('premEnabled', s.premium_enabled);
		set('premTitle', s.premium_title);
		set('premIntro', s.premium_intro);
		set('premFooter', s.premium_footer);
		check('premShowHeader', s.premium_show_header);
		check('premShowHome', s.premium_show_home);
		check('premShowPanel', s.premium_show_panel);
		check('premInvoiceEnabled', s.invoice_enabled);
		set('premInvoiceSeller', s.invoice_seller);
		set('premInvoicePrefix', s.invoice_prefix);
		set('premInvoiceFooter', s.invoice_footer);
		set('premApiUrl', activateUrl);
		const sample = document.getElementById('premApiUrlSample');
		if (sample) sample.textContent = activateUrl;
		// The secret itself is never read back — only whether one exists (see the controller).
		const label = document.getElementById('premTokenBtnLabel');
		if (label) label.textContent = t(hasToken ? 'panel.prem.api_regenerate' : 'panel.prem.api_generate');
	}

	async function savePremiumSettings() {
		const val = (id) => document.getElementById(id)?.value || '';
		const on = (id) => document.getElementById(id)?.checked ? '1' : '0';
		try {
			const d = await FHApi.post('admin_premium_settings', {
				premium_enabled: on('premEnabled'),
				premium_title: val('premTitle'),
				premium_intro: val('premIntro'),
				premium_footer: val('premFooter'),
				premium_show_header: on('premShowHeader'),
				premium_show_home: on('premShowHome'),
				premium_show_panel: on('premShowPanel'),
				invoice_enabled: on('premInvoiceEnabled'),
				invoice_seller: val('premInvoiceSeller'),
				invoice_prefix: val('premInvoicePrefix').trim(),
				invoice_footer: val('premInvoiceFooter')
			});
			if (d.success) showNotification(t('panel.ctl.saved'), 'success');
			else flashMessage('premiumMessage', d.error || t('common.error'), 'error');
		} catch (e) { flashMessage('premiumMessage', t('common.connection_error'), 'error'); }
	}

	/** Issue a new activation secret. Shown once — the server stores it encrypted, never read back. */
	function regeneratePremiumToken() {
		const opts = { danger: false, icon: 'fa-key', confirmLabel: t('panel.prem.api_generate') };
		showConfirm(t('panel.prem.api_title'), t('panel.prem.api_regen_warn'), async () => {
			try {
				const d = await FHApi.post('admin_premium_token', {});
				if (!d.success) { flashMessage('premiumMessage', d.error || t('common.error'), 'error'); return; }
				const box = document.getElementById('premTokenBox');
				box.style.display = '';
				box.innerHTML = `<strong>${esc(t('panel.prem.api_token_once'))}</strong>
					<div class="prem-token-row">
						<code id="premTokenValue">${esc(d.token)}</code>
						<button type="button" class="btn btn-sm" data-fh-click="copyPremiumToken(event)">
							<i class="fa-solid fa-copy"></i> ${esc(t('common.copy'))}
						</button>
					</div>`;
				const label = document.getElementById('premTokenBtnLabel');
				if (label) label.textContent = t('panel.prem.api_regenerate');
				box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
			} catch (e) { flashMessage('premiumMessage', t('common.connection_error'), 'error'); }
		}, opts);
	}

	/** The token is shown once, so copying it has to be one click rather than a careful select. */
	function copyPremiumToken(ev) {
		const el = document.getElementById('premTokenValue');
		if (!el) return;
		navigator.clipboard.writeText(el.textContent).then(
			() => showNotification(t('common.copied'), 'copy', ev ? ev.currentTarget : null),
			() => showNotification(t('panel.coll.copy_failed'), 'error')
		);
	}

	/* ---- Payment plugins (pkt 5): presets that fill a plan's checkout fields ---- */
	let paymentPlugins = [];

	async function loadPaymentPlugins() {
		const grid = document.getElementById('pluginGrid');
		if (!grid) return;
		try {
			const d = await FHApi.get('admin_payment_plugins');
			paymentPlugins = (d.success && d.plugins) ? d.plugins : [];
		} catch (e) { paymentPlugins = []; }

		grid.innerHTML = paymentPlugins.map(p => {
			const filled = Object.values(p.values || {}).filter(v => v !== '').length;
			const total = Object.keys(p.fields || {}).length;
			const state = (total && filled === total)
				? `<span class="plugin-state ok"><i class="fa-solid fa-circle-check"></i> ${esc(t('panel.plug.ready'))}</span>`
				: `<span class="plugin-state">${esc(t('panel.plug.todo', { n: total - filled }))}</span>`;
			return `<button type="button" class="plugin-card" data-fh-click="openPlugin('${esc(p.id)}')">
				<span class="plugin-card-top">
					<i class="${esc(p.iconStyle)} ${esc(p.icon)}"></i>
					<span class="plugin-flags">
						${p.active ? `<span class="plugin-flag"><i class="fa-solid fa-check"></i> ${esc(t('panel.plug.active'))}</span>` : ''}
						${p.serverSide ? `<span class="plugin-flag" title="${esc(t('panel.plug.server_side_hint'))}">${esc(t('panel.plug.server_side'))}</span>` : ''}
					</span>
				</span>
				<span class="plugin-name">${esc(p.name)}</span>
				<span class="plugin-methods">${esc(p.methods)}</span>
				${state}
			</button>`;
		}).join('');
	}

	let currentPlugin = null;
	function openPlugin(id) {
		const p = paymentPlugins.find(x => x.id === id);
		if (!p) return;
		currentPlugin = p;

		document.getElementById('pluginName').textContent = p.name;
		document.getElementById('pluginIcon').className = `${p.iconStyle} ${p.icon}`;
		// The notes are our own copy, written as Markdown — rendered with the same minimal
		// renderer the docs viewer uses.
		document.getElementById('pluginNotes').innerHTML =
			window.FHPanelDocs.renderMarkdown(p.notes || '');

		const docs = document.getElementById('pluginDocs');
		const docsUrl = safeHttpUrl(p.docs);
		docs.style.display = docsUrl ? '' : 'none';
		if (docsUrl) docs.href = docsUrl;
		else docs.removeAttribute('href');

		const fields = Object.entries(p.fields || {});
		document.getElementById('pluginFields').innerHTML = fields.map(([key, label]) => `
			<div class="form-group">
				<label>${esc(label)}</label>
				<input type="text" class="plugin-field" data-field="${esc(key)}" value="${esc((p.values || {})[key] || '')}" autocomplete="off">
			</div>`).join('');
		const testButton = document.getElementById('pluginTestBtn');
		if (testButton) testButton.style.display = p.id === 'przelewy24' ? '' : 'none';

		const msg = document.getElementById('pluginMessage');
		if (msg) { msg.textContent = ''; msg.className = 'auth-message'; }
		showModal('pluginModal');
	}

	/** Save the plugin's credentials; with `apply`, carry its checkout straight into a new plan. */
	async function savePlugin(apply) {
		if (!currentPlugin) return;
		const values = {};
		document.querySelectorAll('#pluginFields .plugin-field').forEach(inp => {
			values[inp.dataset.field] = inp.value;
		});
		try {
			const d = await FHApi.post('admin_payment_plugin_save', { id: currentPlugin.id, values });
			if (!d.success) { flashMessage('pluginMessage', d.error || t('common.error'), 'error'); return; }
			closeModal('pluginModal');
			await loadPaymentPlugins();
			if (apply && d.checkout) {
				openPlanForm();
				document.getElementById('planCheckoutType').value = d.checkout.checkout_type;
				document.getElementById('planCheckoutUrl').value = d.checkout.checkout_url || '';
				document.getElementById('planCheckoutHtml').value = d.checkout.checkout_html || '';
				onPlanCheckoutTypeChange();
			} else {
				showNotification(t('panel.ctl.saved'), 'success');
			}
		} catch (e) { flashMessage('pluginMessage', t('common.connection_error'), 'error'); }
	}

	async function testPaymentPlugin() {
		if (!currentPlugin) return;
		const button = document.getElementById('pluginTestBtn');
		if (button) button.disabled = true;
		try {
			const d = await FHApi.post('admin_payment_plugin_test', { id: currentPlugin.id });
			flashMessage(
				'pluginMessage',
				(d && (d.message || d.error)) || t('panel.plug.test_failed'),
				d && d.success ? 'success' : 'error'
			);
		} catch (e) {
			flashMessage('pluginMessage', t('common.connection_error'), 'error');
		} finally {
			if (button) button.disabled = false;
		}
	}

	function renderPlans() {
		const body = document.getElementById('plansBody');
		if (!body) return;
		if (!plans.length) {
			body.innerHTML = `<tr><td colspan="7" class="empty">${esc(t('panel.prem.none'))}</td></tr>`;
			return;
		}
		body.innerHTML = plans.map(p => {
			const group = p.group_name
				? `<span class="owner-name">${esc(p.group_name)}</span>`
				: `<span class="badge badge-danger">${esc(t('panel.prem.no_group'))}</span>`;
			const duration = Number(p.duration_days) > 0
				? t('panel.prem.days', { n: p.duration_days })
				: t('panel.prem.forever');
			const kind = p.kind && p.kind !== 'paid'
				? ` <span class="badge badge-muted">${esc(t('panel.prem.badge_' + p.kind))}</span>`
				: '';
			// A built-in card can be switched off but not removed, so it gets a lock where the
			// bin would be rather than a button that answers "no".
			const system = Number(p.is_system)
				? ` <span class="badge badge-muted" title="${esc(t('panel.prem.system_hint'))}"><i class="fa-solid fa-lock"></i> ${esc(t('panel.prem.system'))}</span>`
				: '';
			const del = Number(p.is_system)
				? ''
				: `<button class="action-btn del" data-fh-click="askDeletePlan(${p.id}, '${esc(p.name).replace(/'/g, "\\'")}')" title="${esc(t('common.delete'))}"><i class="fa-solid fa-trash"></i></button>`;
			return `<tr>
				<td><strong>${esc(p.name)}</strong>${kind}${system}${Number(p.highlighted) ? ` <span class="badge badge-success">${esc(t('panel.prem.highlighted'))}</span>` : ''}</td>
				<td>${group}</td>
				<td>${esc(p.price || '—')} <small style="color:var(--text-muted)">${esc(p.period || '')}</small></td>
				<td>${esc(duration)}</td>
				<td>${esc(t('panel.prem.checkout_' + (p.checkout_type === 'html' ? 'html' : p.checkout_type === 'none' ? 'none' : 'link')))}</td>
				<td><label class="lang-toggle"><input type="checkbox" ${Number(p.enabled) ? 'checked' : ''}
					data-fh-change="togglePlanEnabled(${p.id}, this.checked)"><span></span></label></td>
				<td><div class="actions">
					<button class="action-btn" data-fh-click="openPlanForm(${p.id})" title="${esc(t('common.edit'))}"><i class="fa-solid fa-pen"></i></button>
					${del}
				</div></td>
			</tr>`;
		}).join('');
	}

	function openPlanForm(id = null) {
		const p = id ? plans.find(x => Number(x.id) === Number(id)) : null;
		document.getElementById('planModalTitle').textContent = t(p ? 'panel.prem.edit' : 'panel.prem.add');
		const set = (elId, v) => { const el = document.getElementById(elId); if (el) el.value = v === null || v === undefined ? '' : v; };

		document.getElementById('planGroup').innerHTML =
			`<option value="">${esc(t('panel.prem.no_group'))}</option>` +
			planGroups.map(g => `<option value="${g.id}">${esc(g.name)}</option>`).join('');

		set('planId', p ? p.id : '');
		set('planName', p ? p.name : '');
		set('planGroup', p ? (p.group_id || '') : '');
		set('planPrice', p ? p.price : '');
		set('planPeriod', p ? p.period : '');
		set('planAmountMinor', p ? (p.amount_minor || '') : '');
		set('planCurrency', p ? (p.currency || 'PLN') : 'PLN');
		set('planDuration', p ? p.duration_days : '');
		set('planBadge', p ? p.badge : '');
		set('planDescFormat', p ? p.description_format : 'markdown');
		set('planDescription', p ? p.description : '');
		set('planFeatures', p ? p.features : '');
		set('planCheckoutType', p ? p.checkout_type : 'link');
		set('planCheckoutUrl', p ? p.checkout_url : '');
		set('planCheckoutHtml', p ? p.checkout_html : '');
		set('planButtonLabel', p ? p.button_label : '');
		set('planSortOrder', p ? p.sort_order : 0);
		set('planKind', p ? (p.kind || 'paid') : 'paid');
		document.getElementById('planHighlighted').checked = p ? !!Number(p.highlighted) : false;
		document.getElementById('planEnabled').checked = p ? !!Number(p.enabled) : false;
		document.getElementById('planShowLimits').checked = p ? !!Number(p.show_limits) : false;
		const selectedLimitFields = new Set(
			String(p ? (p.limit_fields || '') : 'quota,file,files,concurrent,retention,transfer')
				.split(',').filter(Boolean)
		);
		document.querySelectorAll('.plan-limit-field').forEach(box => {
			box.checked = selectedLimitFields.has(box.value);
		});
		document.getElementById('planAutoContent').checked = p ? !!Number(p.auto_content) : true;

		onPlanKindChange();
		onPlanLimitsToggle();
		onPlanCheckoutTypeChange();
		const msg = document.getElementById('planMessage');
		if (msg) { msg.textContent = ''; msg.className = 'auth-message'; }
		showModal('planModal');
	}

	/**
	 * A showcase card has nothing to sell, so the half of this form that is about selling goes
	 * away rather than sitting there inviting numbers the server will overwrite with zero.
	 * Showing the group's limits is switched on by default for those two kinds, because a card
	 * that says nothing about what it gives is the reason they exist.
	 */
	function onPlanKindChange() {
		const kind = document.getElementById('planKind').value;
		const paid = kind === 'paid';
		document.querySelectorAll('#planModal [data-plan-sale]').forEach(el => {
			el.style.display = paid ? '' : 'none';
		});
		const hint = document.getElementById('planKindHint');
		if (hint) hint.textContent = t('panel.prem.kind_hint_' + kind);
		if (!paid) document.getElementById('planShowLimits').checked = true;
		onPlanLimitsToggle();
		if (paid) onPlanCheckoutTypeChange();

		// Automatic mode belongs to the showcase kinds only — there is nothing to derive a
		// price or a sales pitch from. When it is on, the fields it replaces step aside rather
		// than sitting there inviting text the page will not print.
		const autoRow = document.getElementById('planAutoRow');
		const autoBox = document.getElementById('planAutoContent');
		if (autoRow) autoRow.style.display = paid ? 'none' : '';
		const auto = !paid && autoBox && autoBox.checked;
		document.querySelectorAll('#planModal [data-plan-auto]').forEach(el => {
			el.style.display = auto ? 'none' : '';
		});
		const note = document.getElementById('planAutoNote');
		if (note) note.style.display = auto ? '' : 'none';
	}

	function onPlanLimitsToggle() {
		const row = document.getElementById('planLimitFields');
		if (row) row.style.display = document.getElementById('planShowLimits').checked ? '' : 'none';
	}

	function onPlanCheckoutTypeChange() {
		const type = document.getElementById('planCheckoutType').value;
		document.getElementById('planCheckoutUrlRow').style.display = type === 'link' ? '' : 'none';
		document.getElementById('planCheckoutHtmlRow').style.display = type === 'html' ? '' : 'none';
		// pt 10: the built-in checkout charges `amount_minor`, so that field stops being
		// optional the moment it is picked — a plan selling for 0 would be a free plan.
		const hint = document.getElementById('planBuiltinHint');
		if (hint) hint.style.display = type === 'builtin' ? '' : 'none';
	}

	async function savePlan() {
		const val = (id) => document.getElementById(id).value;
		const body = {
			id: parseInt(val('planId')) || 0,
			name: val('planName').trim(),
			kind: val('planKind'),
			show_limits: document.getElementById('planShowLimits').checked,
			limit_fields: Array.from(document.querySelectorAll('.plan-limit-field:checked'))
				.map(box => box.value),
			auto_content: document.getElementById('planAutoContent').checked,
			group_id: parseInt(val('planGroup')) || 0,
			price: val('planPrice').trim(),
			period: val('planPeriod').trim(),
			amount_minor: parseInt(val('planAmountMinor')) || 0,
			currency: val('planCurrency').trim().toUpperCase() || 'PLN',
			duration_days: parseInt(val('planDuration')) || 0,
			description: val('planDescription'),
			description_format: val('planDescFormat'),
			features: val('planFeatures'),
			badge: val('planBadge').trim(),
			checkout_type: val('planCheckoutType'),
			checkout_url: val('planCheckoutUrl').trim(),
			checkout_html: val('planCheckoutHtml'),
			button_label: val('planButtonLabel').trim(),
			highlighted: document.getElementById('planHighlighted').checked,
			enabled: document.getElementById('planEnabled').checked,
			sort_order: parseInt(val('planSortOrder')) || 0
		};
		try {
			const d = await FHApi.post('admin_plan_save', body);
			if (d.success) {
				closeModal('planModal');
				showNotification(t('panel.ctl.saved'), 'success');
				loadPlans();
			} else {
				flashMessage('planMessage', d.error || t('common.error'), 'error');
			}
		} catch (e) { flashMessage('planMessage', t('common.connection_error'), 'error'); }
	}

	async function togglePlanEnabled(id, enabled) {
		const p = plans.find(x => Number(x.id) === Number(id));
		if (!p) return;
		try {
			const d = await FHApi.post('admin_plan_save', Object.assign({}, p, { id, enabled }));
			if (!d.success) showNotification(d.error || t('common.error'), 'error');
		} catch (e) { showNotification(t('common.connection_error'), 'error'); }
		loadPlans();
	}

	function askDeletePlan(id, name) {
		showConfirm(t('panel.prem.del_title'), t('panel.prem.del_confirm', { name }), async () => {
			try {
				const d = await FHApi.post('admin_plan_delete', { id });
				if (d.success) showNotification(t('panel.prem.deleted'), 'success');
				else showNotification(d.error || t('common.error'), 'error');
			} catch (e) { showNotification(t('common.connection_error'), 'error'); }
			loadPlans();
		});
	}

	/* ---- Granting a plan by hand, from the Users tab ---- */
	let grantUserId = null;

	async function openPlanGrant(userId, username) {
		grantUserId = userId;
		document.getElementById('pgUserName').textContent = username;
		const msg = document.getElementById('planGrantMessage');
		if (msg) { msg.textContent = ''; msg.className = 'auth-message'; }
		if (!plans.length) {
			try {
				const d = await FHApi.get('admin_plans');
				if (d.success) { plans = d.plans || []; planGroups = d.groups || []; }
			} catch (e) { /* the select just stays empty */ }
		}
		document.getElementById('pgPlan').innerHTML =
			plans.map(p => `<option value="${p.id}">${esc(p.name)}</option>`).join('');
		document.getElementById('pgDays').value = '';
		document.getElementById('pgNotify').checked = true;
		onGrantPlanChange();
		showModal('planGrantModal');
	}

	/**
	 * Say what leaving the duration blank will actually do (pt 3).
	 *
	 * The field means three things — blank, a number, and zero — and only one of them can be
	 * shown in the box at a time, so the hint carries the other two. It follows the selected
	 * plan, because "as the plan says" is a different number for each of them.
	 */
	function onGrantPlanChange() {
		const hint = document.getElementById('pgDaysHint');
		const box = document.getElementById('pgDays');
		if (!hint || !box) return;
		const planId = parseInt(document.getElementById('pgPlan').value) || 0;
		const plan = plans.find(p => Number(p.id) === planId);
		const planDays = plan ? Number(plan.duration_days) || 0 : 0;
		box.placeholder = planDays > 0 ? String(planDays) : '0';
		hint.textContent = planDays > 0
			? t('panel.prem.grant_days_hint', { n: planDays })
			: t('panel.prem.grant_days_hint_forever');
	}

	async function grantPlan() {
		const planId = parseInt(document.getElementById('pgPlan').value) || 0;
		if (!planId) { flashMessage('planGrantMessage', t('panel.prem.grant_pick'), 'error'); return; }
		// An empty box means "as the plan says"; a typed 0 means "no expiry". They are
		// different instructions, so the key is only sent when something was actually typed.
		const raw = document.getElementById('pgDays').value.trim();
		const body = { user_id: grantUserId, plan_id: planId, notify: document.getElementById('pgNotify').checked };
		if (raw !== '') body.duration_days = parseInt(raw) || 0;
		try {
			const d = await FHApi.post('admin_plan_grant', body);
			if (d.success) {
				closeModal('planGrantModal');
				showNotification(d.expires_at
					? t('panel.prem.granted_until', { date: formatDate(d.expires_at) })
					: t('panel.prem.granted'), 'success');
				refreshUsers();
				if (document.getElementById('premSubsBody')) loadPremiumSubscribers();
				if (document.getElementById('premPaymentsBody')) loadPremiumPayments(1);
			} else {
				flashMessage('planGrantMessage', d.error || t('common.error'), 'error');
			}
		} catch (e) { flashMessage('planGrantMessage', t('common.connection_error'), 'error'); }
	}

	async function revokePlan() {
		try {
			const d = await FHApi.post('admin_plan_grant', {
				user_id: grantUserId, revoke: true,
				notify: document.getElementById('pgNotify').checked
			});
			if (d.success) {
				closeModal('planGrantModal');
				showNotification(t('panel.prem.revoked'), 'success');
				refreshUsers();
				if (document.getElementById('premSubsBody')) loadPremiumSubscribers();
				if (document.getElementById('premPaymentsBody')) loadPremiumPayments(1);
			} else {
				flashMessage('planGrantMessage', d.error || t('common.error'), 'error');
			}
		} catch (e) { flashMessage('planGrantMessage', t('common.connection_error'), 'error'); }
	}

	/* ------------------------------------------------------------------ *
	 * Premium tab (pt 6)
	 *
	 * Two audiences behind one tab: the operator's sales figures and the account's own plan.
	 * Which half loads is decided by which markup the server rendered, so there is no second
	 * copy of the "am I an admin" rule here — the elements simply are not on the page.
	 * ------------------------------------------------------------------ */
	let premiumDays = 30;
	let premPaymentsPage = 1;
	let premSearchTimer = null;
	let bulkPlans = [];
	let bulkGroups = [];
	let bulkPreviewNonce = '';

	/** Minor units → a readable amount. Never a float in the arithmetic, only in the output. */
	function formatMoney(minor, currency) {
		const n = (Number(minor) || 0) / 100;
		return n.toLocaleString(window.LANG || 'pl', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
			+ ' ' + (currency || '');
	}

	/**
	 * What kind of entry this is (pt 2/5). A purchase needs no label — it is the ordinary case
	 * and the amount already says so; the admin ones do, because "0,00 PLN · paid" on its own
	 * would read as a broken purchase rather than as a gift.
	 */
	function paymentKindBadge(kind, actor) {
		if (kind === 'admin_grant') {
			return `<span class="badge badge-info" title="${esc(actor ? t('panel.prem.by_admin', { name: actor }) : '')}">`
				+ `<i class="fa-solid fa-gift"></i> ${esc(t('panel.prem.kind_grant'))}</span>`;
		}
		if (kind === 'admin_revoke') {
			return `<span class="badge badge-muted" title="${esc(actor ? t('panel.prem.by_admin', { name: actor }) : '')}">`
				+ `<i class="fa-solid fa-ban"></i> ${esc(t('panel.prem.kind_revoke'))}</span>`;
		}
		return '';
	}

	/** The badge for a payment's state — the same vocabulary the API stores. */
	function paymentStatusBadge(status) {
		const map = {
			COMPLETED: ['badge-success', 'panel.prem.status_completed'],
			PENDING: ['badge-info', 'panel.prem.status_pending'],
			NEW: ['badge-info', 'panel.prem.status_pending'],
			CANCELED: ['badge-muted', 'panel.prem.status_canceled'],
			REFUNDING: ['badge-info', 'panel.prem.status_refunding'],
			REFUNDED: ['badge-danger', 'panel.prem.status_refunded']
		};
		const [cls, key] = map[status] || ['badge-muted', 'panel.prem.status_canceled'];
		return `<span class="badge ${cls}">${esc(t(key))}</span>`;
	}

	function setPremiumRange(days) {
		premiumDays = days;
		document.querySelectorAll('#premRange .scope-btn').forEach(b => {
			b.classList.toggle('active', Number(b.dataset.days) === days);
		});
		loadPremiumOverview();
	}

	async function loadPremiumOverview() {
		if (!document.getElementById('premRevenue')) return;
		try {
			const d = await FHApi.get('premium_overview', { days: premiumDays });
			if (!d || !d.success) return;
			renderPremiumStats(d.stats);
			renderPremiumChart(d.series);
			renderPremiumPlans(d.plans || []);
		} catch (e) { /* the tiles keep their placeholders */ }
	}

	function renderPremiumStats(s) {
		const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
		// Revenue is per currency and never summed across them — 19 PLN + 19 EUR is not 38 of
		// anything. One currency shows as a figure; several stack in the tile.
		const rev = s.revenue || [];
		set('premRevenue', rev.length ? rev.map(r => formatMoney(r.minor, r.currency)).join(' · ') : formatMoney(0, ''));
		set('premOrders', s.orders || 0);
		set('premBuyers', s.buyers || 0);
		set('premActive', s.active || 0);
		set('premPending', s.pending || 0);
		set('premCanceled', s.canceled || 0);

		const main = rev[0];
		set('premAvg', main && main.orders ? formatMoney(Math.round(main.minor / main.orders), main.currency) : '—');

		// Of the checkouts that were started in the window, how many were paid for.
		const started = (s.orders || 0) + (s.pending || 0) + (s.canceled || 0);
		set('premConv', started ? Math.round(((s.orders || 0) / started) * 100) + '%' : '—');
	}

	function renderPremiumPlans(plans) {
		const body = document.getElementById('premPlansBody');
		if (!body) return;
		if (!plans.length) {
			body.innerHTML = `<tr><td colspan="4" class="empty">${esc(t('panel.prem.no_plans'))}</td></tr>`;
			return;
		}
		body.innerHTML = plans.map(p => `<tr>
			<td><strong>${esc(p.name)}</strong></td>
			<td>${p.amountMinor ? esc(formatMoney(p.amountMinor, p.currency)) : '—'}</td>
			<td>${p.durationDays ? esc(t('panel.prem.days', { n: p.durationDays })) : esc(t('panel.prem.forever'))}</td>
			<td>${p.enabled
				? `<span class="badge badge-success">${esc(t('panel.users.active'))}</span>`
				: `<span class="badge badge-muted">${esc(t('panel.users.inactive'))}</span>`}</td>
		</tr>`).join('');
	}

	/**
	 * Revenue per day.
	 *
	 * Same SVG-by-hand approach as the traffic chart — the project ships no chart library and
	 * one bar series does not justify adding one. Bars rather than a line: a day with no sales
	 * is a real zero, and a line would draw a slope through it as if something happened.
	 */
	function renderPremiumChart(series) {
		const holder = document.getElementById('premChart');
		if (!holder || !series) return;
		const days = series.days || [];
		const revenue = series.revenue || [];
		const meta = document.getElementById('premChartMeta');
		if (meta) meta.textContent = t('panel.prem.chart_meta', { currency: series.currency || '' });

		if (!days.length) { holder.textContent = t('panel.dash.no_data'); return; }

		const W = 640, H = 200, padL = 52, padR = 16, padT = 16, padB = 26;
		const plotW = W - padL - padR, plotH = H - padT - padB;
		const base = padT + plotH;
		const max = Math.max(1, ...revenue);
		const bw = plotW / days.length;
		const gap = Math.min(8, bw * 0.3);
		const y = v => padT + plotH - (v / max) * plotH;

		let grid = '';
		const levels = 4;
		for (let i = 0; i <= levels; i++) {
			const val = (max / levels) * i;
			const gy = y(val);
			const dash = i === levels ? ' stroke-dasharray="5 4"' : (i === 0 ? '' : ' stroke-dasharray="2 5"');
			grid += `<line x1="${padL}" y1="${gy}" x2="${W - padR}" y2="${gy}" stroke="var(--border)" stroke-width="1"${dash}/>`;
			grid += `<text x="${padL - 8}" y="${gy + 3}" font-size="9" fill="var(--text-muted)" text-anchor="end">${val.toFixed(val < 10 ? 1 : 0)}</text>`;
		}
		grid += `<line x1="${padL}" y1="${base}" x2="${W - padR}" y2="${base}" stroke="var(--border)" stroke-width="1.5"/>`;

		const every = Math.max(1, Math.ceil(days.length / 12));
		let bars = '';
		days.forEach((day, i) => {
			const v = revenue[i] || 0;
			const x = padL + i * bw;
			const w = Math.max(1, bw - gap);
			const by = y(v);
			bars += `<rect x="${x + gap / 2}" y="${by}" width="${w}" height="${Math.max(0, base - by)}" rx="${w > 4 ? 3 : 1}" fill="url(#gPrem)">`
				+ `<title>${esc(day)} · ${esc(v.toFixed(2))} ${esc(series.currency || '')} · ${(series.orders || [])[i] || 0}×</title></rect>`;
			if (i % every === 0) {
				bars += `<text x="${x + bw / 2}" y="${H - 8}" font-size="10" fill="var(--text-muted)" text-anchor="middle">${esc(day.slice(5))}</text>`;
			}
		});

		holder.innerHTML = `<svg viewBox="0 0 ${W} ${H}" width="100%" preserveAspectRatio="xMidYMid meet" role="img" aria-label="${esc(t('panel.prem.chart_title'))}">
			<defs><linearGradient id="gPrem" x1="0" y1="0" x2="0" y2="1">
				<stop offset="0" stop-color="var(--accent)" stop-opacity="0.95"/>
				<stop offset="1" stop-color="#8b5cf6" stop-opacity="0.5"/>
			</linearGradient></defs>
			${grid}${bars}
		</svg>`;
	}

	async function loadPremiumPayments(page = premPaymentsPage) {
		const body = document.getElementById('premPaymentsBody');
		if (!body) return;
		premPaymentsPage = Math.max(1, page);
		const params = {
			page: premPaymentsPage,
			per_page: perPage,
			status: document.getElementById('premStatus')?.value || '',
			search: document.getElementById('premSearch')?.value || ''
		};
		try {
			const d = await FHApi.get('premium_payments', params);
			const list = (d && d.success) ? d.payments : [];
			if (!list.length) {
				body.innerHTML = `<tr><td colspan="6" class="empty">${esc(t('panel.prem.no_payments'))}</td></tr>`;
				document.getElementById('premPaymentsPagination').innerHTML = '';
				return;
			}
			body.innerHTML = list.map(p => {
				const kind = paymentKindBadge(p.kind, p.actor_name);
				const isAdmin = p.kind === 'admin_grant' || p.kind === 'admin_revoke';
				return `<tr>
				<td>${formatDate(p.created_at)}</td>
				<td>${p.username
					? `<strong>${esc(p.username)}</strong><br><small style="color:var(--text-muted)">${esc(p.email || '')}</small>`
					: `<span class="badge badge-muted">${esc(t('panel.coll.owner_deleted'))}</span>`}</td>
				<td>${esc(p.plan_name || '—')} ${kind}</td>
				<td>${esc(formatMoney(p.amount_minor, p.currency))}</td>
				<td>${paymentStatusBadge(p.status)}</td>
				<td>${isAdmin
					? `<span style="color:var(--text-muted); font-size:0.8rem;">${esc(p.actor_name ? t('panel.prem.by_admin', { name: p.actor_name }) : '—')}</span>`
					: `<div class="premium-order-cell">
						<code>${esc(p.provider_order_id || p.ext_order_id)}</code>
						<span class="premium-order-actions">${canPremiumRefund && p.status === 'COMPLETED' && p.kind === 'purchase' && ['payu', 'przelewy24'].includes(p.provider) && p.provider_order_id
							? `<button class="action-btn" data-fh-click="askPremiumRefund('${esc(p.ext_order_id)}')" title="${esc(t('panel.prem.refund_btn'))}"><i class="fa-solid fa-rotate-left"></i></button>`
							: ''}${d.invoicesEnabled && ['COMPLETED', 'REFUNDED'].includes(p.status)
							? `<a class="action-btn" href="${apiUrl}?action=invoice&order=${encodeURIComponent(p.ext_order_id)}" target="_blank" rel="noopener noreferrer" title="${esc(t('panel.prem.invoice_btn'))}"><i class="fa-solid fa-file-invoice"></i></a>`
							: ''}</span>
					</div>`}</td>
			</tr>`;
			}).join('');
			renderPager(document.getElementById('premPaymentsPagination'),
				Math.ceil((d.total || 0) / perPage), d.total || 0, premPaymentsPage, 'loadPremiumPayments',
				t('panel.prem.pager_label'));
		} catch (e) {
			body.innerHTML = `<tr><td colspan="6" class="empty">${esc(t('common.connection_error'))}</td></tr>`;
		}
	}

	/* ---- Promo codes (runda 9): percent-off for the native payment checkout ---- */
	let promoCodes = [];

	async function loadPromoCodes() {
		const body = document.getElementById('promoBody');
		if (!body) return;
		try {
			const d = await FHApi.get('admin_promo_codes');
			promoCodes = (d && d.success) ? d.codes : [];
			if (!promoCodes.length) {
				body.innerHTML = `<tr><td colspan="7" class="empty">${esc(t('panel.promo.none'))}</td></tr>`;
				return;
			}
			body.innerHTML = promoCodes.map(c => `<tr>
				<td><code>${esc(c.code)}</code></td>
				<td>${c.scope === 'plan'
					? `<span class="badge badge-info">${esc(c.planName || t('panel.promo.scope_deleted'))}</span>`
					: `<span class="badge badge-muted">${esc(t('panel.promo.scope_all'))}</span>`}</td>
				<td>−${c.percentOff}%</td>
				<td>${c.usedCount}${c.maxUses ? ' / ' + c.maxUses : ''}</td>
				<td>${c.expiresAt ? formatDate(c.expiresAt).split(' ')[0] : '∞'}</td>
				<td>${c.enabled
					? `<span class="badge badge-success">${esc(t('panel.users.active'))}</span>`
					: `<span class="badge badge-muted">${esc(t('panel.users.inactive'))}</span>`}</td>
				<td><div class="actions">
					<button class="action-btn" data-fh-click="openPromoForm(${c.id})" title="${esc(t('common.edit'))}"><i class="fa-solid fa-pen"></i></button>
					<button class="action-btn del" data-fh-click="askDeletePromo(${c.id})" title="${esc(t('common.delete'))}"><i class="fa-solid fa-trash"></i></button>
				</div></td>
			</tr>`).join('');
		} catch (e) {
			body.innerHTML = `<tr><td colspan="7" class="empty">${esc(t('common.connection_error'))}</td></tr>`;
		}
	}

	async function openPromoForm(id = null) {
		if (!plans.length) await loadPlans();
		const c = id ? promoCodes.find(x => x.id === id) : null;
		document.getElementById('promoFormTitle').textContent = t(c ? 'panel.promo.form_edit' : 'panel.promo.form_title');
		document.getElementById('promoFormMessage').className = 'auth-message';
		document.getElementById('promoFormId').value = c ? c.id : '';
		document.getElementById('promoFormCode').value = c ? c.code : '';
		document.getElementById('promoFormScope').value = c && c.scope === 'plan' ? 'plan' : 'all';
		const planSelect = document.getElementById('promoFormPlan');
		planSelect.innerHTML = `<option value="">${esc(t('panel.promo.plan_required'))}</option>` +
			plans.filter(p => (p.kind || 'paid') === 'paid')
				.map(p => `<option value="${p.id}">${esc(p.name)}</option>`).join('');
		planSelect.value = c && c.planId ? String(c.planId) : '';
		document.getElementById('promoFormPercent').value = c ? c.percentOff : 10;
		document.getElementById('promoFormMaxUses').value = c ? c.maxUses : 0;
		document.getElementById('promoFormExpires').value = c && c.expiresAt
			? new Date(c.expiresAt * 1000).toISOString().slice(0, 10) : '';
		document.getElementById('promoFormEnabled').checked = c ? !!c.enabled : true;
		onPromoScopeChange();
		openModal('promoFormModal');
	}

	function onPromoScopeChange() {
		const scoped = document.getElementById('promoFormScope')?.value === 'plan';
		const planGroup = document.getElementById('promoFormPlanGroup');
		if (planGroup) planGroup.style.display = scoped ? '' : 'none';
	}

	async function savePromoForm() {
		try {
			const scope = document.getElementById('promoFormScope').value;
			const d = await FHApi.post('admin_promo_code_save', {
				id: parseInt(document.getElementById('promoFormId').value, 10) || 0,
				code: document.getElementById('promoFormCode').value.trim(),
				scope,
				plan_id: scope === 'plan'
					? (parseInt(document.getElementById('promoFormPlan').value, 10) || 0)
					: 0,
				percent_off: parseInt(document.getElementById('promoFormPercent').value, 10) || 10,
				max_uses: parseInt(document.getElementById('promoFormMaxUses').value, 10) || 0,
				expires_at: document.getElementById('promoFormExpires').value,
				enabled: document.getElementById('promoFormEnabled').checked
			});
			if (!d.success) { flashMessage('promoFormMessage', d.error || t('common.error'), 'error'); return; }
			closeModal('promoFormModal');
			showNotification(t('panel.ctl.saved'), 'success');
			loadPromoCodes();
		} catch (e) { flashMessage('promoFormMessage', t('common.connection_error'), 'error'); }
	}

	function askDeletePromo(id) {
		const c = promoCodes.find(x => x.id === id);
		showConfirm(t('panel.promo.delete_title'), t('panel.promo.delete_q', { code: c ? c.code : '#' + id }), async () => {
			try {
				const d = await FHApi.post('admin_promo_code_delete', { id: id });
				if (!d.success) { showNotification(d.error || t('common.error'), 'error'); return; }
				showNotification(t('panel.ctl.saved'), 'success');
				loadPromoCodes();
			} catch (e) { showNotification(t('common.connection_error'), 'error'); }
		});
	}

	/** Refund a completed PayU plan purchase; revokes the plan when the buyer still holds it. */
	function askPremiumRefund(extOrderId) {
		showConfirm(t('panel.prem.refund_btn'), t('panel.prem.refund_q'), async () => {
			try {
				const d = await FHApi.post('admin_premium_refund', { ext_order_id: extOrderId });
				if (!d.success) { showNotification(d.error || t('common.error'), 'error'); return; }
				showNotification(t(d.pending ? 'panel.ads.refund_pending' : 'panel.ads.refund_ok'), 'success');
				loadPremiumPayments();
				loadPremiumSubscribers();
			} catch (e) { showNotification(t('common.connection_error'), 'error'); }
		}, { danger: true, icon: 'fa-rotate-left', confirmLabel: t('panel.prem.refund_btn') });
	}

	async function loadPremiumSubscribers() {
		const body = document.getElementById('premSubsBody');
		if (!body) return false;
		try {
			const d = await FHApi.get('premium_subscribers');
			bulkPlans = Array.isArray(d?.plans) ? d.plans : bulkPlans;
			bulkGroups = Array.isArray(d?.groups) ? d.groups : bulkGroups;
			if (!plans.length && bulkPlans.length) plans = bulkPlans;
			const list = (d && d.success) ? d.subscribers : [];
			if (!list.length) {
				body.innerHTML = `<tr><td colspan="6" class="empty">${esc(t('panel.prem.no_subs'))}</td></tr>`;
				return true;
			}
			const now = Math.floor(Date.now() / 1000);
			body.innerHTML = list.map(u => {
				const exp = Number(u.group_expires_at) || 0;
				// No expiry is not "expired" — it is a plan that was granted without one, which
				// looks identical in the column unless it says so.
				const expCell = exp
					? (exp < now
						? `<span class="badge badge-danger">${esc(t('panel.prem.expired'))}</span>`
						: `${formatDate(exp)} <small style="color:var(--text-muted)">(${esc(t('panel.prem.days_left', { n: Math.ceil((exp - now) / 86400) }))})</small>`)
					: `<span class="badge badge-muted">${esc(t('panel.prem.no_expiry'))}</span>`;
				const safeName = esc(u.username).replace(/'/g, "\\'");
				return `<tr>
					<td><strong>${esc(u.username)}</strong><br><small style="color:var(--text-muted)">${esc(u.email || '')}</small></td>
					<td><span class="badge badge-info">${esc(u.group_name || '—')}</span></td>
					<td>${expCell}</td>
					<td>${Number(u.purchases) || 0}</td>
					<td>${u.last_paid ? formatDate(u.last_paid) : '—'}</td>
					<td><div class="actions">${canPremiumGrant
						? `<button class="action-btn" data-fh-click="openPlanGrant(${u.id}, '${safeName}')" title="${esc(t('panel.prem.grant_title'))}"><i class="fa-solid fa-gem"></i></button>`
						: ''}</div></td>
				</tr>`;
			}).join('');
			return true;
		} catch (e) {
			body.innerHTML = `<tr><td colspan="6" class="empty">${esc(t('common.connection_error'))}</td></tr>`;
			return false;
		}
	}

	async function openBulkPlanGrant() {
		if (!bulkPlans.length || !bulkGroups.length) await loadPremiumSubscribers();
		if (!bulkPlans.length) {
			showNotification(t('panel.prem.no_plans'), 'error');
			return;
		}
		bulkPreviewNonce = '';
		document.getElementById('bulkPlanMessage').className = 'auth-message';
		document.getElementById('bulkPreview').hidden = true;
		document.getElementById('bulkExecuteBtn').hidden = true;
		const planOptions = bulkPlans.map(plan => `<option value="${plan.id}" data-days="${Number(plan.duration_days) || 0}">${esc(plan.name)}</option>`).join('');
		document.getElementById('bulkTargetPlan').innerHTML = planOptions;
		document.getElementById('bulkBoughtPlan').innerHTML = `<option value="0">${esc(t('panel.prem.bulk_any_plan'))}</option>${planOptions}`;
		document.getElementById('bulkGroup').innerHTML = bulkGroups.map(group => `<option value="${group.id}">${esc(group.name)}</option>`).join('');
		const today = new Date();
		const monthAgo = new Date(today.getTime() - 30 * 86400000);
		const iso = date => date.toISOString().slice(0, 10);
		document.getElementById('bulkFrom').value = iso(monthAgo);
		document.getElementById('bulkTo').value = iso(today);
		updateBulkSourceFields();
		showModal('bulkPlanModal');
	}

	function updateBulkSourceFields() {
		const source = document.getElementById('bulkSource')?.value || 'active_subscribers';
		document.getElementById('bulkGroupWrap').hidden = source !== 'group';
		document.getElementById('bulkBuyerFields').hidden = source !== 'buyers';
		bulkPreviewNonce = '';
		document.getElementById('bulkPreview').hidden = true;
		document.getElementById('bulkExecuteBtn').hidden = true;
	}

	async function previewBulkPlanGrant() {
		const source = document.getElementById('bulkSource').value;
		const body = {
			source,
			group_id: Number(document.getElementById('bulkGroup').value) || 0,
			purchased_plan_id: Number(document.getElementById('bulkBoughtPlan').value) || 0,
			from: document.getElementById('bulkFrom').value,
			to: document.getElementById('bulkTo').value,
			plan_id: Number(document.getElementById('bulkTargetPlan').value) || 0,
			duration_days: Number(document.getElementById('bulkDays').value) || 0,
			notify: document.getElementById('bulkNotify').checked
		};
		try {
			const d = await FHApi.post('premium_bulk_preview', body);
			if (!d.success) { flashMessage('bulkPlanMessage', d.error || t('common.error'), 'error'); return; }
			bulkPreviewNonce = d.nonce;
			const preview = document.getElementById('bulkPreview');
			preview.textContent = t('panel.prem.bulk_preview_result', { n: d.count, plan: d.plan, days: d.days });
			preview.hidden = false;
			document.getElementById('bulkExecuteBtn').hidden = d.count < 1;
		} catch (_error) { flashMessage('bulkPlanMessage', t('common.connection_error'), 'error'); }
	}

	async function executeBulkPlanGrant() {
		if (!bulkPreviewNonce) return;
		const nonce = bulkPreviewNonce;
		bulkPreviewNonce = '';
		document.getElementById('bulkExecuteBtn').disabled = true;
		try {
			const d = await FHApi.post('premium_bulk_execute', { nonce });
			if (!d.success) { flashMessage('bulkPlanMessage', d.error || t('common.error'), 'error'); return; }
			closeModal('bulkPlanModal');
			showNotification(t('panel.prem.bulk_done', { n: d.granted, failed: d.failed }), d.failed ? 'info' : 'success');
			loadPremiumSubscribers();
		} catch (_error) { flashMessage('bulkPlanMessage', t('common.connection_error'), 'error'); }
		finally { document.getElementById('bulkExecuteBtn').disabled = false; }
	}

	/** The account's own plan: what it is, what it gives, how much of it is used. */
	async function loadMyPremium() {
		const card = document.getElementById('myPremiumCard');
		const history = document.getElementById('premHistoryBody');
		if (!card && !history) return;
		let d;
		try {
			d = await FHApi.get('my_premium');
		} catch (e) {
			if (card) card.innerHTML = `<p class="empty">${esc(t('common.connection_error'))}</p>`;
			return;
		}
		if (!d || !d.success) return;

		if (history) {
			const list = d.payments || [];
			// Runda 10: a completed (or refunded) purchase may carry its printable receipt.
			const receipt = p => d.invoicesEnabled && p.extOrderId
				&& ['COMPLETED', 'REFUNDED'].includes(p.status)
				&& !['admin_grant', 'admin_revoke'].includes(p.kind)
				? ` <a class="action-btn" href="${apiUrl}?action=invoice&order=${encodeURIComponent(p.extOrderId)}" target="_blank" title="${esc(t('panel.prem.invoice_btn'))}"><i class="fa-solid fa-file-invoice"></i></a>`
				: '';
			history.innerHTML = list.length
				? list.map(p => `<tr>
					<td>${formatDate(p.createdAt)}</td>
					<td>${esc(p.planName || '—')} ${paymentKindBadge(p.kind, p.actorName)}</td>
					<td>${esc(formatMoney(p.amountMinor, p.currency))}</td>
					<td>${paymentStatusBadge(p.status)}${receipt(p)}</td>
				</tr>`).join('')
				: `<tr><td colspan="4" class="empty">${esc(t('panel.prem.no_history'))}</td></tr>`;
		}

		if (!card) return;

		const g = d.group;
		const exp = Number(d.expiresAt) || 0;
		const now = Math.floor(Date.now() / 1000);
		const used = Number(d.usage.used) || 0;
		const quota = Number(d.usage.quota) || 0;
		const pct = quota > 0 ? Math.min(100, (used / quota) * 100) : 0;
		const transfer = d.usage.transfer || {};
		const transferLimit = Number(transfer.limit) || 0;
		const transferUsed = Number(transfer.used) || 0;
		const transferReserved = Number(transfer.reserved) || 0;
		const transferPct = transferLimit > 0
			? Math.min(100, ((transferUsed + transferReserved) / transferLimit) * 100) : 0;

		// "Free" here means "not on a paid plan", which is the default group — worth naming
		// rather than leaving the card blank, because the limits still come from somewhere.
		const planName = d.plan ? d.plan.name : (g ? g.name : '—');
		const badge = d.plan
			? `<span class="badge badge-success">${esc(t('panel.prem.on_plan'))}</span>`
			: `<span class="badge badge-muted">${esc(t('panel.prem.no_plan'))}</span>`;

		const rows = [];
		if (g) {
			rows.push([t('panel.grp.quota'), quota > 0 ? formatSize(quota) : t('panel.grp.unlimited_ph')]);
			rows.push([t('panel.grp.max_file_size'), g.maxFileMb > 0 ? formatSize(g.maxFileMb * 1048576) : t('panel.prem.system_default')]);
			rows.push([t('panel.grp.files_session'), g.filesPerSession > 0 ? g.filesPerSession : '∞']);
			rows.push([t('panel.grp.concurrent'), g.concurrent > 0 ? g.concurrent : '∞']);
			rows.push([t('premium.limit_transfer'), g.transferLimit > 0
				? `${formatSize(g.transferLimit)} / ${t('premium.transfer_period_' + g.transferPeriod)}`
				: t('panel.grp.unlimited_ph')]);
			rows.push([t('panel.set.autodelete'), g.retentionDays === -1
				? t('panel.grp.autodelete_never')
				: (g.retentionDays > 0 ? t('panel.prem.days', { n: g.retentionDays }) : t('panel.prem.retention_default'))]);
		}

		card.innerHTML = `
			<div class="prem-mine-head">
				<div>
					<h3>${esc(planName)} ${badge}</h3>
					${exp
						? `<p class="prem-mine-exp">${esc(exp > now
							? t('panel.prem.active_until', { date: formatDate(exp), n: Math.ceil((exp - now) / 86400) })
							: t('panel.prem.lapsed'))}</p>`
						: (d.plan ? `<p class="prem-mine-exp">${esc(t('panel.prem.no_expiry'))}</p>` : '')}
				</div>
				${d.enabled ? `<a class="btn btn-primary" href="${appUrl}/premium">
					<i class="fa-solid fa-gem"></i> ${esc(d.plan ? t('panel.prem.extend') : t('panel.prem.get'))}
				</a>` : ''}
			</div>

			${quota > 0 ? `<div class="quota-bar ${pct >= 100 ? 'is-full' : (pct >= 85 ? 'is-high' : (pct >= 60 ? 'is-warm' : ''))}" style="margin:0 0 18px;">
				<div class="quota-bar-head">
					<span class="quota-bar-label"><i class="fa-solid fa-hard-drive"></i> ${esc(t('panel.my.quota_title'))}</span>
					<span class="quota-bar-value"><strong>${formatSize(used)}</strong>
						<span class="quota-bar-of">/ ${formatSize(quota)}</span>
						<span class="quota-bar-pct">${pct < 10 ? pct.toFixed(1) : Math.round(pct)}%</span></span>
				</div>
				<div class="quota-bar-track"><i style="width:${pct.toFixed(2)}%"></i></div>
			</div>` : ''}

			${transferLimit > 0 ? `<div class="quota-bar ${transferPct >= 100 ? 'is-full' : (transferPct >= 85 ? 'is-high' : (transferPct >= 60 ? 'is-warm' : ''))}" style="margin:0 0 18px;">
				<div class="quota-bar-head">
					<span class="quota-bar-label"><i class="fa-solid fa-gauge-high"></i> ${esc(t('premium.limit_transfer'))}</span>
					<span class="quota-bar-value"><strong>${formatSize(transferUsed)}</strong>
						<span class="quota-bar-of">/ ${formatSize(transferLimit)}</span>
						<span class="quota-bar-pct">${transferPct < 10 ? transferPct.toFixed(1) : Math.round(transferPct)}%</span></span>
				</div>
				<div class="quota-bar-track"><i style="width:${transferPct.toFixed(2)}%"></i></div>
				<small>${esc(t('panel.prem.transfer_resets', { date: formatDate(Number(transfer.resets_at) || 0) }))}</small>
			</div>` : ''}

			<div class="table-wrap"><table><tbody>
				${rows.map(([k, v]) => `<tr><td style="color:var(--text-secondary);">${esc(k)}</td><td><strong>${esc(String(v))}</strong></td></tr>`).join('')}
			</tbody></table></div>`;
	}



	let premiumSearchTimer = null;
	function schedulePremiumSearch() {
		clearTimeout(premiumSearchTimer);
		premiumSearchTimer = setTimeout(() => loadPremiumPayments(1), 350);
	}

	window.FHPanelPremium = Object.freeze({
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
	});
}());
