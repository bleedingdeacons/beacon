<?php

declare(strict_types=1);

namespace Beacon\Transport;

if (!defined('ABSPATH')) {
    exit;
}

use Beacon\Transport\Interfaces\HttpTransport;
use Beacon\Transport\Interfaces\HttpTransportFactory;

/**
 * Default {@see HttpTransportFactory}: hands out {@see WpHttpTransport}
 * instances backed by the WordPress HTTP API.
 *
 * The factory holds the *defaults* (TLS verification, timeout,
 * redirect depth, user-agent); {@see create()} builds a fresh
 * transport each time, applying any per-call overrides on top of
 * those defaults. Because every {@see create()} call returns a new
 * instance, the per-request cookie jar each transport maintains never
 * leaks between operations.
 *
 * An operator who wants a different transport (Guzzle, a signing
 * wrapper, a recorder) writes their own {@see HttpTransportFactory}
 * and binds it in place of this one; nothing downstream changes
 * because callers only ever see the interface.
 */
final class WpHttpTransportFactory implements HttpTransportFactory
{
    public function __construct(
        private readonly bool $verifyTls = true,
        private readonly int $timeoutSeconds = 15,
        private readonly int $maxRedirects = 5,
        private readonly string $userAgent = 'Beacon (WordPress call-forwarding transport)',
    ) {
    }

    /**
     * Build a fresh {@see WpHttpTransport}. Any argument left null
     * falls back to the corresponding factory default.
     */
    public function create(
        ?bool $verifyTls = null,
        ?int $timeoutSeconds = null,
        ?int $maxRedirects = null,
    ): HttpTransport {
        return new WpHttpTransport(
            verifyTls: $verifyTls ?? $this->verifyTls,
            timeoutSeconds: $timeoutSeconds ?? $this->timeoutSeconds,
            maxRedirects: $maxRedirects ?? $this->maxRedirects,
            userAgent: $this->userAgent,
        );
    }
}
