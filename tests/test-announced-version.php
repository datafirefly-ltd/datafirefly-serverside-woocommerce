<?php
/**
 * Every signed request says which connector, and which version of it, is speaking (2.34.1).
 *
 * Only the PrestaShop module did. The console could therefore never tell that a WooCommerce shop runs
 * behind: on 23/11/2026 datafirefly.com had 2.33.0, 2.34.0 was published, and the control tower said OK.
 *
 * The service reads `platform/version` and drops anything else, without a word: the shape is held here.
 * Run: php tests/test-announced-version.php
 */
define('ABSPATH', __DIR__ . '/wp-stub/');

$version = isset($argv[1]) ? $argv[1] : '';
if ($version !== '' && $version !== '--none') {
    define('DFSS_VERSION', $version);
}

$GLOBALS['posted'] = array();
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function wp_parse_url($u) { return parse_url($u); }
function is_wp_error($r) { return false; }
function wp_remote_retrieve_response_code($r) { return 200; }
function wp_remote_retrieve_body($r) { return '{}'; }
function wp_safe_remote_post($url, $args) { $GLOBALS['posted'][] = array($url, $args); return array(); }

require __DIR__ . '/../includes/class-dfss-client.php';

/** One request through each public way out, as [path => headers, body]. */
function requests()
{
    $GLOBALS['posted'] = array();
    $client = new DFSS_Client('shop_test', 'secret-of-the-test', 'https://serverside.example.test/v1/events');
    $client->send(array('eventId' => 'e-1', 'eventName' => 'page_view'));
    $client->send_truth(array('date' => '2026-11-22', 'orders' => 1));
    $client->get_public_config();
    $client->heartbeat();

    return $GLOBALS['posted'];
}

if ($version !== '') {
    echo json_encode(array_map(function ($r) { return $r[1]['headers']; }, requests()));
    exit;
}

$ok = 0;
$fail = 0;
function t($name, $cond, $detail = '')
{
    global $ok, $fail;
    if ($cond) { $ok++; echo "  ok  $name\n"; } else { $fail++; echo "  FAIL $name $detail\n"; }
}

/** The headers a given DFSS_VERSION produces: the constant cannot change inside one process. */
function headers_for($v)
{
    return json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($v)), true);
}

$main = file_get_contents(__DIR__ . '/../datafirefly-server-side.php');
$readme = file_get_contents(__DIR__ . '/../readme.txt');
preg_match("/define\\('DFSS_VERSION', '([^']+)'\\)/", $main, $c);
preg_match('/^ \\* Version:\\s+(\\S+)/m', $main, $h);
preg_match('/^Stable tag: (\\S+)/m', $readme, $s);
$shipped = $c[1];
t('constant, plugin header and stable tag agree', $shipped === $h[1] && $shipped === $s[1], "$shipped / $h[1] / $s[1]");
t('the changelog has this version', strpos($readme, '= ' . $shipped . ' =') !== false);

// The very expression of the service (src/signals/IntegrationTracker.ts).
$shape = '#^([a-z0-9_-]{1,24})/([0-9]{1,3}(?:\\.[0-9]{1,3}){0,3})$#';
$all = headers_for($shipped);
t('four ways out were exercised', is_array($all) && count($all) === 4, json_encode($all));
foreach (array('event', 'daily totals', 'public configuration', 'heartbeat') as $i => $label) {
    $got = isset($all[$i]['X-Dfss-Integration']) ? $all[$i]['X-Dfss-Integration'] : '(none)';
    t("$label: woocommerce/$shipped", $got === 'woocommerce/' . $shipped, $got);
    t("$label: the service accepts its shape", preg_match($shape, $got) === 1, $got);
}

$none = headers_for('--none');
t('without the constant nothing is announced, and the request still goes', count($none) === 4 && !isset($none[0]['X-Dfss-Integration']), json_encode($none));

define('DFSS_VERSION', $shipped);
foreach (requests() as $r) {
    $headers = $r[1]['headers'];
    $expected = hash_hmac('sha256', $headers['X-Dfss-Timestamp'] . "\n" . $r[1]['body'], 'secret-of-the-test');
    t('signature unchanged on ' . parse_url($r[0], PHP_URL_PATH), $headers['X-Dfss-Signature'] === $expected);
    t('body says nothing of the version on ' . parse_url($r[0], PHP_URL_PATH), strpos($r[1]['body'], $shipped) === false);
}

t('the readme tells the merchant what is sent', stripos($readme, "plugin's version number") !== false);

echo "\n$ok passed, $fail failed\n";
exit($fail ? 1 : 0);
