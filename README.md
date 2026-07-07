# DataFirefly Server-Side — WooCommerce client + server tracking (free)

**Free WooCommerce server-side tracking plugin.** Send every conversion twice —
a light browser pixel **and** a signed server-side event sharing the same event
id — so ad blockers, iOS/ITP and third-party-cookie loss never cost you a sale,
and nothing is ever counted twice.

One connection key configures everything. No destination credentials (Meta CAPI
token, GA4 API secret, TikTok Events API token) ever touch the browser — they
live only on the DataFirefly Server-Side dispatcher.

> The official WooCommerce connector for **[DataFirefly Server-Side](https://server-side.datafirefly.com/en/)** — server-side conversion tracking for Meta, GA4 and TikTok.

![Version](https://img.shields.io/badge/version-2.2.0-008D9E)
![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-21759b)
![WooCommerce](https://img.shields.io/badge/WooCommerce-5.0%2B-96588a)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb3)
![License](https://img.shields.io/badge/license-GPLv2-blue)

---

## Why server-side tracking for WooCommerce?

Browser-only pixels (Meta Pixel, GA4, TikTok Pixel) lose 10–40 % of conversions
to ad blockers, Safari ITP, iOS restrictions and the end of third-party cookies.
Server-side tracking — the **Conversions API** approach — sends the purchase
straight from your server, so it arrives even when the browser tag is blocked.

DataFirefly Server-Side gives you both layers, **deduplicated**:

- the **browser pixel** sets first-party cookies and fires fast, and
- a **signed server-side event** with the *same* event id backs it up.

Meta, GA4 and TikTok match the two on `(event_name, event_id)` and keep exactly
one conversion — never zero, never double.

## Features

- **Full funnel** — `page_view`, `view_content`, `view_item_list`, `select_item`,
  `add_to_cart`, `initiate_checkout`, `add_payment_info`, `purchase`
  (+ promotions and lead/registration via a simple data-attribute convention).
- **Dual delivery, deduplicated** — client pixel + signed server event, one shared
  event id. Ad-blocker-proof, cookieless-resilient conversions.
- **Per-destination control** — enable/disable the Meta, GA4 and TikTok client
  tags individually. A disabled destination's third-party script (and its
  cookies) is never loaded in your visitors' browsers.
- **Server-side purchase** from the authoritative WooCommerce order hook — fires
  even when the customer's browser closes on the thank-you page.
- **GDPR-aware** — nothing fires until marketing consent is granted. Works with
  DataFirefly Cookie Consent, the WP Consent API, Complianz, Cookiebot and IAB
  TCF v2.
- **One key configures everything** — paste a single connection key; the public
  pixel ids are fetched automatically. No token is ever exposed in the browser.
- **Reliable** — failed server deliveries are retried from a durable queue.
- **Block themes + classic themes** — product-list and product-click tracking
  works on both the classic loop and the block *Product Collection*.

## How it works

```
Shopper's browser ──▶ light pixel (Meta / GA4 / TikTok)   ┐  same event_id
                 └──▶ signed beacon ──▶ DataFirefly ───────┼─▶ Meta CAPI
WooCommerce order hook ──▶ signed server purchase ────────┘   GA4 MP / TikTok API
```

The plugin only ever holds **public** pixel ids. The signing secret and every
destination API token stay on the DataFirefly Server-Side dispatcher, which fans
the events out to Meta Conversions API, GA4 Measurement Protocol and the TikTok
Events API.

## Requirements

- WordPress 5.8+ · WooCommerce 5.0+ · PHP 7.4+
- A **[DataFirefly Server-Side](https://www.datafirefly.com/en/product/datafirefly-server-side/)**
  account (free) to get your connection key.

## Installation

1. Download the latest release ZIP.
2. WordPress admin → **Plugins → Add New → Upload Plugin** → choose the ZIP → **Install** → **Activate**.
3. Go to **DataFirefly Server-Side** in the admin menu.
4. Paste your **connection key** (from your DataFirefly client area), enable
   tracking, and save. That's it — events start flowing.

## Configuration

Everything is driven by the one-line **connection key** (`dfss_…`) from your
DataFirefly client area. It contains your tenant id, signing secret and endpoint.
Toggle *Complete tracking* for the full funnel, pick which client tags load, and
optionally require marketing consent.

## Links

- 🌐 **DataFirefly Server-Side (service):** https://server-side.datafirefly.com/en/
- 🔗 **Product page:** https://www.datafirefly.com/en/product/datafirefly-server-side/
- 🔥 **DataFirefly:** https://www.datafirefly.com

## License

GPLv2 or later — see [LICENSE](LICENSE). DataFirefly® is a trademark of
DataFirefly Ltd.
