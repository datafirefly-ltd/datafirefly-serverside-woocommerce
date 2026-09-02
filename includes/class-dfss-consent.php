<?php
/**
 * DataFirefly Server-Side (WooCommerce) — consent gate (GDPR).
 *
 * Single source of truth for "may we fire tracking right now?". Both layers are
 * gated:
 *   - Client (assets/dfss-tracker.js): the tracker is told whether consent is
 *     required and which signals to watch; it injects nothing and beacons
 *     nothing until marketing consent is granted. This is the PRIMARY gate
 *     because consent state is a browser concern (banners, TCF, live changes).
 *   - Server (this class, used by class-dfss-rest.php): a defense-in-depth check
 *     on the incoming beacon. When the WP Consent API is active its state is
 *     readable server-side (cookie-backed), so we honour an explicit DENY. When
 *     no machine-readable signal exists server-side we fall back to the admin
 *     setting (see has_consent()).
 *
 * Detection covers the common stacks: the official WP Consent API
 * (function_exists('wp_has_consent')), Complianz, Cookiebot, and IAB TCF v2
 * (__tcfapi) — the last three are surfaced to the JS layer, which can read them
 * in real time.
 *
 * Nothing here ever throws: a consent hiccup must never break a page render.
 */
if (!defined('ABSPATH')) {
    exit;
}

class DFSS_Consent
{
    /**
     * Is consent gating switched on for this shop?
     *
     * @param array $opts Plugin options.
     *
     * @return bool
     */
    public static function is_required($opts)
    {
        // ON by default: absence of the key (fresh install) means "require".
        return !isset($opts['require_consent']) || !empty($opts['require_consent']);
    }

    /**
     * Server-side consent decision for an incoming beacon.
     *
     * Logic (fail-safe, privacy-first where we have a signal):
     *   1. Gating off  -> always allowed.
     *   2. WP Consent API present -> honour wp_has_consent('marketing') as the
     *      authoritative answer (it is cookie-backed, readable server-side).
     *   3. No server-readable signal -> trust the client gate (which already
     *      refused to beacon without consent) and allow. The browser is the
     *      authority for banner/TCF state, and a beacon only exists because the
     *      tracker decided consent was granted.
     *
     * @param array $opts Plugin options.
     *
     * @return bool True if the event may be forwarded.
     */
    public static function has_consent($opts)
    {
        if (!self::is_required($opts)) {
            return true;
        }

        // DataFirefly Cookie Consent (our own banner) — read its cookie
        // server-side (fixed name "dfcc_consent", base64 JSON). Authoritative
        // when present: drop the event unless marketing consent is granted.
        $dfcc = self::dfcc_marketing_consent();
        if ($dfcc !== null) {
            return $dfcc;
        }

        if (function_exists('wp_has_consent')) {
            // The official API. If marketing consent is explicitly denied we
            // drop the event regardless of what the browser claimed.
            return (bool) wp_has_consent('marketing');
        }

        // Every other consent tool, read from its own cookie. Purely additive:
        // this only speaks where the code above said nothing, and where it used
        // to fall through to "allow" on the assumption that the browser gate had
        // already decided. That assumption does not hold for the events the
        // browser never sees — an order created in the back office, a gateway
        // callback, a webhook replay — and those were being forwarded whatever
        // the shopper had answered.
        $cmp = self::dfssCmpFromCookies($_COOKIE);
        if ($cmp !== null) {
            return $cmp;
        }

        // No machine-readable server signal: the client gate is authoritative.
        // The tracker does not beacon unless consent was granted, so allow.
        return true;
    }

