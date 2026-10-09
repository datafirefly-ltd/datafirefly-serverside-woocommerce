<?php
/**
 * Retry queue and activity log ({prefix}dfss_queue): failed sends are replayed by cron with
 * backoff; every attempt leaves a row for the Activity panel, trimmed to KEEP_ROWS.
 *
 * Replay (2.30.0). WP-Cron fires `dfss_retry` every 5 minutes, but only when WordPress is
 * visited (or when a real server cron calls wp-cron.php): a quiet shop retries late. Each run:
 *
 *   - expires the pending events older than EXPIRY_DAYS (status `expired`: marked, never sent,
 *     never deleted; a settled row like done/failed/dropped);
 *   - replays purchases and refunds first, then the most recent events: a five-day-old page view
 *     is worth nothing, this morning's sale is the point;
 *   - works within REPLAY_BUDGET_SECONDS and REPLAY_BATCH rows, and starts no new send after a
 *     network failure (code 0), a 5xx or a 429: a dispatcher that is down is not hammered 200 times;
 *   - claims each row just before sending it, so overlapping runs never send the same row twice.
 *
 * Every signed request carries the queue's depth and the age of its oldest event
 * (see signal()), so the dispatcher can see a client whose queue is running late.
 */
if (!defined('ABSPATH')) {
    exit;
}

class DFSS_Queue
{
    /**
     * @var array{depth:int,oldest_age:int|null}|false|null Per-request cache of signal().
     */
    private static $signal_cache = null;

    const CRON_HOOK = 'dfss_retry';
    const MAX_ATTEMPTS = 6;
    const KEEP_ROWS = 200;
    const TRIM_EVERY = 20;
    // Finished rows are kept this long for the Activity panel, then purged: the table is a delivery
    // log, not an archive.
    const PURGE_AFTER = 30 * DAY_IN_SECONDS;
    // A row claimed by a cron run that died mid-send goes back to pending after this long.
    const STALE_CLAIM = 600;
    // A pending event older than this is expired, never sent: no platform accepts it any more, and
    // replaying it only delays the ones that still count.
    const EXPIRY_DAYS = 7;
    // Rows replayed at most per cron run, and the wall-clock budget (seconds) after which a run
    // starts no new send. A send can last its own timeout (4 s) past the budget, and the heartbeat
    // 4 s more: the worst case stays near 20 s, under the usual max_execution_time of 30 s.
    const REPLAY_BATCH = 200;
    const REPLAY_BUDGET_SECONDS = 12;
    // Events replayed before everything else.
    const PRIORITY_EVENTS = array('purchase', 'refund');
    // Option: when the last replay ran (Activity panel diagnostics).
    const LAST_RUN_OPTION = 'dfss_queue_last_run';
    // Transient: a schema problem was reported or repaired less than an hour ago.
    const SCHEMA_NOTICE_TRANSIENT = 'dfss_queue_schema_notice';
    // Transient: a lost event was reported less than an hour ago. Its own key, so that reporting
    // a lost event never keeps the migration from being attempted.
    const INSERT_NOTICE_TRANSIENT = 'dfss_queue_insert_notice';

    // Status values stored in the `status` column.
    const STATUS_PENDING = 'pending'; // queued, awaiting a retry
    const STATUS_SENDING = 'sending'; // claimed by a cron run, being replayed
    const STATUS_DONE = 'done';       // delivered (2xx)
    const STATUS_FAILED = 'failed';   // non-retryable (401/403), never retried
    const STATUS_DROPPED = 'dropped'; // gave up after MAX_ATTEMPTS
    const STATUS_EXPIRED = 'expired'; // pending for more than EXPIRY_DAYS, marked and never sent

    /**
     * @return string Fully-qualified table name.
     */
    public static function table()
    {
        global $wpdb;

        return $wpdb->prefix . 'dfss_queue';
    }

