<?php
/**
 * The bundled translations stay complete and consistent.
 *
 * - every string the plugin translates is in the .pot, so a new string cannot ship without being listed;
 * - every .po has every .pot string translated (the plugin name, its author and its URL stay as they are);
 * - a translation keeps the placeholders of its source (%s, %d, %1$d, %2$s): a lost one breaks a message;
 * - every .po has its compiled .mo, with as many entries as translated strings.
 *
 * Run: php tests/test-translations.php
 */
$root = dirname(__DIR__);
$ok = 0;
$fail = 0;
function t($name, $cond)
{
    global $ok, $fail;
    if ($cond) { $ok++; } else { $fail++; echo "  FAIL $name\n"; }
}

/** @return array<string,string> msgid => msgstr (header excluded) */
function parse_po($file)
{
    $out = array();
    $id = null;
    $str = null;
    $mode = '';
    foreach (file($file) as $line) {
        $line = rtrim($line, "\r\n");
        if (strpos($line, 'msgid "') === 0) {
            if ($id !== null && $id !== '') { $out[$id] = $str; }
            $id = stripcslashes(substr($line, 7, -1));
            $str = '';
            $mode = 'id';
        } elseif (strpos($line, 'msgstr "') === 0) {
            $str = stripcslashes(substr($line, 8, -1));
            $mode = 'str';
        } elseif ($line !== '' && $line[0] === '"') {
            $piece = stripcslashes(substr($line, 1, -1));
            if ($mode === 'id') { $id .= $piece; } else { $str .= $piece; }
        } elseif ($line === '' || $line[0] === '#') {
            if ($id !== null && $id !== '') { $out[$id] = $str; }
            $id = null;
            $mode = '';
        }
    }
    if ($id !== null && $id !== '') { $out[$id] = $str; }

    return $out;
}

function placeholders($s)
{
    preg_match_all('/%(?:\d+\$)?[sdf]/', $s, $m);
    sort($m[0]);

    return $m[0];
}

$pot = parse_po($root . '/languages/datafirefly-server-side.pot');
t('the .pot lists strings', count($pot) > 50);

// 1. Every translated literal in the code is in the .pot.
$missing = array();
$files = array($root . '/datafirefly-server-side.php');
foreach (glob($root . '/includes/*.php') as $f) { $files[] = $f; }
foreach ($files as $f) {
    $code = file_get_contents($f);
    if (preg_match_all('/\b(?:__|esc_html__|esc_attr__|_e|esc_html_e|esc_attr_e)\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*,\s*\'datafirefly-server-side\'/s', $code, $m)) {
        foreach ($m[1] as $lit) {
            $lit = stripslashes($lit);
            if (!isset($pot[$lit])) { $missing[] = substr($lit, 0, 60); }
        }
    }
}
t('every translated string in the code is in the .pot' . ($missing ? ' (missing: ' . implode(' | ', $missing) . ')' : ''), $missing === array());

// 2. Each language is complete, consistent and compiled.
$untranslatable = array('DataFirefly Server-Side', 'DataFirefly Ltd', 'https://datafirefly.com');
$langs = glob($root . '/languages/datafirefly-server-side-*.po');
t('eight languages ship', count($langs) === 8);
foreach ($langs as $po) {
    $loc = basename($po, '.po');
    $tr = parse_po($po);
    $translated = 0;
    foreach ($pot as $id => $_) {
        if (in_array($id, $untranslatable, true)) { continue; }
        t("$loc has: " . substr($id, 0, 40), isset($tr[$id]) && $tr[$id] !== '');
        if (isset($tr[$id]) && $tr[$id] !== '') {
            $translated++;
            t("$loc keeps the placeholders of: " . substr($id, 0, 40), placeholders($id) === placeholders($tr[$id]));
            t("$loc differs from the English source: " . substr($id, 0, 40), $tr[$id] !== $id || strlen($id) < 14);
        }
    }
    $mo = preg_replace('/\.po$/', '.mo', $po);
    t("$loc has a compiled .mo", is_file($mo));
    if (is_file($mo)) {
        $h = unpack('Vmagic/Vrev/Vcount', substr(file_get_contents($mo), 0, 12));
        t("$loc .mo is a gettext file", $h['magic'] === 0x950412de);
        // The .mo holds the header entry plus every translated string.
        t("$loc .mo has every translated string", $h['count'] === $translated + 1);
    }
}

echo "\n$ok passed, $fail failed\n";
exit($fail ? 1 : 0);
