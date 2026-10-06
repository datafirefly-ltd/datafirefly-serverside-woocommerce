<?php
/**
 * Uninstall: removes settings, cron hooks, rate-limit transients and the retry table. Order meta
 * (_dfss_*) stays: it belongs to the merchant's order history.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Options (see DFSS_Plugin::OPTION / PUBLIC_OPTION, maybe_upgrade(), DFSS_Truth::LAST_SENT_OPTION).
foreach (array('dfss_settings', 'dfss_public_config', 'dfss_version', 'dfss_truth_last_date') as $dfss_option) {
    delete_option($dfss_option);
}

// Cron hooks (DFSS_Queue::CRON_HOOK, DFSS_Truth::CRON_HOOK).
wp_clear_scheduled_hook('dfss_retry');
wp_clear_scheduled_hook('dfss_daily_truth');

// Rate-limit buckets (DFSS_REST::rate_limit_ok()).
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup of the plugin's own transients; caching does not apply.
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like('_transient_dfss_rl_') . '%',
        $wpdb->esc_like('_transient_timeout_dfss_rl_') . '%'
    )
);

// Retry queue table (DFSS_Queue::table()).
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}dfss_queue"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom plugin table, name built from $wpdb->prefix only; dropping it is the point of uninstall.
