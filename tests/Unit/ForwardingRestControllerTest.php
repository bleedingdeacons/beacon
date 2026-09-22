<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit;

use Beacon\Core\BeaconContainer;
use Beacon\Forwarding\Interfaces\CallForwardingService;
use Beacon\Forwarding\Interfaces\ForwardingException;
use Beacon\Forwarding\Models\ForwardingRule;
use Beacon\Rest\ForwardingRestController;
use Beacon\Targets\Models\ForwardingTarget;

/*
 * Exercises the controller's route callbacks directly against a fake
 * driver — the REST plumbing (route registration, permissions) is thin
 * WP glue; the behaviour worth testing is the model serialisation and
 * the no-driver / driver-error mapping.
 */

// -- helpers ----------------------------------------------------------

function forwardingControllerWith(CallForwardingService $svc): ForwardingRestController
{
    $container = new BeaconContainer();
    $container->set(CallForwardingService::class, $svc);
    return new ForwardingRestController($container);
}

it('serialises rules from listRules', function () {
    $svc = new FakeForwardingService(rules: [
        new ForwardingRule(['id' => '1', 'label' => 'Day', 'target_id' => 'num:123', 'match' => ['type' => 'any']]),
    ]);
    $resp = forwardingControllerWith($svc)->listRules(new \WP_REST_Request());

    expect($resp)->toBeInstanceOf(\WP_REST_Response::class)
        ->and($resp->get_status())->toBe(200);
    $data = $resp->get_data();
    expect($data)->toHaveCount(1)
        ->and($data[0]['id'])->toBe('1')
        ->and($data[0]['target_id'])->toBe('num:123');
});

it('serialises targets from listTargets', function () {
    $svc = new FakeForwardingService(targets: [
        new ForwardingTarget(['id' => 'num:123', 'kind' => 'number', 'label' => 'Steve', 'address' => '0123']),
    ]);
    $resp = forwardingControllerWith($svc)->listTargets(new \WP_REST_Request());

    expect($resp->get_data()[0]['id'])->toBe('num:123')
        ->and($resp->get_data()[0]['kind'])->toBe('number');
});

it('returns 404 from getRule when the rule is absent', function () {
    $svc = new FakeForwardingService(rules: []);
    $resp = forwardingControllerWith($svc)->getRule(new \WP_REST_Request(['id' => '999']));

    expect($resp)->toBeInstanceOf(\WP_Error::class)
        ->and($resp->get_error_code())->toBe('beacon_rule_not_found')
        ->and($resp->get_error_data()['status'])->toBe(404);
});

it('forces an empty id on createRule and returns the new id', function () {
    $svc = new FakeForwardingService();
    $resp = forwardingControllerWith($svc)->createRule(new \WP_REST_Request([
        'id' => 'ignored',
        'label' => 'New',
        'target_id' => 'num:555',
        'match' => ['type' => 'time_window', 'value' => ['from' => '09:00', 'to' => '17:00', 'days' => ['mon']]],
    ]));

    expect($resp)->toBeInstanceOf(\WP_REST_Response::class)
        ->and($resp->get_status())->toBe(201)
        ->and($resp->get_data()['id'])->toBe('99');
    // The id from the URL/body must be ignored on create — the rule
    // handed to the driver carries an empty id.
    expect($svc->saved[0]->getId())->toBe('')
        ->and($svc->saved[0]->getTargetId())->toBe('num:555');
});

it('uses the path id on updateRule', function () {
    $svc = new FakeForwardingService();
    $resp = forwardingControllerWith($svc)->updateRule(new \WP_REST_Request([
        'id' => '2',
        'label' => 'Edited',
        'target_id' => 'num:777',
        'match' => ['type' => 'any'],
    ]));

    expect($resp->get_status())->toBe(200)
        ->and($resp->get_data()['id'])->toBe('2')
        ->and($svc->saved[0]->getId())->toBe('2');
});

it('reports the outcome of deleteRule', function () {
    $svc = new FakeForwardingService();
    $resp = forwardingControllerWith($svc)->deleteRule(new \WP_REST_Request(['id' => '1']));

    expect($resp->get_data())->toBe(['id' => '1', 'deleted' => true])
        ->and($svc->deleted)->toBe(['1']);
});

it('returns 503 when no driver is bound', function () {
    $controller = new ForwardingRestController(new BeaconContainer());
    $resp = $controller->listRules(new \WP_REST_Request());

    expect($resp)->toBeInstanceOf(\WP_Error::class)
        ->and($resp->get_error_code())->toBe('beacon_no_driver')
        ->and($resp->get_error_data()['status'])->toBe(503);
});

it('maps a forwarding exception to 502', function () {
    $svc = new FakeForwardingService(throw: new ForwardingException('login failed'));
    $resp = forwardingControllerWith($svc)->listRules(new \WP_REST_Request());

    expect($resp)->toBeInstanceOf(\WP_Error::class)
        ->and($resp->get_error_code())->toBe('beacon_forwarding_failed')
        ->and($resp->get_error_data()['status'])->toBe(502)
        ->and($resp->get_error_message())->toBe('login failed');
});

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
