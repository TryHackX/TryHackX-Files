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
	const perPage = 20;
	const inputLimits = PANEL.inputLimits || { usernameMin: 3, passwordMin: 8 };
	const t = (key, params) => window.t(key, params);
	const esc = window.FHUtil.esc;
	const formatSize = window.FHUtil.formatSize;
	const formatDate = window.FHUtil.formatDate;
	const roleLabel = (role) => {
		const key = {
			user: 'panel.users.role_user',
			moderator: 'panel.users.role_moderator',
			admin: 'panel.users.role_admin'
		}[role];
		return key ? t(key) : role;
	};
	const fetchLive = (...args) => window.FHPanelCore.fetchLive(...args);
	const showSkeleton = (...args) => window.FHPanelCore.showSkeleton(...args);
	const finishSkeleton = (...args) => window.FHPanelCore.finishSkeleton(...args);
	const resetCreateUserValidation = () => window.FHPanelCore.resetCreateUserValidation();
	const showModal = (id) => window.showModal(id);
	const closeModal = (id) => window.closeModal(id);
	const showNotification = (...args) => window.showNotification(...args);
	const flashMessage = (...args) => window.flashMessage(...args);

	let users = [];
	let userPage = 1;
	let userSorts = [{ col: 'created_at', dir: 'desc' }];
	let totalUsers = 0;
	let rootAdminId = 0;
	let pendingUserAction = null;
	let pendingUserId = null;
	/* ------------------------------------------------------------------ *
	 * Users tab (admin)
	 * ------------------------------------------------------------------ */
	async function loadUsers(page = 1, silent = false) {
		userPage = page;
		const tbody = document.getElementById('usersBody');
		if (!tbody) return;
		if (!silent) showSkeleton('usersBody', 9);
		try {
			const primarySort = userSorts[0] || { col: 'created_at', dir: 'desc' };
			const params = new URLSearchParams({
				action: 'admin_users',
				page: userPage,
				sort_by: primarySort.col,
				order: primarySort.dir
			});
			if (userSorts.length > 1) {
				params.set('sorts', JSON.stringify(userSorts.map(sort => ({
					key: sort.col,
					dir: sort.dir
				}))));
			}
			const r = await fetchLive(`${apiUrl}?${params}`, 'users', silent);
			if (!r.notModified && r.data && r.data.success) {
				users = r.data.users;
				totalUsers = r.data.total || 0;
				rootAdminId = r.data.rootId || 0;
				renderUsers();
				renderUserPagination();
			}
		} catch (e) {
			if (!silent) tbody.innerHTML = `<tr><td colspan="9" class="empty">${esc(t('panel.users.load_error'))}</td></tr>`;
		} finally {
			if (!silent) finishSkeleton('usersBody');
		}
	}

	function sortUsers(col, event) {
		const index = userSorts.findIndex(sort => sort.col === col);
		const existing = index >= 0 ? userSorts[index] : null;
		if (event && event.shiftKey && (PANEL.isAdmin || (PANEL.perms || []).includes('tables.multi_sort'))) {
			if (!existing) userSorts.push({ col, dir: 'desc' });
			else if (existing.dir === 'desc') existing.dir = 'asc';
			else userSorts.splice(index, 1);
		} else {
			userSorts = !existing
				? [{ col, dir: 'desc' }]
				: (existing.dir === 'desc' ? [{ col, dir: 'asc' }] : []);
		}
		document.querySelectorAll('th[data-fh-click^="sortUsers"]').forEach(th => {
			const icon = th.querySelector('.sort-icon');
			th.classList.remove('sorted');
			const index = userSorts.findIndex(sort => sort.col === th.dataset.sort);
			if (index >= 0) {
				th.classList.add('sorted');
				icon.innerHTML = (userSorts[index].dir === 'asc' ? '▲' : '▼')
					+ (userSorts.length > 1 ? `<sup>${index + 1}</sup>` : '');
			} else if (icon) {
				icon.textContent = '';
			}
		});
		loadUsers(1);
	}

	function renderUsers() {
		const tbody = document.getElementById('usersBody');
		if (!users.length) { tbody.innerHTML = `<tr><td colspan="9" class="empty">${esc(t('panel.users.none'))}</td></tr>`; return; }

		tbody.innerHTML = users.map(u => {
			const role = u.role || 'user';
			const isActive = u.is_active == 1;
			const statusBadge = isActive
				? `<span class="badge badge-success">${esc(t('panel.users.active'))}</span>`
				: `<span class="badge badge-danger">${esc(t('panel.users.inactive'))}</span>`;
			const storageText = u.storage_used ? formatSize(u.storage_used) : '0 B';
			const safeName = esc(u.username).replace(/'/g, "\\'");
			// group_name is NULL when the user has no explicit group → they resolve to the default.
			const groupLabel = u.group_name ? esc(u.group_name) : esc(t('panel.users.group_default'));
			const groupCls = u.group_name ? 'badge-info' : 'badge-muted';
			// The role-bound Moderator group is shown next to (not instead of) the independent
			// plan group. A moderator with Premium therefore has both badges.
			const moderatorGroup = role === 'moderator'
				? `<span class="badge ${u.staff_group_name ? 'badge-info' : 'badge-muted'}"
					title="${esc(t('panel.mu.staff_profile'))}">${esc(u.staff_group_name || t('panel.mu.staff_profile_none'))}</span>`
				: '';
			// pt 4: the owner account cannot be deleted (the API refuses), so it gets a padlock
			// instead of a button — the same treatment a built-in language gets.
			const isRoot = rootAdminId > 0 && u.id === rootAdminId;
			const delBtn = isRoot
				? `<span class="action-btn locked" title="${esc(t('panel.users.root_lock'))}"><i class="fa-solid fa-shield-halved"></i></span>`
				: `<button class="action-btn del" data-fh-click="userAction(${u.id}, 'delete', '${safeName}')" title="${esc(t('common.delete'))}"><i class="fa-solid fa-trash"></i></button>`;
			return `<tr>
				<td class="col-primary"><strong>${esc(u.username)}</strong></td>
				<td class="col-text col-email" title="${esc(u.email || '-')}">${esc(u.email || '-')}</td>
				<td><span class="badge badge-info">${esc(roleLabel(role))}</span></td>
				<td><div class="user-group-badges">
					<span class="badge ${groupCls}">${groupLabel}</span>
					${moderatorGroup}
				</div></td>
				<td>${statusBadge}</td>
				<td>${u.files_count || 0}</td>
				<td>${storageText}</td>
				<td class="col-date">${formatDate(u.created_at)}</td>
				<td class="col-actions"><div class="actions">
					<button class="action-btn" data-fh-click="openManageUser(${u.id})" title="${esc(t('panel.users.manage_tooltip'))}"><i class="fa-solid fa-pen"></i></button>
					<button class="action-btn" data-fh-click="openSetUserGroup(${u.id}, '${safeName}', ${u.group_id || 0})" title="${esc(t('panel.users.group_tooltip'))}"><i class="fa-solid fa-users"></i></button>
					<button class="action-btn" data-fh-click="openPlanGrant(${u.id}, '${safeName}')" title="${esc(t('panel.prem.grant_title'))}"><i class="fa-solid fa-gem"></i></button>
					${!isActive ? `<button class="action-btn" data-fh-click="userAction(${u.id}, 'activate', '${safeName}')" title="${esc(t('panel.users.activate_tooltip'))}"><i class="fa-solid fa-circle-check"></i></button>` : ''}
					${isActive && !isRoot ? `<button class="action-btn" data-fh-click="openBanModal(${u.id}, '${safeName}', '${esc(u.last_ip || '')}')" title="${esc(t('panel.users.ban_tooltip'))}"><i class="fa-solid fa-ban"></i></button>` : ''}
					${isActive && !isRoot ? `<button class="action-btn" data-fh-click="userAction(${u.id}, 'deactivate', '${safeName}')" title="${esc(t('panel.users.deactivate_tooltip'))}"><i class="fa-solid fa-pause"></i></button>` : ''}
					<button class="action-btn" data-fh-click="userAction(${u.id}, 'clean', '${safeName}', ${u.files_count})" title="${esc(t('panel.users.clean_tooltip'))}"><i class="fa-solid fa-broom"></i></button>
					${delBtn}
				</div></td>
			</tr>`;
		}).join('');
	}

	function renderUserPagination() {
		const el = document.getElementById('userPagination');
		if (!el) return;
		const totalPages = Math.ceil(totalUsers / perPage);
		if (totalPages <= 1) { el.innerHTML = ''; return; }
		let html = '<div class="pagination">';
		if (userPage > 1) html += `<button data-fh-click="loadUsers(${userPage - 1})">&laquo;</button>`;
		const start = Math.max(1, userPage - 2), end = Math.min(totalPages, userPage + 2);
		for (let i = start; i <= end; i++) {
			html += `<button class="${i === userPage ? 'active' : ''}" data-fh-click="loadUsers(${i})">${i}</button>`;
		}
		if (userPage < totalPages) html += `<button data-fh-click="loadUsers(${userPage + 1})">&raquo;</button>`;
		html += '</div>';
		el.innerHTML = html;
	}

	function userAction(userId, action, username, extraData = null) {
		pendingUserId = userId;
		pendingUserAction = action;
		const titles = {
			activate: t('panel.ua.activate_title'), deactivate: t('panel.ua.deactivate_title'),
			clean: t('panel.ua.clean_title'), delete: t('panel.ua.delete_title')
		};
		const messages = {
			activate: t('panel.ua.activate_msg', { name: username }),
			deactivate: t('panel.ua.deactivate_msg', { name: username }),
			// The count is only known when the row supplied it; otherwise say "all".
			clean: extraData
				? t('panel.ua.clean_msg', { n: extraData, name: username })
				: t('panel.ua.clean_msg_all', { name: username }),
			delete: t('panel.ua.delete_msg', { name: username })
		};
		document.getElementById('userActionTitle').textContent = titles[action] || t('panel.modal.confirm');
		document.getElementById('userActionMessageText').textContent = messages[action] || t('panel.modal.are_you_sure');
		showModal('userActionModal');
	}

	async function executeUserAction() {
		if (!pendingUserId || !pendingUserAction) return;
		try {
			const d = await FHApi.post('admin_user_action', { user_id: pendingUserId, action: pendingUserAction });
			if (d.success) {
				flashMessage('userActionMessage', t('panel.ua.ok'), 'success');
				setTimeout(() => { closeModal('userActionModal'); loadUsers(userPage); pendingUserId = null; pendingUserAction = null; }, 900);
			} else {
				flashMessage('userActionMessage', d.error || t('common.error'), 'error');
			}
		} catch (e) {
			flashMessage('userActionMessage', t('common.connection_error'), 'error');
		}
	}

	function openBanModal(id, username, ip) {
		document.getElementById('banTargetUser').innerText = username;
		document.getElementById('banTargetId').value = id;
		document.getElementById('banIPLabel').innerText = ip ? t('panel.modal.ban_ip_with', { ip: ip }) : t('panel.modal.ban_ip_unknown');
		document.getElementById('banEmail').checked = false;
		document.getElementById('banName').checked = false;
		document.getElementById('banIP').checked = false;
		document.getElementById('advBanReason').value = '';
		document.getElementById('advBanDuration').value = '0';
		showModal('advancedBanModal');
	}

	async function executeAdvancedBan() {
		const userId = document.getElementById('banTargetId').value;
		try {
			const d = await FHApi.post('admin_user_action', {
				user_id: userId, action: 'ban_advanced',
				ban_options: {
					ban_email: document.getElementById('banEmail').checked,
					ban_name: document.getElementById('banName').checked,
					ban_ip: document.getElementById('banIP').checked,
					reason: document.getElementById('advBanReason').value,
					expires_in: document.getElementById('advBanDuration').value
				}
			});
			if (d.success) {
				showNotification(t('panel.ua.banned'), 'success');
				closeModal('advancedBanModal');
				loadUsers(userPage);
			} else {
				showNotification(d.error || t('common.error'), 'error');
			}
		} catch (e) {
			showNotification(t('common.connection_error'), 'error');
		}
	}

	function showCreateUserModal() {
		document.getElementById('newUsername').value = '';
		document.getElementById('newEmail').value = '';
		document.getElementById('newPassword').value = '';
		document.getElementById('newPassword2').value = '';
		document.getElementById('newRole').value = 'user';
		document.getElementById('newAutoActivate').checked = true;
		const msg = document.getElementById('createUserMessage');
		if (msg) { msg.textContent = ''; msg.className = 'auth-message'; }
		resetCreateUserValidation();
		showModal('createUserModal');
	}

	async function createUser() {
		const username = document.getElementById('newUsername').value.trim();
		const email = document.getElementById('newEmail').value.trim();
		const password = document.getElementById('newPassword').value;
		const password2 = document.getElementById('newPassword2').value;
		const role = document.getElementById('newRole').value;
		const autoActivate = document.getElementById('newAutoActivate').checked;

		// Validate dynamically like the registration form (B3).
		if (!username || !email || !password) { flashMessage('createUserMessage', t('panel.modal.all_fields'), 'error'); return; }
		if (username.length < inputLimits.usernameMin) { flashMessage('createUserMessage', t('panel.modal.username_short_configured', { min: inputLimits.usernameMin }), 'error'); return; }
		if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { flashMessage('createUserMessage', t('panel.modal.email_invalid'), 'error'); return; }
		if (password.length < inputLimits.passwordMin) { flashMessage('createUserMessage', t('panel.mu.pw_short_configured', { min: inputLimits.passwordMin }), 'error'); return; }
		if (password !== password2) { flashMessage('createUserMessage', t('panel.mu.pw_mismatch'), 'error'); return; }
		try {
			const d = await FHApi.post('admin_create_user', { username, email, password, role, auto_activate: autoActivate });
			if (d.success) {
				flashMessage('createUserMessage', t('panel.modal.user_created'), 'success');
				setTimeout(() => { closeModal('createUserModal'); loadUsers(userPage); }, 900);
			} else {
				flashMessage('createUserMessage', d.error || t('common.error'), 'error');
			}
		} catch (e) {
			flashMessage('createUserMessage', t('common.connection_error'), 'error');
		}
	}


	function getUsers() {
		return users.slice();
	}

	function isRootAdmin(userId) {
		return rootAdminId > 0 && Number(userId) === Number(rootAdminId);
	}

	function refreshCurrentPage(silent = false) {
		return loadUsers(userPage, silent);
	}

	window.FHPanelUsers = Object.freeze({
		loadUsers, sortUsers, userAction, executeUserAction,
		openBanModal, executeAdvancedBan, showCreateUserModal, createUser,
		getUsers, isRootAdmin, refreshCurrentPage
	});
}());
