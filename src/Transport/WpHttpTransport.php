<?php

declare(strict_types=1);

namespace Beacon\Transport;

if (!defined('ABSPATH')) {
    exit;
}

use Beacon\Transport\Interfaces\HttpTransport;
use Beacon\Transport\Interfaces\TransportException;

/**
 * WordPress HTTP API implementation of Beacon's {@see HttpTransport}.
 *
 * Beacon ships this as the default transport so that drivers don't
 * each have to reimplement WP-HTTP plumbing. Many upstreams have no
 * API — they're session-cookie-authenticated HTML admins — so a
 * single logical operation is a *sequence* of requests against the
 * same host that must share a session, e.g.:
 *
 *   POST /login/          → sets a session cookie
 *   GET  /resource?…      → must carry that cookie
 *   POST /resource/update → same cookie again
 *
 * The WP HTTP API does not keep a cookie jar across `wp_remote_*`
 * calls, so this transport maintains one itself: every response's
 * `Set-Cookie` headers are parsed (by WP, via
 * `wp_remote_retrieve_cookies()`) into {@see \WP_Http_Cookie} objects
 * and merged into an in-instance jar keyed by cookie name; every
 * subsequent request replays the jar via the `cookies` arg. Because
 * a fresh transport is built per request (the factory hands out a new
 * instance each time — see {@see Interfaces\HttpTransportFactory}),
 * the jar's lifetime is exactly one WordPress request — login state
 * never leaks between page loads or between users.
 *
 * We use WP's HTTP API rather than a raw cURL handle deliberately: it
 * honours host-level proxy configuration, the site's CA bundle, and
 * any `http_request_args` filters an operator relies on.
 *
 * Failure-mode contract (from {@see HttpTransport}):
 *  - Network-layer failures (timeout, DNS, TLS handshake, connection
 *    refused) surface as a {@see \WP_Error} from `wp_remote_request()`
 *    and are re-thrown as {@see TransportException}.
 *  - HTTP status codes — including 3xx, 4xx and 5xx — are NOT errors
 *    here. They come back in the response array and the driver decides
 *    what to do with them.
 *
 * Redirects ARE followed (WP default depth). A login POST that
 * answers 302→dashboard still ends up establishing the session, and
 * cookies set anywhere along the redirect chain are captured from the
 * final response. A driver that needs to inspect a raw 3xx can
 * construct the transport with `maxRedirects: 0`.
 */
final class WpHttpTransport implements HttpTransport
{
    use \Beacon\Logger\HasLogger;

    /**
     * Session cookie jar, keyed by cookie name so a later Set-Cookie
     * for the same name overrides the earlier value.
     *
     * @var array<string,\WP_Http_Cookie>
     */
    private array $cookies = [];

    public function __construct(
        private readonly bool $verifyTls = true,
        private readonly int $timeoutSeconds = 15,
        private readonly int $maxRedirects = 5,
        private readonly string $userAgent = 'Beacon (WordPress call-forwarding transport)',
        /**
         * Optional log-channel override. This transport is generic —
         * Beacon ships it as the default for any driver — so by default
         * it logs to its own class-name channel ("wphttptransport").
         * A driver that wants the transport's HTTP logging to appear
         * under the driver's/plugin's own channel (so a log line clearly
         * identifies which plugin the traffic belongs to) passes its
         * channel name here, e.g. Tamar passes "tamar". Empty string
         * means "use the default class-name channel".
         */
        private readonly string $logChannel = '',
    ) {
    }

