<?php
/**
 * A team member signed in to the shop is not measured.
 *
 * Found on 05/11/2026 on a shop where the team's own visits made at least 13 % of the events of
 * 30 days, searches, add-to-carts and checkout starts included, and they reached GA4, Meta and
 * Google Ads like a customer's. The dispatcher can drop an office IP address, but a home
 * connection whose address changes every time slips through it without a word. A signed-in
 * account does not: the shop knows who it is.
 *
 * Run: php tests/test-staff-exclusion.php
 */
define('ABSPATH', __DIR__ . '/');
require __DIR__ . '/../includes/class-dfss-settings.php';

$ok = 0;
$fail = 0;
function t($name, $cond)
{
    global $ok, $fail;
    if ($cond) { $ok++; echo "  ok  $name\n"; } else { $fail++; echo "  FAIL $name\n"; }
}

$on = DFSS_Settings::defaults();
$off = array_merge($on, array('exclude_staff' => 0));

// On by default: a fresh install and an upgrade stop measuring their own team.
t('on by default', isset($on['exclude_staff']) && $on['exclude_staff'] === 1);

t('administrator: not measured', DFSS_Settings::is_excluded_staff(array('administrator'), $on));
t('shop manager: not measured', DFSS_Settings::is_excluded_staff(array('shop_manager'), $on));
t('several roles, one of them staff: not measured', DFSS_Settings::is_excluded_staff(array('customer', 'shop_manager'), $on));
t('customer: measured', !DFSS_Settings::is_excluded_staff(array('customer'), $on));
t('subscriber: measured', !DFSS_Settings::is_excluded_staff(array('subscriber'), $on));
t('visitor with no account: measured', !DFSS_Settings::is_excluded_staff(array(), $on));
t('switched off: an administrator is measured again', !DFSS_Settings::is_excluded_staff(array('administrator'), $off));
t('missing key reads as on (an install saved before 2.32.0)', DFSS_Settings::is_excluded_staff(array('administrator'), array()));
t('a role name is not matched by its prefix', !DFSS_Settings::is_excluded_staff(array('administrator_of_nothing'), $on));
t('roles not given as strings are ignored', !DFSS_Settings::is_excluded_staff(array(null, 1, array()), $on));

// The checkbox lives in the destination form: ticked, unticked, and left alone by another form.
$saved = array_merge($on, array('exclude_staff' => 1));
$unticked = DFSS_Settings::apply_destination_fields($saved, array('dfss_has_destination_fields' => '1'));
t('form with the field, unticked: off', $unticked['exclude_staff'] === 0);
$ticked = DFSS_Settings::apply_destination_fields($off, array('dfss_has_destination_fields' => '1', 'dfss_exclude_staff' => '1'));
t('form with the field, ticked: on', $ticked['exclude_staff'] === 1);
$other = DFSS_Settings::apply_destination_fields($saved, array());
t('a form without the field leaves it alone', $other['exclude_staff'] === 1);

// The setting is not a browser module: no script is enqueued for it.
t('not a tracker module', !isset(DFSS_Settings::MODULES['exclude_staff']));

echo "\n$ok ok, $fail failed\n";
exit($fail === 0 ? 0 : 1);
