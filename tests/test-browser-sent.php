<?php
/**
 * `browser_sent` is read from the page, so it can hold anything. Only the string "ga4" may pass, and
 * a nested array must not raise a PHP warning (strval() on an array does).
 *
 * Run: php tests/test-browser-sent.php
 */
define('ABSPATH', __DIR__ . '/');
require __DIR__ . '/../includes/class-dfss-rest.php';
$ok = 0;
$fail = 0;
function t($name, $cond)
{
    global $ok, $fail;
    if ($cond) { $ok++; echo "  ok  $name\n"; } else { $fail++; echo "  FAIL $name\n"; }
}

$warnings = 0;
set_error_handler(function () use (&$warnings) { $warnings++; return true; });

$m = new ReflectionMethod('DFSS_REST', 'sanitize_browser_sent');
$m->setAccessible(true);
$rest = (new ReflectionClass('DFSS_REST'))->newInstanceWithoutConstructor();
$call = function (array $body) use ($m, $rest) { return $m->invoke($rest, $body); };

t('ga4 passes', $call(array('browser_sent' => array('ga4'))) === array('ga4'));
t('another platform name is dropped', $call(array('browser_sent' => array('ga4', 'meta'))) === array('ga4'));
t('nothing sent gives an empty list', $call(array()) === array());
t('a non-array value gives an empty list', $call(array('browser_sent' => 'ga4')) === array());
t('duplicates collapse', $call(array('browser_sent' => array('ga4', 'ga4'))) === array('ga4'));
$before = $warnings;
$forged = $call(array('browser_sent' => array(array('ga4'), 'ga4', 42, null, new stdClass())));
t('a forged payload still yields ga4 only', $forged === array('ga4'));
t('and raises no PHP warning', $warnings === $before);

echo "\n$ok passed, $fail failed\n";
exit($fail ? 1 : 0);
