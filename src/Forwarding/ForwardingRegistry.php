<?php

declare(strict_types=1);

namespace Beacon\Forwarding;

if (!defined('ABSPATH')) {
    exit;
}

use Closure;
use Beacon\Forwarding\Interfaces\CallForwardingService;

/**
 * The one place a driver publishes its CallForwardingService and a
 * consumer finds it.
 *
 * Tamar and Trusted each vendor their own copy of this library, but a
 * class loads once per request, so whichever autoloader supplies it both
 * plugins share the same static state. That replaces the `beacon/loaded`
 * action Beacon fired back when it was a plugin: nothing here depends on
 * which plugin WordPress includes first, because the consumer resolves at
 * the moment it needs a driver rather than at boot.
 *
 * The driver binds a resolver, not an instance, so a request that never
 * touches forwarding never builds the HTTP client or reads its settings.
 */
final class ForwardingRegistry
{
    /** @var (Closure(): CallForwardingService)|null */
    private static ?Closure $resolver = null;

    /**
     * @param Closure(): CallForwardingService $resolver
     */
    public static function bind(Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    public static function has(): bool
    {
        return self::$resolver !== null;
    }

    public static function get(): ?CallForwardingService
    {
        return self::$resolver === null ? null : (self::$resolver)();
    }

    public static function clear(): void
    {
        self::$resolver = null;
    }
}
