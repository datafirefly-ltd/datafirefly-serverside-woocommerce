=== DataFirefly Server-Side ===
Contributors: datafirefly
Tags: woocommerce, tracking, conversion api, facebook pixel, ga4
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.23.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Complete WooCommerce tracking — client + server, full-funnel, deduplicated, GDPR-aware, reliable. One key configures everything.

== Description ==

DataFirefly Server-Side delivers complete, reliable WooCommerce conversion tracking with a single connection key:

* **Full funnel** — page view, product view, add to cart, initiate checkout, add payment info, purchase.
* **Dual delivery, deduplicated** — every event fires a light client pixel (Meta / GA4 / TikTok) **and** a signed server-side event sharing the same event id, so ad blockers never cost you a conversion and nothing is ever counted twice.
* **Per-destination control** — enable or disable the Meta, GA4 and TikTok client tags individually. A disabled destination's third-party script (and its cookies) is never loaded in your visitors' browsers.
* **GDPR-aware** — nothing fires until marketing consent is granted. Recognised without configuration: DataFirefly Cookie Consent, WP Consent API, Complianz, Cookiebot, IAB TCF v2, Didomi, Usercentrics, CookieYes, Iubenda, OneTrust, Cookiehub, Osano, Borlabs, Klaro, tarteaucitron — in the browser and on the server, the same list as the PrestaShop and Shopware modules.
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

= 2.23.0 =
* Privacy: a shopper who refuses consent is now reported as refused instead of not reported at all. Saying nothing read, on the dispatcher, exactly like a shop that never asks, and the sale was forwarded to the advertising platforms all the same. The sale itself is still reported, without any personal data, so the shop keeps its totals; the dispatcher records it and stops it there. Requires a dispatcher running 0.64.0 or later.
* Security: the request timestamp is now part of the signed string, as version 2 of the dispatcher signature contract. Until now only the body was signed, so the timestamp header was unauthenticated and the dispatcher's five-minute window protected nothing: anyone who captured one signed request could replay it forever by sending it again with a fresh timestamp. Every signed call (events, daily totals, destination ids) now sends X-Dfss-Signature-Version: 2 and signs the timestamp, a line feed, then the exact bytes posted. Requires a dispatcher running 0.64.0 or later.

= 2.22.1 =
* Fix: on a site served from a full-page cache, the nonce baked into the HTML goes stale and the lead, complete_registration and add_payment_info beacons introduced in 2.22.0 would have been refused. The tracker now fetches a fresh nonce from a never-cached endpoint on the visitor's first interaction, and retries a refused beacon once.

= 2.22.0 =
* Privacy: the server-side purchase event now honours the shopper's consent. The verdict is read from the consent cookie at checkout and stored on the order; when marketing consent was refused, or no consent signal can be read while "Require consent" is on, the purchase is sent with no personal data at all (no email, phone, name, address, IP, cookie or click id) and without a consent claim. It used to send every billing field and report itself as "granted" as soon as the setting was on, without checking.
* Privacy: the login event no longer sends the account email; it carries the user id only, and only with consent.
* Privacy: the retry queue keeps the event payload only while a row is still waiting to be replayed. Delivered and rejected rows keep their event name, id and HTTP code and nothing else, and finished rows are purged after 30 days.
* New: deleting the plugin now removes its settings, the cached destination ids, the daily totals cursor, its cron hooks, its rate-limit transients and the retry queue table.
* Security: the beacon endpoint requires a valid nonce for lead, complete_registration and add_payment_info, which have no page context to check against and could be forged for free. It no longer accepts an order id from the browser, drops a value above 10,000,000 or an item count above 10,000 rather than forwarding it, and replaces a source URL on another host by the shop's home page.
* Security: the endpoint entered in the advanced form must be a valid https:// URL or nothing is saved, and every request to the dispatcher goes through wp_safe_remote_post().
* Fix: an HMAC secret entered in the advanced form was run through sanitize_text_field, which can silently alter a valid secret; it is now validated against the same character set as the connection key and stored verbatim.
* Fix: two overlapping retry runs could replay the same queued event twice. A row is now claimed atomically before it is sent, and a claim that never resolved goes back to the queue after ten minutes.

= 2.21.4 =
* Security: the thank-you page context is only built when the URL carries the order key, as WooCommerce itself requires. Without that check, anyone could read the total and the lines of any order by walking the order ids.
* Security: the HMAC secret is no longer echoed back in the advanced settings form; an empty field keeps the stored secret. The connection key field is masked too.

= 2.21.3 =
* Fix: a fully refunded order was counted neither as a sale nor as a refund in the daily totals, while its purchase event had been sent. It now counts as a sale of the day it was placed and as a refund for the amount refunded, which is what the reconciliation compares against.

= 2.21.2 =
* Fix: on a shop running Polylang for WooCommerce, the daily totals only counted the orders placed in the site's default language: Polylang filters every typed order query on the current language, and under WP-Cron that is the default one. The orders placed in the shop's other languages were silently left out of the totals. The query now asks for all languages.

= 2.21.1 =
* Fix: the daily totals job (truth) died with a fatal error on the first day the shop had issued a refund, because the order query also returned refund objects. The cursor never moved past that day, so the job died again every night on the same day and the dispatcher stopped receiving the shop's daily totals. The query now asks for orders only, and the job no longer lets a PHP error take the whole cron request down.

= 2.21.0 =
* Fix: on a variable product, the purchase event reported the PARENT product while add-to-cart, cart, checkout and payment all reported the variation. The product that was added was never the product that was bought: the funnel split at the last step, and the conversion reached Meta and GA4 with ids matching neither the earlier events nor the variation lines of the product feed. Nothing changes for simple products.

= 2.20.1 =
* Fix: on a stock storefront, the fix in 2.20.0 also silenced the grid's own click tracking, which reads the product id off the grid item. The grid now keeps wiring select_item (exact) while the server keeps naming the list (authoritative ids), and the content-list fallback stands down so only one select_item is sent.

= 2.20.0 =
* Fix: on a product category page, products were listed as content: id "product-5127" instead of 5127, and the category "product" instead of the category name. GA4 item_id and Meta content_ids match a merchant's product feed, whose ids are bare, so those list events matched nothing, silently. Single product pages were never affected.
* Fix: a stock WooCommerce storefront could send two view_item_list events for one category page (server list + DOM grid). The DOM grid now stands down when the server already listed the page.

= 2.2.0 =
* New: per-destination client-tag toggles (Meta, GA4, TikTok) — a disabled destination's script is never loaded.
* Fix: coding-standards and Plugin Check compliance pass (i18n translators comments, input sanitization, no unprefixed globals).

= 2.1.1 =
* New: merchandising events (view_item_list, select_item, view_promotion, select_promotion).

= 2.0.1 =
* Full-funnel client tracking layer with dedup-perfect server-side delivery, retry queue and Activity panel.

= 1.x =
* Server-side purchase event delivery.
