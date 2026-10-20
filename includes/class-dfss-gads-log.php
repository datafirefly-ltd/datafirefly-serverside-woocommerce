<?php
/**
 * Google Ads conversion log (2.31.0).
 *
 * One row per order: what Google Ads should show for it, and why not when it
 * will not. The question it answers is the merchant's own: "how many
 * conversions am I supposed to see in Google Ads?"
 *
 * Ported from the PrestaShop module (DfSsGadsLog, 1.32.1), with the same
 * statuses and the same reading of the dispatcher's answer: a purchase with no
 * click id is accepted with HTTP 200 and then skipped for Google, which from
 * the shop looks exactly like a success. The dispatcher says, in its answer,
 * what each destination did with the event; this class reads that answer
 * instead of guessing.
 *
 * Two differences with PrestaShop, both because WooCommerce already knows:
 *   - a refused purchase is sent anyway, marked "denied" and without user
 *     data, so its status comes from the dispatcher's answer like any other;
 *   - "came from an ad" is read from WooCommerce's own order attribution
 *     (source google, a paid medium), stored on every order by WooCommerce
 *     when the feature is on. Nothing new is stored about the visitor.
 *
 * PRIVACY: no customer, no email, no click id value. The order id, its amount,
 * the consent verdict and the KIND of click id are enough to count.
 *
 * @package DataFirefly_Server_Side
 */

if (!defined('ABSPATH')) {
    exit;
}

class DFSS_Gads_Log
{
    /** Accepted by Google with consent: should show up in Google Ads. */
    const EXPECTED = 'expected';

    /** Accepted by Google with no consent signal: Google may not use it. */
    const SENT_NO_CONSENT = 'sent_no_consent';

    /** No gclid / gbraid / wbraid: Google cannot attribute it, not sent. */
    const NO_CLICK_ID = 'no_click_id';

    /** Click id present but skipped anyway, or excluded by a rule of the site. */
    const GOOGLE_SKIPPED = 'google_skipped';

    /** Google refused the upload (the dispatcher retries it on its side). */
    const GOOGLE_ERROR = 'google_error';

    /** Google Ads is not a destination of this site. */
    const NO_GOOGLE = 'no_google';

    /** The shopper refused (or never gave) marketing consent: recorded, sent nowhere. */
    const CONSENT_REFUSED = 'consent_refused';

    /** Dispatcher unreachable: waiting in the retry queue. */
    const QUEUED = 'queued';

    /** The dispatcher refused the event, or the queue gave up on it. */
    const ERROR = 'error';

    /** Free allowance used up: received, counted, not delivered. */
    const QUOTA_PAUSED = 'quota_paused';

    /** The dispatcher answered something this version cannot read. */
    const UNKNOWN = 'unknown';

    /** Rows older than this are deleted: a year and a month, to compare a year on year. */
    const RETENTION_DAYS = 400;

    /** The value stored when WooCommerce's attribution says the order came from a Google ad. */
    const AD_GOOGLE_PAID = 'google_cpc';

    /**
     * @return string
     */
    public static function table()
    {
        global $wpdb;

        return $wpdb->prefix . 'dfss_gads_log';
    }

