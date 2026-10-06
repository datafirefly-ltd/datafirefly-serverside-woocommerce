/**
 * DataFirefly Server-Side: Meta Pixel module.
 *
 * Enqueued only when the Meta destination is enabled and configured. Registers itself in
 * window.DFSS_DEST; the core tracker injects it after consent and fires it with the event id it
 * shares with the server event, so Meta deduplicates the two.
 */
(function () {
	'use strict';

	var MAP = {
		page_view: 'PageView',
		view_content: 'ViewContent',
		add_to_cart: 'AddToCart',
		initiate_checkout: 'InitiateCheckout',
		add_payment_info: 'AddPaymentInfo',
		purchase: 'Purchase',
		lead: 'Lead',
		complete_registration: 'CompleteRegistration',
		search: 'Search',
		subscribe: 'Subscribe',
		schedule: 'Schedule',
		contact: 'Contact',
		start_trial: 'StartTrial',
		submit_application: 'SubmitApplication',
		donate: 'Donate',
		find_location: 'FindLocation',
		customize_product: 'CustomizeProduct',
		add_to_wishlist: 'AddToWishlist'
	};
	var ready = false;

	function inject(cfg) {
		if (ready || !cfg || !cfg.pixelId) {
			return ready;
		}
		// A pixel already on the page (another plugin, GTM) is reused: a second init would
		// double-count. It must use the same pixel id as the DataFirefly account.
		var preExisting = typeof window.fbq === 'function';

		// Standard Meta Pixel bootstrap.
		!(function (f, b, e, v, n, t, s) {
			if (f.fbq) return;
			n = f.fbq = function () {
				n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments);
			};
			if (!f._fbq) f._fbq = n;
			n.push = n;
			n.loaded = true;
			n.version = '2.0';
			n.queue = [];
			t = b.createElement(e);
			t.async = true;
			t.src = v;
			s = b.getElementsByTagName(e)[0];
			if (s && s.parentNode) {
				s.parentNode.insertBefore(t, s);
			} else {
				(b.head || b.documentElement).appendChild(t);
			}
		})(window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js');

		if (!preExisting) {
			try {
				window.fbq('init', String(cfg.pixelId));
			} catch (e) {}
		}
		ready = true;
		return true;
	}

	function fire(name, eventId, data) {
		if (!ready || typeof window.fbq !== 'function' || !MAP[name]) {
			return;
		}
		var props = {};
		if (data) {
			if (typeof data.value === 'number') { props.value = data.value; }
			if (data.currency) { props.currency = data.currency; }
			if (data.contentIds && data.contentIds.length) {
				props.content_ids = data.contentIds;
				props.content_type = 'product';
			}
			if (typeof data.numItems === 'number') { props.num_items = data.numItems; }
		}
		try {
			window.fbq('track', MAP[name], props, { eventID: eventId });
		} catch (e) {}
	}

	(window.DFSS_DEST = window.DFSS_DEST || {}).meta = { inject: inject, fire: fire };
})();
