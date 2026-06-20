<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit;

use Beacon\Core\BeaconContainer;
use Beacon\Forwarding\Interfaces\CallForwardingService;
use Beacon\Forwarding\Interfaces\ForwardingException;
use Beacon\Forwarding\Models\ForwardingRule;
use Beacon\Rest\ForwardingRestController;
use Beacon\Targets\Models\ForwardingTarget;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the controller's route callbacks directly against a fake
 * driver — the REST plumbing (route registration, permissions) is thin
 * WP glue; the behaviour worth testing is the model serialisation and
 * the no-driver / driver-error mapping.
 */
final class ForwardingRestControllerTest extends TestCase
{
    public function test_listRules_serialises_rules(): void
    {
        $svc = new FakeForwardingService(rules: [
            new ForwardingRule(['id' => '1', 'label' => 'Day', 'target_id' => 'num:123', 'match' => ['type' => 'any']]),
        ]);
        $resp = $this->controllerWith($svc)->listRules(new \WP_REST_Request());

        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        self::assertSame(200, $resp->get_status());
        $data = $resp->get_data();
        self::assertCount(1, $data);
        self::assertSame('1', $data[0]['id']);
        self::assertSame('num:123', $data[0]['target_id']);
    }

    public function test_listTargets_serialises_targets(): void
    {
        $svc = new FakeForwardingService(targets: [
            new ForwardingTarget(['id' => 'num:123', 'kind' => 'number', 'label' => 'Steve', 'address' => '0123']),
        ]);
        $resp = $this->controllerWith($svc)->listTargets(new \WP_REST_Request());

        self::assertSame('num:123', $resp->get_data()[0]['id']);
        self::assertSame('number', $resp->get_data()[0]['kind']);
    }

    public function test_getRule_returns_404_when_absent(): void
    {
        $svc = new FakeForwardingService(rules: []);
        $resp = $this->controllerWith($svc)->getRule(new \WP_REST_Request(['id' => '999']));

        self::assertInstanceOf(\WP_Error::class, $resp);
        self::assertSame('beacon_rule_not_found', $resp->get_error_code());
        self::assertSame(404, $resp->get_error_data()['status']);
    }

    public function test_createRule_forces_empty_id_and_returns_new_id(): void
    {
        $svc = new FakeForwardingService();
        $resp = $this->controllerWith($svc)->createRule(new \WP_REST_Request([
            'id' => 'ignored',
            'label' => 'New',
            'target_id' => 'num:555',
            'match' => ['type' => 'time_window', 'value' => ['from' => '09:00', 'to' => '17:00', 'days' => ['mon']]],
        ]));

        self::assertInstanceOf(\WP_REST_Response::class, $resp);
        self::assertSame(201, $resp->get_status());
        self::assertSame('99', $resp->get_data()['id']);
        // The id from the URL/body must be ignored on create — the rule
        // handed to the driver carries an empty id.
        self::assertSame('', $svc->saved[0]->getId());
        self::assertSame('num:555', $svc->saved[0]->getTargetId());
    }

    public function test_updateRule_uses_path_id(): void
    {
        $svc = new FakeForwardingService();
        $resp = $this->controllerWith($svc)->updateRule(new \WP_REST_Request([
            'id' => '2',
            'label' => 'Edited',
            'target_id' => 'num:777',
            'match' => ['type' => 'any'],
        ]));

        self::assertSame(200, $resp->get_status());
        self::assertSame('2', $resp->get_data()['id']);
        self::assertSame('2', $svc->saved[0]->getId());
    }

    public function test_deleteRule_reports_outcome(): void
    {
        $svc = new FakeForwardingService();
        $resp = $this->controllerWith($svc)->deleteRule(new \WP_REST_Request(['id' => '1']));

        self::assertSame(['id' => '1', 'deleted' => true], $resp->get_data());
        self::assertSame(['1'], $svc->deleted);
    }

    public function test_returns_503_when_no_driver_is_bound(): void
    {
        $controller = new ForwardingRestController(new BeaconContainer());
        $resp = $controller->listRules(new \WP_REST_Request());

        self::assertInstanceOf(\WP_Error::class, $resp);
        self::assertSame('beacon_no_driver', $resp->get_error_code());
        self::assertSame(503, $resp->get_error_data()['status']);
    }

    public function test_maps_forwarding_exception_to_502(): void
    {
        $svc = new FakeForwardingService(throw: new ForwardingException('login failed'));
        $resp = $this->controllerWith($svc)->listRules(new \WP_REST_Request());

        self::assertInstanceOf(\WP_Error::class, $resp);
        self::assertSame('beacon_forwarding_failed', $resp->get_error_code());
        self::assertSame(502, $resp->get_error_data()['status']);
        self::assertSame('login failed', $resp->get_error_message());
    }

    // -- helpers ----------------------------------------------------------

    private function controllerWith(CallForwardingService $svc): ForwardingRestController
    {
        $container = new BeaconContainer();
        $container->set(CallForwardingService::class, $svc);
        return new ForwardingRestController($container);
    }
}

/**
 * In-memory driver double. Records saves/deletes and can be told to
 * throw, so the controller's error mapping is exercisable.
 */
final class FakeForwardingService implements CallForwardingService
{
    /** @var list<ForwardingRule> */
    public array $saved = [];

    /** @var list<string> */
    public array $deleted = [];

    /**
     * @param list<ForwardingRule> $rules
     * @param list<ForwardingTarget> $targets
     */
    public function __construct(
        private array $rules = [],
        private array $targets = [],
        private ?\Throwable $throw = null,
    ) {
    }

    public function listRules(): array
    {
        $this->maybeThrow();
        return $this->rules;
    }

    public function findRule(string $ruleId): ?ForwardingRule
    {
        $this->maybeThrow();
        foreach ($this->rules as $rule) {
            if ($rule->getId() === $ruleId) {
                return $rule;
            }
        }
        return null;
    }

    public function saveRule(ForwardingRule $rule): string
    {
        $this->maybeThrow();
        $this->saved[] = $rule;
        return $rule->getId() !== '' ? $rule->getId() : '99';
    }

    public function deleteRule(string $ruleId): bool
    {
        $this->maybeThrow();
        $this->deleted[] = $ruleId;
        return $ruleId === '1';
    }

    public function listTargets(): array
    {
        $this->maybeThrow();
        return $this->targets;
    }

    public function commit(): bool
    {
        $this->maybeThrow();
        return true;
    }

    public function testConnection(): bool
    {
        $this->maybeThrow();
        return true;
    }

    private function maybeThrow(): void
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }
    }
}
