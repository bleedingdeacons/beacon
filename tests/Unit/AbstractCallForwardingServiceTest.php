<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit;

use PHPUnit\Framework\TestCase;
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
    public function listRules(): array { return []; }
    public function findRule(string $ruleId): ?ForwardingRule { return null; }
    public function saveRule(ForwardingRule $rule): string { return ''; }
    public function deleteRule(string $ruleId): bool { return false; }
    public function listTargets(): array { return []; }
    public function commit(): bool { return true; }
    public function testConnection(): bool { return true; }

    // Expose protected method for testing.
    public function exposeValidate(ForwardingRule $rule): void
    {
        $this->validateRule($rule);
    }
}

final class AbstractCallForwardingServiceTest extends TestCase
{
    public function test_rule_without_target_throws(): void
    {
        $service = new TestableService();
        $rule = new ForwardingRule(['target_id' => '']);

        $this->expectException(ForwardingException::class);
        $this->expectExceptionMessage('no target');
        $service->exposeValidate($rule);
    }

    public function test_any_rule_passes_validation(): void
    {
        $service = new TestableService();
        $rule = new ForwardingRule([
            'target_id' => 't-1',
            'match' => ['type' => 'any'],
        ]);

        $service->exposeValidate($rule);
        $this->assertTrue(true); // didn't throw
    }

    public function test_source_number_rule_requires_plausible_number(): void
    {
        $service = new TestableService();
        $rule = new ForwardingRule([
            'target_id' => 't-1',
            'match' => ['type' => 'source_number', 'value' => 'x'],
        ]);

        $this->expectException(ForwardingException::class);
        $service->exposeValidate($rule);
    }

    public function test_time_window_with_bad_format_throws(): void
    {
        $service = new TestableService();
        $rule = new ForwardingRule([
            'target_id' => 't-1',
            'match' => ['type' => 'time_window', 'value' => ['from' => '25:00', 'to' => '09:00']],
        ]);

        $this->expectException(ForwardingException::class);
        $this->expectExceptionMessage('HH:MM');
        $service->exposeValidate($rule);
    }

    public function test_caller_id_list_must_be_non_empty(): void
    {
        $service = new TestableService();
        $rule = new ForwardingRule([
            'target_id' => 't-1',
            'match' => ['type' => 'caller_id_list', 'value' => []],
        ]);

        $this->expectException(ForwardingException::class);
        $service->exposeValidate($rule);
    }

    public function test_wrapping_midnight_time_window_is_allowed(): void
    {
        // 18:00 → 08:00 means "overnight", and many PBXes support it.
        // We pass it through rather than guessing.
        $service = new TestableService();
        $rule = new ForwardingRule([
            'target_id' => 't-1',
            'match' => ['type' => 'time_window', 'value' => ['from' => '18:00', 'to' => '08:00']],
        ]);

        $service->exposeValidate($rule);
        $this->assertTrue(true);
    }
}
