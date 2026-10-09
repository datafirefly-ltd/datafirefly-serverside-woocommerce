=== DataFirefly Server-Side ===
Contributors: datafirefly
Tags: woocommerce, tracking, conversion api, facebook pixel, ga4
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.30.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Complete WooCommerce tracking — client + server, full-funnel, deduplicated, GDPR-aware, reliable. One key configures everything.

== Description ==

DataFirefly Server-Side delivers complete, reliable WooCommerce conversion tracking with a single connection key:

* **Full funnel** — page view, product view, add to cart, initiate checkout, add payment info, purchase.
* **Dual delivery, deduplicated** — every event fires a light client pixel (Meta / GA4 / TikTok) **and** a signed server-side event sharing the same event id, so ad blockers never cost you a conversion and nothing is ever counted twice.
* **Per-destination control**: enable or disable the Meta, GA4, Google Ads, TikTok and OpenAI browser tags individually. A disabled platform's code is not sent to the browser at all, and its third-party script and cookies never load.
* **Light on the storefront**: deferred, minified scripts, only the modules you use, and page-load events sent in a single request.
* **GDPR-aware** — nothing fires until marketing consent is granted. Recognised without configuration: DataFirefly Cookie Consent, WP Consent API, Complianz, Cookiebot, IAB TCF v2, Didomi, Usercentrics, CookieYes, Iubenda, OneTrust, Cookiehub, Osano, Borlabs, Klaro, tarteaucitron — in the browser and on the server, the same list as the PrestaShop and Shopware modules.
* **Reliable** — failed sends are queued and retried with exponential backoff; an Activity panel shows delivery status live.
* **Secure by design** — no destination credential ever reaches the browser; the HMAC secret never leaves the server; the public beacon endpoint is rate-limited, size-capped and strictly sanitized; purchase events are server-authoritative and cannot be spoofed.

