<?php
/**
 * DataFirefly Server-Side (WooCommerce) — signing HTTP client.
 *
 * Signs an event payload with the tenant's HMAC secret and POSTs it to the
 * dispatcher, exactly per the dispatcher contract:
 *   X-Dfss-Tenant            : tenant id
 *   X-Dfss-Timestamp         : unix seconds (server checks a +/-300s window)
 *   X-Dfss-Signature-Version : 2
 *   X-Dfss-Signature         : hex HMAC-SHA256(timestamp + "\n" + rawBody, secret)
 *
 * Fail-safe: every error is captured and returned, never thrown, so a tracking
 * hiccup can never break the shop's checkout.
 */
if (!defined('ABSPATH')) {
    exit;
}

class DFSS_Client
{
    /** @var string */
    private $tenant_id;
    /** @var string */
    private $secret;
    /** @var string */
    private $endpoint;

    public function __construct($tenant_id, $secret, $endpoint)
    {
        $this->tenant_id = (string) $tenant_id;
        $this->secret = (string) $secret;
        $this->endpoint = (string) $endpoint;
    }

    /**
     * Sign and send one event.
     *
     * @param array $payload IncomingEvent shape (see DFSS_Event_Builder)
     *
     * @return array{ok:bool,code:int,message:string}
     */
    public function send(array $payload)
    {
        if ($this->tenant_id === '' || $this->secret === '' || $this->endpoint === '') {
            return array('ok' => false, 'code' => 0, 'message' => 'not_configured');
        }

        // An empty userData is a legitimate event since 2.22.0 (a purchase or a
        // login without consent carries none). PHP encodes an empty array as
        // `[]`, which the dispatcher's z.object() rejects, event and all; and
        // the retry queue hands the payload back as an array whatever it was
        // when first sent. So the fix lives here, on the only path out.
        if (isset($payload['userData']) && $payload['userData'] === array()) {
            $payload['userData'] = new stdClass();
        }

        // The bytes we sign MUST be byte-for-byte the bytes we POST.
        $body = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return array('ok' => false, 'code' => 0, 'message' => 'json_encode_failed');
        }

