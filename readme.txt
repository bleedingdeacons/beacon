=== Beacon ===
Contributors: thebleedingdeacons
Tags: call-forwarding, contracts, interfaces, telephony, pbx
Requires at least: 6.1
Tested up to: 6.9
Stable tag: 1.1.1
Build date: 2026/06/06
Requires PHP: 8.1
License: MIT (Modified — No Resale)

Abstract interface plugin for call-forwarding systems. Defines contracts; an implementation plugin provides the concrete driver.

== Description ==

Beacon is the contract layer for WordPress-administered call forwarding. It ships interfaces, value objects, and shared traits; an implementation plugin (e.g. **Tamar**) provides the concrete driver and wires everything into the shared container.

Beacon itself never opens a socket and never knows about your PBX — it only defines the shape every driver must satisfy. This separation lets you swap implementations (or stand up tests with mocks) without touching consumers, and gives the same Beacon-shaped admin UI to every PBX the project ends up integrating with.

**Key components:**

* `Beacon\Forwarding\Interfaces\CallForwardingService` — the driver contract (list/save/delete rules, list targets, commit, test).
* `Beacon\Forwarding\Interfaces\ForwardingException` — common throwable for driver failures.
* `Beacon\Forwarding\Models\ForwardingRule` — immutable value object: match condition + target.
* `Beacon\Targets\Models\ForwardingTarget` — immutable value object: destination (number/extension/voicemail).
* `Beacon\Forwarding\AbstractCallForwardingService` — shared validation + hydration drivers can extend.
* `Beacon\Transport\Interfaces\HttpTransport` — abstract HTTP layer so drivers stay testable.

== Installation ==

1. Upload the `beacon` directory to `/wp-content/plugins/`.
2. Activate Beacon through the **Plugins** menu in WordPress.
3. Install and activate an implementation plugin (e.g. Tamar) — Beacon alone does nothing visible until a driver is bound.

== Frequently Asked Questions ==

= Does Beacon do anything on its own? =

No. Beacon ships only contracts. You must install an implementation plugin that binds a concrete driver on the `beacon/loaded` action.

= How do I disable Beacon without deactivating it? =

Define `BEACON_KILL` as `true` in `wp-config.php`. Beacon short-circuits before firing `beacon/loaded`, so any implementation plugin stands down too.

== Changelog ==

= 1.0.0 =
* Initial release.