    /**
     * Create or migrate the table. Idempotent (dbDelta), run on activation and
     * on every version change (DFSS_Plugin::maybe_upgrade()).
     */
    public static function install()
    {
        global $wpdb;

        $table = self::table();
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            order_id BIGINT(20) UNSIGNED NOT NULL,
            reference VARCHAR(40) NOT NULL DEFAULT '',
            order_date DATETIME NOT NULL,
            value DECIMAL(20,2) NOT NULL DEFAULT 0,
            currency VARCHAR(3) NOT NULL DEFAULT '',
            consent VARCHAR(16) NOT NULL DEFAULT '',
            click_id VARCHAR(8) NOT NULL DEFAULT '',
            ad_click VARCHAR(16) NOT NULL DEFAULT '',
            has_email TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(24) NOT NULL DEFAULT '',
            detail VARCHAR(255) NOT NULL DEFAULT '',
            date_upd DATETIME NOT NULL,
            PRIMARY KEY  (order_id),
            KEY order_date (order_date)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    // ---- pure: no database (tests/test-gads-log.php) ---------------------------------------

    /**
     * Which click id the event carries: 'gclid', 'wbraid', 'gbraid' or ''.
     * Same preference order as the dispatcher's resolveAdIdentifiers().
     *
     * @param array $payload
     *
     * @return string
     */
    public static function click_id_kind(array $payload)
    {
        $u = isset($payload['userData']) && is_array($payload['userData']) ? $payload['userData'] : array();
        foreach (array('gclid', 'wbraid', 'gbraid') as $kind) {
            if (isset($u[$kind]) && is_string($u[$kind]) && trim($u[$kind]) !== '') {
                return $kind;
            }
        }

        return '';
    }

    /**
     * The click id inside a Google tag cookie ("GCL.<timestamp>.<id>"), or ''.
     * Same rule as the tracker's dfssGclCookieValue() and the PrestaShop
     * module: anything after the id is dropped, the dispatcher checks the length.
     *
     * @param mixed $raw
     *
     * @return string
     */
    public static function gcl_cookie_value($raw)
    {
        return is_string($raw) && preg_match('/^GCL\.\d+\.([A-Za-z0-9_-]+)/', $raw, $m) === 1 ? $m[1] : '';
    }

    /**
     * Whether WooCommerce's own order attribution says the order came from a
     * Google ad: source google and a paid medium (cpc, ppc, paid...), the way
     * GA4 reads "Paid Search". Sourcebuster, which feeds it, turns a gclid
     * into google / cpc; it knows nothing of gbraid, wbraid or gad_source.
     *
     * @param string $source_type _wc_order_attribution_source_type
     * @param string $source      _wc_order_attribution_utm_source
     * @param string $medium      _wc_order_attribution_utm_medium
     *
     * @return string self::AD_GOOGLE_PAID or ''
     */
    public static function ad_click_from_attribution($source_type, $source, $medium)
    {
        if ((string) $source_type !== 'utm' || strtolower(trim((string) $source)) !== 'google') {
            return '';
        }

        return preg_match('/^(.*cp.*|ppc|paid.*)$/', strtolower(trim((string) $medium))) === 1 ? self::AD_GOOGLE_PAID : '';
    }

    /**
     * What happened to this purchase for Google Ads, from the payload sent and
     * the dispatcher's answer.
     *
     * @param array $payload the purchase event, as sent
     * @param array $result  DFSS_Client::send() / DFSS_Queue::send_direct() result:
     *                       {ok, code, message, body?, skipped?}
     *
     * @return array{status:string,detail:string}
     */
    public static function classify(array $payload, array $result)
    {
        $code = isset($result['code']) ? (int) $result['code'] : 0;

        // The circuit breaker skipped the send: it went straight to the queue.
        if (!empty($result['skipped'])) {
            return array('status' => self::QUEUED, 'detail' => 'dispatcher unreachable, queued for retry');
        }
        if (empty($result['ok'])) {
            // Same split as DFSS_Queue: 401/403 are final, the rest is retried.
            $final = in_array($code, array(401, 403), true);

            return array(
                'status' => $final ? self::ERROR : self::QUEUED,
                'detail' => self::clip('HTTP ' . $code . ': ' . (isset($result['message']) ? (string) $result['message'] : '')),
            );
        }

        // An answer without `body` (a client older than 2.31.0) is read from
        // the message, which holds its first 500 characters.
        $raw = isset($result['body']) ? (string) $result['body'] : (isset($result['message']) ? (string) $result['message'] : '');
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            return array('status' => self::UNKNOWN, 'detail' => self::clip('HTTP ' . $code . ', unreadable answer'));
        }

        $reason = isset($body['reason']) ? (string) $body['reason'] : '';
        if ($reason === 'consent_denied') {
            return array('status' => self::CONSENT_REFUSED, 'detail' => 'refused, recorded by the dispatcher only');
        }
        if ($reason === 'quota_paused') {
            return array('status' => self::QUOTA_PAUSED, 'detail' => 'free allowance used up');
        }
        if ($reason === 'transform_excluded') {
            return array('status' => self::GOOGLE_SKIPPED, 'detail' => 'excluded by a rule of the site (transform_excluded)');
        }
        if ($reason !== '') {
            return array('status' => self::UNKNOWN, 'detail' => self::clip('not dispatched: ' . $reason));
        }

        $google = null;
        $results = isset($body['results']) && is_array($body['results']) ? $body['results'] : array();
        foreach ($results as $r) {
            if (is_array($r) && isset($r['destination']) && $r['destination'] === 'google') {
                $google = $r;
                break;
            }
        }
        if ($google === null) {
            return array('status' => self::NO_GOOGLE, 'detail' => 'no Google Ads destination in the answer');
        }
        if (empty($google['success'])) {
            return array(
                'status' => self::GOOGLE_ERROR,
                'detail' => self::clip(isset($google['error']) ? (string) $google['error'] : 'refused by Google'),
            );
        }
        if (!empty($google['skipped'])) {
            // The dispatcher skips for two reasons and says neither. The
            // payload tells them apart: no click id is the common one.
            return self::click_id_kind($payload) === ''
                ? array('status' => self::NO_CLICK_ID, 'detail' => 'no gclid, gbraid or wbraid')
                : array('status' => self::GOOGLE_SKIPPED, 'detail' => 'invalid click id, or purchase not mapped to a conversion action');
        }

        $consent = isset($payload['consent']) ? (string) $payload['consent'] : '';
        if ($consent !== 'granted') {
            return array(
                'status' => self::SENT_NO_CONSENT,
                'detail' => self::clip('accepted with consent "' . ($consent === '' ? 'none' : $consent) . '": Google may not count it'),
            );
        }

        return array(
            'status' => self::EXPECTED,
            'detail' => self::clip('accepted by Google' . (isset($google['requestId']) ? ', request ' . (string) $google['requestId'] : '')),
        );
    }

