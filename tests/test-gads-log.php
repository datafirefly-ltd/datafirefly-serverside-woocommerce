<?php
/**
 * The Google Ads conversion log (DFSS_Gads_Log, 2.31.0).
 *
 * It answers one question for the merchant, "how many conversions should Google Ads show?", and
 * every wrong answer it can give is silent: a status read from the wrong field, a day listed
 * twice, a replay that leaves its line saying "queued". Pinned here:
 *
 *   - classify() against the dispatcher's real answers (src/lib/processEvent.ts and
 *     src/dispatchers/google.ts of datafirefly-server-side), same cases as the PrestaShop module;
 *   - the click id kinds, in the dispatcher's preference order, and the Google tag cookie reader;
 *   - "came from an ad" read from WooCommerce's order attribution;
 *   - the 30-day list across both clock changes;
 *   - writing, replaying, aggregating and rendering against a real SQL engine (SQLite), the
 *     rendered values escaped.
 *
 * Not covered: dbDelta on MySQL and wc_get_orders() on a real shop (the WordPress bench).
 *
 * Run: php tests/test-gads-log.php
 */
define('ABSPATH', __DIR__ . '/wp-stub/');
define('DAY_IN_SECONDS', 86400);

$GLOBALS['options'] = array();
$GLOBALS['paid'] = array();
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['options']) ? $GLOBALS['options'][$k] : $d; }
function __($s, $d = null) { return $s; }
function esc_html__($s, $d = null) { return htmlspecialchars($s); }
function esc_html($s) { return htmlspecialchars((string) $s); }
function current_time($t) { return gmdate('Y-m-d H:i:s'); }
function number_format_i18n($n, $d = 0) { return number_format((float) $n, $d); }
function wp_timezone() { return new DateTimeZone('UTC'); }
function wc_get_is_paid_statuses() { return array('processing', 'completed'); }
function wc_get_orders($args)
{
    $day = substr($args['date_created'], 0, 10);

    return (object) array('total' => isset($GLOBALS['paid'][$day]) ? $GLOBALS['paid'][$day] : 0);
}

/** $wpdb on SQLite: the calls DFSS_Gads_Log makes. */
class FakeWpdb
{
    public $prefix = 'wp_';
    public $pdo;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("CREATE TABLE wp_dfss_gads_log (order_id INTEGER PRIMARY KEY, reference TEXT NOT NULL DEFAULT '', order_date TEXT NOT NULL, value REAL NOT NULL DEFAULT 0, currency TEXT NOT NULL DEFAULT '', consent TEXT NOT NULL DEFAULT '', click_id TEXT NOT NULL DEFAULT '', ad_click TEXT NOT NULL DEFAULT '', has_email INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT '', detail TEXT NOT NULL DEFAULT '', date_upd TEXT NOT NULL)");
    }

    public function prepare($sql, ...$args)
    {
        $i = 0;

        return preg_replace_callback('/%[sd]/', function ($m) use (&$i, $args) {
            $v = $args[$i++];

            return $m[0] === '%d' ? (string) (int) $v : $this->pdo->quote((string) $v);
        }, $sql);
    }

    public function query($sql) { return $this->pdo->exec($sql); }
    public function get_results($sql) { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_OBJ); }

    public function replace($table, $data)
    {
        $cols = implode(',', array_keys($data));
        $vals = implode(',', array_map(function ($v) { return $this->pdo->quote((string) $v); }, $data));

        return $this->pdo->exec("REPLACE INTO {$table} ({$cols}) VALUES ({$vals})");
    }

    public function update($table, $data, $where)
    {
        $set = array();
        foreach ($data as $k => $v) {
            $set[] = $k . ' = ' . $this->pdo->quote((string) $v);
        }
        $w = array();
        foreach ($where as $k => $v) {
            $w[] = $k . ' = ' . $this->pdo->quote((string) $v);
        }

        return $this->pdo->exec("UPDATE {$table} SET " . implode(', ', $set) . ' WHERE ' . implode(' AND ', $w));
    }

    public function row($id)
    {
        $r = $this->pdo->query('SELECT * FROM wp_dfss_gads_log WHERE order_id = ' . (int) $id)->fetch(PDO::FETCH_ASSOC);

        return $r ?: null;
    }
}
$wpdb = new FakeWpdb();

class FakeDate
{
    private $s;
    public function __construct($s) { $this->s = $s; }
    public function date($f) { return $this->s; }
}

class FakeOrder
{
    public $id;
    public $meta = array();
    public $created;
    public function __construct($id, $created, array $meta = array()) { $this->id = $id; $this->created = $created; $this->meta = $meta; }
    public function get_id() { return $this->id; }
    public function get_order_number() { return (string) $this->id; }
    public function get_date_created() { return new FakeDate($this->created); }
    public function get_total() { return '49.90'; }
    public function get_currency() { return 'EUR'; }
    public function get_meta($k) { return isset($this->meta[$k]) ? $this->meta[$k] : ''; }
}

