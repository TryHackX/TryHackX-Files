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
	const t = (key, params) => window.t(key, params);
	const esc = window.FHUtil.esc;
	const formatSize = window.FHUtil.formatSize;
	const formatDate = window.FHUtil.formatDate;
	const getIcon = (name, mime) => typeof window.fileIcon === 'function'
		? window.fileIcon(name, mime)
		: '<i class="fa-solid fa-file"></i>';
	const showModal = (id) => window.showModal(id);
	const closeModal = (id) => window.closeModal(id);
	const showNotification = (message, type = 'success', anchor = null) =>
		window.showNotification(message, type, anchor);
	const fetchLive = (...args) => window.FHPanelCore.fetchLive(...args);
	/* ------------------------------------------------------------------ *
	 * Dashboard (self-contained SVG charts — no chart library)
	 * ------------------------------------------------------------------ */
	/* ---- Top-downloaded widget (pt 5): its own period, kept across visits ---- */
	// Stored per browser so the choice survives navigation between panel tabs — it is a display
	// preference, not shared state, so localStorage is the right home for it.
	const TOP_FILES_PREF = 'fh.topFiles';
	let topFilesPref = { period: 'all', from: '', to: '', limit: 5 };

	function loadTopFilesPref() {
		try {
			const raw = localStorage.getItem(TOP_FILES_PREF);
			if (raw) topFilesPref = Object.assign(topFilesPref, JSON.parse(raw));
		} catch (e) { /* corrupt or unavailable storage — keep the defaults */ }
	}

	function saveTopFilesPref() {
		try { localStorage.setItem(TOP_FILES_PREF, JSON.stringify(topFilesPref)); } catch (e) { /* ignore */ }
	}

	/** Human label for the active window, shown under the widget heading. */
	function topFilesPeriodLabel() {
		if (topFilesPref.period === 'custom') {
			const from = topFilesPref.from, to = topFilesPref.to;
			if (from && to) return t('panel.top.range', { from: from, to: to });
			if (from) return t('panel.top.range_from', { from: from });
			if (to) return t('panel.top.range_to', { to: to });
			return t('panel.top.all');
		}
		return t('panel.top.' + topFilesPref.period);
	}

	async function loadTopFiles() {
		const holder = document.getElementById('topFiles');
		if (!holder) return;
		const params = { period: topFilesPref.period, limit: topFilesPref.limit };
		if (topFilesPref.period === 'custom') {
			if (topFilesPref.from) params.from = topFilesPref.from;
			if (topFilesPref.to) params.to = topFilesPref.to;
		}
		try {
			const d = await FHApi.get('admin_top_files', params);
			if (d && d.success) renderTopFiles(d.top_files || []);
		} catch (e) { /* leave the previous rendering in place */ }
		const label = document.getElementById('topFilesPeriod');
		if (label) label.textContent = topFilesPeriodLabel();
	}

	function openTopFilesSettings() {
		document.getElementById('tfPeriod').value = topFilesPref.period;
		document.getElementById('tfFrom').value = topFilesPref.from || '';
		document.getElementById('tfTo').value = topFilesPref.to || '';
		document.getElementById('tfLimit').value = topFilesPref.limit || 5;
		onTopFilesPeriodChange();
		showModal('topFilesModal');
	}

	function onTopFilesPeriodChange() {
		const custom = document.getElementById('tfPeriod').value === 'custom';
		document.getElementById('tfCustomRange').style.display = custom ? '' : 'none';
	}

	function applyTopFilesSettings() {
		topFilesPref = {
			period: document.getElementById('tfPeriod').value,
			from: document.getElementById('tfFrom').value,
			to: document.getElementById('tfTo').value,
			limit: Math.max(1, Math.min(20, parseInt(document.getElementById('tfLimit').value) || 5))
		};
		saveTopFilesPref();
		closeModal('topFilesModal');
		loadTopFiles();
	}

	async function loadDashboard(silent = false) {
		try {
			const r = await fetchLive(`${apiUrl}?action=admin_dashboard`, 'dashboard', silent);
			if (r.notModified || !r.data || !r.data.success) return;
			const d = r.data;
			const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
			set('dashFiles', d.stats.total_files);
			set('dashStorage', formatSize(d.stats.total_size));
			set('dashDownloads', d.stats.total_downloads);
			set('dashActive', d.active_downloads);
			// The traffic chart has its own range, so it is fetched separately (pt 5) rather
			// than taken from the dashboard payload's fixed 7-day series.
			loadTraffic();
			// The top-files widget has its own period, so it is fetched separately rather than
			// taken from the dashboard payload (which is always all-time).
			loadTopFiles();
		} catch (e) { /* ignore */ }
	}

	/* ---- Traffic chart with selectable ranges (pt 5) ----
	   The span the admin picks lives in localStorage like the top-files period does, so the
	   dashboard reopens on the view they were last using. The server decides the resolution
	   from the span unless the custom dialog forces one. */
	const TRAFFIC_KEY = 'fh.trafficRange';
	let trafficPref = { range: '7d', from: '', to: '', bucket: '' };

	function loadTrafficPref() {
		try {
			const raw = localStorage.getItem(TRAFFIC_KEY);
			if (raw) trafficPref = Object.assign(trafficPref, JSON.parse(raw));
		} catch (e) { /* stay on the default */ }
		markTrafficRange();
	}

	function saveTrafficPref() {
		try { localStorage.setItem(TRAFFIC_KEY, JSON.stringify(trafficPref)); } catch (e) { /* ignore */ }
	}

	function markTrafficRange() {
		document.querySelectorAll('#trafficRanges .range-btn').forEach(b => {
			b.classList.toggle('active', b.dataset.range === trafficPref.range);
		});
	}

	function setTrafficRange(range) {
		trafficPref = { range, from: '', to: '', bucket: '' };
		saveTrafficPref();
		markTrafficRange();
		loadTraffic();
	}

	function openTrafficRange() {
		document.getElementById('trFrom').value = trafficPref.from || '';
		document.getElementById('trTo').value = trafficPref.to || '';
		document.getElementById('trBucket').value = trafficPref.bucket || '';
		showModal('trafficRangeModal');
	}

	function applyTrafficRange() {
		trafficPref = {
			range: 'custom',
			from: document.getElementById('trFrom').value,
			to: document.getElementById('trTo').value,
			bucket: document.getElementById('trBucket').value
		};
		saveTrafficPref();
		markTrafficRange();
		closeModal('trafficRangeModal');
		loadTraffic();
	}

	async function loadTraffic() {
		const holder = document.getElementById('trafficChart');
		if (!holder) return;
		const params = new URLSearchParams({ range: trafficPref.range });
		if (trafficPref.range === 'custom') {
			params.set('from', trafficPref.from || '');
			params.set('to', trafficPref.to || '');
		}
		if (trafficPref.bucket) params.set('bucket', trafficPref.bucket);
		try {
			const d = await FHApi.get('admin_traffic', Object.fromEntries(params));
			if (!d || !d.success) return;
			const label = document.getElementById('trafficPeriod');
			if (label) {
				label.textContent = `${formatDate(d.from).split(' ')[0]} – ${formatDate(d.to).split(' ')[0]} · ${t('panel.dash.bucket_' + d.bucket)}`;
			}
			renderTrafficChart(d.series || [], d.bucket);
		} catch (e) {
			holder.textContent = t('panel.dash.no_data');
		}
	}

	/**
	 * Shorten a bucket key for the x-axis: "2026-07-20 14:00" → "14:00", "2026-07-20" → "07-20",
	 * "2026-07" → "2026-07". The full value stays in the bar's tooltip.
	 */
	function trafficLabel(date, bucket) {
		if (bucket === 'hour') return date.slice(11);
		if (bucket === 'day') return date.slice(5);
		return date;
	}

	function renderTrafficChart(series, bucket = 'day') {
		const holder = document.getElementById('trafficChart');
		if (!holder) return;
		if (!series.length) { holder.textContent = t('panel.dash.no_data'); return; }

		const W = 640, H = 210, padL = 54, padR = 16, padT = 16, padB = 26;
		const plotW = W - padL - padR;
		const plotH = H - padT - padB;
		const base = padT + plotH;
		const max = Math.max(1, ...series.map(s => Math.max(s.upload, s.download)));
		const bw = plotW / series.length;
		// The gap between slot pairs has to shrink with the slot itself, or a 90-bucket range
		// leaves nothing to draw the bars in.
		const gap = Math.min(10, bw * 0.3);
		const y = v => padT + plotH - (v / max) * plotH;

		// Horizontal gridlines + value labels (top level dashed as the max/baseline marker).
		const levels = 4;
		let grid = '';
		for (let i = 0; i <= levels; i++) {
			const val = (max / levels) * i;
			const gy = y(val);
			const dash = i === levels ? ' stroke-dasharray="5 4"' : (i === 0 ? '' : ' stroke-dasharray="2 5"');
			grid += `<line x1="${padL}" y1="${gy}" x2="${W - padR}" y2="${gy}" stroke="var(--border)" stroke-width="1"${dash}/>`;
			grid += `<text x="${padL - 8}" y="${gy + 3}" font-size="9" fill="var(--text-muted)" text-anchor="end">${formatSize(val)}</text>`;
		}
		// Solid baseline axis.
		grid += `<line x1="${padL}" y1="${base}" x2="${W - padR}" y2="${base}" stroke="var(--border)" stroke-width="1.5"/>`;

		// Print every nth label so a long range doesn't turn the axis into a smear.
		const every = Math.max(1, Math.ceil(series.length / 12));

		let bars = '';
		series.forEach((s, i) => {
			const x = padL + i * bw;
			const half = Math.max(1, (bw - gap) / 2);
			const upY = y(s.upload), dlY = y(s.download);
			bars += `<rect x="${x + gap / 2}" y="${upY}" width="${half}" height="${Math.max(0, base - upY)}" rx="${half > 4 ? 3 : 1}" fill="url(#gUp)"><title>${esc(s.date)} · Upload ${formatSize(s.upload)}</title></rect>`;
			bars += `<rect x="${x + gap / 2 + half}" y="${dlY}" width="${half}" height="${Math.max(0, base - dlY)}" rx="${half > 4 ? 3 : 1}" fill="url(#gDl)"><title>${esc(s.date)} · Download ${formatSize(s.download)}</title></rect>`;
			if (i % every === 0) {
				bars += `<text x="${x + bw / 2}" y="${H - 8}" font-size="10" fill="var(--text-muted)" text-anchor="middle">${esc(trafficLabel(s.date, bucket))}</text>`;
			}
		});

		holder.innerHTML = `<svg viewBox="0 0 ${W} ${H}" width="100%" preserveAspectRatio="xMidYMid meet" role="img" aria-label="${esc(t('panel.dash.chart_alt'))}">
			<defs>
				<linearGradient id="gUp" x1="0" y1="0" x2="0" y2="1">
					<stop offset="0" stop-color="var(--accent)" stop-opacity="0.95"/>
					<stop offset="1" stop-color="var(--accent)" stop-opacity="0.5"/>
				</linearGradient>
				<linearGradient id="gDl" x1="0" y1="0" x2="0" y2="1">
					<stop offset="0" stop-color="#22c55e" stop-opacity="0.95"/>
					<stop offset="1" stop-color="#22c55e" stop-opacity="0.5"/>
				</linearGradient>
			</defs>
			${grid}
			${bars}
		</svg>`;
	}

	function renderTopFiles(list) {
		const holder = document.getElementById('topFiles');
		if (!holder) return;
		if (!list.length) { holder.textContent = t('panel.dash.no_files'); return; }
		const max = Math.max(1, ...list.map(f => Number(f.downloads) || 0));
		holder.innerHTML = list.map(f => {
			const pct = Math.round(((Number(f.downloads) || 0) / max) * 100);
			return `<div class="top-file">
				<div class="top-file-info"><span class="top-file-name" title="${esc(f.original_name)}">${esc(f.original_name)}</span><span class="top-file-meta">${f.downloads} · ${formatSize(f.size)}</span></div>
				<div class="top-file-bar"><i style="width:${pct}%"></i></div>
			</div>`;
		}).join('');
	}

	/* ---- Live active downloads (Faza 2.1) — own faster poll so durations tick ---- */
	let activeDlTimer = null;

	function formatDuration(sec) {
		sec = Math.max(0, Math.floor(sec));
		const m = Math.floor(sec / 60), s = sec % 60;
		return m > 0 ? `${m}m ${s}s` : `${s}s`;
	}

	// Plain fetch (not ETag) on purpose: we re-render every tick so the elapsed time keeps moving.
	async function loadActiveDownloads() {
		const tbody = document.getElementById('activeDownloadsBody');
		if (!tbody) return;
		try {
			const r = await FHApi.get('admin_active_downloads');
			if (r && r.success) {
				const now = r.now || Math.floor(Date.now() / 1000);
				renderActiveDownloads(r.downloads || [], now);
				renderActiveUploads(r.uploads || [], now);
			}
		} catch (e) { /* ignore transient errors */ }
	}

	function renderActiveDownloads(list, now) {
		const tbody = document.getElementById('activeDownloadsBody');
		if (!tbody) return;
		if (!list.length) { tbody.innerHTML = `<tr><td colspan="5" class="empty">${esc(t('panel.dash.no_active'))}</td></tr>`; return; }
		tbody.innerHTML = list.map(d => {
			const name = esc(d.original_name || d.file_id || '—');
			const dur = formatDuration((now || 0) - (Number(d.started_at) || 0));
			return `<tr>
				<td><div class="file-cell"><div class="file-icon">${getIcon(d.original_name || '', d.mime || '')}</div><div class="file-info"><strong title="${name}">${name}</strong><small>${d.file_id}</small></div></div></td>
				<td><code style="font-size:0.75rem">${esc(d.ip_address || '-')}</code></td>
				<td data-sort-value="${Number(d.size) || 0}">${formatSize(d.size)}</td>
				<td data-sort-value="${Math.max(0, (now || 0) - (Number(d.started_at) || 0))}">${dur}</td>
				<td><div class="actions"><button class="action-btn del" data-fh-click="killDownload(${d.id})" title="${esc(t('panel.dash.kill_tooltip'))}"><i class="fa-solid fa-scissors"></i></button></div></td>
			</tr>`;
		}).join('');
	}

	/**
	 * Uploads in flight (pt 8), with the same scissors the download list has: deleting the
	 * tracking row is what the streaming side watches for, in both directions.
	 */
	function renderActiveUploads(list, now) {
		const tbody = document.getElementById('activeUploadsBody');
		if (!tbody) return;
		if (!list.length) { tbody.innerHTML = `<tr><td colspan="6" class="empty">${esc(t('panel.dash.no_uploads'))}</td></tr>`; return; }
		tbody.innerHTML = list.map(u => {
			const name = esc(u.filename || '—');
			const total = Number(u.size) || 0;
			const got = Number(u.received) || 0;
			// Content-Length is what the client claimed; without it there is no percentage to
			// show, only how much has landed so far.
			const pct = total > 0 ? Math.min(100, Math.round((got / total) * 100)) : null;
			const bar = pct === null
				? `<small>${esc(formatSize(got))}</small>`
				: `<div class="upload-progress" title="${pct}%"><span style="width:${pct}%"></span></div>
				   <small>${esc(formatSize(got))} / ${esc(formatSize(total))} · ${pct}%</small>`;
			return `<tr>
				<td><div class="file-cell"><div class="file-icon">${getIcon(u.filename || '', '')}</div>
					<div class="file-info"><strong title="${name}">${name}</strong></div></div></td>
				<td>${u.username ? `<span class="owner-name">${esc(u.username)}</span>` : `<span class="badge badge-muted">${esc(t('panel.coll.owner_guest'))}</span>`}</td>
				<td><code style="font-size:0.75rem">${esc(u.ip_address || '-')}</code></td>
				<td data-sort-value="${pct === null ? got : pct}">${bar}</td>
				<td data-sort-value="${Math.max(0, (now || 0) - (Number(u.started_at) || 0))}">${formatDuration((now || 0) - (Number(u.started_at) || 0))}</td>
				<td><div class="actions"><button class="action-btn del" data-fh-click="killUpload(${u.id})" title="${esc(t('panel.dash.kill_upload_tooltip'))}"><i class="fa-solid fa-scissors"></i></button></div></td>
			</tr>`;
		}).join('');
	}

	async function killDownload(id) {
		try {
			const d = await FHApi.post('admin_kill_download', { id });
			if (d.success) { showNotification(t('panel.dash.killed'), 'success'); loadActiveDownloads(); }
			else showNotification(d.error || t('common.error'), 'error');
		} catch (e) { showNotification(t('common.connection_error'), 'error'); }
	}

	async function killUpload(id) {
		try {
			const d = await FHApi.post('admin_kill_upload', { id });
			if (d.success) { showNotification(t('panel.dash.upload_killed'), 'success'); loadActiveDownloads(); }
			else showNotification(d.error || t('common.error'), 'error');
		} catch (e) { showNotification(t('common.connection_error'), 'error'); }
	}

	function initActiveDownloadsLive() {
		if (PANEL.tab !== 'dashboard') return;
		loadActiveDownloads();
		activeDlTimer = setInterval(() => {
			if (document.visibilityState !== 'visible') return;
			if (document.querySelector('.modal-bg.show, .auth-modal.show')) return;
			loadActiveDownloads();
		}, 1000);
	}


	window.FHPanelDashboard = Object.freeze({
		loadTopFilesPref, loadTrafficPref, loadDashboard, loadTopFiles,
		openTopFilesSettings, onTopFilesPeriodChange, applyTopFilesSettings,
		setTrafficRange, openTrafficRange, applyTrafficRange, loadTraffic,
		loadActiveDownloads, killDownload, killUpload, initActiveDownloadsLive
	});
}());
