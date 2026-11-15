<?php
/**
 * The storefront's own GA4 client id is the fallback on the purchase sent from the server (2.34.0).
 *
 * The tracker makes that id when the browser did not load Google's tag (no _ga), and keeps it in
 * the _dfss_cid cookie (tests/test-client-id.mjs). The purchase must carry the same id as the page
 * views, or GA4 counts the buyer as somebody else. Google's _ga first, ours second, nothing
 * invented: the server only READS the cookie at checkout, it never makes one.
 *
 * Run: php tests/test-client-id.php
 */
define('ABSPATH', __DIR__ . '/');
require __DIR__ . '/../includes/class-dfss-event-builder.php';
$ok = 0;
$fail = 0;
function t($name, $cond, $detail = null)
{
    global $ok, $fail;
    if ($cond) { $ok++; echo "  ok  $name\n"; } else { $fail++; echo "  FAIL $name" . ($detail === null ? '' : ' ' . json_encode($detail)) . "\n"; }
}

echo "\nown_client_id()\n";
t('an id the tracker made', DFSS_Event_Builder::own_client_id('2718281828.1763197200') === '2718281828.1763197200');
t('the largest draw', DFSS_Event_Builder::own_client_id('4294967295.1763197200') === '4294967295.1763197200');
foreach (array('', null, array('x'), 42, 'garbage', 'GA1.1.1.1763197200', '0123.1763197200', '99999999999.1763197200', '4294967296.1763197200', '1.2', "1.1763197200\n") as $forged) {
    t('not ours: ' . json_encode($forged), DFSS_Event_Builder::own_client_id($forged) === '');
}

echo "\norder_client_id() — what the purchase carries\n";
t("Google's id when the order kept a _ga", DFSS_Event_Builder::order_client_id('GA1.2.111111111.1620000000', '') === '111111111.1620000000');
t("the storefront's own id when Google wrote no _ga", DFSS_Event_Builder::order_client_id('', '2718281828.1763197200') === '2718281828.1763197200');
t('ours is kept when both exist, as the tracker does: one shopper, one id', DFSS_Event_Builder::order_client_id('GA1.2.111111111.1620000000', '2718281828.1763197200') === '2718281828.1763197200');
t("Google's when the meta under our name is not ours", DFSS_Event_Builder::order_client_id('GA1.2.111111111.1620000000', 'garbage') === '111111111.1620000000');
t('a malformed _ga falls back to ours', DFSS_Event_Builder::order_client_id('GA1.2.short', '2718281828.1763197200') === '2718281828.1763197200');
t('a cookie that is not ours: nothing invented', DFSS_Event_Builder::order_client_id('', 'GA1.1.1.1763197200') === '');
t('nothing kept on the order: nothing', DFSS_Event_Builder::order_client_id('', '') === '' && DFSS_Event_Builder::order_client_id(null, null) === '');

echo "\nwiring\n";
$builder = file_get_contents(__DIR__ . '/../includes/class-dfss-event-builder.php');
$plugin = file_get_contents(__DIR__ . '/../datafirefly-server-side.php');
t('the purchase reads both order metas through order_client_id()', strpos($builder, "self::order_client_id(\$order->get_meta('_dfss_ga'), \$order->get_meta('_dfss_cid'))") !== false);
t('checkout keeps the cookie on the order only when it is one of ours', preg_match('/own_client_id\(\s*sanitize_text_field\(\s*wp_unslash\(\s*\$_COOKIE\[\'_dfss_cid\'\]\s*\)\s*\)\s*\)/', $plugin) === 1 && strpos($plugin, "update_meta_data('_dfss_cid'") !== false);

echo "\n$ok passed, $fail failed\n";
exit($fail ? 1 : 0);
