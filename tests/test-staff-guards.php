<?php
/**
 * The three places that measure a visit leave a signed-in team member out, and only them.
 *
 * - the storefront tracker (and with it every browser tag) is not enqueued;
 * - the collection endpoint refuses their events, for a page served from a cache that had it;
 * - their sign-in sends no event.
 *
 * A customer, a visitor and a team member with the setting switched off are measured as before.
 * The whole plugin file is loaded against stand-ins for the WordPress functions it calls; the
 * outbound HTTP call is recorded, so "nothing sent" is read on the wire, not inferred.
 *
 * Run: php tests/test-staff-guards.php
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
function is_singular() { return false; }
function is_front_page() { return false; }
function is_home() { return false; }
function is_search() { return false; }
function is_404() { return false; }
function wp_doing_cron() { return false; }
function is_archive() { return false; }
function have_posts() { return false; }
function get_queried_object() { return null; }
function get_search_query() { return ''; }
function get_the_title() { return ''; }
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

require __DIR__ . '/../datafirefly-server-side.php';

$ok = 0;
$fail = 0;
function t($name, $cond)
{
    global $ok, $fail;
    if ($cond) { $ok++; echo "  ok  $name\n"; } else { $fail++; echo "  FAIL $name\n"; }
}

$base = array(
    'enabled' => 1, 'tenant_id' => 'shop', 'hmac_secret' => 'secret', 'endpoint' => 'https://dispatch.test/v1/events',
    'complete_tracking' => 1, 'require_consent' => 0,
);
$admin = new WP_User(1, array('administrator'));
$manager = new WP_User(2, array('shop_manager'));
$customer = new WP_User(3, array('customer'));
$visitor = new WP_User(0, array());

/** Runs a fresh plugin with these options and this signed-in user. */
function plugin_with(array $opts, $user)
{
    $GLOBALS['options'] = array('dfss_settings' => $opts);
    $GLOBALS['current_user'] = $user;
    $GLOBALS['enqueued'] = array();
    $GLOBALS['http'] = array();

    return new DFSS_Plugin();
}

// 1. Storefront tracker.
foreach (array('administrator' => $admin, 'shop manager' => $manager) as $who => $user) {
    plugin_with($base, $user)->enqueue_tracker();
    t("tracker: $who signed in gets no tracker", !in_array('dfss-tracker', $GLOBALS['enqueued'], true));
    t("tracker: $who keeps the URL guard (it measures nothing)", in_array('dfss-url-guard', $GLOBALS['enqueued'], true));
}
foreach (array('customer' => $customer, 'visitor' => $visitor) as $who => $user) {
    plugin_with($base, $user)->enqueue_tracker();
    t("tracker: $who is measured", in_array('dfss-tracker', $GLOBALS['enqueued'], true));
}
plugin_with(array_merge($base, array('exclude_staff' => 0)), $admin)->enqueue_tracker();
t('tracker: setting off, the administrator is measured again', in_array('dfss-tracker', $GLOBALS['enqueued'], true));
// The tracker is told where WordPress itself hands a signed-in customer their token, on the same
// origin whatever host the page was served from (www or not).
t('tracker: it knows where a signed-in customer gets their own token',
    ($GLOBALS['localized']['DFSS_CFG']['accountNonceUrl'] ?? '') === '/wp-admin/admin-ajax.php?action=rest-nonce');

// 2. Sign-in event, read on the wire.
plugin_with($base, $admin)->on_login('admin', $admin);
t('sign-in: an administrator sends nothing', count($GLOBALS['http']) === 0);
plugin_with($base, $manager)->on_login('manager', $manager);
t('sign-in: a shop manager sends nothing', count($GLOBALS['http']) === 0);
plugin_with($base, $customer)->on_login('customer', $customer);
t('sign-in: a customer sends one login event', count($GLOBALS['http']) === 1 && ($GLOBALS['http'][0]['eventName'] ?? '') === 'login');
plugin_with(array_merge($base, array('exclude_staff' => 0)), $admin)->on_login('admin', $admin);
t('sign-in: setting off, the administrator sends it again', count($GLOBALS['http']) === 1);

// 3. Collection endpoint.
$body = json_encode(array('eventName' => 'view_item', 'eventId' => 'e1', 'sourceUrl' => 'https://shop.test/p'));
$rest = function ($user, $opts) use ($body) {
    plugin_with($opts, $user);
    $r = new DFSS_REST(function () use ($opts) { return $opts; });

    return $r->handle_collect(new FakeRequest($body));
};
$res = $rest($admin, $base);
t('endpoint: an administrator is refused as staff', ($res->data['reason'] ?? '') === 'staff');
t('endpoint: and nothing leaves', count($GLOBALS['http']) === 0);
$res = $rest($customer, $base);
t('endpoint: a customer is not refused as staff', ($res->data['reason'] ?? '') !== 'staff');
$res = $rest($admin, array_merge($base, array('exclude_staff' => 0)));
t('endpoint: setting off, the administrator is not refused as staff', ($res->data['reason'] ?? '') !== 'staff');

echo "\n$ok ok, $fail failed\n";
exit($fail === 0 ? 0 : 1);
