<?php
/**
 * The retry queue replays purchases and refunds first, then the most recent events; expires what is
 * older than 7 days instead of sending it; works within a time budget and stops after a dispatcher
 * failure; reports its depth and oldest age on every signed request; and survives a table that is
 * still in the pre-2.30.0 shape.
 *
 * The queue runs against SQLite through a small stand-in for $wpdb (the SQL of the queue is plain
 * enough for both engines). Run: php tests/test-replay-queue.php
 */
define('ABSPATH', __DIR__ . '/wp-stub/');
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);

$GLOBALS['options'] = array();
$GLOBALS['transients'] = array();
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['options']) ? $GLOBALS['options'][$k] : $d; }
function update_option($k, $v, $a = null) { $GLOBALS['options'][$k] = $v; return true; }
function get_transient($k) { return $GLOBALS['transients'][$k] ?? false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['transients'][$k] = $v; $GLOBALS['transient_ttl'][$k] = $t; return true; }
function delete_transient($k) { unset($GLOBALS['transients'][$k]); return true; }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function wp_parse_url($u) { return parse_url($u); }
class WP_Error { function get_error_message() { return 'timeout'; } }
function is_wp_error($r) { return $r instanceof WP_Error; }
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
$GLOBALS['posted'] = array();
$GLOBALS['hooks'] = array();
function add_action($h, $cb) { $GLOBALS['hooks'][$h][] = $cb; }
function remove_action($h, $cb) { $GLOBALS['hooks'][$h] = array_values(array_filter($GLOBALS['hooks'][$h] ?? array(), function ($x) use ($cb) { return $x !== $cb; })); }
function wp_safe_remote_post($url, $args)
{
    $GLOBALS['posted'][] = $args;
    $GLOBALS['posted_urls'][] = $url;
    $GLOBALS['hooks_at_post'][] = count($GLOBALS['hooks']['http_api_curl'] ?? array());

    if (isset($GLOBALS['answer'])) {
        return $GLOBALS['answer'] === 0 ? new WP_Error() : array('code' => $GLOBALS['answer'], 'body' => 'nope');
    }

    return array('code' => 200, 'body' => '{}');
}

/** $wpdb on SQLite. */
class FakeWpdb
{
    public $prefix = 'wp_';
    public $queries = 0;
    public $last_error = '';
    private $pdo;
    private $old = false;
    public $can_migrate = true;
    public $missing = false;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /** @param bool $old true = the pre-2.30.0 table shape */
    public function create($old)
    {
        $this->pdo->exec('DROP TABLE IF EXISTS wp_dfss_queue');
        $this->old = $old;
        $cols = $old ? '' : 'priority INTEGER NOT NULL DEFAULT 0, event_time INTEGER NOT NULL DEFAULT 0,';
        $this->pdo->exec("CREATE TABLE wp_dfss_queue (id INTEGER PRIMARY KEY AUTOINCREMENT, event_name TEXT NOT NULL DEFAULT '', event_id TEXT NOT NULL DEFAULT '', $cols payload TEXT NOT NULL DEFAULT '', origin TEXT NOT NULL DEFAULT 'server', status TEXT NOT NULL DEFAULT 'pending', attempts INTEGER NOT NULL DEFAULT 0, next_attempt INTEGER NOT NULL DEFAULT 0, last_code INTEGER NOT NULL DEFAULT 0, last_error TEXT NOT NULL DEFAULT '', created_at INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL DEFAULT 0)");
    }

    public function migrate()
    {
        if ($this->can_migrate && $this->missing) {
            $this->create(false);
            $this->missing = false;
        }
        if ($this->old && $this->can_migrate) {
            $this->pdo->exec('ALTER TABLE wp_dfss_queue ADD COLUMN priority INTEGER NOT NULL DEFAULT 0');
            $this->pdo->exec('ALTER TABLE wp_dfss_queue ADD COLUMN event_time INTEGER NOT NULL DEFAULT 0');
            $this->old = false;
        }
    }

    public function get_charset_collate() { return ''; }
    public function suppress_errors($b = true) { return false; }

    public function prepare($sql, ...$args)
    {
        $i = 0;
        return preg_replace_callback('/%[sd]/', function ($m) use (&$i, $args) {
            $v = $args[$i++];
            return $m[0] === '%d' ? (string) (int) $v : $this->pdo->quote((string) $v);
        }, $sql);
    }

