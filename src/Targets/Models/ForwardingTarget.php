<?php

declare(strict_types=1);

namespace Beacon\Targets\Models;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Immutable value object representing a destination a rule can point at.
 *
 * Targets come in three shapes:
 *  - 'number'    — an external phone number in E.164 form
 *  - 'extension' — an internal extension on the upstream PBX
 *  - 'voicemail' — a voicemail box ID on the upstream system
 *
 * Drivers that surface targets the upstream calls something else
 * (queue, hunt group, ring group) should pick whichever shape is
 * semantically closest and put the vendor-specific name in `label`.
 * Beacon doesn't try to model every PBX feature — it just gives the
 * UI enough structure to render a sensible "send calls here" picker.
 */
final class ForwardingTarget
{
    public const KIND_NUMBER = 'number';
    public const KIND_EXTENSION = 'extension';
    public const KIND_VOICEMAIL = 'voicemail';

    public readonly string $id;
    public readonly string $label;
    public readonly string $kind;
    public readonly string $address;

    /**
     * @param array<string,mixed> $data
     */
    public function __construct(array $data)
    {
        $this->id = (string) ($data['id'] ?? '');
        $this->label = (string) ($data['label'] ?? '');
        $this->kind = self::normaliseKind($data['kind'] ?? self::KIND_NUMBER);
        $this->address = (string) ($data['address'] ?? '');
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    /**
     * Human-readable summary for log lines and admin tables.
     * `Office Mobile <+44 7700 900123>` reads nicely; the raw address
     * alone leaves the reader guessing which line it belongs to.
     */
    public function describe(): string
    {
        if ($this->label === '') {
            return $this->address;
        }
        if ($this->address === '') {
            return $this->label;
        }
        return $this->label . ' <' . $this->address . '>';
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'kind' => $this->kind,
            'address' => $this->address,
        ];
    }

    /**
     * Unknown kinds coerce to 'number' — the most permissive option —
     * so a driver returning an unexpected kind doesn't break Beacon.
     */
    private static function normaliseKind(mixed $raw): string
    {
        $valid = [self::KIND_NUMBER, self::KIND_EXTENSION, self::KIND_VOICEMAIL];
        $kind = (string) $raw;
        return in_array($kind, $valid, true) ? $kind : self::KIND_NUMBER;
    }
}
