<?php
/**
 * Plugin Name:       DataFirefly Server-Side
 * Description:       Complete WooCommerce tracking — client + server, full-funnel, deduplicated, GDPR-aware, reliable. One key configures everything; no destination credentials ever reach the browser.
 * Version:           2.2.1
 * Author:            DataFirefly Ltd
 * Author URI:        https://datafirefly.com
 * Requires PHP:      7.4
 * Requires at least: 5.8
 * WC requires at least: 5.0
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       datafirefly-serverside
 */

if (!defined('ABSPATH')) {
    exit;
}

define('DFSS_VERSION', '2.2.1');
define('DFSS_PLUGIN_FILE', __FILE__);
define('DFSS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DFSS_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once DFSS_PLUGIN_DIR . 'includes/class-dfss-client.php';
require_once DFSS_PLUGIN_DIR . 'includes/class-dfss-event-builder.php';
require_once DFSS_PLUGIN_DIR . 'includes/class-dfss-consent.php';
require_once DFSS_PLUGIN_DIR . 'includes/class-dfss-queue.php';
require_once DFSS_PLUGIN_DIR . 'includes/class-dfss-rest.php';

// Declare WooCommerce HPOS (custom order tables) compatibility.
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

class DFSS_Plugin
{
    const OPTION = 'dfss_settings';
    const PUBLIC_OPTION = 'dfss_public_config'; // cached public ids from the dispatcher

    public function __construct()
    {
        // --- admin ---
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'maybe_save'));
        // Seamless upgrade: the first admin request on a new version refreshes
        // the cached public ids (so a v1->v2 upgrade lights up client tags with
        // no manual "Refresh" click). Admin-gated so a visitor never pays the call.
        add_action('admin_init', array($this, 'maybe_upgrade'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin'));
        add_action('wp_ajax_dfss_activity', array($this, 'ajax_activity'));

        // --- server-side purchase (unchanged, backward compatible) ---
        // Capture browser cookies at checkout (browser context) onto the order,
        // so the purchase hook can use them even when it fires from a gateway.
        add_action('woocommerce_checkout_create_order', array($this, 'capture_cookies'), 10, 2);
        // Send the purchase once payment is in. Idempotent across all triggers.
        add_action('woocommerce_payment_complete', array($this, 'on_purchase'));
        add_action('woocommerce_order_status_processing', array($this, 'on_purchase'));
        add_action('woocommerce_order_status_completed', array($this, 'on_purchase'));

        // --- client tracking layer (new in v2.0, additive + gated) ---
        add_action('wp_enqueue_scripts', array($this, 'enqueue_tracker'));

        // --- REST beacon endpoint (new) ---
        add_action('rest_api_init', array($this, 'register_rest'));

        // --- retry cron (new) ---
        add_filter('cron_schedules', array($this, 'cron_schedules'));
        add_action(DFSS_Queue::CRON_HOOK, array($this, 'run_retry'));
        // Make sure the schedule survives even if activation predates the cron.
        add_action('init', array(__CLASS__, 'ensure_cron'));
    }

    // ---- activation / deactivation -----------------------------------------

    public static function activate()
    {
        DFSS_Queue::install();
        DFSS_Queue::schedule_cron();
    }

    public static function deactivate()
    {
        DFSS_Queue::unschedule_cron();
    }

    public static function ensure_cron()
    {
        DFSS_Queue::schedule_cron();
    }

    /**
     * Run once after the plugin version changes (fresh activate or v1->v2
     * upgrade). Ensures the retry table exists and — when already connected —
     * refreshes the cached PUBLIC destination ids, so upgrading lights up the
     * client tags without the operator re-connecting or clicking "Refresh".
     * Admin-gated (see the admin_init hook) so a storefront visitor never pays
     * the one-time HTTP call.
     */
    public function maybe_upgrade()
    {
        if (get_option('dfss_version', '') === DFSS_VERSION) {
            return;
        }
        $o = $this->opts();
        if (!empty($o['enabled']) && $o['tenant_id'] !== '' && $o['hmac_secret'] !== '') {
            DFSS_Queue::install();
            $this->refresh_public_config($o);
        }
        update_option('dfss_version', DFSS_VERSION);
    }

    /**
     * Register a 5-minute cron interval for the retry queue.
     *
     * @param array $schedules
     *
     * @return array
     */
    public function cron_schedules($schedules)
    {
        if (!isset($schedules['dfss_5min'])) {
            $schedules['dfss_5min'] = array(
                'interval' => 300,
                'display' => __('Every 5 minutes (DataFirefly retry)', 'datafirefly-serverside'),
            );
        }

        return $schedules;
    }

    public function run_retry()
    {
        $o = $this->opts();
        if (empty($o['enabled'])) {
            return;
        }
        DFSS_Queue::process_due($o['tenant_id'], $o['hmac_secret'], $o['endpoint']);
    }

    // ---- options ------------------------------------------------------------

    /**
     * @return array{enabled:int,tenant_id:string,hmac_secret:string,endpoint:string,complete_tracking:int,require_consent:int,dest_meta:int,dest_ga4:int,dest_tiktok:int}
     */
    public function opts()
    {
        return wp_parse_args(
            get_option(self::OPTION, array()),
            array(
                'enabled' => 0,
                'tenant_id' => '',
                'hmac_secret' => '',
                'endpoint' => self::DEFAULT_ENDPOINT,
                // New in v2.0 — both ON by default (full-funnel + privacy-first).
                'complete_tracking' => 1,
                'require_consent' => 1,
                // New in v2.1 — per-destination client tags. ON by default so
                // upgrades keep the v2.0 behaviour; a destination without a
                // public id in the dispatcher config never loads anyway.
                'dest_meta' => 1,
                'dest_ga4' => 1,
                'dest_tiktok' => 1,
            )
        );
    }

    /**
     * The public destination ids FILTERED by the per-destination toggles.
     *
     * This is the single choke point that guarantees a disabled destination's
     * third-party script (fbevents.js / gtag.js / TikTok events.js) is NEVER
     * loaded in the visitor's browser: the tracker only injects a tag when its
     * public id is present in the localized config, so removing the key here
     * removes the script, the cookies it would set, and its network calls.
     *
     * @param array|null $public Raw public config; defaults to the cached one.
     * @param array|null $opts   Plugin options; defaults to opts().
     *
     * @return array
     */
    private function filtered_public_config($public = null, $opts = null)
    {
        $public = is_array($public) ? $public : $this->public_config();
        $opts = is_array($opts) ? $opts : $this->opts();

        $map = array('dest_meta' => 'meta', 'dest_ga4' => 'ga4', 'dest_tiktok' => 'tiktok');
        foreach ($map as $toggle => $key) {
            if (empty($opts[$toggle])) {
                unset($public[$key]);
            }
        }

        return $public;
    }

    private function is_connected()
    {
        $o = $this->opts();

        return !empty($o['enabled']) && $o['tenant_id'] !== '' && $o['hmac_secret'] !== '';
    }

    /**
     * The cached public destination ids (pixel/measurement ids). Public only.
     *
     * @return array
     */
    private function public_config()
    {
        $cfg = get_option(self::PUBLIC_OPTION, array());

        return is_array($cfg) ? $cfg : array();
    }

    /**
     * Fetch the public-config from the dispatcher and cache it. Called right
     * after a successful connect (and refreshable from the admin screen).
     *
     * @param array $opts
     *
     * @return array{ok:bool,public:array,code:int}
     */
    private function refresh_public_config($opts)
    {
        $client = new DFSS_Client($opts['tenant_id'], $opts['hmac_secret'], $opts['endpoint']);
        $res = $client->get_public_config();
        if (!empty($res['ok'])) {
            update_option(self::PUBLIC_OPTION, $res['public']);
        }

        return array('ok' => !empty($res['ok']), 'public' => $res['public'], 'code' => (int) $res['code']);
    }

    // ---- server-side purchase (unchanged behaviour) ------------------------

    /**
     * @param WC_Order $order
     * @param array    $data
     */
    public function capture_cookies($order, $data)
    {
        $map = array('_fbp' => '_dfss_fbp', '_fbc' => '_dfss_fbc', '_ga' => '_dfss_ga', '_ttp' => '_dfss_ttp');
        foreach ($map as $cookie => $meta) {
            if (!empty($_COOKIE[$cookie])) {
                $order->update_meta_data($meta, sanitize_text_field(wp_unslash($_COOKIE[$cookie])));
            }
        }
        // Also persist our captured click-id cookies (90-day first-party) so the
        // server purchase event carries fbc/ttclid/gclid even if the live pixel
        // cookie is absent.
        $extra = array('_dfss_fbc' => '_dfss_fbc', '_dfss_ttclid' => '_dfss_ttclid', '_dfss_gclid' => '_dfss_gclid');
        foreach ($extra as $cookie => $meta) {
            if (!empty($_COOKIE[$cookie]) && !$order->get_meta($meta)) {
                $order->update_meta_data($meta, sanitize_text_field(wp_unslash($_COOKIE[$cookie])));
            }
        }
        // GA4 session cookie: its name is _ga_<measurementId without the G-
        // prefix>. Derive it from OUR configured measurement id so we capture the
        // session of our own property — never a stray _ga_* from another GA4
        // stream that may also be on the page. The purchase then carries the
        // session_id and GA4 attributes the conversion to the converting
        // session's source instead of reporting it as "Unassigned".
        if (!$order->get_meta('_dfss_ga_session')) {
            $public = $this->public_config();
            $mid = isset($public['ga4']['measurementId'])
                ? (string) $public['ga4']['measurementId']
                : '';
            if ($mid !== '') {
                $cookie_name = '_ga_' . preg_replace('/^G-/', '', $mid);
                if (!empty($_COOKIE[$cookie_name])) {
                    $order->update_meta_data('_dfss_ga_session', sanitize_text_field(wp_unslash($_COOKIE[$cookie_name])));
                }
            }
        }
    }

    /**
     * @param int $order_id
     */
    public function on_purchase($order_id)
    {
        try {
            $opts = $this->opts();
            if (empty($opts['enabled'])) {
                return;
            }
            $order = wc_get_order($order_id);
            if (!$order) {
                return;
            }
            if ($order->get_meta('_dfss_sent')) {
                return; // already delivered for this order
            }

            $payload = DFSS_Event_Builder::build_purchase($order);
            if (null === $payload) {
                return;
            }

            // Claim the order BEFORE sending (optimistic lock). Three hooks
            // (payment_complete / processing / completed) can fire for the same
            // order in the same request or via overlapping async webhooks; setting
            // and persisting the marker first means a concurrent second entry sees
            // it and bails, so we never send the purchase twice. If the send then
            // fails it is handed to the retry queue (which owns delivery), so a
            // later status hook must NOT re-send a fresh copy.
            $order->update_meta_data('_dfss_sent', current_time('mysql'));
            $order->save();

            $client = new DFSS_Client($opts['tenant_id'], $opts['hmac_secret'], $opts['endpoint']);
            $result = $client->send($payload);

            // Record for observability + queue retry on a retryable failure.
            DFSS_Queue::record_attempt($payload, $result, 'server');

            if (empty($result['ok'])) {
                $order->add_order_note('DataFirefly: purchase event not delivered (HTTP ' . (int) $result['code'] . '). Queued for retry.');
                $order->save();
            }
        } catch (\Throwable $e) {
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->warning('DataFirefly send failed: ' . $e->getMessage(), array('source' => 'datafirefly-serverside'));
            }
        }
    }

    // ---- REST + client tracker ---------------------------------------------

    public function register_rest()
    {
        $rest = new DFSS_REST(array($this, 'opts'));
        $rest->register_routes();
    }

    /**
     * Enqueue the client tracker on the storefront, gated by connection +
     * "Complete tracking". Localizes the PUBLIC ids, consent config, REST URL,
     * a wp_rest nonce, and the current page's event context.
     */
    public function enqueue_tracker()
    {
        if (is_admin()) {
            return;
        }
        $o = $this->opts();
        if (empty($o['enabled']) || empty($o['complete_tracking'])) {
            return;
        }

        $handle = 'dfss-tracker';
        wp_register_script(
            $handle,
            DFSS_PLUGIN_URL . 'assets/dfss-tracker.js',
            array(),
            DFSS_VERSION,
            true // in footer
        );

        wp_localize_script($handle, 'DFSS_CFG', array(
            // Filtered by the per-destination toggles: a disabled destination's
            // id never reaches the browser, so its script is never injected.
            'public' => $this->filtered_public_config(),
            'consent' => DFSS_Consent::js_config($o),
            'restUrl' => esc_url_raw(rest_url(DFSS_REST::REST_NAMESPACE . DFSS_REST::ROUTE)),
            'nonce' => wp_create_nonce('wp_rest'),
            'events' => $this->page_event_context(),
        ));

        wp_enqueue_script($handle);
    }

    /**
     * Build the per-page event context the tracker needs (server-authoritative
     * values for value/currency/products), so the browser never has to guess.
     *
     * @return array
     */
    private function page_event_context()
    {
        $ctx = array();

        if (!function_exists('is_product')) {
            return $ctx; // WooCommerce not loaded
        }

        // view_item — product page. Resolve from the queried object (never via
        // the unprefixed $product global — see WPCS PrefixAllGlobals).
        if (is_product()) {
            $dfss_product = wc_get_product(get_queried_object_id());
            if ($dfss_product instanceof WC_Product) {
                $ctx['viewItem'] = array(
                    'id' => (string) $dfss_product->get_id(),
                    'name' => $dfss_product->get_name(),
                    'value' => round((float) wc_get_price_to_display($dfss_product), 2),
                    'currency' => get_woocommerce_currency(),
                );
            }
        }

        // initiate_checkout — checkout page (but NOT the thank-you/order-received
        // sub-page, which is purchase).
        if (function_exists('is_checkout') && is_checkout() && !(function_exists('is_order_received_page') && is_order_received_page())) {
            $cart = function_exists('WC') ? WC()->cart : null;
            if ($cart && !$cart->is_empty()) {
                $products = array();
                $num_items = 0;
                foreach ($cart->get_cart() as $item) {
                    if (empty($item['data']) || !$item['data'] instanceof WC_Product) {
                        continue;
                    }
                    $qty = (int) $item['quantity'];
                    $num_items += $qty;
                    $products[] = array(
                        'id' => (string) $item['data']->get_id(),
                        'name' => $item['data']->get_name(),
                        'price' => round((float) $item['data']->get_price(), 2),
                        'quantity' => $qty,
                    );
                }
                $ctx['checkout'] = array(
                    'value' => round((float) $cart->get_total('edit'), 2),
                    'currency' => get_woocommerce_currency(),
                    'numItems' => $num_items,
                    'products' => $products,
                );
            }
        }

        // purchase — thank-you page. Pin event_id to "order_<id>" so the client
        // pixel and the server purchase event deduplicate exactly.
        if (function_exists('is_order_received_page') && is_order_received_page()) {
            // Read-only lookup of the public thank-you-page order id; the value
            // is cast to int and only used to render tracking context. No state
            // changes, so no nonce applies here.
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $order_id = absint(get_query_var('order-received'));
            if (!$order_id && isset($_GET['order-received'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $order_id = absint(wp_unslash($_GET['order-received']));
            }
            $order = $order_id ? wc_get_order($order_id) : null;
            if ($order instanceof WC_Order) {
                $products = array();
                $num_items = 0;
                foreach ($order->get_items() as $item) {
                    if (!$item instanceof WC_Order_Item_Product) {
                        continue;
                    }
                    $qty = (int) $item->get_quantity();
                    $num_items += $qty;
                    $line_total = (float) $item->get_total();
                    $products[] = array(
                        'id' => (string) $item->get_product_id(),
                        'name' => $item->get_name(),
                        'price' => $qty > 0 ? round($line_total / $qty, 2) : round($line_total, 2),
                        'quantity' => $qty,
                    );
                }
                $ctx['purchase'] = array(
                    'eventId' => 'order_' . $order->get_id(),
                    'orderId' => (string) $order->get_order_number(),
                    'value' => round((float) $order->get_total(), 2),
                    'currency' => $order->get_currency(),
                    'numItems' => $num_items,
                    'products' => $products,
                );
            }
        }

        return $ctx;
    }

    // ---- admin UI -----------------------------------------------------------

    public function admin_menu()
    {
        add_options_page(
            'DataFirefly Server-Side',
            'DataFirefly Server-Side',
            'manage_options',
            'datafirefly-serverside',
            array($this, 'render')
        );
        // Activity panel (observability).
        add_submenu_page(
            'options-general.php',
            __('DataFirefly Activity', 'datafirefly-serverside'),
            __('DataFirefly Activity', 'datafirefly-serverside'),
            'manage_options',
            'datafirefly-activity',
            array($this, 'render_activity')
        );
    }

    public function enqueue_admin($hook)
    {
        // Only on our two settings screens.
        if (strpos((string) $hook, 'datafirefly-serverside') === false
            && strpos((string) $hook, 'datafirefly-activity') === false) {
            return;
        }
        wp_enqueue_script(
            'dfss-admin',
            DFSS_PLUGIN_URL . 'assets/dfss-admin.js',
            array(),
            DFSS_VERSION,
            true
        );
        wp_localize_script('dfss-admin', 'DFSS_ADMIN', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dfss_activity'),
        ));
    }

    const DEFAULT_ENDPOINT = 'https://serverside.datafirefly.com/v1/events';

    /**
     * Decode a one-paste connection key (dfss_<base64url(json{t,s,e})>) into
     * config. Returns null if the key is malformed.
     *
     * Hardening:
     *  - tenant id + secret are validated against a safe charset and stored
     *    VERBATIM (never run through sanitize_text_field, which could silently
     *    mangle a valid secret and break every signature with an opaque 401).
     *  - the endpoint embedded in the key is only honoured if it is HTTPS on a
     *    datafirefly.com host. This stops a socially-engineered hostile key from
     *    redirecting signed customer events to an attacker. Arbitrary endpoints
     *    remain available only via the explicit "Advanced" manual entry.
     *
     * @param string $raw
     *
     * @return array{tenant_id:string,hmac_secret:string,endpoint:string}|null
     */
    private function decode_key($raw)
    {
        $raw = trim((string) $raw);
        if (strpos($raw, 'dfss_') !== 0) {
            return null;
        }
        $json = base64_decode(strtr(substr($raw, 5), '-_', '+/'), true);
        if (false === $json) {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['t']) || empty($data['s'])) {
            return null;
        }

        $tenant = (string) $data['t'];
        $secret = (string) $data['s'];
        // Tenant ids and secrets are opaque tokens (hex / base64url / uuid). Allow
        // that charset only; reject anything else rather than silently altering it.
        if (!preg_match('/^[A-Za-z0-9+\/=_.\-]{1,256}$/', $tenant)
            || !preg_match('/^[A-Za-z0-9+\/=_.\-]{1,512}$/', $secret)) {
            return null;
        }

        $endpoint = self::DEFAULT_ENDPOINT;
        if (!empty($data['e'])) {
            $candidate = esc_url_raw((string) $data['e']);
            if ($this->is_trusted_endpoint($candidate)) {
                $endpoint = $candidate;
            }
            // else: silently fall back to the default (do not honour an untrusted
            // host from the convenience key).
        }

        return array(
            'tenant_id' => $tenant,
            'hmac_secret' => $secret,
            'endpoint' => $endpoint,
        );
    }

    /**
     * Is this an HTTPS endpoint on a datafirefly.com host?
     *
     * @param string $url
     *
     * @return bool
     */
    private function is_trusted_endpoint($url)
    {
        if ($url === '') {
            return false;
        }
        $parts = wp_parse_url($url);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        if (strtolower($parts['scheme']) !== 'https') {
            return false;
        }
        $host = strtolower($parts['host']);

        return $host === 'datafirefly.com' || substr($host, -strlen('.datafirefly.com')) === '.datafirefly.com';
    }

    public function maybe_save()
    {
        if (!isset($_POST['dfss_nonce']) || !current_user_can('manage_options')) {
            return;
        }
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dfss_nonce'])), 'dfss_save')) {
            return;
        }

        // One-key connect (the "wow" path).
        if (isset($_POST['dfss_connect'])) {
            // The key is base64url ("dfss_<...>") so sanitize_text_field cannot
            // alter a valid key; decode_key() then re-validates the charset.
            $decoded = $this->decode_key(isset($_POST['dfss_connkey']) ? sanitize_text_field(wp_unslash($_POST['dfss_connkey'])) : '');
            if (null === $decoded) {
                add_settings_error('dfss', 'badkey', __('That connection key is not valid. Copy it again from your DataFirefly client space.', 'datafirefly-serverside'), 'error');

                return;
            }
            // Connecting turns on complete tracking + consent gating + all
            // client destinations by default.
            $opts = array_merge($decoded, array(
                'enabled' => 1,
                'complete_tracking' => 1,
                'require_consent' => 1,
                'dest_meta' => 1,
                'dest_ga4' => 1,
                'dest_tiktok' => 1,
            ));
            update_option(self::OPTION, $opts);

            // Make sure the retry table + cron exist (covers upgrades where the
            // activation hook didn't run for this version).
            DFSS_Queue::install();
            DFSS_Queue::schedule_cron();

            // Pull the public destination ids so we can inject the client tags.
            $pub = $this->refresh_public_config($opts);

            // Verify the connection with a test event.
            $this->run_test($opts, true, $pub);

            return;
        }

        // Disconnect — clear the connection.
        if (isset($_POST['dfss_disconnect'])) {
            update_option(self::OPTION, array(
                'enabled' => 0, 'tenant_id' => '', 'hmac_secret' => '',
                'endpoint' => self::DEFAULT_ENDPOINT,
                'complete_tracking' => 1, 'require_consent' => 1,
                'dest_meta' => 1, 'dest_ga4' => 1, 'dest_tiktok' => 1,
            ));
            delete_option(self::PUBLIC_OPTION);
            add_settings_error('dfss', 'disconnected', __('Disconnected.', 'datafirefly-serverside'), 'updated');

            return;
        }

        // Toggle settings (connected view): complete tracking + consent +
        // per-destination client tags.
        if (isset($_POST['dfss_update_toggles'])) {
            $o = $this->opts();
            $o['complete_tracking'] = isset($_POST['dfss_complete_tracking']) ? 1 : 0;
            $o['require_consent'] = isset($_POST['dfss_require_consent']) ? 1 : 0;
            $o['dest_meta'] = isset($_POST['dfss_dest_meta']) ? 1 : 0;
            $o['dest_ga4'] = isset($_POST['dfss_dest_ga4']) ? 1 : 0;
            $o['dest_tiktok'] = isset($_POST['dfss_dest_tiktok']) ? 1 : 0;
            update_option(self::OPTION, $o);
            add_settings_error('dfss', 'toggles', __('Tracking settings saved.', 'datafirefly-serverside'), 'updated');

            return;
        }

        // Refresh the cached public destination ids.
        if (isset($_POST['dfss_refresh_public'])) {
            $pub = $this->refresh_public_config($this->opts());
            if (!empty($pub['ok'])) {
                add_settings_error('dfss', 'pub_ok', __('Destination ids refreshed.', 'datafirefly-serverside'), 'updated');
            } else {
                /* translators: %d: HTTP status code returned by the dispatcher. */
                add_settings_error('dfss', 'pub_ko', sprintf(__('Could not refresh destination ids (HTTP %d).', 'datafirefly-serverside'), (int) $pub['code']), 'error');
            }

            return;
        }

        // Manual save (advanced).
        if (isset($_POST['dfss_save'])) {
            $opts = array(
                'enabled' => isset($_POST['dfss_enabled']) ? 1 : 0,
                'tenant_id' => isset($_POST['dfss_tenant_id']) ? sanitize_text_field(wp_unslash($_POST['dfss_tenant_id'])) : '',
                'hmac_secret' => isset($_POST['dfss_hmac_secret']) ? sanitize_text_field(wp_unslash($_POST['dfss_hmac_secret'])) : '',
                'endpoint' => isset($_POST['dfss_endpoint']) ? esc_url_raw(wp_unslash($_POST['dfss_endpoint'])) : '',
                'complete_tracking' => isset($_POST['dfss_complete_tracking']) ? 1 : 0,
                'require_consent' => isset($_POST['dfss_require_consent']) ? 1 : 0,
                'dest_meta' => isset($_POST['dfss_dest_meta']) ? 1 : 0,
                'dest_ga4' => isset($_POST['dfss_dest_ga4']) ? 1 : 0,
                'dest_tiktok' => isset($_POST['dfss_dest_tiktok']) ? 1 : 0,
            );
            update_option(self::OPTION, $opts);
            if (!empty($opts['enabled']) && $opts['tenant_id'] !== '' && $opts['hmac_secret'] !== '') {
                DFSS_Queue::install();
                DFSS_Queue::schedule_cron();
                $this->refresh_public_config($opts);
            }
            add_settings_error('dfss', 'saved', __('Settings saved.', 'datafirefly-serverside'), 'updated');

            return;
        }

        // Test event (from the connected view).
        if (isset($_POST['dfss_test'])) {
            $this->run_test($this->opts(), false, null);
        }
    }

    /**
     * @param array      $opts
     * @param bool       $just_connected
     * @param array|null $pub Result of refresh_public_config(), if available.
     */
    private function run_test($opts, $just_connected, $pub)
    {
        $payload = array(
            'eventId' => 'test_' . time(),
            'eventName' => 'page_view',
            'eventTime' => time(),
            'sourceUrl' => home_url('/'),
            'actionSource' => 'website',
            'userData' => array('clientUserAgent' => 'DataFirefly-Test'),
        );

        $client = new DFSS_Client($opts['tenant_id'], $opts['hmac_secret'], $opts['endpoint']);
        $result = $client->send($payload);

        // The dispatcher accepted the signed request unless it's an auth/state reject.
        $code = (int) $result['code'];
        $connection_ok = $code > 0 && !in_array($code, array(401, 403), true);

        if ($just_connected) {
            if ($connection_ok) {
                $msg = __('Connected! Your shop is live — a test event just reached DataFirefly.', 'datafirefly-serverside');
                if (is_array($pub) && !empty($pub['ok'])) {
                    $dests = $this->describe_destinations($this->filtered_public_config($pub['public'], $opts));
                    if ($dests !== '') {
                        /* translators: %s: comma-separated list of destinations (e.g. Meta, GA4, TikTok). */
                        $msg .= ' ' . sprintf(__('Client tags will load for: %s.', 'datafirefly-serverside'), $dests);
                    }
                }
                add_settings_error('dfss', 'connected', $msg, 'updated');
            } else {
                /* translators: %d: HTTP status code returned by the dispatcher. */
                add_settings_error('dfss', 'connfail', sprintf(__('Connected, but the test was rejected (HTTP %d). Ask your DataFirefly operator to check your tenant is active.', 'datafirefly-serverside'), $code), 'error');
            }

            return;
        }

        if (!empty($result['ok'])) {
            /* translators: %d: HTTP status code returned by the dispatcher. */
            add_settings_error('dfss', 'test_ok', sprintf(__('Test event delivered (HTTP %d).', 'datafirefly-serverside'), $code), 'updated');
        } elseif ($connection_ok) {
            /* translators: %d: HTTP status code returned by the dispatcher. */
            add_settings_error('dfss', 'test_partial', sprintf(__('Reached DataFirefly (HTTP %d) — a destination rejected the test event. Your connection is fine.', 'datafirefly-serverside'), $code), 'updated');
        } else {
            /* translators: 1: HTTP status code, 2: error message from the dispatcher. */
            add_settings_error('dfss', 'test_ko', sprintf(__('Test failed: HTTP %1$d — %2$s', 'datafirefly-serverside'), $code, esc_html($result['message'])), 'error');
        }
    }

    /**
     * Human label of which client destinations are configured (from public ids).
     *
     * @param array $public
     *
     * @return string
     */
    private function describe_destinations($public)
    {
        $names = array();
        if (!empty($public['meta']['pixelId'])) {
            $names[] = 'Meta';
        }
        if (!empty($public['ga4']['measurementId'])) {
            $names[] = 'GA4';
        }
        if (!empty($public['tiktok']['pixelCode'])) {
            $names[] = 'TikTok';
        }

        return implode(', ', $names);
    }

    public function render()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $o = $this->opts();
        $public = $this->public_config();
        // What the visitor's browser will actually load (toggles applied).
        $active_public = $this->filtered_public_config($public, $o);
        settings_errors('dfss');
        ?>
        <div class="wrap">
            <h1>DataFirefly Server-Side</h1>

            <?php if ($this->is_connected()) : ?>
                <div class="notice notice-success inline" style="margin:16px 0;">
                    <p style="font-size:14px;">
                        <span class="dashicons dashicons-yes-alt" style="color:#008D9E;"></span>
                        <strong><?php esc_html_e('Connected', 'datafirefly-serverside'); ?></strong> —
                        <?php esc_html_e('tracking is sent to', 'datafirefly-serverside'); ?>
                        <code><?php echo esc_html($o['tenant_id']); ?></code>.
                        <?php
                        $dests = $this->describe_destinations($active_public);
                        if ($dests !== '') {
                            /* translators: %s: comma-separated list of destinations (e.g. Meta, GA4, TikTok). */
                            echo ' ' . esc_html(sprintf(__('Client tags: %s.', 'datafirefly-serverside'), $dests));
                        }
                        ?>
                    </p>
                </div>

                <form method="post" action="">
                    <?php wp_nonce_field('dfss_save', 'dfss_nonce'); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php esc_html_e('Complete tracking', 'datafirefly-serverside'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="dfss_complete_tracking" value="1" <?php checked(1, (int) $o['complete_tracking']); ?> />
                                    <?php esc_html_e('Inject the light client tags and track the full funnel (page view, product view, add to cart, checkout, purchase). Recommended.', 'datafirefly-serverside'); ?>
                                </label>
                                <p class="description"><?php esc_html_e('When off, only the server-side purchase event is sent (v1 behaviour).', 'datafirefly-serverside'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Require consent', 'datafirefly-serverside'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="dfss_require_consent" value="1" <?php checked(1, (int) $o['require_consent']); ?> />
                                    <?php esc_html_e('Do not fire anything until marketing consent is granted (WP Consent API, Complianz, Cookiebot, IAB TCF).', 'datafirefly-serverside'); ?>
                                </label>
                                <?php if (!DFSS_Consent::has_wp_consent_api()) : ?>
                                    <p class="description"><?php esc_html_e('Tip: install the WP Consent API plugin for the most reliable consent signal.', 'datafirefly-serverside'); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Client destinations', 'datafirefly-serverside'); ?></th>
                            <td>
                                <?php
                                // Which destinations the dispatcher has configured
                                // (unfiltered — shows availability, not the toggle).
                                $dfss_dests = array(
                                    'dest_meta' => array('Meta (Facebook pixel)', !empty($public['meta']['pixelId'])),
                                    'dest_ga4' => array('Google Analytics 4 (gtag.js)', !empty($public['ga4']['measurementId'])),
                                    'dest_tiktok' => array('TikTok pixel', !empty($public['tiktok']['pixelCode'])),
                                );
                                foreach ($dfss_dests as $dfss_key => $dfss_info) :
                                    ?>
                                    <label style="display:block;margin-bottom:6px;">
                                        <input type="checkbox" name="dfss_<?php echo esc_attr($dfss_key); ?>" value="1" <?php checked(1, (int) $o[$dfss_key]); ?> />
                                        <?php echo esc_html($dfss_info[0]); ?>
                                        <?php if (!$dfss_info[1]) : ?>
                                            <em class="description">— <?php esc_html_e('not configured on your DataFirefly account', 'datafirefly-serverside'); ?></em>
                                        <?php endif; ?>
                                    </label>
                                <?php endforeach; ?>
                                <p class="description"><?php esc_html_e('Uncheck a destination you do not use: its third-party script (and its cookies) will never be loaded in your visitors\' browsers. Server-side destinations are managed in your DataFirefly client space.', 'datafirefly-serverside'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <p><button type="submit" name="dfss_update_toggles" class="button button-primary"><?php esc_html_e('Save settings', 'datafirefly-serverside'); ?></button></p>
                </form>

                <p style="margin-top:8px;">
                    <a href="<?php echo esc_url(admin_url('options-general.php?page=datafirefly-activity')); ?>"><?php esc_html_e('View activity', 'datafirefly-serverside'); ?></a>
                </p>

                <hr style="margin:24px 0;" />

                <form method="post" action="" style="display:inline-block;margin-right:8px;">
                    <?php wp_nonce_field('dfss_save', 'dfss_nonce'); ?>
                    <button type="submit" name="dfss_test" class="button"><?php esc_html_e('Send test event', 'datafirefly-serverside'); ?></button>
                </form>
                <form method="post" action="" style="display:inline-block;margin-right:8px;">
                    <?php wp_nonce_field('dfss_save', 'dfss_nonce'); ?>
                    <button type="submit" name="dfss_refresh_public" class="button"><?php esc_html_e('Refresh destination ids', 'datafirefly-serverside'); ?></button>
                </form>
                <form method="post" action="" style="display:inline-block;" onsubmit="return confirm('<?php echo esc_js(__('Disconnect this shop from DataFirefly?', 'datafirefly-serverside')); ?>');">
                    <?php wp_nonce_field('dfss_save', 'dfss_nonce'); ?>
                    <button type="submit" name="dfss_disconnect" class="button button-link-delete"><?php esc_html_e('Disconnect', 'datafirefly-serverside'); ?></button>
                </form>

            <?php else : ?>
                <div class="card" style="max-width:620px;padding:8px 24px 24px;margin-top:16px;">
                    <h2><?php esc_html_e('Connect your shop', 'datafirefly-serverside'); ?></h2>
                    <p class="description" style="font-size:13px;"><?php esc_html_e('Paste the connection key from your DataFirefly client space (Connect your shop). That is the only step — we configure client and server tracking for you.', 'datafirefly-serverside'); ?></p>
                    <form method="post" action="">
                        <?php wp_nonce_field('dfss_save', 'dfss_nonce'); ?>
                        <p>
                            <input type="text" name="dfss_connkey" class="large-text code" placeholder="dfss_..." autocomplete="off" />
                        </p>
                        <p>
                            <button type="submit" name="dfss_connect" class="button button-primary button-hero"><?php esc_html_e('Connect', 'datafirefly-serverside'); ?></button>
                        </p>
                    </form>
                </div>

                <p style="margin-top:18px;">
                    <a href="#" data-dfss-toggle-advanced onclick="document.getElementById('dfss-adv').style.display='block';this.style.display='none';return false;"><?php esc_html_e('Advanced: enter credentials manually', 'datafirefly-serverside'); ?></a>
                </p>
                <div id="dfss-adv" style="display:none;max-width:620px;">
                    <form method="post" action="">
                        <?php wp_nonce_field('dfss_save', 'dfss_nonce'); ?>
                        <table class="form-table" role="presentation">
                            <tr><th scope="row"><label for="dfss_enabled"><?php esc_html_e('Enable', 'datafirefly-serverside'); ?></label></th>
                                <td><input type="checkbox" id="dfss_enabled" name="dfss_enabled" value="1" <?php checked(1, (int) $o['enabled']); ?> /></td></tr>
                            <tr><th scope="row"><label for="dfss_tenant_id"><?php esc_html_e('Tenant ID', 'datafirefly-serverside'); ?></label></th>
                                <td><input type="text" id="dfss_tenant_id" name="dfss_tenant_id" class="regular-text" value="<?php echo esc_attr($o['tenant_id']); ?>" /></td></tr>
                            <tr><th scope="row"><label for="dfss_hmac_secret"><?php esc_html_e('HMAC secret', 'datafirefly-serverside'); ?></label></th>
                                <td><input type="text" id="dfss_hmac_secret" name="dfss_hmac_secret" class="regular-text" value="<?php echo esc_attr($o['hmac_secret']); ?>" /></td></tr>
                            <tr><th scope="row"><label for="dfss_endpoint"><?php esc_html_e('Endpoint', 'datafirefly-serverside'); ?></label></th>
                                <td><input type="url" id="dfss_endpoint" name="dfss_endpoint" class="regular-text" value="<?php echo esc_attr($o['endpoint']); ?>" /></td></tr>
                            <tr><th scope="row"><?php esc_html_e('Complete tracking', 'datafirefly-serverside'); ?></th>
                                <td><label><input type="checkbox" name="dfss_complete_tracking" value="1" <?php checked(1, (int) $o['complete_tracking']); ?> /> <?php esc_html_e('Client + full funnel', 'datafirefly-serverside'); ?></label></td></tr>
                            <tr><th scope="row"><?php esc_html_e('Require consent', 'datafirefly-serverside'); ?></th>
                                <td><label><input type="checkbox" name="dfss_require_consent" value="1" <?php checked(1, (int) $o['require_consent']); ?> /> <?php esc_html_e('Gate on marketing consent', 'datafirefly-serverside'); ?></label></td></tr>
                            <tr><th scope="row"><?php esc_html_e('Client destinations', 'datafirefly-serverside'); ?></th>
                                <td>
                                    <label style="display:block;"><input type="checkbox" name="dfss_dest_meta" value="1" <?php checked(1, (int) $o['dest_meta']); ?> /> Meta</label>
                                    <label style="display:block;"><input type="checkbox" name="dfss_dest_ga4" value="1" <?php checked(1, (int) $o['dest_ga4']); ?> /> GA4</label>
                                    <label style="display:block;"><input type="checkbox" name="dfss_dest_tiktok" value="1" <?php checked(1, (int) $o['dest_tiktok']); ?> /> TikTok</label>
                                </td></tr>
                        </table>
                        <p><button type="submit" name="dfss_save" class="button"><?php esc_html_e('Save', 'datafirefly-serverside'); ?></button></p>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // ---- Activity panel -----------------------------------------------------

    public function render_activity()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $o = $this->opts();
        $count24 = DFSS_Queue::count_last_24h();
        $pending = DFSS_Queue::count_pending();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('DataFirefly Activity', 'datafirefly-serverside'); ?></h1>

            <p style="font-size:14px;margin:12px 0;">
                <?php if ($this->is_connected()) : ?>
                    <span class="dashicons dashicons-yes-alt" style="color:#008D9E;"></span>
                    <strong><?php esc_html_e('Connected', 'datafirefly-serverside'); ?></strong>
                <?php else : ?>
                    <span class="dashicons dashicons-warning" style="color:#b32d2e;"></span>
                    <strong><?php esc_html_e('Not connected', 'datafirefly-serverside'); ?></strong>
                <?php endif; ?>
                &nbsp;|&nbsp;
                <?php
                /* translators: %d: number of events delivered in the last 24 hours. */
                echo esc_html(sprintf(__('Delivered in last 24h: %d', 'datafirefly-serverside'), $count24));
                ?>
                &nbsp;|&nbsp;
                <?php
                /* translators: %d: number of events currently queued for retry. */
                echo esc_html(sprintf(__('Queued for retry: %d', 'datafirefly-serverside'), $pending));
                ?>
            </p>

            <table class="widefat striped" style="max-width:1000px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Time', 'datafirefly-serverside'); ?></th>
                        <th><?php esc_html_e('Event', 'datafirefly-serverside'); ?></th>
                        <th><?php esc_html_e('Source', 'datafirefly-serverside'); ?></th>
                        <th><?php esc_html_e('Status', 'datafirefly-serverside'); ?></th>
                        <th><?php esc_html_e('Detail', 'datafirefly-serverside'); ?></th>
                    </tr>
                </thead>
                <tbody id="dfss-activity-rows">
                    <?php echo $this->activity_rows_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every value is escaped inside activity_rows_html(). ?>
                </tbody>
            </table>
            <p class="description" style="margin-top:8px;"><?php esc_html_e('Updates automatically every 30 seconds. The client side fires the browser pixel; the server side is the ad-blocker-proof delivery — both share one event id for deduplication.', 'datafirefly-serverside'); ?></p>
        </div>
        <?php
    }

    /**
     * ajax handler feeding the auto-refresh of the activity table body.
     */
    public function ajax_activity()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('forbidden', 403);
        }
        check_ajax_referer('dfss_activity');
        wp_send_json_success($this->activity_rows_html());
    }

    /**
     * Render the activity table rows. Every value is escaped here, so callers
     * can echo the result directly.
     *
     * @return string
     */
    private function activity_rows_html()
    {
        $rows = DFSS_Queue::recent(20);
        if (empty($rows)) {
            return '<tr><td colspan="5">' . esc_html__('No events yet.', 'datafirefly-serverside') . '</td></tr>';
        }

        $labels = array(
            DFSS_Queue::STATUS_DONE => __('Delivered', 'datafirefly-serverside'),
            DFSS_Queue::STATUS_PENDING => __('Queued (retry)', 'datafirefly-serverside'),
            DFSS_Queue::STATUS_FAILED => __('Rejected', 'datafirefly-serverside'),
            DFSS_Queue::STATUS_DROPPED => __('Gave up', 'datafirefly-serverside'),
        );
        $colors = array(
            DFSS_Queue::STATUS_DONE => '#008D9E',
            DFSS_Queue::STATUS_PENDING => '#b26a00',
            DFSS_Queue::STATUS_FAILED => '#b32d2e',
            DFSS_Queue::STATUS_DROPPED => '#b32d2e',
        );

        $html = '';
        foreach ($rows as $r) {
            $status = (string) $r->status;
            $label = isset($labels[$status]) ? $labels[$status] : $status;
            $color = isset($colors[$status]) ? $colors[$status] : '#555';
            $time = $r->created_at ? wp_date('Y-m-d H:i:s', (int) $r->created_at) : '';
            $detail = $r->last_code ? ('HTTP ' . (int) $r->last_code) : '';
            if ((int) $r->attempts > 1) {
                /* translators: %d: number of delivery attempts for this event. */
                $detail .= ' · ' . sprintf(__('%d attempts', 'datafirefly-serverside'), (int) $r->attempts);
            }

            $html .= '<tr>';
            $html .= '<td>' . esc_html($time) . '</td>';
            $html .= '<td><code>' . esc_html($r->event_name) . '</code></td>';
            $html .= '<td>' . esc_html($r->origin === 'beacon' ? __('client beacon', 'datafirefly-serverside') : __('server', 'datafirefly-serverside')) . '</td>';
            // Underline + bold in addition to colour (accessibility — never colour alone).
            $html .= '<td><strong style="color:' . esc_attr($color) . ';text-decoration:underline;">' . esc_html($label) . '</strong></td>';
            $html .= '<td>' . esc_html($detail) . '</td>';
            $html .= '</tr>';
        }

        return $html;
    }
}

// Activation / deactivation hooks (table + cron lifecycle).
register_activation_hook(__FILE__, array('DFSS_Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('DFSS_Plugin', 'deactivate'));

new DFSS_Plugin();
