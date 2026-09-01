/**
 * DataFirefly Server-Side — client tracking layer.
 *
 * One key, full funnel, dedup-perfect. For every event this script:
 *   1. Generates an event_id (UUID v4, or "order_<id>" for purchase — passed by
 *      PHP) ONCE, and uses it for BOTH sides.
 *   2. Fires the light client pixel (Meta fbq / GA4 gtag / TikTok ttq) — which
 *      the plugin injects itself using PUBLIC ids only — WITH that event_id, so
 *      the browser cookies (_fbp/_fbc/_ga/_ttp) get set and a fast client event
 *      is recorded.
 *   3. Beacons the SAME event_id + data to /wp-json/dfss/v1/collect, which signs
 *      and forwards it to the server-side CAPI.
 * Meta/GA/TikTok then deduplicate on (event_name, event_id): if an ad-blocker
 * kills step 2, only the server passes — always tracked, never doubled.
 *
 * NOTHING fires until marketing consent is granted (when required). No secret or
 * access token is ever present here — only the public destination ids.
 *
 * No framework, no jQuery: this loads on the storefront and must stay tiny and
 * defensive (a tracking error must never break the page).
 */
(function () {
	'use strict';

	// Injected by PHP via wp_localize_script as window.DFSS_CFG.
	var CFG = window.DFSS_CFG || {};
	var PUBLIC = CFG.public || {}; // { meta:{pixelId}, ga4:{measurementId}, tiktok:{pixelCode}, pinterest:{adAccountId} }
	var CONSENT = CFG.consent || { required: true, cmp: '', hasWpConsentApi: false };
	var EVENTS = CFG.events || {}; // server-provided context for this page (e.g. purchase)
	var REST = CFG.restUrl || '';
	var NONCE = CFG.nonce || '';
	var COOKIE_DAYS = 90;

	// Guard: never run twice (e.g. if enqueued by a stray theme).
	if (window.__dfssTrackerLoaded) {
		return;
	}
	window.__dfssTrackerLoaded = true;

	// ---- tiny utils ---------------------------------------------------------

	function uuidv4() {
		// Prefer the crypto API; fall back to Math.random only if unavailable.
		if (window.crypto && typeof window.crypto.randomUUID === 'function') {
			return window.crypto.randomUUID();
		}
		if (window.crypto && window.crypto.getRandomValues) {
			var b = new Uint8Array(16);
			window.crypto.getRandomValues(b);
			b[6] = (b[6] & 0x0f) | 0x40;
			b[8] = (b[8] & 0x3f) | 0x80;
			var h = [];
			for (var i = 0; i < 16; i++) {
				h.push((b[i] + 0x100).toString(16).substr(1));
			}
			return (
				h[0] + h[1] + h[2] + h[3] + '-' + h[4] + h[5] + '-' + h[6] + h[7] +
				'-' + h[8] + h[9] + '-' + h[10] + h[11] + h[12] + h[13] + h[14] + h[15]
			);
		}
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
			var r = (Math.random() * 16) | 0;
			var v = c === 'x' ? r : (r & 0x3) | 0x8;
			return v.toString(16);
		});
	}

	function setCookie(name, value, days) {
		try {
			var exp = new Date(Date.now() + days * 864e5).toUTCString();
			var secure = location.protocol === 'https:' ? '; Secure' : '';
			document.cookie =
				name + '=' + encodeURIComponent(value) + '; Expires=' + exp +
				'; Path=/; SameSite=Lax' + secure;
		} catch (e) {}
	}

	function getCookie(name) {
		try {
			var m = document.cookie.match('(?:^|; )' + name + '=([^;]*)');
			return m ? decodeURIComponent(m[1]) : '';
		} catch (e) {
			return '';
		}
	}

	function getParam(name) {
		try {
			return new URLSearchParams(location.search).get(name) || '';
		} catch (e) {
			return '';
		}
	}

	function loadScript(src) {
		var s = document.createElement('script');
		s.async = true;
		s.src = src;
		var first = document.getElementsByTagName('script')[0];
		if (first && first.parentNode) {
			first.parentNode.insertBefore(s, first);
		} else {
			(document.head || document.documentElement).appendChild(s);
		}
	}

	// ---- click id capture (first-party, 90 days) ----------------------------

	// Capture ad click ids from the URL into first-party cookies so the server
	// can use them for matching long after the click. fbc has a Meta-prescribed
	// format: fb.1.<ts>.<fbclid>.
	function captureClickIds() {
		var fbclid = getParam('fbclid');
		if (fbclid) {
			// Only (re)write _dfss_fbc if we have a fresh fbclid.
			setCookie('_dfss_fbc', 'fb.1.' + Date.now() + '.' + fbclid, COOKIE_DAYS);
		}
		var gclid = getParam('gclid');
		if (gclid) {
			setCookie('_dfss_gclid', gclid, COOKIE_DAYS);
		}
		// Google issues gbraid or wbraid INSTEAD of gclid when the journey
		// crosses an app boundary or cookies are restricted. Same landing, same
		// cookie lifetime: whichever one arrives is the one that attributes.
		var gbraid = getParam('gbraid');
		if (gbraid) {
			setCookie('_dfss_gbraid', gbraid, COOKIE_DAYS);
		}
		var wbraid = getParam('wbraid');
		if (wbraid) {
			setCookie('_dfss_wbraid', wbraid, COOKIE_DAYS);
		}
		var msclkid = getParam('msclkid');
		if (msclkid) {
			setCookie('_dfss_msclkid', msclkid, COOKIE_DAYS);
		}
		var ttclid = getParam('ttclid');
		if (ttclid) {
			setCookie('_dfss_ttclid', ttclid, COOKIE_DAYS);
		}
		// ChatGPT Ads attribution id. The OAIQ pixel stores it itself in
		// __oppref (720h); our copy covers shops that run without the pixel.
		var oppref = getParam('oppref');
		if (oppref) {
			setCookie('_dfss_oppref', oppref, COOKIE_DAYS);
		}
	}

	// Read the browser identifiers we have available client-side. These are also
	// captured server-side at checkout, but sending them on every beacon keeps
	// match quality high for top-of-funnel events.
	function collectUserData() {
		var u = {};
		var fbp = getCookie('_fbp');
		if (fbp) { u.fbp = fbp; }
		// Prefer the live _fbc cookie set by the pixel; fall back to our captured one.
		var fbc = getCookie('_fbc') || getCookie('_dfss_fbc');
		if (fbc) { u.fbc = fbc; }
		var ttp = getCookie('_ttp');
		if (ttp) { u.ttp = ttp; }
		var ttclid = getCookie('_dfss_ttclid');
		if (ttclid) { u.ttclid = ttclid; }
		var gclid = getCookie('_dfss_gclid');
		if (gclid) { u.gclid = gclid; }
		var gbraid = getCookie('_dfss_gbraid');
		if (gbraid) { u.gbraid = gbraid; }
		var wbraid = getCookie('_dfss_wbraid');
		if (wbraid) { u.wbraid = wbraid; }
		var msclkid = getCookie('_dfss_msclkid');
		if (msclkid) { u.msclkid = msclkid; }
		// Prefer the live __oppref cookie set by the OAIQ pixel; fall back to ours.
		var oppref = getCookie('__oppref') || getCookie('_dfss_oppref');
		if (oppref) { u.oppref = oppref; }
		var obref = getCookie('__obref');
		if (obref) { u.obref = obref; }
		var ga = getCookie('_ga');
		if (ga) {
			// _ga is "GA1.2.<clientId>"; the dispatcher wants the <clientId> part.
			var parts = ga.split('.');
			if (parts.length >= 4) {
				u.clientId = parts[parts.length - 2] + '.' + parts[parts.length - 1];
			}
		}
		// GA4 session id — from the _ga_<container> cookie ("GS1.1.<sessionId>.<n>...").
		// Sending it lets the server-side event join the SAME session gtag opened,
		// so GA4 attributes it to that session's real source/medium instead of
		// opening a sourceless session that reports as "Unassigned".
		var mid = PUBLIC.ga4 && PUBLIC.ga4.measurementId;
		if (mid) {
			var gs = getCookie('_ga_' + String(mid).replace(/^G-/, ''));
			if (gs) {
				// _ga_<id> = "GS1.1.<sessionId>.<n>..." (legacy, dot-separated) OR
				// "GS2.1.s<sessionId>$o<n>$..." (2024+ format, $-separated, s-prefixed).
				// The capture group takes the numeric sessionId in both; a non-match
				// leaves sessionId unset (graceful) rather than shipping garbage.
				var m = gs.match(/^GS\d\.\d+\.s?(\d+)/);
				if (m) { u.sessionId = m[1]; }
			}
		}
		return u;
	}

	// ---- consent ------------------------------------------------------------

	// Resolve current marketing-consent state across the supported stacks.
	// Returns true/false; when required and indeterminate, returns false (deny).
	function hasMarketingConsent() {
		if (!CONSENT.required) {
			return true;
		}

		// 0. DataFirefly Cookie Consent (our own banner) — authoritative when
		// present. Exposes window.dfcc.hasConsent('marketing') from its cookie.
		if (window.dfcc && typeof window.dfcc.hasConsent === 'function') {
			try {
				return !!window.dfcc.hasConsent('marketing');
			} catch (e) {}
		}

		// 1. Official WP Consent API (cookie-backed; exposed on the page).
		if (typeof window.wp_has_consent === 'function') {
			try {
				return !!window.wp_has_consent('marketing');
			} catch (e) {}
		}

		// 2. Complianz.
		if (CONSENT.cmp === 'complianz' && window.cmplz && typeof window.cmplz.has_consent === 'function') {
			try {
				return !!window.cmplz.has_consent('marketing');
			} catch (e) {}
		}

		// 3. Cookiebot.
		if (window.Cookiebot && window.Cookiebot.consent) {
			try {
				return !!window.Cookiebot.consent.marketing;
			} catch (e) {}
		}

		// 4. IAB TCF v2 — treat "marketing" as purposes 3+4 (advertising) granted.
		if (typeof window.__tcfapi === 'function') {
			var tcfResult = null;
			try {
				window.__tcfapi('getTCData', 2, function (data, ok) {
					if (ok && data && data.purpose && data.purpose.consents) {
						tcfResult = !!(data.purpose.consents[3] && data.purpose.consents[4]);
					}
				});
			} catch (e) {}
			if (tcfResult !== null) {
				return tcfResult;
			}
		}

		// Required but no signal we understand -> deny (privacy-first).
		return false;
	}

	// Run `fn` once consent is granted. If already granted, run now. Otherwise
	// listen for the common "consent changed" signals and re-check.
	var consentListenersBound = false;
	var pendingOnConsent = [];

	function whenConsent(fn) {
		if (hasMarketingConsent()) {
			fn();
			return;
		}
		if (!CONSENT.required) {
			fn();
			return;
		}
		pendingOnConsent.push(fn);
		bindConsentListeners();
	}

	function flushPending() {
		if (!hasMarketingConsent()) {
			return;
		}
		var queue = pendingOnConsent.slice();
		pendingOnConsent.length = 0;
		for (var i = 0; i < queue.length; i++) {
			try { queue[i](); } catch (e) {}
		}
	}

	function bindConsentListeners() {
		if (consentListenersBound) {
			return;
		}
		consentListenersBound = true;

		// DataFirefly Cookie Consent (our own banner) — fires on accept/reject.
		document.addEventListener('dfcc_consent_change', flushPending);
		// WP Consent API dispatches this on every change.
		document.addEventListener('wp_listen_for_consent_change', flushPending);
		// Complianz.
		document.addEventListener('cmplz_status_change', flushPending);
		document.addEventListener('cmplz_enable_category', flushPending);
		// Cookiebot.
		window.addEventListener('CookiebotOnAccept', flushPending);
		window.addEventListener('CookiebotOnConsentReady', flushPending);
		// TCF.
		if (typeof window.__tcfapi === 'function') {
			try {
				window.__tcfapi('addEventListener', 2, function (data, ok) {
					if (ok && data && (data.eventStatus === 'useractioncomplete' || data.eventStatus === 'tcloaded')) {
						flushPending();
					}
				});
			} catch (e) {}
		}
	}

	// ---- client tag injection (PUBLIC ids only) -----------------------------

	var injected = { meta: false, ga4: false, tiktok: false, oaiq: false };

	function injectMeta() {
		if (injected.meta || !PUBLIC.meta || !PUBLIC.meta.pixelId) {
			return injected.meta;
		}
		// AUGMENT, NOT REPLACE: if the merchant already has a Meta pixel on the
		// page (another plugin / GTM), `window.fbq` already exists. We must NOT
		// add a second `init` for our id — that would double-count. Instead we
		// reuse the existing fbq and just fire our events WITH the shared eventID
		// (Meta dedups on eventID for the SAME pixel id). Operators must point
		// their existing pixel at the same id as their DataFirefly tenant.
		var preExisting = (typeof window.fbq === 'function');

		// Standard Meta Pixel bootstrap (no PII, just the public Pixel ID).
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

		// Only initialize our pixel when none was already present, to avoid a
		// duplicate init. When pre-existing, we trust the merchant's init.
		if (!preExisting) {
			try {
				window.fbq('init', String(PUBLIC.meta.pixelId));
			} catch (e) {}
		}
		injected.meta = true;
		return true;
	}

	function injectGa4() {
		if (injected.ga4 || !PUBLIC.ga4 || !PUBLIC.ga4.measurementId) {
			return injected.ga4;
		}
		var id = String(PUBLIC.ga4.measurementId);
		loadScript('https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(id));
		window.dataLayer = window.dataLayer || [];
		window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
		window.gtag('js', new Date());
		// We send events explicitly with our own transaction_id for dedup, so we
		// disable automatic page_view here to avoid an unattributed duplicate.
		window.gtag('config', id, { send_page_view: false });
		injected.ga4 = true;
		return true;
	}

	function injectTikTok() {
		if (injected.tiktok || !PUBLIC.tiktok || !PUBLIC.tiktok.pixelCode) {
			return injected.tiktok;
		}
		// Standard TikTok Pixel bootstrap (public Pixel Code only).
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
			ttq.load(String(PUBLIC.tiktok.pixelCode));
			ttq.page();
		})(window, document, 'ttq');
		injected.tiktok = true;
		return true;
	}

	function injectOaiq() {
		if (injected.oaiq || !PUBLIC.openai || !PUBLIC.openai.pixelId) {
			return injected.oaiq;
		}
		// AUGMENT, NOT REPLACE: if the OAIQ SDK (or its queue stub) is already
		// on the page, reuse it and skip our own init — a second init for the
		// same pixel would double-count. Official queue stub otherwise.
		var preExisting = (typeof window.oaiq === 'function');
		if (!preExisting) {
			var q = function () { q.q.push(arguments); };
			q.q = [];
			window.oaiq = q;
			loadScript('https://bzrcdn.openai.com/sdk/oaiq.min.js');
		}
		// The OAIQ pixel defaults to consent=true; injectAll() only ever runs
		// AFTER our own marketing-consent gate, so init here is compliant.
		if (!preExisting) {
			try {
				window.oaiq('init', { pixelId: String(PUBLIC.openai.pixelId) });
			} catch (e) {}
		}
		injected.oaiq = true;
		return true;
	}

	function injectAll() {
		injectMeta();
		injectGa4();
		injectTikTok();
		injectOaiq();
		// Pinterest is intentionally not injected client-side in v2.0 (the
		// dispatcher handles Pinterest server-side; no light client tag needed).
	}

	// ---- per-destination client fire (WITH the shared event_id) -------------

	// Map our canonical event name -> the destination's event name.
	var META_MAP = {
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
	var TT_MAP = {
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
	var GA4_MAP = {
		page_view: 'page_view',
		view_content: 'view_item',
		add_to_cart: 'add_to_cart',
		initiate_checkout: 'begin_checkout',
		add_payment_info: 'add_payment_info',
		purchase: 'purchase',
		lead: 'generate_lead',
		complete_registration: 'sign_up',
		search: 'search',
		view_item_list: 'view_item_list',
		select_item: 'select_item',
		view_cart: 'view_cart',
		remove_from_cart: 'remove_from_cart',
		add_shipping_info: 'add_shipping_info',
		add_to_wishlist: 'add_to_wishlist',
		subscribe: 'subscribe',
		schedule: 'schedule',
		contact: 'contact',
		share: 'share',
		start_trial: 'start_trial',
		submit_application: 'submit_application',
		donate: 'donate',
		find_location: 'find_location',
		customize_product: 'customize_product',
		login: 'login'
	};

	function fireMeta(name, eventId, data) {
		if (!injected.meta || typeof window.fbq !== 'function') {
			return;
		}
		var metaName = META_MAP[name];
		if (!metaName) {
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
			window.fbq('track', metaName, props, { eventID: eventId });
		} catch (e) {}
	}

	function fireGa4(name, eventId, data) {
		if (!injected.ga4 || typeof window.gtag !== 'function') {
			return;
		}
		// Client-side GA4 fires NOTHING. Not even purchase.
		//
		// This used to fire purchase, on the belief that "GA4 deduplicates on
		// transaction_id". Production disproved it: on datafirefly.com,
		// transaction 13945 came back from the GA4 Data API as
		// ecommercePurchases = 2 with revenue 298, exactly twice the 149 we
		// sent. The transaction_id WAS present and identical on both paths —
		// GA4 simply does not deduplicate a gtag event against a Measurement
		// Protocol one. Every sale was counted twice, and the merchant's
		// reported revenue with it.
		//
		// The server-side event is the single source for GA4. The gtag tag is
		// still injected (injectGa4) because the _ga cookie it sets is what
		// lets the server-side event join the same session — that is the only
		// reason the tag is there.
		return;
	}

	function fireTikTok(name, eventId, data) {
		if (!injected.tiktok || !window.ttq || typeof window.ttq.track !== 'function') {
			return;
		}
		var ttName = TT_MAP[name];
		if (!ttName) {
			return;
		}
		var props = {};
		if (data) {
			if (typeof data.value === 'number') { props.value = data.value; }
			if (data.currency) { props.currency = data.currency; }
			if (data.contents && data.contents.length) { props.contents = data.contents; }
		}
		try {
			window.ttq.track(ttName, props, { event_id: eventId });
		} catch (e) {}
	}

	var OAIQ_MAP = {
		page_view: 'page_viewed',
		view_content: 'contents_viewed',
		add_to_cart: 'items_added',
		initiate_checkout: 'checkout_started',
		purchase: 'order_created',
		lead: 'lead_created',
		complete_registration: 'registration_completed'
	};

	// OAIQ wants amounts as integers in MINOR currency units (4200 = 42.00).
	// Currencies without a minor unit must not be multiplied (mirror of the
	// dispatcher's toMinorUnits).
	var OAIQ_ZERO_DECIMAL = { BIF: 1, CLP: 1, DJF: 1, GNF: 1, JPY: 1, KMF: 1, KRW: 1, PYG: 1, RWF: 1, UGX: 1, VND: 1, VUV: 1, XAF: 1, XOF: 1, XPF: 1 };
	function oaiqAmount(value, currency) {
		var factor = OAIQ_ZERO_DECIMAL[String(currency || '').toUpperCase()] ? 1 : 100;
		return Math.round(value * factor);
	}

	function fireOaiq(name, eventId, data) {
		if (!injected.oaiq || typeof window.oaiq !== 'function') {
			return;
		}
		var oaiqName = OAIQ_MAP[name];
		if (!oaiqName) {
			return;
		}
		// Unlike GA4 (which proved it does NOT dedup gtag vs Measurement
		// Protocol), OpenAI documents deduplication explicitly: pixel id +
		// event name + event_id, first event wins. Server CAPI + this pixel
		// share our eventId, so firing both is the intended setup.
		var props = { type: 'customer_action' };
		if (data) {
			if (data.contentIds && data.contentIds.length) {
				props.type = 'contents';
				props.contents = data.contentIds.slice(0, 3).map(function (id) {
					return { id: String(id) };
				});
			}
			if (typeof data.value === 'number' && data.currency) {
				props.amount = oaiqAmount(data.value, data.currency);
				props.currency = String(data.currency).toUpperCase();
			}
		}
		try {
			window.oaiq('measure', oaiqName, props, { event_id: eventId });
		} catch (e) {}
	}

	function fireClient(name, eventId, clientData) {
		fireMeta(name, eventId, clientData);
		fireGa4(name, eventId, clientData);
		fireTikTok(name, eventId, clientData);
		fireOaiq(name, eventId, clientData);
	}

	// ---- beacon to our server -----------------------------------------------

	function beacon(name, eventId, beaconData) {
		if (!REST) {
			return;
		}
		var body = {
			event_name: name,
			event_id: eventId,
			source_url: location.href,
			user_data: collectUserData(),
			event_data: beaconData || {}
		};
		// Referring URL — lets GA4 derive source/medium when no client-side
		// session exists (ad-blocked gtag). Only forward a real external http(s)
		// referrer; empty/internal referrers add nothing.
		var ref = document.referrer;
		if (ref && /^https?:\/\//i.test(ref)) {
			body.page_referrer = ref;
		}
		var payload = JSON.stringify(body);

		// sendBeacon survives page unload (key for purchase on the thank-you
		// page and for add_to_cart that triggers navigation). It cannot set the
		// nonce header, so we pass the nonce in the URL for that path; fetch
		// (with the header) is preferred when the page is staying.
		// Events triggered by a click/submit that may navigate away — flush via
		// sendBeacon so they survive the unload.
		// Events that fire as the page is LEAVING. A plain fetch is cancelled
		// with the document, so these have to go out on sendBeacon or they are
		// simply lost — which is how a mailto click, a share and a booking link
		// can all look like nothing ever happened.
		var navigating = (name === 'purchase' || name === 'select_item' ||
			name === 'select_promotion' || name === 'lead' ||
			name === 'complete_registration' || name === 'contact' ||
			name === 'schedule' || name === 'share' || name === 'start_trial' ||
			name === 'subscribe' || name === 'submit_application' ||
			name === 'donate' || name === 'find_location' ||
			name === 'add_shipping_info' || name === 'add_to_wishlist');
		var usedBeacon = false;
		if (navigator.sendBeacon && (navigating || document.visibilityState === 'hidden')) {
			try {
				var url = REST + (REST.indexOf('?') === -1 ? '?' : '&') + '_wpnonce=' + encodeURIComponent(NONCE);
				var blob = new Blob([payload], { type: 'application/json' });
				usedBeacon = navigator.sendBeacon(url, blob);
			} catch (e) {}
		}
		if (usedBeacon) {
			return;
		}

		// Default path: fetch with the nonce header.
		try {
			fetch(REST, {
				method: 'POST',
				keepalive: true,
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': NONCE
				},
				body: payload
			}).catch(function () {});
		} catch (e) {}
	}

	// ---- the public track() : one id, both sides ----------------------------

	// `opts.eventId` lets PHP pin the id (purchase => "order_<id>"); otherwise a
	// fresh UUID is minted and shared between the client fire and the beacon.
	// `opts.clientData` shapes the client pixel; `opts.beaconData` the server event.
	function track(name, opts) {
		opts = opts || {};
		whenConsent(function () {
			injectAll(); // safe to call repeatedly; injects once
			var eventId = opts.eventId || uuidv4();
			fireClient(name, eventId, opts.clientData || opts.beaconData || {});
			// `clientOnly` fires the browser pixel but skips the server beacon.
			// Used for purchase: the server side is delivered by the authoritative
			// WooCommerce order hook (not the spoofable public beacon), keyed on the
			// same "order_<id>" so the two sides still deduplicate exactly.
			if (!opts.clientOnly) {
				beacon(name, eventId, opts.beaconData || {});
			}
		});
	}

	// Expose for theme/3rd-party use and for our own WooCommerce hooks below.
	window.dfssTrack = track;

	// ---- full-funnel auto-wiring -------------------------------------------

	function num(v) {
		var n = parseFloat(v);
		return isFinite(n) ? n : undefined;
	}

	// page_view on every page.
	function trackPageView() {
		track('page_view', {});
	}

	// view_content on an ordinary content page — article, service page, case
	// study. Context provided by PHP in EVENTS.content, which is built even when
	// WooCommerce is absent: an institutional site has content and leads, not a
	// cart, and it used to report neither.
	function trackContentView() {
		var c = EVENTS.content;
		if (!c || !c.id) {
			return;
		}
		var item = { id: String(c.id), name: c.name, category: c.category, quantity: 1 };
		track('view_content', {
			clientData: {
				contentIds: [item.id],
				items: [{ item_id: item.id, item_name: item.name, item_category: item.category }],
				contents: [{ content_id: item.id, content_name: item.name }]
			},
			beaconData: { products: [item] }
		});
	}

	// view_item on a product page — context provided by PHP in EVENTS.viewItem.
	function trackViewItem() {
		var v = EVENTS.viewItem;
		if (!v) {
			return;
		}
		track('view_content', {
			clientData: {
				value: num(v.value),
				currency: v.currency,
				contentIds: v.id ? [String(v.id)] : [],
				items: v.id ? [{ item_id: String(v.id), item_name: v.name, price: num(v.value), quantity: 1 }] : [],
				contents: v.id ? [{ content_id: String(v.id), content_name: v.name, price: num(v.value), quantity: 1 }] : []
			},
			beaconData: {
				currency: v.currency,
				value: num(v.value),
				products: v.id ? [{ id: String(v.id), name: v.name, price: num(v.value), quantity: 1 }] : []
			}
		});
	}

	// initiate_checkout on the checkout page — context in EVENTS.checkout.
	function trackInitiateCheckout() {
		var c = EVENTS.checkout;
		if (!c) {
			return;
		}
		var products = (c.products || []).map(function (p) {
			return { id: String(p.id), name: p.name, price: num(p.price), quantity: num(p.quantity) };
		});
		track('initiate_checkout', {
			clientData: {
				value: num(c.value),
				currency: c.currency,
				numItems: num(c.numItems),
				contentIds: products.map(function (p) { return p.id; }),
				items: products.map(function (p) { return { item_id: p.id, item_name: p.name, price: p.price, quantity: p.quantity }; }),
				contents: products.map(function (p) { return { content_id: p.id, content_name: p.name, price: p.price, quantity: p.quantity }; })
			},
			beaconData: {
				currency: c.currency,
				value: num(c.value),
				numItems: num(c.numItems),
				products: products
			}
		});
	}

	// add_payment_info — fired once the customer interacts with a payment method
	// on the checkout page (best-effort, classic checkout).
	var paymentInfoSent = false;
	function wireAddPaymentInfo() {
		var c = EVENTS.checkout;
		if (!c) {
			return;
		}
		function onPay() {
			if (paymentInfoSent) {
				return;
			}
			paymentInfoSent = true;
			track('add_payment_info', {
				clientData: { value: num(c.value), currency: c.currency },
				beaconData: { currency: c.currency, value: num(c.value) }
			});
		}
		// Classic checkout exposes payment method radios in this container.
		document.addEventListener('change', function (e) {
			if (e.target && e.target.name === 'payment_method') {
				onPay();
			}
		});
		// A shop with ONE payment method never fires the change above: the
		// radio is pre-selected (often hidden), so the shopper has nothing to
		// change and the step was missing from every such funnel. Placing the
		// order is the other moment the customer has provided payment
		// information, and it is the one GA4 documents for this event.
		// Submit AND click, because a themed button can place the order
		// without submitting the form.
		document.addEventListener('submit', function (e) {
			var f = e.target;
			if (f && f.matches && f.matches('form.checkout, form.woocommerce-checkout')) {
				onPay();
			}
		}, true);
		document.addEventListener('click', function (e) {
			var el = e.target && e.target.closest
				? e.target.closest('#place_order, button[name="woocommerce_checkout_place_order"], .wc-block-components-checkout-place-order-button')
				: null;
			if (el) {
				onPay();
			}
		}, true);
	}

	// purchase on the thank-you page — fully provided by PHP in EVENTS.purchase,
	// including the authoritative event_id ("order_<id>") so it matches the
	// server-side purchase event exactly.
	function trackPurchase() {
		var p = EVENTS.purchase;
		if (!p || !p.eventId) {
			return;
		}
		var products = (p.products || []).map(function (it) {
			return { id: String(it.id), name: it.name, price: num(it.price), quantity: num(it.quantity) };
		});
		track('purchase', {
			eventId: p.eventId, // "order_<id>" — pinned by PHP
			clientOnly: true,   // server side is delivered by the authoritative order hook, not the public beacon
			clientData: {
				value: num(p.value),
				currency: p.currency,
				numItems: num(p.numItems),
				orderId: p.orderId,
				contentIds: products.map(function (it) { return it.id; }),
				items: products.map(function (it) { return { item_id: it.id, item_name: it.name, price: it.price, quantity: it.quantity }; }),
				contents: products.map(function (it) { return { content_id: it.id, content_name: it.name, price: it.price, quantity: it.quantity }; })
			},
			beaconData: {
				currency: p.currency,
				value: num(p.value),
				numItems: num(p.numItems),
				orderId: p.orderId,
				products: products
			}
		});
	}

	// add_to_cart — wire the common WooCommerce signals:
	//   - AJAX add-to-cart (archive/shop loop) fires this jQuery event;
	//   - single-product form submit (no AJAX) we catch on submit.
	function wireAddToCart() {
		// ---- WooCommerce Blocks -------------------------------------------
		// Block themes add to the cart through the Store API and nothing else:
		// no jQuery event, no form submit, no link. All three handlers below
		// are blind to them, so a shop on a modern theme reported browsing and
		// purchases with an empty middle.
		//
		// We observe the documented Store API call rather than listen for a
		// block event, deliberately. The events blocks emit vary by version and
		// several do not carry the product at all; the REST contract does, and
		// it is stable: POST .../wc/store/v1/cart/add-item with {id, quantity}.
		// Watching the request means we read the same id the shop just used.
		//
		// The wrapper is observe-only and paranoid: it always calls through,
		// never inspects the response body, never throws, and reads only its
		// own copy of the request. Tracking sits on the storefront and on the
		// checkout — it may cost an event, never a sale.
		try {
			if (typeof window.fetch === 'function' && !window.__dfssFetchWrapped) {
				window.__dfssFetchWrapped = true;
				var nativeFetch = window.fetch;
				window.fetch = function (input, init) {
					var out = nativeFetch.apply(this, arguments);
					try {
						var url = typeof input === 'string' ? input : (input && input.url) || '';
						var method = ((init && init.method) || (input && input.method) || 'GET').toUpperCase();
						if (
							method === 'POST' &&
							url.indexOf('/wc/store/') !== -1 &&
							url.indexOf('/cart/add-item') !== -1
						) {
							var body = init && typeof init.body === 'string' ? init.body : '';
							var payload = null;
							try { payload = JSON.parse(body); } catch (e) {}
							if (payload && payload.id) {
								// Only once the server accepted it: a rejected
								// add (out of stock, invalid variation) is not
								// an add to cart.
								out.then(function (res) {
									try {
										if (res && res.ok) {
											fireAddToCart(String(payload.id), parseInt(payload.quantity, 10) || 1);
										}
									} catch (e) {}
								}, function () {});
							}
						}
					} catch (e) {}
					return out;
				};
			}
		} catch (e) {}

		// jQuery AJAX add-to-cart (WooCommerce core). Guard for jQuery presence.
		if (window.jQuery) {
			window.jQuery(document.body).on('added_to_cart', function (evt, fragments, cart_hash, $button) {
				var id = '';
				var qty = 1;
				var cardName = '';
				if ($button && $button.length) {
					id = $button.attr('data-product_id') || idFromAddToCartHref($button.attr('href')) || '';
					qty = parseInt($button.attr('data-quantity') || '1', 10) || 1;
					cardName = nameFromCard($button[0]);
				}
				fireAddToCart(id, qty, cardName);
			});
		}
		// Plain `?add-to-cart=` links — the shop loop of many themes, and the
		// fallback WooCommerce itself renders when AJAX add-to-cart is off.
		// Measured on a live shop: the theme fired the AJAX event but its
		// button carried no `data-product_id`, and a listing page has no
		// product context to fall back on, so the event went out naming
		// nothing. The id was in the href the whole time.
		//
		// Safe beside the AJAX handler above: fireAddToCart de-duplicates on
		// the id within a short window, so a theme that does both reports once.
		document.addEventListener('click', function (e) {
			var link = e.target && e.target.closest ? e.target.closest('a[href*="add-to-cart="]') : null;
			if (!link) {
				return;
			}
			var id = idFromAddToCartHref(link.getAttribute('href'));
			if (id) {
				fireAddToCart(id, parseInt(link.getAttribute('data-quantity') || '1', 10) || 1, nameFromCard(link));
			}
		}, true);

		// Non-AJAX single product add-to-cart form.
		document.addEventListener('submit', function (e) {
			var form = e.target;
			if (!form || !form.classList || !form.classList.contains('cart')) {
				return;
			}
			var qtyEl = form.querySelector('[name="quantity"]');
			var qty = qtyEl ? (parseInt(qtyEl.value, 10) || 1) : 1;
			// Four places, because no single one is reliable across themes.
			// The variation input wins when present: on a variable product
			// `add-to-cart` holds the PARENT id and `variation_id` the one that
			// was actually bought.
			var el = form.querySelector('[name="variation_id"]')
				|| form.querySelector('[name="add-to-cart"]')
				|| form.querySelector('[name="product_id"]')
				|| form.querySelector('button[name="add-to-cart"][value]');
			var id = el ? (el.value || el.getAttribute('value') || '') : '';
			// The submit itself is proof of an add-to-cart; the id is not always
			// in the form. Firing with none is still better than losing the
			// step, and fireAddToCart falls back to the page's own product.
			fireAddToCart(id, qty);
		});
	}

	// The product NAME out of the card the button sits in.
	//
	// Added because a product added from a LISTING arrived with an id and
	// nothing else, so the console showed a row headed "8005" — a number the
	// merchant has no way to recognise. The page has no product context there
	// (that only exists on a product page), so the name has to come from the
	// card itself.
	//
	// Theme-independent on purpose: this shop's theme uses none of
	// WooCommerce's standard loop markup, no `li.product`, no
	// `.woocommerce-loop-product__title`, not even an aria-label. What every
	// card DOES have is a link to the product page with the product name as
	// its text. So: walk up a few levels, take the longest link text that is
	// not the add-to-cart button itself. Wrong guesses cost a name, never an
	// event — an empty result simply sends no name, exactly as before.
	function nameFromCard(el) {
		try {
			var node = el;
			for (var up = 0; up < 5 && node; up++) {
				node = node.parentElement;
				if (!node) { break; }
				var links = node.querySelectorAll('a[href*="/product/"], a[href*="/produit/"]');
				var best = '';
				for (var i = 0; i < links.length; i++) {
					var txt = (links[i].textContent || '').trim();
					// Skip the button itself and image-only links.
					if (txt.length > best.length && links[i] !== el) { best = txt; }
				}
				if (best.length > 2) { return best.slice(0, 200); }
			}
		} catch (e) {}
		return '';
	}

	// The product id out of a WooCommerce add-to-cart URL, e.g.
	// /cart/?add-to-cart=8001 or /?add-to-cart=8001&quantity=2.
	function idFromAddToCartHref(href) {
		if (!href) {
			return '';
		}
		var m = String(href).match(/[?&]add-to-cart=(\d+)/);
		return m ? m[1] : '';
	}

	// Guard against the same add-to-cart being reported twice (some themes fire
	// both the AJAX `added_to_cart` event AND submit the form): ignore a repeat
	// for the same product id within a short window.
	var lastAddToCart = {};
	function fireAddToCart(id, qty, cardName) {
		id = id ? String(id) : '';
		qty = qty || 1;

		// ---- the product this is about -----------------------------------
		// Measured on datafirefly.com before this was written: 35 add-to-cart
		// events over 28 days, NONE of which said which product. On the same
		// day, with the same plugin, view_content carried its product line 25
		// times out of 34 and purchase once out of two — so it was never a
		// question of an outdated module. The id came from the button's
		// `data-product_id`, which the AJAX loop provides and a single-product
		// page does not, and the event was sent anyway with an empty list.
		//
		// The merchant then read "0" in the Added-to-cart column and
		// understood "nobody put it in their basket", when it meant "no
		// add-to-cart told us which product". Two different statements.
		//
		// The page's own product context is the fallback, and it is the
		// reliable one: PHP injects it for view_content, so on a product page
		// we always know what was added even when the button says nothing.
		var v = EVENTS.viewItem;
		if (!id && v && v.id) {
			id = String(v.id);
		}

		var now = Date.now();
		var dedupKey = id || '_';
		if (lastAddToCart[dedupKey] && now - lastAddToCart[dedupKey] < 1500) {
			return;
		}
		lastAddToCart[dedupKey] = now;

		// Name and price when the page is about THIS product — the same three
		// fields view_content sends. Without them the console can only show an
		// id, so a merchant reads a row of numbers instead of a product. Only
		// filled when the ids match: a loop add-to-cart is about a different
		// product from the one the page describes, and copying the page's name
		// onto it would label the row with the wrong article.
		var line = { id: id, quantity: qty };
		var name;
		var price;
		if (id && v && v.id && String(v.id) === id) {
			name = v.name;
			price = num(v.value);
		} else if (cardName) {
			// From a listing: the card gives the name, never a price — the
			// displayed price may be a range, a "from" price or struck
			// through, and a wrong amount is worse than none.
			name = cardName;
		}
		if (name) { line.name = name; }
		if (price !== undefined) { line.price = price; }

		track('add_to_cart', {
			clientData: {
				contentIds: id ? [id] : [],
				items: id ? [{ item_id: id, item_name: name, price: price, quantity: qty }] : [],
				contents: id ? [{ content_id: id, content_name: name, price: price, quantity: qty }] : []
			},
			beaconData: {
				products: id ? [line] : []
			}
		});
	}

	// ---- merchandising: lists, item clicks, promotions ----------------------
	//
	// GA4-native reporting for product lists and promotions. These four events
	// are BEACON-ONLY (not standard Meta/TikTok pixel events, so fireClient is a
	// no-op for them); the dispatcher maps them to GA4's recommended params
	// (item_list_id/name, promotion_id/name, creative_*). Two ways to feed them:
	//   1. A documented data-attribute convention (works on ANY theme) —
	//      container [data-df-item-list], items [data-df-item-id]; promo blocks
	//      [data-df-promotion-id]. This is the authoritative, opt-in path.
	//   2. Best-effort WooCommerce auto-detection of the standard product grid
	//      (li.product) — only when the convention is NOT used on the page, so
	//      the two never double-fire.

	var MAX_LIST_ITEMS = 50;

	function attr(el, name) {
		try { return el.getAttribute(name) || ''; } catch (e) { return ''; }
	}

	// Read one convention item element ([data-df-item-id]) into a product.
	function readConventionItem(el) {
		var id = attr(el, 'data-df-item-id');
		if (!id) { return null; }
		var p = { id: String(id) };
		var name = attr(el, 'data-df-item-name');
		if (name) { p.name = name; }
		var cat = attr(el, 'data-df-item-category');
		if (cat) { p.category = cat; }
		var price = num(attr(el, 'data-df-item-price'));
		if (price !== undefined) { p.price = price; }
		var qty = num(attr(el, 'data-df-item-quantity'));
		if (qty !== undefined) { p.quantity = qty; }
		return p;
	}

	// The nearest enclosing [data-df-item-list] (name + id), if any.
	function listContextFor(el) {
		var container = el && el.closest ? el.closest('[data-df-item-list]') : null;
		if (!container) { return {}; }
		var ctx = {};
		var name = attr(container, 'data-df-item-list');
		if (name) { ctx.listName = name; }
		var id = attr(container, 'data-df-item-list-id');
		if (id) { ctx.listId = id; }
		return ctx;
	}

	// view_item_list — one per convention list container on the page.
	function trackConventionLists() {
		var containers = document.querySelectorAll('[data-df-item-list]');
		for (var i = 0; i < containers.length; i++) {
			var container = containers[i];
			var itemEls = container.querySelectorAll('[data-df-item-id]');
			var products = [];
			for (var j = 0; j < itemEls.length && products.length < MAX_LIST_ITEMS; j++) {
				var p = readConventionItem(itemEls[j]);
				if (p) { products.push(p); }
			}
			if (!products.length) { continue; }
			var ctx = {};
			var name = attr(container, 'data-df-item-list');
			if (name) { ctx.listName = name; }
			var id = attr(container, 'data-df-item-list-id');
			if (id) { ctx.listId = id; }
			ctx.products = products;
			track('view_item_list', { beaconData: ctx });
		}
	}

	// select_item — click on any element inside a convention item.
	function wireConventionSelectItem() {
		document.addEventListener('click', function (e) {
			var el = e.target && e.target.closest ? e.target.closest('[data-df-item-id]') : null;
			if (!el) { return; }
			var p = readConventionItem(el);
			if (!p) { return; }
			var data = listContextFor(el);
			data.products = [p];
			track('select_item', { beaconData: data });
		}, true);
	}

	// WooCommerce fallback: the standard product grid. Covers BOTH the classic
	// loop (ul.products li.product — classic themes + [products] shortcode) AND
	// the block "Product Collection" (li.product inside
	// .wp-block-woocommerce-product-template — the default on block themes like
	// Twenty Twenty-*). Only used when the merchant did NOT tag a
	// [data-df-item-list] on the page.
	var WOO_GRID_ITEM = 'ul.products li.product, .wp-block-woocommerce-product-template li.product';

	function wireWooProductGrid() {
		if (document.querySelector('[data-df-item-list]')) {
			return; // convention in use — do not double-detect
		}
		var lis = document.querySelectorAll(WOO_GRID_ITEM);
		if (!lis.length) { return; }

		function wooItem(li) {
			var id = '';
			// 1) the classic/block add-to-cart button carries data-product_id.
			var btn = li.querySelector('a.add_to_cart_button[data-product_id], [data-product_id]');
			if (btn) { id = attr(btn, 'data-product_id'); }
			// 2) block Product Collection puts it in data-wp-context JSON.
			if (!id) {
				var ctx = attr(li, 'data-wp-context');
				var cm = ctx && ctx.match(/"productId":\s*(\d+)/);
				if (cm) { id = cm[1]; }
			}
			// 3) fall back to the WP post-<id> body class.
			if (!id) {
				var m = (li.className || '').match(/(?:^|\s)post-(\d+)(?:\s|$)/);
				if (m) { id = m[1]; }
			}
			if (!id) { return null; }
			var p = { id: String(id) };
			var titleEl = li.querySelector('.woocommerce-loop-product__title, .wc-block-components-product-name, .wp-block-post-title, h2, h3');
			if (titleEl && titleEl.textContent) { p.name = titleEl.textContent.trim().slice(0, 200); }
			return p;
		}

		function wooListName() {
			var h = document.querySelector('.woocommerce-products-header__title, h1.page-title, h1.entry-title, h1.wp-block-post-title');
			if (h && h.textContent) { return h.textContent.trim().slice(0, 200); }
			return (document.title || 'Product list').slice(0, 200);
		}

		var listName = wooListName();
		var products = [];
		for (var i = 0; i < lis.length && products.length < MAX_LIST_ITEMS; i++) {
			var p = wooItem(lis[i]);
			if (p) { products.push(p); }
		}
		if (products.length) {
			track('view_item_list', { beaconData: { listName: listName, products: products } });
		}

		// select_item on product-link clicks inside the grid (classic or block).
		document.addEventListener('click', function (e) {
			var li = e.target && e.target.closest ? e.target.closest(WOO_GRID_ITEM) : null;
			if (!li) { return; }
			// Ignore add-to-cart button clicks — those are add_to_cart, not select_item.
			if (e.target.closest && e.target.closest('.add_to_cart_button')) { return; }
			var p = wooItem(li);
			if (!p) { return; }
			track('select_item', { beaconData: { listName: listName, products: [p] } });
		}, true);
	}

	// Promotions — convention only ([data-df-promotion-id] or [data-df-promotion]).
	// view_promotion when the block first becomes visible; select_promotion on click.
	function promoData(el) {
		var id = attr(el, 'data-df-promotion-id') || attr(el, 'data-df-promotion');
		if (!id) { return null; }
		var d = { promotionId: String(id) };
		var name = attr(el, 'data-df-promotion-name');
		if (name) { d.promotionName = name; }
		var creative = attr(el, 'data-df-promotion-creative');
		if (creative) { d.creativeName = creative; }
		var slot = attr(el, 'data-df-promotion-slot');
		if (slot) { d.creativeSlot = slot; }
		return d;
	}

	function wirePromotions() {
		var els = document.querySelectorAll('[data-df-promotion-id], [data-df-promotion]');
		if (!els.length) { return; }

		// view_promotion once each block is ~half visible.
		if (typeof window.IntersectionObserver === 'function') {
			var seen = new WeakSet();
			var io = new IntersectionObserver(function (entries) {
				for (var i = 0; i < entries.length; i++) {
					var en = entries[i];
					if (en.isIntersecting && !seen.has(en.target)) {
						seen.add(en.target);
						io.unobserve(en.target);
						var d = promoData(en.target);
						if (d) { track('view_promotion', { beaconData: d }); }
					}
				}
			}, { threshold: 0.5 });
			for (var k = 0; k < els.length; k++) { io.observe(els[k]); }
		} else {
			// No IO: fire once on load (best effort).
			for (var m = 0; m < els.length; m++) {
				var d0 = promoData(els[m]);
				if (d0) { track('view_promotion', { beaconData: d0 }); }
			}
		}

		// select_promotion on click.
		document.addEventListener('click', function (e) {
			var el = e.target && e.target.closest ? e.target.closest('[data-df-promotion-id], [data-df-promotion]') : null;
			if (!el) { return; }
			var d = promoData(el);
			if (d) { track('select_promotion', { beaconData: d }); }
		}, true);
	}

	// ---- lead-gen: forms ----------------------------------------------------
	//
	// lead + complete_registration ARE standard Meta/TikTok events, so these fire
	// the client pixel AND beacon. Convention: any form tagged [data-df-lead] or
	// [data-df-register]. Plus WooCommerce auto: the My Account registration form.

	var leadFired = { lead: false, complete_registration: false };

	function fireLead(kind) {
		// Debounce: a form can submit twice (validation re-submit) — one per page.
		if (leadFired[kind]) { return; }
		leadFired[kind] = true;
		track(kind, { clientData: {}, beaconData: {} });
	}

	// Does this form look like a lead form rather than a search box, a login,
	// a comment or a filter? An email field is the discriminator: nothing else
	// on a page asks for one. Deliberately a heuristic and not a list of
	// selectors — requiring the site owner to tag every form with
	// [data-df-lead] means the events arrive only where somebody remembered,
	// which on dotsland.com was one form in a month.
	function looksLikeLeadForm(form) {
		try {
			if (form.matches('[data-df-lead]')) { return true; }
			// Excluded by role: these have email fields too, and none is a lead.
			if (form.matches('[role="search"], .search-form, .woocommerce-form-login, #commentform, [data-df-no-lead]')) { return false; }
			if (form.method && String(form.method).toLowerCase() === 'get') { return false; }
			return !!form.querySelector('input[type="email"], input[name*="email" i]');
		} catch (err) {
			return false;
		}
	}

	function wireLeadForms() {
		document.addEventListener('submit', function (e) {
			var form = e.target;
			if (!form || form.nodeName !== 'FORM') { return; }
			try {
				if (form.matches('[data-df-register]') || form.classList.contains('woocommerce-form-register') || form.classList.contains('register')) {
					fireLead('complete_registration');
					return;
				}
				// A form handled by one of the plugins below reports its own
				// SUCCESS a moment later; firing here as well would count the
				// same lead twice, and would also count the failures.
				if (form.matches('.wpcf7-form, .wpforms-form, .gform_wrapper form, .elementor-form, .nf-form-cont form')) { return; }
				if (looksLikeLeadForm(form)) {
					fireLead('lead');
				}
			} catch (err) {}
		}, true);

		// The form plugins that KNOW whether the message got through. Success is
		// the honest moment to report a lead: a form that failed validation, or
		// whose mail bounced at the server, did not produce a prospect.
		document.addEventListener('wpcf7mailsent', function () { fireLead('lead'); }, false); // Contact Form 7
		if (window.jQuery) {
			window.jQuery(document).on('wpformsAjaxSubmitSuccess', function () { fireLead('lead'); });
			window.jQuery(document).on('gform_confirmation_loaded', function () { fireLead('lead'); });
			window.jQuery(document).on('submit_success', function () { fireLead('lead'); });       // Elementor
			window.jQuery(document).on('nfFormSubmitResponse', function () { fireLead('lead'); }); // Ninja Forms
		}
	}


	// ---- lead-gen and engagement: the events an institutional site has ------
	//
	// A site that sells nothing still converts: it is contacted, subscribed to,
	// booked, shared and applied to. None of that used to leave a trace, so the
	// console showed a shop-shaped funnel of zeros next to an inbox that was
	// filling up. Each wiring below is generic on purpose — it must work on a
	// theme nobody here has ever seen — with a [data-df-*] attribute as the
	// explicit override when a site wants to be exact.

	function firstMatch(el, selector) {
		return el && el.closest ? el.closest(selector) : null;
	}

	// contact — a click on an address or a phone number. On an institutional
	// site this is frequently the ONLY conversion: no form, just the mailto in
	// the footer.
	function wireContactLinks() {
		document.addEventListener('click', function (e) {
			var a = firstMatch(e.target, 'a[href^="mailto:"], a[href^="tel:"], [data-df-contact]');
			if (!a) { return; }
			var href = String(a.getAttribute('href') || '');
			// The address itself is NOT sent: it is the shop's own, it would be
			// personal data on the page, and knowing WHICH mailbox was clicked
			// adds nothing to knowing that someone reached out.
			track('contact', {
				clientData: {},
				beaconData: { method: href.indexOf('tel:') === 0 ? 'phone' : 'email' }
			});
		}, true);
	}

	// schedule — a booking link. Calendly and its equivalents open in a new tab
	// or an overlay, so nothing else on our side would ever see it.
	function wireScheduleLinks() {
		var hosts = 'calendly.com|cal.com|savvycal.com|meetings.hubspot.com|app.acuityscheduling.com|zcal.co|tidycal.com|youcanbook.me';
		var re = new RegExp('(' + hosts + ')', 'i');
		document.addEventListener('click', function (e) {
			var a = firstMatch(e.target, 'a[href], [data-df-schedule]');
			if (!a) { return; }
			if (!a.matches('[data-df-schedule]') && !re.test(String(a.getAttribute('href') || ''))) { return; }
			track('schedule', { clientData: {}, beaconData: {} });
		}, true);
	}

	// share — a click on a social share link. Standard sharer URLs, because
	// every share button in existence ends up pointing at one of them.
	function wireShareLinks() {
		var re = /(facebook\.com\/sharer|twitter\.com\/intent|x\.com\/intent|linkedin\.com\/shar|pinterest\.[a-z.]+\/pin\/create|api\.whatsapp\.com\/send|t\.me\/share|reddit\.com\/submit)/i;
		document.addEventListener('click', function (e) {
			var a = firstMatch(e.target, 'a[href], [data-df-share]');
			if (!a) { return; }
			var href = String(a.getAttribute('href') || '');
			if (!a.matches('[data-df-share]') && !re.test(href)) { return; }
			var network = (href.match(/(facebook|twitter|x|linkedin|pinterest|whatsapp|telegram|reddit)/i) || [])[1];
			track('share', {
				clientData: {},
				beaconData: network ? { method: String(network).toLowerCase() } : {}
			});
		}, true);
	}

	// start_trial — the free-plan / trial CTA, in the languages this product is
	// actually sold in rather than English only.
	function wireTrialLinks() {
		var re = /(free-?trial|start-?free|\/trial|essai-?gratuit|\/gratuit|kostenlos|\/gratis|prova-?gratuita|signup-?free|zdarma|bezplatn)/i;
		document.addEventListener('click', function (e) {
			var a = firstMatch(e.target, 'a[href], [data-df-trial]');
			if (!a) { return; }
			if (!a.matches('[data-df-trial]') && !re.test(String(a.getAttribute('href') || ''))) { return; }
			track('start_trial', { clientData: {}, beaconData: {} });
		}, true);
		document.addEventListener('submit', function (e) {
			var f = e.target;
			if (!f || f.nodeName !== 'FORM') { return; }
			try {
				if (f.matches('[data-df-trial]') || re.test(String(f.getAttribute('action') || ''))) {
					track('start_trial', { clientData: {}, beaconData: {} });
				}
			} catch (err) {}
		}, true);
	}

	// subscribe — a newsletter form. The named ones are the four plugins that
	// cover most WordPress sites; the fallback is the shape of the thing: an
	// email field and nothing else to fill in, which is a newsletter box and
	// not a contact form.
	var NEWSLETTER_SEL = '.mc4wp-form, .sib-form, form.tnp-form, .mailpoet_form, [data-df-subscribe]';
	function looksLikeNewsletter(form) {
		try {
			if (form.matches(NEWSLETTER_SEL)) { return true; }
			if (!form.querySelector('input[type="email"], input[name*="email" i]')) { return false; }
			var filled = form.querySelectorAll('input:not([type="hidden"]):not([type="submit"]):not([type="button"]):not([type="checkbox"]), textarea, select');
			return filled.length === 1; // just the address
		} catch (err) {
			return false;
		}
	}
	var subscribeFired = false;
	function fireSubscribe() {
		if (subscribeFired) { return; }
		subscribeFired = true;
		track('subscribe', { clientData: {}, beaconData: {} });
	}
	function wireNewsletterForms() {
		document.addEventListener('submit', function (e) {
			var f = e.target;
			if (f && f.nodeName === 'FORM' && looksLikeNewsletter(f)) { fireSubscribe(); }
		}, true);
		// Mailchimp for WordPress reports its own success, which is the honest
		// moment: a rejected address never subscribed to anything.
		if (window.jQuery) {
			window.jQuery(document).on('mc4wp-subscribed', function () { fireSubscribe(); });
		}
	}

	// submit_application — a job or partner application: a form asking for a
	// file AND an address. A file upload alone is an avatar or a support
	// attachment, which is why both are required.
	function wireApplicationForms() {
		document.addEventListener('submit', function (e) {
			var f = e.target;
			if (!f || f.nodeName !== 'FORM') { return; }
			try {
				var explicit = f.matches('[data-df-application]');
				if (!explicit && !(f.querySelector('input[type="file"]') && f.querySelector('input[type="email"], input[name*="email" i]'))) { return; }
				track('submit_application', { clientData: {}, beaconData: {} });
			} catch (err) {}
		}, true);
	}

	// donate / find_location — no reliable generic shape exists for either, so
	// these are opt-in by attribute rather than guessed at. A charity tags its
	// donate button once; guessing would have produced a "donation" every time
	// somebody clicked a button whose label happened to match.
	function wireTaggedConversions() {
		[['[data-df-donate]', 'donate'], ['[data-df-find-location]', 'find_location']].forEach(function (pair) {
			document.addEventListener('click', function (e) {
				if (firstMatch(e.target, pair[0])) {
					track(pair[1], { clientData: {}, beaconData: {} });
				}
			}, true);
		});
		document.addEventListener('submit', function (e) {
			var f = e.target;
			if (!f || f.nodeName !== 'FORM') { return; }
			try {
				if (f.matches('[data-df-donate]')) { track('donate', { clientData: {}, beaconData: {} }); }
				else if (f.matches('[data-df-find-location]')) { track('find_location', { clientData: {}, beaconData: {} }); }
			} catch (err) {}
		}, true);
	}

	// search — the results page, with the term WordPress resolved rather than
	// whatever the address bar happens to hold.
	function trackSearch() {
		var q = EVENTS.search;
		if (!q || !q.searchString) { return; }
		track('search', {
			clientData: { searchString: q.searchString },
			beaconData: { searchString: q.searchString }
		});
	}

	// view_item_list — a listing page on a site with no shop: the blog index,
	// a category, a search result page.
	function trackContentList() {
		var l = EVENTS.contentList;
		if (!l || !l.products || !l.products.length) { return; }
		var products = l.products.map(function (p) {
			return { id: String(p.id), name: p.name, category: p.category };
		});
		track('view_item_list', {
			clientData: {
				items: products.map(function (p) { return { item_id: p.id, item_name: p.name, item_category: p.category }; })
			},
			beaconData: { listId: l.listId, listName: l.listName, products: products }
		});
	}

	// select_item — which entry of that listing was actually opened.
	function wireContentListClicks() {
		var l = EVENTS.contentList;
		if (!l || !l.products || !l.products.length) { return; }
		document.addEventListener('click', function (e) {
			var a = firstMatch(e.target, 'a[href]');
			if (!a) { return; }
			// Match the link to a listed entry by its title: the markup around
			// a card differs in every theme, the title does not.
			var text = (a.textContent || '').trim().toLowerCase();
			if (!text) { return; }
			for (var i = 0; i < l.products.length; i++) {
				var p = l.products[i];
				if (p.name && text.indexOf(String(p.name).trim().toLowerCase()) === 0) {
					track('select_item', {
						clientData: { items: [{ item_id: String(p.id), item_name: p.name }] },
						beaconData: { listId: l.listId, listName: l.listName, products: [{ id: String(p.id), name: p.name }] }
					});
					return;
				}
			}
		}, true);
	}

	// view_cart — the cart page. add_to_cart and initiate_checkout cannot tell
	// apart a visitor who assembled a basket and stopped from one who never
	// reached it; this can.
	function trackViewCart() {
		var c = EVENTS.cart;
		if (!c || !c.products) { return; }
		var products = c.products.map(function (p) {
			return { id: String(p.id), name: p.name, price: num(p.price), quantity: num(p.quantity) };
		});
		track('view_cart', {
			clientData: {
				value: num(c.value), currency: c.currency,
				items: products.map(function (p) { return { item_id: p.id, item_name: p.name, price: p.price, quantity: p.quantity }; })
			},
			beaconData: { currency: c.currency, value: num(c.value), numItems: num(c.numItems), products: products }
		});
	}

	// remove_from_cart / customize_product / add_shipping_info / add_to_wishlist
	// — the WooCommerce signals the connector did not listen for.
	function wireCartAndCheckoutExtras() {
		if (window.jQuery) {
			// Woo fires this on the cart page when a line is removed.
			window.jQuery(document.body).on('removed_from_cart', function () {
				track('remove_from_cart', { clientData: {}, beaconData: {} });
			});
			// A variation chosen on a product page: the visitor configured it.
			window.jQuery(document.body).on('found_variation', function (ev, variation) {
				var id = variation && (variation.variation_id || variation.id);
				track('customize_product', {
					clientData: id ? { contentIds: [String(id)] } : {},
					beaconData: id ? { products: [{ id: String(id), quantity: 1 }] } : {}
				});
			});
		}
		// Shipping method chosen at checkout — the step between the cart and
		// the payment, and the one where a shipping price loses the sale.
		var shippingSent = false;
		document.addEventListener('change', function (e) {
			var t = e.target;
			if (!t || !t.name) { return; }
			if (String(t.name).indexOf('shipping_method') !== 0) { return; }
			if (shippingSent) { return; }
			shippingSent = true;
			track('add_shipping_info', { clientData: {}, beaconData: {} });
		}, true);
		// The wishlist plugins that cover most Woo shops, plus the attribute.
		document.addEventListener('click', function (e) {
			var a = firstMatch(e.target, '.add_to_wishlist, .yith-wcwl-add-button a, .tinvwl_add_to_wishlist_button, [data-df-wishlist]');
			if (!a) { return; }
			track('add_to_wishlist', { clientData: {}, beaconData: {} });
		}, true);
	}

	// ---- boot ---------------------------------------------------------------

	function boot() {
		// Capture click ids regardless of consent? No — cookies that aid ad
		// matching are themselves consent-gated. Only after consent.
		whenConsent(captureClickIds);

		// Auto events for the current page (each is internally consent-gated).
		trackPageView();
		trackContentView();
		trackContentList();
		trackSearch();
		trackViewCart();
		trackViewItem();
		trackInitiateCheckout();
		trackPurchase();
		trackConventionLists();

		// Interaction wiring (the listeners themselves are cheap; the events
		// they fire are consent-gated inside track()).
		wireAddToCart();
		wireAddPaymentInfo();
		wireConventionSelectItem();
		wireWooProductGrid();
		wirePromotions();
		wireLeadForms();
		wireContentListClicks();
		wireContactLinks();
		wireScheduleLinks();
		wireShareLinks();
		wireTrialLinks();
		wireNewsletterForms();
		wireApplicationForms();
		wireTaggedConversions();
		wireCartAndCheckoutExtras();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
