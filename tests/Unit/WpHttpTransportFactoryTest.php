<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit;

use Beacon\Tests\Support\FakeWpHttp;
use Beacon\Transport\Interfaces\HttpTransport;
use Beacon\Transport\Interfaces\HttpTransportFactory;
use Beacon\Transport\WpHttpTransport;
use Beacon\Transport\WpHttpTransportFactory;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see WpHttpTransportFactory}.
 *
 * The factory's job is narrow: implement {@see HttpTransportFactory},
 * hand back a fresh {@see WpHttpTransport} every call, and apply its
 * configured defaults unless a per-call override is supplied. We prove
 * the configured values actually reach the wire by driving the
 * resulting transport against the WP HTTP API shims (see
 * tests/bootstrap.php) and inspecting the args it sent.
 */
final class WpHttpTransportFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        FakeWpHttp::reset();
    }

    public function test_it_implements_the_factory_contract(): void
    {
        self::assertInstanceOf(HttpTransportFactory::class, new WpHttpTransportFactory());
    }

    public function test_create_returns_an_http_transport(): void
    {
        $transport = (new WpHttpTransportFactory())->create();

        self::assertInstanceOf(HttpTransport::class, $transport);
        self::assertInstanceOf(WpHttpTransport::class, $transport);
    }

    public function test_each_create_call_returns_a_fresh_instance(): void
    {
        $factory = new WpHttpTransportFactory();

        $a = $factory->create();
        $b = $factory->create();

        // Distinct objects → independent cookie jars, so a session
        // established on one never leaks into the other.
        self::assertNotSame($a, $b);
    }

    public function test_factory_defaults_reach_the_transport(): void
    {
        FakeWpHttp::pushResponse(200, '');

        (new WpHttpTransportFactory(verifyTls: false, timeoutSeconds: 42, maxRedirects: 0))
            ->create()
            ->request('GET', 'https://pbx.example.com/');

        $args = FakeWpHttp::sentArgs(0);
        self::assertFalse($args['sslverify']);
        self::assertSame(42, $args['timeout']);
        self::assertSame(0, $args['redirection']);
    }

    public function test_per_call_overrides_win_over_factory_defaults(): void
    {
        FakeWpHttp::pushResponse(200, '');

        // Factory configured one way…
        (new WpHttpTransportFactory(verifyTls: true, timeoutSeconds: 15, maxRedirects: 5))
            // …but this specific transport asks for different knobs.
            ->create(verifyTls: false, timeoutSeconds: 99, maxRedirects: 0)
            ->request('GET', 'https://pbx.example.com/');

        $args = FakeWpHttp::sentArgs(0);
        self::assertFalse($args['sslverify']);
        self::assertSame(99, $args['timeout']);
        self::assertSame(0, $args['redirection']);
    }

    public function test_omitted_overrides_fall_back_to_factory_defaults(): void
    {
        FakeWpHttp::pushResponse(200, '');

        // Only maxRedirects is overridden; the rest must come from the
        // factory's configured defaults.
        (new WpHttpTransportFactory(verifyTls: false, timeoutSeconds: 30))
            ->create(maxRedirects: 1)
            ->request('GET', 'https://pbx.example.com/');

        $args = FakeWpHttp::sentArgs(0);
        self::assertFalse($args['sslverify']);  // factory default
        self::assertSame(30, $args['timeout']); // factory default
        self::assertSame(1, $args['redirection']); // per-call override
    }
}
