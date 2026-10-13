/**
 * Panel tab bar on phones.
 *
 * Below 600px the bar is a single horizontally scrolling row (panel.css) instead of four
 * wrapped rows. A later tab ("Konto", "Powiadomienia") would then be active but scrolled out
 * of sight, so on load the bar is scrolled to centre the active tab. Nothing happens when the
 * bar fits (desktop).
 */
(function () {
	'use strict';

	function revealActiveTab() {
		const bar = document.querySelector('.tabs');
		const active = bar && bar.querySelector('.tab.active');
		if (!bar || !active || bar.scrollWidth <= bar.clientWidth) return;
		const barBox = bar.getBoundingClientRect();
		const tabBox = active.getBoundingClientRect();
		const left = tabBox.left - barBox.left + bar.scrollLeft;
		bar.scrollLeft = Math.max(0, left - (bar.clientWidth - tabBox.width) / 2);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', revealActiveTab);
	} else {
		revealActiveTab();
	}
	// The icon font arrives later and widens every tab; settle the position again then.
	window.addEventListener('load', revealActiveTab);
})();
