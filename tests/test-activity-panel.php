<?php
/**
 * The Activity panel renders a well-formed table, with the retry-queue diagnostics next to the
 * counters. Found by review on 2.30.0: an edit had eaten the table's opening tags and the page
 * printed a stray fragment of code.
 *
 * The whole plugin file is loaded against stand-ins for the WordPress functions it calls.
 * Run: php tests/test-activity-panel.php
 */
define('ABSPATH', __DIR__ . '/wp-stub/');
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);

$GLOBALS['options'] = array();
function add_action() {}
function add_filter() {}
function register_activation_hook() {}
function register_deactivation_hook() {}
function plugin_dir_path($f) { return dirname($f) . '/'; }
function plugin_dir_url($f) { return 'https://shop.test/wp-content/plugins/x/'; }
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['options']) ? $GLOBALS['options'][$k] : $d; }
function update_option($k, $v, $a = null) { $GLOBALS['options'][$k] = $v; return true; }
function wp_parse_args($a, $d) { return array_merge($d, $a); }
function current_user_can($c) { return true; }
function __($s, $d = null) { return $s; }
function esc_html__($s, $d = null) { return htmlspecialchars($s); }
function esc_html_e($s, $d = null) { echo htmlspecialchars($s); }
function esc_html($s) { return htmlspecialchars((string) $s); }
function esc_attr($s) { return htmlspecialchars((string) $s); }
function human_time_diff($a, $b) { return '5 mins'; }
function wp_date($f, $t) { return gmdate($f, $t); }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function number_format_i18n($n, $d = 0) { return number_format((float) $n, $d); }

class FakeWpdb2
{
    public $prefix = 'wp_';
    public $last_error = '';
    public function get_results($sql) { return $this->rows; }
    public function get_var($sql) { return 3; }
    public function prepare($sql, ...$a) { return $sql; }
    public function suppress_errors($b = true) { return false; }
    public $rows = array();
}
$wpdb = new FakeWpdb2();
$wpdb->rows = array((object) array('status' => 'expired', 'created_at' => 1700000000, 'last_code' => 0, 'attempts' => 1, 'event_name' => 'page_view', 'origin' => 'server'));

require __DIR__ . '/../datafirefly-server-side.php';

$ok = 0;
$fail = 0;
function t($name, $cond)
{
    global $ok, $fail;
    if ($cond) { $ok++; echo "  ok  $name\n"; } else { $fail++; echo "  FAIL $name\n"; }
}

$GLOBALS['options']['dfss_queue_last_run'] = array('time' => time() - 300, 'expired' => 4, 'sent' => 2);
$plugin = new DFSS_Plugin();
ob_start();
$plugin->render_activity();
$html = ob_get_clean();

t('the table opens with its class', strpos($html, '<table class="widefat striped"') !== false);
t('the head holds the Time column first', preg_match('#<thead>\s*<tr>\s*<th>Time</th>\s*<th>Event</th>#', $html) === 1);
t('every column header is there', preg_match('#<th>Source</th>\s*<th>Status</th>\s*<th>Detail</th>#', $html) === 1);
t('no stray code fragment is printed', strpos($html, 'XX') === false && strpos($html, "'datafirefly-server-side'") === false && strpos($html, '<?php') === false);
foreach (array('table', 'thead', 'tbody', 'tr', 'th', 'div', 'p') as $tag) {
    t("<$tag> tags are balanced", preg_match_all("#<{$tag}[\\s>]#", $html) === substr_count($html, "</$tag>"));
}
t('the last retry run is shown', strpos($html, 'Last retry run: 5 mins ago') !== false);
t('expired counts are shown: last run and listed', strpos($html, 'Expired: 4 in the last run, 3 listed') !== false);
t('the expired status has its label', strpos($html, '>Expired</strong>') !== false);
t('the Google Ads conversion log follows the activity table (2.31.0)', strpos($html, '<h2 style="margin-top:32px;">Google Ads conversion log</h2>') > strpos($html, 'id="dfss-activity-rows"'));
t('the log lists thirty days and a 30-day total', substr_count($html, '<tr><td>' . gmdate('Y')) >= 30 && strpos($html, '<th>30 days</th>') !== false);
$GLOBALS['options'] = array();
ob_start();
$plugin->render_activity();
$html = ob_get_clean();
t('no retry run yet is said so', strpos($html, 'No retry run yet') !== false);

// ---- checkout: Google's own click id cookie as a fallback (2.31.0)
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function wp_unslash($s) { return $s; }
class CheckoutOrder
{
    public $meta = array();
    public function update_meta_data($k, $v) { $this->meta[$k] = $v; }
    public function get_meta($k) { return isset($this->meta[$k]) ? $this->meta[$k] : ''; }
}
$checkout = function (array $cookies) use ($plugin) {
    $_COOKIE = $cookies;
    $o = new CheckoutOrder();
    $plugin->capture_cookies($o, array());

    return $o->meta;
};
$m = $checkout(array('_gcl_aw' => 'GCL.1760000000.Cj0KCQjwGOOGLE', '_gcl_gb' => 'GCL.1760000000.0AAAAgoogleB'));
t('checkout: Google\'s _gcl_aw / _gcl_gb fill the click ids ours lack', ($m['_dfss_gclid'] ?? '') === 'Cj0KCQjwGOOGLE' && ($m['_dfss_gbraid'] ?? '') === '0AAAAgoogleB');
$m = $checkout(array('_dfss_gclid' => 'OURS', '_gcl_aw' => 'GCL.1760000000.GOOGLE'));
t('checkout: ours first, Google\'s never overrides it', ($m['_dfss_gclid'] ?? '') === 'OURS');
$m = $checkout(array('_gcl_aw' => 'garbage'));
t('checkout: an unreadable Google cookie stores nothing', !isset($m['_dfss_gclid']));
$m = $checkout(array('_gcl_dc' => 'GCL.1760000000.X'));
t('checkout: no Google cookie for wbraid, nothing invented', !isset($m['_dfss_wbraid']) && !isset($m['_dfss_gclid']));
$_COOKIE = array();

echo "\n$ok passed, $fail failed\n";
exit($fail ? 1 : 0);
