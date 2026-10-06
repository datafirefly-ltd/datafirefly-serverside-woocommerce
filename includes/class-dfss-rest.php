<?php
/**
 * Public beacon endpoint (POST /wp-json/dfss/v1/collect): sanitizes, signs and forwards browser events.
 */
if (!defined('ABSPATH')) {
    exit;
}

class DFSS_REST
{
    const REST_NAMESPACE = 'dfss/v1';
    const ROUTE = '/collect';

    // Hard caps to keep hostile payloads cheap to reject.
    const MAX_BODY_BYTES = 16384;     // one event
    const MAX_BATCH = 10;             // events per batched request
    const MAX_BATCH_BYTES = 65536;    // whole batched request
    const MAX_PRODUCTS = 50;
    // Generous on purpose: one shopper fires several events a minute, and a NAT or CDN egress IP
    // is shared by many. The nonce, size caps and sanitization are the real abuse guards.
    const RATE_LIMIT_MAX = 120;       // events...
    const RATE_LIMIT_WINDOW = 60;     // ...per this many seconds, per IP
    // Above these bounds a field is dropped, never clamped: a clamped figure is still fabricated.
    const MAX_VALUE = 10000000;       // 1e7, in the shop currency
    const MAX_ITEMS = 10000;

    /**
     * Events accepted only with a valid wp_rest nonce: they have no page context to check against,
     * so a forged beacon would cost nothing.
     *
     * @var string[]
     */
    const NONCE_REQUIRED_EVENTS = array('lead', 'complete_registration', 'add_payment_info');

    /**
     * @var callable():array A provider returning the current plugin options.
     */
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
        // Fresh nonce for pages served from a full-page cache, whose baked-in nonce goes stale.
        // Never cached; asked once, on the visitor's first interaction.
        register_rest_route(
            self::REST_NAMESPACE,
            '/nonce',
            array(
                'methods' => 'GET',
                'callback' => array($this, 'handle_nonce'),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            self::REST_NAMESPACE,
            self::ROUTE,
            array(
                'methods' => 'POST',
                'callback' => array($this, 'handle_collect'),
                'permission_callback' => array($this, 'permission_check'),
                // No args schema: the JSON body is sanitized field by field in the handler.
            )
        );
    }

    /**
     * Permission gate: connected, complete tracking on, within the per-IP rate limit.
     *
     * A missing or stale nonce is not rejected here (full-page caches would lose the funnel): it only
     * withholds identity, and NONCE_REQUIRED_EVENTS are refused in the handler.
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
     * Whether the request carries a valid wp_rest nonce.
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
     * GET /nonce: a fresh wp_rest nonce, never cacheable.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_REST_Response
     */
    public function handle_nonce($request)
    {
        $response = new WP_REST_Response(array('nonce' => wp_create_nonce('wp_rest')), 200);
        $response->header('Cache-Control', 'no-store, max-age=0');
        $response->header('X-LiteSpeed-Cache-Control', 'no-cache');

        return $response;
    }

    /**
     * POST /collect: one event, or a batch {"events": [...]} sent by the tracker for page-load
     * events, so a page costs one WordPress boot instead of one per event.
     *
     * Always answers 200: a tracking error must never surface to the visitor. Each event is sent
     * and recorded on its own, retryable failures go to the queue.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_REST_Response
     */
    public function handle_collect($request)
    {
        $opts = $this->opts();

        $raw = (string) $request->get_body();
        if (strlen($raw) > self::MAX_BATCH_BYTES) {
            return new WP_REST_Response(array('ok' => false, 'reason' => 'too_large'), 200);
        }

        $body = json_decode($raw, true);
        if (!is_array($body)) {
            return new WP_REST_Response(array('ok' => false, 'reason' => 'bad_json'), 200);
        }

        if (isset($body['events']) && is_array($body['events'])) {
            $events = array_slice(array_values($body['events']), 0, self::MAX_BATCH);
            // permission_check() counted one request: count the rest of the batch too.
            if (count($events) > 1 && !$this->rate_limit_ok(count($events) - 1)) {
                return new WP_REST_Response(array('ok' => false, 'reason' => 'rate_limited'), 200);
            }
        } else {
            if (strlen($raw) > self::MAX_BODY_BYTES) {
                return new WP_REST_Response(array('ok' => false, 'reason' => 'too_large'), 200);
            }
            $events = array($body);
        }

        // Server-side consent gate (defense in depth; the client already gated).
        if (!DFSS_Consent::has_consent($opts)) {
            return new WP_REST_Response(array('ok' => false, 'reason' => 'no_consent'), 200);
        }

        $context = array(
            'nonce_ok' => $this->nonce_is_valid($request),
            'ip' => $this->client_ip(),
            'ua' => $this->client_ua($request),
            'client' => new DFSS_Client($opts['tenant_id'], $opts['hmac_secret'], $opts['endpoint']),
        );

        $ok = false;
        $reason = '';
        foreach ($events as $event) {
            $result = is_array($event) ? $this->collect_one($event, $context) : 'invalid';
            if ($result === true) {
                $ok = true;
            } elseif ($reason === '' && is_string($result)) {
                $reason = $result;
            }
        }

        // Never leak the dispatcher message (could echo back input); a flag and a reason key only.
        return new WP_REST_Response($ok || $reason === '' ? array('ok' => $ok) : array('ok' => false, 'reason' => $reason), 200);
    }

