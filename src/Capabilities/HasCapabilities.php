<?php

declare(strict_types=1);

namespace Beacon\Capabilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared role / capability helpers for call-forwarding administration.
 *
 * The runtime permission-check API used by services. Role/capability
 * *creation* lives in {@see CapabilityBootstrap}, which the activation
 * hook calls directly.
 *
 * Roles understood by Beacon:
 *  - beacon_operator   — full control: create / delete / activate
 *                        rules, change targets, push config.
 *  - beacon_dispatcher — day-to-day rerouting: switch which target a
 *                        rule points at; can't create/delete rules.
 *  - beacon_viewer     — read-only audit access.
 */
trait HasCapabilities
{
    /**
     * @return array<int,string>
     */
    private static function allCapabilities(): array
    {
        return CapabilityBootstrap::allCapabilities();
    }

    protected function userIsOperator(int $userId = 0): bool
    {
        $user = $userId ? get_userdata($userId) : wp_get_current_user();
        return $user && $user->has_cap('beacon_manage_forwarding');
    }

    protected function userIsDispatcher(int $userId = 0): bool
    {
        $user = $userId ? get_userdata($userId) : wp_get_current_user();
        return $user && $user->has_cap('beacon_route_forwarding');
    }

    protected function userCanView(int $userId = 0): bool
    {
        $user = $userId ? get_userdata($userId) : wp_get_current_user();
        return $user && $user->has_cap('beacon_view_forwarding');
    }
}
