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
require_once __DIR__ . '/../src/Transport/Interfaces/HttpTransportFactory.php';

// --- WP HTTP API shims (for the WpHttpTransport tests) ------------------
//
// WpHttpTransport talks to the WP HTTP API. We shim just enough of it,
// backed by a scriptable fake (Beacon\Tests\Support\FakeWpHttp) that the
// transport tests drive. All shims are class_exists/function_exists
// guarded so a real WordPress test environment can supply the genuine
// articles instead.

require_once __DIR__ . '/Support/FakeWpHttp.php';

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        /** @param mixed $data */
        public function __construct(
            private string $code = '',
            private string $message = '',
            private mixed $data = null,
        ) {
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        /** @return mixed */
        public function get_error_data()
        {
            return $this->data;
        }
    }
}

// --- WP REST API shims (for the ForwardingRestController tests) ---------
//
// Just enough of WP_REST_Request / WP_REST_Response to drive the
// controller's route callbacks directly in a unit test. Guarded so a
// real WordPress test environment supplies the genuine classes.

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        /** @param array<string,mixed> $params */
        public function __construct(private array $params = [])
        {
        }

        /** @return mixed */
        public function get_param(string $key)
        {
            return $this->params[$key] ?? null;
        }

        /** @param mixed $value */
        public function set_param(string $key, $value): void
        {
            $this->params[$key] = $value;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        /** @param mixed $data */
        public function __construct(private mixed $data = null, private int $status = 200)
        {
        }

        /** @return mixed */
        public function get_data()
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }
    }
}

if (!class_exists('WP_Http_Cookie')) {
    class WP_Http_Cookie
    {
        public string $name = '';
        public string $value = '';

        /** @param array<string,mixed> $args */
        public function __construct(array $args = [])
        {
            $this->name  = (string) ($args['name'] ?? '');
            $this->value = (string) ($args['value'] ?? '');
        }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool
    {
        return $thing instanceof \WP_Error;
    }
}

if (!function_exists('wp_remote_request')) {
    function wp_remote_request(string $url, array $args = [])
    {
        return \Beacon\Tests\Support\FakeWpHttp::dispatch($url, $args);
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response)
    {
        if ($response instanceof \WP_Error) {
            return '';
        }
        return $response['response']['code'] ?? '';
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response): string
    {
        if ($response instanceof \WP_Error) {
            return '';
        }
        return (string) ($response['body'] ?? '');
    }
}

if (!function_exists('wp_remote_retrieve_headers')) {
    function wp_remote_retrieve_headers($response)
    {
        if ($response instanceof \WP_Error) {
            return [];
        }
        return $response['headers'] ?? [];
    }
}

if (!function_exists('wp_remote_retrieve_cookies')) {
    function wp_remote_retrieve_cookies($response): array
    {
        if ($response instanceof \WP_Error) {
            return [];
        }
        return $response['cookies'] ?? [];
    }
}

require_once __DIR__ . '/../src/Transport/WpHttpTransport.php';
require_once __DIR__ . '/../src/Transport/WpHttpTransportFactory.php';
