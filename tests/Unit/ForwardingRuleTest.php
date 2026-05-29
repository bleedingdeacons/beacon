<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Beacon\Forwarding\Models\ForwardingRule;

final class ForwardingRuleTest extends TestCase
{
    public function test_defaults_to_match_any_and_enabled(): void
    {
        $rule = new ForwardingRule([]);

        $this->assertSame('', $rule->getId());
        $this->assertSame('any', $rule->getMatchType());
        $this->assertTrue($rule->isEnabled());
        $this->assertTrue($rule->isCatchall());
        $this->assertSame(0, $rule->getPriority());
    }

    public function test_unknown_match_types_coerce_to_any(): void
    {
        // A malformed rule should match everything (and therefore be
        // obvious in the UI) rather than silently match nothing.
        $rule = new ForwardingRule([
            'match' => ['type' => 'wakanda_only', 'value' => 'foo'],
        ]);

        $this->assertSame('any', $rule->getMatchType());
    }

    public function test_with_returns_a_modified_copy(): void
    {
        $original = new ForwardingRule([
            'id' => 'r-1',
            'target_id' => 't-old',
            'enabled' => true,
        ]);
        $derived = $original->with(['target_id' => 't-new']);

        // Original untouched (readonly enforces this at the language level).
        $this->assertSame('t-old', $original->getTargetId());
        $this->assertSame('t-new', $derived->getTargetId());
        $this->assertSame('r-1', $derived->getId());
    }

    public function test_to_array_round_trips(): void
    {
        $original = new ForwardingRule([
            'id' => 'r-7',
            'label' => 'After hours',
            'match' => ['type' => 'time_window', 'value' => ['from' => '18:00', 'to' => '08:00']],
            'target_id' => 'vm-1',
            'enabled' => false,
            'priority' => 5,
        ]);
        $rehydrated = new ForwardingRule($original->toArray());

        $this->assertEquals($original->toArray(), $rehydrated->toArray());
    }
}
