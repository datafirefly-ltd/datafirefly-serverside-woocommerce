<?php
/**
 * DataFirefly Server-Side (WooCommerce) — public beacon endpoint.
 *
 * Registers POST /wp-json/dfss/v1/collect. The browser tracker
 * (assets/dfss-tracker.js) fires a client pixel event AND beacons the same
 * event (with the same event_id) here; this endpoint signs it with the tenant
 * HMAC secret and forwards it to the dispatcher's CAPI. Meta/GA/TikTok then
 * deduplicate on (event_name, event_id), so the conversion is counted once even
 * when an ad-blocker kills the client pixel.
 *
 * This endpoint is PUBLIC — any anonymous visitor hits it — so it is built to
 * be safe against hostile input:
 *   - per-IP rolling-window rate limit backed by a transient;
 *   - request body size cap;
 *   - strict per-field sanitization before anything is forwarded;
 *   - the HMAC secret NEVER leaves the server (it stays in get_option);
 *   - identity (email/externalId) is taken from the logged-in WP/Woo session
 *     and ONLY on a verified wp_rest nonce — the browser cannot impersonate a
 *     customer, and a stale nonce on a cached page degrades to an anonymous (but
 *     still recorded) conversion rather than a dropped one (see permission_check);
 *   - no raw PII is ever logged.
 *
 * On a retryable forward failure the event is enqueued (DFSS_Queue) so a brief
 * outage never loses a conversion.
 */
if (!defined('ABSPATH')) {
    exit;
}

class DFSS_REST
{
    const REST_NAMESPACE = 'dfss/v1';
    const ROUTE = '/collect';

    // Hard caps to keep hostile payloads cheap to reject.
    const MAX_BODY_BYTES = 16384;     // 16 KB is ample for a single event
    const MAX_PRODUCTS = 50;
    // Beacons are nonce-gated and tiny; a single real shopper legitimately fires
    // several per minute (page_view + view_item + add_to_cart...), and behind a
    // NAT/CDN many shoppers can share one egress IP. Keep the cap generous so we
    // never drop real conversions; the nonce + size cap + sanitization are the
    // real abuse guards. Operators can refine the source IP via `dfss_client_ip`.
    const RATE_LIMIT_MAX = 120;       // requests...
    const RATE_LIMIT_WINDOW = 60;     // ...per this many seconds, per IP (rolling bucket)
    // Upper bounds on the numbers a beacon may carry. A single 1e300 value
    // would not crash anything, but it would sit in a merchant's revenue
    // report as a fact (audit 2026-09-04, M5). Above the bound the field is
    // dropped, never clamped: a clamped figure is still a fabricated one.
    const MAX_VALUE = 10000000;       // 1e7, in the shop currency
    const MAX_ITEMS = 10000;

    /**
     * Events the beacon only accepts on a VALID wp_rest nonce. They are the
     * ones with no page context of their own to check against, so a forged
     * beacon costs nothing and reads as a real lead, a real registration or
     * a real payment step in the merchant's reports (audit 2026-09-04, M5).
     * The rest of the funnel keeps the soft policy of permission_check().
     *
     * @var string[]
     */
    const NONCE_REQUIRED_EVENTS = array('lead', 'complete_registration', 'add_payment_info');

    /** @var callable():array A provider returning the current plugin options. */
    private $opts_provider;

    /**
     * @param callable():array $opts_provider Returns {enabled,tenant_id,hmac_secret,endpoint,...}
     */
    public function __construct(callable $opts_provider)
    {
        $this->opts_provider = $opts_provider;
    }

    public function register_routes()
    {
        register_rest_route(
            self::REST_NAMESPACE,
            self::ROUTE,
            array(
                'methods' => 'POST',
                'callback' => array($this, 'handle_collect'),
                'permission_callback' => array($this, 'permission_check'),
                // We read the body ourselves (JSON), so no args schema here; we
                // sanitize every field explicitly in the handler.
            )
        );
    }

