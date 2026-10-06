/**
 * DataFirefly Server-Side: storefront tracker (core).
 *
 * For every event: one event id, a client tag fired with it, and a beacon carrying the same id
 * to /wp-json/dfss/v1/collect, which signs and forwards it server-side. The platforms deduplicate
 * on (event name, event id), so an ad blocker costs nothing and nothing counts twice.
 *
 * Nothing fires before marketing consent when it is required, except the cookieless Google tags
 * in Consent Mode advanced (DFSS-CONSENT-MODE block). Only public ids ever reach this file.
 * Platform tags other than Google are modules (dfss-dest-*.js), loaded only when enabled.
 */
(function () {
	'use strict';

	// Injected by PHP via wp_localize_script as window.DFSS_CFG.
	var CFG = window.DFSS_CFG || {};
	var PUBLIC = CFG.public || {};
	var CONSENT = CFG.consent || { required: true, cmp: '', hasWpConsentApi: false };
	var EVENTS = CFG.events || {}; // server-provided context for this page (e.g. purchase)
	var REST = CFG.restUrl || '';
	var NONCE = CFG.nonce || '';
	var NONCE_URL = CFG.nonceUrl || '';
	var COOKIE_DAYS = 90;

	// Google Consent Mode advanced, a merchant option (off by default). Logic lives in DFSS_CM.
	var CM_ON = false;

	var nonceRefreshed = false;
	function refreshNonce(done) {
		if (!NONCE_URL || typeof window.fetch !== 'function') {
			if (done) { done(); }
			return;
		}
		try {
			fetch(NONCE_URL, { credentials: 'same-origin', cache: 'no-store' })
				.then(function (r) { return r.json(); })
				.then(function (j) {
					if (j && typeof j.nonce === 'string' && j.nonce) {
						NONCE = j.nonce;
						nonceRefreshed = true;
					}
					if (done) { done(); }
				})
				.catch(function () { if (done) { done(); } });
		} catch (e) {
			if (done) { done(); }
		}
	}
	function armNonceRefresh() {
		var fired = false;
		function once() {
			if (fired) { return; }
			fired = true;
			refreshNonce();
		}
		['pointerdown', 'keydown', 'touchstart', 'scroll'].forEach(function (ev) {
			try {
				window.addEventListener(ev, once, { once: true, passive: true });
			} catch (e) {
				window.addEventListener(ev, once);
			}
		});
	}
	armNonceRefresh();

	// Guard: never run twice (e.g. if enqueued by a stray theme).
	if (window.__dfssTrackerLoaded) {
		return;
	}
	window.__dfssTrackerLoaded = true;

	// ---- tiny utils -----------------------------------------------------------

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

	// ---- click id capture (first-party, 90 days) ------------------------------

	// ---- DFSS_TEST_EXPORT_GADS_START (pure, tested by tests/test-google-web-tags.mjs)
	/** Page types Google recognizes, per event. */
	var GADS_PAGETYPE = {
		view_item: 'product',
		view_item_list: 'category',
		view_cart: 'cart',
		add_to_cart: 'cart',
		initiate_checkout: 'cart',
		purchase: 'purchase'
	};

	/** Google reads at most this many product ids per hit. */
	var GADS_MAX_PRODIDS = 100;

	/** Dynamic remarketing payload, or null when there is nothing to send. */
	function dfssRemarketingPayload(conversionId, name, data) {
		if (!conversionId || typeof conversionId !== 'string') {
			return null;
		}
		var payload = {
			send_to: conversionId,
			ecomm_pagetype: GADS_PAGETYPE[name] || 'other'
		};

		var items = (data && data.items) || null;
		if (Object.prototype.toString.call(items) === '[object Array]') {
			var ids = [];
			for (var i = 0; i < items.length && ids.length < GADS_MAX_PRODIDS; i++) {
				var it = items[i];
				var id = it && (it.id || it.item_id);
				if (typeof id === 'string' && id !== '') {
					ids.push(id);
				}
			}
			if (ids.length) {
				payload.ecomm_prodid = ids;
			}
		}

		var v = data && data.value;
		if (typeof v === 'number' && isFinite(v)) {
			payload.ecomm_totalvalue = v;
		}

		return payload;
	}

	/**
	 * Web-page conversion payload, or null when the event has no declared conversion label.
	 */
	function dfssWebConversionPayload(conversionId, labels, name, data) {
		if (!conversionId || typeof conversionId !== 'string') {
			return null;
		}
		var label = labels && labels[name];
		if (typeof label !== 'string' || label === '') {
			return null;
		}
		var payload = { send_to: conversionId + '/' + label };
		var d = data || {};
		if (typeof d.value === 'number' && isFinite(d.value)) {
			payload.value = d.value;
		}
		if (typeof d.currency === 'string' && d.currency !== '') {
			payload.currency = d.currency;
		}
		if (typeof d.orderId === 'string' && d.orderId !== '') {
			payload.transaction_id = d.orderId;
		}

		return payload;
	}
	// ---- DFSS_TEST_EXPORT_GADS_END

	// ---- hold until consent: a refusal is never held, only a missing answer --------

	// ---- DFSS_TEST_EXPORT_HOLD_START (pure, tested by tests/test-consent-hold.mjs)
	/**
	 * What to do with an event given consent state and age: 'send', 'hold' or 'discard'.
	 *
	 * @param {boolean|null} state   true granted, false refused, null no answer
	 * @param {number} ageMs         age of the event
	 * @param {number} holdMs        allowed hold duration (0 = disabled)
	 */
	function dfssHoldDecision(state, ageMs, holdMs) {
		if (state === true) {
			return 'send';
		}
		// An explicit refusal stops here, whatever the duration.
		if (state === false) {
			return 'discard';
		}
		if (typeof holdMs !== 'number' || !isFinite(holdMs) || holdMs <= 0) {
			return 'discard';
		}
		if (typeof ageMs !== 'number' || !isFinite(ageMs) || ageMs < 0) {
			return 'discard';
		}

		return ageMs <= holdMs ? 'hold' : 'discard';
	}
	// ---- DFSS_TEST_EXPORT_HOLD_END

	// ---- click id passthrough before consent (URL only, nothing stored) -----------

	// ---- DFSS_TEST_EXPORT_START (pure function, tested by tests/test-url-passthrough.mjs)
	/**
	 * The href with click ids appended, or null when the link must not be touched (external,
	 * non-navigable, already tagged).
	 */
	function dfssDecorateUrl(href, ids, origin) {
		if (!href || typeof href !== 'string') {
			return null;
		}
		if (/^(mailto:|tel:|javascript:|sms:|#)/i.test(href)) {
			return null;
		}

		var url;
		try {
			url = new URL(href, origin);
		} catch (e) {
			return null;
		}
		if (url.origin !== origin) {
			return null;
		}

		var carried = false;
		var keys = ['gclid', 'gbraid', 'wbraid'];
		for (var i = 0; i < keys.length; i++) {
			var k = keys[i];
			var v = ids && ids[k];
			if (!v || url.searchParams.has(k)) {
				continue;
			}
			url.searchParams.set(k, v);
			carried = true;
		}

		return carried ? url.toString() : null;
	}
	// ---- DFSS_TEST_EXPORT_END

	/**
	 * Click ids read from the current URL, kept in memory only: nothing is stored before consent.
	 */
	var pendingClickIds = { gclid: '', gbraid: '', wbraid: '' };

	function readPendingClickIds() {
		try {
			pendingClickIds = {
				gclid: getParam('gclid') || getCookie('_dfss_gclid') || '',
				gbraid: getParam('gbraid') || getCookie('_dfss_gbraid') || '',
				wbraid: getParam('wbraid') || getCookie('_dfss_wbraid') || ''
			};
		} catch (e) {}
	}

	/**
	 * Carry click ids on internal links at click time; one delegated listener survives DOM rewrites.
	 */
	function wireClickIdPassthrough() {
		if (CONSENT.clickIdPassthrough === false) {
			return;
		}
		try {
			document.addEventListener('click', function (ev) {
				try {
					if (ev.defaultPrevented || ev.button !== 0 || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) {
						return;
					}
					if (getCookie('_dfss_gclid') || getCookie('_dfss_gbraid') || getCookie('_dfss_wbraid')) {
						return;
					}
					var a = ev.target && ev.target.closest ? ev.target.closest('a[href]') : null;
					if (!a || a.hasAttribute('download') || (a.target && a.target !== '_self')) {
						return;
					}
					var next = dfssDecorateUrl(a.getAttribute('href'), pendingClickIds, window.location.origin);
					if (next) {
						a.setAttribute('href', next);
					}
				} catch (e) {}
			}, true);
		} catch (e) {}
	}

	function captureClickIds() {
		var fbclid = getParam('fbclid');
		if (fbclid) {
			// Only (re)write _dfss_fbc if we have a fresh fbclid.
			setCookie('_dfss_fbc', 'fb.1.' + Date.now() + '.' + fbclid, COOKIE_DAYS);
		}
		var gclid = getParam('gclid') || pendingClickIds.gclid;
		if (gclid) {
			setCookie('_dfss_gclid', gclid, COOKIE_DAYS);
		}
		// Google issues gbraid or wbraid INSTEAD of gclid when the journey crosses an app boundary or
		// cookies are restricted.
		var gbraid = getParam('gbraid') || pendingClickIds.gbraid;
		if (gbraid) {
			setCookie('_dfss_gbraid', gbraid, COOKIE_DAYS);
		}
		var wbraid = getParam('wbraid') || pendingClickIds.wbraid;
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
		// ChatGPT Ads attribution id.
		var oppref = getParam('oppref');
		if (oppref) {
			setCookie('_dfss_oppref', oppref, COOKIE_DAYS);
		}
	}

	// Read the browser identifiers we have available client-side.
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
		// GA4 session id, from the _ga_<container> cookie ("GS1.1.<sessionId>.<n>...").
		var mid = PUBLIC.ga4 && PUBLIC.ga4.measurementId;
		if (mid) {
			var gs = getCookie('_ga_' + String(mid).replace(/^G-/, ''));
			if (gs) {
				var m = gs.match(/^GS\d\.\d+\.s?(\d+)/);
				if (m) { u.sessionId = m[1]; }
			}
		}
		return u;
	}

	// ---- DFSS-CONSENT-CORE:BEGIN (genere — ne pas editer ici) --------------
	/**
	 * Marketing-consent detection, shared verbatim by the three storefront
	 * trackers (WooCommerce, PrestaShop, Shopware).
	 *
	 * WHY THIS FILE EXISTS
	 *
	 * Each tracker grew its own consent detection, and they drifted apart. The
	 * WooCommerce one read Cookiebot and IAB TCF; the PrestaShop one read
	 * tarteaucitron; the Shopware one read a single cookie name the merchant had
	 * to type in by hand. So the SAME consent tool answered on one platform and
	 * was invisible on another, and "invisible" here does not mean degraded — the
	 * resolver denies when it understands nothing, so the merchant's tracking was
	 * silently switched off with no error anywhere. A merchant cannot debug that:
	 * the banner works, the module says it is active, and no event arrives.
	 *
	 * This is now ONE implementation, generated into all three by
	 * scripts/sync-consent-core.py. Do not edit the copies — they are overwritten,
	 * and CI fails when they have drifted.
	 *
	 * CONTRACT
	 *
	 *   DFSS_CMP.granted(opts) -> true | false | null
	 *       true  : a tool we understand says marketing consent is granted
	 *       false : a tool we understand says it is refused
	 *       null  : no tool we understand answered — the caller falls back to its
	 *               own platform signal, and denies if that says nothing either.
	 *
	 *   DFSS_CMP.analytics(opts) -> true | false | null
	 *       Same three states, for audience measurement (analytics_storage).
	 *       Used ONLY by Google Consent Mode advanced; never gates a send.
	 *
	 *   DFSS_CMP.bind(onChange)
	 *       Subscribe to every "the visitor changed their mind" signal we know.
	 *
	 * The three-state return is the whole point. A boolean would force this file
	 * to invent an answer for a shop running a CMP it has never heard of, and the
	 * safe invention (deny) would override a platform signal that DID know.
	 */
	var DFSS_CMP = (function () {
		'use strict';

		function readCookie(name) {
			try {
				var m = document.cookie.match(
					new RegExp('(?:^|; )' + String(name).replace(/([.*+?^${}()|[\]\\])/g, '\\$1') + '=([^;]*)')
				);
				return m ? decodeURIComponent(m[1]) : '';
			} catch (e) {
				return '';
			}
		}

		// Each probe returns true/false when it recognises its tool, or null when
		// that tool is simply not on the page. Order is deliberate: our own banner
		// first (a shop running it has chosen it), then the IAB framework (which
		// answers for a whole family of CMPs at once), then the named tools.
		var PROBES = [
			// DataFirefly Cookie Consent — our own banner.
			function () {
				if (window.dfcc && typeof window.dfcc.hasConsent === 'function') {
					return !!window.dfcc.hasConsent('marketing');
				}
				return null;
			},

			// IAB TCF v2 / v2.2. Purposes 3 and 4 are "create a personalised ads
			// profile" and "select personalised ads" — the pair every advertising
			// destination needs. This single probe answers for Didomi, Sirdata,
			// Quantcast, consentmanager, CookieFirst and Usercentrics when they run
			// in TCF mode, which is how most of them run in the EU.
			function () {
				if (typeof window.__tcfapi !== 'function') {
					return null;
				}
				var out = null;
				try {
					// The CMP may answer asynchronously. We only accept the answer
					// if it lands synchronously; otherwise the listeners bound by
					// bind() will re-run this once the CMP is ready.
					window.__tcfapi('getTCData', 2, function (data, ok) {
						if (ok && data && data.purpose && data.purpose.consents) {
							out = !!(data.purpose.consents[3] && data.purpose.consents[4]);
						}
					});
				} catch (e) {}
				return out;
			},

			// Cookiebot.
			function () {
				if (window.Cookiebot && window.Cookiebot.consent) {
					return !!window.Cookiebot.consent.marketing;
				}
				return null;
			},

			// Didomi, outside TCF mode.
			function () {
				if (window.Didomi && typeof window.Didomi.getUserConsentStatusForPurpose === 'function') {
					var v = window.Didomi.getUserConsentStatusForPurpose('advertising_personalization');
					if (v === true || v === false) {
						return v;
					}
					v = window.Didomi.getUserConsentStatusForPurpose('cookies');
					if (v === true || v === false) {
						return v;
					}
				}
				return null;
			},

			// Usercentrics v2 (Cookiebot-owned since 2023, still its own SDK).
			function () {
				try {
					if (window.UC_UI && typeof window.UC_UI.getServicesBaseInfo === 'function') {
						var services = window.UC_UI.getServicesBaseInfo();
						if (services && services.length) {
							for (var i = 0; i < services.length; i++) {
								var s = services[i] || {};
								var cat = String(s.categorySlug || s.category || '').toLowerCase();
								if (cat.indexOf('marketing') !== -1 || cat.indexOf('advertis') !== -1) {
									if (s.consent && s.consent.status === true) {
										return true;
									}
								}
							}
							return false; // it answered, and no marketing service is on
						}
					}
				} catch (e) {}
				return null;
			},

			// CookieYes.
			function () {
				try {
					if (typeof window.getCkyConsent === 'function') {
						var c = window.getCkyConsent();
						if (c && c.categories) {
							return !!(c.categories.advertisement || c.categories.marketing);
						}
					}
				} catch (e) {}
				return null;
			},

			// Iubenda. Purpose 5 is "Measurement", 4 is "Targeting & Advertising".
			function () {
				try {
					if (window._iub && window._iub.cs && window._iub.cs.consent) {
						var p = window._iub.cs.consent.purposes;
						if (p) {
							return !!p[4];
						}
						if (typeof window._iub.cs.consent.consent === 'boolean') {
							return window._iub.cs.consent.consent;
						}
					}
				} catch (e) {}
				return null;
			},

			// OneTrust / CookiePro. C0004 is OneTrust's own id for "Targeting
			// Cookies" and is the default in every template they ship.
			function () {
				try {
					var groups = window.OnetrustActiveGroups || window.OptanonActiveGroups;
					if (typeof groups === 'string' && groups !== '') {
						return groups.indexOf('C0004') !== -1;
					}
				} catch (e) {}
				return null;
			},

			// Cookiehub.
			function () {
				try {
					if (window.cookiehub && typeof window.cookiehub.hasConsented === 'function') {
						return !!window.cookiehub.hasConsented('marketing');
					}
				} catch (e) {}
				return null;
			},

			// Osano.
			function () {
				try {
					if (window.Osano && window.Osano.cm && typeof window.Osano.cm.getConsent === 'function') {
						var c = window.Osano.cm.getConsent();
						if (c && typeof c.MARKETING === 'string') {
							return c.MARKETING === 'ACCEPT';
						}
					}
				} catch (e) {}
				return null;
			},

			// Borlabs Cookie v3, then v2.
			function () {
				try {
					var b = window.BorlabsCookie;
					if (b && b.Consents && typeof b.Consents.hasConsent === 'function') {
						return !!b.Consents.hasConsent('marketing');
					}
					if (b && typeof b.hasCookieGroupConsent === 'function') {
						return !!b.hasCookieGroupConsent('marketing');
					}
				} catch (e) {}
				return null;
			},

			// Klaro.
			function () {
				try {
					if (window.klaro && typeof window.klaro.getManager === 'function') {
						var consents = window.klaro.getManager().consents;
						if (consents && typeof consents === 'object') {
							var names = ['google-ads', 'googleAds', 'facebook', 'meta-pixel', 'marketing', 'advertising'];
							for (var i = 0; i < names.length; i++) {
								if (consents[names[i]] === true) {
									return true;
								}
							}
							return false;
						}
					}
				} catch (e) {}
				return null;
			},
		];

		// ---- mesure d'audience (analytics_storage) ------------------------------
		//
		// Un second signal, lu a part, qui ne sert QU'AU mode avance de Google
		// Consent Mode (analytics_storage). Il ne decide jamais d'aucun envoi : le
		// seul verdict qui ouvre Meta, TikTok, OpenAI ou le dispatcher reste
		// granted(). Un outil dont on ne connait pas la categorie mesure repond
		// null, donc refuse : GA4 recoit alors son ping sans cookies, rien de plus.
		var ANALYTICS_PROBES = [
			function () {
				if (window.dfcc && typeof window.dfcc.hasConsent === 'function') {
					return !!window.dfcc.hasConsent('analytics');
				}
				return null;
			},
			// TCF : 8 = mesurer la performance des contenus, 9 = comprendre les
			// audiences par des statistiques. Les deux, par prudence.
			function () {
				if (typeof window.__tcfapi !== 'function') {
					return null;
				}
				var out = null;
				try {
					window.__tcfapi('getTCData', 2, function (data, ok) {
						if (ok && data && data.purpose && data.purpose.consents) {
							out = !!(data.purpose.consents[8] && data.purpose.consents[9]);
						}
					});
				} catch (e) {}
				return out;
			},
			function () {
				if (window.Cookiebot && window.Cookiebot.consent) {
					return !!window.Cookiebot.consent.statistics;
				}
				return null;
			},
			function () {
				try {
					if (typeof window.getCkyConsent === 'function') {
						var c = window.getCkyConsent();
						if (c && c.categories) {
							return !!c.categories.analytics;
						}
					}
				} catch (e) {}
				return null;
			},
			// Iubenda : la finalite 5 est « Mesure ».
			function () {
				try {
					if (window._iub && window._iub.cs && window._iub.cs.consent && window._iub.cs.consent.purposes) {
						return !!window._iub.cs.consent.purposes[5];
					}
				} catch (e) {}
				return null;
			},
			// OneTrust : C0002 est « Performance Cookies » dans tous leurs modeles.
			function () {
				try {
					var groups = window.OnetrustActiveGroups || window.OptanonActiveGroups;
					if (typeof groups === 'string' && groups !== '') {
						return groups.indexOf('C0002') !== -1;
					}
				} catch (e) {}
				return null;
			},
			function () {
				try {
					if (window.cookiehub && typeof window.cookiehub.hasConsented === 'function') {
						return !!window.cookiehub.hasConsented('analytics');
					}
				} catch (e) {}
				return null;
			},
			function () {
				try {
					if (window.Osano && window.Osano.cm && typeof window.Osano.cm.getConsent === 'function') {
						var c = window.Osano.cm.getConsent();
						if (c && typeof c.ANALYTICS === 'string') {
							return c.ANALYTICS === 'ACCEPT';
						}
					}
				} catch (e) {}
				return null;
			},
			function () {
				try {
					var b = window.BorlabsCookie;
					if (b && b.Consents && typeof b.Consents.hasConsent === 'function') {
						return !!b.Consents.hasConsent('statistics');
					}
					if (b && typeof b.hasCookieGroupConsent === 'function') {
						return !!b.hasCookieGroupConsent('statistics');
					}
				} catch (e) {}
				return null;
			},
			function () {
				try {
					if (window.klaro && typeof window.klaro.getManager === 'function') {
						var consents = window.klaro.getManager().consents;
						if (consents && typeof consents === 'object') {
							var names = ['google-analytics', 'googleAnalytics', 'analytics', 'ga4'];
							for (var i = 0; i < names.length; i++) {
								if (consents[names[i]] === true) {
									return true;
								}
							}
							return false;
						}
					}
				} catch (e) {}
				return null;
			},
		];

		/**
		 * tarteaucitron, which is configuration-driven rather than category-driven:
		 * the merchant tells us which of its "services" count as advertising, so
		 * this probe needs opts and cannot live in the list above.
		 */
		function tarteaucitron(opts) {
			var jobs = (opts && opts.adJobs) || [];
			if (!jobs.length) {
				return null;
			}
			var i;
			// In-memory state is more current than the cookie mid-pageview.
			try {
				if (window.tarteaucitron && window.tarteaucitron.state) {
					for (i = 0; i < jobs.length; i++) {
						if (window.tarteaucitron.state[jobs[i]] === true) {
							return true;
						}
					}
				}
			} catch (e) {}
			var raw = readCookie((opts && opts.cookieName) || 'tarteaucitron');
			if (raw) {
				var choices = parseTarteaucitronCookie(raw);
				if (choices) {
					for (i = 0; i < jobs.length; i++) {
						if (choices[jobs[i]] === true) {
							return true;
						}
					}
					return false; // it answered: cookie present, no ad service granted
				}
			}
			return null;
		}

		/**
		 * tarteaucitron cookie -> { service: bool }. Two formats exist:
		 *   native tarteaucitron.js:      "!gtag=true!facebookpixel=false!youtube=wait"
		 *   legacy DataFirefly TAC 1.0.0: JSON object, possibly URL-encoded.
		 *
		 * Only the second was ever parsed here, with a bare JSON.parse. On a shop
		 * running stock tarteaucitron — the overwhelming majority — the parse threw,
		 * the probe returned null, and consent was never established: every
		 * conversion was dropped, silently, on a shop whose visitors had said yes.
		 *
		 * "wait" means the visitor has not answered for that service and is dropped
		 * rather than read as a refusal. Returns null when the cookie is unreadable,
		 * which the caller treats as "no answer" — never as a denial.
		 *
		 * Mirrors DfSsConsent::parseTarteaucitronCookie() in shared/consent-cookies.php.
		 */
		function parseTarteaucitronCookie(raw) {
			raw = String(raw || '');
			if (!raw) {
				return null;
			}
			var out = {};
			if (raw.charAt(0) === '!') {
				var re = /!([A-Za-z0-9_-]+)=(true|false|wait)/g;
				var m;
				while ((m = re.exec(raw)) !== null) {
					if (m[2] !== 'wait') {
						out[m[1]] = (m[2] === 'true');
					}
				}
				return out;
			}
			var json = null;
			try {
				json = JSON.parse(raw);
			} catch (e) {
				try {
					json = JSON.parse(decodeURIComponent(raw));
				} catch (e2) {}
			}
			if (!json || typeof json !== 'object') {
				return null;
			}
			Object.keys(json).forEach(function (k) {
				out[k] = (json[k] === true || json[k] === 'true');
			});
			return out;
		}

		/** tarteaucitron, cote mesure : memes regles que tarteaucitron(). */
		function tarteaucitronAnalytics(opts) {
			var jobs = (opts && opts.analyticsJobs) || ['gtag', 'analytics', 'gajs'];
			var i;
			try {
				if (window.tarteaucitron && window.tarteaucitron.state) {
					for (i = 0; i < jobs.length; i++) {
						if (window.tarteaucitron.state[jobs[i]] === true) {
							return true;
						}
					}
				}
			} catch (e) {}
			var raw = readCookie((opts && opts.cookieName) || 'tarteaucitron');
			if (raw) {
				var choices = parseTarteaucitronCookie(raw);
				if (choices) {
					for (i = 0; i < jobs.length; i++) {
						if (choices[jobs[i]] === true) {
							return true;
						}
					}
					return false;
				}
			}
			return null;
		}

		function analytics(opts) {
			for (var i = 0; i < ANALYTICS_PROBES.length; i++) {
				var v = null;
				try {
					v = ANALYTICS_PROBES[i]();
				} catch (e) {
					v = null;
				}
				if (v === true || v === false) {
					return v;
				}
			}
			return tarteaucitronAnalytics(opts || {});
		}

		function granted(opts) {
			for (var i = 0; i < PROBES.length; i++) {
				var v = null;
				try {
					v = PROBES[i]();
				} catch (e) {
					v = null;
				}
				if (v === true || v === false) {
					return v;
				}
			}
			return tarteaucitron(opts || {});
		}

		/**
		 * Every "the visitor changed their mind" signal we know, on both document
		 * and window because CMPs disagree about which one they fire on. Binding a
		 * listener for a tool that is not present costs nothing.
		 */
		function bind(onChange, opts) {
			var docEvents = [
				'dfcc_consent_change',          // our own banner
				'cmplz_status_change',          // Complianz
				'cmplz_enable_category',
				'wp_listen_for_consent_change', // WP Consent API
				'CookieConfiguration_Update',   // Shopware's built-in banner
				'cookiehub.changed',
				'klaro-consent-change',
				'tarteaucitron.load',
			];
			var winEvents = [
				'CookiebotOnAccept',
				'CookiebotOnConsentReady',
				'UC_UI_CMP_EVENT',
				'usercentrics_consent_changed',
				'borlabs-cookie-consent-saved',
			];
			var i;
			for (i = 0; i < docEvents.length; i++) {
				try {
					document.addEventListener(docEvents[i], onChange);
				} catch (e) {}
			}
			for (i = 0; i < winEvents.length; i++) {
				try {
					window.addEventListener(winEvents[i], onChange);
				} catch (e) {}
			}
			// tarteaucitron announces each service by name.
			var jobs = (opts && opts.adJobs) || [];
			for (i = 0; i < jobs.length; i++) {
				try {
					document.addEventListener(jobs[i] + '_added', onChange);
				} catch (e) {}
			}
			// Didomi and Osano use callback queues rather than DOM events.
			try {
				window.didomiEventListeners = window.didomiEventListeners || [];
				window.didomiEventListeners.push({ event: 'consent.changed', listener: onChange });
			} catch (e) {}
			try {
				if (window.Osano && window.Osano.cm && typeof window.Osano.cm.addEventListener === 'function') {
					window.Osano.cm.addEventListener('osano-cm-consent-saved', onChange);
				}
			} catch (e) {}
			// IAB TCF pushes its own updates.
			try {
				if (typeof window.__tcfapi === 'function') {
					window.__tcfapi('addEventListener', 2, function (data, ok) {
						if (ok && data && (data.eventStatus === 'useractioncomplete' || data.eventStatus === 'tcloaded')) {
							onChange();
						}
					});
				}
			} catch (e) {}
		}

		return { granted: granted, analytics: analytics, bind: bind };
	})();
	// ---- DFSS-CONSENT-CORE:END ---------------------------------------------
	// ---- DFSS-CONSENT-MODE:BEGIN (genere — ne pas editer ici) --------------
	/**
	 * Google Consent Mode, variante AVANCEE, partagee mot pour mot par les trois
	 * traqueurs (WooCommerce, PrestaShop, Shopware).
	 *
	 * Mode avance : les balises Google (GA4, Google Ads) se chargent des
	 * l'arrivee du visiteur, en refus par defaut, et envoient des pings sans
	 * cookies tant qu'il n'a pas accepte. Google s'en sert pour modeliser ce
	 * qu'il ne voit pas. RIEN D'AUTRE ne change : Meta, TikTok, OpenAI et le
	 * dispatcher restent derriere le consentement marketing.
	 *
	 * Option du marchand, eteinte par defaut. C'est une decision de conformite :
	 * un ping sans cookies contient l'heure, le user-agent, la page d'origine et
	 * l'etat du consentement. Spec : SPEC-MODE-AVANCE-CONSENTEMENT-2026-09-29.
	 *
	 * La regle qui empeche le double comptage GA4 vit ici et nulle part ailleurs :
	 *   navigation  -> la balise GA4 tant que le visiteur n'a PAS accepte (pings
	 *                  sans cookies), et l'envoi au dispatcher, s'il part plus tard,
	 *                  porte browser_sent ; des qu'il a accepte, le serveur seul,
	 *                  comme en mode de base (resistant aux bloqueurs) ;
	 *   achat       -> la balise GA4 SEULEMENT si la commande a ete refusee
	 *                  (le serveur n'envoie rien dans ce cas), sinon le serveur seul.
	 *
	 * Genere dans les trois traqueurs par scripts/sync-consent-core.py.
	 * Ne pas editer les copies.
	 */
	var DFSS_CM = (function () {
		'use strict';

		// EEE (UE 27 + Islande, Liechtenstein, Norvege), Royaume-Uni, Suisse.
		var EEA_UK_CH = [
			'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR',
			'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK',
			'SI', 'ES', 'SE', 'IS', 'LI', 'NO', 'GB', 'CH'
		];

		/** Actif seulement si le marchand l'a choisi ET qu'une balise Google existe. */
		function isAdvanced(consent, pub) {
			if (!consent || consent.googleMode !== 'advanced') {
				return false;
			}
			var hasGa4 = !!(pub && pub.ga4 && pub.ga4.measurementId);
			var hasAds = !!(pub && pub.google && pub.google.conversionId);

			return hasGa4 || hasAds;
		}

		function defaultsCommand(consent) {
			var o = {
				ad_storage: 'denied',
				ad_user_data: 'denied',
				ad_personalization: 'denied',
				analytics_storage: 'denied',
				wait_for_update: 500
			};
			if (consent && consent.consentRegion === 'eea') {
				o.region = EEA_UK_CH.slice();
			}

			return o;
		}

		/** On ne dit a Google que ce qu'on sait. Un signal null n'est pas envoye. */
		function updateCommand(ads, analytics) {
			var o = {};
			if (ads === true || ads === false) {
				var a = ads ? 'granted' : 'denied';
				o.ad_storage = a;
				o.ad_user_data = a;
				o.ad_personalization = a;
			}
			if (analytics === true || analytics === false) {
				o.analytics_storage = analytics ? 'granted' : 'denied';
			}

			return o;
		}

		/**
		 * Un outil de consentement a-t-il deja pose son defaut ? Beaucoup le font
		 * (Cookiebot, Complianz, df-cookie-consent...). Deux sources d'ordres
		 * peuvent se contredire : si l'outil parle deja a Google, on le laisse
		 * parler seul. Verifie sur banc avant ecriture (spec V1).
		 */
		function hasConsentDefault(dl, gtd) {
			// Un outil pose dans GTM passe par l'API de consentement de GTM, qui
			// n'ecrit pas dans le dataLayer. google_tag_data.ics.usedDefault le
			// dit : false sans defaut, true apres (mesure sur banc le 29/09).
			// Objet interne de Google : s'il disparait, on retombe sur le dataLayer.
			try {
				if (gtd && gtd.ics && gtd.ics.usedDefault === true) {
					return true;
				}
			} catch (x) {}
			if (!dl || typeof dl.length !== 'number') {
				return false;
			}
			for (var i = 0; i < dl.length; i++) {
				try {
					var e = dl[i];
					if (e && e[0] === 'consent' && e[1] === 'default') {
						return true;
					}
				} catch (x) {}
			}

			return false;
		}

		function ga4Params(data) {
			var d = data || {};
			var p = {};
			if (typeof d.value === 'number' && isFinite(d.value)) {
				p.value = d.value;
			}
			if (typeof d.currency === 'string' && d.currency !== '') {
				p.currency = d.currency;
			}
			if (typeof d.orderId === 'string' && d.orderId !== '') {
				p.transaction_id = d.orderId;
			}
			if (d.items && typeof d.items.length === 'number' && d.items.length) {
				p.items = Array.prototype.slice.call(d.items, 0, 200);
			}

			return p;
		}

		/**
		 * Le verdict est celui ENREGISTRE SUR LA COMMANDE, ecrit dans la page par le
		 * plugin. Le serveur envoie l'achat pour tout sauf 'denied'. Un verdict
		 * absent (ancienne commande, plugin partiel) laisse l'achat au serveur :
		 * au pire on perd une modelisation, jamais on ne compte double.
		 */
		function ga4FromBrowserForPurchase(verdict) {
			return verdict === 'denied';
		}

		function boot(env) {
			var w = env.win;
			w.dataLayer = w.dataLayer || [];
			if (typeof w.gtag !== 'function') {
				w.gtag = function () { w.dataLayer.push(arguments); };
			}
			var owned = !hasConsentDefault(w.dataLayer, w.google_tag_data);
			if (owned) {
				w.gtag('consent', 'default', defaultsCommand(env.consent));
			}
			w.gtag('set', 'ads_data_redaction', !(env.consent && env.consent.adsDataRedaction === false));
			try { env.injectGa4(); } catch (e) {}
			try { env.injectGoogleAds(); } catch (e) {}
			if (owned) {
				var push = function () {
					var u = updateCommand(env.adsState(), env.analyticsState());
					for (var k in u) {
						if (Object.prototype.hasOwnProperty.call(u, k)) {
							w.gtag('consent', 'update', u);
							return;
						}
					}
				};
				push();
				env.bind(push);
			}

			return owned;
		}

		function fire(env, name, eventId, data, verdict) {
			var sent = [];
			var w = env.win;
			var ga4Id = env.pub && env.pub.ga4 && env.pub.ga4.measurementId;
			var ga4Name = env.ga4Map && env.ga4Map[name];
			var toGa4 = !!(ga4Id && ga4Name && typeof w.gtag === 'function');
			if (name === 'purchase' && !ga4FromBrowserForPurchase(verdict)) {
				toGa4 = false;
			}
			// Un visiteur qui a deja accepte est mesure par le serveur, comme en
			// mode de base. Un bloqueur de publicite coupe gtag.js mais pas l'envoi
			// a la boutique : si la balise marquait cet evenement comme envoye, le
			// dispatcher sauterait GA4 et l'evenement n'arriverait par aucun des
			// deux chemins (relecture du 29/09). La balise ne porte que les pings
			// sans cookies de ceux qui n'ont pas (encore) accepte ; l'achat, lui,
			// suit le verdict de la commande.
			if (name !== 'purchase' && env.adsState() === true) {
				toGa4 = false;
			}
			if (toGa4) {
				var params = ga4Params(data);
				params.send_to = String(ga4Id);
				try {
					w.gtag('event', ga4Name, params); // dfss:ga4-advanced
					sent.push('ga4');
				} catch (e) {}
			}
			try { env.fireGoogleAds(name, data || {}); } catch (e) {}

			return sent;
		}

		return {
			isAdvanced: isAdvanced,
			defaultsCommand: defaultsCommand,
			updateCommand: updateCommand,
			hasConsentDefault: hasConsentDefault,
			ga4Params: ga4Params,
			ga4FromBrowserForPurchase: ga4FromBrowserForPurchase,
			boot: boot,
			fire: fire
		};
	})();
	// ---- DFSS-CONSENT-MODE:END ---------------------------------------------

	// ---- consent ------------------------------------------------------------

	// Resolve current marketing-consent state across the supported stacks.
	// Returns true/false; when required and indeterminate, returns false (deny).
	/**
	 * Raw marketing consent state: true granted, false refused, null no readable answer.
	 */
	function marketingConsentState() {
		if (!CONSENT.required) {
			return true;
		}
		try {
			return DFSS_CMP.granted(CONSENT);
		} catch (e) {}

		return null;
	}

	function hasMarketingConsent() {
		if (!CONSENT.required) {
			return true;
		}

		// DataFirefly Cookie Consent first: authoritative when present.
		if (window.dfcc && typeof window.dfcc.hasConsent === 'function') {
			try {
				return !!window.dfcc.hasConsent('marketing');
			} catch (e) {}
		}

		// Then the WordPress Consent API and Complianz, ahead of the shared detection.
		if (typeof window.wp_has_consent === 'function') {
			try {
				return !!window.wp_has_consent('marketing');
			} catch (e) {}
		}
		if (CONSENT.cmp === 'complianz' && window.cmplz && typeof window.cmplz.has_consent === 'function') {
			try {
				return !!window.cmplz.has_consent('marketing');
			} catch (e) {}
		}

		// Then every other CMP, through the detection shared with PrestaShop and Shopware.
		var shared = DFSS_CMP.granted(CONSENT);
		if (shared !== null) {
			return shared;
		}

		// Required but no signal we understand -> deny (privacy-first).
		return false;
	}

	/**
	 * Three-state ads verdict for Google Consent Mode, including the WordPress-only signals.
	 */
	function adsConsentState() {
		var s = marketingConsentState();
		if (s === null && hasMarketingConsent()) {
			s = true;
		}

		return s;
	}

	/** Three-state analytics verdict, used for analytics_storage only. */
	function analyticsConsentState() {
		if (!CONSENT.required) {
			return true;
		}
		if (window.dfcc && typeof window.dfcc.hasConsent === 'function') {
			try {
				return !!window.dfcc.hasConsent('analytics');
			} catch (e) {}
		}
		if (typeof window.wp_has_consent === 'function') {
			try {
				return !!window.wp_has_consent('statistics');
			} catch (e) {}
		}
		if (CONSENT.cmp === 'complianz' && window.cmplz && typeof window.cmplz.has_consent === 'function') {
			try {
				return !!window.cmplz.has_consent('statistics');
			} catch (e) {}
		}
		try {
			return DFSS_CMP.analytics(CONSENT);
		} catch (e) {}

		return null;
	}

	function cmEnv() {
		return {
			win: window,
			consent: CONSENT,
			pub: PUBLIC,
			ga4Map: GA4_MAP,
			injectGa4: injectGa4,
			injectGoogleAds: injectGoogleAds,
			fireGoogleAds: fireGoogleAds,
			adsState: adsConsentState,
			analyticsState: analyticsConsentState,
			bind: function (cb) { DFSS_CMP.bind(cb, CONSENT); }
		};
	}

	// Run `fn` once consent is granted.
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
		if (marketingConsentState() === false) {
			heldClear();

			return;
		}
		if (!hasMarketingConsent()) {
			return;
		}
		heldFlush();
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
		DFSS_CMP.bind(flushPending, CONSENT);
	}

	// ---- browser tags (public ids only) ------------------------------------------

	// Google tags live here because the shared Consent Mode block calls them. Every other platform
	// is a module (dfss-dest-*.js) registered in window.DFSS_DEST, enqueued only when enabled.
	var injected = { ga4: false, gads: false };
	var HELPERS = { loadScript: loadScript };

	function eachDest(fn) {
		var reg = window.DFSS_DEST || {};
		for (var key in reg) {
			if (Object.prototype.hasOwnProperty.call(reg, key) && PUBLIC[key] && reg[key]) {
				try { fn(reg[key], PUBLIC[key]); } catch (e) {}
			}
		}
	}

	// The gtag tag is only here for the _ga cookie, which lets the server event join the session.
	// It sends nothing itself: GA4 does not deduplicate gtag against Measurement Protocol.
	function injectGa4() {
		if (injected.ga4 || !PUBLIC.ga4 || !PUBLIC.ga4.measurementId) {
			return injected.ga4;
		}
		var id = String(PUBLIC.ga4.measurementId);
		loadScript('https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(id));
		window.dataLayer = window.dataLayer || [];
		window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
		window.gtag('js', new Date());
		window.gtag('config', id, { send_page_view: false });
		injected.ga4 = true;
		return true;
	}

	/** Google Ads tag, only for dynamic remarketing and web-page conversions (purchases go server-side). */
	function injectGoogleAds() {
		var cfg = PUBLIC.google;
		if (injected.gads || !cfg || !cfg.conversionId) {
			return injected.gads;
		}
		var id = String(cfg.conversionId);
		loadScript('https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(id));
		window.dataLayer = window.dataLayer || [];
		window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
		window.gtag('js', new Date());
		window.gtag('config', id);
		injected.gads = true;
		return true;
	}

	function injectAll() {
		eachDest(function (dest, cfg) { dest.inject(cfg, HELPERS); });
		injectGa4();
		injectGoogleAds();
	}

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

	/** Google Ads remarketing and web-page conversions, the only Google hits sent from the browser. */
	function fireGoogleAds(name, clientData) {
		var cfg = PUBLIC.google;
		if (!cfg || !cfg.conversionId || !injectGoogleAds() || typeof window.gtag !== 'function') {
			return;
		}
		try {
			if (cfg.remarketing) {
				var rm = dfssRemarketingPayload(cfg.conversionId, name, clientData || {});
				if (rm) {
					window.gtag('event', 'page_view', rm);
				}
			}
			var cv = dfssWebConversionPayload(cfg.conversionId, cfg.webConversions, name, clientData || {});
			if (cv) {
				window.gtag('event', 'conversion', cv);
			}
		} catch (e) {}
	}

	// Every client tag fires with the shared event id, so the platform deduplicates with the server.
	function fireClient(name, eventId, clientData, googleDone) {
		eachDest(function (dest) { dest.fire(name, eventId, clientData); });
		// In Consent Mode advanced, Google already fired from track().
		if (!googleDone) {
			fireGoogleAds(name, clientData);
		}
	}

	// ---- consent hold (kept in the visitor's own browser) ---------------------

	var HOLD_KEY = '_dfss_held';
	var HOLD_MAX = 20;

	/** Hold duration in milliseconds; 0 disables it (default). */
	function holdMs() {
		var m = CONSENT.holdMinutes;

		return (typeof m === 'number' && isFinite(m) && m > 0) ? m * 60000 : 0;
	}

	function heldRead() {
		try {
			var raw = window.sessionStorage.getItem(HOLD_KEY);

			return raw ? (JSON.parse(raw) || []) : [];
		} catch (e) {
			return [];
		}
	}

	function heldWrite(list) {
		try {
			window.sessionStorage.setItem(HOLD_KEY, JSON.stringify(list.slice(-HOLD_MAX)));
		} catch (e) {}
	}

	function heldClear() {
		try { window.sessionStorage.removeItem(HOLD_KEY); } catch (e) {}
	}

	function heldPush(name, eventId, beaconData, sentByBrowser) {
		var list = heldRead();
		list.push({ n: name, i: eventId, d: beaconData || {}, t: Date.now(), b: sentByBrowser || [] });
		heldWrite(list);
	}

	function heldFlush() {
		var state = marketingConsentState();
		var list = heldRead();
		heldClear();
		if (state !== true || !list.length) {
			return;
		}
		var limit = holdMs();
		for (var i = 0; i < list.length; i++) {
			var row = list[i];
			if (dfssHoldDecision(null, Date.now() - row.t, limit) === 'hold') {
				try { beacon(row.n, row.i, row.d, row.b); } catch (e) {}
			}
		}
	}

	// ---- beacon to our server ---------------------------------------------------

	// Only what the endpoint accepts is beaconed: anything else would boot WordPress for a refusal.
	// The client tags still fire for every event. Old cached configs without the list send all.
	var BEACON_EVENTS = CFG.beaconEvents || null;
	var NONCE_EVENTS = CFG.nonceEvents || ['lead', 'complete_registration', 'add_payment_info'];
	// Events that may leave the page: sent on sendBeacon, which survives unload.
	var NAVIGATING = ['purchase', 'select_item', 'select_promotion', 'lead', 'complete_registration',
		'contact', 'schedule', 'share', 'start_trial', 'subscribe', 'submit_application', 'donate',
		'find_location', 'add_shipping_info', 'add_to_wishlist'];
	var BATCH_MAX = 10;
	var BATCH_DELAY = 30;
	var batch = [];
	var batchTimer = null;
	var beaconed = {};

	function canBeacon(name) {
		return !!REST && (!BEACON_EVENTS || BEACON_EVENTS.indexOf(name) !== -1);
	}

	function sendBeaconNow(payload) {
		if (!navigator.sendBeacon) {
			return false;
		}
		try {
			var url = REST + (REST.indexOf('?') === -1 ? '?' : '&') + '_wpnonce=' + encodeURIComponent(NONCE);
			return navigator.sendBeacon(url, new Blob([payload], { type: 'application/json' }));
		} catch (e) {
			return false;
		}
	}

	// Page-load events go out together: one request, one WordPress boot, instead of one per event.
	function flushBatch() {
		if (batchTimer) {
			clearTimeout(batchTimer);
			batchTimer = null;
		}
		if (!batch.length) {
			return;
		}
		var items = batch.splice(0, batch.length);
		var payload = JSON.stringify(items.length === 1 ? items[0] : { events: items });
		if (document.visibilityState === 'hidden' && sendBeaconNow(payload)) {
			return;
		}
		sendWithFetch(payload, false);
	}

	try {
		window.addEventListener('pagehide', flushBatch);
		document.addEventListener('visibilitychange', function () {
			if (document.visibilityState === 'hidden') {
				flushBatch();
			}
		});
	} catch (e) {}

	function beacon(name, eventId, beaconData, sentByBrowser) {
		// The same event can reach here from the consent queue and the hold queue.
		if (!canBeacon(name) || beaconed[eventId]) {
			return;
		}
		beaconed[eventId] = true;

		var body = {
			event_name: name,
			event_id: eventId,
			source_url: location.href,
			user_data: collectUserData(),
			event_data: beaconData || {}
		};
		// External referrer: lets GA4 derive source/medium when gtag is blocked.
		var ref = document.referrer;
		if (ref && /^https?:\/\//i.test(ref)) {
			body.page_referrer = ref;
		}
		// Consent Mode advanced: the GA4 tag already sent it, the dispatcher must not resend it.
		if (sentByBrowser && sentByBrowser.length) {
			body.browser_sent = sentByBrowser.slice();
		}

		var navigating = NAVIGATING.indexOf(name) !== -1;
		if (navigating || document.visibilityState === 'hidden') {
			flushBatch();
			if (sendBeaconNow(JSON.stringify(body))) {
				return;
			}
			sendWithFetch(JSON.stringify(body), false);
			return;
		}
		// Nonce-gated events go alone, so a stale-nonce retry never resends a whole batch.
		if (NONCE_EVENTS.indexOf(name) !== -1) {
			sendWithFetch(JSON.stringify(body), false);
			return;
		}
		batch.push(body);
		if (batch.length >= BATCH_MAX) {
			flushBatch();
		} else if (!batchTimer) {
			batchTimer = setTimeout(flushBatch, BATCH_DELAY);
		}
	}

	// fetch with the nonce header. A "nonce" refusal on a cached page is retried once with a fresh one.
	function sendWithFetch(payload, retried) {
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
			}).then(function (r) {
				if (retried || !r || !r.ok) { return; }
				return r.json().then(function (j) {
					if (j && j.ok === false && j.reason === 'nonce') {
						refreshNonce(function () { sendWithFetch(payload, true); });
					}
				}).catch(function () {});
			}).catch(function () {});
		} catch (e) {}
	}

	// ---- track(): one event id, shared by the client tag and the server event ------

	// opts.eventId pins the id (purchase: "order_<id>"); opts.clientData shapes the client tags,
	// opts.beaconData the server event; opts.clientOnly skips the beacon (purchase is sent by the
	// order hook, which cannot be spoofed).
	function track(name, opts) {
		opts = opts || {};
		var eventId = opts.eventId || uuidv4();
		var clientData = opts.clientData || opts.beaconData || {};

		// Consent Mode advanced: Google fires now, in the current consent state (cookieless until
		// the visitor accepts).
		var sentByBrowser = CM_ON ? DFSS_CM.fire(cmEnv(), name, eventId, clientData, opts.consentVerdict) : [];

		// No answer to the banner yet: hold the event in the visitor's browser instead of losing it.
		if (!opts.clientOnly && canBeacon(name) && dfssHoldDecision(marketingConsentState(), 0, holdMs()) === 'hold') {
			heldPush(name, eventId, opts.beaconData || {}, sentByBrowser);
		}

		whenConsent(function () {
			injectAll(); // injects once
			fireClient(name, eventId, clientData, CM_ON);
			if (!opts.clientOnly) {
				beacon(name, eventId, opts.beaconData || {}, sentByBrowser);
			}
		});
	}

	// Public API, used by themes and by the optional modules (dfss-engagement.js).
	window.dfssTrack = track;

	// ---- full-funnel auto-wiring ----------------------------------------------

	function num(v) {
		var n = parseFloat(v);
		return isFinite(n) ? n : undefined;
	}

	// page_view on every page.
	function trackPageView() {
		track('page_view', {});
	}

	// view_content on a content page (article, service page...).
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

	// view_item on a product page, context provided by PHP in EVENTS.viewItem.
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

	// initiate_checkout on the checkout page, context in EVENTS.checkout.
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

	// add_payment_info, fired once the customer interacts with a payment method on the checkout page
	// (best-effort, classic checkout).
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
			var apiProducts = (c.products || []).map(function (p) {
				return { id: String(p.id), name: p.name, price: num(p.price), quantity: num(p.quantity) };
			});
			track('add_payment_info', {
				clientData: {
					value: num(c.value),
					currency: c.currency,
					numItems: num(c.numItems),
					contentIds: apiProducts.map(function (p) { return p.id; }),
					items: apiProducts.map(function (p) { return { item_id: p.id, item_name: p.name, price: p.price, quantity: p.quantity }; }),
					contents: apiProducts.map(function (p) { return { content_id: p.id, content_name: p.name, price: p.price, quantity: p.quantity }; })
				},
				beaconData: {
					currency: c.currency,
					value: num(c.value),
					numItems: num(c.numItems),
					products: apiProducts
				}
			});
		}
		// Classic checkout exposes payment method radios in this container.
		document.addEventListener('change', function (e) {
			if (e.target && e.target.name === 'payment_method') {
				onPay();
			}
		});
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
			clientOnly: true,
			consentVerdict: p.consent, // verdict enregistre sur la commande (mode avance)
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

	function wireAddToCart() {
		// WooCommerce Blocks add through the Store API only: observe POST .../cart/add-item. The
		// wrapper always calls through and never throws.
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
								// Only once the server accepted it: a rejected add (out of stock, invalid variation) is not an
								// add to cart.
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

		// jQuery AJAX add-to-cart (WooCommerce core).
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
			var el = form.querySelector('[name="variation_id"]')
				|| form.querySelector('[name="add-to-cart"]')
				|| form.querySelector('[name="product_id"]')
				|| form.querySelector('button[name="add-to-cart"][value]');
			var id = el ? (el.value || el.getAttribute('value') || '') : '';
			// The submit itself is proof of an add-to-cart; the id is not always in the form.
			fireAddToCart(id, qty);
		});
	}

	// The product NAME out of the card the button sits in.
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

	// The product id out of an add-to-cart URL, e.g. /?add-to-cart=8001&quantity=2.
	function idFromAddToCartHref(href) {
		if (!href) {
			return '';
		}
		var m = String(href).match(/[?&]add-to-cart=(\d+)/);
		return m ? m[1] : '';
	}

	var lastAddToCart = {};
	function fireAddToCart(id, qty, cardName) {
		id = id ? String(id) : '';
		qty = qty || 1;

		// Fall back on the page's own product: a single-product button often carries no id.
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

		// Name and price when the page is about THIS product, the same three fields view_content sends.
		var line = { id: id, quantity: qty };
		var name;
		var price;
		if (id && v && v.id && String(v.id) === id) {
			name = v.name;
			price = num(v.value);
		} else if (cardName) {
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

	// ---- merchandising: lists, item clicks, promotions ------------------------

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

	// view_item_list, one per convention list container on the page.
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

	// select_item, click on any element inside a convention item.
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

	// WooCommerce fallback: the standard product grid.
	var WOO_GRID_ITEM = 'ul.products li.product, .wp-block-woocommerce-product-template li.product';
	var wooGridOwnsClicks = false;

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
		// Do not list the page twice: on a category archive the server already sent view_item_list.
		var servedList = EVENTS.contentList && EVENTS.contentList.products && EVENTS.contentList.products.length;
		if (products.length && !servedList) {
			track('view_item_list', { beaconData: { listName: listName, products: products } });
		}

		// select_item on product-link clicks inside the grid (classic or block).
		wooGridOwnsClicks = true;
		document.addEventListener('click', function (e) {
			var li = e.target && e.target.closest ? e.target.closest(WOO_GRID_ITEM) : null;
			if (!li) { return; }
			// Ignore add-to-cart button clicks, those are add_to_cart, not select_item.
			if (e.target.closest && e.target.closest('.add_to_cart_button')) { return; }
			var p = wooItem(li);
			if (!p) { return; }
			track('select_item', { beaconData: { listName: listName, products: [p] } });
		}, true);
	}

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

	// ---- lead-gen: forms ------------------------------------------------------

	var leadFired = { lead: false, complete_registration: false };

	function fireLead(kind) {
		// Debounce: a form can submit twice (validation re-submit), one per page.
		if (leadFired[kind]) { return; }
		leadFired[kind] = true;
		track(kind, { clientData: {}, beaconData: {} });
	}

	// Does this form look like a lead form rather than a search box, a login, a comment or a filter?
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
				if (form.matches('.wpcf7-form, .wpforms-form, .gform_wrapper form, .elementor-form, .nf-form-cont form')) { return; }
				if (looksLikeLeadForm(form)) {
					fireLead('lead');
				}
			} catch (err) {}
		}, true);

		// The form plugins that KNOW whether the message got through.
		document.addEventListener('wpcf7mailsent', function () { fireLead('lead'); }, false); // Contact Form 7
		if (window.jQuery) {
			window.jQuery(document).on('wpformsAjaxSubmitSuccess', function () { fireLead('lead'); });
			window.jQuery(document).on('gform_confirmation_loaded', function () { fireLead('lead'); });
			window.jQuery(document).on('submit_success', function () { fireLead('lead'); });       // Elementor
			window.jQuery(document).on('nfFormSubmitResponse', function () { fireLead('lead'); }); // Ninja Forms
		}
	}

	function firstMatch(el, selector) {
		return el && el.closest ? el.closest(selector) : null;
	}

	// search: the term as WordPress resolved it.
	function trackSearch() {
		var q = EVENTS.search;
		if (!q || !q.searchString) { return; }
		track('search', {
			clientData: { searchString: q.searchString },
			beaconData: { searchString: q.searchString }
		});
	}

	// view_item_list on a listing page (blog index, category, search results).
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

	// select_item: which entry of that listing was opened.
	function wireContentListClicks() {
		var l = EVENTS.contentList;
		if (!l || !l.products || !l.products.length) { return; }
		if (wooGridOwnsClicks) { return; }
		document.addEventListener('click', function (e) {
			var a = firstMatch(e.target, 'a[href]');
			if (!a) { return; }
			// Match the link to a listed entry by its title: the markup around a card differs in every theme,
			// the title does not.
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
		// Shipping method chosen at checkout.
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

	// ---- boot -----------------------------------------------------------------

	function boot() {
		// Click ids live only in the landing URL: read them now and carry them on internal links.
		// Nothing is stored before consent; captureClickIds writes the cookies afterwards.
		readPendingClickIds();
		wireClickIdPassthrough();
		whenConsent(captureClickIds);

		// Consent Mode advanced: default denial and Google tags before the first event.
		CM_ON = DFSS_CM.isAdvanced(CONSENT, PUBLIC);
		if (CM_ON) {
			DFSS_CM.boot(cmEnv());
		}
		trackPageView();
		trackContentView();
		trackContentList();
		trackSearch();
		trackViewCart();
		trackViewItem();
		trackInitiateCheckout();
		trackPurchase();
		trackConventionLists();

		// Interaction wiring. Listeners are cheap; the events are consent-gated inside track().
		wireAddToCart();
		wireAddPaymentInfo();
		wireConventionSelectItem();
		wireWooProductGrid();
		wirePromotions();
		wireLeadForms();
		wireContentListClicks();
		wireCartAndCheckoutExtras();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
