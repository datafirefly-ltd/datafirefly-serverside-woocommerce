/** DataFirefly Server-Side admin: advanced-credentials toggle and 30 s Activity auto-refresh. */
(function () {
	'use strict';

	var CFG = window.DFSS_ADMIN || {};

	// Reveal the advanced credentials form.
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

	// Refresh the Activity table body every 30 s while the tab is visible.
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
