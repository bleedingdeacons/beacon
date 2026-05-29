<?php

declare(strict_types=1);

namespace Beacon\Capabilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Static bootstrap helper for activation / deactivation.
 *
 * Separated from HasCapabilities so PSR-4 autoloading resolves the
 * class. The activation hook in beacon.php `require_once`s this file
 * directly because the autoloader may not yet be registered when the
 * activation callback fires on a fresh install.
 *
 * Roles created by `register()`:
 *  - beacon_operator   — full control: create / delete / activate
 *                        rules, change targets, push config.
 *  - beacon_dispatcher — day-to-day rerouting: switch which target a
 *                        rule points at; can't create/delete rules
 *                        or change connection settings.
 *  - beacon_viewer     — read-only audit access.
 *
 * `register()` also layers the full capability set onto the
 * administrator role, so admins inherit everything by default.
 */
final class CapabilityBootstrap
{
    public const ROLE_OPERATOR = 'beacon_operator';
    public const ROLE_DISPATCHER = 'beacon_dispatcher';
    public const ROLE_VIEWER = 'beacon_viewer';

    /**
     * Create the Beacon roles, grant each its capability set, and
     * layer the full superset onto the administrator role. Idempotent.
     */
    public static function register(): void
    {
        foreach (self::roleCapabilities() as $roleSlug => $caps) {
            $capMap = array_fill_keys($caps, true);

            // add_role() returns null if the role already exists, so
            // we follow up with get_role()->add_cap() to top up any
            // caps that may have been added in a later release.
            add_role($roleSlug, self::roleDisplayName($roleSlug), $capMap);

            $role = get_role($roleSlug);
            if ($role) {
                foreach ($caps as $cap) {
                    if (!$role->has_cap($cap)) {
                        $role->add_cap($cap);
                    }
                }
            }
        }

        $admin = get_role('administrator');
        if ($admin) {
            foreach (self::allCapabilities() as $cap) {
                $admin->add_cap($cap);
            }
        }
    }

    /**
     * Strip the capabilities from every role and remove the custom
     * roles. Call on deactivation/uninstall.
     */
    public static function remove(): void
    {
        $roles = wp_roles();
        foreach ($roles->roles as $slug => $_) {
            $role = get_role($slug);
            if ($role) {
                foreach (self::allCapabilities() as $cap) {
                    $role->remove_cap($cap);
                }
            }
        }

        foreach (array_keys(self::roleCapabilities()) as $roleSlug) {
            remove_role($roleSlug);
        }
    }

    /**
     * @return array<int,string>
     */
    public static function allCapabilities(): array
    {
        return [
            'beacon_manage_forwarding',
            'beacon_route_forwarding',
            'beacon_view_forwarding',
            'beacon_push_config',
        ];
    }

    /**
     * Map of role slug → array of capability slugs the role gets.
     *
     * @return array<string,array<int,string>>
     */
    public static function roleCapabilities(): array
    {
        return [
            self::ROLE_OPERATOR => [
                'read',
                'beacon_manage_forwarding',
                'beacon_route_forwarding',
                'beacon_view_forwarding',
                'beacon_push_config',
            ],
            self::ROLE_DISPATCHER => [
                // Can switch existing rules between configured targets
                // and push the result upstream, but can't create or
                // delete rules or change the connection settings.
                'read',
                'beacon_route_forwarding',
                'beacon_view_forwarding',
                'beacon_push_config',
            ],
            self::ROLE_VIEWER => [
                // Read-only audit access.
                'read',
                'beacon_view_forwarding',
            ],
        ];
    }

    private static function roleDisplayName(string $slug): string
    {
        return match ($slug) {
            self::ROLE_OPERATOR => __('Forwarding Operator', 'beacon'),
            self::ROLE_DISPATCHER => __('Forwarding Dispatcher', 'beacon'),
            self::ROLE_VIEWER => __('Forwarding Viewer', 'beacon'),
            default => ucwords(str_replace('_', ' ', $slug)),
        };
    }
}
