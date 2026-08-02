/**
 * Tri-state, multi-column sorting for panel tables which do not own a server-side sorter.
 * Existing Files/My files/Users sorters are intentionally left in charge of their paged data.
 */
(function () {
	'use strict';

	const bootstrap = typeof document !== 'undefined' ? document.getElementById('panelBootstrap') : null;
	let PANEL = {};
	try {
		PANEL = JSON.parse(bootstrap?.dataset.config || '{}');
	} catch (_error) {
		PANEL = {};
	}
	const canMultiSort = Boolean(PANEL.isAdmin || (PANEL.perms || []).includes('tables.multi_sort'));
	const states = new WeakMap();
	const rowOrder = new WeakMap();
	const rowCounters = new WeakMap();
	const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' });

	function nextSorts(current, column, additive, multiAllowed = canMultiSort) {
		const next = current.map(sort => ({ ...sort }));
		const index = next.findIndex(sort => sort.column === column);
		const existing = index >= 0 ? next[index] : null;
		if (additive && multiAllowed) {
			if (!existing) next.push({ column, dir: 'desc' });
			else if (existing.dir === 'desc') existing.dir = 'asc';
			else next.splice(index, 1);
			return next;
		}
		if (!existing) return [{ column, dir: 'desc' }];
		return existing.dir === 'desc' ? [{ column, dir: 'asc' }] : [];
	}

	function numericValue(text) {
		const compact = text.replace(/\u00a0/g, ' ').trim();
		const size = compact.match(/^(-?[\d\s.,]+)\s*(B|KB|KIB|MB|MIB|GB|GIB|TB|TIB)$/i);
		if (size) {
			const units = { B: 1, KB: 1e3, KIB: 1024, MB: 1e6, MIB: 1024 ** 2, GB: 1e9, GIB: 1024 ** 3, TB: 1e12, TIB: 1024 ** 4 };
			const number = Number(size[1].replace(/\s/g, '').replace(',', '.'));
			return Number.isFinite(number) ? number * units[size[2].toUpperCase()] : null;
		}
		const percent = compact.match(/^(-?[\d\s.,]+)\s*%$/);
		if (percent) {
			const number = Number(percent[1].replace(/\s/g, '').replace(',', '.'));
			return Number.isFinite(number) ? number : null;
		}
		if (/^-?[\d\s]+(?:[.,]\d+)?$/.test(compact)) {
			const number = Number(compact.replace(/\s/g, '').replace(',', '.'));
			return Number.isFinite(number) ? number : null;
		}
		return null;
	}

	function dateValue(text) {
		const match = text.trim().match(/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?/);
		if (!match) return null;
		const value = Date.UTC(
			Number(match[3]), Number(match[2]) - 1, Number(match[1]),
			Number(match[4] || 0), Number(match[5] || 0), Number(match[6] || 0)
		);
		return Number.isFinite(value) ? value : null;
	}

	function cellValue(cell) {
		if (!cell) return '';
		if (cell.dataset.sortValue !== undefined) {
			const explicit = Number(cell.dataset.sortValue);
			return Number.isFinite(explicit) && cell.dataset.sortValue.trim() !== ''
				? explicit : cell.dataset.sortValue.toLocaleLowerCase();
		}
		const input = cell.querySelector('input, select');
		if (input) {
			if (input.type === 'checkbox') return input.checked ? 1 : 0;
			return String(input.value || '').toLocaleLowerCase();
		}
		const text = (cell.textContent || '').replace(/\s+/g, ' ').trim();
		const date = dateValue(text);
		if (date !== null) return date;
		const number = numericValue(text);
		if (number !== null) return number;
		return text.toLocaleLowerCase();
	}

	function compareValues(left, right) {
		if (typeof left === 'number' && typeof right === 'number') return left - right;
		return collator.compare(String(left), String(right));
	}

	function rememberRows(tbody) {
		let counter = rowCounters.get(tbody) || 0;
		let discovered = false;
		Array.from(tbody.rows).forEach(row => {
			if (!rowOrder.has(row)) {
				rowOrder.set(row, counter++);
				discovered = true;
			}
		});
		rowCounters.set(tbody, counter);
		return discovered;
	}

	function updateIcons(table, state) {
		table.querySelectorAll('thead th[data-table-sort-index]').forEach(th => {
			const icon = th.querySelector('.sort-icon');
			const index = state.sorts.findIndex(sort => sort.column === Number(th.dataset.tableSortIndex));
			th.classList.toggle('sorted', index >= 0);
			th.setAttribute('aria-sort', index < 0 ? 'none' : (state.sorts[index].dir === 'asc' ? 'ascending' : 'descending'));
			if (icon) {
				icon.innerHTML = index < 0 ? ''
					: (state.sorts[index].dir === 'asc' ? '▲' : '▼')
						+ (state.sorts.length > 1 ? `<sup>${index + 1}</sup>` : '');
			}
		});
	}

	function applySort(table) {
		const tbody = table.tBodies[0];
		const state = states.get(table);
		if (!tbody || !state) return;
		rememberRows(tbody);
		const rows = Array.from(tbody.rows);
		rows.sort((left, right) => {
			for (const sort of state.sorts) {
				const comparison = compareValues(cellValue(left.cells[sort.column]), cellValue(right.cells[sort.column]));
				if (comparison !== 0) return sort.dir === 'asc' ? comparison : -comparison;
			}
			return (rowOrder.get(left) || 0) - (rowOrder.get(right) || 0);
		});
		const fragment = document.createDocumentFragment();
		rows.forEach(row => fragment.appendChild(row));
		tbody.appendChild(fragment);
		updateIcons(table, state);
	}

	function actionHeader(th, index, count) {
		if (th.matches('[data-no-sort]') || th.querySelector('input, button') || Number(th.colSpan) > 1) return true;
		if (th.hasAttribute('data-sort') || /sort/i.test(th.dataset.fhClick || '')) return true;
		const actions = String(window.t?.('common.actions') || 'Actions').trim().toLocaleLowerCase();
		const label = (th.textContent || '').trim().toLocaleLowerCase();
		return index === count - 1 && [actions, 'actions', 'akcje', 'acciones', '操作'].includes(label);
	}

	function prepareTable(table) {
		if (!table || table.matches('.shortcuts-table') || !table.tHead || !table.tBodies.length) return;
		if (!states.has(table)) states.set(table, { sorts: [] });
		const headers = Array.from(table.tHead.rows[table.tHead.rows.length - 1]?.cells || []);
		table.classList.add('table-stable');
		table.style.setProperty('--table-min-width', `${Math.max(680, headers.length * 110)}px`);
		headers.forEach((th, index) => {
			if (!th.title) th.title = (th.textContent || '').replace(/\s+/g, ' ').trim();
			if (actionHeader(th, index, headers.length)) return;
			th.dataset.tableSortIndex = String(index);
			th.classList.add('table-sortable');
			th.setAttribute('aria-sort', 'none');
			if (!th.querySelector('.sort-icon')) {
				th.append(Object.assign(document.createElement('span'), { className: 'sort-icon' }));
			}
		});
		rememberRows(table.tBodies[0]);
	}

	function refresh(root = document) {
		if (root.matches?.('table')) prepareTable(root);
		root.querySelectorAll?.('table').forEach(prepareTable);
	}

	if (typeof document !== 'undefined') {
		refresh();
		document.addEventListener('click', event => {
			const th = event.target.closest?.('th[data-table-sort-index]');
			if (!th) return;
			const table = th.closest('table');
			const state = states.get(table);
			if (!state) return;
			state.sorts = nextSorts(state.sorts, Number(th.dataset.tableSortIndex), event.shiftKey, canMultiSort);
			applySort(table);
		});

		const observer = new MutationObserver(mutations => {
			const tablesToSort = new Set();
			mutations.forEach(mutation => mutation.addedNodes.forEach(node => {
				if (!(node instanceof Element)) return;
				refresh(node);
				const row = node.matches('tr') ? node : node.querySelector('tr');
				const table = row?.closest('table');
				if (table && !rowOrder.has(row) && rememberRows(table.tBodies[0]) && states.get(table)?.sorts.length) {
					tablesToSort.add(table);
				}
			}));
			tablesToSort.forEach(applySort);
		});
		observer.observe(document.body, { childList: true, subtree: true });
	}

	window.FHTableSort = Object.freeze({ nextSorts, numericValue, dateValue, refresh });
})();
