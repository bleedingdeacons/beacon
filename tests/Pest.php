<?php

declare(strict_types=1);

// Pest configuration.
//
// Every test file in this suite ran on wp-mocks' TestCase when it was PHPUnit,
// and every one still does. Even the pure value-object tests (ForwardingRule,
// BeaconContainer) extended it, and the rest genuinely need it: the transport
// tests read home_url() and the WP HTTP API from the shared stubs, and the
// REST controller tests build WP_REST_Request / WP_REST_Response / WP_Error.
// wp-mocks' TestCase is what sets Brain Monkey up and tears it down around
// each test, and carries the Mockery integration.
//
// So the whole Unit directory is bound here. If a pure-PHP test is ever added
// that should run on plain PHPUnit instead, this has to become a list of the
// files that need wp-mocks rather than the directory.

use BleedingDeacons\WpMocks\TestCase;

pest()->extend(TestCase::class)->in('Unit');
