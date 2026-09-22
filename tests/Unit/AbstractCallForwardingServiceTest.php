<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit;

use Beacon\Forwarding\AbstractCallForwardingService;
use Beacon\Forwarding\Interfaces\ForwardingException;
use Beacon\Forwarding\Models\ForwardingRule;

/**
 * Test-only subclass that exposes the protected validation method.
 *
 * The abstract is meant to be extended by drivers; tests are the
 * cleanest way to exercise its protected surface without making
 * those methods public on every concrete driver.
 */
final class TestableService extends AbstractCallForwardingService
{
    public function listRules(): array
    {
        return [];
    }
    public function findRule(string $ruleId): ?ForwardingRule
    {
        return null;
    }
    public function saveRule(ForwardingRule $rule): string
    {
        return '';
    }
    public function deleteRule(string $ruleId): bool
    {
        return false;
    }
    public function listTargets(): array
    {
        return [];
    }
    public function commit(): bool
    {
        return true;
    }
    public function testConnection(): bool
    {
        return true;
    }

    // Expose protected method for testing.
    public function exposeValidate(ForwardingRule $rule): void
    {
        $this->validateRule($rule);
    }
}

it('throws for a rule without a target', function () {
    $service = new TestableService();
    $rule = new ForwardingRule(['target_id' => '']);

    $service->exposeValidate($rule);
})->throws(ForwardingException::class, 'no target');

it('passes an any rule through validation', function () {
    $service = new TestableService();
    $rule = new ForwardingRule([
        'target_id' => 't-1',
        'match' => ['type' => 'any'],
    ]);

    expect(fn () => $service->exposeValidate($rule))->not->toThrow(ForwardingException::class); // didn't throw
});

it('requires a plausible number for a source number rule', function () {
    $service = new TestableService();
    $rule = new ForwardingRule([
        'target_id' => 't-1',
        'match' => ['type' => 'source_number', 'value' => 'x'],
    ]);

    $service->exposeValidate($rule);
})->throws(ForwardingException::class);

it('throws for a time window with a bad format', function () {
    $service = new TestableService();
    $rule = new ForwardingRule([
        'target_id' => 't-1',
        'match' => ['type' => 'time_window', 'value' => ['from' => '25:00', 'to' => '09:00']],
    ]);

    $service->exposeValidate($rule);
})->throws(ForwardingException::class, 'HH:MM');

it('requires a caller id list to be non-empty', function () {
    $service = new TestableService();
    $rule = new ForwardingRule([
        'target_id' => 't-1',
        'match' => ['type' => 'caller_id_list', 'value' => []],
    ]);

    $service->exposeValidate($rule);
})->throws(ForwardingException::class);

it('allows a time window that wraps midnight', function () {
    // 18:00 → 08:00 means "overnight", and many PBXes support it.
    // We pass it through rather than guessing.
    $service = new TestableService();
    $rule = new ForwardingRule([
        'target_id' => 't-1',
        'match' => ['type' => 'time_window', 'value' => ['from' => '18:00', 'to' => '08:00']],
    ]);

    expect(fn () => $service->exposeValidate($rule))->not->toThrow(ForwardingException::class);
});
