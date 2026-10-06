<?php
/**
 * Event builder: maps orders and sanitized beacons to the dispatcher event shape.
 */
if (!defined('ABSPATH')) {
    exit;
}

class DFSS_Event_Builder
{
    /**
     * Events the public beacon endpoint accepts. No 'purchase': it is sent server-side from the
     * order hook, so it cannot be spoofed. The tracker reads this list and beacons nothing else.
     *
     * The events this list leaves out are NOT sent to the dispatcher ON PURPOSE (view_cart, contact, share,
     * add_to_wishlist, start_trial and the like): the dispatcher counts every event it receives against the
     * account's monthly quota, and each of these would also boot WordPress for a request the dispatcher can
     * only forward to GA4 and Meta, which the browser tags already receive. The dispatcher itself accepts
     * any snake_case name, so extending this list is a decision about volume and cost, not about what is
     * technically possible.
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
     * What the consent layer decided: 'granted' when gating is on (the beacon passed it), else
     * 'not_required'.
     *
     * @return string 'granted'|'not_required'
     */
    private static function consent_state()
    {
        $opts = get_option(DFSS_Plugin::OPTION, array());

        return DFSS_Consent::is_required(is_array($opts) ? $opts : array())
            ? 'granted'
            : 'not_required';
    }

    /**
     * Build a dispatcher event from a sanitized client beacon. Optional fields are added only when
     * valid: the dispatcher rejects the whole event on a single bad field.
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
        // Beacon-accepted events only: a beaconed 'purchase' is dropped (it is server-authoritative).
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
        if ($source_url === '' || !filter_var($source_url, FILTER_VALIDATE_URL)) {
            $source_url = home_url('/');
        }

        $payload = array(
            'eventId' => $event_id,
            'eventName' => $event_name,
            'eventTime' => time(),
            'sourceUrl' => $source_url,
            'actionSource' => 'website',
            'consent' => self::consent_state(),
            'userData' => self::beacon_user_data(
                isset($beacon['user_data']) && is_array($beacon['user_data']) ? $beacon['user_data'] : array(),
                (string) $client_ip,
                (string) $client_ua
            ),
        );

        // Referring URL, optional; only forward when it satisfies the dispatcher's z.string().url()
        // (pageReferrer).
        $page_referrer = isset($beacon['page_referrer']) ? (string) $beacon['page_referrer'] : '';
        if ($page_referrer !== '' && filter_var($page_referrer, FILTER_VALIDATE_URL)) {
            $payload['pageReferrer'] = $page_referrer;
        }

        // Consent Mode advanced: the GA4 tag already sent this event, the dispatcher must not send it
        // again by Measurement Protocol.
        if (!empty($beacon['browser_sent']) && is_array($beacon['browser_sent'])) {
            $payload['browserSent'] = array_values($beacon['browser_sent']);
        }

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
     * @param array  $in        Sanitized user_data from the beacon.
     * @param string $client_ip Server-resolved request IP.
     * @param string $client_ua Server-resolved request user agent.
     *
     * @return array<string,mixed>
     */
    private static function beacon_user_data(array $in, $client_ip, $client_ua)
    {
        $u = array();

        // Browser identifiers (cookies + click ids), passed raw end-to-end.
        foreach (array('fbp', 'fbc', 'ttp', 'ttclid', 'gclid', 'gbraid', 'wbraid', 'msclkid', 'oppref', 'obref', 'clientId', 'sessionId') as $key) {
            if (!empty($in[$key]) && is_string($in[$key])) {
                $u[$key] = $in[$key];
            }
        }

        // Server-trusted identity, injected by the REST layer for logged-in users only (never read
        // from the browser payload).
        if (!empty($in['email']) && is_string($in['email']) && is_email($in['email'])) {
            $u['email'] = $in['email'];
        }
        if (!empty($in['externalId']) && is_string($in['externalId'])) {
            $u['externalId'] = $in['externalId'];
        }

        // Server-resolved context, authoritative, not from the browser body.
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
        // A non-finite value (INF/NAN) would make the dispatcher reject the whole event.
        if (isset($in['value']) && self::is_finite_number($in['value']) && (float) $in['value'] >= 0) {
            $d['value'] = round((float) $in['value'], 2);
        }

        $products = array();
        if (!empty($in['products']) && is_array($in['products'])) {
            foreach ($in['products'] as $p) {
                if (!is_array($p) || empty($p['id'])) {
                    continue; // schema requires a product id
                }
                $line = array('id' => (string) $p['id']);
                $dfss_gid = self::canonical_product_id($line['id']);
                if ($dfss_gid !== '') {
                    $line['groupId'] = $dfss_gid;
                }
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

        // The visitor's own words, and how they got in touch.
        foreach (array('searchString' => 200, 'method' => 40) as $fk => $max) {
            if (!empty($in[$fk]) && is_string($in[$fk])) {
                $d[$fk] = mb_substr($in[$fk], 0, $max);
            }
        }

        // Merchandising context (view_item_list / select_item / view_promotion / select_promotion).
        foreach (array('listId', 'listName', 'promotionId', 'promotionName', 'creativeName', 'creativeSlot') as $mk) {
            if (!empty($in[$mk]) && is_string($in[$mk])) {
                $d[$mk] = mb_substr($in[$mk], 0, 200);
            }
        }

        return $d;
    }

    /**
     * The id to report for an order line: the variation when there is one, else the product.
     *
     * @param WC_Order_Item_Product $item
     *
     * @return string
     */
    public static function order_line_id($item)
    {
        $variation = (int) $item->get_variation_id();

        return (string) ($variation > 0 ? $variation : $item->get_product_id());
    }

    /**
     * The same record's id in the shop's DEFAULT language, when a translation layer splits one product
     * across several posts.
     *
     * @param string $id
     *
     * @return string
     */
    private static function canonical_product_id($id)
    {
        static $memo = array();

        $id = (string) $id;
        if ($id === '') {
            return '';
        }
        if (isset($memo[$id])) {
            return $memo[$id];
        }

        // Content lines carry a "<post_type>-<id>" id (see the content list in the main plugin file);
        // products carry a bare numeric one.
        $prefix = '';
        $post_id = 0;
        if (ctype_digit($id)) {
            $post_id = (int) $id;
        } elseif (preg_match('/^([A-Za-z0-9_]+)-(\d+)$/', $id, $m)) {
            $prefix = $m[1] . '-';
            $post_id = (int) $m[2];
        }
        if ($post_id <= 0) {
            $memo[$id] = '';

            return '';
        }

        $canonical = 0;

        // Polylang.
        if (function_exists('pll_get_post') && function_exists('pll_default_language')) {
            $default = pll_default_language();
            if (is_string($default) && $default !== '') {
                $found = pll_get_post($post_id, $default);
                if (is_numeric($found) && (int) $found > 0) {
                    $canonical = (int) $found;
                }
            }
        }

        // WPML. `true` returns the original when there is no translation. These two filters are
        // WPML's public API, not hooks of ours, hence the prefix sniff is disabled.
        // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
        if ($canonical === 0 && has_filter('wpml_object_id')) {
            $default = apply_filters('wpml_default_language', null);
            $type = get_post_type($post_id);
            if (is_string($default) && $default !== '' && is_string($type) && $type !== '') {
                $found = apply_filters('wpml_object_id', $post_id, $type, true, $default);
                if (is_numeric($found) && (int) $found > 0) {
                    $canonical = (int) $found;
                }
            }
        }
        // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

        if ($canonical === 0 || $canonical === $post_id) {
            $memo[$id] = ''; // nothing to fold

            return '';
        }

        $memo[$id] = $prefix . (string) $canonical;

        return $memo[$id];
    }

    /**
     * True if the value is numeric AND finite (not INF/NAN).
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
     * Build the `refund` event: no personal data, positive amount, keyed on the refund id.
     *
     * @param WC_Order_Refund $refund
     * @param WC_Order        $order
     *
     * @return array|null
     */
    public static function build_refund($refund, $order)
    {
        if (!is_a($refund, 'WC_Order_Refund') || !$order instanceof WC_Order) {
            return null;
        }

        $amount = abs((float) $refund->get_amount());
        if ($amount <= 0) {
            return null;
        }

        $created = $refund->get_date_created();
        $event_time = $created ? $created->getTimestamp() : time();

        return array(
            // The refund id, not the order id: two partial refunds on one order are two real events.
            'eventId' => 'refund_' . (int) $refund->get_id(),
            'eventName' => 'refund',
            'eventTime' => $event_time,
            'sourceUrl' => home_url('/'),
            // Not 'website': nobody was on the site.
            'actionSource' => 'system_generated',
            // No personal data and no visitor to ask, so nothing for a consent gate to decide.
            'consent' => 'not_required',
            'eventData' => array(
                'value' => round($amount, 2),
                'currency' => $order->get_currency(),
                'orderId' => (string) $order->get_order_number(),
            ),
        );
    }

    /**
     * Build the purchase event. A refused consent strips the personal data but still reports the
     * sale, with consent 'denied', so the dispatcher records it without forwarding it.
     *
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

        $verdict = self::purchase_consent($order);

        $payload = array(
            'eventId' => 'order_' . $order->get_id(),
            'eventName' => 'purchase',
            'eventTime' => $event_time,
            'sourceUrl' => home_url('/'),
            'actionSource' => 'website',
            'userData' => $verdict === 'denied' ? array() : self::user_data($order),
            'consent' => $verdict,
        );

        $event_data = self::event_data($order);
        if (!empty($event_data)) {
            $payload['eventData'] = $event_data;
        }

        return $payload;
    }

    /**
     * The consent verdict that applies to an order's purchase event.
     *
     * @param WC_Order $order
     *
     * @return string 'granted'|'denied'|'not_required'
     */
    private static function purchase_consent($order)
    {
        $opts = get_option(DFSS_Plugin::OPTION, array());
        $opts = is_array($opts) ? $opts : array();
        if (!DFSS_Consent::is_required($opts)) {
            return 'not_required';
        }

        $stored = (string) $order->get_meta('_dfss_consent');
        if ($stored === 'granted' || $stored === 'denied') {
            return $stored;
        }

        if (is_admin() || (function_exists('wp_doing_cron') && wp_doing_cron())) {
            return 'denied';
        }

        return DFSS_Consent::server_verdict($opts);
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
        // Click identifiers captured at checkout (see capture_cookies()).
        foreach (array('ttclid', 'gclid', 'gbraid', 'wbraid', 'msclkid') as $click_id) {
            $value = $order->get_meta('_dfss_' . $click_id);
            if ($value) {
                $u[$click_id] = $value;
            }
        }
        // ChatGPT Ads attribution ids, captured at checkout (see capture_cookies()).
        $oppref = $order->get_meta('_dfss_oppref');
        if ($oppref) {
            $u['oppref'] = $oppref;
        }
        $obref = $order->get_meta('_dfss_obref');
        if ($obref) {
            $u['obref'] = $obref;
        }
        $client_id = self::ga_client_id($order->get_meta('_dfss_ga'));
        if ($client_id !== '') {
            $u['clientId'] = $client_id;
        }
        // GA4 session id captured at checkout (_ga_<container> cookie).
        $session_id = self::ga_session_id($order->get_meta('_dfss_ga_session'));
        if ($session_id !== '') {
            $u['sessionId'] = $session_id;
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
        // The same order net of tax.
        $d['valueNet'] = round((float) $order->get_total() - (float) $order->get_total_tax(), 2);
        $d['orderId'] = (string) $order->get_order_number();

        $products = array();
        $num_items = 0;
        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }
            $qty = (int) $item->get_quantity();
            $num_items += $qty;
            $line = array('id' => self::order_line_id($item));
            $dfss_gid = self::canonical_product_id($line['id']);
            if ($dfss_gid !== '') {
                $line['groupId'] = $dfss_gid;
            }
            $name = $item->get_name();
            if ($name) {
                $line['name'] = $name;
            }
            if ($qty > 0) {
                $line['quantity'] = $qty;
            }
            // WC_Order::get_total() is the order total WITH tax; on an order ITEM the identically-
            // named method is the line NET of tax.
            $line_total = (float) $item->get_total() + (float) $item->get_total_tax();
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

    /**
     * Extract the GA4 session id from the _ga_<container> cookie value.
     *
     * @param mixed $gs
     *
     * @return string '' if invalid.
     */
    private static function ga_session_id($gs)
    {
        if (!is_string($gs) || $gs === '') {
            return '';
        }
        if (preg_match('/^GS\d\.\d+\.s?(\d+)/', $gs, $m)) {
            return $m[1];
        }

        return '';
    }
}