    /**
     * Sanitize, check, build, send and record one event.
     *
     * @param array $body    Raw event from the request body.
     * @param array $context {nonce_ok, ip, ua, client}.
     *
     * @return true|string|false True when delivered, a reason key when refused, false when the
     *                           dispatcher did not accept it (queued if retryable).
     */
    private function collect_one(array $body, array $context)
    {
        $beacon = $this->sanitize_beacon($body);
        if ($beacon === null) {
            return 'invalid';
        }
        if (!$context['nonce_ok'] && in_array($beacon['event_name'], self::NONCE_REQUIRED_EVENTS, true)) {
            return 'nonce';
        }

        // Identity comes from the server session only, and only on a verified same-origin request.
        if ($context['nonce_ok']) {
            $beacon['user_data'] = array_merge($beacon['user_data'], $this->server_identity());
        }

        $payload = DFSS_Event_Builder::build_from_beacon($beacon, $context['ip'], $context['ua']);
        if (null === $payload) {
            return 'unmappable';
        }

        $result = $context['client']->send($payload);
        DFSS_Queue::record_attempt($payload, $result, 'beacon');

        return !empty($result['ok']) ? true : false;
    }

    // ---- sanitization ------------------------------------------------------

    /**
     * Strictly sanitize the raw beacon body into the shape build_from_beacon() expects.
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
        // A page of THIS site, or the home page.
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
            'browser_sent' => $this->sanitize_browser_sent($body),
        );
    }

    /**
     * Destinations the storefront tag already served (Consent Mode advanced).
     *
     * @param array $body
     *
     * @return string[]
     */
    private function sanitize_browser_sent(array $body)
    {
        $in = isset($body['browser_sent']) && is_array($body['browser_sent']) ? $body['browser_sent'] : array();

        return array_values(array_intersect(array('ga4'), array_map('strval', $in)));
    }

    /**
     * event_id is our own UUID v4 or "order_<id>", restrict to a safe charset and the schema's 1..128
     * length so nothing weird reaches the dispatcher.
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
     * Sanitize the browser identifiers we accept from the body.
     *
     * @param array $in
     *
     * @return array<string,string>
     */
    private function sanitize_user_data(array $in)
    {
        $out = array();
        // Cookie/click identifiers only. clientId is the GA _ga value; gclid is the Google Ads click
        // id (opaque token, like ttclid).
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
     * Sanitize commerce context (currency/value/products/...).
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
        // Net of tax.
        if (isset($in['valueNet']) && $this->within($in['valueNet'], self::MAX_VALUE)) {
            $out['valueNet'] = (float) $in['valueNet'];
        }
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

        // Merchandising context (view_item_list / select_item / view_promotion / select_promotion).
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

    // ---- server-trusted context --------------------------------------------

    /**
     * Identity from the server session only.
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
     * Best-effort client IP. We trust REMOTE_ADDR by default; behind a known proxy WordPress sites
     * typically populate it correctly via a must-use plugin, so we do NOT blindly trust X-Forwarded-
     * For (spoofable).
     *
     * @return string
     */
    private function client_ip()
    {
        $ip = isset($_SERVER['REMOTE_ADDR'])
            ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
            : '';

        /**
         * Filter the resolved client IP. Return a valid IP string to override REMOTE_ADDR when behind
         * a trusted proxy.
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

    // ---- rate limiting -----------------------------------------------------

    /**
     * Per-IP rate limit: a transient counter keyed by the current time bucket, so each window starts
     * clean instead of a busy shared IP staying blocked.
     *
     * @param int $weight Number of events to count.
     *
     * @return bool True if the request is within the limit.
     */
    private function rate_limit_ok($weight = 1)
    {
        $ip = $this->client_ip();
        if ($ip === '') {
            // No IP to key on, let it through (the nonce + size cap still apply).
            return true;
        }
        // The bucket id changes every RATE_LIMIT_WINDOW seconds; the previous bucket's transient
        // expires on its own.
        $bucket = (int) floor(time() / self::RATE_LIMIT_WINDOW);
        $key = 'dfss_rl_' . $bucket . '_' . md5($ip);
        $count = (int) get_transient($key);
        if ($count >= self::RATE_LIMIT_MAX) {
            return false;
        }
        set_transient($key, $count + max(1, (int) $weight), self::RATE_LIMIT_WINDOW * 2);

        return true;
    }

    // ---- helpers -----------------------------------------------------------

    /**
     * A finite number between 0 and $max inclusive.
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
     * Is this URL on the site's own host (that of home_url())?
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
