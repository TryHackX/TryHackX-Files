(function () {
	'use strict';

	const bootstrap = document.getElementById('panelBootstrap');
	let panel = {};
	try {
		panel = JSON.parse(bootstrap?.dataset.config || '{}');
	} catch {
		panel = {};
	}
	const permissions = new Set(Array.isArray(panel.perms) ? panel.perms : []);
	const canResolveReports = permissions.has('moderation.reports.resolve');
	const canDeleteReportedFiles = permissions.has('moderation.files.delete');
	const appUrl = panel.appUrl || '';
	const apiUrl = panel.apiUrl || '';
	const t = (key, params) => window.t(key, params);
	const esc = window.FHUtil.esc;
	const formatSize = window.FHUtil.formatSize;
	const formatDate = window.FHUtil.formatDate;
	const attr = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
		'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
	})[character]);
	const showModal = (id) => window.showModal(id);
	const closeModal = (id) => window.closeModal(id);
	const showNotification = (message, type = 'success', anchor = null) =>
		window.showNotification(message, type, anchor);
	const fetchLive = (...args) => window.FHPanelCore.fetchLive(...args);
	const showSkeleton = (...args) => window.FHPanelCore.showSkeleton(...args);
	const finishSkeleton = (...args) => window.FHPanelCore.finishSkeleton(...args);
	const renderPager = (...args) => window.FHPanelCore.renderPager(...args);

	let pendingUnbanId = null;
	let pendingRejectId = null;
	let pendingDelReportId = null;
	let pendingDelFileId = null;
	let reportsPage = 1;

	function safeHttpUrl(value) {
		try {
			const url = new URL(String(value || ''));
			return url.protocol === 'http:' || url.protocol === 'https:' ? url.href : null;
		} catch {
			return null;
		}
	}

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
	 * Ban management (IP / email / username)
	 * ------------------------------------------------------------------ */
	async function loadIPBans() {
		showModal('ipBansModal');
		cancelUnban();
		const tbody = document.getElementById('ipBansBody');
		tbody.innerHTML = `<tr><td colspan="5" class="loading">${esc(t('common.loading'))}</td></tr>`;
		try {
			const d = await FHApi.get('admin_ip_bans');
			if (d.success) {
				if (!d.bans.length) { tbody.innerHTML = `<tr><td colspan="5" class="empty">${esc(t('panel.modal.no_bans'))}</td></tr>`; return; }
				tbody.innerHTML = d.bans.map(b => {
					const expires = b.expires_at ? formatDate(b.expires_at) : t('panel.modal.permanent');
					return `<tr>
						<td><strong>${esc(b.value)}</strong></td>
						<td><span class="badge badge-info">${esc(b.type)}</span></td>
						<td>${esc(b.reason || '-')}</td>
						<td>${expires}</td>
						<td><button class="action-btn del" data-fh-click="unbanIP(${b.id}, '${esc(b.type)}', '${esc(b.value)}', '${esc(expires)}')" title="${esc(t('panel.modal.unban_tooltip'))}"><i class="fa-solid fa-trash"></i></button></td>
					</tr>`;
				}).join('');
			} else {
				tbody.innerHTML = `<tr><td colspan="5" class="empty">${esc(t('panel.modal.bans_error', { msg: d.error || t('panel.modal.unknown_error') }))}</td></tr>`;
			}
		} catch (e) {
			tbody.innerHTML = `<tr><td colspan="5" class="empty">${esc(t('common.connection_error'))}</td></tr>`;
		}
	}

	function showAddBanForm() {
		['banValueInput', 'banReasonInput'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
		const d = document.getElementById('banDurationInput'); if (d) d.value = '0';
		const typeSel = document.getElementById('banTypeInput'); if (typeSel) typeSel.value = 'ip';
		updateBanInputPlaceholder();
		const msg = document.getElementById('addBanMessage');
		if (msg) { msg.textContent = ''; msg.className = 'auth-message'; }
		showModal('addBanModal');
	}

	function updateBanInputPlaceholder() {
		const type = document.getElementById('banTypeInput').value;
		const label = document.getElementById('banValueLabel');
		const input = document.getElementById('banValueInput');
		const map = {
			ip: [t('panel.modal.type_ip'), '192.168.1.1'],
			email: [t('panel.modal.type_email'), 'user@example.com'],
			username: [t('panel.modal.type_username'), t('panel.modal.type_username')]
		};
		const [l, p] = map[type] || map.ip;
		label.textContent = l;
		input.placeholder = p;
	}

	async function executeAddBan() {
		const type = document.getElementById('banTypeInput').value;
		const value = document.getElementById('banValueInput').value.trim();
		const reason = document.getElementById('banReasonInput').value.trim();
		const expiresIn = document.getElementById('banDurationInput').value;

		if (!value) { flashMessage('addBanMessage', t('panel.modal.ban_need_value'), 'error'); return; }
		if (type === 'ip' && !/^(?:(?:25[0-5]|2[0-4]\d|[01]?\d?\d)\.){3}(?:25[0-5]|2[0-4]\d|[01]?\d?\d)$/.test(value)) {
			flashMessage('addBanMessage', t('panel.modal.ban_bad_ip'), 'error'); return;
		}
		if (type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
			flashMessage('addBanMessage', t('panel.modal.ban_bad_email'), 'error'); return;
		}
		if (type === 'username' && value.length < 3) {
			flashMessage('addBanMessage', t('panel.modal.ban_short_name'), 'error'); return;
		}
		try {
			const d = await FHApi.post('admin_ban_ip', { type, value, reason, expires_in: expiresIn });
			if (d.success) {
				closeModal('addBanModal');
				loadIPBans();
				flashMessage('ipBansMessage', t('panel.modal.ban_added'), 'success');
			} else {
				flashMessage('addBanMessage', d.error || t('panel.modal.ban_failed'), 'error');
			}
		} catch (e) {
			flashMessage('addBanMessage', t('common.connection_error'), 'error');
		}
	}

	function unbanIP(id, type, value, expires) {
		pendingUnbanId = id;
		document.getElementById('ipBansContent').style.display = 'none';
		const confirmView = document.getElementById('ipBansConfirmView');
		document.getElementById('unbanConfirmMessage').innerHTML = (type && value)
			? t('panel.modal.unban_msg_detail', { type: esc(type), value: esc(value), expires: esc(expires) })
			: esc(t('panel.modal.unban_msg'));
		confirmView.style.display = 'block';
	}

	function cancelUnban() {
		pendingUnbanId = null;
		const cv = document.getElementById('ipBansConfirmView');
		const content = document.getElementById('ipBansContent');
		if (cv) cv.style.display = 'none';
		if (content) content.style.display = 'block';
	}

	async function confirmUnban() {
		if (!pendingUnbanId) return;
		try {
			const d = await FHApi.post('admin_unban_ip', { id: pendingUnbanId });
			if (d.success) {
				loadIPBans();
				flashMessage('ipBansMessage', t('panel.modal.unban_ok'), 'success');
			} else {
				flashMessage('ipBansMessage', d.error || t('panel.modal.unban_failed'), 'error');
				cancelUnban();
			}
		} catch (e) {
			showNotification(t('common.connection_error'), 'error');
			cancelUnban();
		}
	}

	/* ------------------------------------------------------------------ *
	 * Moderation tab (reports)
	 * ------------------------------------------------------------------ */
	let reportsById = new Map();

	async function loadReports(page = 1, silent = false) {
		reportsPage = page;
		const tbody = document.getElementById('reportsBody');
		if (!tbody) return;
		if (!silent) showSkeleton('reportsBody', 5);
		try {
			const r = await fetchLive(`${apiUrl}?action=get_reported_files&page=${page}`, 'reports', silent);
			if (!r.notModified && r.data) {
				if (r.data.success) renderReports(r.data.reports, r.data.total);
				else if (!silent) tbody.innerHTML = `<tr><td colspan="5" class="empty">${esc(t('panel.mod.load_error'))}</td></tr>`;
			}
		} catch (e) {
			if (!silent) tbody.innerHTML = `<tr><td colspan="5" class="empty">${esc(t('common.connection_error'))}</td></tr>`;
		} finally {
			if (!silent) finishSkeleton('reportsBody');
		}
	}

	function renderReports(list, total) {
		const tbody = document.getElementById('reportsBody');
		reportsById = new Map();
		if (!list || !list.length) {
			tbody.innerHTML = `<tr><td colspan="5" class="empty">${esc(t('panel.mod.none'))}</td></tr>`;
			document.getElementById('reportsPagination').innerHTML = '';
			return;
		}
		const safeList = list.filter(r => {
			const id = Number(r && r.id);
			if (!Number.isSafeInteger(id) || id <= 0) return false;
			reportsById.set(id, r);
			return true;
		});
		tbody.innerHTML = safeList.map(r => {
			const id = Number(r.id);
			const fileId = String(r.file_id || '');
			const fileUrl = `${appUrl}/download.php?id=${encodeURIComponent(fileId)}`;
			return `<tr>
			<td><div class="file-cell">
				<div class="file-icon"><i class="fa-solid fa-file-lines"></i></div>
				<div class="file-info">
					<strong><a href="${attr(fileUrl)}" target="_blank" rel="noopener noreferrer" class="file-link">${esc(r.original_name || fileId)}</a></strong>
					<small>${esc(fileId)}</small>
				</div>
			</div></td>
			<td>${esc(r.report_title)}</td>
			<td><div>${esc(r.reporter_name)}</div><small>${esc(r.reporter_email)}</small></td>
			<td>${formatDate(r.created_at)}</td>
			<td><div class="actions">
				<button class="action-btn" data-report-action="details" data-report-id="${id}" title="${attr(t('panel.mod.details_tooltip'))}"><i class="fa-solid fa-circle-info"></i></button>
				<a href="${attr(fileUrl)}" target="_blank" rel="noopener noreferrer" class="action-btn" title="${attr(t('panel.mod.view_file'))}"><i class="fa-solid fa-eye"></i></a>
				${canResolveReports ? `<button class="action-btn" data-report-action="reject" data-report-id="${id}" title="${attr(t('panel.mod.reject_tooltip'))}"><i class="fa-solid fa-shield-halved"></i></button>` : ''}
				${canDeleteReportedFiles ? `<button class="action-btn del" data-report-action="delete" data-report-id="${id}" title="${attr(t('panel.mod.delete_tooltip'))}"><i class="fa-solid fa-trash"></i></button>` : ''}
			</div></td>
		</tr>`;
		}).join('');
		tbody.querySelectorAll('[data-report-action][data-report-id]').forEach(button => {
			button.addEventListener('click', () => {
				const id = Number(button.dataset.reportId);
				const report = reportsById.get(id);
				if (!report) return;
				if (button.dataset.reportAction === 'details') showReportDetails(id);
				if (button.dataset.reportAction === 'reject') showRejectReport(id);
				if (button.dataset.reportAction === 'delete') showDeleteReported(id, String(report.file_id || ''));
			});
		});
		renderReportsPagination(total);
	}

	function renderReportsPagination(total) {
		const el = document.getElementById('reportsPagination');
		if (!el) return;
		const totalPages = Math.ceil(total / 20);
		if (totalPages <= 1) { el.innerHTML = ''; return; }
		let html = '<div class="pagination">';
		if (reportsPage > 1) html += `<button data-fh-click="loadReports(${reportsPage - 1})">&laquo;</button>`;
		for (let i = 1; i <= totalPages; i++) {
			if (i === 1 || i === totalPages || (i >= reportsPage - 2 && i <= reportsPage + 2)) {
				html += `<button class="${i === reportsPage ? 'active' : ''}" data-fh-click="loadReports(${i})">${i}</button>`;
			} else if (i === reportsPage - 3 || i === reportsPage + 3) {
				html += '<span>...</span>';
			}
		}
		if (reportsPage < totalPages) html += `<button data-fh-click="loadReports(${reportsPage + 1})">&raquo;</button>`;
		html += '</div>';
		el.innerHTML = html;
	}

	/**
	 * pt 8: the report currently open in the details modal, so its footer can reject or delete
	 * without the moderator having to close it and find the row again.
	 */
	let reportDetailsCurrent = null;

	/** One label/value cell of the details grid; skipped entirely when there is no value. */
	function detailCell(label, value, opts = {}) {
		if (value === null || value === undefined || value === '') return '';
		const body = opts.raw ? value : esc(value);
		const sub = opts.sub ? `<span class="detail-sub">${esc(opts.sub)}</span>` : '';
		return `<div class="detail-group${opts.wide ? ' detail-wide' : ''}">
			<span class="detail-label">${esc(label)}</span>
			<span class="detail-value${opts.highlight ? ' highlight' : ''}">${body}</span>${sub}
		</div>`;
	}

	async function showReportDetails(id) {
		try {
			const d = await FHApi.get('get_report_details', { id });
			if (!d.success || !d.report) { showNotification(t('panel.mod.details_error'), 'error'); return; }
			const rep = d.report;
			reportDetailsCurrent = { id: rep.id, fileId: rep.file_id };

			const fileUrl = `${appUrl}/download.php?id=${encodeURIComponent(rep.file_id)}`;
			// A report whose file is already gone still has to open — say so instead of
			// rendering an empty name over a dead link.
			const fileGone = !rep.original_name;
			const idLabel = document.getElementById('reportDetailsId');
			if (idLabel) idLabel.textContent = '#' + rep.id;
			const openBtn = document.getElementById('reportDetailsOpen');
			if (openBtn) {
				openBtn.href = fileUrl;
				openBtn.style.display = fileGone ? 'none' : '';
			}

			const meta = [
				fileGone ? t('panel.mod.d_file_gone') : formatSize(rep.size),
				rep.mime_type || '',
				rep.file_uploaded_at ? t('panel.mod.d_uploaded', { date: formatDate(rep.file_uploaded_at) }) : ''
			].filter(Boolean).map(x => `<span>${esc(x)}</span>`).join('');
			const reportLink = safeHttpUrl(rep.report_link);
			const emailHref = `mailto:${encodeURIComponent(String(rep.reporter_email || ''))}`;

			document.getElementById('reportDetailsContent').innerHTML = `
				<div class="report-subject${fileGone ? ' is-gone' : ''}">
					<div class="report-subject-icon"><i class="fa-solid ${fileGone ? 'fa-file-circle-xmark' : 'fa-file-lines'}"></i></div>
					<div class="report-subject-body">
						<strong>${esc(rep.original_name || t('panel.mod.d_file_gone'))}</strong>
						<code>${esc(rep.file_id)}</code>
						<div class="report-subject-meta">${meta}</div>
					</div>
				</div>

				<div class="report-claim">
					<span class="detail-label">${esc(t('panel.mod.d_reason'))}</span>
					<p>${esc(rep.report_title)}</p>
					${reportLink ? `<a href="${attr(reportLink)}" target="_blank" rel="noopener noreferrer" class="detail-link"><i class="fa-solid fa-link"></i> ${esc(rep.report_link)}</a>` : ''}
				</div>

				${rep.additional_info ? `<div class="report-block">
					<span class="detail-label">${esc(t('panel.mod.d_info'))}</span>
					<pre>${esc(rep.additional_info)}</pre>
				</div>` : ''}

				<div class="report-section">
					<h4><i class="fa-solid fa-user-shield"></i> ${esc(t('panel.mod.d_reported_by'))}</h4>
					<div class="report-details-grid">
						${detailCell(t('common.name'), rep.reporter_name)}
						${detailCell('E-mail', `<a href="${attr(emailHref)}" class="detail-link">${esc(rep.reporter_email)}</a>`, { raw: true })}
						${detailCell(t('panel.mod.d_reporter_info'), rep.reporter_entity)}
						${detailCell(t('panel.mod.d_org'), rep.reporter_org)}
					</div>
				</div>

				<div class="report-section">
					<h4><i class="fa-solid fa-fingerprint"></i> ${esc(t('panel.mod.d_technical'))}</h4>
					<div class="report-details-grid">
						${detailCell(t('panel.mod.d_ip'), rep.ip_address)}
						${detailCell(t('common.date'), formatDate(rep.created_at))}
					</div>
				</div>`;
			showModal('reportDetailsModal');
		} catch (e) {
			showNotification(t('panel.mod.details_error'), 'error');
		}
	}

	/** Footer actions of the details modal — hand off to the existing confirmation dialogs. */
	function rejectFromDetails() {
		if (!reportDetailsCurrent) return;
		const r = reportDetailsCurrent;
		closeModal('reportDetailsModal');
		showRejectReport(r.id);
	}

	function deleteFromDetails() {
		if (!reportDetailsCurrent) return;
		const r = reportDetailsCurrent;
		closeModal('reportDetailsModal');
		showDeleteReported(r.id, r.fileId);
	}

	function showRejectReport(id) {
		pendingRejectId = id;
		document.getElementById('rejectReason').value = '';
		showModal('rejectReportModal');
	}

	async function confirmRejectReport() {
		const reason = document.getElementById('rejectReason').value;
		if (!reason) { showNotification(t('panel.mod.need_reason'), 'error'); return; }
		try {
			const d = await FHApi.post('reject_report', { report_id: pendingRejectId, reason });
			if (d.success) { closeModal('rejectReportModal'); loadReports(reportsPage); }
			else showNotification(d.error || t('panel.mod.reject_error'), 'error');
		} catch (e) {
			showNotification(t('common.connection_error'), 'error');
		}
	}

	function showDeleteReported(repId, fileId) {
		pendingDelReportId = repId;
		pendingDelFileId = fileId;
		showModal('deleteReportedModal');
	}

	async function confirmDeleteReported() {
		try {
			const d = await FHApi.post('delete_reported_file', { file_id: pendingDelFileId, report_id: pendingDelReportId });
			if (d.success) { closeModal('deleteReportedModal'); loadReports(reportsPage); }
			else showNotification(d.error || t('panel.mod.delete_error'), 'error');
		} catch (e) {
			showNotification(t('common.connection_error'), 'error');
		}
	}

	/* ------------------------------------------------------------------ *
	 * Audit log
	 * ------------------------------------------------------------------ */
	let auditPage = 1;

	async function loadAuditLog(page = 1, silent = false) {
		auditPage = page;
		const tbody = document.getElementById('auditBody');
		if (!tbody) return;
		if (!silent) showSkeleton('auditBody', 5);
		try {
			const r = await fetchLive(`${apiUrl}?action=admin_audit_log&page=${page}`, 'audit', silent);
			if (!r.notModified && r.data && r.data.success) {
				const d = r.data;
				if (!d.entries.length) {
					tbody.innerHTML = `<tr><td colspan="5" class="empty">${esc(t('panel.audit.none'))}</td></tr>`;
					document.getElementById('auditPagination').innerHTML = '';
					return;
				}
				tbody.innerHTML = d.entries.map(e => `<tr>
					<td>${formatDate(e.created_at)}</td>
					<td>${esc(e.username || '—')}</td>
					<td><span class="badge badge-info">${esc(e.action)}</span></td>
					<td>${esc(e.details || '')}</td>
					<td><code style="font-size:0.75rem">${esc(e.ip_address || '-')}</code></td>
				</tr>`).join('');
				const totalPages = Math.ceil((d.total || 0) / 30);
				renderPager(document.getElementById('auditPagination'), totalPages, d.total, page, 'loadAuditLog', t('panel.files.pager_events'));
			}
		} catch (e) {
			if (!silent) tbody.innerHTML = `<tr><td colspan="5" class="empty">${esc(t('common.connection_error'))}</td></tr>`;
		} finally {
			if (!silent) finishSkeleton('auditBody');
		}
	}

	function refreshReports() {
		return loadReports(reportsPage, true);
	}

	function refreshAuditLog() {
		return loadAuditLog(auditPage, true);
	}

	window.FHPanelModeration = Object.freeze({
		loadIPBans, showAddBanForm, updateBanInputPlaceholder, executeAddBan,
		unbanIP, cancelUnban, confirmUnban, loadReports, showReportDetails,
		showRejectReport, confirmRejectReport, showDeleteReported,
		confirmDeleteReported, rejectFromDetails, deleteFromDetails, loadAuditLog,
		refreshReports, refreshAuditLog
	});
}());