    public function query($sql)
    {
        ++$this->queries;
        $this->last_error = '';
        try {
            return $this->pdo->exec($sql);
        } catch (Throwable $e) {
            $this->last_error = $e->getMessage();
            return false;
        }
    }

    // Like the real wpdb: an SQL error leaves last_error set and get_results() returns an EMPTY
    // ARRAY (query() flushes, nothing is fetched), get_row() returns null.
    public function get_results($sql)
    {
        ++$this->queries;
        $this->last_error = '';
        try {
            return $this->pdo->query($sql)->fetchAll(PDO::FETCH_OBJ);
        } catch (Throwable $e) {
            $this->last_error = $e->getMessage();
            return array();
        }
    }

    public function get_row($sql)
    {
        $r = $this->get_results($sql);
        return $r ? $r[0] : null;
    }

    public function get_var($sql)
    {
        $r = $this->get_results($sql);
        return $r ? array_values((array) $r[0])[0] : null;
    }

    public function insert($table, $data, $format = null)
    {
        ++$this->queries;
        $this->last_error = '';
        try {
            $cols = implode(',', array_keys($data));
            $vals = implode(',', array_map(function ($v) { return $this->pdo->quote((string) $v); }, $data));
            return $this->pdo->exec("INSERT INTO $table ($cols) VALUES ($vals)");
        } catch (Throwable $e) {
            $this->last_error = $e->getMessage();
            return false;
        }
    }

    public function update($table, $data, $where, $format = null, $wf = null)
    {
        ++$this->queries;
        $set = implode(',', array_map(function ($k, $v) { return "$k=" . $this->pdo->quote((string) $v); }, array_keys($data), $data));
        $w = implode(' AND ', array_map(function ($k, $v) { return "$k=" . $this->pdo->quote((string) $v); }, array_keys($where), $where));
        return $this->pdo->exec("UPDATE $table SET $set WHERE $w");
    }

