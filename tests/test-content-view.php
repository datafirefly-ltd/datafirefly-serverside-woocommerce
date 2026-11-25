<?php
/**
 * On a shop, only a product page is a "product view" (2.34.2).
 *
 * `view_content` is GA4's `view_item`. It was also sent for every page and every article, which is what
 * a site without a shop needs (2.16.0) and what a shop must not do: the cart, the account and the home
 * page were counted as products seen. Measured on datafirefly.com over 28 days: 2 030 of the 2 975
 * `view_content` were not products, and "Cart" was the third most viewed product in GA4.
 *
 * The whole plugin file is loaded against stand-ins for WordPress, once per process: a site with
 * WooCommerce and a site without it cannot live in the same one (function_exists).
 *
 * Run: php tests/test-content-view.php
 */
define('ABSPATH', __DIR__ . '/wp-stub/');
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);

$GLOBALS['options'] = array();
$GLOBALS['enqueued'] = array();
$GLOBALS['http'] = array();
$GLOBALS['current_user'] = null;

class WP_User
{
    public $ID;
    public $roles;
    public $user_email = 'someone@shop.test';
    public function __construct($id = 0, array $roles = array())
    {
        $this->ID = $id;
        $this->roles = $roles;
    }
}
class WP_Error
{
    public $code;
    public function __construct($code = '', $message = '', $data = null) { $this->code = $code; }
}
class WP_REST_Response
{
    public $data;
    public $status;
    public function __construct($data = null, $status = 200) { $this->data = $data; $this->status = $status; }
}
class FakeRequest
{
    private $body;
    public function __construct($body) { $this->body = $body; }
    public function get_body() { return $this->body; }
    public function get_header($h) { return null; }
    public function get_param($p) { return null; }
}
class FakeWpdb
{
    public $prefix = 'wp_';
    public $last_error = '';
    public $insert_id = 1;
    public function insert($t, $d, $f = null) { $GLOBALS['queued'][] = $d; return 1; }
    public function prepare($sql, ...$a) { return $sql; }
    public function query($sql) { return 0; }
    public function get_var($sql) { return 0; }
    public function get_results($sql) { return array(); }
    public function get_row($sql, $o = null) { return null; }
    public function update($t, $d, $w, $f = null, $wf = null) { return 1; }
    public function get_charset_collate() { return ''; }
    public function suppress_errors($b = true) { return false; }
}
$wpdb = new FakeWpdb();

function add_action() {}
function add_filter() {}
function register_activation_hook() {}
function register_deactivation_hook() {}
function plugin_dir_path($f) { return dirname($f) . '/'; }
function plugin_dir_url($f) { return 'https://shop.test/wp-content/plugins/x/'; }
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['options']) ? $GLOBALS['options'][$k] : $d; }
function update_option($k, $v, $a = null) { $GLOBALS['options'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['options'][$k]); return true; }
function get_transient($k) { return false; }
function set_transient($k, $v, $t = 0) { return true; }
function wp_parse_args($a, $d) { return array_merge($d, $a); }
function current_user_can($c) { return true; }
function __($s, $d = null) { return $s; }
function esc_html__($s, $d = null) { return $s; }
function esc_url_raw($s) { return $s; }
function home_url($p = '') { return 'https://shop.test' . $p; }
function rest_url($p = '') { return 'https://shop.test/wp-json/' . ltrim($p, '/'); }
function admin_url($p = '', $scheme = 'admin') { return ('relative' === $scheme ? '' : 'https://shop.test') . '/wp-admin/' . ltrim($p, '/'); }
function wp_create_nonce($a) { return 'nonce'; }
function wp_verify_nonce($n, $a) { return false; }
function is_admin() { return false; }
function is_front_page() { return false; }
function is_search() { return false; }
function is_404() { return false; }
function wp_doing_cron() { return false; }
function get_search_query() { return ''; }
function wp_strip_all_tags($s) { return strip_tags((string) $s); }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function wp_generate_uuid4() { return 'uuid'; }
function wp_get_current_user() { return $GLOBALS['current_user'] ?: new WP_User(0, array()); }
function get_current_user_id() { $u = wp_get_current_user(); return (int) $u->ID; }
function get_userdata($id) { return wp_get_current_user(); }
function is_email($e) { return true; }
function wp_register_script($h) { return true; }
function wp_localize_script($h, $n, $d) { $GLOBALS['localized'][$n] = $d; return true; }
function wp_enqueue_script($h) { $GLOBALS['enqueued'][] = $h; }
function wp_safe_remote_post($url, $args = array())
{
    $GLOBALS['http'][] = json_decode($args['body'] ?? '{}', true);
    return array('response' => array('code' => 200), 'body' => '{"ok":true}');
}
function wp_remote_retrieve_response_code($r) { return 200; }
function wp_remote_retrieve_body($r) { return '{"ok":true}'; }
function is_wp_error($v) { return $v instanceof WP_Error; }
function sanitize_text_field($s) { return trim((string) $s); }
function wp_unslash($v) { return $v; }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function apply_filters($h, $v) { return $v; }
function do_action() {}


// What the visitor is looking at. One page per call, as WordPress would answer.
class WP_Post
{
    public $ID;
    public $post_type;
    public $post_title;
    public function __construct($id, $type, $title) { $this->ID = $id; $this->post_type = $type; $this->post_title = $title; }
}
class WC_Product
{
    private $id;
    public function __construct($id) { $this->id = $id; }
    public function get_id() { return $this->id; }
    public function get_name() { return 'Live Search'; }
    public function get_price() { return 199.0; }
}
class FakeCart
{
    public function is_empty() { return false; }
    public function get_cart() { return array(array('data' => new WC_Product(5127), 'quantity' => 1)); }
    public function get_total($c = '') { return 199.0; }
}
class FakeWoo
{
    public $cart;
    public function __construct() { $this->cart = new FakeCart(); }
}

