/**
 * DataFirefly Server-Side: TikTok Pixel module.
 *
 * Enqueued only when the TikTok destination is enabled and configured. Registers itself in
 * window.DFSS_DEST; fired with the event id shared with the server event.
 */
(function () {
	'use strict';

	var MAP = {
		page_view: 'Pageview',
		view_content: 'ViewContent',
		add_to_cart: 'AddToCart',
		initiate_checkout: 'InitiateCheckout',
		add_payment_info: 'AddPaymentInfo',
		purchase: 'CompletePayment',
		lead: 'SubmitForm',
		complete_registration: 'CompleteRegistration',
		search: 'Search',
		subscribe: 'Subscribe',
		add_to_wishlist: 'AddToWishlist',
		contact: 'Contact'
	};
	var ready = false;

	function inject(cfg) {
		if (ready || !cfg || !cfg.pixelCode) {
			return ready;
		}
		// Standard TikTok Pixel bootstrap.
		!(function (w, d, t) {
			w.TiktokAnalyticsObject = t;
			var ttq = (w[t] = w[t] || []);
			ttq.methods = ['page', 'track', 'identify', 'instances', 'debug', 'on', 'off', 'once', 'ready', 'alias', 'group', 'enableCookie', 'disableCookie'];
			ttq.setAndDefer = function (obj, method) {
				obj[method] = function () {
					obj.push([method].concat(Array.prototype.slice.call(arguments, 0)));
				};
			};
			for (var i = 0; i < ttq.methods.length; i++) {
				ttq.setAndDefer(ttq, ttq.methods[i]);
			}
			ttq.instance = function (id) {
				var inst = ttq._i[id] || [];
				for (var j = 0; j < ttq.methods.length; j++) {
					ttq.setAndDefer(inst, ttq.methods[j]);
				}
				return inst;
			};
			ttq.load = function (id, opts) {
				var url = 'https://analytics.tiktok.com/i18n/pixel/events.js';
				ttq._i = ttq._i || {};
				ttq._i[id] = [];
				ttq._i[id]._u = url;
				ttq._t = ttq._t || {};
				ttq._t[id] = +new Date();
				ttq._o = ttq._o || {};
				ttq._o[id] = opts || {};
				var script = d.createElement('script');
				script.type = 'text/javascript';
				script.async = true;
				script.src = url + '?sdkid=' + id + '&lib=' + t;
				var first = d.getElementsByTagName('script')[0];
				if (first && first.parentNode) {
					first.parentNode.insertBefore(script, first);
				} else {
					(d.head || d.documentElement).appendChild(script);
				}
			};
			ttq.load(String(cfg.pixelCode));
			ttq.page();
		})(window, document, 'ttq');
		ready = true;
		return true;
	}

	function fire(name, eventId, data) {
		if (!ready || !window.ttq || typeof window.ttq.track !== 'function' || !MAP[name]) {
			return;
		}
		var props = {};
		if (data) {
			if (typeof data.value === 'number') { props.value = data.value; }
			if (data.currency) { props.currency = data.currency; }
			if (data.contents && data.contents.length) { props.contents = data.contents; }
		}
		try {
			window.ttq.track(MAP[name], props, { event_id: eventId });
		} catch (e) {}
	}

	(window.DFSS_DEST = window.DFSS_DEST || {}).tiktok = { inject: inject, fire: fire };
})();
