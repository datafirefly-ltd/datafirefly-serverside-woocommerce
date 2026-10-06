/**
 * DataFirefly Server-Side: OpenAI (ChatGPT Ads) pixel module.
 *
 * Enqueued only when the OpenAI destination is enabled and configured. OpenAI deduplicates on
 * pixel id + event name + event_id, so firing this pixel and the server event is intended.
 */
(function () {
	'use strict';

	var MAP = {
		page_view: 'page_viewed',
		view_content: 'contents_viewed',
		add_to_cart: 'items_added',
		initiate_checkout: 'checkout_started',
		purchase: 'order_created',
		lead: 'lead_created',
		complete_registration: 'registration_completed'
	};
	// Amounts are integers in minor units (4200 = 42.00), except for zero-decimal currencies.
	var ZERO_DECIMAL = { BIF: 1, CLP: 1, DJF: 1, GNF: 1, JPY: 1, KMF: 1, KRW: 1, PYG: 1, RWF: 1, UGX: 1, VND: 1, VUV: 1, XAF: 1, XOF: 1, XPF: 1 };
	var ready = false;

	function amount(value, currency) {
		return Math.round(value * (ZERO_DECIMAL[String(currency || '').toUpperCase()] ? 1 : 100));
	}

	function inject(cfg, helpers) {
		if (ready || !cfg || !cfg.pixelId) {
			return ready;
		}
		// An SDK already on the page is reused: a second init would double-count. The pixel
		// defaults to consent=true, and the core only injects after its own consent gate.
		if (typeof window.oaiq !== 'function') {
			var q = function () { q.q.push(arguments); };
			q.q = [];
			window.oaiq = q;
			helpers.loadScript('https://bzrcdn.openai.com/sdk/oaiq.min.js');
			try {
				window.oaiq('init', { pixelId: String(cfg.pixelId) });
			} catch (e) {}
		}
		ready = true;
		return true;
	}

	function fire(name, eventId, data) {
		if (!ready || typeof window.oaiq !== 'function' || !MAP[name]) {
			return;
		}
		var props = { type: 'customer_action' };
		if (data) {
			if (data.contentIds && data.contentIds.length) {
				props.type = 'contents';
				props.contents = data.contentIds.slice(0, 3).map(function (id) {
					return { id: String(id) };
				});
			}
			if (typeof data.value === 'number' && data.currency) {
				props.amount = amount(data.value, data.currency);
				props.currency = String(data.currency).toUpperCase();
			}
		}
		try {
			window.oaiq('measure', MAP[name], props, { event_id: eventId });
		} catch (e) {}
	}

	(window.DFSS_DEST = window.DFSS_DEST || {}).openai = { inject: inject, fire: fire };
})();