$GLOBALS['page'] = array('post' => null, 'kind' => '');
function is_singular() { return $GLOBALS['page']['post'] instanceof WP_Post; }
function get_queried_object() { return $GLOBALS['page']['post']; }
function get_queried_object_id() { return $GLOBALS['page']['post'] ? $GLOBALS['page']['post']->ID : 0; }
function get_the_title($p = null) { return $p ? $p->post_title : ''; }
function is_home() { return 'blog' === $GLOBALS['page']['kind']; }
function is_archive() { return false; }
function have_posts() { return 'blog' === $GLOBALS['page']['kind']; }

if (isset($argv[1]) && 'shop' === $argv[1]) {
    function is_product() { return 'product' === $GLOBALS['page']['kind']; }
    function is_shop() { return false; }
    function is_cart() { return 'cart' === $GLOBALS['page']['kind']; }
    function is_checkout() { return 'checkout' === $GLOBALS['page']['kind']; }
    function is_order_received_page() { return false; }
    function is_product_category() { return false; }
    function is_product_taxonomy() { return false; }
    function wc_get_product($id) { return new WC_Product($id); }
    function wc_get_price_to_display($p) { return 199.0; }
    function get_woocommerce_currency() { return 'EUR'; }
    function WC() { static $w = null; return $w ? $w : $w = new FakeWoo(); }
}

require __DIR__ . '/../datafirefly-server-side.php';

$pages = array(
    'home' => array('post' => new WP_Post(2, 'page', 'DataFirefly'), 'kind' => ''),
    'account' => array('post' => new WP_Post(24, 'page', 'My account'), 'kind' => ''),
    'cart' => array('post' => new WP_Post(21, 'page', 'Cart'), 'kind' => 'cart'),
    'checkout' => array('post' => new WP_Post(22, 'page', 'Checkout'), 'kind' => 'checkout'),
    'article' => array('post' => new WP_Post(300, 'post', 'An article'), 'kind' => ''),
    'documentation' => array('post' => new WP_Post(8357, 'df_doc', 'A guide'), 'kind' => ''),
    'product' => array('post' => new WP_Post(5127, 'product', 'Live Search'), 'kind' => 'product'),
    'blog' => array('post' => null, 'kind' => 'blog'),
);

if (isset($argv[1])) {
    $GLOBALS['wp_query'] = (object) array('posts' => array(new WP_Post(300, 'post', 'An article')));
    $GLOBALS['options'] = array('dfss_settings' => array(
        'enabled' => 1, 'tenant_id' => 'shop', 'hmac_secret' => 'secret', 'endpoint' => 'https://dispatch.test/v1/events',
        'complete_tracking' => 1, 'require_consent' => 0,
    ));
    $out = array();
    foreach ($pages as $name => $page) {
        $GLOBALS['page'] = $page;
        $GLOBALS['localized'] = array();
        (new DFSS_Plugin())->enqueue_tracker();
        $out[$name] = isset($GLOBALS['localized']['DFSS_CFG']['events']) ? $GLOBALS['localized']['DFSS_CFG']['events'] : null;
    }
    echo json_encode($out);
    exit;
}

$ok = 0;
$fail = 0;
function t($name, $cond, $detail = '')
{
    global $ok, $fail;
    if ($cond) { $ok++; echo "  ok  $name\n"; } else { $fail++; echo "  FAIL $name $detail\n"; }
}
function site($kind)
{
    $raw = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . $kind);
    $data = json_decode($raw, true);
    if (!is_array($data)) { echo "  FAIL the $kind site did not answer: $raw\n"; exit(1); }

    return $data;
}

// 1. A shop: a product is a product, and nothing else is.
$shop = site('shop');
foreach (array('home', 'account', 'cart', 'checkout', 'article', 'documentation') as $p) {
    t("shop: the $p page is not a product view", !isset($shop[$p]['content']), json_encode($shop[$p]['content'] ?? null));
    t("shop: the $p page carries no product", !isset($shop[$p]['viewItem']));
}
t('shop: the product page is', ($shop['product']['viewItem']['id'] ?? '') === '5127' && 199 == ($shop['product']['viewItem']['value'] ?? 0));
t('shop: and only once', !isset($shop['product']['content']));

// What already had its own event keeps it.
t('shop: the cart page still says view_cart', 1 === ($shop['cart']['cart']['numItems'] ?? 0));
t('shop: the checkout page still says initiate_checkout', 1 === ($shop['checkout']['checkout']['numItems'] ?? 0));
t('shop: the blog list still says view_item_list', 'blog' === ($shop['blog']['contentList']['listId'] ?? ''));

// 2. A site without a shop: reading a page is what there is to measure (2.16.0). Nothing changes.
$site = site('site');
foreach (array('home' => 'page-2', 'account' => 'page-24', 'article' => 'post-300', 'documentation' => 'df_doc-8357') as $p => $id) {
    t("no shop: the $p page is a content view ($id)", ($site[$p]['content']['id'] ?? '') === $id, json_encode($site[$p]));
}
t('no shop: the content keeps its name and its type', 'An article' === ($site['article']['content']['name'] ?? '') && 'post' === ($site['article']['content']['category'] ?? ''));
t('no shop: the blog list still says view_item_list', 'blog' === ($site['blog']['contentList']['listId'] ?? ''));

echo "\n$ok passed, $fail failed\n";
exit($fail ? 1 : 0);
