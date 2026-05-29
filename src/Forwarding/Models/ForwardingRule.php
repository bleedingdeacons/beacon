<?php

declare(strict_types=1);

namespace Beacon\Forwarding\Models;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Immutable value object representing a single forwarding rule.
 *
 * A rule says: "when a call matches `match`, send it to the target
 * with this `targetId`." The `match` array is a small, driver-agnostic
 * vocabulary that every implementation is expected to understand or
 * map to something equivalent on its upstream system:
 *
 *  - `type`           one of: 'any', 'source_number', 'time_window',
 *                     'caller_id_list'. Drivers that don't natively
 *                     support a given type should throw
 *                     {@see \Beacon\Forwarding\Interfaces\ForwardingException}
 *                     rather than silently dropping the condition —
 *                     better to fail loudly than to forward calls in
 *                     a way the operator didn't intend.
 *  - `value`          opaque to Beacon; type-dependent. For
 *                     'source_number', a single E.164 string. For
 *                     'time_window', an associative array with
 *                     `days`, `from`, `to`. For 'caller_id_list', an
 *                     array of strings.
 *
 * `priority` orders rules where the upstream supports it (lower = wins
 * first). Drivers that don't support priority should preserve list
 * order and ignore this field on save, returning rules with
 * `priority = 0` on read.
 *
 * Immutability is enforced by `readonly` — a maintainer who adds a
 * setter by mistake gets a runtime error rather than a silent state
 * mutation. Use {@see self::with()} to derive a modified copy.
 */
final class ForwardingRule
{
    public readonly string $id;
    public readonly string $label;
    /** @var array<string,mixed> */
    public readonly array $match;
    public readonly string $targetId;
    public readonly bool $enabled;
    public readonly int $priority;

    /**
     * @param array<string,mixed> $data
     */
    public function __construct(array $data)
    {
        $this->id = (string) ($data['id'] ?? '');
        $this->label = (string) ($data['label'] ?? '');
        $this->match = self::normaliseMatch($data['match'] ?? []);
        $this->targetId = (string) ($data['target_id'] ?? '');
        // Default to enabled — a freshly-created rule that wasn't
        // explicitly disabled should take effect.
        $this->enabled = (bool) ($data['enabled'] ?? true);
        $this->priority = (int) ($data['priority'] ?? 0);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    /** @return array<string,mixed> */
    public function getMatch(): array
    {
        return $this->match;
    }

    public function getMatchType(): string
    {
        return (string) ($this->match['type'] ?? 'any');
    }

    public function getTargetId(): string
    {
        return $this->targetId;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * Whether this rule is a catchall (matches every incoming call).
     * Useful for UI ordering — catchalls typically render last.
     */
    public function isCatchall(): bool
    {
        return $this->getMatchType() === 'any';
    }

    /**
     * Return a new rule with the given fields overridden. Use for
     * "change the target on this rule" without mutating the original.
     *
     * @param array<string,mixed> $changes
     */
    public function with(array $changes): self
    {
        return new self(array_merge($this->toArray(), $changes));
    }

    /**
     * Export to plain array (for JSON / templates / driver payloads).
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'match' => $this->match,
            'target_id' => $this->targetId,
            'enabled' => $this->enabled,
            'priority' => $this->priority,
        ];
    }

    /**
     * Coerce arbitrary input into a clean match array. Unknown types
     * are coerced to 'any', which is the safe default — a malformed
     * rule should match everything (and therefore be obvious in the
     * UI) rather than match nothing (and therefore silently drop
     * forwarding).
     *
     * @param mixed $raw
     * @return array<string,mixed>
     */
    private static function normaliseMatch(mixed $raw): array
    {
        if (!is_array($raw)) {
            return ['type' => 'any', 'value' => null];
        }
        $type = (string) ($raw['type'] ?? 'any');
        $validTypes = ['any', 'source_number', 'time_window', 'caller_id_list'];
        if (!in_array($type, $validTypes, true)) {
            $type = 'any';
        }
        return [
            'type' => $type,
            'value' => $raw['value'] ?? null,
        ];
    }
}
