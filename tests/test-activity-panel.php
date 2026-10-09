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
$GLOBALS['options'] = array();
ob_start();
$plugin->render_activity();
$html = ob_get_clean();
t('no retry run yet is said so', strpos($html, 'No retry run yet') !== false);

echo "\n$ok passed, $fail failed\n";
exit($fail ? 1 : 0);
