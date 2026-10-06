<?php
/**
 * The consent verdict the confirmation page hands to the tracker must agree with the one the server
 * used to send the purchase, or GA4 counts the order twice (server AND browser).
 *
 * The server asks "is consent required?" FIRST (purchase_consent()). The page used to read the verdict
 * stored on the order directly: an old order stored as 'denied', on a shop that has since switched
 * consent gating OFF, was sent by the server (not required) and again by the browser (denied).
 *
 * Run: php tests/test-page-verdict.php
 */
define('ABSPATH', __DIR__ . '/');
require __DIR__ . '/../includes/class-dfss-consent.php';

$ok = 0;
$fail = 0;
function t($name, $cond)
{
    global $ok, $fail;
    if ($cond) { $ok++; echo "  ok  $name\n"; } else { $fail++; echo "  FAIL $name\n"; }
}

$required = array('require_consent' => 1);
$off = array('require_consent' => 0);
$fresh = array(); // a fresh install: absence of the key means "required"

t('required, stored granted: granted', DFSS_Consent::page_purchase_verdict($required, 'granted') === 'granted');
t('required, stored denied: denied (the browser sends GA4, the server does not)', DFSS_Consent::page_purchase_verdict($required, 'denied') === 'denied');
t('required, nothing stored: empty (the purchase is left to the server, never counted twice)', DFSS_Consent::page_purchase_verdict($required, '') === '');
t('required, junk stored: empty', DFSS_Consent::page_purchase_verdict($required, 'maybe') === '');
t('fresh install counts as required', DFSS_Consent::page_purchase_verdict($fresh, 'denied') === 'denied');
t('gating OFF: not_required, even when the order says denied', DFSS_Consent::page_purchase_verdict($off, 'denied') === 'not_required');
t('gating OFF: not_required when nothing is stored', DFSS_Consent::page_purchase_verdict($off, '') === 'not_required');
t('a non-string stored value is ignored', DFSS_Consent::page_purchase_verdict($required, null) === '');

echo "\n$ok passed, $fail failed\n";
exit($fail ? 1 : 0);
