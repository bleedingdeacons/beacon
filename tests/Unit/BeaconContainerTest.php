<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit;

use Psr\Container\NotFoundExceptionInterface;
use Beacon\Core\BeaconContainer;

it('round-trips set and get', function () {
    $c = new BeaconContainer();
    $c->set('answer', 42);

    expect($c->has('answer'))->toBeTrue()
        ->and($c->get('answer'))->toBe(42);
});

it('builds a factory lazily and caches the result', function () {
    $c = new BeaconContainer();
    $calls = 0;
    $c->factory('thing', function () use (&$calls) {
        $calls++;
        return new \stdClass();
    });

    expect($calls)->toBe(0, 'factory must not run at registration');
    $first = $c->get('thing');
    $second = $c->get('thing');

    expect($calls)->toBe(1, 'factory must run exactly once')
        ->and($first)->toBe($second, 'get must return the cached instance');
});

it('lets set override a prior factory', function () {
    // Implementation plugins overwrite Beacon's defaults — last
    // bind wins.
    $c = new BeaconContainer();
    $c->factory('driver', fn () => 'default');
    $c->set('driver', 'overridden');

    expect($c->get('driver'))->toBe('overridden');
});

it('throws a PSR not-found exception for a missing id', function () {
    // PHPUnit's expectException rather than Pest's ->throws(): the contract
    // here is the PSR interface, and Pest's throws()/toThrow() only treat a
    // concrete class as a type — given an interface, they fall back to
    // matching its name against the exception message and fail.
    $this->expectException(NotFoundExceptionInterface::class);

    $c = new BeaconContainer();
    $c->get('does-not-exist');
});
