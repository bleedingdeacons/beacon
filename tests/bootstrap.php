<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for Beacon.
 *
 * Defines ABSPATH so source files (which guard against direct access
 * via `if (!defined('ABSPATH')) exit;`) load under PHPUnit.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// PSR-11 contracts (stubbed for testing — real installs use composer).
require_once __DIR__ . '/stubs/Psr/Container/ContainerExceptionInterface.php';
require_once __DIR__ . '/stubs/Psr/Container/NotFoundExceptionInterface.php';
require_once __DIR__ . '/stubs/Psr/Container/ContainerInterface.php';

require_once __DIR__ . '/../src/Core/BeaconContainer.php';
require_once __DIR__ . '/../src/Forwarding/Interfaces/CallForwardingService.php';
require_once __DIR__ . '/../src/Forwarding/Interfaces/ForwardingException.php';
require_once __DIR__ . '/../src/Forwarding/Models/ForwardingRule.php';
require_once __DIR__ . '/../src/Forwarding/AbstractCallForwardingService.php';
require_once __DIR__ . '/../src/Targets/Models/ForwardingTarget.php';
require_once __DIR__ . '/../src/Transport/Interfaces/HttpTransport.php';
require_once __DIR__ . '/../src/Transport/Interfaces/TransportException.php';
