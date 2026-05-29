<?php

declare(strict_types=1);

namespace Beacon\Forwarding\Interfaces;

if (!defined('ABSPATH')) {
    exit;
}

use Beacon\Forwarding\Models\ForwardingRule;
use Beacon\Targets\Models\ForwardingTarget;

/**
 * The contract every call-forwarding driver implements.
 *
 * A "rule" is a logical condition (a source number, a time window, a
 * "everything else" catchall) plus the target the call should be sent
 * to when the rule matches. A "target" is the destination — typically
 * a phone number, but drivers are free to model voicemail boxes,
 * extension groups, or external SIP URIs as targets too.
 *
 * The contract is intentionally narrow:
 *  - List, fetch, save, delete rules.
 *  - List the targets the upstream system has on file.
 *  - Push the current rule set live (some upstream systems require an
 *    explicit commit step after editing).
 *  - Check that the connection actually works.
 *
 * Anything driver-specific — credentials, scraping logic, retry
 * policy, vendor-specific options — belongs in the implementation,
 * not in this interface.
 *
 * Drivers SHOULD throw {@see ForwardingException} (or a subclass) for
 * any operational failure rather than returning false-y values, so
 * callers always know whether an operation succeeded or threw.
 */
interface CallForwardingService
{
    /**
     * Fetch every rule currently configured on the upstream system.
     *
     * Implementations typically GET the upstream admin page and parse
     * it; results are NOT expected to be cached at this layer (callers
     * cache where appropriate).
     *
     * @return array<int,ForwardingRule>
     *
     * @throws ForwardingException If the upstream couldn't be reached
     *                              or returned an unparseable response.
     */
    public function listRules(): array;

    /**
     * Fetch a single rule by ID.
     *
     * Returns null when the rule is genuinely absent; throws only when
     * the upstream fetch itself failed.
     *
     * @throws ForwardingException
     */
    public function findRule(string $ruleId): ?ForwardingRule;

    /**
     * Create or update a rule on the upstream system.
     *
     * If the rule's `id` is empty the implementation creates a new
     * rule and returns the assigned ID; if `id` is set the
     * implementation updates the matching rule. The returned ID may
     * differ from the supplied one if the upstream renames it on
     * create (some systems use a sequential numeric ID).
     *
     * @return string The canonical rule ID after the save.
     *
     * @throws ForwardingException
     */
    public function saveRule(ForwardingRule $rule): string;

    /**
     * Remove a rule from the upstream system.
     *
     * Returns true if the rule existed and was deleted, false if it
     * was already absent. Throws only on upstream errors.
     *
     * @throws ForwardingException
     */
    public function deleteRule(string $ruleId): bool;

    /**
     * List the targets known to the upstream system — the
     * destinations rules may point at.
     *
     * @return array<int,ForwardingTarget>
     *
     * @throws ForwardingException
     */
    public function listTargets(): array;

    /**
     * Commit any pending changes to the live forwarding configuration.
     *
     * Some upstream systems edit a draft and require an explicit
     * "apply" / "reload" step. Drivers whose upstream is always live
     * should still implement this as a no-op success (so callers can
     * be uniform). Callers checking the `beacon_push_config`
     * capability should call this after any batch of rule changes.
     *
     * @throws ForwardingException
     */
    public function commit(): bool;

    /**
     * Verify the driver can reach the upstream system with its
     * configured credentials.
     *
     * Drivers should return true only after a real round-trip — not
     * just "credentials are non-empty". Callers use this from the
     * admin UI's "test connection" button, so it needs to fail loud
     * when the upstream is wrong rather than appearing healthy.
     *
     * @throws ForwardingException
     */
    public function testConnection(): bool;
}