    /**
     * Create the table.
     */
    public static function install()
    {
        global $wpdb;

        $table = self::table();
        $charset_collate = $wpdb->get_charset_collate();

        // event_name + event_id are denormalized columns purely so the Activity panel can render
        // without unserializing every payload. priority + event_time (2.30.0) order the replay:
        // purchases and refunds first, then the most recent event (its own time, else the row's
        // creation).
        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_name VARCHAR(40) NOT NULL DEFAULT '',
            event_id VARCHAR(160) NOT NULL DEFAULT '',
            priority TINYINT UNSIGNED NOT NULL DEFAULT 0,
            event_time INT UNSIGNED NOT NULL DEFAULT 0,
            payload LONGTEXT NOT NULL,
            origin VARCHAR(20) NOT NULL DEFAULT 'server',
            status VARCHAR(12) NOT NULL DEFAULT 'pending',
            attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            next_attempt INT UNSIGNED NOT NULL DEFAULT 0,
            last_code INT NOT NULL DEFAULT 0,
            last_error VARCHAR(255) NOT NULL DEFAULT '',
            created_at INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY status_next (status, next_attempt),
            KEY status_prio (status, priority, event_time),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        self::backfill();
    }

    /**
     * Fill priority and event_time for the rows written before 2.30.0. Two set-based UPDATEs that
     * only touch rows still at the default, so a run interrupted halfway simply resumes, and a
     * rerun changes nothing. event_time is the row's creation (an epoch, so no time-zone skew):
     * for a purchase or a beacon it differs from the event by the length of the send only.
     *
     * @return bool False when the columns are not there (the migration has not run).
     */
    public static function backfill()
    {
        global $wpdb;

        $table = self::table();
        $suppress = $wpdb->suppress_errors(true);

        $a = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom plugin table ({$wpdb->prefix}dfss_queue, name built from $wpdb->prefix only); values are passed through $wpdb->prepare().
            $wpdb->prepare(
                "UPDATE {$table} SET priority = 1 WHERE priority = 0 AND event_name IN (%s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                self::PRIORITY_EVENTS[0],
                self::PRIORITY_EVENTS[1]
            )
        );
        $b = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom plugin table ({$wpdb->prefix}dfss_queue, name built from $wpdb->prefix only); constant statement.
            "UPDATE {$table} SET event_time = created_at WHERE event_time = 0" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );

        $wpdb->suppress_errors($suppress);

        return $a !== false && $b !== false;
    }

    /**
     * Schedule the recurring retry cron if not already scheduled.
     */
    public static function schedule_cron()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            // 'dfss_5min' interval is registered in the main plugin file.
            wp_schedule_event(time() + 300, 'dfss_5min', self::CRON_HOOK);
        }
    }

    /**
     * Remove the cron (plugin deactivation).
     */
    public static function unschedule_cron()
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /**
     * Record a send attempt: 'done' on success, 'pending' with backoff if retryable, 'failed' on 401/403.
     *
     * @param array  $payload The IncomingEvent that was (attempted to be) sent.
     * @param array  $result  DFSS_Client::send() result {ok,code,message}.
     * @param string $origin  'server' (purchase hook) or 'beacon' (REST collect).
     *
     * @return void
     */
    public static function record_attempt(array $payload, array $result, $origin = 'server')
    {
        $ok = !empty($result['ok']);
        $code = isset($result['code']) ? (int) $result['code'] : 0;

        if ($ok) {
            self::insert_row($payload, self::STATUS_DONE, 1, 0, $code, '', $origin);
            self::maybe_trim();

            return;
        }

        if (self::is_non_retryable($code)) {
            self::insert_row(
                $payload,
                self::STATUS_FAILED,
                1,
                0,
                $code,
                self::clip($result),
                $origin
            );
            self::maybe_trim();

            return;
        }

        // Retryable failure -> enqueue for the cron, first retry in ~5 min.
        self::insert_row(
            $payload,
            self::STATUS_PENDING,
            1,
            time() + self::backoff(1),
            $code,
            self::clip($result),
            $origin
        );
        self::maybe_trim();
    }

    /**
     * Replay due pending rows, within a time budget (see the class comment).
     *
     * @param string          $tenant_id
     * @param string          $hmac_secret
     * @param string          $endpoint
     * @param DFSS_Client|null $client  Injected by the tests; built from the credentials otherwise.
     * @param float|null      $budget   Seconds after which no new send starts (default REPLAY_BUDGET_SECONDS).
     * @param int|null        $batch    Rows at most (default REPLAY_BATCH).
     *
     * @return array{expired:int,sent:int,delivered:int,retry:int,failed:int,dropped:int,stopped:string,network:bool}
     */
    public static function process_due($tenant_id, $hmac_secret, $endpoint, $client = null, $budget = null, $batch = null)
    {
        global $wpdb;

        $stats = array('expired' => 0, 'sent' => 0, 'delivered' => 0, 'retry' => 0, 'failed' => 0, 'dropped' => 0, 'stopped' => '', 'network' => false);

        if ($tenant_id === '' || $hmac_secret === '' || $endpoint === '') {
            return $stats; // not connected: leave the queue intact
        }

        $table = self::table();
        $now = time();
        $deadline = microtime(true) + ($budget === null ? self::REPLAY_BUDGET_SECONDS : (float) $budget);
        $batch = $batch === null ? self::REPLAY_BATCH : max(1, (int) $batch);

        // Purge finished rows past PURGE_AFTER.
        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom plugin table ({$wpdb->prefix}dfss_queue, name built from $wpdb->prefix only); values are passed through $wpdb->prepare(); a live retry queue must not be served from cache.
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE status NOT IN (%s, %s) AND created_at < %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                self::STATUS_PENDING,
                self::STATUS_SENDING,
                $now - self::PURGE_AFTER
            )
        );

        // Hand back claims that never resolved (PHP killed mid-send).
        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom plugin table ({$wpdb->prefix}dfss_queue, name built from $wpdb->prefix only); values are passed through $wpdb->prepare(); a live retry queue must not be served from cache.
            $wpdb->prepare(
                "UPDATE {$table} SET status = %s, next_attempt = %d, updated_at = %d WHERE status = %s AND updated_at < %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                self::STATUS_PENDING,
                $now,
                $now,
                self::STATUS_SENDING,
                $now - self::STALE_CLAIM
            )
        );

        // Events too old to count are marked, before anything is selected: they must not take a
        // slot of the batch.
        $stats['expired'] = self::expire_old($now);

        $rows = self::select_due($now, $batch);
        if ($client === null) {
            $client = new DFSS_Client($tenant_id, $hmac_secret, $endpoint);
        }
        if (empty($rows)) {
            self::trim();
            self::remember_run($now, $stats);
            self::report($client, $stats);

            return $stats;
        }

        foreach ($rows as $row) {
            if (microtime(true) >= $deadline) {
                $stats['stopped'] = 'budget';
                break; // the rest stays pending, untouched, for the next run
            }

            // Atomic claim: WP-Cron does not lock, so two overlapping runs may select the same row;
            // only the UPDATE that flips it from pending wins. It also checks the attempts and the due time
            // read with the row: a row another run already retried and rescheduled is not sent again. Claimed one by one, just before the
            // send: a run that stops early leaves no row stuck in `sending`.
            $claimed = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom plugin table ({$wpdb->prefix}dfss_queue, name built from $wpdb->prefix only); values are passed through $wpdb->prepare(); a live retry queue must not be served from cache.
                $wpdb->prepare(
                    "UPDATE {$table} SET status = %s, updated_at = %d WHERE id = %d AND status = %s AND attempts = %d AND next_attempt <= %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    self::STATUS_SENDING,
                    time(),
                    (int) $row->id,
                    self::STATUS_PENDING,
                    (int) $row->attempts,
                    $now
                )
            );
            if ($claimed !== 1) {
                continue;
            }

            $payload = json_decode((string) $row->payload, true);
            if (!is_array($payload)) {
                // Corrupt row, drop it so it can't loop forever.
                self::update_status((int) $row->id, self::STATUS_DROPPED, (int) $row->attempts, 0, 0, 'corrupt_payload');
                ++$stats['dropped'];
                continue;
            }

            $result = $client->send($payload);
            ++$stats['sent'];
            $attempts = (int) $row->attempts + 1;
            $code = isset($result['code']) ? (int) $result['code'] : 0;

            if (!empty($result['ok'])) {
                self::update_status((int) $row->id, self::STATUS_DONE, $attempts, 0, $code, '');
                ++$stats['delivered'];
                continue;
            }

            if (self::is_non_retryable($code)) {
                self::update_status((int) $row->id, self::STATUS_FAILED, $attempts, 0, $code, self::clip($result));
                ++$stats['failed'];
                continue;
            }

            if ($attempts >= self::MAX_ATTEMPTS) {
                self::update_status((int) $row->id, self::STATUS_DROPPED, $attempts, 0, $code, self::clip($result));
                ++$stats['dropped'];
            } else {
                // Still retryable, back off further.
                self::update_status(
                    (int) $row->id,
                    self::STATUS_PENDING,
                    $attempts,
                    $now + self::backoff($attempts),
                    $code,
                    self::clip($result)
                );
                ++$stats['retry'];
            }

            // The dispatcher or the network is struggling: the next row would fail the same way.
            if (self::stops_run($code)) {
                $stats['stopped'] = 'dispatcher';
                $stats['network'] = $code === 0;
                break;
            }
        }

        self::trim();
        self::remember_run($now, $stats);
        self::report($client, $stats);

        return $stats;
    }

    /**
     * After a run that changed the queue (sent, expired or dropped a row), send the dispatcher one
     * heartbeat with fresh queue-health headers. The per-request signal was computed before the
     * run drained the queue, so it is forgotten first. A run that did nothing sends nothing.
     *
     * @param DFSS_Client $client
     * @param array       $stats
     *
     * @return void
     */
    private static function report($client, array $stats)
    {
        if ($stats['sent'] + $stats['expired'] + $stats['dropped'] === 0) {
            return;
        }
        // The run stopped because the dispatcher did not answer at all: a heartbeat would wait the
        // same 4 s for nothing, and push the run toward max_execution_time.
        if (!empty($stats['network'])) {
            return;
        }
        self::reset_signal();
        $client->heartbeat();
    }

    /**
     * A failure that says "stop sending for now": no answer at all (code 0), a server error or
     * rate limiting. A 4xx other than 429 concerns that one event only.
     *
     * @param int $code
     *
     * @return bool
     */
    public static function stops_run($code)
    {
        $code = (int) $code;

        return $code === 0 || $code === 429 || $code >= 500;
    }

    /**
     * Queue health for the X-Dfss-Queue-Depth and X-Dfss-Queue-Oldest-Age headers: the pending
     * rows and the age in seconds of the oldest one's event (0 when none).
     *
     * One aggregate query, at most once per request: a figure a few events off is fine, a query per
     * send is not. Null when the queue cannot be read (an unknown queue is not an empty one).
     *
     * @return array{depth:int,oldest_age:int|null}|null
     */
    public static function signal()
    {
        global $wpdb;

        if (self::$signal_cache !== null) {
            return self::$signal_cache === false ? null : self::$signal_cache;
        }

        $table = self::table();
        $suppress = $wpdb->suppress_errors(true);
        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom plugin table ({$wpdb->prefix}dfss_queue, name built from $wpdb->prefix only); values are passed through $wpdb->prepare(); live queue state must not be served from cache.
            $wpdb->prepare(
                "SELECT COUNT(*) AS depth, MIN(NULLIF(event_time, 0)) AS oldest FROM {$table} WHERE status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                self::STATUS_PENDING
            )
        );
        $wpdb->suppress_errors($suppress);

        if (!is_object($row)) {
            self::$signal_cache = false;

            return null;
        }
        $depth = (int) $row->depth;
        $oldest = (int) $row->oldest;
        self::$signal_cache = array(
            'depth' => $depth,
            // 0 for an empty queue; null (header left out) when rows wait but none has an event time.
            'oldest_age' => $depth === 0 ? 0 : ($oldest > 0 ? max(0, time() - $oldest) : null),
        );

        return self::$signal_cache;
    }

    /**
     * Forget the per-request signal (tests, and a long-running process).
     *
     * @return void
     */
    public static function reset_signal()
    {
        self::$signal_cache = null;
    }

    /**
     * Mark `expired` every pending row whose event is older than EXPIRY_DAYS (its own time, else
     * the row's creation). Marked, never sent, never deleted: the row leaves like any settled one.
     * When the columns are not there yet (migration not run) the age is the row's creation.
     *
     * @param int $now
     *
     * @return int Rows expired.
     */
    private static function expire_old($now)
    {
        global $wpdb;

        $table = self::table();
        $cutoff = $now - self::EXPIRY_DAYS * DAY_IN_SECONDS;
        $suppress = $wpdb->suppress_errors(true);

        $done = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom plugin table ({$wpdb->prefix}dfss_queue, name built from $wpdb->prefix only); values are passed through $wpdb->prepare(); a live retry queue must not be served from cache.
            $wpdb->prepare(
                "UPDATE {$table} SET status = %s, payload = %s, next_attempt = 0, last_error = %s, updated_at = %d WHERE status = %s AND ((event_time > 0 AND event_time < %d) OR (event_time = 0 AND created_at < %d))", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                self::STATUS_EXPIRED,
                '{}',
                'expired',
                $now,
                self::STATUS_PENDING,
                $cutoff,
                $cutoff
            )
        );
        if ($done === false) {
            self::schema_behind();
            $done = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom plugin table ({$wpdb->prefix}dfss_queue, name built from $wpdb->prefix only); values are passed through $wpdb->prepare().
                $wpdb->prepare(
                    "UPDATE {$table} SET status = %s, payload = %s, next_attempt = 0, last_error = %s, updated_at = %d WHERE status = %s AND created_at < %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    self::STATUS_EXPIRED,
                    '{}',
                    'expired',
                    $now,
                    self::STATUS_PENDING,
                    $cutoff
                )
            );
        }
        $wpdb->suppress_errors($suppress);

        return $done === false ? 0 : (int) $done;
    }

    /**
     * The due rows: purchases and refunds first, then the most recent event. A queue whose table
     * is still in the pre-2.30.0 shape (files updated, migration not run) is replayed newest row
     * first instead of not at all, and the migration is attempted.
     *
     * @param int $now
     * @param int $batch
     *
     * @return array<int,object>
     */
    private static function select_due($now, $batch)
    {
        global $wpdb;

        $table = self::table();
        $suppress = $wpdb->suppress_errors(true);

        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom plugin table ({$wpdb->prefix}dfss_queue, name built from $wpdb->prefix only); values are passed through $wpdb->prepare(); a live retry queue must not be served from cache.
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = %s AND next_attempt <= %d ORDER BY priority DESC, event_time DESC, id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                self::STATUS_PENDING,
                $now,
                $batch
            )
        );
        // wpdb returns an empty array, not null, when the query fails: the error text tells.
        if ($wpdb->last_error !== '' || !is_array($rows)) {
            $wpdb->last_error = '';
            self::schema_behind();
            $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom plugin table ({$wpdb->prefix}dfss_queue, name built from $wpdb->prefix only); values are passed through $wpdb->prepare().
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE status = %s AND next_attempt <= %d ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    self::STATUS_PENDING,
                    $now,
                    $batch
                )
            );
        }
        $wpdb->suppress_errors($suppress);

        return is_array($rows) ? $rows : array();
    }

    /**
     * The queue table is behind the code. Try the migration, and say so in the PHP error log at
     * most once an hour: a replay that runs every five minutes must not fill the log.
     *
     * @return void
     */
    private static function schema_behind()
    {
        if (get_transient(self::SCHEMA_NOTICE_TRANSIENT)) {
            return;
        }
        self::install();
        self::log_once('retry queue table is behind the plugin version; migration attempted, replay continues in creation order.');
    }

    /**
     * One line in the PHP error log, at most once an hour whatever the cause: the queue runs on
     * every storefront event and every five minutes, a log line each time would flood it.
     *
     * @param string $message
     * @param string $key     Transient that throttles this kind of line.
     *
     * @return void
     */
    private static function log_once($message, $key = self::SCHEMA_NOTICE_TRANSIENT)
    {
        if (get_transient($key)) {
            return;
        }
        set_transient($key, 1, HOUR_IN_SECONDS);
        error_log('[DataFirefly SS] ' . $message); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- one line an hour, the only trace of a queue that does not work.
    }

    /**
     * When the last replay ran, for the Activity panel (WP-Cron needs visitors or a real cron).
     *
     * @param int   $now
     * @param array $stats
     *
     * @return void
     */
    private static function remember_run($now, array $stats)
    {
        update_option(self::LAST_RUN_OPTION, array('time' => (int) $now, 'expired' => (int) $stats['expired'], 'sent' => (int) $stats['sent']), false);
    }

    /**
     * The last replay, or an empty array when none ran yet.
     *
     * @return array{time?:int,expired?:int,sent?:int}
     */
    public static function last_run()
    {
        $v = get_option(self::LAST_RUN_OPTION, array());

        return is_array($v) ? $v : array();
    }

    /**
     * Recent rows for the Activity panel.
     *
     * @param int $limit
     *
     * @return array<int,object>
     */
    public static function recent($limit = 20)
    {
        global $wpdb;

        $table = self::table();
        $limit = max(1, min(100, (int) $limit));

        return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom plugin table ({$wpdb->prefix}dfss_queue, name built from $wpdb->prefix only); values are passed through $wpdb->prepare(); a live retry queue must not be served from cache.
            $wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );
    }

    /**
     * Count of delivered events in the last 24h (Activity panel KPI).
     *
     * @return int
     */
    public static function count_last_24h()
    {
        global $wpdb;

        $table = self::table();
        $since = time() - DAY_IN_SECONDS;

        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom plugin table ({$wpdb->prefix}dfss_queue, name built from $wpdb->prefix only); values are passed through $wpdb->prepare(); a live retry queue must not be served from cache.
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE status = %s AND created_at >= %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                self::STATUS_DONE,
                $since
            )
        );
    }

    /**
     * Number of rows still pending retry (Activity panel KPI).
     *
     * @return int
     */
    public static function count_pending()
    {
        global $wpdb;

        $table = self::table();

        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom plugin table ({$wpdb->prefix}dfss_queue, name built from $wpdb->prefix only); values are passed through $wpdb->prepare(); a live retry queue must not be served from cache.
            $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", self::STATUS_PENDING) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );
    }

    /**
     * Number of expired rows still listed (Activity panel).
     *
     * @return int
     */
    public static function count_expired()
    {
        global $wpdb;

        $table = self::table();

        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom plugin table ({$wpdb->prefix}dfss_queue, name built from $wpdb->prefix only); values are passed through $wpdb->prepare(); live queue state must not be served from cache.
            $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", self::STATUS_EXPIRED) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );
    }

    // ---- internals ---------------------------------------------------------

    /**
     * @param array $payload
     * @param string $status
     * @param int    $attempts
     * @param int    $next_attempt
     * @param int    $code
     * @param string $error
     * @param string $origin
     */
    private static function insert_row(array $payload, $status, $attempts, $next_attempt, $code, $error, $origin)
    {
        global $wpdb;

        $now = time();
        // Only a row that will be replayed needs its payload.
        $encoded = $status === self::STATUS_PENDING
            ? wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : '{}';
        if ($encoded === false) {
            return; // cannot persist: skip silently (caller already attempted send)
        }

        $event_name = (string) ($payload['eventName'] ?? '');
        // The event's own time; the row's creation when the payload has none or an absurd one
        // (more than a day ahead).
        $event_time = isset($payload['eventTime']) && is_numeric($payload['eventTime']) ? (int) $payload['eventTime'] : 0;
        if ($event_time <= 0 || $event_time > $now + DAY_IN_SECONDS) {
            $event_time = $now;
        }

        $row = array(
            'event_name' => substr($event_name, 0, 40),
            'event_id' => substr((string) ($payload['eventId'] ?? ''), 0, 160),
            'priority' => in_array($event_name, self::PRIORITY_EVENTS, true) ? 1 : 0,
            'event_time' => $event_time,
            'payload' => $encoded,
            'origin' => substr((string) $origin, 0, 20),
            'status' => $status,
            'attempts' => (int) $attempts,
            'next_attempt' => (int) $next_attempt,
            'last_code' => (int) $code,
            'last_error' => substr((string) $error, 0, 255),
            'created_at' => $now,
            'updated_at' => $now,
        );
        $format = array('%s', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%d');

        $suppress = $wpdb->suppress_errors(true);
        $ok = $wpdb->insert(self::table(), $row, $format); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- state write to the plugin's own retry-queue table; caching does not apply.
        if ($ok === false) {
            // The table may be missing or still in the pre-2.30.0 shape (files updated, migration
            // not run yet). Try the migration (once an hour), then write again.
            self::schema_behind();
            $ok = $wpdb->insert(self::table(), $row, $format); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- same write, after the migration was attempted.
        }
        if ($ok === false) {
            // A failed send must never be lost for a missing column: write it without the two new
            // ones. The migration dates it from its creation.
            unset($row['priority'], $row['event_time']);
            array_splice($format, 2, 2);
            $ok = $wpdb->insert(self::table(), $row, $format); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- same table, same write, without the two newer columns.
            if ($ok === false) {
                // The event is lost: say so, at most once an hour.
                self::log_once('retry queue insert failed, event not queued: ' . substr((string) $wpdb->last_error, 0, 200), self::INSERT_NOTICE_TRANSIENT);
            }
        }
        $wpdb->suppress_errors($suppress);
    }

    /**
     * @param int $id
     * @param string $status
     * @param int    $attempts
     * @param int    $next_attempt
     * @param int    $code
     * @param string $error
     */
    private static function update_status($id, $status, $attempts, $next_attempt, $code, $error)
    {
        global $wpdb;

        $data = array(
            'status' => $status,
            'attempts' => (int) $attempts,
            'next_attempt' => (int) $next_attempt,
            'last_code' => (int) $code,
            'last_error' => substr((string) $error, 0, 255),
            'updated_at' => time(),
        );
        $format = array('%s', '%d', '%d', '%d', '%s', '%d');
        // Leaving the queue for good: the payload has done its job, drop the personal data with it.
        if ($status !== self::STATUS_PENDING && $status !== self::STATUS_SENDING) {
            $data['payload'] = '{}';
            $format[] = '%s';
        }

        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- state write to the plugin's own retry-queue table; caching does not apply.
            self::table(),
            $data,
            array('id' => (int) $id),
            $format,
            array('%d')
        );
    }

    /**
     * Trim on about one write in TRIM_EVERY: the log cap is soft between cron runs, and the hot path
     * saves two queries per event. The cron trims on every run.
     */
    private static function maybe_trim()
    {
        if (mt_rand(1, self::TRIM_EVERY) === 1) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- sampling, not security.
            self::trim();
        }
    }

    /**
     * Keep only the most recent KEEP_ROWS rows (circular log behaviour).
     */
    private static function trim()
    {
        global $wpdb;

        $table = self::table();

        // The id below which non-pending rows may be pruned: the KEEP_ROWS-th newest id overall.
        $cutoff = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom plugin table ({$wpdb->prefix}dfss_queue, name built from $wpdb->prefix only); values are passed through $wpdb->prepare(); a live retry queue must not be served from cache.
            $wpdb->prepare(
                "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                self::KEEP_ROWS - 1
            )
        );
        if ($cutoff === null) {
            return; // fewer than KEEP_ROWS rows: nothing to prune
        }

        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom plugin table ({$wpdb->prefix}dfss_queue, name built from $wpdb->prefix only); values are passed through $wpdb->prepare(); a live retry queue must not be served from cache.
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE id < %d AND status NOT IN (%s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                (int) $cutoff,
                self::STATUS_PENDING,
                self::STATUS_SENDING
            )
        );
    }

    /**
     * Exponential backoff in seconds for a given attempt number (1-based), capped at 1 hour. 1->5min,
     * 2->10min, 3->20min, 4->40min, 5+->60min.
     *
     * @param int $attempt
     *
     * @return int
     */
    private static function backoff($attempt)
    {
        $minutes = 5 * (2 ** max(0, (int) $attempt - 1));

        return (int) min($minutes, 60) * 60;
    }

    /**
     * 401/403 are configuration/state problems retrying can't fix.
     *
     * @param int $code
     *
     * @return bool
     */
    private static function is_non_retryable($code)
    {
        return in_array((int) $code, array(401, 403), true);
    }

    /**
     * Short, PII-free error string for storage.
     *
     * @param array $result
     *
     * @return string
     */
    private static function clip(array $result)
    {
        $code = isset($result['code']) ? (int) $result['code'] : 0;
        $msg = isset($result['message']) ? (string) $result['message'] : '';

        return 'HTTP ' . $code . ' ' . substr($msg, 0, 180);
    }
}