    public function rows($where = '1')
    {
        return $this->pdo->query("SELECT * FROM wp_dfss_queue WHERE $where ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    }
}

$wpdb = new FakeWpdb();

require __DIR__ . '/../includes/class-dfss-queue.php';
require __DIR__ . '/../includes/class-dfss-client.php';

/** A client that answers from a script and remembers what it was asked to send. */
class FakeClient extends DFSS_Client
{
    public $sent = array();
    public $codes;
    public $delay_us = 0;
    public $on_send = null;
    public $heartbeats = array();

    public function heartbeat()
    {
        $this->heartbeats[] = DFSS_Queue::signal();
    }

    public function __construct($codes = array()) { $this->codes = $codes; }

    public function send(array $payload, $timeout = 4)
    {
        $this->sent[] = $payload['eventId'];
        if ($this->delay_us) { usleep($this->delay_us); }
        if ($this->on_send) { call_user_func($this->on_send, $payload['eventId']); }
        $code = $this->codes[$payload['eventId']] ?? 200;

        return array('ok' => $code >= 200 && $code < 300, 'code' => $code, 'message' => '');
    }
}

$ok = 0;
$fail = 0;
function t($name, $cond)
{
    global $ok, $fail;
    if ($cond) { $ok++; echo "  ok  $name\n"; } else { $fail++; echo "  FAIL $name\n"; }
}

function fresh($old = false)
{
    global $wpdb;
    $wpdb->create($old);
    $GLOBALS['options'] = array();
    $GLOBALS['transients'] = array();
    $GLOBALS['posted'] = array();
    DFSS_Queue::reset_signal();
}

/** Queue one failed event, due now. */
function queue($name, $id, $event_time = null)
{
    global $wpdb;
    $p = array('eventName' => $name, 'eventId' => $id);
    if ($event_time !== null) { $p['eventTime'] = $event_time; }
    DFSS_Queue::record_attempt($p, array('ok' => false, 'code' => 503, 'message' => 'down'));
    $wpdb->query("UPDATE wp_dfss_queue SET next_attempt = 0 WHERE event_id = '$id'");
}

/** Queue one failed event whose event happened, and whose queue row was created, $days ago. */
function queue_old($name, $id, $days, $event_days = null)
{
    global $wpdb;
    queue($name, $id, time() - ($event_days ?? $days) * 86400);
    $wpdb->query("UPDATE wp_dfss_queue SET created_at = " . (time() - $days * 86400) . " WHERE event_id = '$id'");
}

function run($client, $budget = null, $batch = null)
{
    return DFSS_Queue::process_due('t', 's', 'https://x/v1/events', $client, $budget, $batch);
}

$now = time();

// ---- 1. order: purchases and refunds first, then the most recent
fresh();
queue('page_view', 'pv-new', $now - 60);
queue('purchase', 'buy-old', $now - 3 * 86400);
queue('page_view', 'pv-newest', $now - 10);
queue('refund', 'refund-1', $now - 2 * 86400);
queue('purchase', 'buy-recent', $now - 86400);
queue('page_view', 'pv-old', $now - 5 * 86400);
$c = new FakeClient();
run($c);
t('purchases and refunds first (most recent first among them), then the most recent', $c->sent === array('buy-recent', 'refund-1', 'buy-old', 'pv-newest', 'pv-new', 'pv-old'));

// ---- 2. event_time: the payload's, else the creation, not trusted when a day ahead
fresh();
queue('page_view', 'a', $now - 500);
queue('page_view', 'b');
queue('page_view', 'c', $now + 2 * 86400);
queue('page_view', 'd', 'junk');
$et = array();
foreach ($wpdb->rows() as $r) { $et[$r['event_id']] = (int) $r['event_time']; }
t('event_time from the payload', $et['a'] === $now - 500);
t('event_time falls back to the creation time when missing', abs($et['b'] - $now) <= 2);
t('event_time more than a day ahead is not trusted', abs($et['c'] - $now) <= 2);
t('event_time that is not a number falls back', abs($et['d'] - $now) <= 2);
$pr = array();
foreach ($wpdb->rows() as $r) { $pr[$r['event_id']] = (int) $r['priority']; }
fresh();
queue('purchase', 'p1'); queue('refund', 'p2'); queue('page_view', 'p3');
$pr = array();
foreach ($wpdb->rows() as $r) { $pr[$r['event_id']] = (int) $r['priority']; }
t('priority 1 for purchase and refund, 0 for the rest', $pr === array('p1' => 1, 'p2' => 1, 'p3' => 0));

// ---- 3. expiry
fresh();
queue_old('purchase', 'old-buy', 8);
queue_old('page_view', 'old-pv', 7, 7);
$wpdb->query("UPDATE wp_dfss_queue SET created_at = " . ($now - 7 * 86400 - 60) . ", event_time = " . ($now - 7 * 86400 - 60) . " WHERE event_id = 'old-pv'");
queue('page_view', 'fresh-pv', $now - 6 * 86400);
queue('page_view', 'legacy', null);
$wpdb->query("UPDATE wp_dfss_queue SET event_time = 0, created_at = " . ($now - 9 * 86400) . " WHERE event_id = 'legacy'");
$c = new FakeClient();
$stats = run($c);
t('events older than 7 days, queued more than 7 days ago, are never sent', $c->sent === array('fresh-pv'));
t('they are marked expired, not deleted', count($wpdb->rows("status = 'expired'")) === 3);
t('the expiry count is reported', $stats['expired'] === 3);
t('an expired row drops its payload', $wpdb->rows("event_id = 'old-buy'")[0]['payload'] === '{}');
t('an expired row says why', $wpdb->rows("event_id = 'old-buy'")[0]['last_error'] === 'expired');
// settled rows leave through the same retention as done/failed/dropped
$wpdb->query("UPDATE wp_dfss_queue SET created_at = " . ($now - 31 * 86400) . " WHERE event_id = 'old-buy'");
run(new FakeClient());
t('an expired row is purged with the settled rows after the retention', count($wpdb->rows("event_id = 'old-buy'")) === 0);
t('the other expired rows are still there', count($wpdb->rows("status = 'expired'")) === 2);

// the later of the event time and the queueing decides
fresh();
queue('purchase', 'late-buy', $now - 8 * 86400); // order paid 8 days after it was created: queued now
queue_old('page_view', 'stale-row', 8, 8);
queue_old('purchase', 'old-event-fresh-row', 3, 20); // event 20 days old, queued 3 days ago
$c = new FakeClient();
run($c);
t('an old event queued just now is not expired: it is sent', in_array('late-buy', $c->sent, true) && $wpdb->rows("event_id = 'late-buy'")[0]['status'] === 'done');
t('an old event queued less than 7 days ago is sent too', in_array('old-event-fresh-row', $c->sent, true));
t('a row queued more than 7 days ago, for an event as old, is expired', $wpdb->rows("event_id = 'stale-row'")[0]['status'] === 'expired' && !in_array('stale-row', $c->sent, true));

// ---- 4. time budget
fresh();
for ($i = 0; $i < 5; $i++) { queue('page_view', "e$i", $now - $i); }
$c = new FakeClient();
$stats = run($c, 0);
t('no budget left: nothing is sent', $c->sent === array() && $stats['stopped'] === 'budget');
t('rows left behind stay pending, not claimed', count($wpdb->rows("status = 'pending'")) === 5 && count($wpdb->rows("status = 'sending'")) === 0);
$c = new FakeClient();
$c->delay_us = 60000;
$stats = run($c, 0.1);
t('a run stops starting sends once the budget is spent', count($c->sent) >= 1 && count($c->sent) < 5 && $stats['stopped'] === 'budget');
t('the rest is still pending', count($wpdb->rows("status = 'pending'")) === 5 - count($c->sent) && count($wpdb->rows("status = 'sending'")) === 0);
// cap per run
fresh();
for ($i = 0; $i < 5; $i++) { queue('page_view', "e$i", $now - $i); }
$c = new FakeClient();
run($c, null, 3);
t('a run sends at most the batch cap', count($c->sent) === 3);
t('the default cap is 200 rows and 12 seconds', DFSS_Queue::REPLAY_BATCH === 200 && DFSS_Queue::REPLAY_BUDGET_SECONDS === 12);

// ---- 5. stop rules
foreach (array(0 => 'network failure', 500 => '5xx', 503 => '503', 429 => 'rate limit') as $code => $label) {
    fresh();
    queue('purchase', 'first', $now - 1);
    queue('page_view', 'second', $now - 2);
    queue('page_view', 'third', $now - 3);
    $c = new FakeClient(array('first' => $code));
    $stats = run($c);
    t("no new send after $label", $c->sent === array('first') && $stats['stopped'] === 'dispatcher');
    $r = $wpdb->rows("event_id = 'first'")[0];
    t("$label: the failed row is rescheduled, the others untouched", $r['status'] === 'pending' && (int) $r['attempts'] === 2 && (int) $r['next_attempt'] > $now && count($wpdb->rows("status = 'pending' AND attempts = 1")) === 2);
}
fresh();
queue('purchase', 'first', $now - 1);
queue('page_view', 'second', $now - 2);
$c = new FakeClient(array('first' => 422));
run($c);
t('a 4xx other than 429 concerns that one event: the run goes on', $c->sent === array('first', 'second'));
fresh();
queue('purchase', 'first', $now - 1);
queue('page_view', 'second', $now - 2);
$c = new FakeClient(array('first' => 401));
run($c);
t('a 401 marks the row failed and the run goes on', $wpdb->rows("event_id = 'first'")[0]['status'] === 'failed' && $c->sent === array('first', 'second'));

// ---- 6. claim protection
fresh();
queue('page_view', 'busy', $now - 1);
queue('page_view', 'stale', $now - 2);
$wpdb->query("UPDATE wp_dfss_queue SET status = 'sending', updated_at = " . $now . " WHERE event_id = 'busy'");
$wpdb->query("UPDATE wp_dfss_queue SET status = 'sending', updated_at = " . ($now - 700) . " WHERE event_id = 'stale'");
$c = new FakeClient();
run($c);
t('a row claimed by a live run is not sent twice; a stale claim goes back to the queue', $c->sent === array('stale'));

// a concurrent run takes a row after this run selected it
fresh();
queue('page_view', 'mine', $now - 1);
queue('page_view', 'taken', $now - 2);
$c = new FakeClient();
$c->on_send = function ($id) use ($wpdb) { $wpdb->query("UPDATE wp_dfss_queue SET status = 'sending', updated_at = " . time() . " WHERE event_id = 'taken'"); };
run($c);
t('a row claimed by another run after the selection is not sent twice', $c->sent === array('mine'));

// ---- 7. queue-health headers
fresh();
$GLOBALS['posted'] = array();
queue('page_view', 'h1', $now - 120);
queue('page_view', 'h2', $now - 30);
$client = new DFSS_Client('t', 's', 'https://x/v1/events');
$client->send(array('eventName' => 'page_view', 'eventId' => 'z'));
$h = $GLOBALS['posted'][0]['headers'];
t('depth header carries the pending count', $h['X-Dfss-Queue-Depth'] === '2');
t('oldest-age header carries the seconds since the oldest event', abs((int) $h['X-Dfss-Queue-Oldest-Age'] - 120) <= 2);
$q = $wpdb->queries;
$client->send(array('eventName' => 'page_view', 'eventId' => 'z2'));
$client->send(array('eventName' => 'page_view', 'eventId' => 'z3'));
t('computed once per request', $wpdb->queries === $q && $GLOBALS['posted'][2]['headers']['X-Dfss-Queue-Depth'] === '2');
fresh();
$client->send(array('eventName' => 'page_view', 'eventId' => 'z'));
$h = end($GLOBALS['posted'])['headers'];
t('an empty queue reports depth 0 and age 0', $h['X-Dfss-Queue-Depth'] === '0' && $h['X-Dfss-Queue-Oldest-Age'] === '0');
$wpdb->query('DROP TABLE wp_dfss_queue');
DFSS_Queue::reset_signal();
$client->send(array('eventName' => 'page_view', 'eventId' => 'z'));
$h = end($GLOBALS['posted'])['headers'];
t('headers are left out when the queue cannot be read', !isset($h['X-Dfss-Queue-Depth']) && !isset($h['X-Dfss-Queue-Oldest-Age']) && isset($h['X-Dfss-Signature']));

// ---- 8. schema behind the code
$log = tempnam(sys_get_temp_dir(), 'dfss');
ini_set('error_log', $log);
fresh(true);
queue('purchase', 'old-1', $now - 5);
queue('page_view', 'old-2', $now - 9);
t('an event is not lost when the columns are missing', count($wpdb->rows()) === 2);
// the next replay finds the table behind: it migrates, and still sends
$c = new FakeClient();
run($c);
t('a replay on a table behind the code still sends', count($c->sent) === 2);
t('the migration was attempted and the rows are now dated', $wpdb->rows("event_id = 'old-1'")[0]['priority'] == 1 && (int) $wpdb->rows("event_id = 'old-1'")[0]['event_time'] > 0);
// stays behind (migration impossible): many runs, one log line
fresh(true);
$wpdb->can_migrate = false;
$GLOBALS['transients'] = array();
file_put_contents($log, '');
for ($i = 0; $i < 3; $i++) { queue('page_view', "n$i", $now); }
$first = new FakeClient();
run($first);
t('with the migration impossible the replay still sends, newest row first', $first->sent === array('n2', 'n1', 'n0'));
for ($run = 0; $run < 3; $run++) { run(new FakeClient()); }
$lines = array_filter(explode("\n", trim((string) file_get_contents($log))));
t('a schema problem is logged at most once an hour, not on every run', count($lines) === 1);
$wpdb->can_migrate = true;
unlink($log);

// ---- 9. backfill: idempotent and resumable
fresh();
$wpdb->query("INSERT INTO wp_dfss_queue (event_name, event_id, payload, status, created_at) VALUES ('purchase','b1','{}','pending',1000), ('page_view','b2','{}','pending',2000), ('refund','b3','{}','done',3000)");
t('backfill succeeds', DFSS_Queue::backfill() === true);
$r = array();
foreach ($wpdb->rows() as $x) { $r[$x['event_id']] = array((int) $x['priority'], (int) $x['event_time']); }
t('backfill sets priority and event_time from the row', $r === array('b1' => array(1, 1000), 'b2' => array(0, 2000), 'b3' => array(1, 3000)));
$wpdb->query("UPDATE wp_dfss_queue SET event_time = 4242 WHERE event_id = 'b2'");
DFSS_Queue::backfill();
t('a second backfill changes nothing (rows already dated are left alone)', (int) $wpdb->rows("event_id = 'b2'")[0]['event_time'] === 4242);
$wpdb->create(true);
t('backfill reports the migration has not run when the columns are missing', DFSS_Queue::backfill() === false);

// ---- 10. claim guarded by the version read with the row
fresh();
queue('page_view', 'first', $now - 1);
queue('page_view', 'second', $now - 2);
$c = new FakeClient();
$c->on_send = function ($id) use ($wpdb, $now) {
    // another run retried 'second' and rescheduled it, between our selection and our claim
    $wpdb->query("UPDATE wp_dfss_queue SET attempts = 2, next_attempt = " . ($now + 3600) . " WHERE event_id = 'second'");
};
run($c);
t('a row rescheduled by a concurrent run is not sent again', $c->sent === array('first'));
$r = $wpdb->rows("event_id = 'second'")[0];
t('and its attempts and due time are not overwritten', (int) $r['attempts'] === 2 && (int) $r['next_attempt'] === $now + 3600 && $r['status'] === 'pending');

// ---- 11. an insert that fails twice is logged, once an hour
fresh();
$wpdb->query('DROP TABLE wp_dfss_queue');
file_put_contents($log = tempnam(sys_get_temp_dir(), 'dfss'), '');
ini_set('error_log', $log);
for ($i = 0; $i < 5; $i++) { queue('page_view', "lost$i"); }
$lines = array_filter(explode("\n", trim((string) file_get_contents($log))));
t('a lost event is logged, once whatever the number of requests (plus one line for the schema)', count(preg_grep('/insert failed/', $lines)) === 1 && count($lines) === 2);
unlink($log);

// ---- 12. expired count for the panel
fresh();
queue_old('page_view', 'x1', 9);
queue_old('page_view', 'x2', 9);
queue('page_view', 'x3', $now);
run(new FakeClient());
t('count_expired counts the expired rows listed', DFSS_Queue::count_expired() === 2);
t('the last run remembers how many it expired', DFSS_Queue::last_run()['expired'] === 2);

// ---- 13. timeouts: every send, checkout, beacon relay and replay, keeps the 4 s timeout
$GLOBALS['posted'] = array();
$GLOBALS['hooks'] = array();
$client = new DFSS_Client('t', 's', 'https://x/v1/events');
$client->send(array('eventName' => 'purchase', 'eventId' => 'o1'));
$client->send(array('eventName' => 'page_view', 'eventId' => 'b1'));
t('sends keep their 4 s timeout and touch no connect setting', $GLOBALS['posted'][0]['timeout'] === 4 && $GLOBALS['posted'][1]['timeout'] === 4 && empty($GLOBALS['hooks']['http_api_curl']));
$rest = file_get_contents(__DIR__ . '/../includes/class-dfss-rest.php');
$main = file_get_contents(__DIR__ . '/../datafirefly-server-side.php');
t('the beacon relay and the order hooks pass no longer timeout', strpos($rest, 'RELAY_TIMEOUT') === false && preg_match('/->send\(\$payload, /', $main) === 0);
$unin = file_get_contents(__DIR__ . '/../uninstall.php');
t('uninstall removes the last-run option and the schema transient', strpos($unin, "'dfss_queue_last_run'") !== false && strpos($unin, "delete_transient('dfss_queue_schema_notice')") !== false);

// ---- 14. heartbeat after a run that changed the queue
fresh();
queue('page_view', 'hb1', $now - 5);
queue('page_view', 'hb2', $now - 6);
DFSS_Queue::signal(); // cached before the run, as on a request that also relays an event
$c = new FakeClient();
run($c);
t('one heartbeat after a draining run, with a fresh signal (depth 0, age 0)', count($c->heartbeats) === 1 && $c->heartbeats[0] === array('depth' => 0, 'oldest_age' => 0));
fresh();
$c = new FakeClient();
run($c);
t('no heartbeat when the run did nothing', $c->heartbeats === array());
fresh();
queue('page_view', 'later', $now);
$wpdb->query("UPDATE wp_dfss_queue SET next_attempt = " . ($now + 3600));
$c = new FakeClient();
run($c);
t('no heartbeat when nothing was due', $c->heartbeats === array());
fresh();
queue_old('page_view', 'old', 9);
$c = new FakeClient();
run($c);
t('a run that only expired rows sends one heartbeat', count($c->heartbeats) === 1);
fresh();
queue('page_view', 'f1', $now - 1);
queue('page_view', 'f2', $now - 2);
$c = new FakeClient(array('f1' => 503));
run($c);
t('a run that rescheduled a row sends one heartbeat with the remaining depth', count($c->heartbeats) === 1 && $c->heartbeats[0]['depth'] === 2);

// the real client: URL, signature, headers, silence
$GLOBALS['posted'] = array();
$GLOBALS['posted_urls'] = array();
fresh();
queue('page_view', 'k1', $now - 100);
$real = new DFSS_Client('t', 's', 'https://d.example/v1/events');
$real->heartbeat();
t('the heartbeat goes to /v1/heartbeat on the events host', end($GLOBALS['posted_urls']) === 'https://d.example/v1/heartbeat');
$a = end($GLOBALS['posted']);
$hb = json_decode($a['body'], true);
t('body is {"t": unix milliseconds close to now}', is_array($hb) && array_keys($hb) === array('t') && is_int($hb['t']) && abs($hb['t'] - microtime(true) * 1000) < 5000 && preg_match('/^\{"t":\d{13}\}$/', $a['body']) === 1);
t('signed like an event over that exact body, 4 s timeout', $a['timeout'] === 4 && $a['headers']['X-Dfss-Signature'] === hash_hmac('sha256', $a['headers']['X-Dfss-Timestamp'] . "\n" . $a['body'], 's') && $a['headers']['X-Dfss-Signature-Version'] === '2');
usleep(3000);
$real->heartbeat();
$b2 = end($GLOBALS['posted']);
t('two heartbeats in the same second differ in body, hence in signature', $b2['body'] !== $a['body'] && $b2['headers']['X-Dfss-Signature'] !== $a['headers']['X-Dfss-Signature']);
t('with the queue headers', $a['headers']['X-Dfss-Queue-Depth'] === '1' && abs((int) $a['headers']['X-Dfss-Queue-Oldest-Age'] - 100) <= 2);
$real2 = new DFSS_Client('t', 's', 'https://d.example:8443/other/path');
$real2->heartbeat();
t('URL derived from scheme, host and port when the endpoint is not /v1/events', end($GLOBALS['posted_urls']) === 'https://d.example:8443/v1/heartbeat');
$n = count($GLOBALS['posted']);
(new DFSS_Client('', 's', 'https://d.example/v1/events'))->heartbeat();
t('a client that is not configured sends nothing', count($GLOBALS['posted']) === $n);
// 404 and failures are ignored: no exception, no queue row, no log
$rows = count($wpdb->rows());
file_put_contents($log = tempnam(sys_get_temp_dir(), 'dfss'), '');
ini_set('error_log', $log);
$GLOBALS['answer'] = 404;
$ret = $real->heartbeat();
$GLOBALS['answer'] = 0;
$ret2 = $real->heartbeat();
unset($GLOBALS['answer']);
t('a 404 or a network failure is ignored: nothing returned, queued or logged', $ret === null && $ret2 === null && count($wpdb->rows()) === $rows && trim((string) file_get_contents($log)) === '');
unlink($log);
// age unknown
fresh();
$wpdb->query("INSERT INTO wp_dfss_queue (event_name, event_id, payload, status, created_at, event_time) VALUES ('page_view','u1','{}','pending',1000,0)");
DFSS_Queue::reset_signal();
$GLOBALS['posted'] = array();
$real->heartbeat();
$h = end($GLOBALS['posted'])['headers'];
t('depth > 0 with no known event time: the age header is omitted, not 0', $h['X-Dfss-Queue-Depth'] === '1' && !isset($h['X-Dfss-Queue-Oldest-Age']));

// ---- 15. an insert failure report never blocks the migration; a missing table self-heals
fresh();
$wpdb->query('DROP TABLE wp_dfss_queue');
$wpdb->missing = true;
$GLOBALS['transients']['dfss_queue_insert_notice'] = 1; // a lost event was reported a minute ago
queue('purchase', 'heal1', $now);
t('with the insert report already logged, the next insert still migrates and is stored', count($wpdb->rows("event_id = 'heal1'")) === 1);
t('the two notices use separate transients', DFSS_Queue::INSERT_NOTICE_TRANSIENT !== DFSS_Queue::SCHEMA_NOTICE_TRANSIENT);
fresh();
$wpdb->query('DROP TABLE wp_dfss_queue');
$wpdb->missing = true;
$wpdb->can_migrate = false;
file_put_contents($log = tempnam(sys_get_temp_dir(), 'dfss'), '');
ini_set('error_log', $log);
queue('page_view', 'lost1');
t('a lost event sets the insert notice, not the schema one blocking a later migration', !empty($GLOBALS['transients']['dfss_queue_insert_notice']));
$wpdb->can_migrate = true;
unset($GLOBALS['transients']['dfss_queue_schema_notice']);
queue('page_view', 'heal2');
t('once the migration can run, the next insert self-heals', count($wpdb->rows("event_id = 'heal2'")) === 1);
unlink($log);
$wpdb->missing = false;
t('uninstall also removes the insert notice', strpos(file_get_contents(__DIR__ . '/../uninstall.php'), "delete_transient('dfss_queue_insert_notice')") !== false);

// ---- 16. no heartbeat when the run stopped on a network failure; one after a 5xx or 429
fresh();
queue('purchase', 'n1', $now - 1);
queue('page_view', 'n2', $now - 2);
$c = new FakeClient(array('n1' => 0));
$stats = run($c);
t('a run that stopped on code 0 sends no heartbeat', $c->heartbeats === array() && $stats['network'] === true);
foreach (array(503, 429) as $code) {
    fresh();
    queue('purchase', 'n1', $now - 1);
    queue('page_view', 'n2', $now - 2);
    $c = new FakeClient(array('n1' => $code));
    run($c);
    t("a run that stopped on $code still sends its heartbeat", count($c->heartbeats) === 1);
}
fresh();
queue_old('page_view', 'e1', 9);
queue('purchase', 'n1', $now - 1);
$c = new FakeClient(array('n1' => 0));
run($c);
t('expiring rows then stopping on code 0 still sends no heartbeat (it would only wait 4 s)', $c->heartbeats === array());

// ---- 17. circuit breaker on direct sends
foreach (array(0, 429, 500, 503) as $code) {
    fresh();
    $c = new FakeClient(array('o1' => $code));
    $r = DFSS_Queue::send_direct($c, array('eventName' => 'purchase', 'eventId' => 'o1'));
    t("an outage result ($code) on a direct send opens the breaker for 60 s", !empty($GLOBALS['transients']['dfss_direct_skip']) && $GLOBALS['transient_ttl']['dfss_direct_skip'] === 60 && $r['code'] === $code);
}
fresh();
$c = new FakeClient(array('o1' => 422));
DFSS_Queue::send_direct($c, array('eventName' => 'purchase', 'eventId' => 'o1'));
$c2 = new FakeClient();
DFSS_Queue::send_direct($c2, array('eventName' => 'purchase', 'eventId' => 'o2'));
t('a 4xx or a success leaves the breaker closed', empty($GLOBALS['transients']['dfss_direct_skip']));
fresh();
$GLOBALS['transients']['dfss_direct_skip'] = 1;
$c = new FakeClient();
$payload = array('eventName' => 'page_view', 'eventId' => 'beacon1');
$r = DFSS_Queue::send_direct($c, $payload);
DFSS_Queue::record_attempt($payload, $r, 'beacon');
t('with the breaker open the next beacon makes no network call', $c->sent === array());
$row = $wpdb->rows("event_id = 'beacon1'")[0];
t('and is queued at once as pending, due now, no attempt spent', $row['status'] === 'pending' && (int) $row['next_attempt'] <= time() && (int) $row['attempts'] === 0 && $row['payload'] !== '{}');
$pp = array('eventName' => 'purchase', 'eventId' => 'order9');
$r = DFSS_Queue::send_direct($c, $pp);
DFSS_Queue::record_attempt($pp, $r, 'server');
t('an order or refund hook never waits while the breaker is open', $c->sent === array() && count($wpdb->rows("event_id = 'order9' AND status = 'pending'")) === 1);
// the replay ignores the breaker and a delivery clears it
$c = new FakeClient();
run($c);
t('the replay is the probe: it sends despite the breaker, purchase first', $c->sent === array('order9', 'beacon1'));
t('a successful replay clears the breaker', empty($GLOBALS['transients']['dfss_direct_skip']));
fresh();
queue('purchase', 'p', $now - 1);
$c = new FakeClient(array('p' => 503));
run($c);
t('a replay that meets an outage keeps direct sends away', !empty($GLOBALS['transients']['dfss_direct_skip']));
// call sites
$rest = file_get_contents(__DIR__ . '/../includes/class-dfss-rest.php');
$main = file_get_contents(__DIR__ . '/../datafirefly-server-side.php');
t('the beacon relay and the three order hooks go through the breaker', strpos($rest, 'DFSS_Queue::send_direct(') !== false && substr_count($main, 'DFSS_Queue::send_direct($client, $payload)') === 3);
t('the connection test still really sends', substr_count($main, '$result = $client->send($payload);') === 1);

echo "\n$ok passed, $fail failed\n";
exit($fail ? 1 : 0);
