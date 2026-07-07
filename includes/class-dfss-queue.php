<?php
/**
 * DataFirefly Server-Side (WooCommerce) — retry queue + activity log.
 *
 * One lightweight custom table ({$wpdb->prefix}dfss_queue) does double duty:
 *   1. Reliability: any send that fails with a retryable error is recorded and
 *      replayed by the `dfss_retry` WP-cron (every 5 minutes) with exponential
 *      backoff, then dropped after MAX_ATTEMPTS with an admin-visible note.
 *      Zero conversions lost on a brief network/dispatcher outage.
 *   2. Observability: every send attempt (ok or not) leaves a row, so the
 *      "DataFirefly -> Activity" panel reads the last events straight from here.
 *      The table is trimmed to KEEP_ROWS so it can never grow unbounded.
 *
 * We deliberately keep a single table (not Action Scheduler) so the plugin has
 * no hard dependency and stays installable on any WooCommerce 5.0+ shop.
 *
 * Auth/state errors (401/403) are NOT retried — they mean "wrong key" or
 * "tenant suspended", which retrying can never fix; they are logged as failed.
 */
if (!defined('ABSPATH')) {
    exit;
}

class DFSS_Queue
{
    const CRON_HOOK = 'dfss_retry';
    const MAX_ATTEMPTS = 6;
    const KEEP_ROWS = 200;

    // Status values stored in the `status` column.
    const STATUS_PENDING = 'pending'; // queued, awaiting a retry
    const STATUS_DONE = 'done';       // delivered (2xx)
    const STATUS_FAILED = 'failed';   // non-retryable (e.g. 401/403) — not retried
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
     * Create the table. Called on plugin activation (dbDelta = idempotent).
     */
    public static function install()
    {
        global $wpdb;

        $table = self::table();
        $charset_collate = $wpdb->get_charset_collate();

        // event_name + event_id are denormalized columns purely so the Activity
        // panel can render without unserializing every payload.
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
     * Record the result of a send attempt.
     *
     * On success: a 'done' row (observability only).
     * On a retryable failure: a 'pending' row with a backed-off next_attempt.
     * On a non-retryable failure (401/403): a 'failed' row, never retried.
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
            self::trim();

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
            self::trim();

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
        self::trim();
    }

    /**
     * Replay due pending rows. Invoked by the dfss_retry cron.
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
            return; // not connected — leave the queue intact
        }

        $table = self::table();
        $now = time();

        // Bounded batch so a long backlog can't exhaust the cron's time budget.
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom plugin table ({$wpdb->prefix}dfss_queue, name built from $wpdb->prefix only); values are passed through $wpdb->prepare(); a live retry queue must not be served from cache.
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = %s AND next_attempt <= %d ORDER BY id ASC LIMIT 20", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                self::STATUS_PENDING,
                $now
            )
        );
        if (empty($rows)) {
            return;
        }

        $client = new DFSS_Client($tenant_id, $hmac_secret, $endpoint);

        foreach ($rows as $row) {
            $payload = json_decode((string) $row->payload, true);
            if (!is_array($payload)) {
                // Corrupt row — drop it so it can't loop forever.
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

            // Still retryable — back off further.
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

    // --- internals -----------------------------------------------------------

    /**
     * @param array  $payload
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
        $encoded = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return; // cannot persist — skip silently (caller already attempted send)
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
     * @param int    $id
     * @param string $status
     * @param int    $attempts
     * @param int    $next_attempt
     * @param int    $code
     * @param string $error
     */
    private static function update_status($id, $status, $attempts, $next_attempt, $code, $error)
    {
        global $wpdb;

        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- state write to the plugin's own retry-queue table; caching does not apply.
            self::table(),
            array(
                'status' => $status,
                'attempts' => (int) $attempts,
                'next_attempt' => (int) $next_attempt,
                'last_code' => (int) $code,
                'last_error' => substr((string) $error, 0, 255),
                'updated_at' => time(),
            ),
            array('id' => (int) $id),
            array('%s', '%d', '%d', '%d', '%s', '%d'),
            array('%d')
        );
    }

    /**
     * Keep only the most recent KEEP_ROWS rows (circular log behaviour).
     * Pending rows are always preserved so a trim can't drop a not-yet-retried
     * conversion.
     */
    private static function trim()
    {
        global $wpdb;

        $table = self::table();

        // The id below which non-pending rows may be pruned: the KEEP_ROWS-th
        // newest id overall.
        $cutoff = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom plugin table ({$wpdb->prefix}dfss_queue, name built from $wpdb->prefix only); values are passed through $wpdb->prepare(); a live retry queue must not be served from cache.
            $wpdb->prepare(
                "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                self::KEEP_ROWS - 1
            )
        );
        if ($cutoff === null) {
            return; // fewer than KEEP_ROWS rows — nothing to prune
        }

        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom plugin table ({$wpdb->prefix}dfss_queue, name built from $wpdb->prefix only); values are passed through $wpdb->prepare(); a live retry queue must not be served from cache.
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE id < %d AND status != %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                (int) $cutoff,
                self::STATUS_PENDING
            )
        );
    }

    /**
     * Exponential backoff in seconds for a given attempt number (1-based),
     * capped at 1 hour. 1->5min, 2->10min, 3->20min, 4->40min, 5+->60min.
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
     * Short, PII-free error string for storage. We only keep the HTTP code and
     * a clipped dispatcher message (which is an error key like
     * "invalid_signature", never customer data).
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
