<?php

declare(strict_types=1);

namespace Beacon\Core;

if (!defined('ABSPATH')) {
    exit;
}

use Psr\Container\ContainerInterface;

/**
 * Register Beacon's shared services into the container.
 *
 * Beacon deliberately ships no concrete binding for
 * {@see \Beacon\Forwarding\Interfaces\CallForwardingService} — that's
 * the implementation plugin's responsibility (Anchor, etc.). The
 * service provider exists so Beacon can register the things that
 * *are* its responsibility: stateless helpers and the cross-plugin
 * extension hook.
 *
 * Implementation plugins should hook `beacon/loaded` and run their
 * own service provider against the same container.
 */
final class BeaconServiceProvider
{
    public function register(ContainerInterface $container): void
    {
        // Currently no shared bindings — Beacon is contracts-only.
        // The hook below lets implementation plugins extend the
        // container at exactly the right moment in the boot sequence,
        // before `beacon/loaded` fires for general consumers.

        /**
         * Fires while Beacon is registering shared services.
         *
         * Use this hook to register helpers that should be available
         * before any implementation plugin's service provider runs.
         *
         * @param ContainerInterface $container Shared dependency container
         */
        do_action('beacon/register_services', $container);
    }
}
