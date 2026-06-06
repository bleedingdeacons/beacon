<?php

declare(strict_types=1);

namespace Beacon\Transport\Interfaces;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds {@see HttpTransport} instances.
 *
 * Beacon defines this so that drivers (and the container bindings that
 * wire them up) depend on the *idea* of "give me a transport" rather
 * than on a concrete class such as {@see \Beacon\Transport\WpHttpTransport}.
 * Two things fall out of that:
 *
 *  - Drivers stay testable: a test can hand a service a factory that
 *    returns a scripted fake transport, without anyone calling `new`
 *    on a WP-specific class.
 *  - Operators can swap the whole transport implementation (Guzzle,
 *    an HMAC-signing wrapper, a recording proxy) by binding a
 *    different factory, without touching driver code.
 *
 * Why a factory and not just the transport itself? {@see HttpTransport}
 * implementations are *stateful within a request* — {@see \Beacon\Transport\WpHttpTransport}
 * keeps a per-instance cookie jar so a GET-then-POST flow against a
 * session-cookie upstream stays authenticated. That state must not
 * leak between independent logical operations or between WordPress
 * requests, so each operation wants its *own* fresh transport. The
 * factory makes "fresh instance, please" the explicit, cheap call,
 * and keeps construction details (TLS verification, timeouts) in one
 * place rather than scattered across every `new` site.
 *
 * Per-call overrides exist for the cases a driver legitimately needs
 * to bend the defaults — most commonly turning redirect-following off
 * so it can inspect a raw 3xx. Anything not overridden falls back to
 * the factory's configured defaults.
 */
interface HttpTransportFactory
{
    /**
     * Build a fresh {@see HttpTransport}.
     *
     * Each call MUST return a new instance with empty per-request
     * state (no carried-over cookie jar), so independent operations
     * never share a session.
     *
     * Every parameter is optional; when omitted, the factory supplies
     * its own configured default. They mirror the knobs a transport
     * commonly needs:
     *
     * @param bool|null $verifyTls      Verify the upstream's TLS
     *                                  certificate. Null → factory default.
     * @param int|null  $timeoutSeconds Per-request timeout in seconds.
     *                                  Null → factory default.
     * @param int|null  $maxRedirects   Redirect-follow depth. Pass 0 to
     *                                  see a raw 3xx response. Null →
     *                                  factory default.
     */
    public function create(
        ?bool $verifyTls = null,
        ?int $timeoutSeconds = null,
        ?int $maxRedirects = null,
    ): HttpTransport;
}