    /**
     * Resolve the Sentinel channel for this instance's HTTP logging.
     * When a per-instance {@see $logChannel} override was supplied we
     * use it (so the line is attributed to the owning plugin); without
     * one we defer to the trait's default class-name channel. Returns
     * null when no logger is available, so callers stay null-safe.
     */
    private function channel(): ?\Sentinel_Log_Channel
    {
        if ($this->logChannel === '') {
            return self::log();
        }
        if (!function_exists('wp_log')) {
            return null;
        }
        return wp_log($this->logChannel);
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string,headers:array<string,string>}
     *
     * @throws TransportException
     */
    public function request(string $method, string $url, array $headers = [], string $body = ''): array
    {
        $args = [
            'method'      => strtoupper($method),
            'timeout'     => $this->timeoutSeconds,
            'redirection' => $this->maxRedirects,
            'sslverify'   => $this->verifyTls,
            'httpversion' => '1.1',
            'user-agent'  => $this->userAgent,
            'headers'     => $this->prepareRequestHeaders($headers),
            'cookies'     => array_values($this->cookies),
        ];

        // Only attach a body when there is one. A GET/HEAD/DELETE with
        // an empty string body is the common case and some servers
        // behave oddly if handed an empty entity body.
        if ($body !== '') {
            $args['body'] = $body;
        }

        // Note: the request body and headers can carry credentials and
        // session cookies, so they are deliberately kept out of the log
        // context. Only the method, URL, byte count and the *names* of
        // the cookies being replayed are recorded — never cookie values.
        $this->channel()?->debug('HTTP request', [
            'method' => strtoupper($method),
            'url' => $url,
            'body_bytes' => strlen($body),
            'cookies_sent' => array_keys($this->cookies),
        ]);

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            // Network-layer failure — DNS, TLS, timeout, refused. The
            // WP_Error code (e.g. 'http_request_failed') is preserved
            // in the message so the operator gets a usable diagnostic.
            $this->channel()?->error('HTTP request failed at the network layer', [
                'method' => strtoupper($method),
                'url' => $url,
                'error' => $response->get_error_message(),
            ]);
            throw new TransportException(
                'HTTP request to ' . $url . ' failed: ' . $response->get_error_message()
            );
        }

        // Capture any cookies this response set BEFORE returning, so
        // the next call in the sequence is authenticated.
        $cookiesBefore = array_keys($this->cookies);
        $this->captureCookies($response);
        $newCookies = array_values(array_diff(array_keys($this->cookies), $cookiesBefore));

        $status = (int) wp_remote_retrieve_response_code($response);
        $responseBody = (string) wp_remote_retrieve_body($response);
        $normalisedHeaders = $this->normaliseResponseHeaders($response);
        $finalUrl = $this->finalUrl($response, $url);
        $redirected = $finalUrl !== $url;

        // The Location header and the final (post-redirect) URL are
        // plain URLs, safe to log and essential for diagnosing a login
        // that "succeeds" but lands back on the login page. We also log
        // the final URL's path and parsed query parameters separately,
        // so the outcome signal (e.g. logged_in=1, notify=failedlogin)
        // is visible as structured data rather than buried in a string.
        // Param values whose names look secret-bearing are redacted.
        // Cookie *names* set by this response are logged; values never
        // are.
        $this->channel()?->debug('HTTP response', [
            'method' => strtoupper($method),
            'url' => $url,
            'final_url' => $finalUrl,
            'redirected' => $redirected,
            'final_path' => $this->urlPath($finalUrl),
            'final_query' => $this->urlQueryParams($finalUrl),
            'location' => $normalisedHeaders['location'] ?? '',
            'location_query' => $this->urlQueryParams((string) ($normalisedHeaders['location'] ?? '')),
            'status' => $status,
            'body_bytes' => strlen($responseBody),
            'set_cookie_names' => $newCookies,
            'jar_cookie_names' => array_keys($this->cookies),
        ]);

