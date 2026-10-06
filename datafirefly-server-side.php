<?php
/**
 * Plugin Name:       DataFirefly Server-Side
 * Description:       Complete WooCommerce tracking: client + server, full-funnel, deduplicated, GDPR-aware, reliable. One key configures everything; no destination credentials ever reach the browser.
 * Version:           2.28.0
 * Author:            DataFirefly Ltd
 * Author URI:        https://datafirefly.com
 * Requires PHP:      7.4
 * Requires at least: 5.8
 * WC requires at least: 5.0
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       datafirefly-server-side
 */

if (!defined('ABSPATH')) {
    exit;
}

// A pre-2.27.0 copy (folder "datafirefly-serverside") is already loaded: declaring the classes
// twice is fatal, so step aside. On activation, this copy switches the old one off and takes over
// its settings (see includes/class-dfss-legacy.php).
if (defined('DFSS_VERSION')) {
    if (!function_exists('dfss_take_over_legacy_copy')) {
        function dfss_take_over_legacy_copy()
        {
            deactivate_plugins('datafirefly-serverside/datafirefly-serverside.php', true);
        }
    }
    register_activation_hook(__FILE__, 'dfss_take_over_legacy_copy');

    return;
}

define('DFSS_VERSION', '2.28.0');
define('DFSS_PLUGIN_FILE', __FILE__);
define('DFSS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DFSS_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once DFSS_PLUGIN_DIR . 'includes/class-dfss-client.php';
require_once DFSS_PLUGIN_DIR . 'includes/class-dfss-event-builder.php';
require_once DFSS_PLUGIN_DIR . 'includes/class-dfss-consent.php';
require_once DFSS_PLUGIN_DIR . 'includes/class-dfss-settings.php';
require_once DFSS_PLUGIN_DIR . 'includes/class-dfss-queue.php';
require_once DFSS_PLUGIN_DIR . 'includes/class-dfss-rest.php';
require_once DFSS_PLUGIN_DIR . 'includes/class-dfss-truth.php';
require_once DFSS_PLUGIN_DIR . 'includes/class-dfss-legacy.php';

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

    /** @var array|null Per-request cache of opts(), flushed whenever the option changes. */
    private $opts_cache = null;

    public function __construct()
    {
        // ---- admin ---------------------------------------------------------
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'maybe_save'));
        // The first admin request on a new version refreshes the cached public ids.
        add_action('admin_init', array($this, 'maybe_upgrade'));
        add_action('admin_init', array(__CLASS__, 'ensure_cron'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin'));
        add_action('wp_ajax_dfss_activity', array($this, 'ajax_activity'));

        foreach (array('add_option_', 'update_option_', 'delete_option_') as $dfss_hook) {
            add_action($dfss_hook . self::OPTION, array($this, 'flush_opts'));
        }

        // ---- server-side events --------------------------------------------
        // Cookies and consent are captured at checkout, where the browser is; the purchase hook
        // may fire later from a gateway webhook that has none.
        add_action('woocommerce_checkout_create_order', array($this, 'capture_cookies'), 10, 2);
        // The block checkout (Store API), WooCommerce's default since 8.3, never fires the hook above.
        add_action('woocommerce_store_api_checkout_update_order_from_request', array($this, 'capture_cookies_store_api'), 10, 2);
        // Send the purchase once payment is in. Idempotent across the three hooks.
        add_action('woocommerce_payment_complete', array($this, 'on_purchase'));
        add_action('woocommerce_order_status_processing', array($this, 'on_purchase'));
        add_action('woocommerce_order_status_completed', array($this, 'on_purchase'));
        // Fires for partial and full refunds, with the refund id.
        add_action('woocommerce_order_refunded', array($this, 'on_refund'), 10, 2);
        // Server-side: only this hook knows the password was right.
        add_action('wp_login', array($this, 'on_login'), 10, 2);

        // ---- client tracking layer -----------------------------------------
        add_action('wp_enqueue_scripts', array($this, 'enqueue_tracker'));
        add_action('rest_api_init', array($this, 'register_rest'));

        // ---- cron ------------------------------------------------------------
        add_filter('cron_schedules', array($this, 'cron_schedules'));
        add_action(DFSS_Queue::CRON_HOOK, array($this, 'run_retry'));
        add_action(DFSS_Truth::CRON_HOOK, array($this, 'run_truth'));
        // Self-heal a lost schedule from admin and cron requests only, never on storefront pages.
        add_action('init', array($this, 'ensure_cron_on_cron'));
    }

    public function ensure_cron_on_cron()
    {
        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            self::ensure_cron();
        }
    }

    // ---- activation / deactivation -----------------------------------------

    public static function activate()
    {
        DFSS_Queue::install();
        DFSS_Queue::schedule_cron();
        DFSS_Truth::schedule_cron();
    }

    public static function deactivate()
    {
        DFSS_Queue::unschedule_cron();
        DFSS_Truth::unschedule_cron();
    }

    public static function ensure_cron()
    {
        DFSS_Queue::schedule_cron();
        DFSS_Truth::schedule_cron();
    }

    /**
     * Run once after the plugin version changes (fresh activate or v1->v2 upgrade).
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
                'display' => __('Every 5 minutes (DataFirefly retry)', 'datafirefly-server-side'),
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

    /**
     * Daily: tell the dispatcher what the shop actually sold yesterday.
     */
    public function run_truth()
    {
        $o = $this->opts();
        if (empty($o['enabled']) || $o['tenant_id'] === '' || $o['hmac_secret'] === '') {
            return;
        }
        DFSS_Truth::run($o, new DFSS_Client($o['tenant_id'], $o['hmac_secret'], $o['endpoint']));
    }

    // ---- options -----------------------------------------------------------

    /**
     * Plugin options merged with their defaults. Keys added in later versions get their default
     * on upgrade (wp_parse_args only fills missing keys), so an upgrade changes no behaviour.
     *
     * @return array
     */
    public function opts()
    {
        if ($this->opts_cache === null) {
            $saved = get_option(self::OPTION, array());
            $this->opts_cache = wp_parse_args(
                is_array($saved) ? $saved : array(),
                array_merge(
                    array(
                        'enabled' => 0,
                        'tenant_id' => '',
                        'hmac_secret' => '',
                        'endpoint' => self::DEFAULT_ENDPOINT,
                        'complete_tracking' => 1,
                        'require_consent' => 1,
                        'clickid_passthrough' => 1,
                        // Consent hold and Google advanced mode are compliance decisions: off by default.
                        'consent_hold_minutes' => 0,
                        'google_consent_mode' => 'basic',
                        'consent_default_region' => 'all',
                        'ads_data_redaction' => 1,
                    ),
                    DFSS_Settings::defaults()
                )
            );
        }

        return $this->opts_cache;
    }

    public function flush_opts()
    {
        $this->opts_cache = null;
    }

    /**
     * The public destination ids filtered by the destination toggles. The single choke point that
     * keeps a disabled destination's script out of the browser: no id, no tag.
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

        return DFSS_Settings::filter_public($public, $opts);
    }

    private function is_connected()
    {
        $o = $this->opts();

        return !empty($o['enabled']) && $o['tenant_id'] !== '' && $o['hmac_secret'] !== '';
    }

    /**
     * The cached public destination ids (pixel/measurement ids).
     *
     * @return array
     */
    private function public_config()
    {
        $cfg = get_option(self::PUBLIC_OPTION, array());

        return is_array($cfg) ? $cfg : array();
    }

    /**
     * Fetch the public-config from the dispatcher and cache it.
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

    // ---- checkout capture and server events --------------------------------

    /**
     * @param WC_Order $order
     * @param array    $data
     */
    public function capture_cookies($order, $data)
    {
        // The consent verdict, read here where the shopper's cookies are, and kept on the order.
        $order->update_meta_data('_dfss_consent', DFSS_Consent::server_verdict($this->opts()));

        $map = array('_fbp' => '_dfss_fbp', '_fbc' => '_dfss_fbc', '_ga' => '_dfss_ga', '_ttp' => '_dfss_ttp', '__oppref' => '_dfss_oppref', '__obref' => '_dfss_obref');
        foreach ($map as $cookie => $meta) {
            if (!empty($_COOKIE[$cookie])) {
                $order->update_meta_data($meta, sanitize_text_field(wp_unslash($_COOKIE[$cookie])));
            }
        }
        // Our own 90-day click-id cookies, as a fallback when the platform cookie is absent.
        $extra = array(
            '_dfss_fbc' => '_dfss_fbc',
            '_dfss_ttclid' => '_dfss_ttclid',
            '_dfss_gclid' => '_dfss_gclid',
            '_dfss_gbraid' => '_dfss_gbraid',
            '_dfss_wbraid' => '_dfss_wbraid',
            '_dfss_msclkid' => '_dfss_msclkid',
            '_dfss_oppref' => '_dfss_oppref',
        );
        foreach ($extra as $cookie => $meta) {
            if (!empty($_COOKIE[$cookie]) && !$order->get_meta($meta)) {
                $order->update_meta_data($meta, sanitize_text_field(wp_unslash($_COOKIE[$cookie])));
            }
        }
        // GA4 session cookie (_ga_<measurement id without G->) of OUR property only, so the
        // purchase joins the converting session instead of landing as "Unassigned".
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
     * The same capture for the block checkout (Store API).
     *
     * @param WC_Order $order
     * @param mixed    $request WP_REST_Request, unused.
     */
    public function capture_cookies_store_api($order, $request = null)
    {
        if (!$order instanceof WC_Order) {
            return;
        }
        $this->capture_cookies($order, array());
        $order->save();
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

            // Claim the order BEFORE sending (optimistic lock): the three hooks can overlap, and a
            // failed send belongs to the retry queue, not to a later hook.
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

    /**
     * A visitor signed in. Carries the user id only, and only with consent.
     *
     * @param string  $user_login
     * @param WP_User $user
     */
    public function on_login($user_login, $user = null)
    {
        try {
            $opts = $this->opts();
            if (empty($opts['enabled'])) {
                return;
            }
            $verdict = DFSS_Consent::server_verdict($opts);
            $payload = array(
                'eventId' => 'login_' . ($user instanceof WP_User ? (int) $user->ID : 0) . '_' . time(),
                'eventName' => 'login',
                'eventTime' => time(),
                'sourceUrl' => home_url('/'),
                'actionSource' => 'website',
                'userData' => array(),
                // Since dispatcher 0.64.0 a refusal is said, not left out (see build_purchase).
                'consent' => $verdict,
            );
            if ($verdict !== 'denied' && $user instanceof WP_User) {
                $payload['userData']['externalId'] = (string) $user->ID;
            }

            $client = new DFSS_Client($opts['tenant_id'], $opts['hmac_secret'], $opts['endpoint']);
            $result = $client->send($payload);
            DFSS_Queue::record_attempt($payload, $result, 'server');
        } catch (\Throwable $e) {
            // A sign-in must never fail because our measurement did.
        }
    }

    /**
     * A refund was issued. Not consent-gated and without personal data: no visitor is involved.
     *
     * @param int $order_id
     * @param int $refund_id
     */
    public function on_refund($order_id, $refund_id)
    {
        try {
            $opts = $this->opts();
            if (empty($opts['enabled'])) {
                return;
            }
            $refund = wc_get_order($refund_id);
            $order = wc_get_order($order_id);
            if (!$refund || !$order) {
                return;
            }

            $payload = DFSS_Event_Builder::build_refund($refund, $order);
            if (null === $payload) {
                return;
            }

            $client = new DFSS_Client($opts['tenant_id'], $opts['hmac_secret'], $opts['endpoint']);
            $result = $client->send($payload);
            DFSS_Queue::record_attempt($payload, $result, 'server');

            if (empty($result['ok'])) {
                $order->add_order_note('DataFirefly: refund event not delivered (HTTP ' . (int) $result['code'] . '). Queued for retry.');
                $order->save();
            }
        } catch (\Throwable $e) {
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->warning('DataFirefly refund send failed: ' . $e->getMessage(), array('source' => 'datafirefly-serverside'));
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
     * Enqueue the client tracker on the storefront, gated by connection + "Complete tracking".
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

        $public = $this->filtered_public_config(null, $o);
        // Footer + defer: never blocks rendering. WordPress < 6.3 reads the array as in_footer.
        $args = array('in_footer' => true, 'strategy' => 'defer');

        // Destination modules run before the core, which picks them up at boot. Only the enabled
        // and configured ones are enqueued: a disabled destination ships no code at all.
        $deps = array();
        foreach (DFSS_Settings::DESTINATIONS as $key => $dest) {
            if ($dest[3] !== '' && DFSS_Settings::is_configured($public, $key)) {
                $handle = 'dfss-dest-' . $dest[3];
                wp_register_script($handle, $this->asset_url('dfss-dest-' . $dest[3]), array(), DFSS_VERSION, $args);
                $deps[] = $handle;
            }
        }

        wp_register_script('dfss-tracker', $this->asset_url('dfss-tracker'), $deps, DFSS_VERSION, $args);
        wp_localize_script('dfss-tracker', 'DFSS_CFG', array(
            'public' => $public,
            'consent' => DFSS_Consent::js_config($o),
            'restUrl' => esc_url_raw(rest_url(DFSS_REST::REST_NAMESPACE . DFSS_REST::ROUTE)),
            'nonce' => wp_create_nonce('wp_rest'),
            // Fresh nonce source for pages served from a full-page cache.
            'nonceUrl' => esc_url_raw(rest_url(DFSS_REST::REST_NAMESPACE . '/nonce')),
            // The tracker only beacons what the endpoint accepts, and batches what needs no nonce.
            'beaconEvents' => DFSS_Event_Builder::BEACON_EVENTS,
            'nonceEvents' => DFSS_REST::NONCE_REQUIRED_EVENTS,
            'events' => $this->page_event_context(),
        ));
        wp_enqueue_script('dfss-tracker');

        foreach (DFSS_Settings::MODULES as $key => $module) {
            if (!empty($o[$key])) {
                wp_enqueue_script('dfss-' . $module, $this->asset_url('dfss-' . $module), array('dfss-tracker'), DFSS_VERSION, $args);
            }
        }
    }

    /**
     * URL of a tracker script: the minified build, unless SCRIPT_DEBUG is on or the source is
     * newer than the build (2 minutes of slack for archive extraction timestamps).
     *
     * @param string $name File name without extension, in assets/.
     *
     * @return string
     */
    private function asset_url($name)
    {
        $src = DFSS_PLUGIN_DIR . 'assets/' . $name . '.js';
        $min = DFSS_PLUGIN_DIR . 'assets/' . $name . '.min.js';
        $use_min = !(defined('SCRIPT_DEBUG') && SCRIPT_DEBUG)
            && is_file($min)
            && (!is_file($src) || filemtime($min) + 120 >= filemtime($src));

        return DFSS_PLUGIN_URL . 'assets/' . $name . ($use_min ? '.min.js' : '.js');
    }

    /**
     * The product category to report for a product line, as a human name.
     *
     * @param int $post_id
     *
     * @return string
     */
    private function product_category_label($post_id)
    {
        if (is_tax('product_cat')) {
            $dfss_term = get_queried_object();
            if ($dfss_term instanceof WP_Term) {
                return html_entity_decode(wp_strip_all_tags($dfss_term->name), ENT_QUOTES, 'UTF-8');
            }
        }
        $dfss_terms = get_the_terms((int) $post_id, 'product_cat');
        if (is_array($dfss_terms) && !empty($dfss_terms)) {
            $dfss_first = reset($dfss_terms);
            if ($dfss_first instanceof WP_Term) {
                return html_entity_decode(wp_strip_all_tags($dfss_first->name), ENT_QUOTES, 'UTF-8');
            }
        }

        return '';
    }

    /**
     * Build the per-page event context the tracker needs (server-authoritative values for
     * value/currency/products), so the browser never has to guess.
     *
     * @return array
     */
    private function page_event_context()
    {
        $ctx = array();

        // view_content on a content page (article, service page...). Built even without WooCommerce;
        // products report view_item below instead.
        if (is_singular() && !(function_exists('is_product') && is_product())) {
            $dfss_post = get_queried_object();
            if ($dfss_post instanceof WP_Post) {
                $ctx['content'] = array(
                    // The post TYPE is part of the id so an article and a page that share a number
                    // stay two distinct lines.
                    'id' => $dfss_post->post_type . '-' . (int) $dfss_post->ID,
                    'name' => html_entity_decode(wp_strip_all_tags(get_the_title($dfss_post)), ENT_QUOTES, 'UTF-8'),
                    'category' => $dfss_post->post_type,
                );
            }
        }

        // search: the term as WordPress resolved it.
        if (is_search()) {
            $dfss_q = trim((string) get_search_query());
            if ('' !== $dfss_q) {
                $ctx['search'] = array(
                    'searchString' => function_exists('mb_substr')
                        ? mb_substr($dfss_q, 0, 200)
                        : substr($dfss_q, 0, 200),
                );
            }
        }

        // view_item_list on a listing page (blog index, archive, search results).
        if ((is_home() || is_archive() || is_search()) && !(function_exists('is_shop') && is_shop())) {
            $dfss_items = array();
            if (have_posts()) {
                global $wp_query;
                $dfss_posts = isset($wp_query->posts) && is_array($wp_query->posts) ? $wp_query->posts : array();
                foreach (array_slice($dfss_posts, 0, 20) as $dfss_p) {
                    if (!$dfss_p instanceof WP_Post) {
                        continue;
                    }
                    // Products keep their bare id: GA4 and Meta match it against the product feed.
                    if ('product' === $dfss_p->post_type && function_exists('is_product')) {
                        $dfss_items[] = array(
                            'id' => (string) (int) $dfss_p->ID,
                            'name' => html_entity_decode(wp_strip_all_tags(get_the_title($dfss_p)), ENT_QUOTES, 'UTF-8'),
                            'category' => $this->product_category_label($dfss_p->ID),
                        );
                        continue;
                    }
                    $dfss_items[] = array(
                        'id' => $dfss_p->post_type . '-' . (int) $dfss_p->ID,
                        'name' => html_entity_decode(wp_strip_all_tags(get_the_title($dfss_p)), ENT_QUOTES, 'UTF-8'),
                        'category' => $dfss_p->post_type,
                    );
                }
            }
            $dfss_list_id = 'archive';
            $dfss_list_name = 'Archive';
            if (is_search()) {
                $dfss_list_id = 'search';
                $dfss_list_name = 'Search results';
            } elseif (is_home()) {
                $dfss_list_id = 'blog';
                $dfss_list_name = 'Blog';
            } elseif (is_category() || is_tag() || is_tax()) {
                $dfss_term = get_queried_object();
                if ($dfss_term instanceof WP_Term) {
                    $dfss_list_id = $dfss_term->taxonomy . '-' . $dfss_term->slug;
                    $dfss_list_name = html_entity_decode(wp_strip_all_tags($dfss_term->name), ENT_QUOTES, 'UTF-8');
                }
            } elseif (is_post_type_archive()) {
                $dfss_list_id = 'post-type-' . (string) get_query_var('post_type');
                $dfss_list_name = (string) post_type_archive_title('', false);
            }

            if (!empty($dfss_items)) {
                $ctx['contentList'] = array(
                    'listId' => $dfss_list_id,
                    'listName' => $dfss_list_name,
                    'products' => $dfss_items,
                );
            }
        }

        if (!function_exists('is_product')) {
            return $ctx; // WooCommerce not loaded: content context only
        }

        // view_cart.
        if (function_exists('is_cart') && is_cart()) {
            $dfss_cart = function_exists('WC') ? WC()->cart : null;
            if ($dfss_cart && !$dfss_cart->is_empty()) {
                $dfss_cart_items = array();
                $dfss_cart_n = 0;
                foreach ($dfss_cart->get_cart() as $dfss_item) {
                    if (empty($dfss_item['data']) || !$dfss_item['data'] instanceof WC_Product) {
                        continue;
                    }
                    $dfss_qty = (int) $dfss_item['quantity'];
                    $dfss_cart_n += $dfss_qty;
                    $dfss_cart_items[] = array(
                        'id' => (string) $dfss_item['data']->get_id(),
                        'name' => $dfss_item['data']->get_name(),
                        'price' => round((float) wc_get_price_to_display($dfss_item['data']), 2),
                        'quantity' => $dfss_qty,
                    );
                }
                if (!empty($dfss_cart_items)) {
                    $ctx['cart'] = array(
                        'value' => round((float) $dfss_cart->get_total('edit'), 2),
                        'currency' => get_woocommerce_currency(),
                        'numItems' => $dfss_cart_n,
                        'products' => $dfss_cart_items,
                    );
                }
            }
        }

        // view_item on a product page.
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

        // initiate_checkout on the checkout page, except the order-received page (purchase).
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

        // purchase on the thank-you page, with event id "order_<id>" to deduplicate with the server.
        if (function_exists('is_order_received_page') && is_order_received_page()) {
            // Read-only lookup of the public thank-you-page order id; the value is cast to int and
            // only used to render tracking context.
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $order_id = absint(get_query_var('order-received'));
            if (!$order_id && isset($_GET['order-received'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $order_id = absint(wp_unslash($_GET['order-received']));
            }
            $order = $order_id ? wc_get_order($order_id) : null;
            // Require the order key, as WooCommerce does: the id alone would expose any order.
            // wc_clean() is WooCommerce's sanitizer (unknown to PHPCS); the key only feeds hash_equals().
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $order_key = isset($_GET['key']) ? wc_clean(wp_unslash($_GET['key'])) : '';
            if ($order instanceof WC_Order && ($order_key === '' || !hash_equals((string) $order->get_order_key(), (string) $order_key))) {
                $order = null;
            }
            if ($order instanceof WC_Order) {
                $products = array();
                $num_items = 0;
                foreach ($order->get_items() as $item) {
                    if (!$item instanceof WC_Order_Item_Product) {
                        continue;
                    }
                    $qty = (int) $item->get_quantity();
                    $num_items += $qty;
                    // Tax included, like the order value: on an order ITEM, get_total() is net of tax.
                    $line_total = (float) $item->get_total() + (float) $item->get_total_tax();
                    $products[] = array(
                        'id' => DFSS_Event_Builder::order_line_id($item),
                        'name' => $item->get_name(),
                        'price' => $qty > 0 ? round($line_total / $qty, 2) : round($line_total, 2),
                        'quantity' => $qty,
                    );
                }
                $ctx['purchase'] = array(
                    'eventId' => 'order_' . $order->get_id(),
                    'orderId' => (string) $order->get_order_number(),
                    'value' => round((float) $order->get_total(), 2),
                    // Net of tax, for the merchant's own reporting.
                    'valueNet' => round((float) $order->get_total() - (float) $order->get_total_tax(), 2),
                    'currency' => $order->get_currency(),
                    'numItems' => $num_items,
                    'products' => $products,
                    // Verdict stored at checkout. In Consent Mode advanced the tracker sends the purchase
                    // to GA4 itself only when the server did not.
                    'consent' => (string) $order->get_meta('_dfss_consent'),
                );
            }
        }

        return $ctx;
    }

    // ---- admin UI ----------------------------------------------------------

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
            __('DataFirefly Activity', 'datafirefly-server-side'),
            __('DataFirefly Activity', 'datafirefly-server-side'),
            'manage_options',
            'datafirefly-activity',
            array($this, 'render_activity')
        );
    }

    public function enqueue_admin($hook)
    {
        // Only on our two screens (settings_page_datafirefly-serverside / -activity).
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
     * Decode a one-paste connection key (dfss_<base64url(json{t,s,e})>) into config.
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
        // Tenant ids and secrets are opaque tokens (hex / base64url / uuid).
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
            // An untrusted host in the key falls back to the default: signed events never leave
            // datafirefly.com unless typed in the advanced form.
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
            // The key is base64url ("dfss_<...>") so sanitize_text_field cannot alter a valid key;
            // decode_key() then re-validates the charset.
            $decoded = $this->decode_key(isset($_POST['dfss_connkey']) ? sanitize_text_field(wp_unslash($_POST['dfss_connkey'])) : '');
            if (null === $decoded) {
                add_settings_error('dfss', 'badkey', __('That connection key is not valid. Copy it again from your DataFirefly client space.', 'datafirefly-server-side'), 'error');

                return;
            }
            // Connecting turns on complete tracking, consent gating and every destination.
            $opts = array_merge($decoded, array(
                'enabled' => 1,
                'complete_tracking' => 1,
                'require_consent' => 1,
            ), DFSS_Settings::defaults());
            update_option(self::OPTION, $opts);
            $opts = $this->opts();

            DFSS_Queue::install();
            DFSS_Queue::schedule_cron();

            $pub = $this->refresh_public_config($opts);

            // Verify the connection with a test event.
            $this->run_test($opts, true, $pub);

            return;
        }

        if (isset($_POST['dfss_disconnect'])) {
            update_option(self::OPTION, array_merge(array(
                'enabled' => 0, 'tenant_id' => '', 'hmac_secret' => '',
                'endpoint' => self::DEFAULT_ENDPOINT,
                'complete_tracking' => 1, 'require_consent' => 1,
            ), DFSS_Settings::defaults()));
            delete_option(self::PUBLIC_OPTION);
            add_settings_error('dfss', 'disconnected', __('Disconnected.', 'datafirefly-server-side'), 'updated');

            return;
        }

        // Connected view: tracking, consent, destinations and modules.
        if (isset($_POST['dfss_update_toggles'])) {
            $o = $this->opts();
            $o['complete_tracking'] = isset($_POST['dfss_complete_tracking']) ? 1 : 0;
            $o['require_consent'] = isset($_POST['dfss_require_consent']) ? 1 : 0;
            $o = DFSS_Settings::apply_destination_fields($o, wp_unslash($_POST));
            $o = DFSS_Settings::apply_consent_fields($o, wp_unslash($_POST));
            update_option(self::OPTION, $o);
            add_settings_error('dfss', 'toggles', __('Tracking settings saved.', 'datafirefly-server-side'), 'updated');

            return;
        }

        // Refresh the cached public destination ids.
        if (isset($_POST['dfss_refresh_public'])) {
            $pub = $this->refresh_public_config($this->opts());
            if (!empty($pub['ok'])) {
                add_settings_error('dfss', 'pub_ok', __('Destination ids refreshed.', 'datafirefly-server-side'), 'updated');
            } else {
                /* translators: %d: HTTP status code returned by the dispatcher. */
                add_settings_error('dfss', 'pub_ko', sprintf(__('Could not refresh destination ids (HTTP %d).', 'datafirefly-server-side'), (int) $pub['code']), 'error');
            }

            return;
        }

        // Manual save (advanced). The secret is validated, never sanitized (that could alter it), and
        // an empty field keeps the stored one since the form never echoes it.
        if (isset($_POST['dfss_save'])) {
            $secret = $this->opts()['hmac_secret'];
            $typed = isset($_POST['dfss_hmac_secret']) ? trim((string) wp_unslash($_POST['dfss_hmac_secret'])) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated against a strict charset just below, never altered.
            if ($typed !== '') {
                if (!preg_match('/^[A-Za-z0-9+\/=_.\-]{1,512}$/', $typed)) {
                    add_settings_error('dfss', 'badsecret', __('The HMAC secret contains characters that are not part of a DataFirefly secret. Copy it again from your client space.', 'datafirefly-server-side'), 'error');

                    return;
                }
                $secret = $typed;
            }

            // Signed events go wherever this points: valid https only (no loopback or private range).
            $endpoint = isset($_POST['dfss_endpoint']) ? esc_url_raw(wp_unslash($_POST['dfss_endpoint'])) : '';
            $scheme = $endpoint !== '' ? wp_parse_url($endpoint, PHP_URL_SCHEME) : '';
            if ($endpoint === '' || strtolower((string) $scheme) !== 'https' || !wp_http_validate_url($endpoint)) {
                add_settings_error('dfss', 'badendpoint', __('The endpoint must be a valid https:// URL. Settings were not saved.', 'datafirefly-server-side'), 'error');

                return;
            }

            $opts = array(
                'enabled' => isset($_POST['dfss_enabled']) ? 1 : 0,
                'tenant_id' => isset($_POST['dfss_tenant_id']) ? sanitize_text_field(wp_unslash($_POST['dfss_tenant_id'])) : '',
                'hmac_secret' => $secret,
                'endpoint' => $endpoint,
                'complete_tracking' => isset($_POST['dfss_complete_tracking']) ? 1 : 0,
                'require_consent' => isset($_POST['dfss_require_consent']) ? 1 : 0,
            );
            $opts = DFSS_Settings::apply_destination_fields(array_merge($this->opts(), $opts), wp_unslash($_POST));
            $opts = DFSS_Settings::apply_consent_fields($opts, wp_unslash($_POST));
            update_option(self::OPTION, $opts);
            if (!empty($opts['enabled']) && $opts['tenant_id'] !== '' && $opts['hmac_secret'] !== '') {
                DFSS_Queue::install();
                DFSS_Queue::schedule_cron();
                $this->refresh_public_config($opts);
            }
            add_settings_error('dfss', 'saved', __('Settings saved.', 'datafirefly-server-side'), 'updated');

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
            // No 'consent': there is no visitor, so nothing to report.
            'userData' => array('clientUserAgent' => 'DataFirefly-Test'),
        );

        $client = new DFSS_Client($opts['tenant_id'], $opts['hmac_secret'], $opts['endpoint']);
        $result = $client->send($payload);

        // The dispatcher accepted the signed request unless it's an auth/state reject.
        $code = (int) $result['code'];
        $connection_ok = $code > 0 && !in_array($code, array(401, 403), true);

        if ($just_connected) {
            if ($connection_ok) {
                $msg = __('Connected! Your shop is live, a test event just reached DataFirefly.', 'datafirefly-server-side');
                if (is_array($pub) && !empty($pub['ok'])) {
                    $dests = $this->describe_destinations($this->filtered_public_config($pub['public'], $opts));
                    if ($dests !== '') {
                        /* translators: %s: comma-separated list of destinations (e.g. Meta, GA4, TikTok). */
                        $msg .= ' ' . sprintf(__('Client tags will load for: %s.', 'datafirefly-server-side'), $dests);
                    }
                }
                add_settings_error('dfss', 'connected', $msg, 'updated');
            } else {
                /* translators: %d: HTTP status code returned by the dispatcher. */
                add_settings_error('dfss', 'connfail', sprintf(__('Connected, but the test was rejected (HTTP %d). Ask your DataFirefly operator to check your tenant is active.', 'datafirefly-server-side'), $code), 'error');
            }

            return;
        }

        if (!empty($result['ok'])) {
            /* translators: %d: HTTP status code returned by the dispatcher. */
            add_settings_error('dfss', 'test_ok', sprintf(__('Test event delivered (HTTP %d).', 'datafirefly-server-side'), $code), 'updated');
        } elseif ($connection_ok) {
            /* translators: %d: HTTP status code returned by the dispatcher. */
            add_settings_error('dfss', 'test_partial', sprintf(__('Reached DataFirefly (HTTP %d): a destination rejected the test event. Your connection is fine.', 'datafirefly-server-side'), $code), 'updated');
        } else {
            /* translators: 1: HTTP status code, 2: error message from the dispatcher. */
            add_settings_error('dfss', 'test_ko', sprintf(__('Test failed: HTTP %1$d, %2$s', 'datafirefly-server-side'), $code, esc_html($result['message'])), 'error');
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
        foreach (DFSS_Settings::DESTINATIONS as $key => $dest) {
            if (DFSS_Settings::is_configured($public, $key)) {
                $names[] = $dest[2];
            }
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
                        <strong><?php esc_html_e('Connected', 'datafirefly-server-side'); ?></strong> :
                        <?php esc_html_e('tracking is sent to', 'datafirefly-server-side'); ?>
                        <code><?php echo esc_html($o['tenant_id']); ?></code>.
                        <?php
                        $dests = $this->describe_destinations($active_public);
                        if ($dests !== '') {
                            /* translators: %s: comma-separated list of destinations (e.g. Meta, GA4, TikTok). */
                            echo ' ' . esc_html(sprintf(__('Client tags: %s.', 'datafirefly-server-side'), $dests));
                        }
                        ?>
                    </p>
                </div>

                <form method="post" action="">
                    <?php wp_nonce_field('dfss_save', 'dfss_nonce'); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php esc_html_e('Complete tracking', 'datafirefly-server-side'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="dfss_complete_tracking" value="1" <?php checked(1, (int) $o['complete_tracking']); ?> />
                                    <?php esc_html_e('Inject the light client tags and track the full funnel (page view, product view, add to cart, checkout, purchase). Recommended.', 'datafirefly-server-side'); ?>
                                </label>
                                <p class="description"><?php esc_html_e('When off, only the server-side purchase event is sent (v1 behaviour).', 'datafirefly-server-side'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Require consent', 'datafirefly-server-side'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="dfss_require_consent" value="1" <?php checked(1, (int) $o['require_consent']); ?> />
                                    <?php esc_html_e('Do not fire anything until marketing consent is granted. Detected without configuration: WP Consent API, Complianz, DataFirefly Cookie Consent, Cookiebot, IAB TCF v2, Didomi, Usercentrics, CookieYes, Iubenda, OneTrust, Cookiehub, Osano, Borlabs, Klaro, tarteaucitron.', 'datafirefly-server-side'); ?>
                                </label>
                                <?php if (!DFSS_Consent::has_wp_consent_api()) : ?>
                                    <p class="description"><?php esc_html_e('Tip: install the WP Consent API plugin for the most reliable consent signal.', 'datafirefly-server-side'); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php $this->render_destination_fields($o, $public); ?>
                        <?php $this->render_consent_fields($o); ?>
                    </table>
                    <p><button type="submit" name="dfss_update_toggles" class="button button-primary"><?php esc_html_e('Save settings', 'datafirefly-server-side'); ?></button></p>
                </form>

                <p style="margin-top:8px;">
                    <a href="<?php echo esc_url(admin_url('options-general.php?page=datafirefly-activity')); ?>"><?php esc_html_e('View activity', 'datafirefly-server-side'); ?></a>
                </p>

                <hr style="margin:24px 0;" />

                <form method="post" action="" style="display:inline-block;margin-right:8px;">
                    <?php wp_nonce_field('dfss_save', 'dfss_nonce'); ?>
                    <button type="submit" name="dfss_test" class="button"><?php esc_html_e('Send test event', 'datafirefly-server-side'); ?></button>
                </form>
                <form method="post" action="" style="display:inline-block;margin-right:8px;">
                    <?php wp_nonce_field('dfss_save', 'dfss_nonce'); ?>
                    <button type="submit" name="dfss_refresh_public" class="button"><?php esc_html_e('Refresh destination ids', 'datafirefly-server-side'); ?></button>
                </form>
                <form method="post" action="" style="display:inline-block;" onsubmit="return confirm('<?php echo esc_js(__('Disconnect this shop from DataFirefly?', 'datafirefly-server-side')); ?>');">
                    <?php wp_nonce_field('dfss_save', 'dfss_nonce'); ?>
                    <button type="submit" name="dfss_disconnect" class="button button-link-delete"><?php esc_html_e('Disconnect', 'datafirefly-server-side'); ?></button>
                </form>

            <?php else : ?>
                <div class="card" style="max-width:620px;padding:8px 24px 24px;margin-top:16px;">
                    <h2><?php esc_html_e('Connect your shop', 'datafirefly-server-side'); ?></h2>
                    <p class="description" style="font-size:13px;"><?php esc_html_e('Paste the connection key from your DataFirefly client space (Connect your shop). That is the only step: we configure client and server tracking for you.', 'datafirefly-server-side'); ?></p>
                    <form method="post" action="">
                        <?php wp_nonce_field('dfss_save', 'dfss_nonce'); ?>
                        <p>
                            <input type="password" name="dfss_connkey" class="large-text code" placeholder="dfss_..." autocomplete="off" />
                        </p>
                        <p>
                            <button type="submit" name="dfss_connect" class="button button-primary button-hero"><?php esc_html_e('Connect', 'datafirefly-server-side'); ?></button>
                        </p>
                    </form>
                </div>

                <p style="margin-top:18px;">
                    <a href="#" data-dfss-toggle-advanced><?php esc_html_e('Advanced: enter credentials manually', 'datafirefly-server-side'); ?></a>
                </p>
                <div id="dfss-adv" style="display:none;max-width:620px;">
                    <form method="post" action="">
                        <?php wp_nonce_field('dfss_save', 'dfss_nonce'); ?>
                        <table class="form-table" role="presentation">
                            <tr><th scope="row"><label for="dfss_enabled"><?php esc_html_e('Enable', 'datafirefly-server-side'); ?></label></th>
                                <td><input type="checkbox" id="dfss_enabled" name="dfss_enabled" value="1" <?php checked(1, (int) $o['enabled']); ?> /></td></tr>
                            <tr><th scope="row"><label for="dfss_tenant_id"><?php esc_html_e('Tenant ID', 'datafirefly-server-side'); ?></label></th>
                                <td><input type="text" id="dfss_tenant_id" name="dfss_tenant_id" class="regular-text" value="<?php echo esc_attr($o['tenant_id']); ?>" /></td></tr>
                            <tr><th scope="row"><label for="dfss_hmac_secret"><?php esc_html_e('HMAC secret', 'datafirefly-server-side'); ?></label></th>
                                <td><input type="password" id="dfss_hmac_secret" name="dfss_hmac_secret" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo $o['hmac_secret'] !== '' ? esc_attr__('Configured. Leave empty to keep it.', 'datafirefly-server-side') : ''; ?>" /></td></tr>
                            <tr><th scope="row"><label for="dfss_endpoint"><?php esc_html_e('Endpoint', 'datafirefly-server-side'); ?></label></th>
                                <td><input type="url" id="dfss_endpoint" name="dfss_endpoint" class="regular-text" value="<?php echo esc_attr($o['endpoint']); ?>" /></td></tr>
                            <tr><th scope="row"><?php esc_html_e('Complete tracking', 'datafirefly-server-side'); ?></th>
                                <td><label><input type="checkbox" name="dfss_complete_tracking" value="1" <?php checked(1, (int) $o['complete_tracking']); ?> /> <?php esc_html_e('Client + full funnel', 'datafirefly-server-side'); ?></label></td></tr>
                            <tr><th scope="row"><?php esc_html_e('Require consent', 'datafirefly-server-side'); ?></th>
                                <td><label><input type="checkbox" name="dfss_require_consent" value="1" <?php checked(1, (int) $o['require_consent']); ?> /> <?php esc_html_e('Gate on marketing consent', 'datafirefly-server-side'); ?></label></td></tr>
                            <?php $this->render_destination_fields($o, $public); ?>
                            <?php $this->render_consent_fields($o); ?>
                        </table>
                        <p><button type="submit" name="dfss_save" class="button"><?php esc_html_e('Save', 'datafirefly-server-side'); ?></button></p>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Browser destinations and optional modules, shown in both forms. The hidden field tells the
     * save handler this form carries them (DFSS_Settings::apply_destination_fields).
     *
     * @param array $o      Saved options.
     * @param array $public Unfiltered public ids: shows availability, not the toggle.
     */
    private function render_destination_fields(array $o, array $public)
    {
        ?>
                            <tr><th scope="row"><?php esc_html_e('Browser tags', 'datafirefly-server-side'); ?></th>
                                <td>
                                    <input type="hidden" name="dfss_has_destination_fields" value="1" />
                                    <?php foreach (DFSS_Settings::DESTINATIONS as $dfss_key => $dfss_dest) : ?>
                                        <label style="display:block;margin-bottom:6px;">
                                            <input type="checkbox" name="dfss_<?php echo esc_attr($dfss_key); ?>" value="1" <?php checked(1, (int) $o[$dfss_key]); ?> />
                                            <?php echo esc_html($dfss_dest[2]); ?>
                                            <?php if (!DFSS_Settings::is_configured($public, $dfss_key)) : ?>
                                                <em class="description">(<?php esc_html_e('not configured on your DataFirefly account', 'datafirefly-server-side'); ?>)</em>
                                            <?php endif; ?>
                                        </label>
                                    <?php endforeach; ?>
                                    <p class="description"><?php esc_html_e('Uncheck a platform you do not use: its code is not sent to the browser at all, and its third-party script and cookies never load. Server-side delivery is managed in your DataFirefly client space and is not affected.', 'datafirefly-server-side'); ?></p>
                                </td></tr>
                            <tr><th scope="row"><?php esc_html_e('Lead and engagement events', 'datafirefly-server-side'); ?></th>
                                <td>
                                    <label><input type="checkbox" name="dfss_mod_engagement" value="1" <?php checked(1, (int) $o['mod_engagement']); ?> /> <?php esc_html_e('Enabled', 'datafirefly-server-side'); ?></label>
                                    <p class="description"><?php esc_html_e('Detects clicks on email and phone links, booking links (Calendly...), social shares, free-trial links, newsletter sign-ups, job applications and tagged donate / store-locator buttons. Useful for content and service sites. A shop that does not need them can switch them off: the script is then not loaded. Lead forms, registration and the purchase funnel are not affected.', 'datafirefly-server-side'); ?></p>
                                </td></tr>
        <?php
    }

    /**
     * The five consent settings, shown in both the connected and the manual form.
     *
     * @param array $o Saved options.
     */
    private function render_consent_fields(array $o)
    {
        ?>
                            <tr><th scope="row"><?php esc_html_e('Carry the ad click ID across pages', 'datafirefly-server-side'); ?></th>
                                <td>
                                    <input type="hidden" name="dfss_has_consent_fields" value="1" />
                                    <label><input type="checkbox" name="dfss_clickid_passthrough" value="1" <?php checked(1, (int) ($o['clickid_passthrough'] ?? 1)); ?> /> <?php esc_html_e('Enabled', 'datafirefly-server-side'); ?></label>
                                    <p class="description"><?php esc_html_e('A Google click ID only exists in the URL of the landing page. Without this, a shopper who arrives from an ad, browses, then accepts the banner has already lost it, and the sale can never be attributed. This carries it on your own internal links, in the URL only: nothing is written to the device, and it is never passed to another site. The cookie itself still waits for consent.', 'datafirefly-server-side'); ?></p>
                                </td></tr>
                            <tr><th scope="row"><?php esc_html_e('Hold events until consent (minutes)', 'datafirefly-server-side'); ?></th>
                                <td>
                                    <input type="number" min="0" max="1440" step="1" name="dfss_consent_hold_minutes" value="<?php echo esc_attr((string) ($o['consent_hold_minutes'] ?? 0)); ?>" class="small-text" />
                                    <p class="description"><?php esc_html_e('A shopper who has not yet answered the banner is not a shopper who refused. With a value above zero, their events wait IN THEIR OWN BROWSER for that many minutes: nothing reaches your shop or DataFirefly. If they accept, the events are sent. If they refuse, or the delay passes, they are discarded. An explicit refusal is never held, whatever the value. Zero disables it, and zero is the default: this is a compliance decision, not a technical setting. Ask your data protection officer.', 'datafirefly-server-side'); ?></p>
                                </td></tr>
                            <tr><th scope="row"><?php esc_html_e('Google consent mode', 'datafirefly-server-side'); ?></th>
                                <td>
                                    <select name="dfss_google_consent_mode">
                                        <option value="basic" <?php selected('basic', (string) ($o['google_consent_mode'] ?? 'basic')); ?>><?php esc_html_e('Basic (nothing loads before consent)', 'datafirefly-server-side'); ?></option>
                                        <option value="advanced" <?php selected('advanced', (string) ($o['google_consent_mode'] ?? 'basic')); ?>><?php esc_html_e('Advanced (Google tags load cookieless before consent)', 'datafirefly-server-side'); ?></option>
                                    </select>
                                    <p class="description"><?php esc_html_e('Basic is the default: no tag of any kind loads until the visitor accepts. Advanced loads the Google tags (GA4, Google Ads) as soon as the page opens, with consent denied: until the visitor accepts, they send cookieless pings (time, browser, referring page, consent state; the IP address is truncated) that Google uses to model the conversions and visits it cannot see. Meta, TikTok and every other platform still wait for consent. This is a compliance decision, not a technical setting: ask your data protection officer.', 'datafirefly-server-side'); ?></p>
                                </td></tr>
                            <tr><th scope="row"><?php esc_html_e('Where consent is denied by default', 'datafirefly-server-side'); ?></th>
                                <td>
                                    <select name="dfss_consent_default_region">
                                        <option value="all" <?php selected('all', (string) ($o['consent_default_region'] ?? 'all')); ?>><?php esc_html_e('Everywhere', 'datafirefly-server-side'); ?></option>
                                        <option value="eea" <?php selected('eea', (string) ($o['consent_default_region'] ?? 'all')); ?>><?php esc_html_e('EEA, United Kingdom and Switzerland only', 'datafirefly-server-side'); ?></option>
                                    </select>
                                    <p class="description"><?php esc_html_e('Advanced mode only. With the second choice, visitors outside these countries are treated as consenting by the Google tags until they answer. Applies only when your consent tool does not already send Google its own default: if it does (Cookiebot, Complianz, DataFirefly Cookie Consent...), its default wins and this setting has no effect.', 'datafirefly-server-side'); ?></p>
                                </td></tr>
                            <tr><th scope="row"><?php esc_html_e('Hide the ad click ID while ads consent is denied', 'datafirefly-server-side'); ?></th>
                                <td>
                                    <label><input type="checkbox" name="dfss_ads_data_redaction" value="1" <?php checked(1, (int) ($o['ads_data_redaction'] ?? 1)); ?> /> <?php esc_html_e('Enabled', 'datafirefly-server-side'); ?></label>
                                    <p class="description"><?php esc_html_e('Advanced mode only. Removes the Google click ID from the cookieless pings until the visitor accepts advertising. The most privacy-protective choice; Google says it can reduce modelling accuracy.', 'datafirefly-server-side'); ?></p>
                                </td></tr>
        <?php
    }

    // ---- Activity panel ----------------------------------------------------

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
            <h1><?php esc_html_e('DataFirefly Activity', 'datafirefly-server-side'); ?></h1>

            <p style="font-size:14px;margin:12px 0;">
                <?php if ($this->is_connected()) : ?>
                    <span class="dashicons dashicons-yes-alt" style="color:#008D9E;"></span>
                    <strong><?php esc_html_e('Connected', 'datafirefly-server-side'); ?></strong>
                <?php else : ?>
                    <span class="dashicons dashicons-warning" style="color:#b32d2e;"></span>
                    <strong><?php esc_html_e('Not connected', 'datafirefly-server-side'); ?></strong>
                <?php endif; ?>
                &nbsp;|&nbsp;
                <?php
                /* translators: %d: number of events delivered in the last 24 hours. */
                echo esc_html(sprintf(__('Delivered in last 24h: %d', 'datafirefly-server-side'), $count24));
                ?>
                &nbsp;|&nbsp;
                <?php
                /* translators: %d: number of events currently queued for retry. */
                echo esc_html(sprintf(__('Queued for retry: %d', 'datafirefly-server-side'), $pending));
                ?>
            </p>

            <table class="widefat striped" style="max-width:1000px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Time', 'datafirefly-server-side'); ?></th>
                        <th><?php esc_html_e('Event', 'datafirefly-server-side'); ?></th>
                        <th><?php esc_html_e('Source', 'datafirefly-server-side'); ?></th>
                        <th><?php esc_html_e('Status', 'datafirefly-server-side'); ?></th>
                        <th><?php esc_html_e('Detail', 'datafirefly-server-side'); ?></th>
                    </tr>
                </thead>
                <tbody id="dfss-activity-rows">
                    <?php echo $this->activity_rows_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every value is escaped inside activity_rows_html(). ?>
                </tbody>
            </table>
            <p class="description" style="margin-top:8px;"><?php esc_html_e('Updates automatically every 30 seconds. The client side fires the browser pixel; the server side is the ad-blocker-proof delivery, and both share one event id for deduplication.', 'datafirefly-server-side'); ?></p>
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
     * Render the activity table rows.
     *
     * @return string
     */
    private function activity_rows_html()
    {
        $rows = DFSS_Queue::recent(20);
        if (empty($rows)) {
            return '<tr><td colspan="5">' . esc_html__('No events yet.', 'datafirefly-server-side') . '</td></tr>';
        }

        $labels = array(
            DFSS_Queue::STATUS_DONE => __('Delivered', 'datafirefly-server-side'),
            DFSS_Queue::STATUS_PENDING => __('Queued (retry)', 'datafirefly-server-side'),
            DFSS_Queue::STATUS_SENDING => __('Retrying', 'datafirefly-server-side'),
            DFSS_Queue::STATUS_FAILED => __('Rejected', 'datafirefly-server-side'),
            DFSS_Queue::STATUS_DROPPED => __('Gave up', 'datafirefly-server-side'),
        );
        $colors = array(
            DFSS_Queue::STATUS_DONE => '#008D9E',
            DFSS_Queue::STATUS_PENDING => '#b26a00',
            DFSS_Queue::STATUS_SENDING => '#b26a00',
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
                $detail .= ' · ' . sprintf(__('%d attempts', 'datafirefly-server-side'), (int) $r->attempts);
            }

            $html .= '<tr>';
            $html .= '<td>' . esc_html($time) . '</td>';
            $html .= '<td><code>' . esc_html($r->event_name) . '</code></td>';
            $html .= '<td>' . esc_html($r->origin === 'beacon' ? __('client beacon', 'datafirefly-server-side') : __('server', 'datafirefly-server-side')) . '</td>';
            // Underline + bold in addition to colour (accessibility, never colour alone).
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
DFSS_Legacy::register();