    /**
     * The last $days calendar days, newest first, in the site's time zone.
     * Counted from noon: a one-hour clock change never leaves the day (the
     * PrestaShop 1.32.0 bug: from midnight, a day was skipped or listed twice).
     *
     * @param int                $days
     * @param int                $now Unix time.
     * @param DateTimeZone|null  $tz  The site's time zone (wp_timezone()).
     *
     * @return string[] Y-m-d
     */
    public static function day_list($days, $now, $tz = null)
    {
        $tz = $tz instanceof DateTimeZone ? $tz : new DateTimeZone('UTC');
        $noon = (new DateTimeImmutable('@' . (int) $now))->setTimezone($tz)->setTime(12, 0);
        $out = array();
        for ($i = 0; $i < (int) $days; ++$i) {
            $out[] = $noon->modify('-' . $i . ' days')->format('Y-m-d');
        }

        return $out;
    }

    /**
     * @param string $s
     *
     * @return string
     */
    private static function clip($s)
    {
        return function_exists('mb_substr') ? mb_substr($s, 0, 255) : substr($s, 0, 255);
    }

    // ---- writes: never throw, a log must not break a checkout --------------------------------

    /**
     * Record an order that was sent (or queued), from the dispatcher's answer.
     *
     * @param WC_Order $order
     * @param array    $payload
     * @param array    $result
     */
    public static function record_sent($order, array $payload, array $result)
    {
        $c = self::classify($payload, $result);
        self::write($order, $payload, $c['status'], $c['detail']);
    }