require __DIR__ . '/../includes/class-dfss-gads-log.php';

$ok = 0;
$fail = 0;
function t($name, $cond, $detail = null)
{
    global $ok, $fail;
    if ($cond) { $ok++; echo "  ok  $name\n"; } else { $fail++; echo "  FAIL $name" . ($detail === null ? '' : ' ' . json_encode($detail)) . "\n"; }
}

/** A dispatched answer, as processEvent() returns it. */
function answer($google = null, $code = 200)
{
    $results = array(array('destination' => 'ga4', 'success' => true));
    if ($google !== null) {
        $results[] = array_merge(array('destination' => 'google'), $google);
    }

    return array('ok' => $code >= 200 && $code < 300, 'code' => $code, 'message' => '', 'body' => json_encode(array('tenantId' => 't', 'results' => $results)));
}
function status($p, $r) { return DFSS_Gads_Log::classify($p, $r)['status']; }

$click = array('eventName' => 'purchase', 'eventId' => 'order_1', 'consent' => 'granted', 'userData' => array('gclid' => 'Cj0KCQjw1234567', 'email' => 'a@b.c'), 'eventData' => array('value' => 120.5, 'currency' => 'EUR'));
$noclick = array('eventName' => 'purchase', 'eventId' => 'order_2', 'consent' => 'granted', 'userData' => array('email' => 'a@b.c'), 'eventData' => array('value' => 30, 'currency' => 'EUR'));
$denied = array('eventName' => 'purchase', 'eventId' => 'order_3', 'consent' => 'denied', 'userData' => array(), 'eventData' => array('value' => 80, 'currency' => 'EUR'));

echo "\nclassify() — the dispatcher's answers\n";
t('accepted by Google, consent granted: expected', status($click, answer(array('success' => true, 'requestId' => 'r1'))) === 'expected');
t('consent not_required: sent without consent', status(array_merge($click, array('consent' => 'not_required')), answer(array('success' => true))) === 'sent_no_consent');
t('skipped, no click id: no click id', status($noclick, answer(array('success' => true, 'skipped' => true))) === 'no_click_id');
t('skipped with a click id: skipped by the dispatcher', status($click, answer(array('success' => true, 'skipped' => true))) === 'google_skipped');
t('207, Google failed: refused by Google, its error kept', DFSS_Gads_Log::classify($click, answer(array('success' => false, 'error' => 'PERMISSION_DENIED'), 207)) === array('status' => 'google_error', 'detail' => 'PERMISSION_DENIED'));
t('no google result: not connected', status($click, answer(null)) === 'no_google');
t('a refused purchase (sent "denied"): consent refused, from the answer', status($denied, array('ok' => true, 'code' => 200, 'message' => '', 'body' => '{"dispatched":false,"reason":"consent_denied"}')) === 'consent_refused');
t('quota_paused (202)', status($click, array('ok' => true, 'code' => 202, 'message' => '', 'body' => '{"accepted":true,"dispatched":false,"reason":"quota_paused"}')) === 'quota_paused');
t('transform_excluded: skipped, not "not connected"', status($click, array('ok' => true, 'code' => 200, 'message' => '', 'body' => '{"dispatched":false,"reason":"transform_excluded"}')) === 'google_skipped');
t('an unknown reason: unknown, reason kept', DFSS_Gads_Log::classify($click, array('ok' => true, 'code' => 200, 'message' => '', 'body' => '{"dispatched":false,"reason":"x_new"}'))['detail'] === 'not dispatched: x_new');
t('breaker open (send skipped): queued', status($click, array('ok' => false, 'code' => 0, 'message' => 'circuit_open', 'skipped' => true)) === 'queued');
t('network failure: queued', status($click, array('ok' => false, 'code' => 0, 'message' => 'timeout')) === 'queued');
t('400: queued (the queue retries every code but 401/403)', status($click, array('ok' => false, 'code' => 400, 'message' => '')) === 'queued');
t('401 and 403: final error', status($click, array('ok' => false, 'code' => 401, 'message' => '')) === 'error' && status($click, array('ok' => false, 'code' => 403, 'message' => '')) === 'error');
t('an answer without body (pre-2.31.0 client) is read from the message', status($click, array('ok' => true, 'code' => 200, 'message' => answer(array('success' => true))['body'])) === 'expected');
t('unreadable answer: unknown', status($click, array('ok' => true, 'code' => 200, 'message' => 'oops')) === 'unknown');

