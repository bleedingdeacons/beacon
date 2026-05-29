<?php

declare(strict_types=1);

namespace Beacon\Transport\Interfaces;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thrown by {@see HttpTransport} implementations on network-layer
 * failures — timeouts, DNS errors, TLS handshake errors, connection
 * refused. HTTP status codes (including 4xx and 5xx) are NOT raised
 * as TransportException; they come back as a normal response and the
 * driver decides whether to wrap them in a {@see \Beacon\Forwarding\Interfaces\ForwardingException}.
 *
 * This separation matters because the two failure modes mean
 * different things to the operator:
 *  - TransportException → "we couldn't talk to your PBX at all"
 *  - 401/403 response   → "we talked to it but it rejected us"
 */
class TransportException extends \RuntimeException
{
}
