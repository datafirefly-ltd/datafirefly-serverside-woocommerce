<?php
/**
 * The pre-2.27.0 copy (folder "datafirefly-serverside"): its uninstall would delete the shared
 * settings, so they and the pending retries are set aside before it runs and restored after.
 *
 * @package DataFirefly_ServerSide
 */

if (!defined('ABSPATH')) {
    exit;
}

class DFSS_Legacy
{
    /**
     * Plugin basename of the pre-2.27.0 copy.
     */
    const BASENAME = 'datafirefly-serverside/datafirefly-serverside.php';

    /**
     * Options the older copy's uninstall.php deletes.
     */
    const OPTIONS = array('dfss_settings', 'dfss_public_config', 'dfss_version', 'dfss_truth_last_date');

    /**
     * @var array|null what was set aside before the older copy's uninstall
     */
    private static $saved = null;

    public static function register()
    {
        add_action('pre_uninstall_plugin', array(__CLASS__, 'before_legacy_uninstall'), 10, 1);
        add_action('delete_plugin', array(__CLASS__, 'after_legacy_uninstall'), 10, 1);
        add_action('admin_notices', array(__CLASS__, 'notice'));
    }

    public static function is_present()
    {
        return file_exists(WP_PLUGIN_DIR . '/' . self::BASENAME);
    }

    /**
     * WordPress fires this just before it includes a plugin's uninstall.php.
     *
     * @param string $plugin plugin basename
     */
    public static function before_legacy_uninstall($plugin)
    {
        if ($plugin !== self::BASENAME) {
            return;
        }

        global $wpdb;
        $options = array();
        foreach (self::OPTIONS as $name) {
            $value = get_option($name, null);
            if ($value !== null) {
                $options[$name] = $value;
            }
        }

        $table = DFSS_Queue::table();
        $rows = $wpdb->get_results("SELECT * FROM {$table}", ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- plugin's own table, name built from $wpdb->prefix only; read once, just before another copy drops it.

        self::$saved = array(
            'options' => $options,
            'rows' => is_array($rows) ? $rows : array(),
        );
    }

    /**
     * WordPress fires this after the uninstall.php, before deleting the files.
     *
     * @param string $plugin plugin basename
     */
    public static function after_legacy_uninstall($plugin)
    {
        if ($plugin !== self::BASENAME || self::$saved === null) {
            return;
        }

        global $wpdb;
        foreach (self::$saved['options'] as $name => $value) {
            update_option($name, $value);
        }

        DFSS_Queue::install();
        $table = DFSS_Queue::table();
        foreach (self::$saved['rows'] as $row) {
            $wpdb->insert($table, $row); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- putting back the plugin's own retry rows.
        }

        DFSS_Plugin::ensure_cron();
        self::$saved = null;
    }

    public static function notice()
    {
        if (!current_user_can('activate_plugins') || !self::is_present()) {
            return;
        }
        echo '<div class="notice notice-info"><p>'
            . esc_html__('An older copy of DataFirefly Server-Side (folder "datafirefly-serverside") is still installed. It has been deactivated and this version has taken over with the same settings. You can delete the older copy from the Plugins screen: your settings are kept.', 'datafirefly-server-side')
            . '</p></div>';
    }
}
