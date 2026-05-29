<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Container\NotFoundExceptionInterface;
use Beacon\Core\BeaconContainer;

final class BeaconContainerTest extends TestCase
{
    public function test_set_and_get_round_trip(): void
    {
        $c = new BeaconContainer();
        $c->set('answer', 42);

        $this->assertTrue($c->has('answer'));
        $this->assertSame(42, $c->get('answer'));
    }

    public function test_factory_builds_lazily_and_caches(): void
    {
        $c = new BeaconContainer();
        $calls = 0;
        $c->factory('thing', function () use (&$calls) {
            $calls++;
            return new \stdClass();
        });

        $this->assertSame(0, $calls, 'factory must not run at registration');
        $first = $c->get('thing');
        $second = $c->get('thing');

        $this->assertSame(1, $calls, 'factory must run exactly once');
        $this->assertSame($first, $second, 'get must return the cached instance');
    }

    public function test_set_overrides_prior_factory(): void
    {
        // Implementation plugins overwrite Beacon's defaults — last
        // bind wins.
        $c = new BeaconContainer();
        $c->factory('driver', fn () => 'default');
        $c->set('driver', 'overridden');

        $this->assertSame('overridden', $c->get('driver'));
    }

    public function test_missing_id_throws_psr_not_found(): void
    {
        $c = new BeaconContainer();
        $this->expectException(NotFoundExceptionInterface::class);
        $c->get('does-not-exist');
    }
}
