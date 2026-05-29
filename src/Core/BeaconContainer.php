<?php

declare(strict_types=1);

namespace Beacon\Core;

if (!defined('ABSPATH')) {
    exit;
}

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Minimal PSR-11 container with factory + shared-instance support.
 *
 * This exists so Beacon can run standalone — without depending on
 * Unity or any other ecosystem container. Hosting plugins that want
 * Beacon to share their container can hook `beacon/container` and
 * return their own PSR-11 implementation; Beacon will use it
 * transparently.
 *
 * Two binding styles are supported:
 *  - `set($id, $instance)`         — store a pre-built object/value.
 *  - `factory($id, $callable)`     — store a closure that builds the
 *                                    object lazily; result is cached
 *                                    on first `get()` so subsequent
 *                                    resolutions hit the same instance.
 *
 * `has()` returns true for either binding style.
 */
final class BeaconContainer implements ContainerInterface
{
    /** @var array<string,mixed> */
    private array $instances = [];

    /** @var array<string,callable> */
    private array $factories = [];

    public function set(string $id, mixed $value): void
    {
        $this->instances[$id] = $value;
        // A direct set overrides any prior factory — last bind wins,
        // which is the expected behaviour when an implementation plugin
        // swaps out a default with a concrete driver.
        unset($this->factories[$id]);
    }

    public function factory(string $id, callable $callable): void
    {
        $this->factories[$id] = $callable;
        // Likewise: registering a new factory invalidates any cached
        // instance built from a previous factory.
        unset($this->instances[$id]);
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->instances)
            || array_key_exists($id, $this->factories);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (array_key_exists($id, $this->factories)) {
            // Build on first request, then cache. Factories are
            // expected to be pure of side-effects beyond building the
            // service itself.
            $instance = ($this->factories[$id])($this);
            $this->instances[$id] = $instance;
            return $instance;
        }
        throw new class("Beacon container: no binding for '{$id}'.") extends \RuntimeException implements NotFoundExceptionInterface {};
    }
}
