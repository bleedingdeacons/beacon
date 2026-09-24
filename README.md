# Beacon — Call-Forwarding Contracts

[![CI](https://github.com/bleedingdeacons/beacon/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/bleedingdeacons/beacon/actions/workflows/ci.yml)
[![Semgrep](https://github.com/bleedingdeacons/beacon/actions/workflows/semgrep.yml/badge.svg?branch=main)](https://github.com/bleedingdeacons/beacon/actions/workflows/semgrep.yml)
[![Coverage Status](https://coveralls.io/repos/github/bleedingdeacons/beacon/badge.svg?branch=main)](https://coveralls.io/github/bleedingdeacons/beacon?branch=main)
![PHPStan](https://img.shields.io/badge/dynamic/yaml?url=https%3A%2F%2Fraw.githubusercontent.com%2Fbleedingdeacons%2Fbeacon%2Fmain%2Fphpstan.neon.dist&query=%24.parameters.level&label=PHPStan&prefix=level%20&color=brightgreen)
![PHPCS](https://img.shields.io/badge/dynamic/xml?url=https%3A%2F%2Fraw.githubusercontent.com%2Fbleedingdeacons%2Fbeacon%2Fmain%2F.phpcs.xml.dist&query=%2Fruleset%2Frule%5B1%5D%2F%40ref&label=PHPCS&color=brightgreen)
![Version](https://img.shields.io/github/v/tag/bleedingdeacons/beacon?label=version&color=blue)
![PHP](https://img.shields.io/badge/php-8.4%2B-777bb4)
![Licence](https://img.shields.io/badge/licence-MIT%20(Modified)-green)

Call-forwarding contracts for the Bleeding Deacons suite. **A Composer library, not a WordPress plugin** — it is never activated. Tamar (the driver for Tamar Telecommunications' panel) and Trusted (the rota) each `require` it, and it is loaded by their own Composer autoloaders.

Until v3.0.0 Beacon was a plugin of its own that owned a PSR-11 container and fired `beacon/loaded`. It became a library so call forwarding stops needing a separate plugin to be installed and activated alongside the one that actually does the work.

## How a driver reaches a consumer

```
Tamar (plugins_loaded)  ──bind──▶  ForwardingRegistry  ◀──get──  Trusted (when it needs a driver)
```

- **Tamar** builds its driver behind a resolver and calls `ForwardingRegistry::bind()`.
- **Trusted** calls `ForwardingRegistry::get()` at the moment it needs to forward, and gets `null` when no driver is bound.

Both plugins vendor their own copy of this package, but a PHP class loads once per request, so whichever autoloader supplies `ForwardingRegistry` both plugins share its state. Nothing depends on which plugin WordPress includes first.

**Keep the two copies compatible.** Whichever plugin's autoloader loads a class first supplies it to both, so Tamar and Trusted should require the same major version. A breaking change here is a new major, and both consumers move to it together.

## What it ships

| | |
|---|---|
| `Beacon\Forwarding\Interfaces\CallForwardingService` | The driver contract — list/save/delete rules, list targets, commit, test. |
| `Beacon\Forwarding\ForwardingRegistry` | Where a driver is published and a consumer finds it. |
| `Beacon\Forwarding\Interfaces\ForwardingException` | Common throwable for driver failures. |
| `Beacon\Forwarding\Models\ForwardingRule` | Immutable value object: match condition + target. |
| `Beacon\Targets\Models\ForwardingTarget` | Immutable value object: destination (number/extension/voicemail). |
| `Beacon\Forwarding\AbstractCallForwardingService` | Shared validation + hydration drivers can extend. |
| `Beacon\Transport\…` | `HttpTransport` contract and the WordPress HTTP API implementation. |
| `Beacon\Core\BeaconContainer` | Minimal PSR-11 container a driver can wire itself with. |
| `Beacon\Capabilities\CapabilityBootstrap` | The forwarding roles and capabilities below. |
| `Beacon\Rest\ForwardingRestController` | Optional `beacon/v1` REST API over the bound driver. |

The capabilities and the REST controller are classes only. **The driver plugin wires them**: Tamar registers the roles on activation, removes them on deactivation and uninstall, and registers the REST routes when `BEACON_ENABLE_REST` is defined in `wp-config.php`.

## Installation

In the consuming plugin's `composer.json`:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/bleedingdeacons/beacon" }
],
"require": {
    "bleedingdeacons/beacon": "^3.0"
}
```

Releases are `vX.Y.Z` tags cut by hand on `main`. There is no zip and no GitHub Release asset.

## Capabilities

| Capability | Granted to |
|---|---|
| `beacon_manage_forwarding` | Operator only — create / delete rules, change connection settings. |
| `beacon_route_forwarding`  | Operator + Dispatcher — switch existing rules between targets. |
| `beacon_push_config`       | Operator + Dispatcher — commit pending changes upstream. |
| `beacon_view_forwarding`   | Operator + Dispatcher + Viewer — read-only audit. |

## Requirements

- WordPress 6.1+
- PHP 8.4+

## Testing

Install the dev dependencies and run the suite from the repository root:

```bash
composer install
```

| Command | Description |
|---|---|
| `composer test` | Run the full Pest test suite |
| `composer test:unit` | Run unit tests only |
| `composer test:integration` | Run integration tests only |
| `composer test:coverage` | Generate an HTML coverage report |
| `composer phpstan` | Run PHPStan static analysis |
| `composer cs` | Check coding standards |
| `composer cs:fix` | Auto-fix coding standard violations |
| `composer check` | Run CS + PHPStan + tests in sequence |

Line coverage is reported to [Coveralls](https://coveralls.io/github/bleedingdeacons/beacon?branch=main)
on every CI run — see the coverage badge at the top of this file.

---

## License

GPL-2.0+
