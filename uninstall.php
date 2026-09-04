<?php
/**
 * DataFirefly Server-Side (WooCommerce): uninstall.
 *
 * Runs when the plugin is DELETED from the admin (not on deactivation), and
 * only then: WordPress defines WP_UNINSTALL_PLUGIN before including this
 * file, and nothing else does. Until the audit of 2026-09-04 (M3) there was
 * no uninstall at all, so a removed plugin left its settings (tenant id and
 * HMAC secret included), its cached destination ids, its cron hooks and a
 * retry table holding customer payloads in the shop database for good.
 *
 * Standalone by design: the plugin's classes are not loaded here, so every
 * name is spelled out rather than read from a constant.
 *
 * Order meta (_dfss_*) is left in place: it belongs to the merchant's order
 * records, and deleting it would rewrite their history.
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

// Rate-limit buckets (DFSS_REST::rate_limit_ok()). They expire on their own
// within two minutes, but an uninstall should not have to wait for that.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup of the plugin's own transients; caching does not apply.
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like('_transient_dfss_rl_') . '%',
        $wpdb->esc_like('_transient_timeout_dfss_rl_') . '%'
    )
);

// Retry queue table (DFSS_Queue::table()).
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}dfss_queue"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom plugin table, name built from $wpdb->prefix only; dropping it is the point of uninstall.
