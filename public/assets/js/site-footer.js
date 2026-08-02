(function () {
	'use strict';

	function decodeContact(trigger) {
		const key = Number.parseInt(trigger?.dataset?.contactKey || '', 10);
		const encoded = String(trigger?.dataset?.contactBytes || '');
		if (!Number.isInteger(key) || key < 0 || key > 255 || !/^\d+(?:\.\d+)*$/.test(encoded)) {
			return '';
		}

		const bytes = encoded.split('.').map((value) => Number.parseInt(value, 10));
		if (bytes.some((value) => !Number.isInteger(value) || value < 0 || value > 255)) {
			return '';
		}

		const address = bytes.map((value) => String.fromCharCode(value ^ key)).join('');
		return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(address) ? address : '';
	}

	function handleContactClick(event) {
		const trigger = event.target?.closest?.('[data-footer-contact]');
		if (!trigger) return;

		const address = decodeContact(trigger);
		if (!address) return;

		event.preventDefault();
		window.location.href = 'mailto:' + address;
	}

	document.addEventListener('click', handleContactClick);

	// Small, immutable surface for regression tests and optional integrations.
	window.FHSiteFooter = Object.freeze({ decodeContact });
})();
