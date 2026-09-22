<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit;

use Beacon\Forwarding\ForwardingRegistry;
use Beacon\Forwarding\Interfaces\CallForwardingService;

beforeEach(fn () => ForwardingRegistry::clear());
afterEach(fn () => ForwardingRegistry::clear());

it('reports no driver until one is bound', function () {
    expect(ForwardingRegistry::has())->toBeFalse()
        ->and(ForwardingRegistry::get())->toBeNull();
});

it('does not run the resolver when a driver is bound or checked', function () {
    $calls = 0;
    ForwardingRegistry::bind(function () use (&$calls): CallForwardingService {
        $calls++;
        return \Mockery::mock(CallForwardingService::class);
    });

    expect(ForwardingRegistry::has())->toBeTrue()
        ->and($calls)->toBe(0);
});

it('resolves the bound driver on demand', function () {
    $driver = \Mockery::mock(CallForwardingService::class);
    ForwardingRegistry::bind(fn (): CallForwardingService => $driver);

    expect(ForwardingRegistry::get())->toBe($driver);
});

it('lets a later bind replace an earlier one', function () {
    $first = \Mockery::mock(CallForwardingService::class);
    $second = \Mockery::mock(CallForwardingService::class);
    ForwardingRegistry::bind(fn (): CallForwardingService => $first);
    ForwardingRegistry::bind(fn (): CallForwardingService => $second);

    expect(ForwardingRegistry::get())->toBe($second);
});
