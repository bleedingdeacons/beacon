<?php

declare(strict_types=1);

namespace Beacon\Rest;

if (!defined('ABSPATH')) {
    exit;
}

use Psr\Container\ContainerInterface;
use Beacon\Forwarding\Interfaces\CallForwardingService;
use Beacon\Forwarding\Interfaces\ForwardingException;
use Beacon\Forwarding\Models\ForwardingRule;

/**
 * Driver-agnostic REST API over whatever {@see CallForwardingService}
 * an implementation plugin has bound into the container.
 *
 * Beacon ships no driver of its own, so every route resolves the
 * service lazily at request time and returns a clear 503 when no driver
 * is active. The controller knows nothing about Tamar or any other
 * implementation — it speaks only the contract and serialises Beacon's
 * own {@see ForwardingRule}/{@see \Beacon\Targets\Models\ForwardingTarget}
 * models via their `toArray()`.
 *
 * Routes (namespace `beacon/v1`):
 *
 *   GET    /rules            list rules
 *   POST   /rules            create a rule          (beacon_manage_forwarding)
 *   GET    /rules/<id>       fetch one rule
 *   PUT    /rules/<id>       update a rule          (beacon_manage_forwarding)
 *   DELETE /rules/<id>       delete a rule          (beacon_manage_forwarding)
 *   GET    /targets          list targets
 *   POST   /commit           push pending changes   (beacon_push_config)
 *   GET    /test             test the connection
 *
 * Reads require `beacon_view_forwarding`. Errors map to status codes:
 * no driver → 503, a rule that doesn't exist → 404, and any
 * {@see ForwardingException} (login/fetch/parse/validation failure on
 * the driver) → 502.
 */
final class ForwardingRestController
{
    use \Beacon\Logger\HasLogger;

    /** Log to the shared "beacon" channel so log lines name the plugin. */
    protected static function logChannel(): string
    {
        return 'beacon';
    }

    private const REST_NAMESPACE = 'beacon/v1';

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    /**
     * Hook route registration onto `rest_api_init`. Cheap to call on
     * every request — the routes only register when the REST stack boots.
     */
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::REST_NAMESPACE, '/rules', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'listRules'],
                'permission_callback' => $this->requires('beacon_view_forwarding'),
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'createRule'],
                'permission_callback' => $this->requires('beacon_manage_forwarding'),
            ],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/rules/(?P<id>[^/]+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'getRule'],
                'permission_callback' => $this->requires('beacon_view_forwarding'),
            ],
            [
                'methods' => 'PUT',
                'callback' => [$this, 'updateRule'],
                'permission_callback' => $this->requires('beacon_manage_forwarding'),
            ],
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'deleteRule'],
                'permission_callback' => $this->requires('beacon_manage_forwarding'),
            ],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/targets', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'listTargets'],
                'permission_callback' => $this->requires('beacon_view_forwarding'),
            ],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/commit', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'commit'],
                'permission_callback' => $this->requires('beacon_push_config'),
            ],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/test', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'test'],
                'permission_callback' => $this->requires('beacon_view_forwarding'),
            ],
        ]);
    }

    // -- route callbacks --------------------------------------------------

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function listRules(\WP_REST_Request $request)
    {
        return $this->withService(
            fn(CallForwardingService $svc) => $this->ok(
                array_map(fn(ForwardingRule $r) => $r->toArray(), $svc->listRules())
            )
        );
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function getRule(\WP_REST_Request $request)
    {
        $id = (string) $request->get_param('id');
        return $this->withService(function (CallForwardingService $svc) use ($id) {
            $rule = $svc->findRule($id);
            if ($rule === null) {
                return new \WP_Error(
                    'beacon_rule_not_found',
                    sprintf('No forwarding rule with id "%s".', $id),
                    ['status' => 404]
                );
            }
            return $this->ok($rule->toArray());
        });
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function createRule(\WP_REST_Request $request)
    {
        $data = $this->ruleInput($request);
        $data['id'] = ''; // empty id ⇒ the driver creates a new rule
        return $this->withService(function (CallForwardingService $svc) use ($data) {
            $id = $svc->saveRule(new ForwardingRule($data));
            return $this->ok(['id' => $id], 201);
        });
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function updateRule(\WP_REST_Request $request)
    {
        $data = $this->ruleInput($request);
        $data['id'] = (string) $request->get_param('id');
        return $this->withService(function (CallForwardingService $svc) use ($data) {
            $id = $svc->saveRule(new ForwardingRule($data));
            return $this->ok(['id' => $id]);
        });
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function deleteRule(\WP_REST_Request $request)
    {
        $id = (string) $request->get_param('id');
        return $this->withService(
            fn(CallForwardingService $svc) => $this->ok(['id' => $id, 'deleted' => $svc->deleteRule($id)])
        );
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function listTargets(\WP_REST_Request $request)
    {
        return $this->withService(
            fn(CallForwardingService $svc) => $this->ok(
                array_map(fn($t) => $t->toArray(), $svc->listTargets())
            )
        );
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function commit(\WP_REST_Request $request)
    {
        return $this->withService(
            fn(CallForwardingService $svc) => $this->ok(['committed' => $svc->commit()])
        );
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public function test(\WP_REST_Request $request)
    {
        return $this->withService(
            fn(CallForwardingService $svc) => $this->ok(['ok' => $svc->testConnection()])
        );
    }

    // -- internals --------------------------------------------------------

    /**
     * Resolve the bound driver and run $fn against it, translating the
     * two failure modes every route shares: no driver bound (503) and a
     * driver-level {@see ForwardingException} (502).
     *
     * @param callable(CallForwardingService):(\WP_REST_Response|\WP_Error) $fn
     * @return \WP_REST_Response|\WP_Error
     */
    private function withService(callable $fn)
    {
        if (!$this->container->has(CallForwardingService::class)) {
            return new \WP_Error(
                'beacon_no_driver',
                'No call-forwarding driver is active. Activate an implementation plugin.',
                ['status' => 503]
            );
        }

        /** @var CallForwardingService $svc */
        $svc = $this->container->get(CallForwardingService::class);

        try {
            return $fn($svc);
        } catch (ForwardingException $e) {
            self::logWarning('Forwarding REST request failed', ['error' => $e->getMessage()]);
            return new \WP_Error('beacon_forwarding_failed', $e->getMessage(), ['status' => 502]);
        }
    }

    /**
     * Build a permission callback for a capability. A closure so the
     * check runs per request (when the current user is known), not at
     * route-registration time.
     *
     * @return callable():bool
     */
    private function requires(string $capability): callable
    {
        return static fn(): bool => current_user_can($capability);
    }

    /**
     * Assemble the raw rule array from the request body. The match block
     * is passed through verbatim — {@see ForwardingRule} normalises it —
     * so the API speaks Beacon's own rule vocabulary directly.
     *
     * @return array<string,mixed>
     */
    private function ruleInput(\WP_REST_Request $request): array
    {
        $match = $request->get_param('match');
        $enabled = $request->get_param('enabled');

        return [
            'label' => (string) $request->get_param('label'),
            'match' => is_array($match) ? $match : ['type' => 'any', 'value' => null],
            'target_id' => (string) $request->get_param('target_id'),
            'enabled' => $enabled === null ? true : (bool) filter_var($enabled, FILTER_VALIDATE_BOOLEAN),
            'priority' => (int) $request->get_param('priority'),
        ];
    }

    /**
     * @param array<string,mixed>|list<mixed> $data
     * @return \WP_REST_Response
     */
    private function ok($data, int $status = 200): \WP_REST_Response
    {
        return new \WP_REST_Response($data, $status);
    }
}