        return [
            'status'  => $status,
            'body'    => $responseBody,
            'headers' => $normalisedHeaders,
        ];
    }

    /**
     * Best-effort extraction of the URL the request actually ended on
     * after any redirects. WP exposes the final {@see \WP_HTTP_Requests_Response}
     * under the `http_response` key; its underlying Requests object
     * carries the resolved URL. Falls back to the requested URL when
     * the object isn't available (e.g. a fake transport in tests).
     *
     * @param array<string,mixed>|\WP_Error $response
     */
    private function finalUrl($response, string $requestedUrl): string
    {
        if (!is_array($response)) {
            return $requestedUrl;
        }
        $httpResponse = $response['http_response'] ?? null;
        if (is_object($httpResponse) && method_exists($httpResponse, 'get_response_object')) {
            $reqObj = $httpResponse->get_response_object();
            if (is_object($reqObj) && isset($reqObj->url) && is_string($reqObj->url) && $reqObj->url !== '') {
                return $reqObj->url;
            }
        }
        return $requestedUrl;
    }

    /**
     * The path component of a URL (no scheme/host/query), for logging.
     * Returns '' if the URL can't be parsed.
     */
    private function urlPath(string $url): string
    {
        if ($url === '') {
            return '';
        }
        $path = parse_url($url, PHP_URL_PATH);
        return is_string($path) ? $path : '';
    }

    /**
     * Parse a URL's query string into a name => value map for logging.
     * Values for parameters whose name looks secret-bearing (token,
     * nonce, key, auth, password, etc.) are redacted; the rest — the
     * useful outcome signals like `logged_in` or `notify` — are kept
     * verbatim so they can be read directly in the log.
     *
     * @return array<string,string>
     */
    private function urlQueryParams(string $url): array
    {
        if ($url === '') {
            return [];
        }
        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return [];
        }
        $parsed = [];
        parse_str($query, $parsed);

        $out = [];
        foreach ($parsed as $name => $value) {
            $name = (string) $name;
            $flat = is_array($value) ? implode(',', array_map('strval', $value)) : (string) $value;
            if (preg_match('/(pass|pwd|token|nonce|secret|auth|key|sid|session)/i', $name) === 1) {
                $out[$name] = '[redacted]';
            } else {
                $out[$name] = $flat;
            }
        }
        return $out;
    }
    /**
     * Read-only view of the current jar as name → value pairs. Exposed
     * for diagnostics and tests — the request flow uses the
     * {@see \WP_Http_Cookie} objects directly so path/domain/expiry are
     * preserved.
     *
     * @return array<string,string>
     */
    public function cookies(): array
    {
        $out = [];
        foreach ($this->cookies as $name => $cookie) {
            $out[$name] = (string) $cookie->value;
        }
        return $out;
    }

    // -- internals --------------------------------------------------------

    /**
     * Merge caller headers over our defaults. We supply an `Accept`
     * default so the upstream serves HTML rather than negotiating
     * something exotic, but the caller wins on any header it sets
     * (the driver sets `Content-Type` for its form POSTs, for
     * instance).
     *
     * @param array<string,string> $headers
     * @return array<string,string>
     */
    private function prepareRequestHeaders(array $headers): array
    {
        $defaults = [
            'Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8',
        ];

        // Caller headers take precedence. Case differences (Accept vs
        // accept) are harmless — WP's HTTP layer treats header names
        // case-insensitively on send.
        return array_merge($defaults, $headers);
    }

    /**
     * Parse this response's Set-Cookie headers (via WP, which handles
     * the multi-cookie and attribute-parsing edge cases) and fold them
     * into the jar. A cookie with an empty name is ignored.
     *
     * @param array<string,mixed>|\WP_Error $response
     */
    private function captureCookies($response): void
    {
        $cookies = wp_remote_retrieve_cookies($response);
        if (!is_array($cookies)) {
            return;
        }
        foreach ($cookies as $cookie) {
            if (!$cookie instanceof \WP_Http_Cookie) {
                continue;
            }
            $name = (string) $cookie->name;
            if ($name === '') {
                continue;
            }
            $this->cookies[$name] = $cookie;
        }
    }

    /**
     * Coerce WP's case-insensitive header dictionary into the plain
     * `array<string,string>` the contract promises, with lower-cased
     * keys. Multi-value headers (which WP may hand back as an array)
     * are joined with ", " so the value stays a string; cookie
     * continuity does not depend on this map — it is informational for
     * the caller.
     *
     * @param array<string,mixed>|\WP_Error $response
     * @return array<string,string>
     */
    private function normaliseResponseHeaders($response): array
    {
        $headers = wp_remote_retrieve_headers($response);

        // wp_remote_retrieve_headers() returns a case-insensitive
        // dictionary object on success and '' if headers are absent.
        if (is_object($headers) && method_exists($headers, 'getAll')) {
            $headers = $headers->getAll();
        }
        if (!is_array($headers)) {
            return [];
        }

        $out = [];
        foreach ($headers as $name => $value) {
            $key = strtolower((string) $name);
            $out[$key] = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
        }
        return $out;
    }
}