    /**
     * Permission gate. The endpoint is public (anonymous visitors), so the
     * "permission" is: the plugin connected with complete tracking on, and the
     * per-IP rate limit.
     *
     * NONCE / CACHE POLICY (the "soft policy" for full-page-cached stores): we do
     * NOT hard-reject on a missing/stale nonce. On Litespeed/WP Rocket/Cloudflare
     * APO the nonce baked into cached HTML rotates and goes stale, which would
     * silently drop top-of-funnel (and even purchase) beacons — the exact thing
     * this version exists to capture. Instead, a valid nonce only UPGRADES the
     * request to "trusted same-origin": the handler attaches server-side identity
     * (logged-in customer email/id) only then. A stale/absent nonce still records
     * an anonymous conversion. This is safe because the endpoint never trusts the
     * body for identity and is rate-limited, size-capped and strictly sanitized.
     *
     * @param WP_REST_Request $request
     *
     * @return bool|WP_Error
     */
    public function permission_check($request)
    {
        $opts = $this->opts();

        // Nothing to do if the shop isn't connected or complete tracking is off.
        if (empty($opts['enabled']) || empty($opts['complete_tracking'])) {
            return new WP_Error('dfss_disabled', 'Tracking disabled', array('status' => 403));
        }

        // Rate limit BEFORE anything else so a flood is cheap to reject.
        if (!$this->rate_limit_ok()) {
            return new WP_Error('dfss_rate_limited', 'Too many requests', array('status' => 429));
        }

        return true;
    }

    /**
     * Whether the request carries a valid wp_rest nonce. Used to decide if we may
     * attach server-trusted identity (see the cache policy note above).
     *
     * WP REST passes the nonce as the X-WP-Nonce header or _wpnonce param
     * (sendBeacon can't set headers, so the tracker puts it in the URL).
     * wp_verify_nonce returns 1 (this session) or 2 (older but still valid).
     *
     * @param WP_REST_Request $request
     *
     * @return bool
     */
    private function nonce_is_valid($request)
    {
        $nonce = $request->get_header('X-WP-Nonce');
        if (!$nonce) {
            $nonce = $request->get_param('_wpnonce');
        }

        return $nonce && wp_verify_nonce($nonce, 'wp_rest');
    }

    /**
     * Handle one beacon: sanitize -> consent gate -> sign+forward -> enqueue on
     * retryable failure. Always returns 2xx to the browser (a tracking error
     * must never surface to the visitor); the real status lives in the queue.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_REST_Response
     */
    public function handle_collect($request)
    {
        $opts = $this->opts();

        // Size cap: reject oversized bodies outright.
        $raw = $request->get_body();
        if (strlen((string) $raw) > self::MAX_BODY_BYTES) {
            return new WP_REST_Response(array('ok' => false, 'reason' => 'too_large'), 200);
        }

        $body = json_decode((string) $raw, true);
        if (!is_array($body)) {
            return new WP_REST_Response(array('ok' => false, 'reason' => 'bad_json'), 200);
        }

        // Server-side consent gate (defense in depth; the client already gated).
        if (!DFSS_Consent::has_consent($opts)) {
            return new WP_REST_Response(array('ok' => false, 'reason' => 'no_consent'), 200);
        }

        $beacon = $this->sanitize_beacon($body);
        if ($beacon === null) {
            return new WP_REST_Response(array('ok' => false, 'reason' => 'invalid'), 200);
        }

        $nonce_ok = $this->nonce_is_valid($request);

        // The events anyone could fabricate for free are not recorded without
        // proof the beacon came from a page we served (see NONCE_REQUIRED_EVENTS).
        if (!$nonce_ok && in_array($beacon['event_name'], self::NONCE_REQUIRED_EVENTS, true)) {
            return new WP_REST_Response(array('ok' => false, 'reason' => 'nonce'), 200);
        }

        // Identity is server-trusted only, AND only on a verified same-origin
        // request (valid wp_rest nonce). For a logged-in customer we attach their
        // email/id ourselves. The browser body is NEVER trusted for identity
        // (prevents a hostile beacon claiming someone's id). On a stale-nonce
        // cached page we still record the conversion, just without identity.
        if ($nonce_ok) {
            $beacon['user_data'] = array_merge(
                $beacon['user_data'],
                $this->server_identity()
            );
        }

        // Server-resolved IP + UA (authoritative, not from the body).
        $client_ip = $this->client_ip();
        $client_ua = $this->client_ua($request);

        $payload = DFSS_Event_Builder::build_from_beacon($beacon, $client_ip, $client_ua);
        if (null === $payload) {
            return new WP_REST_Response(array('ok' => false, 'reason' => 'unmappable'), 200);
        }

        $client = new DFSS_Client($opts['tenant_id'], $opts['hmac_secret'], $opts['endpoint']);
        $result = $client->send($payload);

        // Observability + reliability: every attempt is recorded; retryable
        // failures get queued for the cron to replay.
        DFSS_Queue::record_attempt($payload, $result, 'beacon');

        // Never leak the dispatcher message (could echo back input); just a flag.
        return new WP_REST_Response(array('ok' => !empty($result['ok'])), 200);
    }

