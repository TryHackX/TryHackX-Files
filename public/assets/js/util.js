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

	var HTML_ESCAPES = {
		'&': '&amp;',
		'<': '&lt;',
		'>': '&gt;',
		'"': '&quot;',
		"'": '&#39;'
	};

	/**
	 * HTML-escape a value for insertion into innerHTML — as element text AND as the value of a
	 * quoted attribute. Quotes are escaped on purpose: the old text-node trick left `"` and `'`
	 * alone, so a stored name containing `"` closed the attribute and could add `data-fh-*`
	 * attributes, which panel-events.js executes.
	 */
	function esc(s) {
		if (s === null || s === undefined) return '';
		return String(s).replace(/[&<>"']/g, function (ch) { return HTML_ESCAPES[ch]; });
	}

	/** Alias of esc(), named for the call sites that fill an attribute value. */
	var attr = esc;

	/**
	 * A string argument for a declarative handler such as
	 * `data-fh-click="askDelete(<jsArg>)"` (see panel-events.js): a single-quoted literal with
	 * backslash, quote and line breaks escaped the way that parser reads them, then HTML-escaped
	 * so the whole thing can sit inside a double-quoted attribute. Use it for every value that
	 * is not a number or a server-generated hex id.
	 */
	function jsArg(value) {
		var text = String(value === null || value === undefined ? '' : value)
			.replace(/\\/g, '\\\\')
			.replace(/'/g, "\\'")
			.replace(/\r/g, '\\r')
			.replace(/\n/g, '\\n');
		return esc("'" + text + "'");
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

	/**
	 * Copy text to the clipboard and report whether it really happened.
	 *
	 * `navigator.clipboard` is missing on plain-http installs and rejects when the document is
	 * not focused or the permission is refused, so callers must not announce "copied" before this
	 * promise settles. Falls back to a hidden textarea + execCommand('copy').
	 */
	function copyText(text) {
		var value = String(text === null || text === undefined ? '' : text);
		var viaTextarea = function () {
			try {
				var area = document.createElement('textarea');
				area.value = value;
				area.setAttribute('readonly', '');
				area.style.position = 'fixed';
				area.style.top = '-1000px';
				document.body.appendChild(area);
				area.select();
				var ok = document.execCommand('copy');
				document.body.removeChild(area);
				return ok === true;
			} catch (error) {
				return false;
			}
		};
		if (typeof navigator !== 'undefined' && navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
			return navigator.clipboard.writeText(value).then(
				function () { return true; },
				function () { return viaTextarea(); }
			);
		}
		return Promise.resolve(viaTextarea());
	}

	window.FHUtil = {
		esc: esc,
		attr: attr,
		jsArg: jsArg,
		safeHttpUrl: safeHttpUrl,
		formatSize: formatSize,
		formatDate: formatDate,
		copyText: copyText
	};
})();
