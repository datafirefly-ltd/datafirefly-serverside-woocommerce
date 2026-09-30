<?php
/**
 * The copy of this plugin distributed before 2.27.0.
 *
 * Until 2.26.x the DataFirefly client space shipped this plugin in a folder
 * named "datafirefly-serverside". Its wordpress.org identifier, which cannot
 * change, is "datafirefly-server-side", and WordPress treats two folders as
 * two plugins: installing 2.27.0 on such a shop puts a second copy next to
 * the first instead of updating it.
 *
 * Both copies store their settings under the same option names, so nothing
 * has to be migrated. Two things do need care:
 *
 * - the older copy's uninstall.php deletes those shared settings (tenant id
 *   and HMAC secret included), the retry table and the cron hooks. Deleting
 *   the older copy from the Plugins screen, which is what the notice below
 *   invites, would leave this copy unconfigured. The settings and the pending
 *   retries are therefore set aside just before that uninstall runs and put
 *   back just after;
 * - the merchant has to be told the older copy is still there.
 *
 * Switching the older copy off happens in the main file, before any class is
 * declared: see the guard at the top of datafirefly-server-side.php.
 *
 * @package DataFirefly_ServerSide
 */

if (!defined('ABSPATH')) {
    exit;
}

class DFSS_Legacy
{
    /** Plugin basename of the pre-2.27.0 copy. */
    const BASENAME = 'datafirefly-serverside/datafirefly-serverside.php';

    /** Options the older copy's uninstall.php deletes. */
    const OPTIONS = array('dfss_settings', 'dfss_public_config', 'dfss_version', 'dfss_truth_last_date');

    /** @var array|null what was set aside before the older copy's uninstall */
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