    // --- sanitization --------------------------------------------------------

    /**
     * Strictly sanitize the raw beacon body into the shape build_from_beacon()
     * expects. Returns null if the essentials (event name + id) are missing.
     *
     * Every string goes through sanitize_text_field(wp_unslash()); URLs through
     * esc_url_raw; numbers are cast. Unknown keys are dropped (we only read the
     * fields we know). This is the trust boundary for hostile input.
     *
     * @param array $body
     *
     * @return array|null
     */
    private function sanitize_beacon(array $body)
    {
        $event_name = isset($body['event_name'])
            ? sanitize_key((string) $body['event_name'])
            : '';
        // Allowlist check happens in the builder; here we just need a value.
        if ($event_name === '') {
            return null;
        }

        $event_id = isset($body['event_id'])
            ? $this->sanitize_event_id($body['event_id'])
            : '';
        if ($event_id === '') {
            return null;
        }

        $source_url = isset($body['source_url'])
            ? esc_url_raw(wp_unslash((string) $body['source_url']))
            : '';
        // A page of THIS site, or the home page. The value is forwarded as the
        // event's page_location and shows up in GA4 as if the visit happened
        // there; a beacon naming another host would plant a foreign URL in the
        // merchant's reports (audit 2026-09-04, M5). Referrers are legitimately
        // external and stay untouched.
        if ($source_url !== '' && !$this->is_own_host($source_url)) {
            $source_url = home_url('/');
        }

        $page_referrer = isset($body['page_referrer'])
            ? esc_url_raw(wp_unslash((string) $body['page_referrer']))
            : '';

        $user_data = $this->sanitize_user_data(
            isset($body['user_data']) && is_array($body['user_data']) ? $body['user_data'] : array()
        );
        $event_data = $this->sanitize_event_data(
            isset($body['event_data']) && is_array($body['event_data']) ? $body['event_data'] : array()
        );

        return array(
            'event_name' => $event_name,
            'event_id' => $event_id,
            'source_url' => $source_url,
            'page_referrer' => $page_referrer,
            'user_data' => $user_data,
            'event_data' => $event_data,
        );
    }

    /**
     * event_id is our own UUID v4 or "order_<id>" — restrict to a safe charset
     * and the schema's 1..128 length so nothing weird reaches the dispatcher.
     *
     * @param mixed $value
     *
     * @return string '' if invalid.
     */
    private function sanitize_event_id($value)
    {
        if (!is_string($value)) {
            return '';
        }
        $value = trim($value);
        // Allow hyphenated hex (UUID) and the order_<id> form: [A-Za-z0-9_-].
        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/', $value)) {
            return '';
        }

