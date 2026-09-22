<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit;

use Beacon\Forwarding\Models\ForwardingRule;

it('defaults to match any and enabled', function () {
    $rule = new ForwardingRule([]);

    expect($rule->getId())->toBe('')
        ->and($rule->getMatchType())->toBe('any')
        ->and($rule->isEnabled())->toBeTrue()
        ->and($rule->isCatchall())->toBeTrue()
        ->and($rule->getPriority())->toBe(0);
});

it('coerces unknown match types to any', function () {
    // A malformed rule should match everything (and therefore be
    // obvious in the UI) rather than silently match nothing.
    $rule = new ForwardingRule([
        'match' => ['type' => 'wakanda_only', 'value' => 'foo'],
    ]);

    expect($rule->getMatchType())->toBe('any');
});

it('returns a modified copy from with()', function () {
    $original = new ForwardingRule([
        'id' => 'r-1',
        'target_id' => 't-old',
        'enabled' => true,
    ]);
    $derived = $original->with(['target_id' => 't-new']);

    // Original untouched (readonly enforces this at the language level).
    expect($original->getTargetId())->toBe('t-old')
        ->and($derived->getTargetId())->toBe('t-new')
        ->and($derived->getId())->toBe('r-1');
});

it('round-trips through toArray()', function () {
    $original = new ForwardingRule([
        'id' => 'r-7',
        'label' => 'After hours',
        'match' => ['type' => 'time_window', 'value' => ['from' => '18:00', 'to' => '08:00']],
        'target_id' => 'vm-1',
        'enabled' => false,
        'priority' => 5,
    ]);
    $rehydrated = new ForwardingRule($original->toArray());

    expect($rehydrated->toArray())->toEqual($original->toArray());
});
