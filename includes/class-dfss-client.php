<?php
/**
 * Signing HTTP client for the dispatcher. Never throws: a tracking error must not break checkout.
 */
if (!defined('ABSPATH')) {
    exit;
}

class DFSS_Client
{
    /**
     * @var string
     */
    private $tenant_id;
    /**
     * @var string
     */
    private $secret;
    /**
     * @var string
     */
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
     * @param int   $timeout Total HTTP timeout in seconds: 4 on the checkout path, longer for the
     *                       browser-beacon relay. The connect timeout stays at 4 either way.
     *
     * @return array{ok:bool,code:int,message:string}
     */
    public function send(array $payload, $timeout = 4)
    {
        if ($this->tenant_id === '' || $this->secret === '' || $this->endpoint === '') {
            return array('ok' => false, 'code' => 0, 'message' => 'not_configured');
        }

        // An empty userData is a legitimate event since 2.22.0 (a purchase or a login without consent
        // carries none).
        if (isset($payload['userData']) && $payload['userData'] === array()) {
            $payload['userData'] = new stdClass();
        }

        // The bytes we sign MUST be byte-for-byte the bytes we POST.
        $body = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return array('ok' => false, 'code' => 0, 'message' => 'json_encode_failed');
        }

        return $this->request($this->endpoint, $body, (int) $timeout);
    }

    /**
     * Fetch the tenant's PUBLIC destination ids from the dispatcher.
     *
     * @return array{ok:bool,code:int,public:array,message:string}
     */
    public function get_public_config()
    {
        if ($this->tenant_id === '' || $this->secret === '' || $this->endpoint === '') {
            return array('ok' => false, 'code' => 0, 'public' => array(), 'message' => 'not_configured');
        }

        $url = $this->public_config_url();
        // Empty object, the dispatcher signs/validates the raw body, which must be exactly the two
        // bytes "{}" on both sides.
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
     * Send the shop's daily totals to POST /v1/truth, sibling of the events endpoint and signed the
     * same way.
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
     * Sign a raw body and POST it. The only signed path out: send(), send_truth() and
     * get_public_config() all use it. Signature v2 covers the timestamp too, so a captured request
     * cannot be replayed with a fresh one (the dispatcher allows +/-300 s).
     *
     * @param string $url
     * @param string $body Raw bytes: exactly what is signed and sent.
     * @param int    $timeout
     *
     * @return array{ok:bool,code:int,message:string}
     */
    private function request($url, $body, $timeout = 4)
    {
        $timestamp = (string) time();
        // Signed string: timestamp, LF, then the exact bytes we POST (lowercase hex HMAC-SHA256).
        $signature = hash_hmac('sha256', $timestamp . "\n" . $body, $this->secret);

        $headers = array(
            'Content-Type' => 'application/json',
            'X-Dfss-Tenant' => $this->tenant_id,
            'X-Dfss-Timestamp' => $timestamp,
            'X-Dfss-Signature-Version' => '2',
            'X-Dfss-Signature' => $signature,
        );
        // Queue health, so the dispatcher can see a shop whose retries run late (WP-Cron without
        // visitors, a dispatcher outage). Computed once per request, and left out when the queue
        // cannot be read: an unknown queue is not an empty one. Not part of the signature.
        if (class_exists('DFSS_Queue', false)) {
            $signal = DFSS_Queue::signal();
            if ($signal !== null) {
                $headers['X-Dfss-Queue-Depth'] = (string) (int) $signal['depth'];
                $headers['X-Dfss-Queue-Oldest-Age'] = (string) (int) $signal['oldest_age'];
            }
        }

        // WordPress gives cURL the same value for the total and the connect timeout: a longer total
        // timeout must not lengthen the connect wait.
        $connect_cap = null;
        if ($timeout > 4) {
            $connect_cap = function ($handle) {
                curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 4); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- capping the connect wait on WordPress' own cURL handle, through its http_api_curl action.
            };
            add_action('http_api_curl', $connect_cap);
        }

        // The safe variant refuses loopback and private ranges: the URL can be operator-supplied.
        $response = wp_safe_remote_post(
            $url,
            array(
                // Tracking must never slow checkout: keep the timeout tight.
                'timeout' => $timeout,
                'redirection' => 0,
                'headers' => $headers,
                'body' => $body,
            )
        );

        if ($connect_cap !== null) {
            remove_action('http_api_curl', $connect_cap);
        }

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