        return $value;
    }

    /**
     * Sanitize the browser identifiers we accept from the body. We deliberately
     * do NOT accept email/externalId from the body — identity is server-trusted
     * (see server_identity()). Each value is a short opaque token.
     *
     * @param array $in
     *
     * @return array<string,string>
     */
    private function sanitize_user_data(array $in)
    {
        $out = array();
        // Cookie/click identifiers only. clientId is the GA _ga value; gclid is
        // the Google Ads click id (opaque token, like ttclid).
        foreach (array('fbp', 'fbc', 'ttp', 'ttclid', 'gclid', 'gbraid', 'wbraid', 'msclkid', 'oppref', 'obref', 'clientId', 'sessionId') as $key) {
            if (!empty($in[$key]) && is_scalar($in[$key])) {
                $val = sanitize_text_field(wp_unslash((string) $in[$key]));
                if ($val !== '' && strlen($val) <= 256) {
                    $out[$key] = $val;
                }
            }
        }

        return $out;
    }

    /**
     * Sanitize commerce context (currency/value/products/...). Caps the number
     * of products so a hostile payload can't blow up.
     *
     * @param array $in
     *
     * @return array<string,mixed>
     */
    private function sanitize_event_data(array $in)
    {
        $out = array();

        if (!empty($in['currency']) && is_scalar($in['currency'])) {
            $cur = strtoupper(sanitize_text_field(wp_unslash((string) $in['currency'])));
            if (preg_match('/^[A-Z]{3}$/', $cur)) {
                $out['currency'] = $cur;
            }
        }
        if (isset($in['value']) && $this->within($in['value'], self::MAX_VALUE)) {
            $out['value'] = (float) $in['value'];
        }
        // Net of tax. The purchase goes server-side and never through this
        // beacon, but the allow-list is the contract for every event: a field
        // missing here is dropped in silence, which is how a value that IS
        // being sent still never arrives.
        if (isset($in['valueNet']) && $this->within($in['valueNet'], self::MAX_VALUE)) {
            $out['valueNet'] = (float) $in['valueNet'];
        }
        // No orderId: the purchase is server-authoritative and never comes
        // through here (BEACON_EVENTS), so an order number in a beacon is a
        // claim nobody verified (audit 2026-09-04, M5). The builder's
        // allow-list dropped it too; both lists must agree.
        if (isset($in['numItems']) && $this->within($in['numItems'], self::MAX_ITEMS)) {
            $out['numItems'] = (int) $in['numItems'];
        }

        if (!empty($in['products']) && is_array($in['products'])) {
            $products = array();
            $count = 0;
            foreach ($in['products'] as $p) {
                if ($count >= self::MAX_PRODUCTS) {
                    break;
                }
                if (!is_array($p) || empty($p['id']) || !is_scalar($p['id'])) {
                    continue;
                }
                $line = array('id' => sanitize_text_field(wp_unslash((string) $p['id'])));
                if (!empty($p['name']) && is_scalar($p['name'])) {
                    $line['name'] = sanitize_text_field(wp_unslash((string) $p['name']));
                }
                if (!empty($p['category']) && is_scalar($p['category'])) {
                    $line['category'] = sanitize_text_field(wp_unslash((string) $p['category']));
                }
                if (isset($p['quantity']) && $this->within($p['quantity'], self::MAX_ITEMS)) {
                    $line['quantity'] = (float) $p['quantity'];
                }
                if (isset($p['price']) && $this->within($p['price'], self::MAX_VALUE)) {
                    $line['price'] = (float) $p['price'];
                }
                $products[] = $line;
                $count++;
            }
            if (!empty($products)) {
                $out['products'] = $products;
            }
        }

        // Merchandising context (view_item_list / select_item / view_promotion /
        // select_promotion). Short opaque labels — capped and text-sanitized.
        // Free-text context that is NOT a label: what the visitor searched for,
        // and how they got in touch. Capped, and the dispatcher redacts a term
        // that turns out to be an email address or a phone number.
        //
        // This allow-list is the contract for every event on this route: a
        // field missing here is dropped in SILENCE. The search term was sent
        // by the tracker and stored nowhere for exactly that reason.
        if (!empty($in['searchString']) && is_scalar($in['searchString'])) {
            $term = sanitize_text_field(wp_unslash((string) $in['searchString']));
            if ($term !== '') {
                $out['searchString'] = mb_substr($term, 0, 200);
            }
        }
        if (!empty($in['method']) && is_scalar($in['method'])) {
            $method = sanitize_text_field(wp_unslash((string) $in['method']));
            if ($method !== '') {
                $out['method'] = mb_substr($method, 0, 40);
            }
        }

        foreach (array('listId', 'listName', 'promotionId', 'promotionName', 'creativeName', 'creativeSlot') as $mk) {
            if (!empty($in[$mk]) && is_scalar($in[$mk])) {
                $val = sanitize_text_field(wp_unslash((string) $in[$mk]));
                if ($val !== '') {
                    $out[$mk] = mb_substr($val, 0, 200);
                }
            }
        }

        return $out;
    }

    // --- server-trusted context ---------------------------------------------

    /**
     * Identity from the server session only. For a logged-in WooCommerce/WP
     * user we attach the account email + id; otherwise nothing. Never read from
     * the browser body.
     *
     * @return array<string,string>
     */
    private function server_identity()
    {
        $out = array();
        $user_id = get_current_user_id();
        if ($user_id > 0) {
            $user = get_userdata($user_id);
            if ($user && is_email($user->user_email)) {
                $out['email'] = $user->user_email;
            }
            $out['externalId'] = (string) $user_id;
        }

        return $out;
    }

    /**
     * Best-effort client IP. We trust REMOTE_ADDR by default; behind a known
     * proxy WordPress sites typically populate it correctly via a must-use
     * plugin, so we do NOT blindly trust X-Forwarded-For (spoofable).
     *
     * Operators who DO run a trusted reverse proxy / CDN can supply the real
     * client IP via the `dfss_client_ip` filter, which improves both the rate
     * limit (per real visitor, not per proxy) and the CAPI IP match quality.
     *
     * @return string
     */
    private function client_ip()
    {
        $ip = isset($_SERVER['REMOTE_ADDR'])
            ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
            : '';

        /**
         * Filter the resolved client IP. Return a valid IP string to override
         * REMOTE_ADDR when behind a trusted proxy. NEVER read X-Forwarded-For
         * here without verifying the request actually came from your proxy.
         *
         * @param string $ip The IP resolved from REMOTE_ADDR.
         */
        $ip = (string) apply_filters('dfss_client_ip', $ip);

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    /**
     * @param WP_REST_Request $request
     *
     * @return string
     */
    private function client_ua($request)
    {
        $ua = $request->get_header('User-Agent');
        if (!$ua && isset($_SERVER['HTTP_USER_AGENT'])) {
            $ua = sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']));
        }
        $ua = sanitize_text_field((string) $ua);

        return substr($ua, 0, 512);
    }

    // --- rate limiting -------------------------------------------------------

    /**
     * Per-IP rolling-window rate limit using a transient counter, keyed by the
     * current time bucket so the window auto-rolls. Cheap, no table.
     *
     * A naive fixed window that re-extends its TTL on every hit would keep a
     * steady-traffic IP (or a shared NAT/CDN egress IP) blocked indefinitely once
     * it crossed the cap — silently dropping legitimate beacons. Bucketing the key
     * by floor(time()/window) means each window has its own counter that simply
     * expires, so a new window always starts clean.
     *
     * @return bool True if the request is within the limit.
     */
    private function rate_limit_ok()
    {
        $ip = $this->client_ip();
        if ($ip === '') {
            // No IP to key on — let it through (the nonce + size cap still apply).
            return true;
        }
        // The bucket id changes every RATE_LIMIT_WINDOW seconds; the previous
        // bucket's transient expires on its own.
        $bucket = (int) floor(time() / self::RATE_LIMIT_WINDOW);
        $key = 'dfss_rl_' . $bucket . '_' . md5($ip);
        $count = (int) get_transient($key);
        if ($count >= self::RATE_LIMIT_MAX) {
            return false;
        }
        // TTL covers the rest of this bucket plus one window of slack so the row
        // is reaped even if the next request lands at the bucket boundary.
        set_transient($key, $count + 1, self::RATE_LIMIT_WINDOW * 2);

        return true;
    }

    // --- helpers -------------------------------------------------------------

    /**
     * A finite number between 0 and $max inclusive. Anything else (negative,
     * INF/NAN, "1e300", or plainly out of bounds) fails and the caller drops
     * the field.
     *
     * @param mixed     $v
     * @param int|float $max
     *
     * @return bool
     */
    private function within($v, $max)
    {
        if (!is_numeric($v)) {
            return false;
        }
        $f = (float) $v;

        return is_finite($f) && $f >= 0 && $f <= $max;
    }

    /**
     * Is this URL on the site's own host (that of home_url())? Compared on
     * the host only, case-insensitively; scheme, port and path are free.
     *
     * @param string $url
     *
     * @return bool
     */
    private function is_own_host($url)
    {
        $host = wp_parse_url($url, PHP_URL_HOST);
        $own = wp_parse_url(home_url('/'), PHP_URL_HOST);
        if (!is_string($host) || $host === '' || !is_string($own) || $own === '') {
            return false;
        }

        return strtolower($host) === strtolower($own);
    }

    /**
     * @return array
     */
    private function opts()
    {
        return call_user_func($this->opts_provider);
    }
}
