<?php
/**
 * Settings of the admin forms: consent fields, browser destinations and optional modules.
 *
 * Pure (no WordPress call) so it can be tested on its own: tests/test-settings-save.php.
 *
 * @package DataFirefly_ServerSide
 */

// The standalone test defines ABSPATH itself before loading this file.
if (!defined('ABSPATH')) {
    exit;
}

class DFSS_Settings
{
    /**
     * Browser destinations: option => [public config key, id field, label, script module].
     *
     * A module name means the tag ships in its own file (assets/dfss-dest-<module>.js), enqueued
     * only when the destination is enabled and configured. Google tags have no module: the shared
     * Consent Mode block calls them directly, so they stay in the core tracker (still never loaded
     * in the browser when switched off).
     */
    const DESTINATIONS = array(
        'dest_meta' => array('meta', 'pixelId', 'Meta Pixel', 'meta'),
        'dest_ga4' => array('ga4', 'measurementId', 'Google Analytics 4', ''),
        'dest_google_ads' => array('google', 'conversionId', 'Google Ads', ''),
        'dest_tiktok' => array('tiktok', 'pixelCode', 'TikTok Pixel', 'tiktok'),
        'dest_openai' => array('openai', 'pixelId', 'OpenAI (ChatGPT Ads)', 'openai'),
    );

    /** Optional tracker modules: option => script (assets/dfss-<module>.js). */
    const MODULES = array(
        'mod_engagement' => 'engagement',
    );

    /**
     * Every destination and module enabled: the behaviour of a fresh install or an upgrade.
     *
     * @return array<string,int>
     */
    public static function defaults()
    {
        return array_fill_keys(array_merge(array_keys(self::DESTINATIONS), array_keys(self::MODULES)), 1);
    }

    /**
     * Apply the destination and module checkboxes of a submitted form.
     *
     * Only a form carrying the hidden field dfss_has_destination_fields may change them.
     *
     * @param array $o    Saved options.
     * @param array $post The submitted fields ($_POST).
     *
     * @return array
     */
    public static function apply_destination_fields(array $o, array $post)
    {
        if (empty($post['dfss_has_destination_fields'])) {
            return $o;
        }
        foreach (array_keys(self::defaults()) as $key) {
            $o[$key] = empty($post['dfss_' . $key]) ? 0 : 1;
        }

        return $o;
    }

    /**
     * Remove the switched-off destinations from the public config. A missing key counts as enabled.
     *
     * @param array $public Public destination ids from the dispatcher.
     * @param array $opts   Plugin options.
     *
     * @return array
     */
    public static function filter_public(array $public, array $opts)
    {
        foreach (self::DESTINATIONS as $key => $dest) {
            if (array_key_exists($key, $opts) && empty($opts[$key])) {
                unset($public[$dest[0]]);
            }
        }

        return $public;
    }

    /**
     * Whether a destination is configured on the DataFirefly account.
     *
     * @param array  $public Public destination ids.
     * @param string $key    Option key (dest_*).
     *
     * @return bool
     */
    public static function is_configured(array $public, $key)
    {
        if (!array_key_exists($key, self::DESTINATIONS)) {
            return false;
        }
        list($pub_key, $id_field) = self::DESTINATIONS[$key];

        return !empty($public[$pub_key][$id_field]);
    }

    /**
     * Apply the five consent fields of a submitted form: click-ID passthrough, hold, Google consent
     * mode, default region, ads data redaction.
     *
     * Only a form carrying the hidden field dfss_has_consent_fields may change them: an absent
     * checkbox would otherwise read as "off" on a form that does not show it.
     *
     * @param array $o    Saved options.
     * @param array $post The submitted fields ($_POST).
     *
     * @return array
     */
    public static function apply_consent_fields(array $o, array $post)
    {
        if (empty($post['dfss_has_consent_fields'])) {
            return $o;
        }

        $o['clickid_passthrough'] = isset($post['dfss_clickid_passthrough']) ? 1 : 0;
        // Capped at 24 h: past that, a held event no longer relates to the visit.
        $hold = isset($post['dfss_consent_hold_minutes']) && is_scalar($post['dfss_consent_hold_minutes'])
            ? (int) $post['dfss_consent_hold_minutes']
            : 0;
        $o['consent_hold_minutes'] = max(0, min(1440, $hold));
        $o['google_consent_mode'] = (isset($post['dfss_google_consent_mode']) && $post['dfss_google_consent_mode'] === 'advanced')
            ? 'advanced'
            : 'basic';
        $o['consent_default_region'] = (isset($post['dfss_consent_default_region']) && $post['dfss_consent_default_region'] === 'eea')
            ? 'eea'
            : 'all';
        $o['ads_data_redaction'] = isset($post['dfss_ads_data_redaction']) ? 1 : 0;

        return $o;
    }
}
