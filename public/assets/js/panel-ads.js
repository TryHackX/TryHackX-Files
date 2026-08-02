(function () {
	'use strict';

	const t = (key, params) => window.t(key, params);
	const esc = window.FHUtil.esc;
	const safeHttpUrl = window.FHUtil.safeHttpUrl;
	const formatDate = window.FHUtil.formatDate;
	const openModal = (id) => window.openModal(id);
	const closeModal = (id) => window.closeModal(id);
	const showNotification = (message, type = 'success', anchor = null) =>
		window.showNotification(message, type, anchor);
	const showConfirm = (...args) => window.showConfirm(...args);
	const bootstrap = document.getElementById('panelBootstrap');
	let panel = {};
	try {
		panel = JSON.parse(bootstrap?.dataset.config || '{}');
	} catch {
		panel = {};
	}
	const apiUrl = panel.apiUrl || '';

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

	function formatMoney(minor, currency) {
		const amount = (Number(minor) || 0) / 100;
		return amount.toLocaleString(window.LANG || 'pl', {
			minimumFractionDigits: 2,
			maximumFractionDigits: 2
		}) + ' ' + (currency || '');
	}
	/* ------------------------------------------------------------------ *
	 * Advertising (Faza 8)
	 *
	 * Four screens share this section: the settings sub-tab, the admin manager
	 * (overview / creatives / zones / queue / packages), and the buyer's "Moje
	 * reklamy". Every loader no-ops when its markup is absent, so the init
	 * switch can call them wholesale and the sub-tab decides what happens.
	 * ------------------------------------------------------------------ */
	let adsData = { ads: [], zones: [] };
	let adsPackages = [];
	let adsDays = 30;
	let myAdsList = [], myAdsPackages = [], myAdsBoosts = [];
	let myAdsInvoicesEnabled = false;

	function adStatusBadge(status) {
		const map = {
			active: ['badge-success', 'panel.ads.status_active'],
			paused: ['badge-muted', 'panel.ads.status_paused'],
			pending: ['badge-info', 'panel.ads.status_pending'],
			draft: ['badge-muted', 'panel.ads.status_draft'],
			rejected: ['badge-danger', 'panel.ads.status_rejected'],
			expired: ['badge-muted', 'panel.ads.status_expired']
		};
		const [cls, key] = map[status] || ['badge-muted', 'panel.ads.status_paused'];
		return `<span class="badge ${cls}">${esc(t(key))}</span>`;
	}

	function adTypeLabel(type) {
		return type === 'html' ? t('panel.ads.type_html')
			: type === 'adsense' ? 'AdSense' : t('panel.ads.type_image');
	}

	function zoneLabel(zoneId) {
		const z = adsData.zones.find(z => z.id === zoneId);
		return z ? `${z.pageLabel} · ${z.label}` : zoneId;
	}

	/* ---- Settings sub-tab ---- */

	async function loadAdsSettings() {
		if (!document.getElementById('adsEnabled')) return;
		try {
			const d = await FHApi.get('admin_ads');
			if (d.success && d.settings) fillAdsSettings(d.settings);
		} catch (e) { /* fields keep their blanks */ }
	}

	function fillAdsSettings(s) {
		const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = v || ''; };
		const check = (id, v) => { const el = document.getElementById(id); if (el) el.checked = v === '1'; };
		check('adsEnabled', s.ads_enabled);
		check('adsAdminPreview', s.ads_admin_preview);
		check('adsTrackImpressions', s.ads_track_impressions);
		check('adsTrackClicks', s.ads_track_clicks);
		set('adsAdsenseClient', s.ads_adsense_client);
		check('adsAdsenseAuto', s.ads_adsense_auto);
		set('adsConsentMode', s.ads_consent_mode || 'off');
		check('adsSellingEnabled', s.ads_selling_enabled);
		check('adsInvoiceEnabled', s.invoice_enabled);
		set('adsInvoiceSeller', s.invoice_seller);
		set('adsInvoicePrefix', s.invoice_prefix || 'FH');
		set('adsInvoiceFooter', s.invoice_footer);
		set('adsWarnDays', s.ads_warn_days);
		set('adsZoneMax', s.ads_zone_max);
		set('adsGraceDays', s.ads_grace_days);
		set('adsReviewComp', s.ads_review_comp_days);
		set('adsTxtContent', s.ads_txt_content);
		set('adsCspExtra', s.ads_csp_extra);
		set('adsContact', s.ads_contact);
		// Banner cap: stored in KiB, shown in whichever unit divides cleanly.
		const kb = parseInt(s.ads_max_banner_kb, 10) || 5120;
		const asMb = kb >= 1024 && kb % 1024 === 0;
		set('adsMaxBanner', asMb ? kb / 1024 : kb);
		set('adsMaxBannerUnit', asMb ? 'MB' : 'KB');
		const off = String(s.ads_zones_disabled || '').split(',').map(x => x.trim());
		document.querySelectorAll('#adsZonesList .ads-zone-check').forEach(c => {
			c.checked = off.indexOf(c.value) === -1; // checked = zone active
		});
		// Per-zone box overrides; empty inputs fall back to the placeholder defaults.
		let dims = {};
		try { dims = JSON.parse(s.ads_zone_dims || '{}') || {}; } catch (e) { }
		document.querySelectorAll('#adsZonesList .ads-zone-w').forEach(i => { i.value = dims[i.dataset.zone] ? dims[i.dataset.zone][0] : ''; });
		document.querySelectorAll('#adsZonesList .ads-zone-h').forEach(i => { i.value = dims[i.dataset.zone] ? dims[i.dataset.zone][1] : ''; });
	}

	async function saveAdsSettings(btn = null) {
		const val = (id) => document.getElementById(id)?.value || '';
		const on = (id) => document.getElementById(id)?.checked ? '1' : '0';
		const zonesOff = Array.from(document.querySelectorAll('#adsZonesList .ads-zone-check'))
			.filter(c => !c.checked).map(c => c.value).join(',');
		const unit = val('adsMaxBannerUnit') === 'MB' ? 1024 : 1;
		const dims = {};
		document.querySelectorAll('#adsZonesList .ads-zone-row').forEach(row => {
			const w = row.querySelector('.ads-zone-w');
			const h = row.querySelector('.ads-zone-h');
			if (w && h && w.value && h.value) dims[w.dataset.zone] = [parseInt(w.value, 10), parseInt(h.value, 10)];
		});
		try {
			const d = await FHApi.post('admin_ads_settings', {
				ads_enabled: on('adsEnabled'),
				ads_admin_preview: on('adsAdminPreview'),
				ads_track_impressions: on('adsTrackImpressions'),
				ads_track_clicks: on('adsTrackClicks'),
				ads_adsense_client: val('adsAdsenseClient').trim(),
				ads_adsense_auto: on('adsAdsenseAuto'),
				ads_consent_mode: val('adsConsentMode') || 'off',
				ads_selling_enabled: on('adsSellingEnabled'),
				invoice_enabled: on('adsInvoiceEnabled'),
				invoice_seller: val('adsInvoiceSeller'),
				invoice_prefix: val('adsInvoicePrefix').trim(),
				invoice_footer: val('adsInvoiceFooter'),
				ads_warn_days: val('adsWarnDays'),
				ads_max_banner_kb: String(Math.round((parseFloat(val('adsMaxBanner').replace(',', '.')) || 5) * unit)),
				ads_zone_max: val('adsZoneMax'),
				ads_grace_days: val('adsGraceDays'),
				ads_review_comp_days: val('adsReviewComp'),
				ads_zones_disabled: zonesOff,
				ads_zone_dims: Object.keys(dims).length ? JSON.stringify(dims) : '',
				ads_contact: val('adsContact'),
				ads_txt_content: val('adsTxtContent'),
				ads_csp_extra: val('adsCspExtra')
			});
			if (d.success) {
				showNotification(t('panel.ctl.saved'), 'success', btn);
				if (d.settings) fillAdsSettings(d.settings); // the server may have sanitised
			} else flashMessage('adsSettingsMessage', d.error || t('common.error'), 'error');
		} catch (e) { flashMessage('adsSettingsMessage', t('common.connection_error'), 'error'); }
	}

	/* ---- Admin: creatives + zones ---- */

	async function loadAdsManager() {
		if (!document.getElementById('adsListBody') && !document.querySelector('.zones-grid')
			&& !document.getElementById('adFormZone')) return;
		try {
			const d = await FHApi.get('admin_ads');
			if (!d.success) return;
			adsData = { ads: d.ads || [], zones: d.zones || [] };
			renderAdsList();
			renderZoneGrid();
		} catch (e) { /* keep placeholders */ }
	}

	function renderAdsList() {
		const body = document.getElementById('adsListBody');
		if (!body) return;
		if (!adsData.ads.length) {
			body.innerHTML = `<tr><td colspan="8" class="empty">${esc(t('panel.ads.none'))}</td></tr>`;
			return;
		}
		body.innerHTML = adsData.ads.map(a => {
			const previewUrl = safeHttpUrl(a.imageUrl);
			const schedule = (a.startsAt ? formatDate(a.startsAt).split(' ')[0] : '…')
				+ ' → ' + (a.endsAt ? formatDate(a.endsAt).split(' ')[0] : '∞');
			const canPause = a.status === 'active';
			const canResume = a.status === 'paused';
			return `<tr>
				<td><div class="admin-ad-title">${previewUrl
					? `<a class="admin-ad-thumb" href="${esc(previewUrl)}" target="_blank" rel="noopener noreferrer" title="${esc(t('panel.ads.preview'))}"><img src="${esc(previewUrl)}" alt=""></a>`
					: '<span class="admin-ad-thumb admin-ad-thumb--empty"><i class="fa-solid fa-image"></i></span>'}
					<strong>${esc(a.name)}</strong></div></td>
				<td>${esc(adTypeLabel(a.type))}</td>
				<td>${esc(a.zoneLabel)}</td>
				<td>${a.ownerId ? esc(a.ownerName || ('#' + a.ownerId)) : `<span class="badge badge-info">${esc(t('panel.ads.house'))}</span>`}</td>
				<td>${adStatusBadge(a.status)}</td>
				<td>${a.weight}</td>
				<td><small>${esc(schedule)}</small></td>
				<td><div class="actions">
					<button class="action-btn" data-fh-click='openAdForm(${JSON.stringify(a.id)})' title="${esc(t('common.edit'))}"><i class="fa-solid fa-pen"></i></button>
					${canPause ? `<button class="action-btn" data-fh-click="adAction(${a.id}, 'pause')" title="${esc(t('panel.ads.pause'))}"><i class="fa-solid fa-pause"></i></button>` : ''}
					${canResume ? `<button class="action-btn" data-fh-click="adAction(${a.id}, 'resume')" title="${esc(t('panel.ads.resume'))}"><i class="fa-solid fa-play"></i></button>` : ''}
					<button class="action-btn del" data-fh-click="askDeleteAd(${a.id})" title="${esc(t('common.delete'))}"><i class="fa-solid fa-trash"></i></button>
				</div></td>
			</tr>`;
		}).join('');
	}

	function renderZoneGrid() {
		if (!document.querySelector('.zones-grid')) return;
		adsData.zones.forEach(z => {
			const holder = document.getElementById('zoneAds-' + z.id);
			if (!holder) return;
			const here = adsData.ads.filter(a => a.zone === z.id && ['active', 'paused', 'pending'].includes(a.status));
			holder.innerHTML = here.length
				? here.map(a => `<button type="button" class="zone-chip zone-chip--${a.status}" data-fh-click="openAdForm(${a.id})" title="${esc(adTypeLabel(a.type))}">
						<i class="fa-solid ${a.type === 'adsense' ? 'fa-google' : a.type === 'html' ? 'fa-code' : 'fa-image'}"></i>
						${esc(a.name)}${a.weight > 1 ? ` <small>×${a.weight}</small>` : ''}
					</button>`).join('')
				: `<span class="zone-empty">${esc(t('panel.ads.zone_empty'))}</span>`;
		});
	}

	function fillZoneSelect(selectId, selected) {
		const sel = document.getElementById(selectId);
		if (!sel) return;
		sel.innerHTML = adsData.zones.map(z =>
			`<option value="${esc(z.id)}"${z.id === selected ? ' selected' : ''}>${esc(z.pageLabel)} · ${esc(z.label)}${z.dims ? ' (' + esc(z.dims) + ')' : ''}${z.disabled ? ' — ' + esc(t('panel.ads.zone_off')) : ''}</option>`).join('');
	}

	/** The dropzone quotes the selected zone's box — it is what the crop exports. */
	function updateAdFormZoneHint() {
		const sel = document.getElementById('adFormZone');
		const dims = document.getElementById('adFormDropDims');
		if (!sel) return;
		const z = adsData.zones.find(z => z.id === sel.value);
		if (dims) dims.textContent = z && z.dims ? t('panel.ads.form_dims_hint', { dims: z.dims }) : '';
	}

	/** The admin form's target box: the currently selected zone (fallback 960×120). */
	function adFormDims() {
		const sel = document.getElementById('adFormZone');
		const z = sel ? adsData.zones.find(z => z.id === sel.value) : null;
		return { w: (z && z.w) || 960, h: (z && z.h) || 120 };
	}

	/** The buyer form's target box: the package's base zone. */
	let myAdFormDimsBox = { w: 960, h: 120 };
	function myAdFormDims() { return myAdFormDimsBox; }

	function openAdForm(adId = null, presetZone = null) {
		const ad = adId ? adsData.ads.find(a => a.id === adId) : null;
		// Purchased creatives are managed through the queue; the form opens content-only for
		// them — type/zone/weight/schedule/status are what the buyer's package fixed, so those
		// controls are disabled here and re-pinned server-side no matter what arrives.
		const owned = !!(ad && ad.ownerId);
		document.querySelectorAll('input[name="adFormType"]').forEach(r => { r.disabled = owned; });
		['adFormZone', 'adFormWeight', 'adFormStatus', 'adFormStarts', 'adFormEnds'].forEach(fid => {
			const el = document.getElementById(fid);
			if (el) el.disabled = owned;
		});
		const ownedNote = document.getElementById('adFormOwnedNote');
		if (ownedNote) ownedNote.style.display = owned ? '' : 'none';
		document.getElementById('adFormTitle').textContent = t(ad ? 'panel.ads.form_title_edit' : 'panel.ads.form_title');
		document.getElementById('adFormMessage').className = 'auth-message';
		document.getElementById('adFormId').value = ad ? ad.id : '';
		document.getElementById('adFormName').value = ad ? ad.name : '';
		const type = ad ? ad.type : 'image';
		document.querySelectorAll('input[name="adFormType"]').forEach(r => { r.checked = r.value === type; });
		fillZoneSelect('adFormZone', ad ? ad.zone : (presetZone || (adsData.zones[0] || {}).id));
		document.getElementById('adFormZone').onchange = updateAdFormZoneHint;
		updateAdFormZoneHint();
		adUploaderReset('adForm');
		document.getElementById('adFormPreview').innerHTML = ad && a2url(ad) ? `<img src="${esc(a2url(ad))}" alt="">` : '';
		document.getElementById('adFormImageUrl').value = ad && !ad.hasUpload ? ad.imageUrl : '';
		document.getElementById('adFormTargetUrl').value = ad ? ad.targetUrl : '';
		document.getElementById('adFormAlt').value = ad ? ad.altText : '';
		document.getElementById('adFormHtml').value = ad ? ad.html : '';
		document.getElementById('adFormAdsenseSlot').value = ad ? ad.adsenseSlot : '';
		document.getElementById('adFormWeight').value = ad ? ad.weight : 1;
		document.getElementById('adFormStatus').value = ad && ad.status === 'paused' ? 'paused' : 'active';
		const toDate = ts => ts ? new Date(ts * 1000).toISOString().slice(0, 10) : '';
		document.getElementById('adFormStarts').value = ad ? toDate(ad.startsAt) : '';
		document.getElementById('adFormEnds').value = ad ? toDate(ad.endsAt) : '';
		adFormTypeChanged();
		openModal('adFormModal');
	}

	function a2url(ad) { return safeHttpUrl(ad.imageUrl); }

	function adFormTypeChanged() {
		const type = document.querySelector('input[name="adFormType"]:checked')?.value || 'image';
		document.getElementById('adFormImageFields').style.display = type === 'image' ? '' : 'none';
		document.getElementById('adFormHtmlFields').style.display = type === 'html' ? '' : 'none';
		document.getElementById('adFormAdsenseFields').style.display = type === 'adsense' ? '' : 'none';
	}

	async function saveAdForm() {
		const val = (id) => document.getElementById(id)?.value || '';
		const id = parseInt(val('adFormId'), 10) || 0;
		const payload = {
			id: id,
			name: val('adFormName').trim(),
			type: document.querySelector('input[name="adFormType"]:checked')?.value || 'image',
			zone: val('adFormZone'),
			image_url: val('adFormImageUrl').trim(),
			target_url: val('adFormTargetUrl').trim(),
			alt_text: val('adFormAlt').trim(),
			html: val('adFormHtml'),
			adsense_slot: val('adFormAdsenseSlot').trim(),
			weight: parseInt(val('adFormWeight'), 10) || 1,
			status: val('adFormStatus'),
			starts_at: val('adFormStarts'),
			ends_at: val('adFormEnds')
		};
		try {
			const d = await FHApi.post('admin_ad_save', payload);
			if (!d.success) { flashMessage('adFormMessage', d.error || t('common.error'), 'error'); return; }
			const savedId = d.id || id;
			const file = await adUploaderBlob('adForm');
			if (file) {
				const fd = new FormData();
				fd.append('id', savedId);
				fd.append('file', file);
				const up = await FHApi.postForm('admin_ad_upload', fd);
				if (!up.success) { flashMessage('adFormMessage', up.error || t('common.error'), 'error'); return; }
			}
			closeModal('adFormModal');
			showNotification(t('panel.ctl.saved'), 'success');
			loadAdsManager();
		} catch (e) { flashMessage('adFormMessage', t('common.connection_error'), 'error'); }
	}

	async function adAction(id, action) {
		try {
			const d = await FHApi.post('admin_ad_action', { id: id, do: action });
			if (!d.success) { showNotification(d.error || t('common.error'), 'error'); return; }
			showNotification(t('panel.ctl.saved'), 'success');
			loadAdsManager();
			loadAdsQueue();
		} catch (e) { showNotification(t('common.connection_error'), 'error'); }
	}

	function askDeleteAd(id) {
		const ad = adsData.ads.find(a => a.id === id);
		showConfirm(t('panel.ads.delete_title'), t('panel.ads.delete_q', { name: ad ? ad.name : '#' + id }), async () => {
			try {
				const d = await FHApi.post('admin_ad_delete', { id: id });
				if (!d.success) { showNotification(d.error || t('common.error'), 'error'); return; }
				showNotification(t('panel.ctl.saved'), 'success');
				loadAdsManager();
			} catch (e) { showNotification(t('common.connection_error'), 'error'); }
		});
	}

	function openZoneAssign(zoneId) {
		document.getElementById('zoneAssignZone').value = zoneId;
		document.getElementById('zoneAssignLabel').textContent = zoneLabel(zoneId);
		const list = document.getElementById('zoneAssignList');
		// Only house creatives move freely — a purchased ad's zone is what its buyer paid for.
		const movable = adsData.ads.filter(a => !a.ownerId && a.zone !== zoneId);
		list.innerHTML = movable.length
			? movable.map(a => `<div class="zone-assign-row">
					<span>${esc(a.name)} <small style="color:var(--text-muted)">${esc(adTypeLabel(a.type))} · ${esc(a.zoneLabel)}</small> ${adStatusBadge(a.status)}</span>
					<button type="button" class="btn btn-sm btn-primary" data-fh-click="assignAdToZone(${a.id}, '${esc(zoneId)}')">
						<i class="fa-solid fa-arrow-right"></i> ${esc(t('panel.ads.assign_here'))}
					</button>
				</div>`).join('')
			: `<p class="empty">${esc(t('panel.ads.assign_none'))}</p>`;
		openModal('zoneAssignModal');
	}

	async function assignAdToZone(adId, zoneId) {
		const ad = adsData.ads.find(a => a.id === adId);
		if (!ad) return;
		try {
			// The save endpoint validates whole creatives, so the move re-sends the ad as-is
			// with only the zone changed.
			const d = await FHApi.post('admin_ad_save', {
				id: ad.id, name: ad.name, type: ad.type, zone: zoneId,
				image_url: ad.hasUpload ? '' : ad.imageUrl, target_url: ad.targetUrl, alt_text: ad.altText,
				html: ad.html, adsense_slot: ad.adsenseSlot, weight: ad.weight,
				status: ad.status === 'paused' ? 'paused' : 'active',
				starts_at: ad.startsAt || '', ends_at: ad.endsAt || ''
			});
			if (!d.success) { showNotification(d.error || t('common.error'), 'error'); return; }
			closeModal('zoneAssignModal');
			showNotification(t('panel.ctl.saved'), 'success');
			loadAdsManager();
		} catch (e) { showNotification(t('common.connection_error'), 'error'); }
	}

	/* ---- Admin: approval queue ---- */

	let adsQueueData = [];

	/**
	 * Keep the server-rendered queue badges honest after client-side actions (runda 6).
	 * Only the "Reklamy" parent tab and the "Kolejka" sub-tab carry the badge — a loose
	 * substring match used to also tag every ads sub-tab and the PL/EN language links,
	 * whose hrefs carry the whole query string (runda 7).
	 */
	function updateQueueBadges(count) {
		const badgeLinks = Array.from(document.querySelectorAll('a.sub-tab')).filter(link => {
			const href = link.getAttribute('href') || '';
			return /[?&]mstab=ads$/.test(href) || /[?&]astab=queue(?:$|&)/.test(href);
		});
		badgeLinks.forEach(link => {
			let badge = link.querySelector('.tab-badge');
			if (count > 0) {
				if (!badge) {
					badge = document.createElement('span');
					badge.className = 'tab-badge';
					link.appendChild(document.createTextNode(' '));
					link.appendChild(badge);
				}
				badge.textContent = count;
			} else if (badge) {
				badge.remove();
			}
		});
	}

	async function loadAdsQueue() {
		const holder = document.getElementById('adsQueueList');
		if (!holder) return;
		try {
			const d = await FHApi.get('admin_ad_queue');
			const list = (d && d.success) ? d.queue : [];
			adsQueueData = list;
			updateQueueBadges(list.length);
			if (!list.length) {
				holder.innerHTML = `<p class="empty">${esc(t('panel.ads.queue_empty'))}</p>`;
				return;
			}
			holder.innerHTML = list.map(a => {
				// One purchase, one card (runda 7): the base zone plus each add-on placement as
				// chips; a top-up card (root already live) reviews only the new children.
				// Chips with a banner are clickable (runda 8) and swap the card's preview, so
				// the reviewer sees each zone's creative without leaving the queue.
				const paidMinor = a.orderAmountMinor != null ? a.orderAmountMinor : a.amountMinor;
				const paidCurrency = a.orderCurrency || a.currency;
				const chip = (label, img, ownBanner, active) => {
					const previewUrl = safeHttpUrl(img);
					return `<button type="button" class="zone-chip${active ? ' zone-chip--active' : ''}" ${previewUrl ? `data-preview="${esc(previewUrl)}"` : 'disabled'}
						title="${esc(ownBanner ? t('panel.myads.addon_own') : t('panel.myads.addon_reuse'))}">${label}${ownBanner ? ' <i class="fa-solid fa-image"></i>' : ''}</button>`;
				};
				const zones = [chip(esc(zoneLabel(a.zone)), a.imageUrl, true, true)]
					.concat((a.children || []).map(c => chip(esc(c.zoneLabel), c.imageUrl || a.imageUrl, !!c.imageUrl, false)));
				const previewUrl = safeHttpUrl(a.imageUrl);
				const targetUrl = safeHttpUrl(a.targetUrl);
				return `<div class="ads-queue-card">
				<div class="ads-queue-preview">${previewUrl
					? `<img src="${esc(previewUrl)}" alt="${esc(a.altText)}">`
					: `<i class="fa-solid fa-image" style="color:var(--text-muted); font-size:1.6rem;"></i>`}</div>
				<div class="ads-queue-meta">
					<strong>${esc(a.name)}${a.addonOnly ? ` <span class="badge badge-info">${esc(t('panel.ads.queue_addon'))}</span>` : ''}</strong>
					<span>${esc(t('panel.ads.th_owner'))}: <strong>${esc(a.ownerName || '—')}</strong></span>
					<span>${esc(t('panel.ads.queue_package'))}: ${esc(a.packageName || '—')}</span>
					<span>${esc(t('panel.ads.th_zone'))}: ${zones.join(' ')}</span>
					<span>${esc(t('panel.ads.queue_paid'))}: ${paidMinor ? esc(formatMoney(paidMinor, paidCurrency)) : '—'}
						${a.orderId ? ` · <code style="font-size:0.78rem;">${esc(a.orderId)}</code>` : ''}</span>
					<span>${esc(t('panel.ads.queue_target'))}: ${targetUrl ? `<a href="${esc(targetUrl)}" target="_blank" rel="noopener noreferrer nofollow">${esc(a.targetUrl)}</a>` : '—'}</span>
					<small style="color:var(--text-muted)">${esc(t('panel.ads.queue_since'))} ${formatDate(a.createdAt)}</small>
				</div>
				<div class="ads-queue-actions">
					<button type="button" class="btn btn-primary" data-fh-click="approveAd(${a.id})"><i class="fa-solid fa-check"></i> ${esc(t('panel.ads.approve'))}</button>
					<button type="button" class="btn btn-danger" data-fh-click="openAdReject(${a.id})"><i class="fa-solid fa-xmark"></i> ${esc(t('panel.ads.reject'))}</button>
				</div>
			</div>`;
			}).join('');
			// One delegated listener survives every reload: a zone chip with a banner swaps
			// the card's preview image to that placement's creative.
			if (!holder.dataset.previewWired) {
				holder.dataset.previewWired = '1';
				holder.addEventListener('click', e => {
					const chipEl = e.target.closest('.zone-chip[data-preview]');
					if (!chipEl) return;
					const card = chipEl.closest('.ads-queue-card');
					const img = card?.querySelector('.ads-queue-preview img');
					if (!img) return;
					img.src = chipEl.dataset.preview;
					card.querySelectorAll('.zone-chip--active').forEach(c => c.classList.remove('zone-chip--active'));
					chipEl.classList.add('zone-chip--active');
				});
			}
		} catch (e) {
			holder.innerHTML = `<p class="empty">${esc(t('common.connection_error'))}</p>`;
		}
	}

	function approveAd(id) {
		showConfirm(t('panel.ads.approve'), t('panel.ads.approve_q'), () => adAction(id, 'approve'),
			{ danger: false, icon: 'fa-check', confirmLabel: t('panel.ads.approve') });
	}

	function openAdReject(id) {
		// The name comes from the loaded queue, not an inline argument — quotes inside an
		// HTML attribute were tearing the handler apart (runda 6, "Odrzuć did nothing").
		const ad = adsQueueData.find(a => a.id === id) || adsData.ads.find(a => a.id === id);
		document.getElementById('adRejectId').value = id;
		document.getElementById('adRejectName').textContent = ad ? ad.name : '#' + id;
		document.getElementById('adRejectReason').value = '';
		// The PayU give-back offer (runda 8): only when the card carries a completed order.
		const refundRow = document.getElementById('adRejectRefundRow');
		const refundable = ad && ad.canRefund && ad.orderId && ad.orderAmountMinor;
		refundRow.style.display = refundable ? '' : 'none';
		document.getElementById('adRejectRefund').checked = false;
		if (refundable) {
			document.getElementById('adRejectRefundLabel').textContent =
				t('panel.ads.reject_refund', { amount: formatMoney(ad.orderAmountMinor, ad.orderCurrency || ad.currency) });
		}
		openModal('adRejectModal');
	}

	async function confirmAdReject() {
		const id = parseInt(document.getElementById('adRejectId').value, 10);
		const reason = document.getElementById('adRejectReason').value.trim();
		const refund = document.getElementById('adRejectRefundRow').style.display !== 'none'
			&& document.getElementById('adRejectRefund').checked;
		try {
			const d = await FHApi.post('admin_ad_action', { id: id, do: 'reject', reason: reason, refund: refund });
			if (!d.success) { showNotification(d.error || t('common.error'), 'error'); return; }
			closeModal('adRejectModal');
			if (d.refund === 'ok') {
				showNotification(t('panel.ads.refund_ok'), 'success');
			} else if (d.refund === 'pending') {
				showNotification(t('panel.ads.refund_pending'), 'success');
			} else if (d.refund === 'failed') {
				showNotification(t('panel.ads.refund_failed', { err: d.refundError || '?' }), 'error');
			} else {
				showNotification(t('panel.ctl.saved'), 'success');
			}
			loadAdsQueue();
			loadAdsManager();
		} catch (e) { showNotification(t('common.connection_error'), 'error'); }
	}

	/* ---- Admin: packages ---- */

	async function loadAdsPackages() {
		const body = document.getElementById('adsPackagesBody');
		if (!body) return;
		try {
			const d = await FHApi.get('admin_ad_packages');
			if (!d.success) { body.innerHTML = `<tr><td colspan="6" class="empty">${esc(d.error || t('common.error'))}</td></tr>`; return; }
			adsPackages = d.packages || [];
			if (!adsData.zones.length) adsData.zones = d.zones || [];
			if (!adsPackages.length) {
				body.innerHTML = `<tr><td colspan="8" class="empty">${esc(t('panel.ads.packages_none'))}</td></tr>`;
				return;
			}
			body.innerHTML = adsPackages.map(p => {
				const isBoost = (p.kind || 'placement') === 'boost';
				return `<tr>
				<td><strong>${esc(p.name)}</strong></td>
				<td>${isBoost
					? `<span class="badge badge-info"><i class="fa-solid fa-bolt"></i> ${esc(t('panel.ads.kind_boost'))}</span>`
					: `<span class="badge badge-muted">${esc(t('panel.ads.kind_placement'))}</span>`}</td>
				<td>${isBoost ? '—' : esc(zoneLabel(p.zone))}</td>
				<td>${esc(t('panel.prem.days', { n: p.duration_days }))}</td>
				<td>${isBoost ? '+' + Number(p.weight_bonus || 0) : Number(p.priority || 10)}</td>
				<td>${esc(formatMoney(p.amount_minor, p.currency))}</td>
				<td>${Number(p.enabled)
					? `<span class="badge badge-success">${esc(t('panel.users.active'))}</span>`
					: `<span class="badge badge-muted">${esc(t('panel.users.inactive'))}</span>`}</td>
				<td><div class="actions">
					<button class="action-btn" data-fh-click="openPackageForm(${p.id})" title="${esc(t('common.edit'))}"><i class="fa-solid fa-pen"></i></button>
					<button class="action-btn del" data-fh-click="askDeletePackage(${p.id})" title="${esc(t('common.delete'))}"><i class="fa-solid fa-trash"></i></button>
				</div></td>
			</tr>`;
			}).join('');
		} catch (e) {
			body.innerHTML = `<tr><td colspan="6" class="empty">${esc(t('common.connection_error'))}</td></tr>`;
		}
	}

	function openPackageForm(pkgId = null) {
		const p = pkgId ? adsPackages.find(x => x.id === pkgId) : null;
		document.getElementById('packageFormTitle').textContent = t(p ? 'panel.ads.package_form_edit' : 'panel.ads.package_form_title');
		document.getElementById('packageFormMessage').className = 'auth-message';
		document.getElementById('packageFormId').value = p ? p.id : '';
		document.getElementById('packageFormName').value = p ? p.name : '';
		document.getElementById('packageFormDesc').value = p ? (p.description || '') : '';
		const kind = p ? (p.kind || 'placement') : 'placement';
		document.querySelectorAll('input[name="packageFormKind"]').forEach(r => { r.checked = r.value === kind; });
		fillZoneSelect('packageFormZone', p && p.zone ? p.zone : (adsData.zones[0] || {}).id);
		document.getElementById('packageFormPriority').value = p ? (p.priority || 10) : 10;
		document.getElementById('packageFormBonus').value = p ? (p.weight_bonus || 20) : 20;
		document.getElementById('packageFormDays').value = p ? p.duration_days : 30;
		document.getElementById('packageFormPrice').value = p ? ((p.amount_minor || 0) / 100).toFixed(2) : '';
		document.getElementById('packageFormCurrency').value = p ? (p.currency || 'PLN') : 'PLN';
		document.getElementById('packageFormSort').value = p ? p.sort : 0;
		document.getElementById('packageFormEnabled').checked = p ? !!Number(p.enabled) : true;
		renderPackageAddons(p);
		// Property assignment, not addEventListener: openPackageForm runs per open and
		// stacked listeners would re-render the list N times per zone change.
		document.getElementById('packageFormZone').onchange = () => renderPackageAddons(collectCurrentPackageState());
		packageKindChanged();
		openModal('packageFormModal');
	}

	/** Keep the addon list in sync when the base zone changes mid-edit. */
	function collectCurrentPackageState() {
		const addon_zones = {};
		document.querySelectorAll('#packageFormAddons .pkg-addon-check:checked').forEach(c => {
			const price = c.closest('.myad-addon-row').querySelector('.pkg-addon-price');
			addon_zones[c.value] = Math.round(parseFloat((price.value || '0').replace(',', '.')) * 100);
		});
		return { addon_zones: addon_zones, zone: document.getElementById('packageFormZone').value };
	}

	/**
	 * The package's add-on offer (pt 7): every zone except the base one, with a checkbox
	 * and a surcharge input. Stored as {zone: minor} JSON.
	 */
	function renderPackageAddons(p) {
		const holder = document.getElementById('packageFormAddons');
		if (!holder) return;
		let stored = {};
		if (p && p.addon_zones) {
			try { stored = typeof p.addon_zones === 'string' ? (JSON.parse(p.addon_zones) || {}) : p.addon_zones; } catch (e) { }
		}
		const baseZone = document.getElementById('packageFormZone').value;
		holder.innerHTML = adsData.zones.filter(z => z.id !== baseZone).map(z => {
			const on = stored[z.id] != null;
			return `<div class="myad-addon-row">
				<label class="form-check">
					<input type="checkbox" class="pkg-addon-check" value="${esc(z.id)}" ${on ? 'checked' : ''}>
					<span>${esc(z.pageLabel)} · ${esc(z.label)} <small style="color:var(--text-muted)">${esc(z.dims)}</small></span>
				</label>
				<span class="myad-addon-price">+
					<input type="number" class="pkg-addon-price input" min="0" step="0.01" style="width:90px;"
						value="${on ? ((stored[z.id] || 0) / 100).toFixed(2) : ''}" ${on ? '' : 'disabled'} placeholder="5.00">
				</span>
			</div>`;
		}).join('');
		// One delegated listener survives every re-render — the per-row inline handlers
		// did not fire reliably, which left the price inputs stuck (runda 5, pt 1).
		if (!holder.dataset.wired) {
			holder.dataset.wired = '1';
			holder.addEventListener('change', e => {
				const check = e.target.closest('.pkg-addon-check');
				if (!check) return;
				const price = check.closest('.myad-addon-row').querySelector('.pkg-addon-price');
				price.disabled = !check.checked;
				if (check.checked) price.focus();
			});
		}
	}

	/** Placement sells a zone; a boost sells extra weight — the form shows what applies. */
	function packageKindChanged() {
		const kind = document.querySelector('input[name="packageFormKind"]:checked')?.value || 'placement';
		const isBoost = kind === 'boost';
		document.getElementById('packageFormZoneRow').style.display = isBoost ? 'none' : '';
		document.getElementById('packageFormBonusCol').style.display = isBoost ? '' : 'none';
		document.getElementById('packageFormPriorityRow').querySelector('.form-group').style.display = isBoost ? 'none' : '';
		const addonsRow = document.getElementById('packageFormAddonsRow');
		if (addonsRow) addonsRow.style.display = isBoost ? 'none' : '';
		const hint = document.getElementById('packageFormKindHint');
		if (hint) hint.textContent = t(isBoost ? 'panel.ads.kind_boost_hint' : 'panel.ads.kind_placement_hint');
	}

	async function savePackageForm() {
		const val = (id) => document.getElementById(id)?.value || '';
		const payload = {
			id: parseInt(val('packageFormId'), 10) || 0,
			name: val('packageFormName').trim(),
			description: val('packageFormDesc'),
			kind: document.querySelector('input[name="packageFormKind"]:checked')?.value || 'placement',
			zone: val('packageFormZone'),
			priority: parseInt(val('packageFormPriority'), 10) || 10,
			weight_bonus: parseInt(val('packageFormBonus'), 10) || 0,
			addon_zones: collectCurrentPackageState().addon_zones,
			duration_days: parseInt(val('packageFormDays'), 10) || 30,
			amount_minor: Math.round(parseFloat(val('packageFormPrice').replace(',', '.') || '0') * 100),
			currency: val('packageFormCurrency'),
			sort: parseInt(val('packageFormSort'), 10) || 0,
			enabled: document.getElementById('packageFormEnabled').checked
		};
		try {
			const d = await FHApi.post('admin_ad_package_save', payload);
			if (!d.success) { flashMessage('packageFormMessage', d.error || t('common.error'), 'error'); return; }
			closeModal('packageFormModal');
			showNotification(t('panel.ctl.saved'), 'success');
			loadAdsPackages();
		} catch (e) { flashMessage('packageFormMessage', t('common.connection_error'), 'error'); }
	}

	function askDeletePackage(id) {
		const p = adsPackages.find(x => x.id === id);
		showConfirm(t('panel.ads.package_delete'), t('panel.ads.package_delete_q', { name: p ? p.name : '#' + id }), async () => {
			try {
				const d = await FHApi.post('admin_ad_package_delete', { id: id });
				if (!d.success) { showNotification(d.error || t('common.error'), 'error'); return; }
				showNotification(t('panel.ctl.saved'), 'success');
				loadAdsPackages();
			} catch (e) { showNotification(t('common.connection_error'), 'error'); }
		});
	}

	/* ---- Admin: metrics ---- */

	function setAdsRange(days) {
		adsDays = days;
		document.querySelectorAll('#adsRange .scope-btn').forEach(b => {
			b.classList.toggle('active', Number(b.dataset.days) === days);
		});
		loadAdsStats();
	}

	async function loadAdsStats() {
		if (!document.getElementById('adsStatImpressions')) return;
		try {
			const d = await FHApi.get('admin_ads_stats', { days: adsDays });
			if (!d || !d.success) return;
			const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
			set('adsStatImpressions', (d.totals.impressions || 0).toLocaleString());
			set('adsStatClicks', (d.totals.clicks || 0).toLocaleString());
			set('adsStatCtr', (d.totals.ctr || 0) + '%');
			set('adsStatActive', d.totals.active || 0);
			renderAdsChartInto('adsChart', d.series);
			const meta = document.getElementById('adsChartMeta');
			if (meta) meta.textContent = t('panel.ads.chart_meta');
			renderAdsPerAd(d.perAd || []);
		} catch (e) { /* tiles keep their placeholders */ }
	}

	function renderAdsPerAd(rows) {
		const body = document.getElementById('adsPerAdBody');
		if (!body) return;
		if (!rows.length) {
			body.innerHTML = `<tr><td colspan="6" class="empty">${esc(t('panel.ads.none'))}</td></tr>`;
			return;
		}
		body.innerHTML = rows.map(r => `<tr>
			<td><strong>${esc(r.name)}</strong></td>
			<td>${esc(r.zoneLabel || zoneLabel(r.zone))}</td>
			<td>${adStatusBadge(r.status)}</td>
			<td>${Number(r.impressions).toLocaleString()}</td>
			<td>${Number(r.clicks).toLocaleString()}</td>
			<td>${r.ctr}%</td>
		</tr>`).join('');
	}

	/**
	 * Impressions as bars, clicks as a line over them — same hand-built SVG as the other
	 * charts. Clicks ride the impressions scale; with real traffic they are a low line,
	 * which is exactly what a CTR looks like.
	 */
	function renderAdsChartInto(holderId, series) {
		const holder = document.getElementById(holderId);
		if (!holder || !series) return;
		const days = series.days || [];
		const imps = series.impressions || [];
		const clicks = series.clicks || [];
		if (!days.length) { holder.textContent = t('panel.dash.no_data'); return; }

		const W = 640, H = 200, padL = 52, padR = 16, padT = 16, padB = 26;
		const plotW = W - padL - padR, plotH = H - padT - padB;
		const base = padT + plotH;
		const max = Math.max(1, ...imps, ...clicks);
		const bw = plotW / days.length;
		const gap = Math.min(8, bw * 0.3);
		const y = v => padT + plotH - (v / max) * plotH;

		let grid = '';
		for (let i = 0; i <= 4; i++) {
			const val = (max / 4) * i;
			const gy = y(val);
			const dash = i === 4 ? ' stroke-dasharray="5 4"' : (i === 0 ? '' : ' stroke-dasharray="2 5"');
			grid += `<line x1="${padL}" y1="${gy}" x2="${W - padR}" y2="${gy}" stroke="var(--border)" stroke-width="1"${dash}/>`;
			grid += `<text x="${padL - 8}" y="${gy + 3}" font-size="9" fill="var(--text-muted)" text-anchor="end">${Math.round(val)}</text>`;
		}
		grid += `<line x1="${padL}" y1="${base}" x2="${W - padR}" y2="${base}" stroke="var(--border)" stroke-width="1.5"/>`;

		const every = Math.max(1, Math.ceil(days.length / 12));
		let bars = '', line = '';
		days.forEach((day, i) => {
			const v = imps[i] || 0;
			const x = padL + i * bw;
			const w = Math.max(1, bw - gap);
			const by = y(v);
			bars += `<rect x="${x + gap / 2}" y="${by}" width="${w}" height="${Math.max(0, base - by)}" rx="${w > 4 ? 3 : 1}" fill="url(#gAds)">`
				+ `<title>${esc(day)} · ${v} / ${clicks[i] || 0}</title></rect>`;
			line += `${i ? 'L' : 'M'}${(x + bw / 2).toFixed(1)},${y(clicks[i] || 0).toFixed(1)}`;
			if (i % every === 0) {
				bars += `<text x="${x + bw / 2}" y="${H - 8}" font-size="10" fill="var(--text-muted)" text-anchor="middle">${esc(day.slice(5))}</text>`;
			}
		});

		holder.innerHTML = `<svg viewBox="0 0 ${W} ${H}" width="100%" preserveAspectRatio="xMidYMid meet" role="img" aria-label="${esc(t('panel.ads.chart_title'))}">
			<defs><linearGradient id="gAds" x1="0" y1="0" x2="0" y2="1">
				<stop offset="0" stop-color="var(--accent)" stop-opacity="0.95"/>
				<stop offset="1" stop-color="#8b5cf6" stop-opacity="0.5"/>
			</linearGradient></defs>
			${grid}${bars}
			<path d="${line}" fill="none" stroke="var(--success)" stroke-width="2" stroke-linejoin="round"/>
		</svg>`;
	}

	/* ---- Buyer: my ads ---- */

	async function loadMyAds() {
		if (!document.getElementById('myAdsList')) return;
		try {
			const [pk, mine] = await Promise.all([FHApi.get('ad_packages'), FHApi.get('my_ads')]);
			myAdsPackages = (pk && pk.success) ? pk.packages : [];
			myAdsBoosts = (pk && pk.success) ? (pk.boosts || []) : [];
			myAdsList = (mine && mine.success) ? mine.ads : [];
			myAdsInvoicesEnabled = !!(mine && mine.success && mine.invoicesEnabled);
			renderMyAdsPackages();
			renderMyAdsList();
		} catch (e) {
			document.getElementById('myAdsList').innerHTML = `<p class="empty">${esc(t('common.connection_error'))}</p>`;
		}
	}

	function renderMyAdsPackages() {
		const holder = document.getElementById('myAdsPackages');
		if (!holder) return;
		holder.innerHTML = myAdsPackages.length
			? myAdsPackages.map(p => `<div class="myads-package">
					<strong>${esc(p.name)}</strong>
					<span class="myads-package-zone"><i class="fa-solid fa-location-dot"></i> ${esc(p.zoneLabel)}${p.zoneDims ? ` · ${esc(p.zoneDims)} px` : ''}</span>
					${p.description ? `<p>${esc(p.description)}</p>` : ''}
					<div class="myads-package-foot">
						<span><i class="fa-solid fa-clock"></i> ${esc(t('panel.prem.days', { n: p.durationDays }))}</span>
						<strong>${esc(formatMoney(p.amountMinor, p.currency))}</strong>
					</div>
					<button type="button" class="btn btn-primary" data-fh-click="buyPackage(${p.id})">
						<i class="fa-solid fa-cart-shopping"></i> ${esc(t('panel.myads.buy'))}
					</button>
				</div>`).join('')
			: `<p class="empty">${esc(t('panel.myads.no_packages'))}</p>`;
	}

	function renderMyAdsList() {
		const holder = document.getElementById('myAdsList');
		if (!holder) return;
		if (!myAdsList.length) {
			holder.innerHTML = `<p class="empty">${esc(t('panel.myads.none'))}</p>`;
			return;
		}
		holder.innerHTML = myAdsList.map(a => {
			const actions = [];
			if (a.status === 'draft') {
				actions.push(`<button type="button" class="btn btn-primary btn-sm" data-fh-click="payMyAd(${a.id})"><i class="fa-solid fa-credit-card"></i> ${esc(t('panel.myads.pay'))}</button>`);
			}
			// Renewal (runda 4): same package again, no re-review. Active = extend; expired
			// within the grace window = revive from today. The modal (runda 6) lets the
			// buyer drop add-on placements before paying.
			if (a.status === 'active' || (a.status === 'expired' && a.graceUntil)) {
				actions.push(`<button type="button" class="btn btn-primary btn-sm" data-fh-click="openMyAdRenew(${a.id})"><i class="fa-solid fa-rotate-right"></i> ${esc(t('panel.myads.renew'))}</button>`);
			}
			if (['draft', 'pending', 'rejected', 'active'].includes(a.status)) {
				actions.push(`<button type="button" class="btn btn-sm" data-fh-click="editMyAd(${a.id})"><i class="fa-solid fa-pen"></i> ${esc(t('common.edit'))}</button>`);
			}
			if (a.status === 'active' && myAdsBoosts.length) {
				actions.push(`<button type="button" class="btn btn-sm" data-fh-click="openMyAdBoost(${a.id})"><i class="fa-solid fa-bolt"></i> ${esc(t('panel.myads.boost'))}</button>`);
			}
			// The owner's own kill-switch: hides the ad, the clock keeps running (pt 8).
			if (a.status === 'active') {
				actions.push(a.selfPaused
					? `<button type="button" class="btn btn-sm" data-fh-click="toggleMyAdPause(${a.id}, false)"><i class="fa-solid fa-play"></i> ${esc(t('panel.myads.resume'))}</button>`
					: `<button type="button" class="btn btn-sm" data-fh-click="toggleMyAdPause(${a.id}, true)"><i class="fa-solid fa-pause"></i> ${esc(t('panel.myads.pause'))}</button>`);
			}
			if (['active', 'expired'].includes(a.status)) {
				actions.push(`<button type="button" class="btn btn-sm" data-fh-click="toggleMyAdMetrics(${a.id})"><i class="fa-solid fa-chart-line"></i> ${esc(t('panel.myads.metrics'))}</button>`);
			}
			if (myAdsInvoicesEnabled && a.orderId
				&& ['COMPLETED', 'REFUNDED'].includes(a.paymentStatus)) {
				actions.push(`<a class="btn btn-sm" href="${apiUrl}?action=invoice&order=${encodeURIComponent(a.orderId)}" target="_blank" rel="noopener noreferrer"><i class="fa-solid fa-file-invoice"></i> ${esc(t('panel.prem.invoice_btn'))}</a>`);
			}
			const zoneChips = [`<span class="zone-chip">${esc(a.zoneLabel)}</span>`]
				.concat((a.children || []).map(c =>
					`<span class="zone-chip" title="${esc(c.hasOwnBanner ? t('panel.myads.addon_own') : t('panel.myads.addon_reuse'))}">${esc(c.zoneLabel)}${c.hasOwnBanner ? ' <i class="fa-solid fa-image"></i>' : ''}</span>`));
			const previewUrl = safeHttpUrl(a.imageUrl);
			return `<div class="myad-row" id="myAdRow-${a.id}">
				<div class="myad-thumb">${previewUrl ? `<img src="${esc(previewUrl)}" alt="">` : '<i class="fa-solid fa-image"></i>'}</div>
				<div class="myad-meta">
					<strong>${esc(a.name)}</strong>
					<span>${esc(a.package || '—')} · ${zoneChips.join(' ')}</span>
					<span>${adStatusBadge(a.status)}
						${a.selfPaused ? ` <span class="badge badge-muted" title="${esc(t('panel.myads.paused_note'))}"><i class="fa-solid fa-pause"></i> ${esc(t('panel.myads.paused_badge'))}</span>` : ''}
						${a.status === 'active' && a.endsAt ? ` <small>${esc(t('panel.myads.until', { date: formatDate(a.endsAt).split(' ')[0] }))}</small>` : ''}
						${a.status === 'expired' && a.graceUntil ? ` <small style="color:var(--warning)">${esc(t('panel.myads.grace_note', { date: formatDate(a.graceUntil).split(' ')[0] }))}</small>` : ''}
						${a.boostUntil ? ` <span class="badge badge-info" title="${esc(t('panel.myads.boost_active', { n: a.boostWeight, date: formatDate(a.boostUntil).split(' ')[0] }))}"><i class="fa-solid fa-bolt"></i> +${a.boostWeight}</span>` : ''}
						${a.status === 'rejected' && a.rejectReason ? ` <small style="color:var(--danger)">${esc(a.rejectReason)}</small>` : ''}
					</span>
				</div>
				<div class="myad-actions">${actions.join('')}</div>
				<div class="myad-metrics" id="myAdMetrics-${a.id}" style="display:none;"></div>
			</div>`;
		}).join('');
	}

	/** Owner pause/resume, with the no-refund warning spelled out BEFORE the call (pt 8). */
	function toggleMyAdPause(id, paused) {
		const doIt = async () => {
			try {
				const d = await FHApi.post('my_ad_toggle', { id: id, paused: paused });
				if (!d.success) { showNotification(d.error || t('common.error'), 'error'); return; }
				showNotification(t('panel.ctl.saved'), 'success');
				loadMyAds();
			} catch (e) { showNotification(t('common.connection_error'), 'error'); }
		};
		if (paused) {
			showConfirm(t('panel.myads.pause'), t('panel.myads.pause_warn'), doIt,
				{ danger: true, icon: 'fa-pause', confirmLabel: t('panel.myads.pause') });
		} else {
			doIt();
		}
	}

	function buyPackage(pkgId) {
		const p = myAdsPackages.find(x => x.id === pkgId);
		if (!p) return;
		openMyAdForm(null, p);
	}

	let myAdAddonFiles = {};

	function openMyAdForm(ad, pkg) {
		document.getElementById('myAdFormTitle').textContent = t(ad ? 'panel.myads.form_title_edit' : 'panel.myads.form_title');
		document.getElementById('myAdFormMessage').className = 'auth-message';
		document.getElementById('myAdFormId').value = ad ? ad.id : '';
		document.getElementById('myAdFormPackage').value = pkg ? pkg.id : (ad ? ad.packageId : '');
		document.getElementById('myAdFormPackageInfo').textContent = pkg
			? t('panel.myads.form_package_info', { name: pkg.name, zone: pkg.zoneLabel, days: pkg.durationDays, price: formatMoney(pkg.amountMinor, pkg.currency) })
				+ (pkg.zoneDims ? ' · ' + t('panel.ads.form_dims_hint', { dims: pkg.zoneDims }) : '')
			: (ad ? `${ad.package || ''} · ${ad.zoneLabel}` : '');
		document.getElementById('myAdFormName').value = ad ? ad.name : '';
		adUploaderReset('myAdForm');
		myAdFormDimsBox = pkg && pkg.zoneW
			? { w: pkg.zoneW, h: pkg.zoneH }
			: { w: 960, h: 120 };
		const dimsEl = document.getElementById('myAdFormDropDims');
		if (dimsEl) dimsEl.textContent = t('panel.ads.form_dims_hint', { dims: myAdFormDimsBox.w + '×' + myAdFormDimsBox.h });
		const previewUrl = ad ? safeHttpUrl(ad.imageUrl) : '';
		document.getElementById('myAdFormPreview').innerHTML = previewUrl ? `<img src="${esc(previewUrl)}" alt="">` : '';
		document.getElementById('myAdFormTargetUrl').value = ad ? ad.targetUrl : '';
		document.getElementById('myAdFormAlt').value = ad ? ad.altText : '';
		document.getElementById('myAdFormSubmitLabel').textContent = t(ad ? 'common.save' : 'panel.myads.form_submit');
		renderMyAdAddons(ad, pkg);
		openModal('myAdFormModal');
	}

	/**
	 * Add-on placements (pt 7): the package's extra zones, each with its surcharge and an
	 * optional own-banner picker (unchecked or fileless add-ons reuse the main creative,
	 * re-cropped server-side to their zone's box).
	 */
	function renderMyAdAddons(ad, pkg) {
		const box = document.getElementById('myAdFormAddonsBox');
		const holder = document.getElementById('myAdFormAddons');
		myAdAddonFiles = {};
		// Edit mode (runda 5): the same section offers the zones the purchase does NOT
		// occupy yet — checking one leads to the "dopłać" checkout after saving.
		if (ad && !pkg) pkg = myAdsPackages.find(p => p.id === ad.packageId);
		let addons = (pkg && pkg.addonZones) || [];
		if (ad) {
			const owned = [ad.zone].concat((ad.children || []).map(c => c.zone));
			addons = addons.filter(a => owned.indexOf(a.zone) === -1);
		}
		if (!box || !holder || !addons.length) {
			if (box) box.style.display = 'none';
			return;
		}
		box.style.display = '';
		holder.innerHTML = addons.map(a => `<div class="myad-addon-row" data-zone="${esc(a.zone)}" data-w="${a.w || 960}" data-h="${a.h || 120}">
			<label class="form-check">
				<input type="checkbox" class="myad-addon-check" value="${esc(a.zone)}" data-fh-change="myAdAddonToggled(this)">
				<span>${esc(a.pageLabel)} · ${esc(a.label)} <small style="color:var(--text-muted)">${esc(a.dims)} px</small></span>
			</label>
			<strong class="myad-addon-price">+${esc(formatMoney(a.amountMinor, pkg.currency))}</strong>
			<div class="myad-addon-file" style="display:none;">
				<label class="btn btn-sm"><i class="fa-solid fa-image"></i> ${esc(t('panel.myads.addon_own'))}
					<input type="file" accept="image/jpeg,image/png,image/webp,image/gif" hidden
						data-fh-change="myAdAddonFilePicked(this, '${esc(a.zone)}')">
				</label>
				<span class="myad-addon-filename" style="color:var(--text-muted); font-size:0.82rem;">${esc(t('panel.myads.addon_reuse'))}</span>
			</div>
		</div>`).join('');
		updateMyAdTotal(pkg, !!ad);
	}

	function myAdAddonToggled(check) {
		const row = check.closest('.myad-addon-row');
		row.querySelector('.myad-addon-file').style.display = check.checked ? '' : 'none';
		if (!check.checked) {
			delete myAdAddonFiles[check.value];
			const nameEl = row.querySelector('.myad-addon-filename');
			if (nameEl) nameEl.textContent = t('panel.myads.addon_reuse');
		}
		const editing = !!parseInt(document.getElementById('myAdFormId').value, 10);
		const pkgId = parseInt(document.getElementById('myAdFormPackage').value, 10);
		updateMyAdTotal(myAdsPackages.find(p => p.id === pkgId), editing);
		// The submit button says what will happen: pay the surcharge, or just save.
		const label = document.getElementById('myAdFormSubmitLabel');
		if (label && editing) {
			const any = document.querySelectorAll('.myad-addon-check:checked').length > 0;
			label.textContent = t(any ? 'panel.myads.pay_extra' : 'common.save');
		}
	}

	/** An add-on's own banner goes through the same crop stage, at that zone's box. */
	function myAdAddonFilePicked(input, zone) {
		const file = input.files[0];
		const row = input.closest('.myad-addon-row');
		const nameEl = row.querySelector('.myad-addon-filename');
		if (!file) return;
		if (file.type === 'image/gif') {
			myAdAddonFiles[zone] = file;
			if (nameEl) nameEl.textContent = file.name;
			return;
		}
		openAdCrop(file, { w: parseInt(row.dataset.w, 10), h: parseInt(row.dataset.h, 10) }, (cropped) => {
			myAdAddonFiles[zone] = cropped;
			if (nameEl) nameEl.textContent = file.name + ' ✓';
		});
	}

	function updateMyAdTotal(pkg, editing) {
		const totalEl = document.getElementById('myAdFormTotal');
		if (!totalEl || !pkg) return;
		let extra = 0;
		document.querySelectorAll('.myad-addon-check:checked').forEach(c => {
			const a = (pkg.addonZones || []).find(x => x.zone === c.value);
			if (a) extra += a.amountMinor;
		});
		totalEl.textContent = editing
			? (extra > 0 ? t('panel.myads.total_extra', { price: formatMoney(extra, pkg.currency) }) : '')
			: t('panel.myads.total', { price: formatMoney(pkg.amountMinor + extra, pkg.currency) });
	}

	function editMyAd(id) {
		const ad = myAdsList.find(a => a.id === id);
		if (!ad) return;
		// A live ad goes back through review on save (runda 8): say so BEFORE the buyer
		// invests in the edit, not after — the tab intro was the only place this was written.
		if (ad.status === 'active') {
			showConfirm(t('common.edit'), t('panel.myads.edit_warn'), () => openMyAdForm(ad, null),
				{ danger: false, icon: 'fa-pen', confirmLabel: t('common.edit') });
			return;
		}
		openMyAdForm(ad, null);
	}

	async function saveMyAdForm() {
		const val = (id) => document.getElementById(id)?.value || '';
		const id = parseInt(val('myAdFormId'), 10) || 0;
		const payload = {
			id: id,
			package_id: parseInt(val('myAdFormPackage'), 10) || 0,
			name: val('myAdFormName').trim(),
			target_url: val('myAdFormTargetUrl').trim(),
			alt_text: val('myAdFormAlt').trim(),
			addons: Array.from(document.querySelectorAll('.myad-addon-check:checked')).map(c => c.value)
		};
		const file = await adUploaderBlob('myAdForm');
		if (!payload.name) { flashMessage('myAdFormMessage', t('panel.ads.err_name'), 'error'); return; }
		if (!id && !file) { flashMessage('myAdFormMessage', t('panel.myads.err_creative'), 'error'); return; }
		try {
			const d = await FHApi.post('my_ad_save', payload);
			if (!d.success) { flashMessage('myAdFormMessage', d.error || t('common.error'), 'error'); return; }
			const savedId = d.id || id;
			if (file) {
				const fd = new FormData();
				fd.append('id', savedId);
				fd.append('file', file);
				const up = await FHApi.postForm('admin_ad_upload', fd);
				if (!up.success) { flashMessage('myAdFormMessage', up.error || t('common.error'), 'error'); return; }
			}
			// Add-on placements with their own creative get it now; the rest inherit the
			// main banner server-side at checkout.
			for (const child of (d.children || []).concat(d.newChildren || [])) {
				const own = myAdAddonFiles[child.zone];
				if (!own) continue;
				const fd = new FormData();
				fd.append('id', child.id);
				fd.append('file', own);
				await FHApi.postForm('admin_ad_upload', fd).catch(() => { });
			}
			closeModal('myAdFormModal');
			if (!id) {
				// A fresh draft goes straight to checkout — that is what the buyer came to do.
				FHApi.navigatePost('ad_checkout', { ad: savedId });
				return;
			}
			if (d.needPay) {
				// Placements added to a paid purchase: the surcharge checkout (runda 5).
				FHApi.navigatePost('ad_checkout', { ad: savedId, extra: 1 });
				return;
			}
			showNotification(t(d.pending ? 'panel.myads.resubmitted' : 'panel.ctl.saved'), 'success');
			loadMyAds();
		} catch (e) { flashMessage('myAdFormMessage', t('common.connection_error'), 'error'); }
	}

	function payMyAd(id) {
		FHApi.navigatePost('ad_checkout', { ad: id });
	}

	/**
	 * Renewal configuration (runda 6): the base placement is fixed, each add-on can be
	 * unchecked — maybe the extra zones did not earn their surcharge. The dropped ids ride
	 * the checkout URL and are applied at fulfilment.
	 */
	function openMyAdRenew(adId) {
		const ad = myAdsList.find(a => a.id === adId);
		if (!ad) return;
		const pkg = myAdsPackages.find(p => p.id === ad.packageId);
		const currency = ad.currency || (pkg && pkg.currency) || 'PLN';
		const baseMinor = ad.amountMinor || (pkg && pkg.amountMinor) || 0;
		document.getElementById('myAdRenewAdId').value = adId;
		document.getElementById('myAdRenewName').textContent = ad.name;
		const rows = [`<div class="myad-addon-row">
			<label class="form-check">
				<input type="checkbox" checked disabled>
				<span>${esc(ad.zoneLabel)} <small style="color:var(--text-muted)">${esc(t('panel.myads.renew_base'))}</small></span>
			</label>
			<strong class="myad-addon-price">${esc(formatMoney(baseMinor, currency))}</strong>
		</div>`];
		(ad.children || []).forEach(c => {
			const price = (ad.addonPrices || {})[c.zone] || 0;
			rows.push(`<div class="myad-addon-row">
				<label class="form-check">
					<input type="checkbox" class="myad-renew-check" value="${c.id}" data-price="${price}" checked>
					<span>${esc(c.zoneLabel)}</span>
				</label>
				<strong class="myad-addon-price">+${esc(formatMoney(price, currency))}</strong>
			</div>`);
		});
		const holder = document.getElementById('myAdRenewList');
		holder.innerHTML = rows.join('');
		const updateTotal = () => {
			let total = baseMinor;
			holder.querySelectorAll('.myad-renew-check:checked').forEach(c => { total += parseInt(c.dataset.price, 10) || 0; });
			document.getElementById('myAdRenewTotal').textContent = t('panel.myads.total', { price: formatMoney(total, currency) });
		};
		holder.onchange = updateTotal;
		updateTotal();
		openModal('myAdRenewModal');
	}

	function confirmMyAdRenew() {
		const adId = parseInt(document.getElementById('myAdRenewAdId').value, 10);
		const drop = Array.from(document.querySelectorAll('#myAdRenewList .myad-renew-check'))
			.filter(c => !c.checked).map(c => c.value);
		closeModal('myAdRenewModal');
		FHApi.navigatePost('ad_checkout', {
			ad: adId,
			drop: drop.length ? drop.join(',') : undefined
		});
	}

	/** Boost picker: which of the operator's boost packages to apply to this running ad. */
	function openMyAdBoost(adId) {
		const ad = myAdsList.find(a => a.id === adId);
		if (!ad) return;
		document.getElementById('myAdBoostAdId').value = adId;
		document.getElementById('myAdBoostName').textContent = ad.name;
		document.getElementById('myAdBoostList').innerHTML = myAdsBoosts.map(b => `<div class="zone-assign-row">
				<span><strong>${esc(b.name)}</strong>
					<small style="color:var(--text-muted)">+${b.weightBonus} · ${esc(t('panel.prem.days', { n: b.durationDays }))}</small>
					${b.description ? `<br><small>${esc(b.description)}</small>` : ''}
				</span>
				<button type="button" class="btn btn-sm btn-primary" data-fh-click="buyMyAdBoost(${adId}, ${b.id})">
					${esc(formatMoney(b.amountMinor, b.currency))} <i class="fa-solid fa-arrow-right"></i>
				</button>
			</div>`).join('');
		openModal('myAdBoostModal');
	}

	function buyMyAdBoost(adId, pkgId) {
		FHApi.navigatePost('ad_checkout', { ad: adId, pkg: pkgId });
	}

	async function toggleMyAdMetrics(id) {
		const holder = document.getElementById('myAdMetrics-' + id);
		if (!holder) return;
		if (holder.style.display !== 'none') { holder.style.display = 'none'; return; }
		holder.style.display = '';
		holder.innerHTML = `<p class="empty">${esc(t('common.loading'))}</p>`;
		try {
			const d = await FHApi.get('my_ad_metrics', { id: id, days: 30 });
			if (!d.success) { holder.innerHTML = `<p class="empty">${esc(d.error || t('common.error'))}</p>`; return; }
			holder.innerHTML = `<div class="myad-metrics-tiles">
					<span><strong>${Number(d.impressions).toLocaleString()}</strong> ${esc(t('panel.ads.stat_impressions'))}</span>
					<span><strong>${Number(d.clicks).toLocaleString()}</strong> ${esc(t('panel.ads.stat_clicks'))}</span>
					<span><strong>${d.ctr}%</strong> CTR</span>
				</div>
				<div id="myAdChart-${id}"></div>`;
			renderAdsChartInto('myAdChart-' + id, d.series);
		} catch (e) {
			holder.innerHTML = `<p class="empty">${esc(t('common.connection_error'))}</p>`;
		}
	}

	/* ------------------------------------------------------------------ *
	 * Banner uploader (Faza 8 runda 5): dropzone → shared crop MODAL.
	 *
	 * One crop stage serves every banner pick — the main creative of both ad forms and
	 * each add-on placement's own banner — with the frame locked to that slot's zone box
	 * (avatar-cropper UX: rule-of-thirds grid, drag to reposition, wheel/slider to zoom).
	 * Apply exports the frame through a canvas at the zone's EXACT pixel size and stores
	 * the resulting File on the slot; the dropzone then shows the cropped thumbnail.
	 * Animated GIFs skip the stage — a canvas export would freeze the first frame.
	 * ------------------------------------------------------------------ */
	const adUploaders = {};
	const adCrop = { img: null, file: null, dims: null, cb: null, zoom: 1, ox: 0, oy: 0, fw: 0, fh: 0, wired: false };

	function adCropCover() { return Math.max(adCrop.fw / adCrop.img.naturalWidth, adCrop.fh / adCrop.img.naturalHeight); }
	function adCropClamp() {
		const s = adCropCover() * adCrop.zoom;
		adCrop.ox = Math.min(0, Math.max(adCrop.fw - adCrop.img.naturalWidth * s, adCrop.ox));
		adCrop.oy = Math.min(0, Math.max(adCrop.fh - adCrop.img.naturalHeight * s, adCrop.oy));
	}
	function adCropPaint() {
		const imgEl = document.getElementById('adCropImg');
		const s = adCropCover() * adCrop.zoom;
		imgEl.style.width = (adCrop.img.naturalWidth * s) + 'px';
		imgEl.style.height = (adCrop.img.naturalHeight * s) + 'px';
		imgEl.style.transform = `translate(${adCrop.ox}px, ${adCrop.oy}px)`;
	}
	function adCropCenter() {
		if (!adCrop.img) return;
		adCrop.zoom = 1;
		document.getElementById('adCropZoom').value = 100;
		const s = adCropCover();
		adCrop.ox = (adCrop.fw - adCrop.img.naturalWidth * s) / 2;
		adCrop.oy = (adCrop.fh - adCrop.img.naturalHeight * s) / 2;
		adCropPaint();
	}
	function adCropZoomTo(pct) {
		const old = adCropCover() * adCrop.zoom;
		adCrop.zoom = Math.min(3, Math.max(1, pct / 100));
		document.getElementById('adCropZoom').value = Math.round(adCrop.zoom * 100);
		const now = adCropCover() * adCrop.zoom;
		// Keep the frame centre pointing at the same image pixel while zooming.
		adCrop.ox = adCrop.fw / 2 - ((adCrop.fw / 2 - adCrop.ox) / old) * now;
		adCrop.oy = adCrop.fh / 2 - ((adCrop.fh / 2 - adCrop.oy) / old) * now;
		adCropClamp();
		adCropPaint();
	}

	function adCropWire() {
		if (adCrop.wired) return;
		adCrop.wired = true;
		const frame = document.getElementById('adCropFrame');
		const zoomEl = document.getElementById('adCropZoom');
		let dragging = null;
		frame.addEventListener('pointerdown', e => {
			if (!adCrop.img) return;
			e.preventDefault();
			dragging = { x: e.clientX - adCrop.ox, y: e.clientY - adCrop.oy };
			frame.setPointerCapture(e.pointerId);
		});
		frame.addEventListener('pointermove', e => {
			if (!dragging) return;
			adCrop.ox = e.clientX - dragging.x;
			adCrop.oy = e.clientY - dragging.y;
			adCropClamp();
			adCropPaint();
		});
		frame.addEventListener('pointerup', () => { dragging = null; });
		frame.addEventListener('wheel', e => {
			if (!adCrop.img) return;
			e.preventDefault();
			adCropZoomTo(adCrop.zoom * 100 + (e.deltaY < 0 ? 12 : -12));
		}, { passive: false });
		zoomEl.addEventListener('input', () => adCropZoomTo(parseInt(zoomEl.value, 10)));
	}

	/** Open the crop stage for a picked file. cb(File, thumbDataUrl) fires on Apply. */
	function openAdCrop(file, dims, cb) {
		adCropWire();
		adCrop.file = file;
		adCrop.dims = dims;
		adCrop.cb = cb;
		const url = URL.createObjectURL(file);
		const probe = new Image();
		probe.onload = () => {
			adCrop.img = probe;
			const frame = document.getElementById('adCropFrame');
			document.getElementById('adCropModalDims').textContent = dims.w + '×' + dims.h + ' px';
			openModal('adCropModal');
			// Size the on-screen frame to the zone's ratio once the modal has a width.
			requestAnimationFrame(() => {
				const maxW = Math.min(520, frame.parentElement.clientWidth || 520);
				adCrop.fw = maxW;
				adCrop.fh = Math.max(40, Math.round(maxW * dims.h / dims.w));
				frame.style.height = adCrop.fh + 'px';
				document.getElementById('adCropImg').src = url;
				adCropCenter();
			});
		};
		probe.src = url;
	}

	function adCropApply() {
		if (!adCrop.img || !adCrop.cb) { closeModal('adCropModal'); return; }
		const scale = adCropCover() * adCrop.zoom;
		const canvas = document.createElement('canvas');
		canvas.width = adCrop.dims.w;
		canvas.height = adCrop.dims.h;
		canvas.getContext('2d').drawImage(
			adCrop.img,
			-adCrop.ox / scale, -adCrop.oy / scale, adCrop.fw / scale, adCrop.fh / scale,
			0, 0, adCrop.dims.w, adCrop.dims.h
		);
		const type = adCrop.file.type === 'image/png' ? 'image/png' : 'image/jpeg';
		canvas.toBlob(b => {
			const out = b ? new File([b], 'banner.' + (type === 'image/png' ? 'png' : 'jpg'), { type }) : adCrop.file;
			const cb = adCrop.cb;
			closeModal('adCropModal');
			adCrop.img = null;
			adCrop.cb = null;
			cb(out, canvas.toDataURL(type, 0.7));
		}, type, 0.9);
	}

	function adCropCancel() {
		closeModal('adCropModal');
		adCrop.img = null;
		adCrop.cb = null;
	}

	function initAdUploader(prefix, getDims) {
		const drop = document.getElementById(prefix + 'Drop');
		const input = document.getElementById(prefix + 'File');
		if (!drop || !input || adUploaders[prefix]) return;
		const st = adUploaders[prefix] = { getDims, file: null, raw: null };

		function showDone(thumbUrl) {
			document.getElementById(prefix + 'DropIdle').style.display = 'none';
			const done = document.getElementById(prefix + 'DropDone');
			done.style.display = '';
			document.getElementById(prefix + 'DropThumb').src = thumbUrl;
			drop.classList.add('has-file');
		}

		function acceptFile(file) {
			if (!/^image\/(jpeg|png|webp|gif)$/.test(file.type)) return;
			st.raw = file;
			if (file.type === 'image/gif') {
				// As picked — the server leaves GIFs alone and CSS fits them at display time.
				st.file = file;
				showDone(URL.createObjectURL(file));
				showNotification(t('panel.ads.crop_gif_note'), 'success', drop);
				return;
			}
			openAdCrop(file, st.getDims(), (cropped, thumb) => {
				st.file = cropped;
				showDone(thumb);
			});
		}

		drop.addEventListener('click', () => input.click());
		drop.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
		drop.addEventListener('dragover', e => { e.preventDefault(); drop.classList.add('is-over'); });
		drop.addEventListener('dragleave', () => drop.classList.remove('is-over'));
		drop.addEventListener('drop', e => {
			e.preventDefault();
			drop.classList.remove('is-over');
			if (e.dataTransfer.files[0]) acceptFile(e.dataTransfer.files[0]);
		});
		input.addEventListener('change', () => { if (input.files[0]) acceptFile(input.files[0]); });
	}

	/** Re-open the crop stage on the originally picked file (a fresh framing, no re-pick). */
	function adUploaderRecrop(prefix) {
		const st = adUploaders[prefix];
		if (!st || !st.raw || st.raw.type === 'image/gif') return;
		openAdCrop(st.raw, st.getDims(), (cropped, thumb) => {
			st.file = cropped;
			document.getElementById(prefix + 'DropThumb').src = thumb;
		});
	}

	function adCropClear(prefix) {
		const st = adUploaders[prefix];
		if (!st) return;
		st.file = null;
		st.raw = null;
		const input = document.getElementById(prefix + 'File');
		if (input) input.value = '';
		document.getElementById(prefix + 'DropIdle').style.display = '';
		document.getElementById(prefix + 'DropDone').style.display = 'none';
		document.getElementById(prefix + 'Drop').classList.remove('has-file');
	}

	/** The uploader's export: the already-cropped File (or the raw GIF), or null. */
	function adUploaderBlob(prefix) {
		const st = adUploaders[prefix];
		return Promise.resolve(st && st.file ? st.file : null);
	}

	/** Fresh state when a modal opens: no leftover file, idle dropzone. */
	function adUploaderReset(prefix) {
		if (adUploaders[prefix]) adCropClear(prefix);
	}

	window.FHPanelAds = Object.freeze({
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
	});
}());
