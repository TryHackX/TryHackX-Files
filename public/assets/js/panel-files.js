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
	const perPage = 20;
	const COLLECTION_MIN_FILES = 2;
	const MB_MULT = { MB: 1, GB: 1024, TB: 1024 * 1024 };
	const MB_UNIT_LABEL = { MB: 'MiB', GB: 'GiB', TB: 'TiB' };
	const t = (key, params) => window.t(key, params);
	const esc = window.FHUtil.esc;
	const safeHttpUrl = window.FHUtil.safeHttpUrl;
	const formatSize = window.FHUtil.formatSize;
	const formatDate = window.FHUtil.formatDate;
	const getIcon = (name, mime) => typeof window.fileIcon === 'function'
		? window.fileIcon(name, mime)
		: '<i class="fa-solid fa-file"></i>';
	const fetchLive = (...args) => window.FHPanelCore.fetchLive(...args);
	const showSkeleton = (...args) => window.FHPanelCore.showSkeleton(...args);
	const finishSkeleton = (...args) => window.FHPanelCore.finishSkeleton(...args);
	const resetCollectionValidation = (kind) =>
		window.FHPanelCore.resetCollectionValidation(kind);
	const showModal = (id) => window.showModal(id);
	const closeModal = (id) => window.closeModal(id);
	const showConfirm = (...args) => window.showConfirm(...args);
	const showNotification = (...args) => window.showNotification(...args);
	const flashMessage = (...args) => window.flashMessage(...args);

	let files = [];
	let myFiles = [];
	let currentPage = 1;
	let myCurrentPage = 1;
	let fileSorts = [{ col: 'uploadedAt', dir: 'desc' }];
	let myFileSorts = [{ col: 'uploadedAt', dir: 'desc' }];
	let filesTotal = 0;
	let filesSearch = '';
	let filesSearchTimer = null;
	let selectedFiles = new Set();
	let pendingMyFileDelete = null;

	function sizeToMb(value, unit) {
		if (value === '' || value === undefined || value === null) return undefined;
		const numeric = Number.parseFloat(value);
		if (!Number.isFinite(numeric)) return undefined;
		return numeric * (MB_MULT[unit] || 1);
	}

	function mbToSize(value, unit) {
		const numeric = Number.parseFloat(value);
		if (!Number.isFinite(numeric)) return '';
		return String(Number.parseFloat((numeric / (MB_MULT[unit] || 1)).toFixed(4)));
	}

	function sizeChip(min, max, minUnit, maxUnit) {
		const hasValue = value => value !== undefined && value !== null && value !== '';
		const lower = hasValue(min)
			? mbToSize(min, minUnit) + ' ' + (MB_UNIT_LABEL[minUnit] || 'MiB')
			: '0';
		const upper = hasValue(max)
			? mbToSize(max, maxUnit) + ' ' + (MB_UNIT_LABEL[maxUnit] || 'MiB')
			: '∞';
		return lower + ' – ' + upper;
	}

	function readSizeBound(prefix, which) {
		const valueElement = document.getElementById(prefix + 'Size' + which);
		const unitElement = document.getElementById(prefix + 'Size' + which + 'Unit');
		const unit = unitElement ? unitElement.value : 'MB';
		return { mb: sizeToMb(valueElement ? valueElement.value : '', unit), unit };
	}

	function writeSizeBound(prefix, which, valueMb, unit) {
		const valueElement = document.getElementById(prefix + 'Size' + which);
		const unitElement = document.getElementById(prefix + 'Size' + which + 'Unit');
		const selectedUnit = unit || 'MB';
		if (unitElement) unitElement.value = selectedUnit;
		if (valueElement) {
			valueElement.value = valueMb === undefined || valueMb === null
				? ''
				: mbToSize(valueMb, selectedUnit);
		}
	}
	/* ------------------------------------------------------------------ *
	 * Files tab (admin)
	 * ------------------------------------------------------------------ */
	const FILE_SORT_MAP = { name: 'original_name', size: 'size', uploadedAt: 'uploaded_at', downloads: 'downloads', owner: 'owner', ip: 'uploaded_ip' };

	// What this session may do in the browser. Mirrors the server's decision (PANEL.perms comes
	// straight from Permissions::forCurrentUser) — the API still enforces every one of these.
	const can = (p) => PANEL.isAdmin || (PANEL.perms || []).indexOf(p) >= 0;

	/** Number of columns the file table currently renders — the empty/loading row spans it. */
	function filesColCount() {
		return 5 + (can('files.see_owner') ? 1 : 0) + (can('files.see_ip') ? 1 : 0)
			+ ((PANEL.isAdmin || can('files.collection_all')) ? 1 : 0);
	}

	async function loadFiles(page = 1, silent = false) {
		currentPage = page;
		const tbody = document.getElementById('filesBody');
		if (!tbody) return;
		const cols = filesColCount();
		if (!silent) showSkeleton('filesBody', cols);
		const primarySort = fileSorts[0] || { col: 'uploadedAt', dir: 'desc' };
		const params = new URLSearchParams({
			action: 'list', page, per_page: perPage,
			sort: FILE_SORT_MAP[primarySort.col] || 'uploaded_at',
			order: primarySort.dir
		});
		if (can('tables.multi_sort') && fileSorts.length > 1) {
			params.set('sorts', JSON.stringify(fileSorts.map(sort => ({
				key: FILE_SORT_MAP[sort.col],
				dir: sort.dir
			}))));
		}
		if (filesSearch) params.set('search', filesSearch);
		// Only send filters when some are set, so the ETag-backed silent poll stays cheap.
		const activeFilters = collectActiveFilters();
		if (Object.keys(activeFilters).length) params.set('filters', JSON.stringify(activeFilters));
		try {
			// Only background polling may return 304. A visible/manual first load must always
			// render a 200 response, otherwise an empty-state loader could remain forever.
			const r = await fetchLive(`${apiUrl}?${params}`, 'files', silent);
			if (!r.notModified && r.data && r.data.success) {
				files = r.data.files.map(f => ({
					...f,
					name: String(f.name || f.originalName || 'Unknown')
				}));
				filesTotal = r.data.total || 0;
				renderFiles();
			}
		} catch (e) {
			if (!silent) tbody.innerHTML = `<tr><td colspan="${cols}" class="empty">${esc(t('panel.files.load_error'))}</td></tr>`;
		} finally {
			if (!silent) finishSkeleton('filesBody');
		}
	}

	function sortBy(col, event) {
		if (!FILE_SORT_MAP[col]) return; // Only DB-backed columns are sortable server-side.
		const index = fileSorts.findIndex(sort => sort.col === col);
		const existing = index >= 0 ? fileSorts[index] : null;
		if (event && event.shiftKey && can('tables.multi_sort')) {
			if (!existing) fileSorts.push({ col, dir: 'desc' });
			else if (existing.dir === 'desc') existing.dir = 'asc';
			else fileSorts.splice(index, 1);
		} else {
			fileSorts = !existing
				? [{ col, dir: 'desc' }]
				: (existing.dir === 'desc' ? [{ col, dir: 'asc' }] : []);
		}
		updateSortIcons();
		loadFiles(1);
	}

	function updateSortIcons() {
		document.querySelectorAll('th[data-fh-click^="sortBy"]').forEach(th => {
			const icon = th.querySelector('.sort-icon');
			th.classList.remove('sorted');
			const index = fileSorts.findIndex(sort => sort.col === th.dataset.sort);
			if (index >= 0) {
				th.classList.add('sorted');
				icon.innerHTML = (fileSorts[index].dir === 'asc' ? '▲' : '▼')
					+ (fileSorts.length > 1 ? `<sup>${index + 1}</sup>` : '');
			} else if (icon) {
				icon.textContent = '';
			}
		});
	}

	function sortRows(list, sorts) {
		return list.sort((a, b) => {
			for (const sort of sorts) {
				let va = a[sort.col] ?? '', vb = b[sort.col] ?? '';
				if (typeof va === 'string') va = va.toLowerCase();
				if (typeof vb === 'string') vb = vb.toLowerCase();
				if (va < vb) return sort.dir === 'asc' ? -1 : 1;
				if (va > vb) return sort.dir === 'asc' ? 1 : -1;
			}
			return 0;
		});
	}

	function renderPager(el, totalPages, total, page, cb, label = null) {
		if (!el) return;
		if (label === null) label = t('panel.files.pager_label');
		if (totalPages <= 1) { el.innerHTML = `<span class="page-info">${total} ${label}</span>`; return; }
		let html = `<button ${page === 1 ? 'disabled' : ''} data-fh-click="${cb}(${page - 1})">←</button>`;
		for (let i = 1; i <= totalPages; i++) {
			if (i === 1 || i === totalPages || (i >= page - 2 && i <= page + 2)) {
				html += `<button class="${i === page ? 'active' : ''}" data-fh-click="${cb}(${i})">${i}</button>`;
			} else if (i === page - 3 || i === page + 3) {
				html += `<span class="page-info">...</span>`;
			}
		}
		html += `<button ${page === totalPages ? 'disabled' : ''} data-fh-click="${cb}(${page + 1})">→</button>`;
		html += `<span class="page-info">${total} ${label}</span>`;
		el.innerHTML = html;
	}

	/* ------------------------------------------------------------------ *
	 * Advanced file filters (pt 9)
	 *
	 * Criteria live in sessionStorage, so they survive a reload and moving between panel tabs
	 * but do not outlive the browser session — a filtered list is a working state, not a
	 * setting. The server re-checks every filter against the group's `filter.*` permissions
	 * and silently drops the rest, so a stale or hand-edited entry here can only ever result
	 * in a broader list, never a wider view than the group is allowed.
	 * ------------------------------------------------------------------ */
	const FILTERS_KEY = 'fh.fileFilters';
	const COLL_FILTERS_KEY = 'fh.collFilters';
	const SCOPE_KEY = 'fh.filterScope';
	let fileFilters = {};
	let collFilters = {};
	let filterFacets = { users: [], ips: [], extensions: [] };
	let collFacets = { owners: [] };
	let allowedFilters = [];
	let allowedCFilters = [];
	let facetsLoaded = false, collFacetsLoaded = false;

	/**
	 * pt 4: what the panel is searching — files, collections, or files that sit inside some
	 * collection. It decides which criteria the modal shows and which list they narrow, which
	 * is what keeps the modal from opening as one wall of every criterion of both objects.
	 */
	let filterScope = 'all';

	// The sharing states the server understands; the chip label comes from panel.flt.share_*.
	const SHARING_STATES = ['public', 'password', 'onetime', 'burned', 'expiring', 'expired', 'capped'];

	function loadFileFilters() {
		try {
			const raw = sessionStorage.getItem(FILTERS_KEY);
			fileFilters = raw ? JSON.parse(raw) : {};
			const rawC = sessionStorage.getItem(COLL_FILTERS_KEY);
			collFilters = rawC ? JSON.parse(rawC) : {};
			filterScope = sessionStorage.getItem(SCOPE_KEY) || 'all';
		} catch (e) { fileFilters = {}; collFilters = {}; }
		// A scope this session may not use falls back to the one every group has. Seeing the
		// collections list is `collections.view_all`; filtering it is a separate permission and
		// must not decide whether the list can be looked at at all.
		// `in_collections` used to be a scope; a session that stored it before the upgrade must
		// not be left pointing at something that no longer exists.
		if (filterScope === 'in_collections') filterScope = 'all';
		if (!can('collections.view_all') && (filterScope === 'collections' || filterScope === 'all')) filterScope = 'files';
		showScopeButtons();
		applyScopeToLayout();
		updateFilterIndicators();
	}

	/**
	 * Which of the tab's two lists the current scope puts on screen.
	 *
	 * `in_collections` is a file-side criterion ("files that sit in some collection"), so it
	 * shows files only — which is why this is not a plain `scope !== 'files'`.
	 */
	function scopeShowsCollections() {
		return (filterScope === 'collections' || filterScope === 'all') && can('collections.view_all');
	}

	function scopeShowsFiles() {
		return filterScope !== 'collections';
	}

	/** Hide the scope buttons this group cannot use, rather than letting them fail on click. */
	function showScopeButtons() {
		document.querySelectorAll('#fltScopeRow .scope-btn[data-perm]').forEach(btn => {
			btn.style.display = can(btn.dataset.perm) ? '' : 'none';
		});
	}

	function persistFileFilters() {
		try {
			const put = (key, obj) => {
				if (Object.keys(obj).length) sessionStorage.setItem(key, JSON.stringify(obj));
				else sessionStorage.removeItem(key);
			};
			put(FILTERS_KEY, fileFilters);
			put(COLL_FILTERS_KEY, collFilters);
			sessionStorage.setItem(SCOPE_KEY, filterScope);
		} catch (e) { /* private mode — filters just won't persist */ }
	}

	/**
	 * The filter payload sent with a file-list request; empty when the session may not filter.
	 * The `in_collections` scope is itself a membership criterion, so it is folded in here
	 * rather than stored as a separate checkbox.
	 */
	function collectActiveFilters() {
		if (!can('files.advanced_filters')) return {};
		const f = Object.assign({}, fileFilters);
		// Membership is a criterion the operator picks in the panel now, not a scope, so there
		// is nothing left to fold in here — but a group that lost the permission must not keep
		// filtering by a value left in its session from when it had it.
		if (f.in_collection !== undefined && !can('filter.in_collection')) delete f.in_collection;
		return f;
	}

	/** The filter payload sent with a collections-list request. */
	function collectCollectionFilters() {
		return (scopeShowsCollections() && can('collections.filters')) ? collFilters : {};
	}

	/** Look an owner id up in a facet list, naming the guest / deleted-account cases. */
	function ownerLabel(id, list) {
		const u = (list || []).find(x => Number(x.id) === Number(id));
		if (u) return u.name || (Number(id) ? t('panel.coll.owner_deleted') : t('panel.coll.owner_guest'));
		return '#' + id;
	}

	/**
	 * Human labels for the chips summarising what is currently filtered.
	 *
	 * In the "all" scope both lists are on screen, so both sets of chips are, too — a criterion
	 * that is narrowing a visible table has to be visible itself, or there is no way to tell why
	 * the list is short.
	 */
	function describeFilters() {
		const out = [];
		// "Wszystko" is this tab's unnarrowed state, so it is not something to report as a
		// filter — and a chip for it could not be dismissed anyway, since dismissing a scope
		// means going back to exactly that.
		if (filterScope === 'collections') {
			out.push({ key: '_scope', text: t('panel.flt.scope') + ': ' + t('panel.flt.scope_collections') });
		}
		if (filterScope === 'collections' || filterScope === 'all') {
			const c = collFilters;
			if (c.empty) out.push({ key: 'empty', text: t('panel.flt.c_empty_only') });
			if (c.files_min || c.files_max) {
				out.push({ key: 'cfiles', text: t('panel.flt.c_files') + ': ' + (c.files_min || 0) + ' – ' + (c.files_max || '∞') });
			}
			if (c.size_min || c.size_max) {
				out.push({ key: 'csize', text: t('panel.flt.c_size') + ': ' + sizeChip(c.size_min, c.size_max, c.size_min_unit, c.size_max_unit) });
			}
			if (c.date_from || c.date_to) {
				out.push({ key: 'cdate', text: t('panel.flt.date') + ': ' + (c.date_from || '…') + ' – ' + (c.date_to || '…') });
			}
			if (c.dl_min || c.dl_max) {
				out.push({ key: 'cdownloads', text: t('panel.flt.downloads') + ': ' + (c.dl_min || 0) + ' – ' + (c.dl_max || '∞') });
			}
			if ((c.users || []).length) {
				out.push({ key: 'cusers', text: t('panel.flt.user') + ': ' + c.users.map(id => ownerLabel(id, collFacets.owners)).join(', ') });
			}
			if ((c.sharing || []).length) {
				out.push({ key: 'csharing', text: t('panel.flt.sharing') + ': ' + c.sharing.map(s => t('panel.flt.share_' + s)).join(', ') });
			}
			if (filterScope === 'collections') {
				return out;
			}
		}

		const f = fileFilters;
		if (f.date_from || f.date_to) {
			out.push({ key: 'date', text: t('panel.flt.date') + ': ' + (f.date_from || '…') + ' – ' + (f.date_to || '…') });
		}
		if (f.size_min || f.size_max) {
			out.push({ key: 'size', text: t('panel.flt.size') + ': ' + sizeChip(f.size_min, f.size_max, f.size_min_unit, f.size_max_unit) });
		}
		if (f.dl_min || f.dl_max) {
			out.push({ key: 'downloads', text: t('panel.flt.downloads') + ': ' + (f.dl_min || 0) + ' – ' + (f.dl_max || '∞') });
		}
		if ((f.extensions || []).length) out.push({ key: 'extensions', text: t('panel.flt.extensions') + ': ' + f.extensions.join(', ') });
		if (f.mime) out.push({ key: 'mime', text: t('panel.flt.mime') + ': ' + f.mime });
		if ((f.users || []).length) {
			out.push({ key: 'users', text: t('panel.flt.user') + ': ' + f.users.map(id => ownerLabel(id, filterFacets.users)).join(', ') });
		}
		if ((f.ips || []).length) out.push({ key: 'ips', text: 'IP: ' + f.ips.join(', ') });
		if (f.in_collection !== undefined) {
			out.push({ key: 'in_collection', text: t('panel.mflt.coll_' + (Number(f.in_collection) ? 'in' : 'out')) });
		}
		if (f.inactive_days) out.push({ key: 'inactive_days', text: t('panel.flt.inactive_chip', { n: f.inactive_days }) });
		if (f.dead) out.push({ key: 'dead', text: t('panel.flt.dead') });
		if ((f.sharing || []).length) {
			out.push({ key: 'sharing', text: t('panel.flt.sharing') + ': ' + f.sharing.map(s => t('panel.flt.share_' + s)).join(', ') });
		}
		return out;
	}

	/** Keep the Filters button badge, the clear-all button and the chip row in sync. */
	function updateFilterIndicators() {
		const chips = describeFilters();
		const count = document.getElementById('filterCount');
		const clearBtn = document.getElementById('clearFiltersBtn');
		const bar = document.getElementById('activeFilters');
		if (count) {
			count.textContent = chips.length;
			count.style.display = chips.length ? '' : 'none';
		}
		if (clearBtn) clearBtn.style.display = chips.length ? '' : 'none';
		if (bar) {
			bar.style.display = chips.length ? '' : 'none';
			bar.innerHTML = chips.map(c =>
				`<span class="filter-chip">${esc(c.text)}<button type="button" data-fh-click="removeFilter('${esc(c.key)}')" title="${esc(t('panel.flt.remove'))}"><i class="fa-solid fa-xmark"></i></button></span>`
			).join('');
		}
	}

	/** Drop one chip's worth of criteria (a chip may cover a from/to pair). */
	function removeFilter(key) {
		if (key === '_scope') {
			// setFilterScope now persists and reloads on its own. Back to "all", which is the
			// unnarrowed state of this tab now that it can show both lists.
			setFilterScope(can('collections.view_all') ? 'all' : 'files');
			return;
		}
		const groups = {
			date: ['date_from', 'date_to'],
			size: ['size_min', 'size_max', 'size_min_unit', 'size_max_unit'],
			downloads: ['dl_min', 'dl_max'],
			cdate: ['date_from', 'date_to'],
			csize: ['size_min', 'size_max', 'size_min_unit', 'size_max_unit'],
			cfiles: ['files_min', 'files_max'],
			cdownloads: ['dl_min', 'dl_max'],
			cusers: ['users'],
			csharing: ['sharing']
		};
		// The chip's own key says which map it came from — the collection ones are all c-prefixed
		// — which matters in the "all" scope, where chips from both are on screen at once.
		const isCollectionChip = key === 'empty' || /^c[a-z]/.test(key);
		const target = isCollectionChip ? collFilters : fileFilters;
		(groups[key] || [key]).forEach(k => { delete target[k]; });
		persistFileFilters();
		updateFilterIndicators();
		reloadScopedList();
	}

	/**
	 * pt 3: "clear all filters" clears all of them.
	 *
	 * It used to wipe only the criteria of the scope you happened to be in, leaving both the
	 * scope itself and the other scope's criteria in place — so pressing it while searching
	 * "Pliki w kolekcjach" left that chip standing and the list still narrowed, which reads
	 * exactly like a button that does not work.
	 */
	function clearAllFilters(fromModal = false) {
		fileFilters = {};
		collFilters = {};
		setFilterScope(can('collections.view_all') ? 'all' : 'files', false);
		persistFileFilters();
		updateFilterIndicators();
		if (fromModal) closeModal('filtersModal');
		loadFiles(1);
		if (can('collections.view_all')) loadAdminCollections(1);
	}

	/** Refresh whichever list (or lists) the current scope shows. */
	function reloadScopedList() {
		if (scopeShowsFiles()) loadFiles(1);
		if (scopeShowsCollections()) loadAdminCollections(1);
	}

	/**
	 * Show only the list the scope is about. Leaving both tables up would mean filtering
	 * collections while a full, unrelated file table sat above them.
	 */
	function applyScopeToLayout() {
		// "all" shows both tables, like the Moje pliki tab; the other scopes show the one they
		// are about, so filtering collections does not leave an unrelated file table above them.
		const showFiles = scopeShowsFiles();
		const showColl = scopeShowsCollections();
		const filesBlock = document.getElementById('filesBlock');
		const collBlock = document.getElementById('collectionsBlock');
		if (filesBlock) filesBlock.style.display = showFiles ? '' : 'none';
		if (collBlock) collBlock.style.display = showColl ? '' : 'none';
		document.querySelectorAll('#fltScopeRow .scope-btn').forEach(b => {
			b.classList.toggle('active', b.dataset.scope === filterScope);
		});

		// The toolbar is shared, so it has to say which list it is pointed at. With both lists
		// up the term goes to both, and the placeholder says so.
		const search = document.getElementById('search');
		if (search) {
			search.placeholder = t(filterScope === 'collections' ? 'panel.coll.search_ph'
				: filterScope === 'all' ? 'panel.flt.search_ph_all' : 'panel.files.search_ph');
		}
		document.querySelectorAll('[data-scope-only]').forEach(el => {
			const wants = el.dataset.scopeOnly;
			el.style.display = (wants === 'collections' ? showColl : showFiles) ? '' : 'none';
		});
	}

	/**
	 * Pick the scope inside the modal, revealing that scope's sections.
	 *
	 * pt 3: picking one now *applies* it, without waiting for the Apply button. Switching to
	 * "Kolekcje" always looked like it filtered — because it swaps which table is on screen —
	 * while "Pliki w kolekcjach" left the same file list sitting there unchanged, so the same
	 * control appeared to work for two of its three values. It also persists here: the scope is
	 * part of what is being searched, and leaving it only in memory meant closing the modal
	 * with Esc quietly reverted it on the next page load.
	 *
	 * `apply` is false when the modal is merely opening and re-asserting the current scope —
	 * that must not fire a reload.
	 */
	function setFilterScope(scope, apply = true) {
		const changed = filterScope !== scope;
		filterScope = scope;
		applyScopeToLayout();
		showFilterSections();
		const hint = document.getElementById('fltScopeHint');
		if (hint) hint.textContent = t('panel.flt.scope_hint_' + scope);

		if (apply && changed) {
			// Leaving a list drops what was ticked in it: the selection actions share the
			// toolbar now, and a count left over from a table nobody can see is a delete
			// waiting to surprise someone.
			selectedFiles.clear();
			selectedCollections.clear();
			updateBulkBar();
			updateCollectionBulkBar();
			persistFileFilters();
			updateFilterIndicators();
			reloadScopedList();
		}
	}

	/** Only the sections belonging to the active scope, and only those this group may use. */
	function showFilterSections() {
		document.querySelectorAll('#filtersModal .flt-section').forEach(sec => {
			const scopes = (sec.dataset.scope || '').split(' ');
			const perm = sec.dataset.perm;
			const permitted = perm.indexOf('cfilter.') === 0
				? allowedCFilters.indexOf(perm) >= 0
				: allowedFilters.indexOf(perm) >= 0;
			sec.style.display = (scopes.indexOf(filterScope) >= 0 && permitted) ? '' : 'none';
		});
		markLastFilterSection('filtersModal');
	}

	/**
	 * Mark the last *visible* section so it can drop its separator (pt 4).
	 *
	 * CSS cannot pick it: `:last-of-type` looks at element type, and these sections sit next to
	 * a `.modal-btns` div, so it never matched a section at all — every one kept its bottom
	 * border and the dialog ended on a rule with nothing under it.
	 */
	function markLastFilterSection(modalId) {
		const secs = [...document.querySelectorAll('#' + modalId + ' .flt-section')]
			.filter(s => s.style.display !== 'none');
		document.querySelectorAll('#' + modalId + ' .flt-section').forEach(s => s.classList.remove('is-last'));
		if (secs.length) secs[secs.length - 1].classList.add('is-last');
	}

	/**
	 * Chip pickers.
	 *
	 * An install with many users, IPs or file types would otherwise render hundreds of chips
	 * into the modal, so each list shows only the CHIP_LIMIT largest (the facets arrive
	 * already sorted by count) and is paired with a search box that filters the full set.
	 * Anything currently selected is always shown regardless of the cap or the query —
	 * otherwise ticking a chip and then typing would silently drop the selection from view
	 * while it stayed active.
	 *
	 * The full item list lives in `chipData` so filtering never needs another round trip.
	 */
	const CHIP_LIMIT = 15;
	const chipData = {};

	function renderChips(holder, items, selected) {
		chipData[holder] = { items: items || [], selected: (selected || []).map(String) };
		paintChips(holder);
	}

	function paintChips(holder) {
		const el = document.getElementById(holder);
		const data = chipData[holder];
		if (!el || !data) return;

		const query = (document.getElementById(holder + 'Search')?.value || '').trim().toLowerCase();
		const sel = new Set(data.selected);

		const matches = data.items.filter(i => !query || String(i.label).toLowerCase().includes(query));
		// Selected chips first, then the biggest remainder up to the cap.
		const chosen = matches.filter(i => sel.has(String(i.value)));
		const rest = matches.filter(i => !sel.has(String(i.value))).slice(0, Math.max(0, CHIP_LIMIT - chosen.length));
		const shown = chosen.concat(rest);
		const hidden = matches.length - shown.length;

		if (!data.items.length) { el.innerHTML = `<span class="chip-empty">—</span>`; return; }
		if (!shown.length) { el.innerHTML = `<span class="chip-empty">${esc(t('panel.flt.no_match'))}</span>`; return; }

		el.innerHTML = shown.map(i =>
			`<button type="button" class="chip ${sel.has(String(i.value)) ? 'active' : ''}" data-value="${esc(i.value)}"
				data-fh-click="toggleChip('${esc(holder)}', this)">${esc(i.label)}${i.count !== undefined ? ` <span class="chip-count">${i.count}</span>` : ''}</button>`
		).join('') + (hidden > 0 ? `<span class="chip-more">${esc(t('panel.flt.more_hidden', { n: hidden }))}</span>` : '');
	}

	/** Toggle a chip, remembering it in chipData so a re-render (search) keeps the state. */
	function toggleChip(holder, btn) {
		const data = chipData[holder];
		if (!data) return;
		const value = btn.dataset.value;
		const i = data.selected.indexOf(value);
		if (i >= 0) data.selected.splice(i, 1); else data.selected.push(value);
		btn.classList.toggle('active');
	}

	/** Re-filter a chip list as the user types in its search box. */
	function filterChips(holder) { paintChips(holder); }

	// Read from chipData, not the DOM: a chip can be selected and then hidden by a search.
	function readChips(holder) {
		return (chipData[holder]?.selected || []).slice();
	}

	async function openFiltersModal() {
		// Facets (who uploaded, from which IPs, which extensions exist) come from the server,
		// which also reports which filter sections this session may use at all.
		if (!facetsLoaded) {
			try {
				const d = await FHApi.get('file_facets');
				if (d && d.success) {
					filterFacets = d.facets || filterFacets;
					allowedFilters = d.allowed || [];
					facetsLoaded = true;
				}
			} catch (e) { /* show the form with whatever we have */ }
		}
		// The collection half has its own facets and its own permission set (pt 4).
		if (!collFacetsLoaded && can('collections.filters')) {
			try {
				const d = await FHApi.get('collection_facets');
				if (d && d.success) {
					collFacets = { owners: d.owners || [] };
					allowedCFilters = d.allowed || [];
					collFacetsLoaded = true;
				}
			} catch (e) { /* show the form with whatever we have */ }
		}

		// Offer only the scopes this session can actually act on. Seeing a list is what decides
		// whether its scope is offered — being allowed to *filter* it is a separate permission,
		// and a group with one but not the other still has a list worth looking at.
		showScopeButtons();
		setFilterScope(filterScope, false);

		const val = (id, v) => { const el = document.getElementById(id); if (el) el.value = (v === undefined || v === null) ? '' : v; };

		const f = fileFilters;
		val('fltDateFrom', f.date_from); val('fltDateTo', f.date_to);
		writeSizeBound('flt', 'Min', f.size_min, f.size_min_unit);
		writeSizeBound('flt', 'Max', f.size_max, f.size_max_unit);
		val('fltDlMin', f.dl_min); val('fltDlMax', f.dl_max);
		val('fltMime', f.mime);
		val('fltInactive', f.inactive_days);
		document.getElementById('fltDead').checked = !!f.dead;

		const c = collFilters;
		val('fltCDateFrom', c.date_from); val('fltCDateTo', c.date_to);
		writeSizeBound('fltC', 'Min', c.size_min, c.size_min_unit);
		writeSizeBound('fltC', 'Max', c.size_max, c.size_max_unit);
		val('fltCFilesMin', c.files_min); val('fltCFilesMax', c.files_max);
		val('fltCDlMin', c.dl_min); val('fltCDlMax', c.dl_max);
		const emptyBox = document.getElementById('fltCEmpty');
		if (emptyBox) emptyBox.checked = !!c.empty;

		// Start each chip search empty so the modal always opens showing the top entries.
		['fltExtList', 'fltUserList', 'fltIpList', 'fltCUserList'].forEach(id => {
			const box = document.getElementById(id + 'Search');
			if (box) box.value = '';
		});

		renderChips('fltExtList', filterFacets.extensions.map(e => ({ value: e.ext, label: '.' + e.ext, count: e.count })), f.extensions);
		renderChips('fltUserList', filterFacets.users.map(u => ({
			value: u.id, label: u.name || (u.id ? t('panel.coll.owner_deleted') : t('panel.coll.owner_guest')), count: u.count
		})), f.users);
		renderChips('fltIpList', filterFacets.ips.map(i => ({ value: i.ip, label: i.ip, count: i.count })), f.ips);
		renderChips('fltSharingList', SHARING_STATES.map(s => ({ value: s, label: t('panel.flt.share_' + s) })), f.sharing);
		renderChips('fltCollList',
			COLLECTION_STATES.map(s => ({ value: s, label: t('panel.mflt.coll_' + s) })),
			f.in_collection === undefined ? [] : [Number(f.in_collection) ? 'in' : 'out']);

		renderChips('fltCUserList', collFacets.owners.map(u => ({
			value: u.id, label: u.name || (u.id ? t('panel.coll.owner_deleted') : t('panel.coll.owner_guest')), count: u.count
		})), c.users);
		renderChips('fltCSharingList', SHARING_STATES.map(s => ({ value: s, label: t('panel.flt.share_' + s) })), c.sharing);
		onEmptyCollectionsToggle();

		showModal('filtersModal');
	}

	/**
	 * "Empty collections only" is a shorthand for file_count = 0, so the count and size ranges
	 * would only contradict it. Grey them out rather than let the two disagree.
	 */
	function onEmptyCollectionsToggle() {
		const on = !!document.getElementById('fltCEmpty')?.checked;
		['fltCFilesMin', 'fltCFilesMax', 'fltCSizeMin', 'fltCSizeMax'].forEach(id => {
			const el = document.getElementById(id);
			if (!el) return;
			el.disabled = on;
			if (on) el.value = '';
		});
	}

	/**
	 * Read the modal back into the filter maps.
	 *
	 * Both halves run in the "all" scope, because both sets of sections are on screen there and
	 * both lists are being narrowed. Each half reads only its own inputs, so a section the
	 * current scope hides simply contributes nothing.
	 */
	function applyFilters() {
		let next = {};
		const num = (id) => { const el = document.getElementById(id); const v = el ? el.value : ''; return v === '' ? undefined : v; };
		const put = (k, v) => { if (v !== undefined && v !== '' && v !== null) next[k] = v; };

		if (filterScope === 'collections' || filterScope === 'all') {
			if (document.getElementById('fltCEmpty').checked) {
				next.empty = 1;
			} else {
				put('files_min', num('fltCFilesMin'));
				put('files_max', num('fltCFilesMax'));
				const cMin = readSizeBound('fltC', 'Min'), cMax = readSizeBound('fltC', 'Max');
				put('size_min', cMin.mb);
				put('size_max', cMax.mb);
				// The units are kept only so the boxes and the chip re-open the way they were
				// typed; the server never sees them (its gate accepts a fixed key list).
				if (next.size_min !== undefined) next.size_min_unit = cMin.unit;
				if (next.size_max !== undefined) next.size_max_unit = cMax.unit;
			}
			put('date_from', document.getElementById('fltCDateFrom').value);
			put('date_to', document.getElementById('fltCDateTo').value);
			put('dl_min', num('fltCDlMin'));
			put('dl_max', num('fltCDlMax'));
			const cUsers = readChips('fltCUserList');
			if (cUsers.length) next.users = cUsers.map(Number);
			const cSharing = readChips('fltCSharingList');
			if (cSharing.length) next.sharing = cSharing;

			collFilters = next;
			next = {};
		}

		if (filterScope !== 'collections') {
			put('date_from', document.getElementById('fltDateFrom').value);
			put('date_to', document.getElementById('fltDateTo').value);
			const fMin = readSizeBound('flt', 'Min'), fMax = readSizeBound('flt', 'Max');
			put('size_min', fMin.mb);
			put('size_max', fMax.mb);
			if (next.size_min !== undefined) next.size_min_unit = fMin.unit;
			if (next.size_max !== undefined) next.size_max_unit = fMax.unit;
			put('dl_min', num('fltDlMin'));
			put('dl_max', num('fltDlMax'));
			put('mime', document.getElementById('fltMime').value.trim());
			put('inactive_days', num('fltInactive'));
			if (document.getElementById('fltDead').checked) next.dead = 1;

			const exts = readChips('fltExtList');
			if (exts.length) next.extensions = exts;
			const users = readChips('fltUserList');
			if (users.length) next.users = users.map(Number);
			const ips = readChips('fltIpList');
			if (ips.length) next.ips = ips;
			const sharing = readChips('fltSharingList');
			if (sharing.length) next.sharing = sharing;
			// "In a collection" and "not in one" are opposites, so picking both means no filter.
			const coll = readChips('fltCollList');
			if (coll.length === 1) next.in_collection = coll[0] === 'in' ? 1 : 0;

			fileFilters = next;
		}

		persistFileFilters();

		updateFilterIndicators();
		closeModal('filtersModal');
		reloadScopedList();
	}

	/**
	 * Owner cell for the all-files list (pt 10). A file with no user_id was uploaded by a
	 * guest; one whose user_id no longer resolves belonged to a since-deleted account. Both
	 * used to render as a bare dash, which read as a bug — say which it is instead.
	 */
	function ownerCell(f) {
		if (f.owner) return `<span class="owner-name">${esc(f.owner)}</span>`;
		if (f.userId) {
			return `<span class="badge badge-muted" title="#${f.userId}">${esc(t('panel.coll.owner_deleted'))}</span>`;
		}
		return `<span class="badge badge-muted">${esc(t('panel.coll.owner_guest'))}</span>`;
	}

	/** Sharing badges shared by the all-files list and the collection lists. */
	function shareBadges(o) {
		const b = [];
		if (o.oneTime) b.push(`<span class="file-badge ${o.consumed ? 'used' : ''}" title="${esc(o.consumed ? t('panel.my.badge_onetime_used') : t('panel.my.badge_onetime'))}"><i class="fa-solid fa-fire"></i> ${o.consumed ? esc(t('panel.my.badge_used')) : '1×'}</span>`);
		if (o.hasPassword) b.push(`<span class="file-badge" title="${esc(t('panel.my.badge_pw'))}"><i class="fa-solid fa-lock"></i></span>`);
		if (o.expiresAt > 0) b.push(`<span class="file-badge" title="${esc(t('panel.my.badge_expires'))}"><i class="fa-solid fa-hourglass-half"></i></span>`);
		return b.length ? ' ' + b.join(' ') : '';
	}

	function renderFiles() {
		const tbody = document.getElementById('filesBody');
		const cols = filesColCount();
		if (!files.length) {
			tbody.innerHTML = `<tr><td colspan="${cols}" class="empty">${esc(t('panel.files.none'))}</td></tr>`;
			document.getElementById('filesPagination').innerHTML = '';
			updateBulkBar();
			return;
		}

		const showOwner = can('files.see_owner');
		const showIp = can('files.see_ip');
		const showCheck = PANEL.isAdmin || can('files.collection_all');

		tbody.innerHTML = files.map(f => {
			const safeName = esc(f.name).replace(/'/g, "\\'");
			const checked = selectedFiles.has(f.id) ? 'checked' : '';
			const mime = f.mimeType || f.mime || '';
			const isMedia = mime && (mime.indexOf('image/') === 0 || mime.indexOf('video/') === 0);
			const iconInner = isMedia
				? `<img class="file-thumb" src="${appUrl}/api/thumb?id=${f.id}" alt="" loading="lazy" data-fh-error="this.remove()">${getIcon(f.name, mime)}`
				: getIcon(f.name, mime);
			return `<tr>
				${showCheck ? `<td class="col-select"><input type="checkbox" class="file-check" data-id="${f.id}" ${checked} data-fh-change="toggleFileSelect('${f.id}', this.checked)"></td>` : ''}
				<td class="col-primary"><div class="file-cell">
					<div class="file-icon">${iconInner}</div>
					<div class="file-info"><strong title="${esc(f.name)}">${esc(f.name)}</strong>${shareBadges(f)}<small>${f.id}</small></div>
				</div></td>
				${showOwner ? `<td class="col-text">${ownerCell(f)}</td>` : ''}
				<td>${formatSize(f.size)}</td>
				<td class="col-date">${formatDate(f.uploadedAt)}</td>
				${showIp ? `<td><code style="font-size:0.75rem">${esc(f.uploadedIP || '-')}</code></td>` : ''}
				<td class="col-downloads">${f.downloads}</td>
				<td class="col-actions"><div class="actions">
					<a href="${appUrl}/download.php?id=${f.id}" target="_blank" class="action-btn" title="${esc(t('panel.files.view'))}"><i class="fa-solid fa-eye"></i></a>
					<button class="action-btn" data-fh-click="downloadFile('${f.id}', ${f.hasPassword ? 'true' : 'false'})" title="${esc(t('panel.files.download_tooltip'))}"><i class="fa-solid fa-download"></i></button>
					<button class="action-btn" data-fh-click="copyUrl(event, '${f.id}')" title="${esc(t('panel.files.copy_link'))}"><i class="fa-solid fa-copy"></i></button>
					${canAddToCollection(false) ? `<button class="action-btn" data-fh-click="openAddToCollection('${f.id}', '${safeName}', ${f.hasPassword ? 'true' : 'false'})" title="${esc(t('panel.atc.tooltip'))}"><i class="fa-solid fa-plus"></i></button>` : ''}
					${PANEL.isAdmin ? `<button class="action-btn" data-fh-click="openFileOptions('${f.id}', true)" title="${esc(t('panel.my.options_tooltip'))}"><i class="fa-solid fa-gear"></i></button>` : ''}
					${PANEL.isAdmin ? `<button class="action-btn del" data-fh-click="showDeleteFile('${f.id}', '${safeName}')" title="${esc(t('common.delete'))}"><i class="fa-solid fa-trash"></i></button>` : ''}
				</div></td>
			</tr>`;
		}).join('');

		const totalPages = Math.ceil(filesTotal / perPage);
		renderPager(document.getElementById('filesPagination'), totalPages, filesTotal, currentPage, 'goPage');
		updateBulkBar();
	}

	function goPage(p) { loadFiles(p); }

	/* ---- bulk selection ---- */
	function toggleFileSelect(id, on) {
		if (on) selectedFiles.add(id); else selectedFiles.delete(id);
		updateBulkBar();
	}

	function toggleSelectAllFiles(on) {
		files.forEach(f => { if (on) selectedFiles.add(f.id); else selectedFiles.delete(f.id); });
		document.querySelectorAll('#filesBody .file-check').forEach(cb => { cb.checked = on; });
		updateBulkBar();
	}

	function updateBulkBar() {
		// pt 1: deleting works from one file, collecting does not — so the two buttons appear
		// at different counts rather than one of them sitting there muted.
		const n = selectedFiles.size;
		toggleSlideButton('bulkCollectionBtn', n >= COLLECTION_MIN_FILES, n);
		toggleSlideButton('bulkDeleteBtn', n > 0, n);
	}

	function bulkDeleteFiles() {
		if (!selectedFiles.size) return;
		showConfirm(t('panel.files.bulk_title'), t('panel.files.bulk_confirm', { n: selectedFiles.size }), async () => {
			const ids = Array.from(selectedFiles);
			let ok = 0;
			for (const id of ids) {
				try {
					const fd = new FormData();
					fd.append('action', 'delete_file');
					fd.append('file_id', id);
					fd.append('ajax', '1');
					const d = await (await fetch('panel.php', { method: 'POST', body: fd })).json();
					if (d.success) ok++;
				} catch (e) { /* continue */ }
			}
			selectedFiles.clear();
			showNotification(t('panel.files.bulk_done', { ok: ok, total: ids.length }), ok === ids.length ? 'success' : 'error');
			loadFiles(currentPage);
		});
	}

	function showDeleteFile(id, name) {
		document.getElementById('deleteFileId').value = id;
		document.getElementById('deleteFileName').textContent = name;
		const msg = document.getElementById('deleteFileMessage');
		if (msg) { msg.textContent = ''; msg.className = 'auth-message'; }
		showModal('deleteModal');
	}

	async function executeFileDelete() {
		const fileId = document.getElementById('deleteFileId').value;
		if (!fileId) return;
		try {
			const formData = new FormData();
			formData.append('action', 'delete_file');
			formData.append('file_id', fileId);
			formData.append('ajax', '1');
			const d = await (await fetch('panel.php', { method: 'POST', body: formData })).json();
			if (d.success) {
				flashMessage('deleteFileMessage', t('panel.files.deleted'), 'success');
				loadFiles();
				setTimeout(() => closeModal('deleteModal'), 1200);
			} else {
				flashMessage('deleteFileMessage', d.error || t('panel.files.delete_failed'), 'error');
			}
		} catch (e) {
			flashMessage('deleteFileMessage', t('common.connection_error'), 'error');
		}
	}

	/* ------------------------------------------------------------------ *
	 * My files tab
	 * ------------------------------------------------------------------ */
	/** Retention meta for the marker in "My files" (runda 9): {days, since, warnDays}. */
	let myRetention = { days: 0, since: 0, warnDays: 3 };

	async function loadMyFiles(silent = false) {
		try {
			// pt 8: the criteria go to the server, which owns both the scoping to this account
			// and the permission check. Search, sort and paging stay in the browser as before.
			const active = collectMyFilters();
			const qs = Object.keys(active).length
				? '&filters=' + encodeURIComponent(JSON.stringify(active))
				: '';
			const r = await fetchLive(`${apiUrl}?action=user_files${qs}`, 'myfiles', silent);
			if (!r.notModified && r.data && r.data.success) {
				myFiles = r.data.files;
				myRetention = r.data.retention || { days: 0, since: 0, warnDays: 3 };
				// A filtered list can drop the page we were on out of existence.
				myCurrentPage = 1;
				renderMyFiles();
			}
		} catch (e) {
			if (!silent) document.getElementById('myFilesBody').innerHTML = `<tr><td colspan="6" class="empty">${esc(t('panel.files.load_error'))}</td></tr>`;
		}
	}

	/* ------------------------------------------------------------------ *
	 * "My files" filters (pt 8)
	 *
	 * Deliberately a sibling of the all-files filter code rather than a branch inside it: the
	 * two panels narrow different lists, live on different tabs and answer to different
	 * permissions, and folding them together would mean every function growing a "which one is
	 * this?" argument. The chip-picker helpers (renderChips / readChips / filterChips) are the
	 * genuinely shared part and are reused as they are.
	 * ------------------------------------------------------------------ */
	const MY_FILTERS_KEY = 'fh.myFileFilters';
	const MY_SCOPE_KEY = 'fh.myFilterScope';
	let myFilters = {};
	/* pt 3: which of the tab's two lists is being searched. 'all' is the state the tab is
	   normally in — files above, collections below — and the other two hide the list you are
	   not asking about, exactly like the scope picker on the Files tab. */
	let myFilterScope = 'all';
	let myFilterFacets = { extensions: [] };
	let allowedMFilters = [];
	let myFacetsLoaded = false;
	// pt 6: the two halves are gated separately, so the panel has to know which of them this
	// group may use — not least because a scope with nothing in it should not be offered.
	let myCanFilterFiles = false;
	let myCanFilterCollections = false;

	/** Membership chips: in a collection / not in one. Mutually exclusive, so at most one. */
	const COLLECTION_STATES = ['in', 'out'];

	function loadMyFileFilters() {
		try {
			const raw = sessionStorage.getItem(MY_FILTERS_KEY);
			myFilters = raw ? JSON.parse(raw) : {};
			myFilterScope = sessionStorage.getItem(MY_SCOPE_KEY) || 'all';
		} catch (e) { myFilters = {}; }
		applyMyScopeToLayout();
		updateMyFilterIndicators();
	}

	function persistMyFilters() {
		try {
			if (Object.keys(myFilters).length) sessionStorage.setItem(MY_FILTERS_KEY, JSON.stringify(myFilters));
			else sessionStorage.removeItem(MY_FILTERS_KEY);
			sessionStorage.setItem(MY_SCOPE_KEY, myFilterScope);
		} catch (e) { /* private mode — filters just won't persist */ }
	}

	/** Show the list(s) the scope is about; hide the one it is not. */
	function applyMyScopeToLayout() {
		const filesBlock = document.getElementById('myFilesBlock');
		const collBlock = document.getElementById('myCollectionsBlock');
		if (filesBlock) filesBlock.style.display = myFilterScope === 'collections' ? 'none' : '';
		if (collBlock) collBlock.style.display = myFilterScope === 'files' ? 'none' : '';
		document.querySelectorAll('#mfltScopeRow .scope-btn').forEach(b => {
			b.classList.toggle('active', b.dataset.scope === myFilterScope);
		});
	}

	/**
	 * Pick the scope inside the modal, revealing that scope's criteria.
	 *
	 * pt 3: applies and persists straight away, for the same reason as the all-files panel —
	 * see setFilterScope(). `apply` is false while the modal is merely opening.
	 */
	function setMyFilterScope(scope, apply = true) {
		const changed = myFilterScope !== scope;
		myFilterScope = scope;
		applyMyScopeToLayout();
		showMyFilterSections();
		const hint = document.getElementById('mfltScopeHint');
		if (hint) hint.textContent = t('panel.mflt.scope_hint_' + scope);

		if (apply && changed) {
			persistMyFilters();
			updateMyFilterIndicators();
			loadMyFiles();
			renderCollections();
		}
	}

	/** Only the sections belonging to the active scope, and only those this group may use. */
	function showMyFilterSections() {
		document.querySelectorAll('#myFiltersModal .flt-section').forEach(sec => {
			const scopes = (sec.dataset.mscope || '').split(' ');
			const permitted = allowedMFilters.indexOf(sec.dataset.perm) >= 0;
			sec.style.display = (scopes.indexOf(myFilterScope) >= 0 && permitted) ? '' : 'none';
		});
		markLastFilterSection('myFiltersModal');
	}

	/**
	 * Narrow the collection list by the same criteria (pt 3).
	 *
	 * Client-side, unlike the file half: `user_collections` already returns the account's whole
	 * list with every number these criteria ask about, so filtering it here costs one pass over
	 * an array the browser is holding anyway — where the files go through the server because
	 * that list is the one that can be large and is paged there.
	 */
	function collectionMatchesFilters(c) {
		const f = myFilters;
		const MiB = 1024 * 1024;

		if (f.empty_only) return Number(c.fileCount) === 0;

		// `c_*` keys: the collection criteria are their own now (pt 4), so narrowing files by
		// size no longer silently narrows collections by the same number.
		if (f.c_date_from && c.createdAt < Math.floor(new Date(f.c_date_from + 'T00:00:00').getTime() / 1000)) return false;
		if (f.c_date_to && c.createdAt > Math.floor(new Date(f.c_date_to + 'T23:59:59').getTime() / 1000)) return false;
		if (f.c_size_min !== undefined && c.totalSize < f.c_size_min * MiB) return false;
		if (f.c_size_max !== undefined && c.totalSize > f.c_size_max * MiB) return false;
		if (f.c_dl_min !== undefined && c.downloads < Number(f.c_dl_min)) return false;
		if (f.c_dl_max !== undefined && c.downloads > Number(f.c_dl_max)) return false;
		if (f.files_min !== undefined && c.fileCount < Number(f.files_min)) return false;
		if (f.files_max !== undefined && c.fileCount > Number(f.files_max)) return false;

		if ((f.c_sharing || []).length) {
			const now = Math.floor(Date.now() / 1000);
			const states = {
				password: !!c.hasPassword,
				onetime: !!c.oneTime,
				burned: !!c.oneTime && !!c.consumed,
				expiring: c.expiresAt > 0 && c.expiresAt > now,
				expired: c.expiresAt > 0 && c.expiresAt <= now,
				capped: c.maxDownloads > 0,
				public: !c.hasPassword && !c.oneTime && !c.expiresAt && !c.maxDownloads
			};
			// Any of the picked states, matching how the server ORs them for files.
			if (!f.c_sharing.some(k => states[k])) return false;
		}
		return true;
	}

	/** Only the file criteria go to the server; the collection ones are applied here (pt 4). */
	function collectMyFilters() {
		if (!can('myfiles.filters')) return {};
		const out = {};
		const collectionOnly = ['files_min', 'files_max', 'empty_only', 'c_date_from', 'c_date_to',
			'c_size_min', 'c_size_max', 'c_size_min_unit', 'c_size_max_unit', 'c_dl_min', 'c_dl_max', 'c_sharing'];
		Object.keys(myFilters).forEach(k => { if (collectionOnly.indexOf(k) < 0) out[k] = myFilters[k]; });
		return out;
	}

	/** Human labels for the chips summarising what is currently filtered. */
	function describeMyFilters() {
		const out = [];
		const f = myFilters;
		if (f.date_from || f.date_to) {
			out.push({ key: 'date', text: t('panel.flt.date') + ': ' + (f.date_from || '…') + ' – ' + (f.date_to || '…') });
		}
		if (f.size_min || f.size_max) {
			out.push({ key: 'size', text: t('panel.flt.size') + ': ' + sizeChip(f.size_min, f.size_max, f.size_min_unit, f.size_max_unit) });
		}
		if (f.dl_min || f.dl_max) {
			out.push({ key: 'downloads', text: t('panel.flt.downloads') + ': ' + (f.dl_min || 0) + ' – ' + (f.dl_max || '∞') });
		}
		if ((f.extensions || []).length) out.push({ key: 'extensions', text: t('panel.flt.extensions') + ': ' + f.extensions.join(', ') });
		if (f.mime) out.push({ key: 'mime', text: t('panel.flt.mime') + ': ' + f.mime });
		if ((f.sharing || []).length) {
			out.push({ key: 'sharing', text: t('panel.flt.sharing') + ': ' + f.sharing.map(s => t('panel.flt.share_' + s)).join(', ') });
		}
		if (f.in_collection !== undefined) {
			out.push({ key: 'in_collection', text: t('panel.mflt.coll_' + (Number(f.in_collection) ? 'in' : 'out')) });
		}
		if (f.inactive_days) out.push({ key: 'inactive_days', text: t('panel.flt.inactive_chip', { n: f.inactive_days }) });
		if (f.dead) out.push({ key: 'dead', text: t('panel.flt.dead') });
		if (f.empty_only) out.push({ key: 'empty_only', text: t('panel.flt.c_empty_only') });
		if (f.files_min !== undefined || f.files_max !== undefined) {
			out.push({
				key: 'cfiles',
				text: t('panel.flt.c_files') + ': ' + (f.files_min || 0) + ' – ' + (f.files_max === undefined ? '∞' : f.files_max)
			});
		}
		if (f.c_size_min !== undefined || f.c_size_max !== undefined) {
			out.push({ key: 'csize', text: t('panel.flt.c_size') + ': ' + sizeChip(f.c_size_min, f.c_size_max, f.c_size_min_unit, f.c_size_max_unit) });
		}
		if (f.c_date_from || f.c_date_to) {
			out.push({ key: 'cdate', text: t('panel.flt.date') + ': ' + (f.c_date_from || '…') + ' – ' + (f.c_date_to || '…') });
		}
		if (f.c_dl_min !== undefined || f.c_dl_max !== undefined) {
			out.push({ key: 'cdownloads', text: t('panel.flt.downloads') + ': ' + (f.c_dl_min || 0) + ' – ' + (f.c_dl_max === undefined ? '∞' : f.c_dl_max) });
		}
		if ((f.c_sharing || []).length) {
			out.push({ key: 'csharing', text: t('panel.flt.sharing') + ': ' + f.c_sharing.map(x => t('panel.flt.share_' + x)).join(', ') });
		}
		if (myFilterScope !== 'all') {
			out.push({ key: '_mscope', text: t('panel.flt.scope') + ': ' + t('panel.mflt.scope_' + myFilterScope) });
		}
		return out;
	}

	/** A count range and "empty only" would contradict each other — grey the range out. */
	function onMyEmptyCollectionsToggle() {
		const on = !!document.getElementById('mfltCEmpty')?.checked;
		['mfltCFilesMin', 'mfltCFilesMax'].forEach(id => {
			const el = document.getElementById(id);
			if (!el) return;
			el.disabled = on;
			if (on) el.value = '';
		});
	}

	function updateMyFilterIndicators() {
		const chips = describeMyFilters();
		const count = document.getElementById('myFilterCount');
		const clearBtn = document.getElementById('myClearFiltersBtn');
		const bar = document.getElementById('myActiveFilters');
		if (count) {
			count.textContent = chips.length;
			count.style.display = chips.length ? '' : 'none';
		}
		if (clearBtn) clearBtn.style.display = chips.length ? '' : 'none';
		if (bar) {
			bar.style.display = chips.length ? '' : 'none';
			bar.innerHTML = chips.map(c =>
				`<span class="filter-chip">${esc(c.text)}<button type="button" data-fh-click="removeMyFilter('${esc(c.key)}')" title="${esc(t('panel.flt.remove'))}"><i class="fa-solid fa-xmark"></i></button></span>`
			).join('');
		}
	}

	function removeMyFilter(key) {
		if (key === '_mscope') {
			setMyFilterScope('all'); // persists and refreshes both lists on its own
			return;
		}
		const groups = {
			date: ['date_from', 'date_to'],
			size: ['size_min', 'size_max', 'size_min_unit', 'size_max_unit'],
			downloads: ['dl_min', 'dl_max'],
			cfiles: ['files_min', 'files_max'],
			csize: ['c_size_min', 'c_size_max', 'c_size_min_unit', 'c_size_max_unit'],
			cdate: ['c_date_from', 'c_date_to'],
			cdownloads: ['c_dl_min', 'c_dl_max'],
			csharing: ['c_sharing']
		};
		(groups[key] || [key]).forEach(k => { delete myFilters[k]; });
		persistMyFilters();
		updateMyFilterIndicators();
		loadMyFiles();
		renderCollections();
	}

	function clearAllMyFilters(fromModal = false) {
		myFilters = {};
		setMyFilterScope('all', false); // the reload below covers both lists
		persistMyFilters();
		updateMyFilterIndicators();
		if (fromModal) closeModal('myFiltersModal');
		loadMyFiles();
		renderCollections();
	}

	async function openMyFiltersModal() {
		if (!myFacetsLoaded) {
			try {
				const d = await FHApi.get('my_file_facets');
				if (d && d.success) {
					myFilterFacets = d.facets || myFilterFacets;
					allowedMFilters = d.allowed || [];
					myCanFilterFiles = !!d.canFilterFiles;
					myCanFilterCollections = !!d.canFilterCollections;
					myFacetsLoaded = true;
				}
			} catch (e) { /* show the form with whatever we have */ }
		}

		// A scope with no criteria behind it is a dead end; hide it rather than let someone
		// pick it and find an empty dialog (pt 6).
		document.querySelectorAll('#mfltScopeRow .scope-btn').forEach(b => {
			const sc = b.dataset.scope;
			const ok = sc === 'all'
				|| (sc === 'files' && myCanFilterFiles)
				|| (sc === 'collections' && myCanFilterCollections);
			b.style.display = ok ? '' : 'none';
		});
		if ((myFilterScope === 'files' && !myCanFilterFiles)
			|| (myFilterScope === 'collections' && !myCanFilterCollections)) {
			myFilterScope = 'all';
		}
		setMyFilterScope(myFilterScope, false);

		const val = (id, v) => { const el = document.getElementById(id); if (el) el.value = (v === undefined || v === null) ? '' : v; };
		const f = myFilters;
		val('mfltDateFrom', f.date_from); val('mfltDateTo', f.date_to);
		writeSizeBound('mflt', 'Min', f.size_min, f.size_min_unit);
		writeSizeBound('mflt', 'Max', f.size_max, f.size_max_unit);
		val('mfltDlMin', f.dl_min); val('mfltDlMax', f.dl_max);
		val('mfltMime', f.mime);
		val('mfltInactive', f.inactive_days);
		document.getElementById('mfltDead').checked = !!f.dead;
		val('mfltCFilesMin', f.files_min); val('mfltCFilesMax', f.files_max);
		val('mfltCDateFrom', f.c_date_from); val('mfltCDateTo', f.c_date_to);
		val('mfltCDlMin', f.c_dl_min); val('mfltCDlMax', f.c_dl_max);
		writeSizeBound('mfltC', 'Min', f.c_size_min, f.c_size_min_unit);
		writeSizeBound('mfltC', 'Max', f.c_size_max, f.c_size_max_unit);
		document.getElementById('mfltCEmpty').checked = !!f.empty_only;
		onMyEmptyCollectionsToggle();

		const extSearch = document.getElementById('mfltExtListSearch');
		if (extSearch) extSearch.value = '';

		renderChips('mfltExtList', (myFilterFacets.extensions || []).map(e => ({ value: e.ext, label: '.' + e.ext, count: e.count })), f.extensions);
		renderChips('mfltSharingList', SHARING_STATES.map(s => ({ value: s, label: t('panel.flt.share_' + s) })), f.sharing);
		renderChips('mfltCollList',
			COLLECTION_STATES.map(s => ({ value: s, label: t('panel.mflt.coll_' + s) })),
			f.in_collection === undefined ? [] : [Number(f.in_collection) ? 'in' : 'out']);
		renderChips('mfltCSharingList', SHARING_STATES.map(x => ({ value: x, label: t('panel.flt.share_' + x) })), f.c_sharing);

		showModal('myFiltersModal');
	}

	function applyMyFilters() {
		const next = {};
		const num = (id) => { const el = document.getElementById(id); const v = el ? el.value : ''; return v === '' ? undefined : v; };
		const put = (k, v) => { if (v !== undefined && v !== '' && v !== null) next[k] = v; };

		put('date_from', document.getElementById('mfltDateFrom').value);
		put('date_to', document.getElementById('mfltDateTo').value);
		if (document.getElementById('mfltCEmpty').checked) {
			// "Empty only" is file_count = 0, so a count range beside it could only contradict it.
			next.empty_only = 1;
		} else {
			put('files_min', num('mfltCFilesMin'));
			put('files_max', num('mfltCFilesMax'));
			const cMin = readSizeBound('mfltC', 'Min'), cMax = readSizeBound('mfltC', 'Max');
			put('c_size_min', cMin.mb);
			put('c_size_max', cMax.mb);
			if (next.c_size_min !== undefined) next.c_size_min_unit = cMin.unit;
			if (next.c_size_max !== undefined) next.c_size_max_unit = cMax.unit;
		}
		put('c_date_from', document.getElementById('mfltCDateFrom').value);
		put('c_date_to', document.getElementById('mfltCDateTo').value);
		put('c_dl_min', num('mfltCDlMin'));
		put('c_dl_max', num('mfltCDlMax'));
		const cShare = readChips('mfltCSharingList');
		if (cShare.length) next.c_sharing = cShare;

		const mMin = readSizeBound('mflt', 'Min'), mMax = readSizeBound('mflt', 'Max');
		put('size_min', mMin.mb);
		put('size_max', mMax.mb);
		if (next.size_min !== undefined) next.size_min_unit = mMin.unit;
		if (next.size_max !== undefined) next.size_max_unit = mMax.unit;
		put('dl_min', num('mfltDlMin'));
		put('dl_max', num('mfltDlMax'));
		put('mime', document.getElementById('mfltMime').value.trim());
		put('inactive_days', num('mfltInactive'));
		if (document.getElementById('mfltDead').checked) next.dead = 1;

		const exts = readChips('mfltExtList');
		if (exts.length) next.extensions = exts;
		const sharing = readChips('mfltSharingList');
		if (sharing.length) next.sharing = sharing;
		// "In a collection" and "not in one" are opposites, so a pick of both means no filter.
		const coll = readChips('mfltCollList');
		if (coll.length === 1) next.in_collection = coll[0] === 'in' ? 1 : 0;

		myFilters = next;
		persistMyFilters();
		updateMyFilterIndicators();
		closeModal('myFiltersModal');
		loadMyFiles();
		renderCollections();
	}

	function sortMyFiles(col, event) {
		const index = myFileSorts.findIndex(sort => sort.col === col);
		const existing = index >= 0 ? myFileSorts[index] : null;
		if (event && event.shiftKey && can('tables.multi_sort')) {
			if (!existing) myFileSorts.push({ col, dir: 'desc' });
			else if (existing.dir === 'desc') existing.dir = 'asc';
			else myFileSorts.splice(index, 1);
		} else {
			myFileSorts = !existing
				? [{ col, dir: 'desc' }]
				: (existing.dir === 'desc' ? [{ col, dir: 'asc' }] : []);
		}
		updateMySortIcons();
		renderMyFiles();
	}

	function updateMySortIcons() {
		document.querySelectorAll('th[data-fh-click^="sortMyFiles"]').forEach(th => {
			const icon = th.querySelector('.sort-icon');
			th.classList.remove('sorted');
			const index = myFileSorts.findIndex(sort => sort.col === th.dataset.sort);
			if (index >= 0) {
				th.classList.add('sorted');
				icon.innerHTML = (myFileSorts[index].dir === 'asc' ? '▲' : '▼')
					+ (myFileSorts.length > 1 ? `<sup>${index + 1}</sup>` : '');
			} else if (icon) {
				icon.textContent = '';
			}
		});
	}

	/**
	 * "Zniknie za X dni" under the upload date (runda 9). The deadline is the later of the
	 * upload and the account's last group change, plus the group's retention — the same
	 * arithmetic the deletion sweep runs, so the marker never lies about the sweep.
	 */
	function retentionNote(f) {
		if (!myRetention.days) return '';
		const start = Math.max(Number(f.uploadedAt) || 0, Number(myRetention.since) || 0);
		if (!start) return '';
		const deadline = start + myRetention.days * 86400;
		const left = Math.ceil((deadline - Date.now() / 1000) / 86400);
		const text = left > 0 ? t('panel.my.retention_in', { n: left }) : t('panel.my.retention_soon');
		const warn = left <= myRetention.warnDays;
		return `<br><small style="color:${warn ? 'var(--warning)' : 'var(--text-muted)'}" title="${esc(formatDate(deadline))}">
			<i class="fa-solid fa-hourglass-half"></i> ${esc(text)}</small>`;
	}

	function renderMyFiles() {
		const q = (document.getElementById('mySearch')?.value || '').toLowerCase();
		let filtered = myFiles.filter(f => f.name.toLowerCase().includes(q));
		sortRows(filtered, myFileSorts);

		const totalSize = filtered.reduce((a, f) => a + (parseInt(f.size) || 0), 0);
		const totalDownloads = filtered.reduce((a, f) => a + (parseInt(f.downloads) || 0), 0);
		const set = (id, val) => { const el = document.getElementById(id); if (el) el.innerText = val; };
		set('myTotalFiles', filtered.length);
		set('myTotalSize', formatSize(totalSize));
		set('myTotalDownloads', totalDownloads);

		const total = filtered.length;
		const totalPages = Math.ceil(total / perPage);
		if (myCurrentPage > totalPages) myCurrentPage = Math.max(1, totalPages);
		const pageFiles = filtered.slice((myCurrentPage - 1) * perPage, myCurrentPage * perPage);

		if (!pageFiles.length) {
			document.getElementById('myFilesBody').innerHTML = `<tr><td colspan="6" class="empty">${esc(t('panel.files.none'))}</td></tr>`;
			document.getElementById('myFilesPagination').innerHTML = '';
			updateCollectionSelection();
			return;
		}

		document.getElementById('myFilesBody').innerHTML = pageFiles.map(f => {
			const safeName = esc(f.name).replace(/'/g, "\\'");
			const badges = [];
			if (f.oneTime) badges.push(`<span class="file-badge ${f.consumed ? 'used' : ''}" title="${esc(f.consumed ? t('panel.my.badge_onetime_used') : t('panel.my.badge_onetime'))}"><i class="fa-solid fa-fire"></i> ${f.consumed ? esc(t('panel.my.badge_used')) : '1×'}</span>`);
			if (f.hasPassword) badges.push(`<span class="file-badge" title="${esc(t('panel.my.badge_pw'))}"><i class="fa-solid fa-lock"></i></span>`);
			if (f.expiresAt > 0) badges.push(`<span class="file-badge" title="${esc(t('panel.my.badge_expires'))}"><i class="fa-solid fa-hourglass-half"></i></span>`);
			const badgesHtml = badges.length ? ` ${badges.join(' ')}` : '';
			const checked = selectedMyFiles.has(f.id) ? 'checked' : '';
			const isMedia = f.mime && (f.mime.indexOf('image/') === 0 || f.mime.indexOf('video/') === 0);
			const iconInner = isMedia
				? `<img class="file-thumb" src="${appUrl}/api/thumb?id=${f.id}" alt="" loading="lazy" data-fh-error="this.remove()">${getIcon(f.name, f.mime)}`
				: getIcon(f.name, f.mime);
			return `<tr>
				<td class="col-check col-select"><input type="checkbox" class="myfile-check" data-id="${f.id}" ${checked} data-fh-change="toggleMyFileSelect('${f.id}', this.checked)"></td>
				<td class="col-primary"><div class="file-cell">
					<div class="file-icon">${iconInner}</div>
					<div class="file-info"><strong title="${esc(f.name)}">${esc(f.name)}</strong>${badgesHtml}<small>${f.id}</small></div>
				</div></td>
				<td>${formatSize(f.size)}</td>
				<td class="col-date">${formatDate(f.uploadedAt)}${retentionNote(f)}</td>
				<td class="col-downloads">${f.downloads}</td>
				<td class="col-actions"><div class="actions">
					<a href="${appUrl}/download.php?id=${f.id}" target="_blank" class="action-btn" title="${esc(t('panel.files.view'))}"><i class="fa-solid fa-eye"></i></a>
					<button class="action-btn" data-fh-click="downloadFile('${f.id}', ${f.hasPassword ? 'true' : 'false'})" title="${esc(t('panel.files.download_tooltip'))}"><i class="fa-solid fa-download"></i></button>
					<button class="action-btn" data-fh-click="copyUrl(event, '${f.id}')" title="${esc(t('panel.files.copy_link'))}"><i class="fa-solid fa-copy"></i></button>
					${canAddToCollection(true) ? `<button class="action-btn" data-fh-click="openAddToCollection('${f.id}', '${safeName}', ${f.hasPassword ? 'true' : 'false'})" title="${esc(t('panel.atc.tooltip'))}"><i class="fa-solid fa-plus"></i></button>` : ''}
					<button class="action-btn" data-fh-click="openFileOptions('${f.id}')" title="${esc(t('panel.my.options_tooltip'))}"><i class="fa-solid fa-gear"></i></button>
					<button class="action-btn del" data-fh-click="deleteMyFile('${f.id}', '${safeName}', '${f.deleteToken}')" title="${esc(t('common.delete'))}"><i class="fa-solid fa-trash"></i></button>
				</div></td>
			</tr>`;
		}).join('');

		renderPager(document.getElementById('myFilesPagination'), totalPages, total, myCurrentPage, 'goMyPage');
		updateCollectionSelection();
	}

	function goMyPage(p) { myCurrentPage = p; renderMyFiles(); }

	/* ---- Collections (Faza 3.2): select own files, group under one ZIP link ---- */
	let selectedMyFiles = new Set();
	let collections = [];
	let collectionsPage = 1;

	function toggleMyFileSelect(id, on) {
		if (on) selectedMyFiles.add(id); else selectedMyFiles.delete(id);
		updateCollectionSelection();
	}

	function toggleSelectAllMyFiles(cb) {
		// Select/deselect only the files currently visible (respecting search filter).
		const q = (document.getElementById('mySearch')?.value || '').toLowerCase();
		myFiles.filter(f => f.name.toLowerCase().includes(q)).forEach(f => {
			if (cb.checked) selectedMyFiles.add(f.id); else selectedMyFiles.delete(f.id);
		});
		document.querySelectorAll('.myfile-check').forEach(c => { c.checked = cb.checked; });
		updateCollectionSelection();
	}

	/**
	 * One toolbar button that slides out of the right edge when a selection makes it useful
	 * (see `.collect-slide`). Every list in the panel drives its selection actions through this.
	 *
	 * pt 7: it slides in once there is something to press it for, and gives the width back to
	 * the search box the rest of the time. Hidden from the tab order and from screen readers
	 * while collapsed — a button nobody can see should not be reachable by Tab either, and
	 * `pointer-events: none` only handles the mouse.
	 */
	function toggleSlideButton(id, show, count) {
		const btn = document.getElementById(id);
		if (!btn) return;
		const pill = btn.querySelector('.collect-count');
		if (pill && count !== undefined) pill.textContent = count;
		btn.classList.toggle('is-shown', !!show);
		btn.tabIndex = show ? 0 : -1;
		btn.setAttribute('aria-hidden', show ? 'false' : 'true');
	}

	function updateCollectionSelection() {
		const n = selectedMyFiles.size;
		toggleSlideButton('createCollectionBtn', n >= COLLECTION_MIN_FILES, n);
		// Deleting is worth offering from the first tick; bundling needs two.
		toggleSlideButton('deleteMyFilesBtn', n > 0, n);
	}

	/**
	 * Delete every ticked own file.
	 *
	 * One call per file, each carrying that file's delete token — the same request the trash
	 * icon on a single row makes, because the token is what the server checks and there is no
	 * bulk endpoint that takes a list of them. `user_delete_files` is a different thing: it
	 * wipes the whole account's uploads behind a password prompt.
	 */
	function bulkDeleteMyFiles() {
		if (!selectedMyFiles.size) return;
		showConfirm(t('panel.files.bulk_title'), t('panel.files.bulk_confirm', { n: selectedMyFiles.size }), async () => {
			const ids = Array.from(selectedMyFiles);
			let ok = 0;
			for (const id of ids) {
				const file = myFiles.find(f => f.id === id);
				if (!file) continue;
				try {
					const fd = new FormData();
					fd.append('id', id);
					fd.append('token', file.deleteToken);
					const d = await FHApi.postForm('delete', fd);
					if (d.success) ok++;
				} catch (e) { /* continue — the count reports what got through */ }
			}
			selectedMyFiles.clear();
			const selectAll = document.getElementById('myFilesSelectAll');
			if (selectAll) selectAll.checked = false;
			showNotification(t('panel.files.bulk_done', { ok: ok, total: ids.length }), ok === ids.length ? 'success' : 'error');
			loadMyFiles();
		});
	}

	/* ------------------------------------------------------------------ *
	 * pt 5: file an existing file into an existing collection
	 *
	 * The + on a file row. Deliberately a picker over the account's *own* collections, in both
	 * lists: the target is always somewhere the caller can already put things, and only the
	 * source differs — hence two permissions (`myfiles.coll_add` for one's own files,
	 * `files.coll_add` for anyone else's), enforced on the server.
	 * ------------------------------------------------------------------ */
	const ATC_RECENT = 6;              // how many to offer before anyone types
	let atcFileId = null;
	let atcNeedsPassword = false;
	let atcPicked = null;

	/**
	 * The account's own collections, cached for the pickers.
	 *
	 * The all-files tab has no reason to load a collection list of its own, but the + there
	 * needs one — and needs to know whether to render at all, since a + that can only ever say
	 * "you have no collections" is a button that wastes a click.
	 */
	let myCollectionsCache = null;

	async function ensureMyCollections(force = false) {
		if (myCollectionsCache && !force) return myCollectionsCache;
		if (!can('myfiles.collections')) return (myCollectionsCache = []);
		try {
			const d = await FHApi.get('user_collections');
			myCollectionsCache = (d && d.success && d.collections) ? d.collections : [];
		} catch (e) {
			myCollectionsCache = [];
		}
		return myCollectionsCache;
	}

	/** Is the + worth rendering for this list at all? */
	function canAddToCollection(own) {
		if (!can(own ? 'myfiles.coll_add' : 'files.coll_add')) return false;
		return (myCollectionsCache || []).length > 0;
	}

	async function openAddToCollection(fileId, fileName, hasPassword) {
		atcFileId = fileId;
		atcNeedsPassword = !!hasPassword;
		atcPicked = null;

		document.getElementById('atcFileName').textContent = fileName;
		document.getElementById('atcSearch').value = '';
		document.getElementById('atcPassword').value = '';
		document.getElementById('atcPasswordRow').style.display = hasPassword ? '' : 'none';
		const msg = document.getElementById('addToCollectionMessage');
		if (msg) { msg.textContent = ''; msg.className = 'auth-message'; }
		updateAtcSubmit();

		await ensureMyCollections(true); // fresh: one may have just been created
		renderAddToCollectionList();
		showModal('addToCollectionModal');
	}

	function renderAddToCollectionList() {
		const holder = document.getElementById('atcList');
		const hint = document.getElementById('atcHint');
		if (!holder) return;

		const all = myCollectionsCache || [];
		if (!all.length) {
			// Nothing to file into. Say what to do about it rather than showing an empty box.
			hint.textContent = '';
			holder.innerHTML = `<p class="atc-empty">${esc(t('panel.atc.none'))}</p>`;
			return;
		}

		const q = (document.getElementById('atcSearch').value || '').trim().toLowerCase();
		let list = q ? all.filter(c => c.name.toLowerCase().includes(q)) : all.slice();

		// Newest first — the collection someone is filing into is nearly always one they just
		// made. Without a query only the first few are offered, so the modal opens as a short
		// list of likely answers rather than as everything they have ever created.
		list.sort((a, b) => (b.createdAt || 0) - (a.createdAt || 0));
		const shown = q ? list : list.slice(0, ATC_RECENT);
		hint.textContent = q ? '' : t('panel.atc.recent', { n: shown.length, total: all.length });

		if (!shown.length) {
			holder.innerHTML = `<p class="atc-empty">${esc(t('panel.coll.no_match'))}</p>`;
			return;
		}

		holder.innerHTML = shown.map(c => `
			<button type="button" class="atc-item ${atcPicked === c.id ? 'is-picked' : ''}" data-id="${esc(c.id)}"
				data-fh-click="pickAddToCollection('${esc(c.id)}')">
				<span class="atc-item-name">
					<i class="fa-solid fa-box-archive"></i> ${esc(c.name)}
					${c.hasPassword ? `<i class="fa-solid fa-lock atc-item-lock" title="${esc(t('panel.my.badge_pw'))}"></i>` : ''}
				</span>
				<span class="atc-item-meta">${t('panel.atc.files_n', { n: c.fileCount })}</span>
			</button>`).join('');
	}

	function pickAddToCollection(id) {
		atcPicked = id;
		document.querySelectorAll('#atcList .atc-item').forEach(el => {
			el.classList.toggle('is-picked', el.dataset.id === id);
		});
		updateAtcSubmit();
	}

	function updateAtcSubmit() {
		const btn = document.getElementById('atcSubmit');
		if (btn) btn.classList.toggle('is-inactive', !atcPicked);
	}

	async function submitAddToCollection() {
		if (!atcPicked || !atcFileId) return;
		const body = { collection_id: atcPicked, file_id: atcFileId };
		const pw = document.getElementById('atcPassword').value;
		if (atcNeedsPassword && pw !== '') body.password = pw;

		try {
			const d = await FHApi.post('add_file_to_collection', body);
			if (d && d.success) {
				closeModal('addToCollectionModal');
				showNotification(
					d.already
						? t('panel.atc.already', { name: d.name })
						: t('panel.atc.done', { name: d.name }),
					d.already ? 'info' : 'success'
				);
				// The member count on the collection row just changed.
				myCollectionsCache = null;
				if (document.getElementById('collectionsBody')) loadCollections();
				if (document.getElementById('adminCollectionsBody')) loadAdminCollections();
				return;
			}
			if (d && d.require_password) {
				document.getElementById('atcPasswordRow').style.display = '';
				atcNeedsPassword = true;
			}
			flashMessage('addToCollectionMessage', (d && d.error) || t('common.error'), 'error');
		} catch (e) {
			flashMessage('addToCollectionMessage', t('common.connection_error'), 'error');
		}
	}

	/** Reset and show the create-collection form. Shared by "My Files" and the all-files list. */
	function openCreateCollectionForm() {
		const count = collectionFromAll ? selectedFiles.size : selectedMyFiles.size;
		document.getElementById('ccName').value = '';
		document.getElementById('ccCount').textContent = count;
		// C2: reset the sharing controls each time the modal opens.
		document.getElementById('ccExpiry').value = '';
		document.getElementById('ccMaxDl').value = '';
		document.getElementById('ccOneTime').checked = false;
		document.getElementById('ccPassword').value = '';
		document.getElementById('ccPassword2').value = '';
		resetCollectionValidation('create');
		document.getElementById('ccFormView').style.display = '';
		document.getElementById('ccResultView').style.display = 'none';
		document.getElementById('ccRejected').style.display = 'none';
		document.getElementById('ccQr').innerHTML = '';
		document.getElementById('ccModalHeader').style.display = '';
		const msg = document.getElementById('createCollectionMessage');
		if (msg) { msg.textContent = ''; msg.className = 'auth-message'; }
		showModal('createCollectionModal');
		setTimeout(() => document.getElementById('ccName').focus(), 60);
	}

	/**
	 * pt 1: building a collection out of one's *own* files asks for the password of every
	 * protected one, exactly as the all-files builder does.
	 *
	 * A password on a file protects its contents; a collection serves those contents as a ZIP.
	 * Being signed in as the owner is not the same as knowing the password, so without this a
	 * borrowed session could unwrap every protected upload on the account. The list of protected
	 * files is already in `myFiles`, so no extra round trip is needed.
	 */
	function openCreateCollection() {
		// pt 9: no toast here. The button is greyed out below the minimum and the counter next
		// to its label says how many are picked, so a popup would only repeat what is on screen.
		if (selectedMyFiles.size < COLLECTION_MIN_FILES) return;
		collectionFromAll = false;
		collectionFilePasswords = {};
		const locked = myFiles.filter(f => selectedMyFiles.has(f.id) && f.hasPassword);
		if (locked.length) {
			renderLockedFileInputs(locked.map(f => ({ id: f.id, name: f.name })));
			showModal('collLockedModal');
			return;
		}
		openCreateCollectionForm();
	}

	/** Password prompts for the protected files about to be collected. */
	function renderLockedFileInputs(list) {
		document.getElementById('clLockedList').innerHTML = list.map(f => `
			<div class="form-group">
				<label title="${esc(f.name)}">${esc(f.name)}</label>
				<input type="password" class="cl-pw" data-id="${esc(f.id)}" placeholder="${esc(t('panel.cc.locked_ph'))}" autocomplete="off">
			</div>`).join('');
	}

	async function submitCreateCollection() {
		const name = document.getElementById('ccName').value.trim();
		const ids = collectionFromAll ? Array.from(selectedFiles) : Array.from(selectedMyFiles);
		if (ids.length < COLLECTION_MIN_FILES) {
			flashMessage('createCollectionMessage', t('panel.coll.min_files', { n: COLLECTION_MIN_FILES }), 'error');
			return;
		}

		// C2: validate the optional password the same way single-file options do.
		const password = document.getElementById('ccPassword').value;
		const password2 = document.getElementById('ccPassword2').value;
		if (password !== '') {
			if (password.length < 8) { flashMessage('createCollectionMessage', t('panel.fo.pw_short'), 'error'); return; }
			if (password !== password2) { flashMessage('createCollectionMessage', t('panel.fo.pw_mismatch'), 'error'); return; }
		}
		const body = {
			name,
			file_ids: ids,
			expiry_days: parseInt(document.getElementById('ccExpiry').value) || 0,
			max_downloads: parseInt(document.getElementById('ccMaxDl').value) || 0,
			one_time: document.getElementById('ccOneTime').checked
		};
		if (password !== '') body.password = password;
		// Both endpoints now verify the per-file passwords of protected members (pt 1/pt 7).
		body.passwords = collectionFilePasswords;

		try {
			const d = await FHApi.post(collectionFromAll ? 'create_collection_from_all' : 'user_create_collection', body);
			if (d.success) {
				showCreatedCollection(d);
				if (collectionFromAll) {
					selectedFiles.clear();
					collectionFilePasswords = {};
					renderFiles();
					const sa = document.getElementById('selectAllFiles'); if (sa) sa.checked = false;
				} else {
					selectedMyFiles.clear();
					renderMyFiles();
					const sa = document.getElementById('myFilesSelectAll'); if (sa) sa.checked = false;
				}
				// pt 2: refresh both lists regardless of which tab built it. The collection is
				// always owned by the creator, so it belongs in "My collections"; and building
				// one from the all-files list used to leave "All collections" stale until a
				// manual refresh, unlike the My Files path.
				loadCollections();
				loadAdminCollections();
			} else {
				// A create that failed because every protected file was mis-keyed says so in the
				// form itself, listing the files, instead of a bare "not allowed".
				let msg = d.error || t('panel.coll.create_failed');
				if ((d.rejected || []).length) {
					msg += ' — ' + t('panel.cc.rejected', { names: d.rejected.join(', ') });
				}
				flashMessage('createCollectionMessage', msg, 'error');
			}
		} catch (e) {
			flashMessage('createCollectionMessage', t('common.connection_error'), 'error');
		}
	}

	/**
	 * Result view of a successful create (pt 2).
	 *
	 * Mirrors the upload page's dialog: QR + link + the same actions. Files that were skipped
	 * because their password did not check out are listed *here* — they used to be announced by
	 * a bottom toast that vanished on its own while the link stayed unexplained.
	 */
	let createdCollectionId = null, createdCollectionFromAll = false, createdCollectionUrl = '';
	function showCreatedCollection(d) {
		createdCollectionId = d.id || null;
		createdCollectionFromAll = collectionFromAll;
		createdCollectionUrl = safeHttpUrl(d.url)
			|| `${appUrl}/collection.php?id=${encodeURIComponent(d.id || '')}`;
		document.getElementById('ccUrl').textContent = createdCollectionUrl;
		// The QR is rendered by the Python upload server; if that is not running the image would
		// otherwise leave a broken icon on a white card, so hide the holder instead.
		const qr = document.getElementById('ccQr');
		qr.style.display = '';
		qr.innerHTML = `<img alt="QR" width="200" height="200" src="${appUrl}/api/qr?scale=5&data=${encodeURIComponent(createdCollectionUrl)}"
			data-fh-error="this.parentNode.style.display='none'">`;

		const rejected = d.rejected || [];
		const box = document.getElementById('ccRejected');
		box.style.display = rejected.length ? '' : 'none';
		if (rejected.length) document.getElementById('ccRejectedNames').textContent = rejected.join(', ');

		const open = document.getElementById('ccOpenBtn');
		if (open) open.href = createdCollectionUrl;

		document.getElementById('ccFormView').style.display = 'none';
		document.getElementById('ccResultView').style.display = '';
		// The result view carries its own centred title, so the modal's header bar would just
		// repeat it — hiding it is what makes this read as the same dialog as the upload page's.
		document.getElementById('ccModalHeader').style.display = 'none';
		// The subtitle promises the link is on the clipboard, so put it there (as the home page
		// does) rather than making that a lie.
		navigator.clipboard.writeText(createdCollectionUrl).catch(() => { });
		// Re-assert the modal: the create can be reached through the locked-files step, which
		// closes one modal and opens another, and the result must be on screen either way.
		showModal('createCollectionModal');
	}

	/**
	 * Jump straight from the result view into the new collection's settings. The settings modal
	 * reads the row out of whichever list the current tab loaded, so refresh that one first —
	 * "All collections" on the Files tab, "My collections" on My Files.
	 */
	function editCreatedCollection() {
		const id = createdCollectionId;
		if (!id) return;
		closeModal('createCollectionModal');
		if (createdCollectionFromAll) {
			loadAdminCollections().then(() => openCollectionSettings(id, true));
		} else {
			loadCollections().then(() => openCollectionSettings(id));
		}
	}

	function copyCollectionResult(ev) {
		navigator.clipboard.writeText(createdCollectionUrl).then(
			() => showNotification(t('common.copied'), 'copy', ev ? ev.currentTarget : null),
			() => showNotification(t('panel.coll.copy_failed'), 'error')
		);
	}

	async function loadCollections() {
		const body = document.getElementById('collectionsBody');
		if (!body) return;
		try {
			const d = await FHApi.get('user_collections');
			collections = (d.success && d.collections) ? d.collections : [];
		} catch (e) { collections = []; }
		renderCollections();
		openCollectionFromUrl();
	}

	/**
	 * `?coll=<id>` opens that collection's settings once the list has loaded. It is how the
	 * "Edit" button on the upload page's collection-created dialog hands off to the panel,
	 * which is where collection settings live. Consumed once, then dropped from the URL so a
	 * refresh doesn't reopen the modal.
	 */
	let collFromUrlHandled = false;
	function openCollectionFromUrl() {
		if (collFromUrlHandled) return;
		const id = new URLSearchParams(window.location.search).get('coll');
		if (!id) return;
		collFromUrlHandled = true;
		if (!collections.some(c => c.id === id)) return; // not this user's, or already gone
		openCollectionSettings(id);
		const url = new URL(window.location.href);
		url.searchParams.delete('coll');
		window.history.replaceState({}, '', url);
	}

	// Escape `text`, then wrap the first case-insensitive occurrence of `q` in a highlight span.
	function highlight(text, q) {
		const s = String(text);
		if (!q) return esc(s);
		const i = s.toLowerCase().indexOf(q);
		if (i < 0) return esc(s);
		return esc(s.slice(0, i)) + '<span class="search-hl">' + esc(s.slice(i, i + q.length)) + '</span>' + esc(s.slice(i + q.length));
	}

	/** How many matching member files a collection row names before it says "+N". */
	const MAX_MATCH_SHOWN = 3;

	/**
	 * The "files" cell of a collection row.
	 *
	 * An empty collection gets the badge *instead of* the count: "0" and "PUSTA" are the same
	 * statement, and printing both made the number look like part of the label. Shared by both
	 * collection lists so they cannot disagree about what empty looks like.
	 */
	function collectionFilesCell(count) {
		const n = Number(count) || 0;
		return n === 0
			? `<span class="badge badge-danger" title="${esc(t('panel.flt.c_empty_hint'))}">${esc(t('panel.coll.empty_badge'))}</span>`
			: String(n);
	}

	/**
	 * "…because these files inside it matched" — the line under a collection's name.
	 *
	 * Shared by both collection lists: the own-collections table works out its hits in the
	 * browser (it holds the file names), the admin one is told them by the server, but what the
	 * reader sees has to be the same thing in both places.
	 */
	function collectionMatchHtml(hits, q) {
		if (!q || !hits || !hits.length) return '';
		const shown = hits.slice(0, MAX_MATCH_SHOWN)
			.map(n => `<span class="coll-match"><i class="fa-solid fa-magnifying-glass"></i> ${highlight(n, q)}</span>`).join('');
		const more = hits.length > MAX_MATCH_SHOWN
			? `<span class="coll-match-more">+${hits.length - MAX_MATCH_SHOWN}</span>` : '';
		return `<div class="coll-matches">${shown}${more}</div>`;
	}

	function renderCollections() {
		const body = document.getElementById('collectionsBody');
		if (!body) return;
		// C3: the "My Files" search also filters collections — by collection name OR by a
		// member file name — and surfaces up to a few matching files (highlighted).
		const q = (document.getElementById('mySearch')?.value || '').trim().toLowerCase();

		// pt 4: no own-collections permission → nothing to list.
		if (!can('myfiles.collections')) {
			body.innerHTML = '';
			const pager = document.getElementById('collectionsPagination');
			if (pager) pager.innerHTML = '';
			return;
		}
		// pt 3/6: the filter panel narrows this list too — but only when the group may filter
		// *collections*. It used to key off the file permission, so a group allowed file
		// filters but not collection ones had its collections quietly narrowed by criteria it
		// was never shown and could not clear.
		let list = can('myfiles.coll_filters') ? collections.filter(collectionMatchesFilters) : collections;
		if (q) {
			list = list
				.map(c => {
					const nameHit = c.name.toLowerCase().includes(q);
					const fileHits = (c.fileNames || []).filter(n => n.toLowerCase().includes(q));
					return (nameHit || fileHits.length) ? Object.assign({}, c, { _fileHits: fileHits }) : null;
				})
				.filter(Boolean);
		}

		const canPick = can('myfiles.coll_delete');
		// A collection filtered or searched out of view cannot stay ticked for a bulk delete.
		const visible = new Set(list.map(c => c.id));
		selectedMyCollections = new Set(Array.from(selectedMyCollections).filter(id => visible.has(id)));

		if (!list.length) {
			body.innerHTML = `<tr><td colspan="${canPick ? 6 : 5}" class="empty">${esc(q ? t('panel.coll.no_match') : t('panel.coll.none'))}</td></tr>`;
			document.getElementById('collectionsPagination').innerHTML = '';
			updateMyCollectionSelection();
			return;
		}

		// Paginate like the file tables — a prolific user's collection list grew unbounded.
		const collTotal = list.length;
		const collPages = Math.ceil(collTotal / perPage);
		if (collectionsPage > collPages) collectionsPage = Math.max(1, collPages);
		const pageList = list.slice((collectionsPage - 1) * perPage, collectionsPage * perPage);

		body.innerHTML = pageList.map(c => {
			const safeName = esc(c.name).replace(/'/g, "\\'");
			const collectionUrl = safeHttpUrl(c.url)
				|| `${appUrl}/collection.php?id=${encodeURIComponent(c.id)}`;
			// C2: mirror the per-file badges so active sharing controls are visible at a glance.
			const badges = [];
			if (c.oneTime) badges.push(`<span class="file-badge ${c.consumed ? 'used' : ''}" title="${esc(c.consumed ? t('panel.my.badge_onetime_used') : t('panel.my.badge_onetime'))}"><i class="fa-solid fa-fire"></i> ${c.consumed ? esc(t('panel.my.badge_used')) : '1×'}</span>`);
			if (c.hasPassword) badges.push(`<span class="file-badge" title="${esc(t('panel.my.badge_pw'))}"><i class="fa-solid fa-lock"></i></span>`);
			if (c.expiresAt > 0) badges.push(`<span class="file-badge" title="${esc(t('panel.my.badge_expires'))}"><i class="fa-solid fa-hourglass-half"></i></span>`);
			const badgesHtml = badges.length ? ` ${badges.join(' ')}` : '';
			const matchHtml = collectionMatchHtml(c._fileHits, q);
			return `<tr>
				${canPick ? `<td class="col-check col-select"><input type="checkbox" class="mycoll-check" data-id="${c.id}" ${selectedMyCollections.has(c.id) ? 'checked' : ''}
					data-fh-change="toggleMyCollectionSelect('${c.id}', this.checked)"></td>` : ''}
				<td class="col-primary"><div class="file-cell"><div class="file-icon"><i class="fa-solid fa-box-archive"></i></div>
					<div class="file-info"><strong title="${esc(c.name)}">${q ? highlight(c.name, q) : esc(c.name)}</strong>${badgesHtml}<small>${c.id}</small>${matchHtml}</div></div></td>
				<td>${collectionFilesCell(c.fileCount)}</td>
				<td>${formatSize(c.totalSize)}</td>
				<td class="col-downloads">${c.downloads}</td>
				<td class="col-actions"><div class="actions">
					<a href="${esc(collectionUrl)}" target="_blank" rel="noopener noreferrer" class="action-btn" title="${esc(t('panel.coll.open_tooltip'))}"><i class="fa-solid fa-eye"></i></a>
					<button class="action-btn" data-fh-click="downloadCollection('${c.id}')" title="${esc(t('panel.coll.zip_tooltip'))}"><i class="fa-solid fa-download"></i></button>
					<button class="action-btn" data-fh-click="copyCollectionUrl(event, '${c.id}')" title="${esc(t('panel.coll.copy_tooltip'))}"><i class="fa-solid fa-copy"></i></button>
					<button class="action-btn" data-fh-click="openCollectionSettings('${c.id}')" title="${esc(t('panel.cc.edit_tooltip'))}"><i class="fa-solid fa-gear"></i></button>
					<button class="action-btn del" data-fh-click="askDeleteCollection('${c.id}', '${safeName}')" title="${esc(t('panel.coll.delete_tooltip'))}"><i class="fa-solid fa-trash"></i></button>
				</div></td>
			</tr>`;
		}).join('');

		const selectAll = document.getElementById('myCollectionsSelectAll');
		if (selectAll) selectAll.checked = pageList.every(c => selectedMyCollections.has(c.id));
		updateMyCollectionSelection();

		renderPager(document.getElementById('collectionsPagination'), collPages, collTotal, collectionsPage,
			'goCollectionsPage', t('panel.coll.pager_label'));
	}

	function goCollectionsPage(p) { collectionsPage = p; renderCollections(); }

	/* ---- Selecting one's own collections, so several can go at once ---- */
	let selectedMyCollections = new Set();

	function toggleMyCollectionSelect(id, on) {
		if (on) selectedMyCollections.add(id); else selectedMyCollections.delete(id);
		const selectAll = document.getElementById('myCollectionsSelectAll');
		if (selectAll && !on) selectAll.checked = false;
		updateMyCollectionSelection();
	}

	function toggleSelectAllMyCollections(cb) {
		// Only what is on screen: the list is searched, filtered and paged, and a tick nobody
		// can see is a deletion nobody agreed to.
		document.querySelectorAll('.mycoll-check').forEach(c => {
			c.checked = cb.checked;
			if (cb.checked) selectedMyCollections.add(c.dataset.id); else selectedMyCollections.delete(c.dataset.id);
		});
		updateMyCollectionSelection();
	}

	function updateMyCollectionSelection() {
		toggleSlideButton('deleteMyCollectionsBtn', selectedMyCollections.size > 0, selectedMyCollections.size);
	}

	/** Delete every ticked collection. The member files are untouched — only the bundles go. */
	function bulkDeleteMyCollections() {
		const ids = Array.from(selectedMyCollections);
		if (!ids.length) return;
		showConfirm(t('panel.coll.bulk_del_title'), t('panel.coll.bulk_del_q', { n: ids.length }), async () => {
			let ok = 0;
			for (const id of ids) {
				try {
					const d = await FHApi.post('user_delete_collection', { id });
					if (d.success) ok++;
				} catch (e) { /* continue */ }
			}
			selectedMyCollections.clear();
			const selectAll = document.getElementById('myCollectionsSelectAll');
			if (selectAll) selectAll.checked = false;
			showNotification(
				ok === ids.length ? t('panel.coll.bulk_deleted', { n: ok }) : t('panel.coll.delete_failed'),
				ok === ids.length ? 'success' : 'error'
			);
			loadCollections();
		});
	}

	function copyCollectionUrl(ev, id) {
		if (ev) ev.preventDefault();
		const c = collections.find(x => x.id === id);
		const url = c ? safeHttpUrl(c.url) : '';
		const copyUrl = url || `${appUrl}/collection.php?id=${encodeURIComponent(id)}`;
		// C5: use the same anchored "Copied" toast as file links (copyUrl) instead of a
		// bespoke bottom success toast, so copy feedback is consistent across the project.
		navigator.clipboard.writeText(copyUrl).then(
			() => showNotification(t('common.copied'), 'copy', ev ? ev.target : null),
			() => showNotification(t('panel.coll.copy_failed'), 'error')
		);
	}

	/* ---- pt 7: build a collection out of the all-files list ----
	   Files someone else protected with a password may be included only by supplying that
	   password, which the server verifies — the permission grants gathering, never bypassing.
	   So: ask the server which of the picked files are locked, collect those passwords, then
	   reuse the ordinary create-collection modal for the name and sharing options. */
	let collectionFromAll = false;      // which endpoint submitCreateCollection() should call
	let collectionFilePasswords = {};   // fileId → password, only for this one submission

	async function openCollectionFromAll() {
		if (selectedFiles.size < COLLECTION_MIN_FILES) return;
		collectionFromAll = true;
		collectionFilePasswords = {};
		const ids = Array.from(selectedFiles);
		try {
			const d = await FHApi.post('check_files_protected', { file_ids: ids });
			if (d && d.success && (d.protected || []).length) {
				renderLockedFileInputs(d.protected);
				showModal('collLockedModal');
				return;
			}
		} catch (e) { /* fall through and let the create call report the problem */ }
		openCreateCollectionForm();
	}

	/** Collect the typed passwords and move on to the name/sharing form. */
	function continueLockedFiles() {
		document.querySelectorAll('#clLockedList .cl-pw').forEach(inp => {
			if (inp.value !== '') collectionFilePasswords[inp.dataset.id] = inp.value;
		});
		closeModal('collLockedModal');
		openCreateCollectionForm();
	}

	/* ---- C4: admin view of every collection (Files tab) ----
	   pt 4: searched, filtered and paged by the server rather than by slicing a flat "newest
	   200" in the browser, and selectable so a filtered set (typically the empty ones) can be
	   cleared out in one go. */
	let adminCollections = [];
	let adminCollectionsPage = 1;
	let adminCollectionsTotal = 0;
	let collSearch = '';
	let selectedCollections = new Set();

	async function loadAdminCollections(page = adminCollectionsPage) {
		const body = document.getElementById('adminCollectionsBody');
		if (!body) return;
		adminCollectionsPage = Math.max(1, page);
		const params = { page: adminCollectionsPage, per_page: perPage };
		if (collSearch) params.search = collSearch;
		const active = collectCollectionFilters();
		if (Object.keys(active).length) params.filters = JSON.stringify(active);
		try {
			const d = await FHApi.get('admin_collections', params);
			adminCollections = (d.success && d.collections) ? d.collections : [];
			adminCollectionsTotal = (d && d.total) || 0;
		} catch (e) { adminCollections = []; adminCollectionsTotal = 0; }
		// A row that scrolled out of the filtered set can't stay selected for a bulk delete.
		const visible = new Set(adminCollections.map(c => c.id));
		selectedCollections = new Set(Array.from(selectedCollections).filter(id => visible.has(id)));
		renderAdminCollections();
	}

	function toggleCollectionSelect(id, on) {
		if (on) selectedCollections.add(id); else selectedCollections.delete(id);
		updateCollectionBulkBar();
	}

	function toggleSelectAllCollections(cb) {
		adminCollections.forEach(c => { if (cb.checked) selectedCollections.add(c.id); else selectedCollections.delete(c.id); });
		document.querySelectorAll('#adminCollectionsBody .coll-check').forEach(c => {
			c.checked = cb.checked;
		});
		updateCollectionBulkBar();
	}

	function updateCollectionBulkBar() {
		toggleSlideButton('collBulkDeleteBtn', selectedCollections.size > 0, selectedCollections.size);
	}

	/** Delete every selected collection. The member files are untouched — only the bundles go. */
	function bulkDeleteCollections() {
		const ids = Array.from(selectedCollections);
		if (!ids.length) return;
		showConfirm(t('panel.coll.bulk_del_title'), t('panel.coll.bulk_del_q', { n: ids.length }), async () => {
			try {
				const d = await FHApi.post('admin_delete_collections', { ids });
				if (d.success) showNotification(t('panel.coll.bulk_deleted', { n: d.deleted }), 'success');
				else showNotification(d.error || t('common.error'), 'error');
			} catch (e) { showNotification(t('common.connection_error'), 'error'); }
			selectedCollections.clear();
			updateCollectionBulkBar();
			loadAdminCollections();
		});
	}

	function renderAdminCollections() {
		const body = document.getElementById('adminCollectionsBody');
		if (!body) return;
		const canPick = PANEL.isAdmin || can('collections.delete_all');
		const cols = 6 + (canPick ? 1 : 0);
		if (!adminCollections.length) {
			body.innerHTML = `<tr><td colspan="${cols}" class="empty">${esc(t('panel.coll.none'))}</td></tr>`;
			document.getElementById('adminCollectionsPagination').innerHTML = '';
			updateCollectionBulkBar();
			return;
		}

		const acPages = Math.max(1, Math.ceil(adminCollectionsTotal / perPage));

		body.innerHTML = adminCollections.map(c => {
			const safeName = esc(c.name).replace(/'/g, "\\'");
			const collectionUrl = safeHttpUrl(c.url)
				|| `${appUrl}/collection.php?id=${encodeURIComponent(c.id)}`;
			// The member files the term matched, rendered exactly as the own-collections list
			// does it — same markup, same highlight, same "+N" overflow.
			const matchHtml = collectionMatchHtml(c.fileHits, collSearch.trim().toLowerCase());
			// pt 13: a collection whose owner account is gone showed a bare dash. Name the state.
			const owner = c.ownerState === 'ok'
				? `<span class="owner-name">${esc(c.owner)}</span>`
				: `<span class="badge badge-muted"${c.userId ? ` title="#${c.userId}"` : ''}>${esc(t(c.ownerState === 'guest' ? 'panel.coll.owner_guest' : 'panel.coll.owner_deleted'))}</span>`;
			// An empty collection is the thing this list exists to help find — flag it. The badge
			// replaces the count rather than following it: "0 PUSTA" says one thing twice.
			const files = collectionFilesCell(c.fileCount);
			return `<tr>
			${canPick ? `<td class="col-select"><input type="checkbox" class="file-check coll-check" ${selectedCollections.has(c.id) ? 'checked' : ''}
				data-fh-change="toggleCollectionSelect('${c.id}', this.checked)"></td>` : ''}
			<td class="col-primary"><div class="file-cell"><div class="file-icon"><i class="fa-solid fa-box-archive"></i></div>
				<div class="file-info"><strong title="${esc(c.name)}">${collSearch ? highlight(c.name, collSearch.trim().toLowerCase()) : esc(c.name)}</strong>${shareBadges(c)}<small>${c.id}</small>${matchHtml}</div></div></td>
			<td class="col-text">${owner}</td>
			<td>${files}</td>
			<td>${formatSize(c.totalSize)}</td>
			<td class="col-downloads">${c.downloads}</td>
			<td class="col-actions"><div class="actions">
				<a href="${esc(collectionUrl)}" target="_blank" rel="noopener noreferrer" class="action-btn" title="${esc(t('panel.coll.open_tooltip'))}"><i class="fa-solid fa-eye"></i></a>
				<button class="action-btn" data-fh-click="downloadCollection('${c.id}', true)" title="${esc(t('panel.coll.zip_tooltip'))}"><i class="fa-solid fa-download"></i></button>
				<button class="action-btn" data-fh-click="copyAdminCollectionUrl(event, '${c.id}')" title="${esc(t('panel.coll.copy_tooltip'))}"><i class="fa-solid fa-copy"></i></button>
				<button class="action-btn" data-fh-click="openCollectionSettings('${c.id}', true)" title="${esc(t('panel.cc.edit_tooltip'))}"><i class="fa-solid fa-gear"></i></button>
				<button class="action-btn del" data-fh-click="askDeleteCollection('${c.id}', '${safeName}', true)" title="${esc(t('panel.coll.delete_tooltip'))}"><i class="fa-solid fa-trash"></i></button>
			</div></td>
		</tr>`;
		}).join('');

		const selectAll = document.getElementById('selectAllCollections');
		if (selectAll) selectAll.checked = adminCollections.every(c => selectedCollections.has(c.id));
		updateCollectionBulkBar();

		renderPager(document.getElementById('adminCollectionsPagination'), acPages, adminCollectionsTotal, adminCollectionsPage,
			'goAdminCollectionsPage', t('panel.coll.pager_label'));
	}

	// Paging now fetches — the browser only ever holds the page it is showing.
	function goAdminCollectionsPage(p) { loadAdminCollections(p); }

	function copyAdminCollectionUrl(ev, id) {
		if (ev) ev.preventDefault();
		const c = adminCollections.find(x => x.id === id);
		const url = c ? safeHttpUrl(c.url) : '';
		const copyUrl = url || `${appUrl}/collection.php?id=${encodeURIComponent(id)}`;
		navigator.clipboard.writeText(copyUrl).then(
			() => showNotification(t('common.copied'), 'copy', ev ? ev.target : null),
			() => showNotification(t('panel.coll.copy_failed'), 'error')
		);
	}

	// `fromAdmin` decides which list to refresh after the action — the admin "all collections"
	// table on the Files tab, or the owner's list on My Files.
	let delCollFromAdmin = false;

	function askDeleteCollection(id, name, fromAdmin = false) {
		delCollFromAdmin = fromAdmin;
		document.getElementById('delCollId').value = id;
		document.getElementById('delCollName').textContent = name;
		showModal('deleteCollectionModal');
	}

	async function confirmDeleteCollection() {
		const id = document.getElementById('delCollId').value;
		try {
			const d = await FHApi.post('user_delete_collection', { id });
			closeModal('deleteCollectionModal');
			if (d.success) {
				showNotification(t('panel.coll.deleted'), 'success');
				if (delCollFromAdmin) loadAdminCollections(); else loadCollections();
			} else {
				showNotification(d.error || t('panel.coll.delete_failed'), 'error');
			}
		} catch (e) { closeModal('deleteCollectionModal'); showNotification(t('common.connection_error'), 'error'); }
	}

	/* ---- pt 17: edit a collection's name + sharing controls ---- */
	let collSettingsFromAdmin = false;

	function findCollection(id, fromAdmin) {
		const list = fromAdmin ? adminCollections : collections;
		return list.find(c => c.id === id);
	}

	function openCollectionSettings(id, fromAdmin = false) {
		const c = findCollection(id, fromAdmin);
		if (!c) return;
		collSettingsFromAdmin = fromAdmin;
		document.getElementById('csId').value = c.id;
		document.getElementById('csName').value = c.name || '';
		// expires_at is an absolute timestamp; the form asks for "days from now", so show what
		// is left rather than the original span.
		const daysLeft = c.expiresAt > 0 ? Math.max(0, Math.ceil((c.expiresAt * 1000 - Date.now()) / 86400000)) : '';
		document.getElementById('csExpiry').value = daysLeft;
		document.getElementById('csMaxDl').value = c.maxDownloads > 0 ? c.maxDownloads : '';
		document.getElementById('csLimitAction').value = c.onLimitAction === 'delete' ? 'delete' : 'keep';
		document.getElementById('csOneTime').checked = !!c.oneTime;
		document.getElementById('csPassword').value = '';
		document.getElementById('csPassword2').value = '';
		document.getElementById('csClearPw').checked = false;
		const clearRow = document.getElementById('csClearPwRow');
		if (clearRow) clearRow.style.display = c.hasPassword ? '' : 'none';
		resetCollectionValidation('settings');
		const msg = document.getElementById('collSettingsMessage');
		if (msg) { msg.textContent = ''; msg.className = 'auth-message'; }
		loadCsFiles(c.id);
		showModal('collSettingsModal');
	}

	/* ---- Member list in the settings modal (runda 9): reorder + take-out ---- */
	let csFiles = [];

	async function loadCsFiles(collectionId) {
		const holder = document.getElementById('csFilesList');
		if (!holder) return;
		holder.innerHTML = `<p class="empty">${esc(t('common.loading'))}</p>`;
		try {
			const d = await FHApi.get('user_collection_files', { id: collectionId });
			csFiles = (d && d.success) ? d.files : [];
			renderCsFiles();
		} catch (e) {
			holder.innerHTML = `<p class="empty">${esc(t('common.connection_error'))}</p>`;
		}
	}

	function renderCsFiles() {
		const holder = document.getElementById('csFilesList');
		if (!holder) return;
		if (!csFiles.length) {
			holder.innerHTML = `<p class="empty">${esc(t('panel.cs.files_none'))}</p>`;
			return;
		}
		holder.innerHTML = csFiles.map((f, i) => `<div class="cs-file-row">
			<span class="cs-file-pos">${i + 1}.</span>
			<span class="cs-file-name" title="${esc(f.name)}">${esc(f.name)} <small style="color:var(--text-muted)">${formatSize(f.size)}</small></span>
			<span class="cs-file-actions">
				<button type="button" class="action-btn" ${i === 0 ? 'disabled' : ''} data-fh-click="csMoveFile(${i}, -1)" title="${esc(t('panel.cs.move_up'))}"><i class="fa-solid fa-arrow-up"></i></button>
				<button type="button" class="action-btn" ${i === csFiles.length - 1 ? 'disabled' : ''} data-fh-click="csMoveFile(${i}, 1)" title="${esc(t('panel.cs.move_down'))}"><i class="fa-solid fa-arrow-down"></i></button>
				<button type="button" class="action-btn del" data-fh-click="csRemoveFile(${i})" title="${esc(t('panel.cs.remove_file'))}"><i class="fa-solid fa-xmark"></i></button>
			</span>
		</div>`).join('');
	}

	async function csMoveFile(index, delta) {
		const to = index + delta;
		if (to < 0 || to >= csFiles.length) return;
		[csFiles[index], csFiles[to]] = [csFiles[to], csFiles[index]];
		renderCsFiles(); // optimistic — the order the user sees is the order being saved
		try {
			const d = await FHApi.post('user_collection_reorder', {
				collection_id: document.getElementById('csId').value,
				order: csFiles.map(f => f.id)
			});
			if (!d.success) throw new Error();
		} catch (e) {
			flashMessage('collSettingsMessage', t('common.connection_error'), 'error');
			loadCsFiles(document.getElementById('csId').value); // resync with the truth
		}
	}

	function csRemoveFile(index) {
		const f = csFiles[index];
		if (!f) return;
		showConfirm(t('panel.cs.remove_file'), t('panel.cs.remove_q', { name: f.name }), async () => {
			try {
				const d = await FHApi.post('user_collection_remove_file', {
					collection_id: document.getElementById('csId').value,
					file_id: f.id
				});
				if (!d.success) { flashMessage('collSettingsMessage', d.error || t('common.error'), 'error'); return; }
				csFiles.splice(index, 1);
				renderCsFiles();
				// The tab's counters (file count, size) changed underneath the modal.
				if (collSettingsFromAdmin) loadAdminCollections(); else loadCollections();
			} catch (e) { flashMessage('collSettingsMessage', t('common.connection_error'), 'error'); }
		}, { danger: true, icon: 'fa-xmark', confirmLabel: t('panel.cs.remove_file') });
	}

	async function saveCollectionSettings() {
		const password = document.getElementById('csPassword').value;
		const password2 = document.getElementById('csPassword2').value;
		const clearPw = document.getElementById('csClearPw').checked;
		if (!clearPw && password !== '') {
			if (password.length < 8) { flashMessage('collSettingsMessage', t('panel.fo.pw_short'), 'error'); return; }
			if (password !== password2) { flashMessage('collSettingsMessage', t('panel.fo.pw_mismatch'), 'error'); return; }
		}
		const body = {
			id: document.getElementById('csId').value,
			name: document.getElementById('csName').value.trim(),
			expiry_days: parseInt(document.getElementById('csExpiry').value) || 0,
			max_downloads: parseInt(document.getElementById('csMaxDl').value) || 0,
			one_time: document.getElementById('csOneTime').checked,
			on_limit_action: document.getElementById('csLimitAction').value,
			clear_password: clearPw
		};
		if (!clearPw && password !== '') body.password = password;

		try {
			const d = await FHApi.post('user_update_collection', body);
			if (d.success) {
				showNotification(t('panel.cc.saved'), 'success');
				closeModal('collSettingsModal');
				if (collSettingsFromAdmin) loadAdminCollections(); else loadCollections();
			} else {
				flashMessage('collSettingsMessage', d.error || t('common.error'), 'error');
			}
		} catch (e) {
			flashMessage('collSettingsMessage', t('common.connection_error'), 'error');
		}
	}

	/* ---- Quick download straight from a file row (My Files + the all-files list) ----
	   Mirrors what the download page does: ask for a single-use, IP-bound token and follow it,
	   so the download goes through the same counters, limits and throttling as any other. A
	   protected file asks for its password first, exactly like a protected collection. ---- */
	let pendingDownloadFile = null;

	async function startFileDownload(id, password) {
		const params = { id };
		if (password) params.pw = password;
		const d = await FHApi.post('get_download_token', params);
		if (d && d.success && d.token) {
			window.location.href = `${appUrl}/api/download?token=${encodeURIComponent(d.token)}`;
			return { ok: true };
		}
		return { ok: false, needPassword: !!(d && d.require_password), error: d && d.error };
	}

	async function downloadFile(id, hasPassword) {
		if (hasPassword) {
			pendingDownloadFile = id;
			document.getElementById('fdPassword').value = '';
			const err = document.getElementById('fdError');
			if (err) { err.textContent = ''; err.className = 'auth-message'; }
			showModal('fileDownloadModal');
			return;
		}
		try {
			const r = await startFileDownload(id, null);
			if (!r.ok) {
				// A file can gain a password (or a CAPTCHA gate) between list load and click.
				if (r.needPassword) { downloadFile(id, true); return; }
				showNotification(r.error || t('download.link_failed'), 'error');
			}
		} catch (e) { showNotification(t('common.connection_error'), 'error'); }
	}

	async function submitFileDownloadPassword() {
		if (!pendingDownloadFile) return;
		const pw = document.getElementById('fdPassword').value;
		try {
			const r = await startFileDownload(pendingDownloadFile, pw);
			if (r.ok) { closeModal('fileDownloadModal'); pendingDownloadFile = null; return; }
			flashMessage('fdError', r.error || t('pwprompt.wrong'), 'error', 4000);
		} catch (e) {
			flashMessage('fdError', t('common.connection_error'), 'error', 4000);
		}
	}

	/* ---- pt 14: downloading a protected collection asked for nothing and dumped the raw
	   {"detail":"Password required"} JSON. Verify the password first, then follow the link
	   with the short-lived token the ZIP endpoint accepts. ---- */
	let pendingZipCollection = null;
	let pendingZipMode = 'collection';

	function openCollectionMemberPasswords(id, protectedFiles) {
		pendingZipCollection = id;
		pendingZipMode = 'members';
		document.getElementById('czCollectionPasswordWrap').hidden = true;
		document.getElementById('czIntro').textContent = t('collection.member_passwords_intro');
		const list = document.getElementById('czMemberPasswords');
		list.hidden = false;
		list.innerHTML = (protectedFiles || []).map(file => `<label class="collection-password-row">
			<span title="${esc(file.name || '')}">${esc(file.name || file.id || '')}</span>
			<input type="password" maxlength="1024" autocomplete="current-password" data-file-id="${esc(file.id || '')}">
		</label>`).join('');
		document.getElementById('czSubmitBtn').innerHTML = `<i class="fa-solid fa-file-zipper"></i> ${esc(t('collection.download_available'))}`;
		showModal('collZipModal');
		list.querySelector('input')?.focus();
	}

	function followCollectionZip(id, c, tok, collectionToken = '') {
		const base = (c && safeHttpUrl(c.zipUrl)) || `${appUrl}/api/collection?id=${encodeURIComponent(id)}`;
		const url = new URL(base, window.location.href);
		url.searchParams.set('dt', tok.token);
		if (collectionToken) url.searchParams.set('t', collectionToken);
		if (tok.member_token) url.searchParams.set('m', tok.member_token);
		if (Array.isArray(tok.skipped) && tok.skipped.length) {
			showNotification(t('collection.skipped_protected', { n: tok.skipped.length }), 'info');
		}
		window.location.href = url.toString();
	}

	async function downloadCollection(id, fromAdmin = false) {
		const c = findCollection(id, fromAdmin);
		if (c && c.hasPassword) {
			pendingZipCollection = id;
			pendingZipMode = 'collection';
			document.getElementById('czCollectionPasswordWrap').hidden = false;
			document.getElementById('czMemberPasswords').hidden = true;
			document.getElementById('czIntro').textContent = t('panel.cc.pw_required_download');
			document.getElementById('czSubmitBtn').innerHTML = `<i class="fa-solid fa-unlock"></i> ${esc(t('collection.unlock'))}`;
			document.getElementById('czPassword').value = '';
			const err = document.getElementById('czError');
			if (err) { err.textContent = ''; err.className = 'auth-message'; }
			showModal('collZipModal');
			return;
		}
		// Runda 10: the ZIP wants a short-lived token now — fetch one, then follow. The
		// server-built URL still carries the signed "this is account N" tag, so fetching
		// your own collection does not ring your own bell.
		try {
			const tok = await FHApi.post('collection_zip_token', { id: id });
			if (tok.require_member_passwords) { openCollectionMemberPasswords(id, tok.protected); return; }
			if (!tok.success) { showNotification(tok.error || t('common.error'), 'error'); return; }
			followCollectionZip(id, c, tok);
		} catch (e) { showNotification(t('common.connection_error'), 'error'); }
	}

	async function submitCollectionZipPassword() {
		if (!pendingZipCollection) return;
		if (pendingZipMode === 'members') {
			const passwords = {};
			document.querySelectorAll('#czMemberPasswords input[data-file-id]').forEach(input => {
				passwords[input.dataset.fileId] = input.value;
			});
			try {
				const tok = await FHApi.post('collection_zip_token', {
					id: pendingZipCollection,
					passwords,
					confirm_member_passwords: true
				});
				if (!tok.success) { flashMessage('czError', tok.error || t('common.error'), 'error', 4000); return; }
				const c = findCollection(pendingZipCollection, false) || findCollection(pendingZipCollection, true);
				closeModal('collZipModal');
				followCollectionZip(pendingZipCollection, c, tok);
				pendingZipCollection = null;
			} catch (e) { flashMessage('czError', t('common.connection_error'), 'error', 4000); }
			return;
		}
		const pw = document.getElementById('czPassword').value;
		try {
			const d = await FHApi.post('verify_collection_password', { id: pendingZipCollection, password: pw });
			if (d && d.success) {
				const tok = await FHApi.post('collection_zip_token', { id: pendingZipCollection });
				if (!tok.success) { flashMessage('czError', tok.error || t('common.error'), 'error', 4000); return; }
				const c = findCollection(pendingZipCollection, false) || findCollection(pendingZipCollection, true);
				closeModal('collZipModal');
				followCollectionZip(pendingZipCollection, c, tok, d.token || '');
				pendingZipCollection = null;
			} else {
				flashMessage('czError', (d && d.error) || t('pwprompt.wrong'), 'error', 4000);
			}
		} catch (e) {
			flashMessage('czError', t('common.connection_error'), 'error', 4000);
		}
	}

	/* ---- per-file sharing options (expiry / download cap / password) ---- */
	// Which list the open options modal came from, so saving refreshes the right one.
	let fileOptionsFromAll = false;

	function openFileOptions(id, fromAll = false) {
		// Pre-fill from the already-loaded file so saving is non-destructive: this modal
		// writes ALL sharing options at once, so blank fields would otherwise clear them.
		fileOptionsFromAll = fromAll;
		const f = (fromAll ? files.find(x => x.id === id) : myFiles.find(x => x.id === id)) || {};
		document.getElementById('foFileId').value = id;
		const daysLeft = f.expiresAt > 0 ? Math.ceil((f.expiresAt - Date.now() / 1000) / 86400) : 0;
		document.getElementById('foExpiry').value = daysLeft > 0 ? daysLeft : '';
		document.getElementById('foMaxDl').value = f.maxDownloads > 0 ? f.maxDownloads : '';
		document.getElementById('foLimitAction').value = f.onLimitAction === 'delete' ? 'delete' : 'keep';
		document.getElementById('foOneTime').checked = !!f.oneTime;
		const usedNote = document.getElementById('foOneTimeUsed');
		if (usedNote) usedNote.style.display = f.consumed ? 'block' : 'none';
		document.getElementById('foPassword').value = '';
		document.getElementById('foPassword2').value = '';
		document.getElementById('foClearPw').checked = false;
		// pt 7: offering "remove the existing password" on a file that has none is noise — and
		// worse, it reads as if the file might be protected. Show it only when there is one.
		const foClearRow = document.getElementById('foClearPwRow');
		if (foClearRow) foClearRow.style.display = f.hasPassword ? '' : 'none';
		resetFoValidation();
		const msg = document.getElementById('fileOptionsMessage');
		if (msg) { msg.textContent = ''; msg.className = 'auth-message'; }
		showModal('fileOptionsModal');
	}

	// Live strength meter + requirement checklist for the file-options password.
	function updateFoStrength() {
		const val = document.getElementById('foPassword').value;
		const bar = document.getElementById('foPwdBar');
		let score = 0;
		const checks = [
			['foReqLen', val.length >= 8],
			['foReqUpper', /[A-Z]/.test(val)],
			['foReqDigit', /\d/.test(val)],
			['foReqSpec', /[^a-zA-Z0-9]/.test(val)]
		];
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
		updateFoMatch();
	}

	function updateFoMatch() {
		const p1 = document.getElementById('foPassword').value;
		const p2 = document.getElementById('foPassword2').value;
		const status = document.getElementById('foPassMatchStatus');
		const confirmInput = document.getElementById('foPassword2');
		if (!p2) { if (status) { status.className = 'field-status'; status.textContent = ''; } confirmInput.classList.remove('error', 'success'); return; }
		const ok = p1 === p2;
		if (status) {
			status.textContent = ok ? t('pwd.match_ok') : t('pwd.match_bad');
			status.className = 'field-status show ' + (ok ? 'status-ok' : 'status-bad');
		}
		confirmInput.classList.toggle('success', ok);
		confirmInput.classList.toggle('error', !ok);
	}

	function resetFoValidation() {
		const bar = document.getElementById('foPwdBar');
		if (bar) bar.style.width = '0%';
		['foReqLen', 'foReqUpper', 'foReqDigit', 'foReqSpec'].forEach(id => {
			const el = document.getElementById(id);
			if (el) el.classList.remove('valid');
		});
		const status = document.getElementById('foPassMatchStatus');
		if (status) { status.className = 'field-status'; status.textContent = ''; }
		document.getElementById('foPassword').classList.remove('error', 'success');
		document.getElementById('foPassword2').classList.remove('error', 'success');
	}

	async function saveFileOptions() {
		const password = document.getElementById('foPassword').value;
		const password2 = document.getElementById('foPassword2').value;
		const clearPw = document.getElementById('foClearPw').checked;
		// Only validate the new password when one is actually being set.
		if (!clearPw && password !== '') {
			if (password.length < 8) {
				flashMessage('fileOptionsMessage', t('panel.fo.pw_short'), 'error');
				return;
			}
			if (password !== password2) {
				flashMessage('fileOptionsMessage', t('panel.fo.pw_mismatch'), 'error');
				return;
			}
		}
		const body = {
			file_id: document.getElementById('foFileId').value,
			expiry_days: parseInt(document.getElementById('foExpiry').value) || 0,
			max_downloads: parseInt(document.getElementById('foMaxDl').value) || 0,
			one_time: document.getElementById('foOneTime').checked,
			on_limit_action: document.getElementById('foLimitAction').value,
			password: password,
			clear_password: clearPw
		};
		try {
			const d = await FHApi.post('user_set_file_options', body);
			if (d.success) {
				flashMessage('fileOptionsMessage', t('panel.fo.saved'), 'success');
				setTimeout(() => {
					closeModal('fileOptionsModal');
					// Refresh whichever list the modal was opened from, so the sharing badges
					// and the pre-fill for the next open reflect what was just saved.
					if (fileOptionsFromAll) loadFiles(currentPage); else loadMyFiles();
				}, 1000);
			} else {
				flashMessage('fileOptionsMessage', d.error || t('panel.fo.save_error'), 'error');
			}
		} catch (e) {
			flashMessage('fileOptionsMessage', t('common.connection_error'), 'error');
		}
	}

	function deleteMyFile(id, name, token) {
		document.getElementById('myFileDeleteName').textContent = name;
		const msg = document.getElementById('myFileDeleteMessage');
		if (msg) { msg.textContent = ''; msg.className = 'auth-message'; }
		pendingMyFileDelete = { id, token };
		showModal('myFileDeleteModal');
	}

	async function executeMyFileDelete() {
		if (!pendingMyFileDelete) return;
		try {
			const formData = new FormData();
			formData.append('id', pendingMyFileDelete.id);
			formData.append('token', pendingMyFileDelete.token);
			const d = await FHApi.postForm('delete', formData);
			if (d.success) {
				flashMessage('myFileDeleteMessage', t('panel.my.deleted'), 'success');
				loadMyFiles();
				setTimeout(() => { closeModal('myFileDeleteModal'); pendingMyFileDelete = null; }, 1200);
			} else {
				flashMessage('myFileDeleteMessage', d.error || t('panel.my.delete_error'), 'error');
			}
		} catch (e) {
			flashMessage('myFileDeleteMessage', t('common.connection_error'), 'error');
		}
	}


	function bindFileControls() {
		document.getElementById('search')?.addEventListener('input', (event) => {
			clearTimeout(filesSearchTimer);
			const value = event.target.value.trim();
			filesSearchTimer = setTimeout(() => {
				filesSearch = value;
				collSearch = value;
				reloadScopedList();
			}, 350);
		});
		document.getElementById('selectAllFiles')?.addEventListener(
			'change',
			(event) => toggleSelectAllFiles(event.target.checked)
		);
		document.getElementById('mySearch')?.addEventListener('input', () => {
			myCurrentPage = 1;
			collectionsPage = 1;
			renderMyFiles();
			renderCollections();
		});
		document.getElementById('foPassword')?.addEventListener('input', updateFoStrength);
		document.getElementById('foPassword2')?.addEventListener('input', updateFoMatch);
	}

	function initFilesTab() {
		loadFileFilters();
		ensureMyCollections().then(() => {
			if (files.length) renderFiles();
		});
		if (document.getElementById('filesBody')) loadFiles();
		if (document.getElementById('adminCollectionsBody')) loadAdminCollections();
	}

	function initMyFilesTab() {
		loadMyFileFilters();
		ensureMyCollections().then(() => {
			if (myFiles.length) renderMyFiles();
		});
		loadMyFiles();
		updateMySortIcons();
		loadCollections();
	}

	function refreshAdmin(silent = true) {
		if (scopeShowsFiles()) loadFiles(currentPage, silent);
		if (scopeShowsCollections()) loadAdminCollections();
	}

	function refreshMy(silent = true) {
		return loadMyFiles(silent);
	}

	window.FHPanelFiles = Object.freeze({
		renderPager, loadFiles, sortBy, goPage, showDeleteFile, executeFileDelete,
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
		bindFileControls, initFilesTab, initMyFilesTab, refreshAdmin, refreshMy
	});
}());
