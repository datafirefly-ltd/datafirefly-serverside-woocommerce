<?php
/**
 * DataFirefly Server-Side (WooCommerce) — event builder.
 *
 * Maps a WooCommerce order to the dispatcher's IncomingEvent shape. Built
 * defensively: each optional field is added only when present and valid,
 * because the dispatcher validates strictly (Zod) and rejects the whole event
 * on a single bad field (email format, country 2 chars, currency 3 chars).
 *
 * Browser identifiers (_fbp/_fbc/_ga/_ttp) are read from order meta captured at
 * checkout — the purchase hook can fire from a gateway callback with no cookie
 * context, so we never rely on $_COOKIE here.
 */
if (!defined('ABSPATH')) {
    exit;
}

class DFSS_Event_Builder
{
    /**
     * The event names the PUBLIC beacon endpoint accepts from the browser.
     *
     * Deliberately EXCLUDES 'purchase': the purchase conversion is delivered
     * server-side by the authoritative WooCommerce order hook (build_purchase —
     * not spoofable) and client-side by the injected pixel, both keyed on
     * "order_<id>" so they deduplicate. Accepting 'purchase' on a public,
     * soft-nonce endpoint would let anyone inject fake conversions — inflating a
     * merchant's Meta/GA revenue and poisoning ad optimization. It also omits the
     * events the tracker never emits (lead/complete_registration/search), keeping
     * the public attack surface to exactly the top-of-funnel events we send.
     *
     * Note: the spec's "view_item" maps to the schema's "view_content".
     *
     * @var string[]
     */
    const BEACON_EVENTS = array(
        'page_view',
        'view_content',
        'view_item_list',
        'select_item',
        'add_to_cart',
        'initiate_checkout',
        'add_payment_info',
        'view_promotion',
        'select_promotion',
        'lead',
        'complete_registration',
        'search',
    );

    /**
     * Build a dispatcher event from a sanitized client beacon.
     *
     * The REST endpoint (class-dfss-rest.php) sanitizes every raw field before
     * calling this; here we shape + validate into the dispatcher's IncomingEvent
     * with the SAME defensive discipline as build_purchase(): each optional
     * field is added only when present and valid, because the dispatcher
     * validates strictly (Zod) and rejects the whole event on a single bad field.
     *
     * The full server context (IP, user agent) is added server-side here so the
     * browser never has to send it — and so it is always present even when the
     * beacon is sparse.
     *
     * @param array  $beacon Sanitized beacon: {
     *     event_id, event_name, source_url, user_data:array, event_data:array }
     * @param string $client_ip   Resolved server-side (REST request IP).
     * @param string $client_ua   Resolved server-side (request user agent).
     *
     * @return array|null Null if the beacon can't be mapped (caller drops it).
     */
    public static function build_from_beacon(array $beacon, $client_ip = '', $client_ua = '')
    {
        $event_name = isset($beacon['event_name']) ? (string) $beacon['event_name'] : '';
        // Beacon-accepted events only — purchase is server-authoritative (see
        // BEACON_EVENTS), so a beaconed 'purchase' is dropped here.
        if (!in_array($event_name, self::BEACON_EVENTS, true)) {
            return null;
        }

        $event_id = isset($beacon['event_id']) ? (string) $beacon['event_id'] : '';
        // eventId must be 1-128 chars per schema; bail rather than send junk.
        $len = strlen($event_id);
        if ($len < 1 || $len > 128) {
            return null;
        }

        $source_url = isset($beacon['source_url']) ? (string) $beacon['source_url'] : '';
        // sourceUrl must satisfy the dispatcher's z.string().url(); validate the
        // shape (not reachability — we never fetch it) and fall back to home.
        if ($source_url === '' || !filter_var($source_url, FILTER_VALIDATE_URL)) {
            $source_url = home_url('/');
        }

        $payload = array(
            'eventId' => $event_id,
            'eventName' => $event_name,
            'eventTime' => time(),
            'sourceUrl' => $source_url,
            'actionSource' => 'website',
            'userData' => self::beacon_user_data(
                isset($beacon['user_data']) && is_array($beacon['user_data']) ? $beacon['user_data'] : array(),
                (string) $client_ip,
                (string) $client_ua
            ),
        );

        $event_data = self::beacon_event_data(
            isset($beacon['event_data']) && is_array($beacon['event_data']) ? $beacon['event_data'] : array()
        );
        if (!empty($event_data)) {
            $payload['eventData'] = $event_data;
        }

        return $payload;
    }

