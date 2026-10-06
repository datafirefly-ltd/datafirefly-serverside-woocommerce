/**
 * DataFirefly Server-Side: lead and engagement events.
 *
 * Contact links, booking links, social shares, trial links, newsletter forms, applications and
 * tagged donate / store-locator buttons. Optional module (setting "Lead and engagement events"),
 * loaded after dfss-tracker.js and sent through its window.dfssTrack().
 */
(function () {
	'use strict';

	if (typeof window.dfssTrack !== 'function' || window.__dfssEngagementLoaded) {
		return;
	}
	window.__dfssEngagementLoaded = true;

	var track = window.dfssTrack;

	function firstMatch(el, selector) {
		return el && el.closest ? el.closest(selector) : null;
	}

	// contact: a click on an email or phone link. The address itself is not sent.
	function wireContactLinks() {
		document.addEventListener('click', function (e) {
			var a = firstMatch(e.target, 'a[href^="mailto:"], a[href^="tel:"], [data-df-contact]');
			if (!a) { return; }
			var href = String(a.getAttribute('href') || '');
			track('contact', {
				clientData: {},
				beaconData: { method: href.indexOf('tel:') === 0 ? 'phone' : 'email' }
			});
		}, true);
	}

	// schedule: a booking link (Calendly and similar open elsewhere, so the click is all we see).
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

	// share: a click on a standard social sharer URL.
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

	// start_trial: a free-plan or trial link or form, matched in several languages.
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

	// subscribe: a known newsletter plugin, or a form whose only field is an email address.
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
		// Mailchimp for WordPress reports its own success: a rejected address subscribed to nothing.
		if (window.jQuery) {
			window.jQuery(document).on('mc4wp-subscribed', function () { fireSubscribe(); });
		}
	}

	// submit_application: a form asking for a file AND an email address.
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

	// donate / find_location: opt-in by attribute only, no generic shape is reliable.
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

	wireContactLinks();
	wireScheduleLinks();
	wireShareLinks();
	wireTrialLinks();
	wireNewsletterForms();
	wireApplicationForms();
	wireTaggedConversions();
})();
