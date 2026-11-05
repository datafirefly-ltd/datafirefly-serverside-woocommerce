/**
 * What the Google tags read as the address of the page.
 *
 * gtag.js sends location.href and document.referrer with every hit, whoever
 * loaded it (this plugin, a consent tool, a theme), and consent changes nothing:
 * on 05/11/2026 Drexco Medical's guest order tracking form, a GET form, put the
 * customer's email in the address, and Google received it in clear on 3 hits
 * from a visitor who had not answered the banner, on 14 of 15 after accepting,
 * remarketing lists included. The dispatcher cleaned the same address on the
 * server path, which is why nothing in our data showed it.
 *
 * Loaded in the head, before any tag: a 'set' only applies to the commands
 * after it. Same rules as sanitizeUrl() in the dispatcher (src/lib/piiUrl.ts),
 * held together by tests/test-url-guard.mjs. Only an address that changes is
 * set: on every other page Google keeps reading the page itself.
 *
 * Google only. A Meta or TikTok pixel reads the address on its own and offers
 * no way to give it another one.
 */
(function (w, d) {
	'use strict';

	var EMAIL = /[A-Za-z0-9._%+-]{1,64}(?:@|%40)[A-Za-z0-9.-]{1,253}\.[A-Za-z]{2,63}/;
	var PHONE = /(?:\+|%2B)?\d[\d .-]{8,}\d/g;
	var ATTRIBUTION = ' gclid gbraid wbraid fbclid ttclid epik msclkid oppref obref gad_source gad_campaignid dclid srsltid _gl twclid sccid li_fat_id ';
	var IDENTITY = ' email mail emailaddress useremail customeremail billingemail phone tel telephone mobile mobilephone phonenumber cellphone cell firstname lastname fullname name surname givenname familyname address address1 address2 streetaddress street zip zipcode postcode postalcode birthdate dateofbirth dob password passwd pwd ';

	function inList(list, word) {
		return word !== '' && list.indexOf(' ' + word + ' ') !== -1;
	}

	function looksLikePhone(s) {
		var digits = s.replace(/\D/g, '').length;
		if (digits < 9 || digits > 15) {
			return false;
		}
		var separators = s.replace(/[\d+]|%2B/gi, '').length;
		if (separators >= 3) {
			return true;
		}

		return separators === 0 && /^(?:\+|%2B|0)/i.test(s);
	}

	function containsPii(text) {
		if (EMAIL.test(text)) {
			return true;
		}
		var found = text.match(PHONE) || [];
		for (var i = 0; i < found.length; i++) {
			if (looksLikePhone(found[i])) {
				return true;
			}
		}

		return false;
	}

	function normalise(key) {
		return key.toLowerCase().replace(/[^a-z0-9]/g, '');
	}

	function isIdentityKey(key) {
		var whole = normalise(key);
		if (inList(IDENTITY, whole) || /(?:email|phone)$/.test(whole)) {
			return true;
		}
		var inner = /\[([^\]]*)\]\s*$/.exec(key);

		return inner !== null && inList(IDENTITY, normalise(inner[1]));
	}

	function decode(text) {
		try {
			return decodeURIComponent(text.replace(/\+/g, ' '));
		} catch (e) {
			return text;
		}
	}

	/** Decoded up to twice, and a "+" stays a plus sign: "%2540" is "@". */
	function decodePath(text) {
		var out = text;
		for (var i = 0; i < 2 && out.indexOf('%') !== -1; i++) {
			try {
				out = decodeURIComponent(out);
			} catch (e) {
				break;
			}
		}

		return out;
	}

	function cleanPath(head) {
		var u;
		try {
			u = new URL(head);
		} catch (e) {
			return containsPii(decodePath(head)) ? '[redacted]' : head;
		}
		var segments = u.pathname.split('/').filter(Boolean);
		var carries = segments.map(function (s) {
			return containsPii(s) || containsPii(decodePath(s));
		});
		if (carries.indexOf(true) === -1) {
			return u.username !== '' || u.password !== '' ? u.protocol + '//' + u.host + u.pathname : head;
		}

		return u.origin + '/' + (segments.length > 0 && !carries[0] ? segments[0] + '/' : '') + '[redacted]';
	}

	function keepPart(part) {
		var eq = part.indexOf('=');
		var key = decode(eq === -1 ? part : part.slice(0, eq));
		var value = eq === -1 ? '' : decode(part.slice(eq + 1));
		var lower = key.replace(/^\s+|\s+$/g, '').toLowerCase();

		if (inList(ATTRIBUTION, lower)) {
			return true;
		}
		if (lower.indexOf('utm_') === 0) {
			return !containsPii(value);
		}
		if (isIdentityKey(key)) {
			return false;
		}

		return !containsPii(key) && !containsPii(value);
	}

	/** The address without its identifiers, or null when it has none. */
	function clean(raw) {
		var hash = raw.indexOf('#');
		var fragment = hash === -1 ? '' : raw.slice(hash + 1);
		var noFragment = hash === -1 ? raw : raw.slice(0, hash);
		var q = noFragment.indexOf('?');
		var head = q === -1 ? noFragment : noFragment.slice(0, q);
		var parts = (q === -1 ? '' : noFragment.slice(q + 1)).split('&').filter(function (p) {
			return p !== '';
		});
		var kept = parts.filter(keepPart);
		var path = cleanPath(head);
		var dirtyFragment = containsPii(fragment) || containsPii(decode(fragment));
		if (path === head && kept.length === parts.length && !dirtyFragment) {
			return null;
		}

		return kept.length > 0 ? path + '?' + kept.join('&') : path;
	}

	try {
		var settings = {};
		var page = clean(String(w.location.href));
		var from = d.referrer ? clean(String(d.referrer)) : null;
		if (page !== null) {
			settings.page_location = page;
		}
		if (from !== null) {
			settings.page_referrer = from;
		}
		if (page !== null || from !== null) {
			w.dataLayer = w.dataLayer || [];
			(function () {
				w.dataLayer.push(arguments);
			})('set', settings);
		}
	} catch (e) {}
})(window, document);