        return $this->request($this->endpoint, $body);
    }

    /**
     * Fetch the tenant's PUBLIC destination ids from the dispatcher.
     *
     * Mirrors the dispatcher contract for POST /v1/tenant/public-config: the
     * body carries no data, so we sign an empty `{}` JSON object (kept as POST
     * because a raw body must always exist for the HMAC signature). The
     * response is `{ tenantId, public: { meta?, ga4?, tiktok?, pinterest? } }`
     * — PUBLIC ids only, never an accessToken or apiSecret.
     *
     * The public-config URL is derived from the events endpoint by swapping the
     * trailing `/v1/events` for `/v1/tenant/public-config`, so a single key keeps
     * configuring everything.
     *
     * @return array{ok:bool,code:int,public:array,message:string}
     */
    public function get_public_config()
    {
        if ($this->tenant_id === '' || $this->secret === '' || $this->endpoint === '') {
            return array('ok' => false, 'code' => 0, 'public' => array(), 'message' => 'not_configured');
        }

        $url = $this->public_config_url();
        // Empty object — the dispatcher signs/validates the raw body, which must
        // be exactly the two bytes "{}" on both sides.
        $body = '{}';

        $result = $this->request($url, $body);

        $public = array();
        if (!empty($result['ok'])) {
            $decoded = json_decode((string) $result['message'], true);
            if (is_array($decoded) && isset($decoded['public']) && is_array($decoded['public'])) {
                $public = $decoded['public'];
            }
        }

        return array(
            'ok' => !empty($result['ok']),
            'code' => (int) $result['code'],
            'public' => $public,
            'message' => (string) $result['message'],
        );
    }

    /**
     * Derive the public-config URL from the events endpoint.
     *
     * @return string
     */
    /**
     * Send the shop's daily totals to POST /v1/truth, sibling of the events
     * endpoint and signed the same way. Allowed to fail: nothing is lost, the
     * next daily run sends the day again.
     *
     * @param array $payload {date, orders, revenue, currency, refunds?, refundAmount?, timezone?}
     *
     * @return array{ok: bool, code: int, message: string}
     */
    public function send_truth(array $payload)
    {
        if ($this->tenant_id === '' || $this->secret === '' || $this->endpoint === '') {
            return array('ok' => false, 'code' => 0, 'message' => 'not_configured');
        }
        $body = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($body)) {
            return array('ok' => false, 'code' => 0, 'message' => 'json_encode_failed');
        }

        return $this->request($this->sibling_url('/v1/truth'), $body, 8);
    }

    /**
     * A path on the same host as the events endpoint.
     */
    private function sibling_url($path)
    {
        $endpoint = $this->endpoint;
        if (substr($endpoint, -strlen('/v1/events')) === '/v1/events') {
            return substr($endpoint, 0, strlen($endpoint) - strlen('/v1/events')) . $path;
        }
        $parts = wp_parse_url($endpoint);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return $parts['scheme'] . '://' . $parts['host'] . $port . $path;
    }

    private function public_config_url()
    {
        $endpoint = $this->endpoint;
        if (substr($endpoint, -strlen('/v1/events')) === '/v1/events') {
            return substr($endpoint, 0, -strlen('/v1/events')) . '/v1/tenant/public-config';
        }

        // Fallback: derive from the scheme+host of the configured endpoint.
        $parts = wp_parse_url($endpoint);
        if (!empty($parts['scheme']) && !empty($parts['host'])) {
            $base = $parts['scheme'] . '://' . $parts['host'];
            if (!empty($parts['port'])) {
                $base .= ':' . $parts['port'];
            }

            return $base . '/v1/tenant/public-config';
        }

        return $endpoint;
    }

    /**
     * Sign a raw body and POST it to a dispatcher URL.
     *
     * Shared by send(), send_truth() and get_public_config() so the HMAC scheme
     * lives in exactly one place and no signed call can be left behind on the
     * old scheme: headers X-Dfss-Tenant, X-Dfss-Timestamp (unix seconds, the
     * server allows a +/-300s window), X-Dfss-Signature-Version: 2, and
     * X-Dfss-Signature = lowercase hex HMAC-SHA256(timestamp + LF + rawBody).
     *
     * The timestamp is inside the signed string since 2.23.0. Signing the body
     * alone left the timestamp unauthenticated, so the dispatcher's +/-300s
     * window protected nothing: a captured (body, signature) pair replayed with
     * a fresh timestamp was accepted forever.
     *
     * Fail-safe: every error is captured and returned, never thrown.
     *
     * @param string $url
     * @param string $body Raw bytes — these exact bytes are both signed and sent.
     * @param int    $timeout
     *
     * @return array{ok:bool,code:int,message:string}
     */
    private function request($url, $body, $timeout = 4)
    {
        $timestamp = (string) time();
        // The signed string is the timestamp, one LF, then the exact bytes we
        // POST. hash_hmac() returns lowercase hex by default, and the
        // dispatcher rejects anything that is not exactly 64 lowercase hex
        // chars.
        $signature = hash_hmac('sha256', $timestamp . "\n" . $body, $this->secret);

        // The safe variant: the URL is operator-supplied (Advanced form), and
        // wp_safe_remote_post() refuses loopback, private ranges and odd
        // ports, so a mistyped or hostile endpoint cannot turn the shop into
        // an SSRF relay signing requests at its own network (audit
        // 2026-09-04, F2).
        $response = wp_safe_remote_post(
            $url,
            array(
                // Tracking must never slow checkout: keep the timeout tight.
                'timeout' => $timeout,
                'redirection' => 0,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'X-Dfss-Tenant' => $this->tenant_id,
                    'X-Dfss-Timestamp' => $timestamp,
                    'X-Dfss-Signature-Version' => '2',
                    'X-Dfss-Signature' => $signature,
                ),
                'body' => $body,
            )
        );

        if (is_wp_error($response)) {
            return array('ok' => false, 'code' => 0, 'message' => $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        return array(
            'ok' => ($code >= 200 && $code < 300),
            'code' => $code,
            'message' => substr((string) wp_remote_retrieve_body($response), 0, 500),
        );
    }
}
