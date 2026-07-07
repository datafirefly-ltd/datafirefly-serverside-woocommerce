/**
 * DataFirefly Server-Side — admin helper (Activity panel).
 *
 * Tiny, dependency-free. Two jobs:
 *   1. Reveal the "advanced credentials" block on the connect screen.
 *   2. Auto-refresh the Activity table every 30s by re-fetching the page's
 *      table fragment via the admin-ajax endpoint, so the operator sees new
 *      events without a manual reload. Falls back silently if anything is off.
 *
 * The data itself is rendered server-side (escaped in PHP); this only swaps the
 * table body HTML it receives from our own nonce-protected ajax action.
 */
(function () {
	'use strict';

	var CFG = window.DFSS_ADMIN || {};

	// 1. Advanced block toggle (progressive enhancement of the inline link).
	function wireAdvancedToggle() {
		var link = document.querySelector('[data-dfss-toggle-advanced]');
		var box = document.getElementById('dfss-adv');
		if (!link || !box) {
			return;
		}
		link.addEventListener('click', function (e) {
			e.preventDefault();
			box.style.display = 'block';
			link.style.display = 'none';
		});
	}

	// 2. Activity auto-refresh.
	function wireActivityRefresh() {
		var tbody = document.getElementById('dfss-activity-rows');
		if (!tbody || !CFG.ajaxUrl || !CFG.nonce) {
			return;
		}

		function refresh() {
			var url = CFG.ajaxUrl +
				(CFG.ajaxUrl.indexOf('?') === -1 ? '?' : '&') +
				'action=dfss_activity&_ajax_nonce=' + encodeURIComponent(CFG.nonce);
			fetch(url, { credentials: 'same-origin' })
				.then(function (r) { return r.ok ? r.json() : null; })
				.then(function (json) {
					if (json && json.success && typeof json.data === 'string') {
						tbody.innerHTML = json.data;
					}
				})
				.catch(function () {});
		}

		// Refresh every 30s while the tab is visible.
		setInterval(function () {
			if (document.visibilityState === 'visible') {
				refresh();
			}
		}, 30000);
	}

	function init() {
		wireAdvancedToggle();
		wireActivityRefresh();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
