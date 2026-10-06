<?php
/**
 * Retry queue and activity log ({prefix}dfss_queue): failed sends are replayed by cron with
 * backoff; every attempt leaves a row for the Activity panel, trimmed to KEEP_ROWS.
 */
if (!defined('ABSPATH')) {
    exit;
}

class DFSS_Queue
{
    const CRON_HOOK = 'dfss_retry';
    const MAX_ATTEMPTS = 6;
    const KEEP_ROWS = 200;
    const TRIM_EVERY = 20;
    // Finished rows are kept this long for the Activity panel, then purged: the table is a delivery
    // log, not an archive.
    const PURGE_AFTER = 30 * DAY_IN_SECONDS;
    // A row claimed by a cron run that died mid-send goes back to pending after this long.
    const STALE_CLAIM = 600;

    // Status values stored in the `status` column.
    const STATUS_PENDING = 'pending'; // queued, awaiting a retry
    const STATUS_SENDING = 'sending'; // claimed by a cron run, being replayed
    const STATUS_DONE = 'done';       // delivered (2xx)
    const STATUS_FAILED = 'failed';   // non-retryable (401/403), never retried
    const STATUS_DROPPED = 'dropped'; // gave up after MAX_ATTEMPTS

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
        // without unserializing every payload.
        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_name VARCHAR(40) NOT NULL DEFAULT '',
            event_id VARCHAR(160) NOT NULL DEFAULT '',
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
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
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
     * Replay due pending rows.
     *
     * @param string $tenant_id
     * @param string $hmac_secret
     * @param string $endpoint
     *
     * @return void
     */
    public static function process_due($tenant_id, $hmac_secret, $endpoint)
    {
        global $wpdb;

        if ($tenant_id === '' || $hmac_secret === '' || $endpoint === '') {
            return; // not connected: leave the queue intact
        }

        $table = self::table();
        $now = time();

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

        // Bounded batch so a long backlog can't exhaust the cron's time budget.
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom plugin table ({$wpdb->prefix}dfss_queue, name built from $wpdb->prefix only); values are passed through $wpdb->prepare(); a live retry queue must not be served from cache.
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = %s AND next_attempt <= %d ORDER BY id ASC LIMIT 20", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                self::STATUS_PENDING,
                $now
            )
        );
        if (empty($rows)) {
            self::trim();

            return;
        }

        $client = new DFSS_Client($tenant_id, $hmac_secret, $endpoint);

        foreach ($rows as $row) {
            // Atomic claim: WP-Cron does not lock, so two overlapping runs may select the same row;
            // only the UPDATE that flips it from pending wins.
            $claimed = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom plugin table ({$wpdb->prefix}dfss_queue, name built from $wpdb->prefix only); values are passed through $wpdb->prepare(); a live retry queue must not be served from cache.
                $wpdb->prepare(
                    "UPDATE {$table} SET status = %s, updated_at = %d WHERE id = %d AND status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    self::STATUS_SENDING,
                    $now,
                    (int) $row->id,
                    self::STATUS_PENDING
                )
            );
            if ($claimed !== 1) {
                continue;
            }

            $payload = json_decode((string) $row->payload, true);
            if (!is_array($payload)) {
                // Corrupt row, drop it so it can't loop forever.
                self::update_status((int) $row->id, self::STATUS_DROPPED, (int) $row->attempts, 0, 0, 'corrupt_payload');
                continue;
            }

            $result = $client->send($payload);
            $attempts = (int) $row->attempts + 1;
            $code = isset($result['code']) ? (int) $result['code'] : 0;

            if (!empty($result['ok'])) {
                self::update_status((int) $row->id, self::STATUS_DONE, $attempts, 0, $code, '');
                continue;
            }

            if (self::is_non_retryable($code)) {
                self::update_status((int) $row->id, self::STATUS_FAILED, $attempts, 0, $code, self::clip($result));
                continue;
            }

            if ($attempts >= self::MAX_ATTEMPTS) {
                self::update_status((int) $row->id, self::STATUS_DROPPED, $attempts, 0, $code, self::clip($result));
                continue;
            }

            // Still retryable, back off further.
            self::update_status(
                (int) $row->id,
                self::STATUS_PENDING,
                $attempts,
                $now + self::backoff($attempts),
                $code,
                self::clip($result)
            );
        }

        self::trim();
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

        $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- state write to the plugin's own retry-queue table; caching does not apply.
            self::table(),
            array(
                'event_name' => substr((string) ($payload['eventName'] ?? ''), 0, 40),
                'event_id' => substr((string) ($payload['eventId'] ?? ''), 0, 160),
                'payload' => $encoded,
                'origin' => substr((string) $origin, 0, 20),
                'status' => $status,
                'attempts' => (int) $attempts,
                'next_attempt' => (int) $next_attempt,
                'last_code' => (int) $code,
                'last_error' => substr((string) $error, 0, 255),
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%d')
        );
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
