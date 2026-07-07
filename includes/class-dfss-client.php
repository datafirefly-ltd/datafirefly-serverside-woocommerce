<?php
/**
 * DataFirefly Server-Side (WooCommerce) — signing HTTP client.
 *
 * Signs an event payload with the tenant's HMAC secret and POSTs it to the
 * dispatcher, exactly per the dispatcher contract:
 *   X-Dfss-Tenant     : tenant id
 *   X-Dfss-Timestamp  : unix seconds (server checks a +/-300s window)
 *   X-Dfss-Signature  : hex HMAC-SHA256(rawBody, secret)
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
     * Shared by send() and get_public_config() so the HMAC scheme lives in one
     * place: headers X-Dfss-Tenant, X-Dfss-Timestamp (unix seconds, the server
     * allows +/-300s and does NOT sign it), X-Dfss-Signature = lowercase hex
     * HMAC-SHA256(rawBody, secret).
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
        // hash_hmac() returns lowercase hex by default — the dispatcher rejects
        // anything that is not exactly 64 lowercase hex chars.
        $signature = hash_hmac('sha256', $body, $this->secret);

        $response = wp_remote_post(
            $url,
            array(
                // Tracking must never slow checkout: keep the timeout tight.
                'timeout' => $timeout,
                'redirection' => 0,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'X-Dfss-Tenant' => $this->tenant_id,
                    'X-Dfss-Timestamp' => $timestamp,
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
