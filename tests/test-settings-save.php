<?php
/**
 * A settings form may only change the settings it shows.
 *
 * Found by review on 29/09/2026: the connected screen's form carried neither
 * the click-ID passthrough, nor the hold, nor the Google consent mode fields,
 * but its save handler wrote all of them from $_POST. An absent checkbox reads
 * as "off", so every "Save settings" on a connected shop silently switched the
 * passthrough off, reset the hold to zero and put Google consent mode back to
 * basic. The merchant saw a saved form and a changed shop.
 *
 * Run: php tests/test-settings-save.php
 */
require __DIR__ . '/../includes/class-dfss-settings.php';

$ok = 0;
$fail = 0;
function t($name, $cond)
{
    global $ok, $fail;
    if ($cond) {
        $ok++;
        echo "  ok  $name\n";
    } else {
        $fail++;
        echo "  FAIL $name\n";
    }
}

$saved = array(
    'clickid_passthrough' => 1,
    'consent_hold_minutes' => 30,
    'google_consent_mode' => 'advanced',
    'consent_default_region' => 'eea',
    'ads_data_redaction' => 0,
    'complete_tracking' => 1,
);

// A form that does not show the consent fields leaves them alone.
$o = DFSS_Settings::apply_consent_fields($saved, array('dfss_complete_tracking' => '1'));
t('form without the fields: passthrough untouched', $o['clickid_passthrough'] === 1);
t('form without the fields: hold untouched', $o['consent_hold_minutes'] === 30);
t('form without the fields: Google mode untouched', $o['google_consent_mode'] === 'advanced');
t('form without the fields: region untouched', $o['consent_default_region'] === 'eea');
t('form without the fields: redaction untouched', $o['ads_data_redaction'] === 0);
t('form without the fields: other keys untouched', $o['complete_tracking'] === 1);

// A form that shows them applies them, an unticked checkbox meaning off.
$o = DFSS_Settings::apply_consent_fields($saved, array(
    'dfss_has_consent_fields' => '1',
    'dfss_consent_hold_minutes' => '5000',
    'dfss_google_consent_mode' => 'basic',
    'dfss_consent_default_region' => 'all',
    'dfss_ads_data_redaction' => '1',
));
t('form with the fields: unticked passthrough is off', $o['clickid_passthrough'] === 0);
t('form with the fields: hold capped at 24 h', $o['consent_hold_minutes'] === 1440);
t('form with the fields: Google mode applied', $o['google_consent_mode'] === 'basic');
t('form with the fields: region applied', $o['consent_default_region'] === 'all');
t('form with the fields: redaction applied', $o['ads_data_redaction'] === 1);

// Closed lists: anything else falls back to the safe value.
$o = DFSS_Settings::apply_consent_fields($saved, array(
    'dfss_has_consent_fields' => '1',
    'dfss_google_consent_mode' => '<script>',
    'dfss_consent_default_region' => array('eea'),
    'dfss_consent_hold_minutes' => '-3',
));
t('unknown Google mode falls back to basic', $o['google_consent_mode'] === 'basic');
t('non-string region falls back to all', $o['consent_default_region'] === 'all');
t('negative hold falls back to 0', $o['consent_hold_minutes'] === 0);

echo "\n$ok passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
