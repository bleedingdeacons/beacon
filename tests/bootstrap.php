<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for Beacon.
 *
 * WordPress stand-ins come from bleedingdeacons/wp-mocks, shared across the
 * plugin suite. Its bootstrap loads Patchwork before anything patchable, so
 * anything below that defines WordPress functions of its own must stay after
 * the Bootstrap::load() call, not before it.
 *
 * The `rest` group is loaded for ForwardingRestControllerTest, which calls the
 * controller's route callbacks directly with a WP_REST_Request and asserts on
 * the WP_REST_Response they hand back.
 *
 * Not loaded here: the `sentinel` stub group. Beacon\Logger\HasLogger is
 * written to no-op when wp_log() is absent — the shared logger mu-plugin is
 * Sentinel's, and Beacon does not depend on it — and that is the branch these
 * tests run.
 *
 * The WP HTTP API the transport talks to is stubbed by wp-mocks too, backed by
 * Doubles\FakeWpHttp: the transport tests queue responses on it and then assert
 * on what was sent — cookie continuity, header merging, request shape.
 */

use BleedingDeacons\WpMocks\Bootstrap;
use BleedingDeacons\WpMocks\WpState;

require_once __DIR__ . '/../vendor/autoload.php';

Bootstrap::load(['wordpress', 'rest']);

// Makes plugins_url()/plugin_dir_url() answer with Beacon's own path.
WpState::$pluginSlug = 'beacon';

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

$src = __DIR__ . '/../src';
require_once $src . '/Core/BeaconContainer.php';
require_once $src . '/Forwarding/Interfaces/CallForwardingService.php';
require_once $src . '/Forwarding/Interfaces/ForwardingException.php';
require_once $src . '/Forwarding/Models/ForwardingRule.php';
require_once $src . '/Forwarding/AbstractCallForwardingService.php';
require_once $src . '/Targets/Models/ForwardingTarget.php';
require_once $src . '/Transport/Interfaces/HttpTransport.php';
require_once $src . '/Transport/Interfaces/TransportException.php';
require_once $src . '/Transport/Interfaces/HttpTransportFactory.php';
require_once $src . '/Transport/WpHttpTransport.php';
require_once $src . '/Transport/WpHttpTransportFactory.php';
