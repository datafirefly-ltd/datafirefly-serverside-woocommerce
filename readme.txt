=== DataFirefly Server-Side ===
Contributors: datafirefly
Tags: woocommerce, tracking, conversion api, facebook pixel, ga4
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Complete WooCommerce tracking — client + server, full-funnel, deduplicated, GDPR-aware, reliable. One key configures everything.

== Description ==

DataFirefly Server-Side delivers complete, reliable WooCommerce conversion tracking with a single connection key:

* **Full funnel** — page view, product view, add to cart, initiate checkout, add payment info, purchase.
* **Dual delivery, deduplicated** — every event fires a light client pixel (Meta / GA4 / TikTok) **and** a signed server-side event sharing the same event id, so ad blockers never cost you a conversion and nothing is ever counted twice.
* **Per-destination control** — enable or disable the Meta, GA4 and TikTok client tags individually. A disabled destination's third-party script (and its cookies) is never loaded in your visitors' browsers.
* **GDPR-aware** — nothing fires until marketing consent is granted (DataFirefly Cookie Consent, WP Consent API, Complianz, Cookiebot, IAB TCF v2).
* **Reliable** — failed sends are queued and retried with exponential backoff; an Activity panel shows delivery status live.
* **Secure by design** — no destination credential ever reaches the browser; the HMAC secret never leaves the server; the public beacon endpoint is rate-limited, size-capped and strictly sanitized; purchase events are server-authoritative and cannot be spoofed.

Requires a DataFirefly account (https://datafirefly.com) providing the dispatcher connection key.

== Installation ==

1. Upload the plugin and activate it.
2. Go to Settings → DataFirefly Server-Side.
3. Paste the connection key from your DataFirefly client space and click Connect. That is the only step.
4. Optional: in the same screen, untick any client destination (Meta, GA4, TikTok) you do not use.

== Frequently Asked Questions ==

= Does the plugin load Facebook / TikTok / Google scripts on my shop? =

Only for the destinations that are both configured on your DataFirefly account **and** enabled in the "Client destinations" setting. Untick a destination and its script will never be injected.

= Is consent respected? =

Yes. When "Require consent" is on (default), no tag is injected and no event is sent until marketing consent is granted, with live re-check when the visitor accepts.

== Changelog ==

= 2.2.0 =
* New: per-destination client-tag toggles (Meta, GA4, TikTok) — a disabled destination's script is never loaded.
* Fix: coding-standards and Plugin Check compliance pass (i18n translators comments, input sanitization, no unprefixed globals).

= 2.1.1 =
* New: merchandising events (view_item_list, select_item, view_promotion, select_promotion).

= 2.0.1 =
* Full-funnel client tracking layer with dedup-perfect server-side delivery, retry queue and Activity panel.

= 1.x =
* Server-side purchase event delivery.
