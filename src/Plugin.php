<?php

declare(strict_types=1);

namespace Beacon;

if (!defined('ABSPATH')) {
    exit;
}

use Psr\Container\ContainerInterface;
use RuntimeException;
use Beacon\Core\BeaconContainer;
use Beacon\Core\BeaconServiceProvider;
use Beacon\Forwarding\Interfaces\CallForwardingService;

/**
 * Main Beacon Plugin Class
 *
 * Beacon is the *contracts* plugin for call forwarding. It boots on
 * `plugins_loaded`, registers its interfaces into a shared PSR-11
 * container, and fires `beacon/loaded` so implementation plugins
 * (Anchor, etc.) can bind their concrete drivers.
 *
 * Beacon ships no concrete CallForwardingService — that's the
 * implementation plugin's responsibility. If `beacon/loaded` fires and
 * no driver is bound by the time someone calls `beacon()->get(...)`,
 * the resolution will fail loudly so misconfiguration surfaces fast
 * rather than silently no-op'ing forwarding changes.
 *
 * If a host plugin (e.g. Unity) already provides a shared PSR-11
 * container, Beacon will adopt it via the `beacon/container` filter
 * instead of using its own — this lets Beacon participate in a wider
 * ecosystem without forcing every plugin to use its container.
 */
class Plugin
{
    use \Beacon\Logger\HasLogger;

    protected static function logChannel(): string
    {
        return 'beacon';
    }

    private static ?ContainerInterface $container = null;
    private static bool $initialized = false;

    /**
     * Initialise Beacon.
     *
     * Idempotent — subsequent calls are no-ops, which matters because
     * `plugins_loaded` can in theory fire more than once during certain
     * test harness setups.
     */
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        // Give the wider ecosystem a chance to supply a shared
        // container. If nothing's provided we fall back to Beacon's
        // own minimal PSR-11 implementation so the plugin works
        // standalone.
        $container = apply_filters('beacon/container', null);
        if (!$container instanceof ContainerInterface) {
            $container = new BeaconContainer();
        }

        self::$container = $container;

        (new BeaconServiceProvider())->register($container);

        // The forwarding API is private: it drives where AA helpline
        // calls get routed, so it must only ever be reached in-process by
        // a trusted plugin service — never as a public HTTP endpoint.
        //
        // The supported integration path is the container service: a
        // consumer plugin (Trusted, Tamar's admin, etc.) hooks
        // `beacon/loaded`, takes the passed container, and resolves
        // CallForwardingService directly — no HTTP round-trip, no exposed
        // route to attack.
        //
        // The REST wrapper therefore stays OFF unless an operator
        // explicitly opts in by defining BEACON_ENABLE_REST in
        // wp-config.php (e.g. for a vetted external client behind its own
        // auth). Default-deny keeps the attack surface closed.
        if (defined('BEACON_ENABLE_REST') && BEACON_ENABLE_REST) {
            (new \Beacon\Rest\ForwardingRestController($container))->register();
            self::logWarning('Forwarding REST API enabled via BEACON_ENABLE_REST — this exposes call-forwarding control over HTTP.');
        }

        self::$initialized = true;

        self::logDebug('Initialised', ['version' => defined('BEACON_VERSION') ? BEACON_VERSION : 'unknown']);
    }

    /**
     * Get the shared container.
     *
     * @throws RuntimeException If the plugin hasn't booted yet.
     */
    public static function getContainer(): ContainerInterface
    {
        if (self::$container === null) {
            throw new RuntimeException('Beacon Plugin not initialised — wait for the beacon/loaded action.');
        }
        return self::$container;
    }

    /**
     * Whether Beacon has finished initialising.
     */
    public static function isInitialized(): bool
    {
        return self::$initialized;
    }

    /**
     * Whether an implementation plugin has bound a concrete driver to
     * the CallForwardingService contract. Used by the admin notice to
     * decide whether to nag the operator that Beacon is sitting idle.
     */
    public static function hasDriver(): bool
    {
        if (self::$container === null) {
            return false;
        }
        return self::$container->has(CallForwardingService::class);
    }
}
