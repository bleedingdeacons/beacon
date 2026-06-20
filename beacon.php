<?php

declare(strict_types=1);

/**
 * Plugin Name: Beacon
 * Description: Abstract interface plugin for call-forwarding systems. Defines the contracts (CallForwardingService, models, transport) that implementation plugins (e.g. Anchor) bind concrete drivers against. Ships no driver of its own — Beacon alone does nothing visible until an implementation plugin is active.
 * Version: 1.1.5
 * Requires at least: 6.1
 * Requires PHP: 8.1
 * GitHub Plugin URI: https://github.com/thebleedingdeacons/beacon
 * GitHub Branch: main
 * Author: The Bleeding Deacons
 * Author URI: https://github.com/bleedingdeacons/beacon
 * Contact: thebleedingdeacons@gmail.com
 * License: MIT (Modified)
 * Text Domain: beacon
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Kill switch.
 *
 * Set `define('BEACON_KILL', true);` in wp-config.php to deactivate
 * Beacon without removing it from the active plugins list. Mirrors
 * Stalwart's pattern — when enabled, Beacon short-circuits here and
 * `beacon/loaded` never fires, so Anchor (and any other downstream
 * implementations) stand down too.
 */
if (defined('BEACON_KILL') && BEACON_KILL === true) {
    if (is_admin()) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-warning"><p>'
                . '<strong>Beacon:</strong> Plugin is disabled via the '
                . '<code>BEACON_KILL</code> kill switch in <code>wp-config.php</code>.'
                . '</p></div>';
        });
    }
    return;
}

// Define plugin constants
if (!function_exists('get_plugin_data')) {
    if (file_exists(ABSPATH . 'wp-admin/includes/plugin.php')) {
        require_once(ABSPATH . 'wp-admin/includes/plugin.php');
    }
}

$beacon_plugin_data = get_plugin_data(__FILE__, false, false);
define('BEACON_VERSION', $beacon_plugin_data['Version']);
define('BEACON_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BEACON_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BEACON_PLUGIN_FILE', __FILE__);

// Load Composer autoloader if present.
$beacon_autoloader = BEACON_PLUGIN_DIR . 'vendor/autoload.php';
if (file_exists($beacon_autoloader)) {
    require_once $beacon_autoloader;
}

// Fallback PSR-4 autoloader for the Beacon namespace. Lets the plugin
// run on a fresh deployment before `composer install` has been executed.
spl_autoload_register(function ($class) {
    $prefix = 'Beacon\\';
    $base_dir = BEACON_PLUGIN_DIR . 'src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

/**
 * Resolve a service out of the Beacon container.
 *
 * @return \Psr\Container\ContainerInterface
 * @throws \RuntimeException If Beacon is not initialised yet.
 */
function beacon(): \Psr\Container\ContainerInterface {
    return \Beacon\Plugin::getContainer();
}

// Boot on `plugins_loaded` so any container-providing plugin (e.g.
// Unity) has had a chance to register itself first. Beacon will use a
// shared container if one is offered via the `beacon/container` filter,
// or fall back to its own minimal PSR-11 container otherwise — this
// keeps Beacon usable both standalone and inside a larger ecosystem.
add_action('plugins_loaded', function () {
    try {
        if (!class_exists('Beacon\\Plugin')) {
            throw new \Exception('Beacon\\Plugin class not found. Check that Plugin.php exists in the src/ directory.');
        }

        \Beacon\Plugin::init();

        /**
         * Fires after Beacon has registered its contracts with the
         * container. Implementation plugins (Anchor, etc.) hook this to
         * bind their concrete drivers.
         *
         * @param \Psr\Container\ContainerInterface $container The shared dependency container
         */
        do_action('beacon/loaded', \Beacon\Plugin::getContainer());

    } catch (\Exception $e) {
        function_exists('wp_log')
            ? wp_log('beacon')->error('Beacon Plugin Initialisation Error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()])
            : error_log('Beacon Plugin Initialisation Error: ' . $e->getMessage());

        if (is_admin()) {
            add_action('admin_notices', function () use ($e) {
                echo '<div class="notice notice-error is-dismissible"><p><strong>Beacon Plugin Error:</strong> ' . esc_html($e->getMessage()) . '</p></div>';
            });
        }
    } catch (\Throwable $e) {
        function_exists('wp_log')
            ? wp_log('beacon')->critical('Beacon Plugin Fatal Error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()])
            : error_log('Beacon Plugin Fatal Error: ' . $e->getMessage());
    }
}, 5);

// Surface a notice if no implementation plugin has bound a driver.
// We can't check this until `init` because implementations bind on
// `beacon/loaded`, which fires during `plugins_loaded`.
add_action('admin_notices', function () {
    if (!did_action('beacon/loaded')) {
        return;
    }
    if (!\Beacon\Plugin::isInitialized()) {
        return;
    }
    if (\Beacon\Plugin::hasDriver()) {
        return;
    }
    echo '<div class="notice notice-warning is-dismissible"><p>'
        . '<strong>Beacon:</strong> No call-forwarding driver is bound. Install and activate an implementation plugin (e.g. <em>Anchor</em>) to wire Beacon up to a real system.'
        . '</p></div>';
});

// Activation: register capabilities.
register_activation_hook(__FILE__, function () {
    require_once BEACON_PLUGIN_DIR . 'src/Capabilities/CapabilityBootstrap.php';
    \Beacon\Capabilities\CapabilityBootstrap::register();
});

register_deactivation_hook(__FILE__, function () {
    require_once BEACON_PLUGIN_DIR . 'src/Capabilities/CapabilityBootstrap.php';
    \Beacon\Capabilities\CapabilityBootstrap::remove();
});