    /**
     * Marketing-consent decision from the DataFirefly Cookie Consent cookie,
     * read server-side. The cookie ("dfcc_consent") is base64(JSON) carrying a
     * `categories` map. Returns true/false, or null when the cookie is absent
     * or unreadable (so the caller falls back to other signals).
     *
     * @return bool|null
     */
    private static function dfcc_marketing_consent()
    {
        if (empty($_COOKIE['dfcc_consent'])) {
            return null;
        }
        // sanitize_text_field cannot alter a valid base64 string; strict
        // base64_decode() below then rejects anything that is not clean base64.
        $raw = base64_decode(sanitize_text_field(wp_unslash($_COOKIE['dfcc_consent'])), true);
        if (!$raw) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['categories']) || !is_array($data['categories'])) {
            return null;
        }

        return !empty($data['categories']['marketing']);
    }

    /**
     * Whether the WP Consent API plugin/feature is active.
     *
     * @return bool
     */
    public static function has_wp_consent_api()
    {
        return function_exists('wp_has_consent') && function_exists('wp_set_consent');
    }

    /**
     * Best-effort detection of a known consent banner, for the client config.
     *
     * The JS layer uses this hint to pick which live signal to watch. Detection
     * is heuristic (plugin presence) — the tracker still verifies the actual
     * granted/denied state at runtime.
     *
     * @return string One of: 'wp_consent_api', 'complianz', 'cookiebot',
     *                'tcf', '' (none detected).
     */
    public static function detect_cmp()
    {
        // DataFirefly Cookie Consent (our own banner) — preferred when active.
        if (defined('DFCC_VERSION') || class_exists('DataFirefly\\CookieConsent\\Plugin')) {
            return 'dfcc';
        }
        if (self::has_wp_consent_api()) {
            return 'wp_consent_api';
        }
        // Complianz exposes cmplz_* and the constant below.
        if (defined('cmplz_plugin') || function_exists('cmplz_has_consent')) {
            return 'complianz';
        }
        // Cookiebot plugin.
        if (defined('CYBOT_COOKIEBOT_PLUGIN_VERSION') || class_exists('Cookiebot_WP')) {
            return 'cookiebot';
        }

        // IAB TCF can only be confirmed in the browser (__tcfapi); we can't
        // detect it reliably from PHP, so the tracker probes for it.
        return '';
    }

    /**
     * The config the PHP layer hands to the JS tracker.
     *
     * @param array $opts Plugin options.
     *
     * @return array{required:bool,cmp:string,hasWpConsentApi:bool}
     */
    public static function js_config($opts)
    {
        return array(
            'required' => self::is_required($opts),
            'cmp' => self::detect_cmp(),
            'hasWpConsentApi' => self::has_wp_consent_api(),
        );
    }

    // ---- DFSS-CONSENT-COOKIES:BEGIN (genere — ne pas editer ici) --------
    // ---------------------------------------------------------------------
    // Shared server-side consent detection.
    //
    // The browser tracker and the PHP layer are two different gates on the
    // same shop, and they were reading different things. Unifying only the
    // browser would have made it worse, not better: a PrestaShop shop running
    // Cookiebot would start sending its page views (the browser gate now
    // understands Cookiebot) while its purchases stayed blocked (the PHP gate
    // still only understood tarteaucitron) — the two gates disagreeing about
    // the same visitor, and the most valuable event the one that goes missing.
    //
    // So the PHP layer gets the same treatment: one implementation, generated
    // into all three connectors by the dispatcher's sync-consent-core.py, and
    // a test that fails when the copies drift.
    //
    // What a server CAN read is cookies, so that is what this does. Several
    // consent tools write a plainly readable verdict; those are covered. Two
    // are deliberately NOT:
    //
    //   - Usercentrics keeps its verdict per service under names that change
    //     between versions. Guessing would produce a confident wrong answer,
    //     which is worse here than no answer.
    //   - Klaro and Osano's JS-only setups leave nothing server-readable.
    //
    // For those, this returns null and the caller falls back to its own
    // platform signal — exactly as before. Nothing regresses.
    // ---------------------------------------------------------------------

    /**
     * Marketing consent as the CMP wrote it into a cookie.
     *
     * @param array $cookies Usually $_COOKIE, or the framework's cookie bag.
     *
     * @return bool|null true/false when a tool we understand answered, null
     *                   when none of them is present.
     */
    public static function dfssCmpFromCookies($cookies)
    {
        if (!is_array($cookies) || empty($cookies)) {
            return null;
        }

        $get = function ($name) use ($cookies) {
            return isset($cookies[$name]) && is_scalar($cookies[$name])
                ? (string) $cookies[$name]
                : '';
        };

        // Cookiebot. The value is JSON-ish but not valid JSON (unquoted keys),
        // so it is matched textually rather than decoded.
        $v = $get('CookieConsent');
        if ($v !== '') {
            $v = urldecode($v);
            if (preg_match('/marketing\s*:\s*(true|false)/i', $v, $m)) {
                return strtolower($m[1]) === 'true';
            }
        }

        // Complianz writes one cookie per category.
        $v = $get('cmplz_marketing');
        if ($v !== '') {
            return strtolower(trim($v)) === 'allow';
        }

        // CookieYes.
        $v = $get('cookieyes-consent');
        if ($v !== '') {
            $v = urldecode($v);
            if (preg_match('/advertisement\s*:\s*(yes|no)/i', $v, $m)) {
                return strtolower($m[1]) === 'yes';
            }
        }

        // OneTrust / CookiePro. C0004 is their own id for "Targeting Cookies"
        // and is the default in every template they ship.
        $v = $get('OptanonConsent');
        if ($v !== '') {
            $v = urldecode($v);
            if (strpos($v, 'C0004') !== false) {
                return strpos($v, 'C0004:1') !== false;
            }
        }

        // Osano.
        $v = $get('osano_consentmanager');
        if ($v !== '') {
            $v = urldecode($v);
            if (preg_match('/MARKETING["\']?\s*[:=]\s*["\']?(ACCEPT|DENY)/i', $v, $m)) {
                return strtoupper($m[1]) === 'ACCEPT';
            }
        }

        // Cookiehub.
        $v = $get('cookiehub');
        if ($v !== '') {
            $v = urldecode($v);
            if (preg_match('/marketing["\']?\s*:\s*["\']?(true|1|false|0)/i', $v, $m)) {
                return in_array(strtolower($m[1]), array('true', '1'), true);
            }
        }

        // Borlabs Cookie: real JSON, "consents" keyed by category.
        $v = $get('borlabs-cookie');
        if ($v !== '') {
            $json = json_decode(urldecode($v), true);
            if (is_array($json)) {
                $consents = isset($json['consents']) && is_array($json['consents'])
                    ? $json['consents']
                    : $json;
                if (array_key_exists('marketing', $consents)) {
                    $marketing = $consents['marketing'];
                    return !empty($marketing);
                }
            }
        }

        // Iubenda: one cookie per site id, so the name has to be searched for.
        foreach ($cookies as $name => $raw) {
            if (strpos((string) $name, '_iub_cs-') !== 0 || !is_scalar($raw)) {
                continue;
            }
            $json = json_decode(urldecode((string) $raw), true);
            if (is_array($json) && isset($json['purposes']) && is_array($json['purposes'])) {
                // Purpose 4 is "Targeting & Advertising".
                if (array_key_exists(4, $json['purposes'])) {
                    return !empty($json['purposes'][4]);
                }
                if (array_key_exists('4', $json['purposes'])) {
                    return !empty($json['purposes']['4']);
                }
            }
        }

        // IAB TCF v2, last because decoding it costs the most and every CMP
        // above that also speaks TCF has already answered.
        $v = $get('euconsent-v2');
        if ($v !== '') {
            $tcf = self::dfssCmpTcfPurposes($v);
            if ($tcf !== null) {
                return $tcf;
            }
        }

        return null;
    }

    /**
     * Purposes 3 AND 4 of an IAB TCF v2 consent string.
     *
     * The core string is a fixed bit layout, so the two bits we need sit at
     * known offsets: 6 (version) + 36 (created) + 36 (lastUpdated) + 12 (cmpId)
     * + 12 (cmpVersion) + 6 (consentScreen) + 12 (consentLanguage) + 12
     * (vendorListVersion) + 6 (tcfPolicyVersion) + 1 (isServiceSpecific) + 1
     * (useNonStandardTexts) + 12 (specialFeatureOptIns) = 152, then 24 purpose
     * bits. Purpose N is bit 152 + (N - 1).
     *
     * Returns null on anything that does not decode, never false: a string we
     * failed to read is not a refusal.
     *
     * @param string $tc
     *
     * @return bool|null
     */
    private static function dfssCmpTcfPurposes($tc)
    {
        // Only the core segment, which is everything before the first dot.
        $core = explode('.', (string) $tc);
        $core = $core[0];
        if ($core === '') {
            return null;
        }
        $b64 = strtr($core, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $bits = base64_decode($b64, true);
        if (!is_string($bits) || strlen($bits) < 22) {
            return null; // 176 bits are needed to reach purpose 24
        }
        // Version must be 2; anything else is not this layout.
        if (((ord($bits[0]) >> 2) & 0x3F) !== 2) {
            return null;
        }
        $bit = function ($n) use ($bits) {
            return (ord($bits[(int) floor($n / 8)]) >> (7 - ($n % 8))) & 1;
        };

        return $bit(152 + 2) === 1 && $bit(152 + 3) === 1;
    }
    // ---- DFSS-CONSENT-COOKIES:END ---------------------------------------
}
