<?php

declare(strict_types=1);

namespace Beacon\Forwarding\Interfaces;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The common throwable type drivers raise on operational failure.
 *
 * Subclassing is encouraged — implementations may distinguish, e.g.,
 * an `AuthenticationFailedException` from a `ParseException` so the
 * admin UI can render specific guidance. Catching `ForwardingException`
 * at the call site catches everything driver-level without coupling
 * the caller to driver-specific subclasses.
 *
 * Network and parsing failures both belong here. Validation failures
 * (e.g. a malformed rule passed to `saveRule()`) belong here too,
 * since they're still "the operation didn't succeed."
 */
class ForwardingException extends \RuntimeException
{
}
