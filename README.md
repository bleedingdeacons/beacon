# Beacon — Call-Forwarding Contracts

[![CI](https://github.com/bleedingdeacons/beacon/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/bleedingdeacons/beacon/actions/workflows/ci.yml)
![Version](https://img.shields.io/badge/version-1.1.9-blue)
![PHP](https://img.shields.io/badge/php-8.1%2B-777bb4)
![Licence](https://img.shields.io/badge/licence-MIT%20(Modified)-green)

PSR-compliant contract layer for WordPress-administered call forwarding. Ships interfaces, value objects, and shared traits; an implementation plugin (e.g. **Anchor**) provides the concrete driver and wires everything into the shared container.

## Architecture

```
plugins_loaded  ──▶  Beacon  ──fires──▶  beacon/loaded  ──▶  Anchor
                     (contracts)                            (implementation)
```

- **Beacon** registers contracts (`CallForwardingService`, `HttpTransport`) into a PSR-11 container and fires `beacon/loaded`.
- **Anchor** binds a concrete driver against those contracts on `beacon/loaded`. The driver fetches an HTML configuration page from the upstream PBX, parses it, and POSTs back changes to apply forwarding rules.

This separation lets you swap implementations (or stand up tests with mocks) without touching consumers, and gives the same Beacon-shaped admin UI to every PBX the project ends up integrating with.

## Beacon ships only contracts

| | |
|---|---|
| `Beacon\Forwarding\Interfaces\CallForwardingService` | The driver contract — list/save/delete rules, list targets, commit, test. |
| `Beacon\Forwarding\Interfaces\ForwardingException` | Common throwable for driver failures. |
| `Beacon\Forwarding\Models\ForwardingRule` | Immutable value object: match condition + target. |
| `Beacon\Targets\Models\ForwardingTarget` | Immutable value object: destination (number/extension/voicemail). |
| `Beacon\Forwarding\AbstractCallForwardingService` | Shared validation + hydration drivers can extend. |
| `Beacon\Transport\Interfaces\HttpTransport` | Abstract HTTP layer so drivers stay testable. |

Beacon itself never opens a socket and never knows about your PBX.

## Installation

```bash
composer install
```

Activate Beacon, then activate an implementation plugin (e.g. Tamar). Beacon alone does nothing visible — it will surface an admin notice when no driver is bound.

## Hooks

| Hook | Params | When |
|---|---|---|
| `beacon/container` (filter) | `?ContainerInterface` | Lets a host plugin (e.g. Unity) supply a shared PSR-11 container. Return your container to have Beacon use it. |
| `beacon/register_services` | `ContainerInterface` | During Beacon's service-provider registration. Implementations should hook this for early bindings. |
| `beacon/loaded` | `ContainerInterface` | After Beacon has registered everything. Implementations bind their driver here. |

## Capabilities

| Capability | Granted to |
|---|---|
| `beacon_manage_forwarding` | Operator only — create / delete rules, change connection settings. |
| `beacon_route_forwarding`  | Operator + Dispatcher — switch existing rules between targets. |
| `beacon_push_config`       | Operator + Dispatcher — commit pending changes upstream. |
| `beacon_view_forwarding`   | Operator + Dispatcher + Viewer — read-only audit. |

## File layout

```
beacon/
├── beacon.php                   Bootstrap
├── uninstall.php                Capability cleanup
├── composer.json                PSR-4 autoload
├── src/
│   ├── Plugin.php               Boots container, fires beacon/loaded
│   ├── Core/
│   │   ├── BeaconContainer.php       Minimal PSR-11 container
│   │   └── BeaconServiceProvider.php
│   ├── Logger/HasLogger.php
│   ├── Capabilities/HasCapabilities.php
│   ├── Forwarding/
│   │   ├── Interfaces/CallForwardingService.php
│   │   ├── Interfaces/ForwardingException.php
│   │   ├── Models/ForwardingRule.php
│   │   └── AbstractCallForwardingService.php
│   ├── Targets/Models/ForwardingTarget.php
│   └── Transport/
│       ├── Interfaces/HttpTransport.php
│       └── Interfaces/TransportException.php
└── tests/
    └── Unit/
```

## Requirements

- WordPress 6.1+
- PHP 8.1+

## License

GPL-2.0+