echo "\nclick ids, Google's cookie, WooCommerce attribution\n";
t('gclid > wbraid > gbraid', DFSS_Gads_Log::click_id_kind(array('userData' => array('gbraid' => 'a', 'wbraid' => 'b', 'gclid' => 'c'))) === 'gclid'
    && DFSS_Gads_Log::click_id_kind(array('userData' => array('gbraid' => 'a', 'wbraid' => 'b'))) === 'wbraid'
    && DFSS_Gads_Log::click_id_kind(array('userData' => array('gbraid' => 'a'))) === 'gbraid'
    && DFSS_Gads_Log::click_id_kind(array()) === '');
t('GCL.<time>.<id>', DFSS_Gads_Log::gcl_cookie_value('GCL.1760000000.Cj0KCQjw_ab-12') === 'Cj0KCQjw_ab-12');
t('the "$i..." suffix is dropped', DFSS_Gads_Log::gcl_cookie_value('GCL.1760000000.Cj0K$i1760000001') === 'Cj0K');
t('not a GCL cookie, or not a string: empty', DFSS_Gads_Log::gcl_cookie_value('1.2.Cj0K') === '' && DFSS_Gads_Log::gcl_cookie_value(null) === '' && DFSS_Gads_Log::gcl_cookie_value(array('x')) === '');
t('sourcebuster on a gclid (utm, google, cpc): from an ad', DFSS_Gads_Log::ad_click_from_attribution('utm', 'google', 'cpc') === 'google_cpc');
t('paid mediums as GA4 reads them (ppc, paid_search, Cpc)', DFSS_Gads_Log::ad_click_from_attribution('utm', 'Google', 'ppc') === 'google_cpc' && DFSS_Gads_Log::ad_click_from_attribution('utm', 'google', 'paid_search') === 'google_cpc' && DFSS_Gads_Log::ad_click_from_attribution('utm', 'google', 'Cpc') === 'google_cpc');
t('google organic, referral, typein: not an ad', DFSS_Gads_Log::ad_click_from_attribution('organic', 'google', 'organic') === '' && DFSS_Gads_Log::ad_click_from_attribution('referral', 'google.com', 'referral') === '' && DFSS_Gads_Log::ad_click_from_attribution('typein', '(direct)', '(none)') === '');
t('another network in cpc (bing): not a Google ad', DFSS_Gads_Log::ad_click_from_attribution('utm', 'bing', 'cpc') === '');
t('utm google + email: not an ad', DFSS_Gads_Log::ad_click_from_attribution('utm', 'google', 'email') === '');

echo "\nday_list() — thirty calendar days, whatever the clock does\n";
$paris = new DateTimeZone('Europe/Paris');
t('00:30 after the spring change: 29/03 not skipped', DFSS_Gads_Log::day_list(3, (new DateTime('2026-03-30 00:30:00', $paris))->getTimestamp(), $paris) === array('2026-03-30', '2026-03-29', '2026-03-28'));
t('23:30 after the autumn change: 25/10 not listed twice', DFSS_Gads_Log::day_list(3, (new DateTime('2026-10-26 23:30:00', $paris))->getTimestamp(), $paris) === array('2026-10-26', '2026-10-25', '2026-10-24'));
$m = DFSS_Gads_Log::day_list(400, (new DateTime('2026-12-31 00:00:01', $paris))->getTimestamp(), $paris);
t('400 distinct days across two changes', count(array_unique($m)) === 400 && $m[0] === '2026-12-31');

echo "\nwrites, replay, aggregates, rendering (SQLite)\n";
$today = gmdate('Y-m-d');
$yesterday = gmdate('Y-m-d', time() - 86400);
$ad = array('_wc_order_attribution_source_type' => 'utm', '_wc_order_attribution_utm_source' => 'google', '_wc_order_attribution_utm_medium' => 'cpc');
DFSS_Gads_Log::record_sent(new FakeOrder(1, "$today 10:00:00", $ad), $click, answer(array('success' => true, 'requestId' => 'r1')));
DFSS_Gads_Log::record_sent(new FakeOrder(2, "$today 11:00:00", $ad), $noclick, answer(array('success' => true, 'skipped' => true)));
DFSS_Gads_Log::record_sent(new FakeOrder(3, "$today 12:00:00", $ad), $denied, array('ok' => true, 'code' => 200, 'message' => '', 'body' => '{"dispatched":false,"reason":"consent_denied"}'));
DFSS_Gads_Log::record_sent(new FakeOrder(4, "$yesterday 09:00:00"), array_merge($click, array('eventId' => 'order_4')), array('ok' => false, 'code' => 0, 'message' => 'timeout'));
$r1 = $wpdb->row(1);
t('a line per order: status, value, currency, kinds', $r1['status'] === 'expected' && (float) $r1['value'] === 120.5 && $r1['currency'] === 'EUR' && $r1['click_id'] === 'gclid' && $r1['ad_click'] === 'google_cpc' && (int) $r1['has_email'] === 1, $r1);
t('no click id, from an ad', $wpdb->row(2)['status'] === 'no_click_id' && $wpdb->row(2)['ad_click'] === 'google_cpc');
t('refused, from an ad, no email kept', $wpdb->row(3)['status'] === 'consent_refused' && (int) $wpdb->row(3)['has_email'] === 0);
t('queued, no attribution: not from an ad', $wpdb->row(4)['status'] === 'queued' && $wpdb->row(4)['ad_click'] === '');
DFSS_Gads_Log::record_sent(new FakeOrder(1, "$today 10:00:00", $ad), $click, answer(array('success' => false, 'error' => 'X')));
t('the second trigger overwrites, never a second line', (int) $wpdb->pdo->query('SELECT COUNT(*) FROM wp_dfss_gads_log WHERE order_id = 1')->fetchColumn() === 1 && $wpdb->row(1)['status'] === 'google_error');
DFSS_Gads_Log::record_sent(new FakeOrder(1, "$today 10:00:00", $ad), $click, answer(array('success' => true)));

