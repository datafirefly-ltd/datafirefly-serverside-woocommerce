<?php
/**
 * The consent-related settings of the admin forms.
 *
 * Pure (no WordPress call) so it can be tested on its own:
 * tests/test-settings-save.php.
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
     * Apply the five consent fields of a submitted form: click-ID passthrough,
     * hold, Google consent mode, default region, ads data redaction.
     *
     * Only a form that SHOWS them may change them. Such a form carries the
     * hidden field dfss_has_consent_fields; any other form leaves the five
     * saved values untouched. Without this rule an absent checkbox reads as
     * "off", and the connected screen's form, which did not show these
     * fields, switched the passthrough off and reset the hold and the Google
     * mode on every save (review of 29/09/2026).
     *
     * Every value is compared to a closed list or clamped: nothing free-form
     * reaches the options.
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
        // Borne a 24 h : au-dela, un evenement retenu n'a plus de rapport
        // avec la visite qui l'a produit.
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
