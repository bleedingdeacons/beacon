<?php

/**
 * Fired when Beacon is uninstalled.
 *
 * Only removes capabilities. Forwarding rules, target lists and any
 * driver-specific data are owned by the implementation plugin
 * (Anchor, etc.) and cleaned up by its own uninstaller.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/src/Capabilities/CapabilityBootstrap.php';

\Beacon\Capabilities\CapabilityBootstrap::remove();