    /**
     * Shape the userData object of a beacon into schema-valid fields.
     *
     * Only the dispatcher's known userData keys are emitted. The browser never
     * sends PII for top-of-funnel events (we have no email until checkout), so
     * this is mostly browser identifiers + the server-resolved IP/UA. If a
     * logged-in customer is known, the REST layer may inject email/externalId
     * (it does so server-side, never trusting the browser for identity).
     *
     * @param array  $in        Sanitized user_data from the beacon.
     * @param string $client_ip Server-resolved request IP.
     * @param string $client_ua Server-resolved request user agent.
     *
     * @return array<string,mixed>
     */
    private static function beacon_user_data(array $in, $client_ip, $client_ua)
    {
        $u = array();

        // Browser identifiers (cookies + click ids) — passed raw end-to-end.
        // Each is a free string in the schema; only emit when non-empty.
        // gclid is the Google Ads click id (opaque token, like ttclid).
        foreach (array('fbp', 'fbc', 'ttp', 'ttclid', 'gclid', 'clientId') as $key) {
            if (!empty($in[$key]) && is_string($in[$key])) {
                $u[$key] = $in[$key];
            }
        }

        // Server-trusted identity, injected by the REST layer for logged-in
        // users only (never read from the browser payload).
        if (!empty($in['email']) && is_string($in['email']) && is_email($in['email'])) {
            $u['email'] = $in['email'];
        }
        if (!empty($in['externalId']) && is_string($in['externalId'])) {
            $u['externalId'] = $in['externalId'];
        }

        // Server-resolved context — authoritative, not from the browser body.
        if ($client_ua !== '') {
            $u['clientUserAgent'] = $client_ua;
        }
        if ($client_ip !== '') {
            $u['clientIpAddress'] = $client_ip;
        }

        return $u;
    }

    /**
     * Shape the eventData object of a beacon into schema-valid fields.
     *
     * @param array $in Sanitized event_data from the beacon.
     *
     * @return array<string,mixed>
     */
    private static function beacon_event_data(array $in)
    {
        $d = array();

        if (!empty($in['currency']) && is_string($in['currency']) && strlen($in['currency']) === 3) {
            $d['currency'] = strtoupper($in['currency']);
        }
        // Guard against INF/NAN (e.g. "1e400") — the dispatcher's z.number()
        // rejects non-finite values and would drop the WHOLE event.
        if (isset($in['value']) && self::is_finite_number($in['value']) && (float) $in['value'] >= 0) {
            $d['value'] = round((float) $in['value'], 2);
        }
        if (!empty($in['orderId']) && is_string($in['orderId'])) {
            $d['orderId'] = $in['orderId'];
        }

        $products = array();
        if (!empty($in['products']) && is_array($in['products'])) {
            foreach ($in['products'] as $p) {
                if (!is_array($p) || empty($p['id'])) {
                    continue; // schema requires a product id
                }
                $line = array('id' => (string) $p['id']);
                if (!empty($p['name']) && is_string($p['name'])) {
                    $line['name'] = $p['name'];
                }
                if (!empty($p['category']) && is_string($p['category'])) {
                    $line['category'] = $p['category'];
                }
                if (isset($p['quantity']) && self::is_finite_number($p['quantity']) && (float) $p['quantity'] > 0) {
                    $line['quantity'] = (float) $p['quantity'];
                }
                if (isset($p['price']) && self::is_finite_number($p['price']) && (float) $p['price'] >= 0) {
                    $line['price'] = round((float) $p['price'], 2);
                }
                $products[] = $line;
            }
        }
        if (!empty($products)) {
            $d['products'] = $products;
        }

        if (isset($in['numItems']) && self::is_finite_number($in['numItems']) && (int) $in['numItems'] >= 0) {
            $d['numItems'] = (int) $in['numItems'];
        }

        // Merchandising context (view_item_list / select_item / view_promotion /
        // select_promotion). GA4-native list & promotion reporting; other
        // destinations ignore these keys.
        foreach (array('listId', 'listName', 'promotionId', 'promotionName', 'creativeName', 'creativeSlot') as $mk) {
            if (!empty($in[$mk]) && is_string($in[$mk])) {
                $d[$mk] = mb_substr($in[$mk], 0, 200);
            }
        }

        return $d;
    }

    /**
     * True if the value is numeric AND finite (not INF/NAN). The dispatcher's
     * z.number() rejects non-finite values; a single one rejects the whole event.
     *
     * @param mixed $v
     *
     * @return bool
     */
    private static function is_finite_number($v)
    {
        return is_numeric($v) && is_finite((float) $v);
    }

