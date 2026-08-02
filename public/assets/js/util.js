/**
 * Shared front-end helpers (Q7 / Faza 5 · #3).
 *
 * One source of truth for the small formatting/escaping helpers that used to be
 * copy-pasted into index.js and panel.js. Load this BEFORE those scripts; they
 * pick the functions up from `window.FHUtil`. Kept dependency-free and framework
 * -agnostic, mirroring the file-icons.js pattern.
 */
(function () {
	'use strict';

	/** HTML-escape a value for safe insertion as text into innerHTML. */
	function esc(s) {
		if (s === null || s === undefined) return '';
		var d = document.createElement('div');
		d.textContent = s;
		return d.innerHTML;
	}

	/**
	 * Return a browser-navigable HTTP(S) or relative URL, rejecting executable and
	 * ambiguous schemes. Callers must still HTML-escape the result when interpolating it
	 * into markup.
	 */
	function safeHttpUrl(value) {
		var raw = String(value === null || value === undefined ? '' : value).trim();
		if (!raw || /[\u0000-\u001F\u007F]/.test(raw)) return '';
		if (/^https?:\/\//i.test(raw)) return raw;
		if (/^(?:\/(?!\/)|\.{1,2}\/|[?#])/.test(raw)) return raw;
		if (/^[a-z][a-z0-9+.-]*:/i.test(raw) || /^(?:\\\\|\/\/)/.test(raw)) return '';
		return raw;
	}

	/**
	 * Human-readable byte size using IEC units (KiB/MiB/…), 2 decimals.
	 * Exact bytes below 1 KiB; 0/negative/NaN collapse to "0 B".
	 */
	function formatSize(bytes) {
		var b = Number(bytes);
		if (!b || b < 0) return '0 B';
		if (b < 1024) return b + ' B';
		var units = ['KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
		var i = -1;
		do { b /= 1024; i++; } while (b >= 1024 && i < units.length - 1);
		return b.toFixed(2) + ' ' + units[i];
	}

	/** Format a UNIX timestamp (seconds) as a locale date + HH:MM; empty → "-". */
	function formatDate(ts) {
		if (!ts) return '-';
		var d = new Date(ts * 1000);
		return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
	}

	window.FHUtil = {
		esc: esc,
		safeHttpUrl: safeHttpUrl,
		formatSize: formatSize,
		formatDate: formatDate
	};
})();
