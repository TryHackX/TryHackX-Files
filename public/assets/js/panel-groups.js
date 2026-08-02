(function () {
	'use strict';

	const t = (key, params) => window.t(key, params);
	const esc = window.FHUtil.esc;
	const showModal = (id) => window.showModal(id);
	const closeModal = (id) => window.closeModal(id);
	const showNotification = (message, type = 'success', anchor = null) =>
		window.showNotification(message, type, anchor);
	const showConfirm = (...args) => window.showConfirm(...args);
	const renderPager = (...args) => window.FHPanelCore.renderPager(...args);
	const refreshUsers = () => window.FHPanelUsers.refreshCurrentPage();
	const resetManageUserPasswordValidation = () =>
		window.FHPanelCore.resetManageUserPasswordValidation();

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
	 * User groups (A8) — CRUD + assigning a user to a group
	 * ------------------------------------------------------------------ */
	let groupsList = [];

	// Bandwidth is stored in the DB as bytes/s computed as value × 1024^n (matching the
	// Settings → Limits controller, whose "Kb/Mb/Gb" labels are really binary multipliers).
	const BW_MULT = { Kb: 1024, Mb: 1024 * 1024, Gb: 1024 * 1024 * 1024 };
	function bandwidthToBytes(val, unit) {
		const v = parseFloat(val) || 0;
		if (v <= 0) return 0;
		return Math.round(v * (BW_MULT[unit] || BW_MULT.Mb));
	}
	function bytesToBandwidth(bytes) {
		const b = Number(bytes) || 0;
		if (b === 0) return { val: 0, unit: 'Mb' };
		if (b >= BW_MULT.Gb && b % BW_MULT.Gb === 0) return { val: b / BW_MULT.Gb, unit: 'Gb' };
		if (b >= BW_MULT.Mb && b % BW_MULT.Mb === 0) return { val: b / BW_MULT.Mb, unit: 'Mb' };
		return { val: Math.round(b / BW_MULT.Kb), unit: 'Kb' };
	}
	/**
	 * Sizes are stored in whole MiB. These convert between that and the MiB/GiB/TiB the form
	 * shows, so a 5 TiB group limit isn't typed as "5242880" (pt 17). Mirrors the unit
	 * selectors already used for the system-wide limits in Settings → Storage.
	 */
	const MB_MULT = { MB: 1, GB: 1024, TB: 1024 * 1024 };
	const QUOTA_MULT = { GB: 1024 ** 3, TB: 1024 ** 4 };
	function mbToUnit(mb) {
		const v = Number(mb) || 0;
		if (v > 0 && v % MB_MULT.TB === 0) return { val: v / MB_MULT.TB, unit: 'TB' };
		if (v > 0 && v % MB_MULT.GB === 0) return { val: v / MB_MULT.GB, unit: 'GB' };
		return { val: v, unit: 'MB' };
	}

	function unitToMb(val, unit) {
		return Math.round((parseFloat(val) || 0) * (MB_MULT[unit] || 1));
	}
	function bytesToQuotaUnit(bytes) {
		const value = Number(bytes) || 0;
		if (value > 0 && value % QUOTA_MULT.TB === 0) {
			return { val: value / QUOTA_MULT.TB, unit: 'TB' };
		}
		return { val: value > 0 ? value / QUOTA_MULT.GB : 0, unit: 'GB' };
	}
	function quotaUnitToBytes(value, unit) {
		const number = parseFloat(value) || 0;
		return number > 0 ? Math.round(number * (QUOTA_MULT[unit] || QUOTA_MULT.GB)) : 0;
	}

	function bwLabel(bytes) {
		const b = Number(bytes) || 0;
		if (b === 0) return '∞';
		const u = bytesToBandwidth(b);
		return u.val + ' ' + u.unit;
	}

	// The permission catalogue (key → label) ships with the group list so the form and the
	// preview render from the server's definition rather than a copy that can drift.
	let permCatalog = {
		file: {}, filter: {}, collection: {}, cfilter: {}, myfiles: {}, mfilter: {},
		mycoll: {}, mcfilter: {}, ui: {}, ads: {}, moderation: {}, premium: {}, staffOnly: []
	};

	/** Human label for a permission key, from whichever catalogue section defines it. */
	function permLabel(p) {
		return permCatalog.file[p] || permCatalog.filter[p]
			|| permCatalog.collection[p] || permCatalog.cfilter[p]
			|| permCatalog.myfiles[p] || permCatalog.mfilter[p]
			|| permCatalog.mycoll[p] || permCatalog.mcfilter[p]
			|| permCatalog.ui[p]
			|| permCatalog.ads[p] || permCatalog.moderation[p] || permCatalog.premium[p] || p;
	}
	let groupsPage = 1;
	const groupsPerPage = 10;

	async function loadGroups() {
		const tbody = document.getElementById('settingsGroupsBody');
		try {
			const d = await FHApi.get('admin_groups');
			if (d.success) {
				groupsList = d.groups || [];
				if (d.catalog) permCatalog = d.catalog;
				renderSettingsGroups();
			} else if (tbody) {
				tbody.innerHTML = `<tr><td colspan="7" class="empty">${esc(t('panel.grp.load_error'))}</td></tr>`;
			}
		} catch (e) {
			if (tbody) tbody.innerHTML = `<tr><td colspan="7" class="empty">${esc(t('common.connection_error'))}</td></tr>`;
		}
	}

	function loadSettingsGroups() { return loadGroups(); }

	/** Compact "3 permissions" cell with the full list as a tooltip. */
	function permSummaryCell(g) {
		const perms = g.permissions || [];
		if (!perms.length) return `<span style="color:var(--text-muted)">${esc(t('panel.grp.perms_none'))}</span>`;
		const labels = perms.map(permLabel);
		return `<span class="badge badge-muted" title="${esc(labels.join('\n'))}">${esc(t('panel.grp.perms_count', { n: perms.length }))}</span>`;
	}

	function renderSettingsGroups() {
		const body = document.getElementById('settingsGroupsBody');
		if (!body) return;
		if (!groupsList.length) {
			body.innerHTML = `<tr><td colspan="7" class="empty">${esc(t('panel.grp.none'))}</td></tr>`;
			document.getElementById('groupsPagination').innerHTML = '';
			return;
		}

		const total = groupsList.length;
		const totalPages = Math.ceil(total / groupsPerPage);
		if (groupsPage > totalPages) groupsPage = Math.max(1, totalPages);
		const pageRows = groupsList.slice((groupsPage - 1) * groupsPerPage, groupsPage * groupsPerPage);

		body.innerHTML = pageRows.map(g => {
			const mf = mbToUnit(g.max_file_size_mb);
			const isModerator = g.slug === 'moderator';
			const maxFile = Number(g.max_file_size_mb) > 0
				? (mf.val + ' ' + { MB: 'MiB', GB: 'GiB', TB: 'TiB' }[mf.unit])
				: esc(t('panel.grp.system'));
			const maxFiles = Number(g.max_files_per_session) > 0 ? g.max_files_per_session : '∞';
			const isSystem = Number(g.is_system) === 1;
			const badges =
				(isSystem ? `<span class="badge badge-muted">${esc(t('panel.grp.system_badge'))}</span>` : '') +
				(Number(g.is_default) === 1 ? `<span class="badge badge-success">${esc(t('panel.grp.default_badge'))}</span>` : '');
			// System and default groups can't be deleted, so show a padlock in place of the bin
			// rather than an enabled button that would only ever return an error.
			const locked = isSystem || Number(g.is_default) === 1;
			const delBtn = locked
				? `<span class="action-btn locked" title="${esc(t(isSystem ? 'panel.grp.system_lock_tooltip' : 'api.group_default_locked'))}"><i class="fa-solid fa-lock"></i></span>`
				: `<button class="action-btn del" data-fh-click="deleteGroup(${g.id}, '${esc(g.name).replace(/'/g, "\\'")}')" title="${esc(t('common.delete'))}"><i class="fa-solid fa-trash"></i></button>`;
			const members = g.slug === 'guest'
				? '—'
				: (isModerator
					? (Number(g.staff_members) || 0)
					: (g.members || 0));
			return `<tr>
				<td class="col-primary"><div class="group-name-cell"><strong>${esc(g.name)}</strong>${badges ? `<div class="group-name-badges">${badges}</div>` : ''}</div></td>
				<td class="col-center">${maxFile}</td>
				<td class="col-center">${maxFiles}</td>
				<td class="col-center">${bwLabel(g.limit_upload)} / ${bwLabel(g.limit_download)}</td>
				<td class="col-center">${permSummaryCell(g)}</td>
				<td class="col-center">${members}</td>
				<td class="col-actions"><div class="actions">
					<button class="action-btn" data-fh-click="openGroupForm(${g.id})" title="${esc(t('panel.grp.edit_tooltip'))}"><i class="fa-solid fa-pen"></i></button>
					${delBtn}
				</div></td>
			</tr>`;
		}).join('');

		renderPager(document.getElementById('groupsPagination'), totalPages, total, groupsPage, 'goGroupsPage', t('panel.grp.pager_label'));
	}

	function goGroupsPage(p) { groupsPage = p; renderSettingsGroups(); }

	/** Render the permission checkboxes from the server catalogue. */
	function renderPermissionInputs(selected) {
		const set = new Set(selected || []);
		const box = (key, label, cls) => `<label class="perm-item ${cls || ''}">
			<input type="checkbox" class="perm-check" value="${esc(key)}" ${set.has(key) ? 'checked' : ''} data-fh-change="onPermToggle()">
			<span>${esc(label)}</span>
		</label>`;

		const fill = (id, map, cls) => {
			const el = document.getElementById(id);
			if (el) el.innerHTML = Object.keys(map || {}).map(k => box(k, map[k], cls)).join('');
		};
		fill('grpPermList', permCatalog.file);
		fill('grpFilterList', permCatalog.filter, 'perm-sub');
		fill('grpCollList', permCatalog.collection);
		fill('grpCFilterList', permCatalog.cfilter, 'perm-sub');
		fill('grpMyList', permCatalog.myfiles);
		fill('grpUiList', permCatalog.ui);
		fill('grpMFilterList', permCatalog.mfilter, 'perm-sub');
		fill('grpMyCollList', permCatalog.mycoll);
		fill('grpMCFilterList', permCatalog.mcfilter, 'perm-sub');
		fill('grpAdsList', permCatalog.ads);
		fill('grpModerationList', permCatalog.moderation);
		fill('grpPremiumStaffList', permCatalog.premium);
		onPermToggle();
	}

	/**
	 * Mirror the server's dependency rules live, so the form can't offer a combination the
	 * backend would silently drop: everything hangs off "view all files"; the advanced-filter
	 * list needs "advanced filters"; and the collection filters (pt 4) need the collections
	 * list plus its own filter permission.
	 */
	function onPermToggle() {
		const checks = Array.from(document.querySelectorAll('#grpPermsSection .perm-check'));
		const on = k => checks.some(c => c.value === k && c.checked);
		const viewAll = on('files.view_all');
		const advanced = viewAll && on('files.advanced_filters');
		const collView = viewAll && on('collections.view_all');
		const collFilters = collView && on('collections.filters');

		// pt 8: "My files" is its own root — it hangs off nothing, because every account has
		// that tab whether or not it may see anyone else's uploads.
		const myFilters = on('myfiles.filters');
		// pt 4: and so is its collections half.
		const myColl = on('myfiles.collections');
		const myCollFilters = myColl && on('myfiles.coll_filters');
		const reportsView = on('moderation.reports.view');
		const premiumMetrics = on('premium.metrics');
		const premiumPayments = premiumMetrics && on('premium.payments');
		const adsApprove = on('ads.approve');

		checks.forEach(c => {
			const v = c.value;
			let disabled;
			if (v.indexOf('mcfilter.') === 0) disabled = !myCollFilters;
			else if (v === 'myfiles.collections' || v === 'myfiles.filters') disabled = false;
			else if (v.indexOf('myfiles.coll_') === 0) disabled = !myColl;
			else if (v.indexOf('mfilter.') === 0) disabled = !myFilters;
			else if (v.indexOf('filter.') === 0) disabled = !advanced;
			else if (v.indexOf('cfilter.') === 0) disabled = !collFilters;
			else if (v === 'collections.view_all') disabled = !viewAll;
			else if (v.indexOf('collections.') === 0) disabled = !collView;
			else if (v === 'ads.refund') disabled = !adsApprove;
			// Advertising permissions do not depend on the file browser.
			else if (v.indexOf('ads.') === 0) disabled = false;
			else if (v === 'moderation.reports.resolve' || v === 'moderation.files.delete') disabled = !reportsView;
			else if (v.indexOf('moderation.') === 0) disabled = false;
			else if (v === 'premium.payments') disabled = !premiumMetrics;
			else if (v === 'premium.refunds') disabled = !premiumPayments;
			else if (v.indexOf('premium.') === 0) disabled = false;
			else if (v.indexOf('tables.') === 0) disabled = false;
			else disabled = v !== 'files.view_all' && !viewAll;

			c.disabled = disabled;
			if (disabled) c.checked = false;
			c.closest('.perm-item').classList.toggle('perm-disabled', disabled);
		});
		document.getElementById('grpMFilterBlock').style.display = myFilters ? 'block' : 'none';
		document.getElementById('grpMCFilterBlock').style.display = myCollFilters ? 'block' : 'none';
		document.getElementById('grpFilterBlock').style.display = advanced ? 'block' : 'none';
		document.getElementById('grpCollBlock').style.display = viewAll ? 'block' : 'none';
		document.getElementById('grpCFilterBlock').style.display = collFilters ? 'block' : 'none';
	}

	function collectPermissions() {
		return Array.from(document.querySelectorAll('#grpPermsSection .perm-check'))
			.filter(c => c.checked && !c.disabled)
			.map(c => c.value);
	}

	function openGroupForm(id) {
		const g = id ? groupsList.find(x => Number(x.id) === Number(id)) : null;
		const isGuest = !!(g && g.slug === 'guest');
		const isModerator = !!(g && g.slug === 'moderator');
		document.getElementById('grpFormTitle').textContent = g ? g.name : t('panel.grp.new');
		document.getElementById('grpId').value = g ? g.id : '';
		document.getElementById('grpName').value = g ? g.name : '';
		const maxFile = mbToUnit(g ? g.max_file_size_mb : 0);
		document.getElementById('grpMaxFileSize').value = maxFile.val || '';
		document.getElementById('grpMaxFileUnit').value = maxFile.unit;
		document.getElementById('grpMaxFiles').value = g ? (g.max_files_per_session || '') : '';
		const quota = mbToUnit(g ? g.storage_quota_mb : 0);
		document.getElementById('grpStorageQuota').value = quota.val || '';
		document.getElementById('grpQuotaUnit').value = quota.unit;
		const transferQuota = bytesToQuotaUnit(g ? g.transfer_quota_bytes : 0);
		document.getElementById('grpTransferQuota').value = transferQuota.val || '';
		document.getElementById('grpTransferQuotaUnit').value = transferQuota.unit;
		document.getElementById('grpTransferPeriod').value =
			(g && ['day', 'week', 'month', 'year'].includes(g.transfer_quota_period))
				? g.transfer_quota_period : 'week';
		document.getElementById('grpConcDl').value = g ? (g.concurrent_downloads || '') : '';
		document.getElementById('grpConnPerFile').value = g ? (g.concurrent_connections_per_file || '') : '';
		// pt 6: -1 in the column is the "never" state; anything else is a day count (0 = follow
		// the installation default).
		const retention = g ? parseInt(g.auto_delete_days) || 0 : 0;
		document.getElementById('grpAutoDeleteNever').checked = retention < 0;
		document.getElementById('grpAutoDelete').value = retention > 0 ? retention : '';
		onGroupRetentionToggle();
		const up = bytesToBandwidth(g ? g.limit_upload : 0);
		const down = bytesToBandwidth(g ? g.limit_download : 0);
		document.getElementById('grpUpLimit').value = up.val || '';
		document.getElementById('grpUpUnit').value = up.unit;
		document.getElementById('grpDownLimit').value = down.val || '';
		document.getElementById('grpDownUnit').value = down.unit;
		// The guest group can never be the default (guests have no account to default to), and
		// editing the current default keeps the box checked and disabled — un-defaulting happens
		// by marking a different group default.
		const defBox = document.getElementById('grpIsDefault');
		defBox.checked = !!(g && Number(g.is_default) === 1);
		defBox.disabled = isGuest || isModerator || !!(g && Number(g.is_default) === 1);
		defBox.closest('.form-group').style.display = (isGuest || isModerator) ? 'none' : '';
		document.getElementById('grpLimitsSection').style.display = '';
		document.getElementById('grpModeratorGroupNote').style.display = isModerator ? 'block' : 'none';

		// Guests have no account, so permissions have nothing to attach to.
		document.getElementById('grpPermsSection').style.display = isGuest ? 'none' : '';
		document.getElementById('grpGuestPermNote').style.display = isGuest ? 'block' : 'none';
		if (!isGuest) renderPermissionInputs(g ? g.permissions : []);

		const msg = document.getElementById('groupsMessage');
		if (msg) { msg.textContent = ''; msg.className = 'auth-message'; }
		showModal('groupsModal');
	}

	/** "Never delete" and a day count are mutually exclusive — grey the number out (pt 6). */
	function onGroupRetentionToggle() {
		const never = document.getElementById('grpAutoDeleteNever').checked;
		const days = document.getElementById('grpAutoDelete');
		days.disabled = never;
		if (never) days.value = '';
	}

	async function saveGroup() {
		const name = document.getElementById('grpName').value.trim();
		if (!name) { flashMessage('groupsMessage', t('panel.grp.need_name'), 'error'); return; }
		const body = {
			id: document.getElementById('grpId').value || '',
			name,
			max_file_size_mb: unitToMb(document.getElementById('grpMaxFileSize').value, document.getElementById('grpMaxFileUnit').value),
			max_files_per_session: parseInt(document.getElementById('grpMaxFiles').value) || 0,
			storage_quota_mb: unitToMb(document.getElementById('grpStorageQuota').value, document.getElementById('grpQuotaUnit').value),
			transfer_quota_bytes: quotaUnitToBytes(document.getElementById('grpTransferQuota').value, document.getElementById('grpTransferQuotaUnit').value),
			transfer_quota_period: document.getElementById('grpTransferPeriod').value,
			concurrent_downloads: parseInt(document.getElementById('grpConcDl').value) || 0,
			concurrent_connections_per_file: parseInt(document.getElementById('grpConnPerFile').value) || 0,
			limit_upload: bandwidthToBytes(document.getElementById('grpUpLimit').value, document.getElementById('grpUpUnit').value),
			limit_download: bandwidthToBytes(document.getElementById('grpDownLimit').value, document.getElementById('grpDownUnit').value),
			is_default: document.getElementById('grpIsDefault').checked,
			auto_delete_days: document.getElementById('grpAutoDeleteNever').checked
				? -1
				: (parseInt(document.getElementById('grpAutoDelete').value) || 0),
			permissions: collectPermissions()
		};
		try {
			const d = await FHApi.post('admin_group_save', body);
			if (d.success) {
				showNotification(t('panel.grp.saved'), 'success');
				closeModal('groupsModal');
				await loadGroups();
				refreshUsers(); // group name column may have changed
			} else {
				flashMessage('groupsMessage', d.error || t('panel.grp.save_error'), 'error');
			}
		} catch (e) {
			flashMessage('groupsMessage', t('common.connection_error'), 'error');
		}
	}

	function deleteGroup(id, name) {
		showConfirm(t('panel.grp.del_title'), t('panel.grp.del_confirm', { name: name }), async () => {
			try {
				const d = await FHApi.post('admin_group_delete', { id });
				if (d.success) {
					showNotification(t('panel.grp.deleted'), 'success');
					await loadGroups();
					refreshUsers();
				} else {
					showNotification(d.error || t('panel.grp.del_error'), 'error');
				}
			} catch (e) {
				showNotification(t('common.connection_error'), 'error');
			}
		});
	}

	async function openSetUserGroup(userId, username, currentGroupId) {
		document.getElementById('sugUserId').value = userId;
		document.getElementById('sugUserName').textContent = username;
		const msg = document.getElementById('setUserGroupMessage');
		if (msg) { msg.textContent = ''; msg.className = 'auth-message'; }
		// Ensure we have the group list (and the permission catalogue) for the dropdown.
		if (!groupsList.length) await loadGroups();
		const sel = document.getElementById('sugGroupSelect');
		// Guest is anonymous-only; Moderator is a role-bound permission group. Neither is a
		// plan/limits assignment.
		sel.innerHTML = `<option value="0">${esc(t('panel.grp.default_option'))}</option>` +
			groupsList.filter(g => g.slug !== 'guest' && g.slug !== 'moderator')
				.map(g => `<option value="${g.id}">${esc(g.name)}${Number(g.is_default) === 1 ? esc(t('panel.grp.default_suffix')) : ''}</option>`).join('');
		sel.value = String(currentGroupId || 0);
		const u = window.FHPanelState.getUsers()
			.find(x => Number(x.id) === Number(userId));
		sugPreviewRole = u ? (u.role || 'user') : 'user';
		renderGroupPreview();
		showModal('setUserGroupModal');
	}

	// Role of the user whose group is being changed — decides whether staff-only permissions
	// (IP visibility) would actually apply, matching Permissions::forGroup() on the server.
	let sugPreviewRole = 'user';

	/** pt 11: summarise what the selected group grants, before it is applied. */
	function renderGroupPreview() {
		const body = document.getElementById('sugPreviewBody');
		if (!body) return;
		const sel = document.getElementById('sugGroupSelect');
		const id = parseInt(sel.value) || 0;
		const g = id ? groupsList.find(x => Number(x.id) === id) : groupsList.find(x => Number(x.is_default) === 1);

		if (sugPreviewRole === 'admin') {
			body.innerHTML = `<p class="perm-preview-note">${esc(t('panel.grp.preview_admin'))}</p>`;
			return;
		}
		if (!g) { body.innerHTML = `<p class="perm-preview-note">${esc(t('panel.grp.preview_none'))}</p>`; return; }

		const staffOnly = permCatalog.staffOnly || [];
		// The selected group is the account's plan/limit group. Staff-only grants come from
		// the automatic Moderator system group, never from this assignment.
		const effective = (g.permissions || []).filter(p => staffOnly.indexOf(p) < 0);

		// Limits as labelled pairs rather than a run-on sentence, so each value is scannable.
		const mf = mbToUnit(g.max_file_size_mb);
		const quota = mbToUnit(g.storage_quota_mb);
		const limitRows = [
			[t('panel.grp.th_max_file'), Number(g.max_file_size_mb) > 0
				? mf.val + ' ' + { MB: 'MiB', GB: 'GiB', TB: 'TiB' }[mf.unit] : t('panel.grp.system')],
			[t('panel.grp.th_files_session'), Number(g.max_files_per_session) > 0 ? g.max_files_per_session : '∞'],
			[t('panel.grp.quota'), Number(g.storage_quota_mb) > 0
				? quota.val + ' ' + { MB: 'MiB', GB: 'GiB', TB: 'TiB' }[quota.unit] : '∞'],
			[t('panel.grp.th_updown'), bwLabel(g.limit_upload) + ' / ' + bwLabel(g.limit_download)]
		];
		const limitsHtml = `<dl class="perm-preview-grid">${limitRows.map(([k, v]) =>
			`<dt>${esc(k)}</dt><dd>${esc(String(v))}</dd>`).join('')}</dl>`;

		// Browsing capabilities and the individual filters they unlock are separate ideas —
		// listing them in one flat run made a filter look as significant as "see every file".
		const filePerms = effective.filter(p => p.indexOf('files.') === 0);
		const filterPerms = effective.filter(p => p.indexOf('filter.') === 0);
		const list = (perms) => `<ul class="perm-preview-list">${perms.map(p =>
			`<li><i class="fa-solid fa-check"></i> ${esc(permLabel(p))}</li>`).join('')}</ul>`;

		let permsHtml = '';
		if (!effective.length) {
			permsHtml = `<p class="perm-preview-note">${esc(t('panel.grp.preview_none'))}</p>`;
		} else {
			if (filePerms.length) {
				permsHtml += `<h5>${esc(t('panel.grp.perms_title'))}</h5>${list(filePerms)}`;
			}
			if (filterPerms.length) {
				permsHtml += `<h5>${esc(t('panel.grp.filters_title'))}</h5>${list(filterPerms)}`;
			}
		}

		body.innerHTML = `<h5>${esc(t('panel.grp.preview_limits'))}</h5>${limitsHtml}${permsHtml}`;
	}

	async function confirmSetUserGroup() {
		const userId = parseInt(document.getElementById('sugUserId').value);
		const groupId = parseInt(document.getElementById('sugGroupSelect').value) || 0;
		try {
			const d = await FHApi.post('admin_set_user_group', { user_id: userId, group_id: groupId });
			if (d.success) {
				closeModal('setUserGroupModal');
				showNotification(t('panel.grp.changed'), 'success');
				refreshUsers();
			} else {
				flashMessage('setUserGroupMessage', d.error || t('common.error'), 'error');
			}
		} catch (e) {
			flashMessage('setUserGroupMessage', t('common.connection_error'), 'error');
		}
	}

	/* ---- manage user: role / storage limit / password reset (Faza 2.2) ---- */
	async function openManageUser(userId) {
		const u = window.FHPanelState.getUsers()
			.find(x => Number(x.id) === Number(userId));
		if (!u) return;
		document.getElementById('muUserId').value = u.id;
		document.getElementById('muUserName').textContent = u.username;
		const roleSelect = document.getElementById('muRole');
		roleSelect.value = u.role || 'user';
		roleSelect.disabled = window.FHPanelState.isRootAdmin(u.id);
		onManageRoleChange();
		// storage_limit is stored in bytes; show MiB (0 = unlimited).
		const mb = Math.round((Number(u.storage_limit) || 0) / (1024 * 1024));
		document.getElementById('muStorage').value = mb > 0 ? mb : '';
		document.getElementById('muPassword').value = '';
		document.getElementById('muPassword2').value = '';
		resetManageUserPasswordValidation();
		const msg = document.getElementById('manageUserMessage');
		if (msg) { msg.textContent = ''; msg.className = 'auth-message'; }
		showModal('manageUserModal');
	}

	async function saveManageUser() {
		const userId = parseInt(document.getElementById('muUserId').value);
		const role = document.getElementById('muRole').value;
		const storageMb = document.getElementById('muStorage').value;
		const pw = document.getElementById('muPassword').value;
		const pw2 = document.getElementById('muPassword2').value;
		if (pw !== '') {
			const minimum = Number(document.getElementById('muPassword').minLength) || 8;
			if (pw.length < minimum) { flashMessage('manageUserMessage', t('panel.mu.pw_short_configured', { min: minimum }), 'error'); return; }
			if (pw !== pw2) { flashMessage('manageUserMessage', t('panel.mu.pw_mismatch'), 'error'); return; }
		}
		const body = {
			user_id: userId,
			role,
			storage_limit_mb: storageMb === '' ? '' : (parseInt(storageMb) || 0)
		};
		if (pw !== '') body.password = pw;
		try {
			const d = await FHApi.post('admin_update_user', body);
			if (d.success) {
				flashMessage('manageUserMessage', t('panel.mu.saved'), 'success');
				setTimeout(() => { closeModal('manageUserModal'); refreshUsers(); }, 900);
			} else {
				flashMessage('manageUserMessage', d.error || t('panel.mu.save_error'), 'error');
			}
		} catch (e) {
			flashMessage('manageUserMessage', t('common.connection_error'), 'error');
		}
	}

	function onManageRoleChange() {
		const role = document.getElementById('muRole')?.value || 'user';
		const moderatorNote = document.getElementById('muModeratorGroupNote');
		const adminNote = document.getElementById('muAdminPermissionsNote');
		if (moderatorNote) moderatorNote.style.display = role === 'moderator' ? '' : 'none';
		if (adminNote) adminNote.style.display = role === 'admin' ? '' : 'none';
	}

	window.FHPanelGroups = Object.freeze({
		loadGroups, loadSettingsGroups, openGroupForm, saveGroup, deleteGroup,
		goGroupsPage, onPermToggle, onGroupRetentionToggle, openSetUserGroup,
		confirmSetUserGroup, renderGroupPreview, openManageUser, saveManageUser,
		onManageRoleChange
	});
}());