    /**
     * @param WC_Order $order
     *
     * @return array|null Null if the order can't be mapped (caller skips send).
     */
    public static function build_purchase($order)
    {
        if (!$order instanceof WC_Order) {
            return null;
        }

        $created = $order->get_date_created();
        $event_time = $created ? $created->getTimestamp() : time();

        $payload = array(
            'eventId' => 'order_' . $order->get_id(),
            'eventName' => 'purchase',
            'eventTime' => $event_time,
            'sourceUrl' => home_url('/'),
            'actionSource' => 'website',
            'userData' => self::user_data($order),
        );

        $event_data = self::event_data($order);
        if (!empty($event_data)) {
            $payload['eventData'] = $event_data;
        }

        return $payload;
    }

    /**
     * @param WC_Order $order
     *
     * @return array<string,mixed>
     */
    private static function user_data($order)
    {
        $u = array();

        $email = $order->get_billing_email();
        if ($email && is_email($email)) {
            $u['email'] = $email;
        }
        $customer_id = (int) $order->get_customer_id();
        if ($customer_id > 0) {
            $u['externalId'] = (string) $customer_id;
        }

        $phone = $order->get_billing_phone();
        if ($phone) {
            $u['phone'] = $phone;
        }
        $first = $order->get_billing_first_name();
        if ($first) {
            $u['firstName'] = $first;
        }
        $last = $order->get_billing_last_name();
        if ($last) {
            $u['lastName'] = $last;
        }
        $city = $order->get_billing_city();
        if ($city) {
            $u['city'] = $city;
        }
        $zip = $order->get_billing_postcode();
        if ($zip) {
            $u['zipCode'] = $zip;
        }
        $country = $order->get_billing_country();
        if ($country && strlen($country) === 2) {
            $u['country'] = strtoupper($country);
        }

        // WooCommerce stores these on the order itself.
        $ua = $order->get_customer_user_agent();
        if ($ua) {
            $u['clientUserAgent'] = $ua;
        }
        $ip = $order->get_customer_ip_address();
        if ($ip) {
            $u['clientIpAddress'] = $ip;
        }

        // Browser cookies captured at checkout (order meta).
        $fbp = $order->get_meta('_dfss_fbp');
        if ($fbp) {
            $u['fbp'] = $fbp;
        }
        $fbc = $order->get_meta('_dfss_fbc');
        if ($fbc) {
            $u['fbc'] = $fbc;
        }
        $ttp = $order->get_meta('_dfss_ttp');
        if ($ttp) {
            $u['ttp'] = $ttp;
        }
        // Google Ads click id, captured at checkout (see capture_cookies()).
        $gclid = $order->get_meta('_dfss_gclid');
        if ($gclid) {
            $u['gclid'] = $gclid;
        }
        $client_id = self::ga_client_id($order->get_meta('_dfss_ga'));
        if ($client_id !== '') {
            $u['clientId'] = $client_id;
        }

        return $u;
    }

    /**
     * @param WC_Order $order
     *
     * @return array<string,mixed>
     */
    private static function event_data($order)
    {
        $d = array();

        $currency = $order->get_currency();
        if ($currency && strlen($currency) === 3) {
            $d['currency'] = strtoupper($currency);
        }
        $d['value'] = round((float) $order->get_total(), 2);
        $d['orderId'] = (string) $order->get_order_number();

        $products = array();
        $num_items = 0;
        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }
            $qty = (int) $item->get_quantity();
            $num_items += $qty;
            $line = array('id' => (string) $item->get_product_id());
            $name = $item->get_name();
            if ($name) {
                $line['name'] = $name;
            }
            if ($qty > 0) {
                $line['quantity'] = $qty;
            }
            $line_total = (float) $item->get_total();
            $line['price'] = $qty > 0 ? round($line_total / $qty, 2) : round($line_total, 2);
            $products[] = $line;
        }
        if (!empty($products)) {
            $d['products'] = $products;
            $d['numItems'] = $num_items;
        }

        return $d;
    }

    /**
     * Extract the GA4 client id from the _ga cookie value.
     * "GA1.2.123456789.1620000000" -> "123456789.1620000000".
     *
     * @param mixed $ga
     */
    private static function ga_client_id($ga)
    {
        if (!is_string($ga) || $ga === '') {
            return '';
        }
        $parts = explode('.', $ga);
        if (count($parts) < 4) {
            return '';
        }

        return $parts[count($parts) - 2] . '.' . $parts[count($parts) - 1];
    }
}
