<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit;

use BleedingDeacons\WpMocks\Doubles\FakeWpHttp;
use Beacon\Transport\Interfaces\HttpTransport;
use Beacon\Transport\Interfaces\HttpTransportFactory;
use Beacon\Transport\WpHttpTransport;
use Beacon\Transport\WpHttpTransportFactory;

/*
 * Unit tests for {@see WpHttpTransportFactory}.
 *
 * The factory's job is narrow: implement {@see HttpTransportFactory},
 * hand back a fresh {@see WpHttpTransport} every call, and apply its
 * configured defaults unless a per-call override is supplied. We prove
 * the configured values actually reach the wire by driving the
 * resulting transport against the WP HTTP API shims (see
 * tests/bootstrap.php) and inspecting the args it sent.
 */

beforeEach(function () {
    FakeWpHttp::reset();
});

it('implements the factory contract', function () {
    expect(new WpHttpTransportFactory())->toBeInstanceOf(HttpTransportFactory::class);
});

it('returns an http transport from create()', function () {
    $transport = (new WpHttpTransportFactory())->create();

    expect($transport)->toBeInstanceOf(HttpTransport::class)
        ->toBeInstanceOf(WpHttpTransport::class);
});

it('returns a fresh instance from each create() call', function () {
    $factory = new WpHttpTransportFactory();

    $a = $factory->create();
    $b = $factory->create();

    // Distinct objects → independent cookie jars, so a session
    // established on one never leaks into the other.
    expect($a)->not->toBe($b);
});

it('passes the factory defaults through to the transport', function () {
    FakeWpHttp::pushResponse(200, '');

    (new WpHttpTransportFactory(verifyTls: false, timeoutSeconds: 42, maxRedirects: 0))
        ->create()
        ->request('GET', 'https://pbx.example.com/');

    $args = FakeWpHttp::sentArgs(0);
    expect($args['sslverify'])->toBeFalse()
        ->and($args['timeout'])->toBe(42)
        ->and($args['redirection'])->toBe(0);
});

it('lets per-call overrides win over the factory defaults', function () {
    FakeWpHttp::pushResponse(200, '');

    // Factory configured one way…
    (new WpHttpTransportFactory(verifyTls: true, timeoutSeconds: 15, maxRedirects: 5))
        // …but this specific transport asks for different knobs.
        ->create(verifyTls: false, timeoutSeconds: 99, maxRedirects: 0)
        ->request('GET', 'https://pbx.example.com/');

    $args = FakeWpHttp::sentArgs(0);
    expect($args['sslverify'])->toBeFalse()
        ->and($args['timeout'])->toBe(99)
        ->and($args['redirection'])->toBe(0);
});

it('falls back to the factory defaults for omitted overrides', function () {
    FakeWpHttp::pushResponse(200, '');

    // Only maxRedirects is overridden; the rest must come from the
    // factory's configured defaults.
    (new WpHttpTransportFactory(verifyTls: false, timeoutSeconds: 30))
        ->create(maxRedirects: 1)
        ->request('GET', 'https://pbx.example.com/');

    $args = FakeWpHttp::sentArgs(0);
    expect($args['sslverify'])->toBeFalse()      // factory default
        ->and($args['timeout'])->toBe(30)        // factory default
        ->and($args['redirection'])->toBe(1);    // per-call override
});