$p4 = array_merge($click, array('eventId' => 'order_4'));
DFSS_Gads_Log::record_replay($p4, array('ok' => false, 'code' => 503, 'message' => ''), 'pending');
t('replay still failing: still queued, code kept', $wpdb->row(4)['status'] === 'queued' && $wpdb->row(4)['detail'] === 'still queued, HTTP 503');
DFSS_Gads_Log::record_replay($p4, answer(array('success' => true, 'requestId' => 'r4')), 'done');
t('replay delivered: the line becomes expected', $wpdb->row(4)['status'] === 'expected');
DFSS_Gads_Log::record_replay($p4, array('ok' => false, 'code' => 401, 'message' => ''), 'failed');
t('replay given up: error', $wpdb->row(4)['status'] === 'error' && strpos($wpdb->row(4)['detail'], 'queue gave up (failed)') === 0);
DFSS_Gads_Log::record_replay(array('eventName' => 'page_view', 'eventId' => 'order_4'), answer(), 'done');
DFSS_Gads_Log::record_replay(array('eventName' => 'purchase', 'eventId' => 'order_999'), answer(array('success' => true)), 'done');
t('a page view, or an order without line: nothing touched, nothing created', $wpdb->row(4)['status'] === 'error' && $wpdb->row(999) === null);

DFSS_Gads_Log::record_sent(new FakeOrder(5, '2024-01-01 10:00:00'), $click, answer(array('success' => true)));
t('a row older than 400 days does not survive the write that follows it', $wpdb->row(5) === null);
DFSS_Gads_Log::record_sent(new FakeOrder(6, "$today 13:00:00"), array_merge($click, array('eventId' => 'order_6')), answer(array('success' => true)));

$GLOBALS['paid'] = array($today => 3, $yesterday => 1);
$days = DFSS_Gads_Log::daily(30);
$d0 = $days[0];
t('30 days, newest first', count($days) === 30 && $d0['day'] === $today && $days[1]['day'] === $yesterday);
t('today: paid orders, seen, expected and its value', $d0['orders'] === 3 && $d0['seen'] === 4 && $d0['expected'] === 2 && abs($d0['expected_value'] - 241.0) < 0.001, $d0);
t('today: no click id 1 (from an ad 1), refused 1 (from an ad 1)', $d0['no_click_id'] === 1 && $d0['no_click_id_ad'] === 1 && $d0['consent_refused'] === 1 && $d0['consent_refused_ad'] === 1);
t('yesterday: the given-up replay counts in errors', $days[1]['other'] === 1 && $days[1]['orders'] === 1);

$wpdb->update('wp_dfss_gads_log', array('detail' => '<script>alert(1)</script>', 'reference' => '<b>7</b>'), array('order_id' => 2));
$html = DFSS_Gads_Log::render_panel();
t('rendered: heading, both tables', strpos($html, 'Google Ads conversion log') !== false && substr_count($html, '<table') === 2);
t('a value from the dispatcher is escaped, never markup', strpos($html, '<script>') === false && strpos($html, '&lt;script&gt;alert(1)&lt;/script&gt;') !== false && strpos($html, '<b>7</b>') === false);
foreach (array('table', 'thead', 'tbody', 'tfoot', 'tr', 'div') as $tag) {
    t("<$tag> balanced", preg_match_all("#<{$tag}[\\s>]#", $html) === substr_count($html, "</$tag>"));
}
$GLOBALS['options']['woocommerce_feature_order_attribution_enabled'] = 'no';
t('attribution off: the panel says the ad columns stay at 0', strpos(DFSS_Gads_Log::render_panel(), 'order attribution is turned off') !== false);

echo "\n$ok passed, $fail failed\n";
exit($fail ? 1 : 0);