    /**
     * A queued purchase was replayed: update its row with the new outcome.
     * Only an existing row is touched; the order is not loaded here.
     *
     * @param array  $payload
     * @param array  $result
     * @param string $settled the row's new queue status: done, failed, dropped or pending
     */
    public static function record_replay(array $payload, array $result, $settled)
    {
        global $wpdb;

        try {
            if (!isset($payload['eventName']) || $payload['eventName'] !== 'purchase') {
                return;
            }
            $order_id = (int) preg_replace('/^order_/', '', isset($payload['eventId']) ? (string) $payload['eventId'] : '');
            if ($order_id <= 0) {
                return;
            }

            $code = isset($result['code']) ? (int) $result['code'] : 0;
            if ($settled === 'done') {
                $c = self::classify($payload, $result);
            } elseif ($settled === 'pending') {
                $c = array('status' => self::QUEUED, 'detail' => 'still queued, HTTP ' . $code);
            } else {
                $c = array('status' => self::ERROR, 'detail' => 'queue gave up (' . $settled . '), HTTP ' . $code);
            }

            $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own log table, written once per replayed purchase.
                self::table(),
                array('status' => $c['status'], 'detail' => self::clip($c['detail']), 'date_upd' => current_time('mysql')),
                array('order_id' => $order_id)
            );
        } catch (\Throwable $e) {
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->warning('DataFirefly gads log replay: ' . $e->getMessage(), array('source' => 'datafirefly-serverside'));
            }
        }
    }

    /**
     * @param WC_Order $order
     * @param array    $payload
     * @param string   $status
     * @param string   $detail
     */
    private static function write($order, array $payload, $status, $detail)
    {
        global $wpdb;

        try {
            $data = isset($payload['eventData']) && is_array($payload['eventData']) ? $payload['eventData'] : array();
            $created = $order->get_date_created();

            $wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the plugin's own log table: one row per order, the later trigger overwrites the earlier.
                self::table(),
                array(
                    'order_id' => (int) $order->get_id(),
                    'reference' => substr((string) $order->get_order_number(), 0, 40),
                    'order_date' => $created ? $created->date('Y-m-d H:i:s') : current_time('mysql'),
                    'value' => isset($data['value']) ? round((float) $data['value'], 2) : round((float) $order->get_total(), 2),
                    'currency' => substr(isset($data['currency']) ? (string) $data['currency'] : (string) $order->get_currency(), 0, 3),
                    'consent' => substr(isset($payload['consent']) ? (string) $payload['consent'] : '', 0, 16),
                    'click_id' => self::click_id_kind($payload),
                    'ad_click' => self::ad_click_from_attribution(
                        (string) $order->get_meta('_wc_order_attribution_source_type'),
                        (string) $order->get_meta('_wc_order_attribution_utm_source'),
                        (string) $order->get_meta('_wc_order_attribution_utm_medium')
                    ),
                    'has_email' => !empty($payload['userData']['email']) ? 1 : 0,
                    'status' => $status,
                    'detail' => self::clip($detail),
                    'date_upd' => current_time('mysql'),
                )
            );

            $table = self::table();
            $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- retention of the plugin's own log table.
                $wpdb->prepare(
                    "DELETE FROM {$table} WHERE order_date < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix only.
                    gmdate('Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS)
                )
            );
        } catch (\Throwable $e) {
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->warning('DataFirefly gads log: ' . $e->getMessage(), array('source' => 'datafirefly-serverside'));
            }
        }
    }

    // ---- reads, for the Activity page ------------------------------------------------------

    /**
     * Per day, newest first: the shop's paid orders next to what the log says about them.
     *
     * @param int $days
     *
     * @return array<int,array<string,mixed>>
     */
    public static function daily($days)
    {
        global $wpdb;

        $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $day_list = self::day_list($days, time(), $tz);
        $since = end($day_list) . ' 00:00:00';
        $table = self::table();

        $log = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read on an admin page only.
            $wpdb->prepare(
                "SELECT DATE(order_date) AS day,
                        COUNT(*) AS seen,
                        SUM(status = %s) AS expected,
                        SUM(CASE WHEN status = %s THEN value ELSE 0 END) AS expected_value,
                        SUM(status = %s) AS sent_no_consent,
                        SUM(status = %s) AS no_click_id,
                        SUM(status = %s AND ad_click <> '') AS no_click_id_ad,
                        SUM(status = %s) AS consent_refused,
                        SUM(status = %s AND ad_click <> '') AS consent_refused_ad,
                        SUM(status NOT IN (%s, %s, %s, %s)) AS other
                 FROM {$table}
                 WHERE order_date >= %s
                 GROUP BY DATE(order_date)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix only.
                self::EXPECTED,
                self::EXPECTED,
                self::SENT_NO_CONSENT,
                self::NO_CLICK_ID,
                self::NO_CLICK_ID,
                self::CONSENT_REFUSED,
                self::CONSENT_REFUSED,
                self::EXPECTED,
                self::SENT_NO_CONSENT,
                self::NO_CLICK_ID,
                self::CONSENT_REFUSED,
                $since
            )
        );

        $out = array();
        foreach ($day_list as $day) {
            $out[$day] = array(
                'day' => $day, 'orders' => self::paid_orders_on($day), 'seen' => 0, 'expected' => 0, 'expected_value' => 0.0,
                'sent_no_consent' => 0, 'no_click_id' => 0, 'no_click_id_ad' => 0,
                'consent_refused' => 0, 'consent_refused_ad' => 0, 'other' => 0,
            );
        }
        foreach (is_array($log) ? $log : array() as $r) {
            $r = (array) $r;
            if (!isset($r['day']) || !isset($out[$r['day']])) {
                continue;
            }
            foreach (array('seen', 'expected', 'sent_no_consent', 'no_click_id', 'no_click_id_ad', 'consent_refused', 'consent_refused_ad', 'other') as $k) {
                $out[$r['day']][$k] = (int) $r[$k];
            }
            $out[$r['day']]['expected_value'] = (float) $r['expected_value'];
        }

        return array_values($out);
    }

    /**
     * Paid orders created on this day (site time zone), whatever the order
     * storage (posts or HPOS): WooCommerce's own query, one count per day.
     *
     * @param string $day Y-m-d
     *
     * @return int
     */
    private static function paid_orders_on($day)
    {
        if (!function_exists('wc_get_orders')) {
            return 0;
        }
        $found = wc_get_orders(array(
            'status' => function_exists('wc_get_is_paid_statuses') ? wc_get_is_paid_statuses() : array('processing', 'completed'),
            'type' => 'shop_order',
            'date_created' => $day . '...' . $day,
            'limit' => 1,
            'return' => 'ids',
            'paginate' => true,
        ));

        return is_object($found) && isset($found->total) ? (int) $found->total : 0;
    }

    /**
     * The latest orders, newest first.
     *
     * @param int $limit
     *
     * @return array<int,array<string,mixed>>
     */
    public static function latest($limit)
    {
        global $wpdb;

        $table = self::table();
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read on an admin page only.
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY order_date DESC, order_id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix only.
                (int) $limit
            )
        );

        return is_array($rows) ? array_map(function ($r) {
            return (array) $r;
        }, $rows) : array();
    }

    /**
     * Whether WooCommerce's order attribution is on (it feeds "came from an ad").
     *
     * @return bool
     */
    public static function attribution_enabled()
    {
        return get_option('woocommerce_feature_order_attribution_enabled', 'yes') !== 'no';
    }

    /**
     * The panel shown under the Activity table. Read-only; every value escaped here.
     *
     * @return string
     */
    public static function render_panel()
    {
        // Statistics only: whatever fails here, the Activity page above it must still render.
        try {
            return self::panel_html(self::daily(30), self::latest(50));
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * @param array<int,array<string,mixed>> $days
     * @param array<int,array<string,mixed>> $latest
     *
     * @return string
     */
    private static function panel_html(array $days, array $latest)
    {

        $labels = array(
            self::EXPECTED => __('Expected in Google Ads', 'datafirefly-server-side'),
            self::SENT_NO_CONSENT => __('Sent without consent (Google may ignore it)', 'datafirefly-server-side'),
            self::NO_CLICK_ID => __('No click ID (not sent to Google)', 'datafirefly-server-side'),
            self::GOOGLE_SKIPPED => __('Skipped by the dispatcher', 'datafirefly-server-side'),
            self::GOOGLE_ERROR => __('Refused by Google', 'datafirefly-server-side'),
            self::NO_GOOGLE => __('Google Ads not connected', 'datafirefly-server-side'),
            self::CONSENT_REFUSED => __('Consent refused (not sent)', 'datafirefly-server-side'),
            self::QUEUED => __('Waiting in the retry queue', 'datafirefly-server-side'),
            self::ERROR => __('Delivery error', 'datafirefly-server-side'),
            self::QUOTA_PAUSED => __('Plan allowance used up', 'datafirefly-server-side'),
            self::UNKNOWN => __('Unknown', 'datafirefly-server-side'),
        );
        $num = function ($v) {
            return esc_html(number_format_i18n((float) $v, 2));
        };

        $total = array('orders' => 0, 'seen' => 0, 'expected' => 0, 'expected_value' => 0.0, 'no_click_id' => 0, 'no_click_id_ad' => 0,
            'consent_refused' => 0, 'consent_refused_ad' => 0, 'sent_no_consent' => 0, 'other' => 0);
        $cols = array('orders', 'seen', 'expected', 'expected_value', 'no_click_id', 'no_click_id_ad', 'consent_refused', 'consent_refused_ad', 'sent_no_consent', 'other');
        $rows = '';
        foreach ($days as $d) {
            $rows .= '<tr><td>' . esc_html($d['day']) . '</td>';
            foreach ($cols as $k) {
                $total[$k] += $d[$k];
                $cell = $k === 'expected_value' ? $num($d[$k]) : (string) (int) $d[$k];
                $rows .= '<td>' . ($k === 'expected' ? '<strong>' . $cell . '</strong>' : $cell) . '</td>';
            }
            $rows .= '</tr>';
        }
        $foot = '<tr><th>' . esc_html__('30 days', 'datafirefly-server-side') . '</th>';
        foreach ($cols as $k) {
            $foot .= '<th>' . ($k === 'expected_value' ? $num($total[$k]) : (string) (int) $total[$k]) . '</th>';
        }
        $foot .= '</tr>';

        $heads = array(
            __('Day', 'datafirefly-server-side'),
            __('Shop orders (paid)', 'datafirefly-server-side'),
            __('Seen by the plugin', 'datafirefly-server-side'),
            __('Expected in Google Ads', 'datafirefly-server-side'),
            __('Expected value', 'datafirefly-server-side'),
            __('No click ID', 'datafirefly-server-side'),
            __('of which came from an ad', 'datafirefly-server-side'),
            __('Consent refused', 'datafirefly-server-side'),
            __('of which came from an ad', 'datafirefly-server-side'),
            __('Sent without consent', 'datafirefly-server-side'),
            __('Errors / queued', 'datafirefly-server-side'),
        );
        $head = '<tr>';
        foreach ($heads as $h) {
            $head .= '<th>' . esc_html($h) . '</th>';
        }
        $head .= '</tr>';

        $list = '';
        foreach ($latest as $r) {
            $status = (string) $r['status'];
            $list .= '<tr><td>' . esc_html((string) $r['order_date']) . '</td>'
                . '<td>' . esc_html('#' . (string) $r['reference']) . '</td>'
                . '<td>' . $num($r['value']) . ' ' . esc_html((string) $r['currency']) . '</td>'
                . '<td>' . esc_html((string) $r['consent']) . '</td>'
                . '<td>' . esc_html((string) $r['click_id'] !== '' ? (string) $r['click_id'] : '-') . '</td>'
                . '<td>' . esc_html((string) $r['ad_click'] !== '' ? __('Yes', 'datafirefly-server-side') : '-') . '</td>'
                . '<td>' . esc_html((int) $r['has_email'] ? __('Yes', 'datafirefly-server-side') : __('No', 'datafirefly-server-side')) . '</td>'
                . '<td><strong>' . esc_html(isset($labels[$status]) ? $labels[$status] : $status) . '</strong></td>'
                . '<td><small>' . esc_html((string) $r['detail']) . '</small></td></tr>';
        }
        if ($list === '') {
            $list = '<tr><td colspan="9">' . esc_html__('No order logged yet: the log starts with version 2.31.0.', 'datafirefly-server-side') . '</td></tr>';
        }

        $help = '<p class="description" style="max-width:1000px;">' . esc_html__('"Expected in Google Ads" counts the purchases Google accepted with the shopper consent: that is the number Google Ads should show, by click date, 24 to 72 hours later, for clicks inside the conversion window. Days are by order date.', 'datafirefly-server-side') . '</p>'
            . '<p class="description" style="max-width:1000px;">' . esc_html__('"Of which came from an ad": WooCommerce\'s own order attribution says the order came from Google with a paid medium (cpc, ppc, paid...). Read from the order, nothing new is stored about the visitor. WooCommerce sees the gclid of the landing page, not gbraid, wbraid or gad_source.', 'datafirefly-server-side') . '</p>';
        if (!self::attribution_enabled()) {
            $help .= '<p class="description" style="max-width:1000px;"><strong>' . esc_html__('WooCommerce order attribution is turned off (WooCommerce > Settings > Advanced > Features): the "of which came from an ad" columns stay at 0.', 'datafirefly-server-side') . '</strong></p>';
        }

        return '<h2 style="margin-top:32px;">' . esc_html__('Google Ads conversion log', 'datafirefly-server-side') . '</h2>'
            . $help
            . '<div style="overflow-x:auto;max-width:1200px;"><table class="widefat striped"><thead>' . $head . '</thead><tbody>' . $rows . '</tbody><tfoot>' . $foot . '</tfoot></table></div>'
            . '<h3>' . esc_html__('Latest orders', 'datafirefly-server-side') . '</h3>'
            . '<div style="overflow-x:auto;max-width:1200px;"><table class="widefat striped"><thead><tr>'
            . '<th>' . esc_html__('Date', 'datafirefly-server-side') . '</th>'
            . '<th>' . esc_html__('Order', 'datafirefly-server-side') . '</th>'
            . '<th>' . esc_html__('Amount', 'datafirefly-server-side') . '</th>'
            . '<th>' . esc_html__('Consent', 'datafirefly-server-side') . '</th>'
            . '<th>' . esc_html__('Click ID', 'datafirefly-server-side') . '</th>'
            . '<th>' . esc_html__('Arrived from an ad', 'datafirefly-server-side') . '</th>'
            . '<th>' . esc_html__('Email', 'datafirefly-server-side') . '</th>'
            . '<th>' . esc_html__('Google Ads', 'datafirefly-server-side') . '</th>'
            . '<th>' . esc_html__('Detail', 'datafirefly-server-side') . '</th>'
            . '</tr></thead><tbody>' . $list . '</tbody></table></div>';
    }
}