Requires a DataFirefly account (https://datafirefly.com) providing the dispatcher connection key.

= External service =

This plugin sends your shop's tracking events to DataFirefly Server-Side, a service operated by DataFirefly Limited (Ireland). The plugin does nothing until you paste a connection key, and without a key no request ever leaves your site.

What is sent, and when:

* On each tracked visitor action (page view, product view, add to cart, checkout started, payment info added, purchase, form lead): the event name, an event id, the page URL and referrer, the product or order details for commerce events, the visitor's advertising cookies and click identifiers when they exist, and the visitor's IP address and user agent. Personal details of the buyer (email, phone, name, address) are sent for the purchase event only, and only when marketing consent has been granted; DataFirefly hashes them before forwarding them to any advertising platform, the plugin itself sends them as they are, over TLS. When consent is refused or unknown, the purchase is reported without any personal data at all.
* Once a night: the number of orders and their total for the previous day, so the service can tell you what it received against what your shop actually sold.
* When you open the settings screen: a request for the public identifiers of your enabled destinations (pixel ids and measurement ids), so the plugin can fire the matching browser tags.

Every request is signed with a secret that stays on your server and is never exposed to the browser. The endpoint is https://serverside.datafirefly.com.

DataFirefly then forwards the events, on your behalf and according to what you enabled in your DataFirefly account, to the advertising and analytics platforms you configured there: Meta, Google Analytics 4, Google Ads, TikTok, Pinterest, Microsoft Advertising and OpenAI.

* Terms of service: https://server-side.datafirefly.com/en/terms.html
* Privacy policy: https://server-side.datafirefly.com/en/privacy.html

== Installation ==

1. Upload the plugin and activate it.
2. Go to Settings → DataFirefly Server-Side.
3. Paste the connection key from your DataFirefly client space and click Connect. That is the only step.
4. Optional: in the same screen, untick any browser tag (Meta, GA4, Google Ads, TikTok, OpenAI) you do not use, and the lead and engagement events if your site does not need them.

== Frequently Asked Questions ==

= Does the plugin load Facebook / TikTok / Google scripts on my shop? =

Only for the platforms that are both configured on your DataFirefly account **and** enabled in the "Browser tags" setting. Untick one and its code is not even sent to the browser.

= Does the plugin slow my storefront down? =

The core tracker is about 10 KB compressed, loaded deferred in the footer. Each platform tag is a separate small file loaded only when enabled, and the lead and engagement events can be switched off. Page-load events go to your server in one request instead of one per event.

= Is consent respected? =

Yes. When "Require consent" is on (default), no tag is injected and no event is sent until marketing consent is granted, with live re-check when the visitor accepts.

== Changelog ==

= 2.30.0 =
* Improved: events that failed to send are retried purchases and refunds first, then the most recent ones. Until now the oldest came first, so after a long outage a sale could wait behind thousands of old page views.
* New: a queued event older than 7 days is marked Expired and is not sent (no platform accepts it any more). It is counted in the Activity panel ("Expired" in the last run) and in the status of the latest rows, never sent and never skipped silently; the oldest settled rows are cleaned up with the usual log trimming.
* Note: a purchase that is sent for the first time more than 7 days after its order date expires without being sent, because the platforms' attribution windows are shorter than that.
* Improved: each retry run works within a 12 second budget and 200 events, and stops sending after a network failure, a server error or a rate limit instead of trying every remaining event against a dispatcher that is down.
* New: every signed request to the dispatcher now carries the number of queued events and the age of the oldest one, so a shop whose retries run late is visible. After a retry run that changed the queue, the plugin also sends the dispatcher one empty heartbeat with the fresh figures, so a quiet shop does not look stalled.
* Improved: the Activity panel shows when the retry queue last ran. WordPress runs retries from its scheduler every 5 minutes, but only when the site is visited: on a quiet shop, call wp-cron.php from a server cron job every 5 minutes.
* Improved: events relayed from the visitor's browser now wait up to 8 seconds for the dispatcher instead of 4 (checkout and order events keep 4), so slow networks stop aborting them.
* Improved: the Activity panel also shows how many events the last retry run expired, and how many expired events are still listed (the log keeps only the latest rows).
* Fix: an event is no longer lost if the queue table has not been updated yet when it is saved; the table is now also updated for a shop that is not connected.
* New: the new messages are translated into French, German, Spanish, Italian, Dutch, Polish, Portuguese and Czech.

= 2.29.0 =
* New: the settings screen and its messages are translated into French, German, Spanish, Italian, Dutch, Polish, Portuguese and Czech. The language follows the WordPress user's locale; any other language stays in English.
* Fix: Google Consent Mode advanced no longer counts an order a second time in GA4 when the confirmation page is reloaded or revisited with the back button.
* Fix: the consent verdict handed to the confirmation page now asks whether consent is required first, as the server does. An old refused order on a shop that has since switched consent gating off could reach GA4 twice.
* Fix: Consent Mode advanced leaves ads_data_redaction to the consent tool when that tool already set Google's defaults.
* Fix: the browser_sent list read from the page accepts strings only; a forged payload no longer raises a PHP warning.
* Improved: the cookieless GA4 pings of Consent Mode advanced now carry search_term, item_list_id, item_list_name and the items of the event.
* Code: the shared consent and design blocks are now in English. The reason the tracker leaves some events out of the server beacon (quota and cost) is documented in the code.

= 2.28.0 =
* New: Google Ads and OpenAI (ChatGPT Ads) browser tags can now be switched off, like Meta, GA4 and TikTok.
* New: a switched-off platform's code is no longer shipped at all. Meta, TikTok and OpenAI tags are separate files, enqueued only when enabled and configured.
* New: "Lead and engagement events" setting (contact, booking, share, trial, newsletter, application, tagged donate and store-locator buttons). On by default; off, its script is not loaded.
* Performance: the tracker no longer sends the server events its endpoint always refused (view_cart, contact, share, add_to_wishlist and nine others). Each one booted WordPress for nothing; the browser tags still receive them.
* Performance: page-load events (page view, product view, list...) go out in one request instead of one per event. The endpoint accepts a batch of up to 10 events, each rate-limited, sanitized, sent and recorded on its own.
* Performance: minified builds (core tracker 109 KB down to 33 KB, 10 KB compressed), deferred loading, options cached per request, the cron check no longer runs on storefront pages, and the activity log is trimmed on about one write in twenty instead of every write.
* Fix: the admin script never loaded on the settings screen (slug mismatch).
* Code: comments shortened and translated to English, except inside the blocks generated by the shared sync scripts.

= 2.27.0 =
* The plugin now uses its wordpress.org identifier everywhere: folder datafirefly-server-side, text domain datafirefly-server-side. Copies downloaded from the DataFirefly client space until 2.26.x used the folder datafirefly-serverside, which WordPress treats as a different plugin. Activating 2.27.0 on such a shop switches the older copy off and keeps every setting; the older copy can then be deleted from the Plugins screen without losing the connection, because its uninstall no longer takes the shared settings with it.
* The package passes the WordPress Plugin Check with no error: no hidden file is shipped (every PHP file already refuses direct access), and the settings helper uses the standard direct-access guard. The shared consent code and the design stylesheet carry their licence header.

= 2.26.0 =
* New: optional Google Consent Mode "advanced" (off by default). Before consent, only the Google tags load, cookieless, for Google's modelling; Meta, TikTok and every other platform still wait for consent. Once the visitor accepts, measurement goes through the server as before, so ad blockers do not take it away. A purchase is sent to GA4 by the browser only when the server did not send it.
* Fix: on a connected shop, "Save settings" switched the click-ID passthrough off and reset the hold to zero, because the form did not show those fields. The connected screen now shows all consent settings, and a form only changes what it shows.
* Fix: orders placed through the block checkout (WooCommerce's default since 8.3) now keep the consent verdict, the ad click IDs and the GA4 session, as classic checkout orders always did.

= 2.25.3 =
* Fix: since 2.25.0 the storefront tracker stopped on page load (two click-ID functions were missing), so no browser event was sent: no page view, no pixel, no add-to-cart. Purchases sent by the server were not affected.

= 2.25.2 =
* Housekeeping, no change to what the plugin does. The development test benches, the developer README and the .gitignore no longer travel inside the archive: they are repository files, not plugin files. The readme now states plainly which service the plugin talks to, what it sends and when, with links to the terms and the privacy policy.

= 2.25.1 =
* Housekeeping, no change to what the plugin does. The readme had stayed on 2.23.0 while the code shipped 2.25.0, so the plugin directory would have served the older version whatever was uploaded; the 2.24.0 and 2.25.0 entries below were missing and are now written. "Tested up to" moves to WordPress 7.1. Three code-standard warnings are silenced with a written reason instead of being left to a reviewer's judgement: wc_clean() is WooCommerce's own sanitiser and the checker does not know it, and the two WPML filters are that plugin's public API, not hooks of ours.

= 2.25.0 =
* New: the Google click ID now survives navigation. A gclid only ever exists in the URL of the landing page, so a shopper who arrives from an ad, browses a few pages, then accepts the cookie banner has already lost it and the sale can no longer be attributed. The tracker reads it on load and carries it on your own internal links, in the URL only. Nothing is written to the device, so nothing needs consent, and it is never passed to another site. Can be turned off in the settings.
* New: an optional hold on events until the shopper answers the banner. Someone who has not answered yet is not someone who refused. With a value above zero, their events wait in their own browser for that many minutes: nothing reaches your shop or DataFirefly. If they accept, the events are sent; if they refuse, or the delay passes, the events are discarded. An explicit refusal is never held, whatever the value. Zero by default, because this is a compliance decision and not a technical setting.
* New: dynamic remarketing and web-page conversion actions are handled by the browser tag, which are the two things a server-side send can never do. An audience is built from the visitor's own cookie, and a conversion action created as a web page type has that type frozen at creation and cannot receive an import.
* Note: a shop upgrading to this version receives both new settings at their defaults, click ID passthrough on and the hold at zero, with nothing to do.

= 2.24.0 =
* Fix: shops using tarteaucitron in its native cookie format were never granting consent, and every conversion was dropped in silence. The consent reader only understood the older JSON format and parsed the cookie blindly, which threw on the native `!gtag=true!facebookpixel=false!youtube=wait` form. Both formats are now read.

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

== Upgrade Notice ==

= 2.30.0 =
The retry queue table gets two columns on upgrade (done automatically). Events queued for more than 7 days are no longer sent. No setting changes.

= 2.29.0 =
Translations added and a few consent fixes. No setting changes.

= 2.28.0 =
Every browser tag stays enabled on upgrade: nothing changes until you untick one.

= 2.27.0 =
If you installed the plugin from the DataFirefly client space (folder datafirefly-serverside), install and activate this version first: it takes over with the same settings. Then delete the older copy.
