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
}
