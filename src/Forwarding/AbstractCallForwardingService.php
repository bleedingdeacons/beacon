<?php

declare(strict_types=1);

namespace Beacon\Forwarding;

if (!defined('ABSPATH')) {
    exit;
}

use Beacon\Forwarding\Interfaces\CallForwardingService;
use Beacon\Forwarding\Interfaces\ForwardingException;
use Beacon\Forwarding\Models\ForwardingRule;
use Beacon\Targets\Models\ForwardingTarget;

/**
 * Shared helper logic any concrete CallForwardingService can extend.
 *
 * Three things consistently want sharing across drivers:
 *
 *  1. Validating a {@see ForwardingRule} before it goes upstream.
 *     A rule with an empty target ID, a malformed source-number
 *     match, or a time window with `from` >= `to` should fail at
 *     this boundary — pushing it to the upstream and letting the
 *     upstream reject it produces a much worse error message and
 *     leaves the operator wondering whether the save half-succeeded.
 *
 *  2. Hydrating arrays returned by an upstream parser back into
 *     ForwardingRule/ForwardingTarget value objects. The drivers
 *     parse different HTML shapes but the *output* should be uniform.
 *
 *  3. E.164-light normalisation for source numbers and target
 *     addresses. We don't ship a full E.164 parser (that's a libphonenumber
 *     concern), but we do strip whitespace and reject obviously-bad
 *     inputs so the upstream gets clean data.
 *
 * The class doesn't implement the contract itself — concrete drivers
 * still need to define the upstream-facing methods. It just gives them
 * a place to stand.
 */
abstract class AbstractCallForwardingService implements CallForwardingService
{
    /**
     * Validate a rule before it leaves the driver. Throws on the first
     * problem rather than collecting all of them — the admin UI shows
     * one issue at a time anyway, and validating in order means the
     * error message points at the first thing the operator needs to
     * fix.
     *
     * @throws ForwardingException
     */
    protected function validateRule(ForwardingRule $rule): void
    {
        if ($rule->getTargetId() === '') {
            throw new ForwardingException(
                'Forwarding rule has no target. Pick a destination before saving.'
            );
        }

        $match = $rule->getMatch();
        $type = (string) ($match['type'] ?? '');
        $value = $match['value'] ?? null;

        switch ($type) {
            case 'any':
                // No value required — the rule matches everything.
                break;

            case 'source_number':
                if (!is_string($value) || trim($value) === '') {
                    throw new ForwardingException(
                        'A source-number rule needs a non-empty number.'
                    );
                }
                if (self::normaliseNumber($value) === '') {
                    throw new ForwardingException(
                        'Source number "' . $value . '" does not look like a phone number.'
                    );
                }
                break;

            case 'time_window':
                if (!is_array($value)) {
                    throw new ForwardingException(
                        'A time-window rule needs a value with `from` and `to`.'
                    );
                }
                $from = (string) ($value['from'] ?? '');
                $to = (string) ($value['to'] ?? '');
                if (!self::isHHMM($from) || !self::isHHMM($to)) {
                    throw new ForwardingException(
                        'Time-window from/to must be in HH:MM 24-hour format.'
                    );
                }
                // Allow `to` < `from` to mean "wraps midnight" — many
                // upstream systems support this, and we'd rather pass
                // it through than guess.
                break;

            case 'caller_id_list':
                if (!is_array($value) || count($value) === 0) {
                    throw new ForwardingException(
                        'A caller-id-list rule needs at least one number in its list.'
                    );
                }
                foreach ($value as $number) {
                    if (!is_string($number) || self::normaliseNumber($number) === '') {
                        throw new ForwardingException(
                            'Caller-id list contains an entry that does not look like a phone number: ' . var_export($number, true)
                        );
                    }
                }
                break;

            default:
                throw new ForwardingException(
                    'Unknown match type "' . $type . '".'
                );
        }
    }

    /**
     * Hydrate an array of raw rule arrays into ForwardingRule objects,
     * preserving order. Driver parsers can return raw arrays; the
     * abstract takes care of turning them into value objects so each
     * driver doesn't reimplement the same loop.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,ForwardingRule>
     */
    protected function hydrateRules(array $rows): array
    {
        $rules = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                // Skip non-array entries rather than throwing — a
                // junk row in the upstream response shouldn't break
                // the whole list. The driver's parser is the layer
                // that should already be flagging upstream weirdness.
                continue;
            }
            $rules[] = new ForwardingRule($row);
        }
        return $rules;
    }

    /**
     * Hydrate an array of raw target arrays into ForwardingTarget
     * objects.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,ForwardingTarget>
     */
    protected function hydrateTargets(array $rows): array
    {
        $targets = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $targets[] = new ForwardingTarget($row);
        }
        return $targets;
    }

    /**
     * Trim a phone-number-shaped string. Returns '' if it doesn't
     * look like a phone number at all. We deliberately accept both
     * `+`-prefixed E.164 and bare digit strings — the upstream often
     * accepts either, and forcing a canonical form here would reject
     * inputs the upstream is happy with.
     */
    protected static function normaliseNumber(string $raw): string
    {
        // Strip everything except digits and the leading `+`. Spaces,
        // dashes, parentheses, dots — all decorative; the upstream
        // doesn't care.
        $cleaned = preg_replace('/[^\d+]/', '', $raw) ?? '';
        if ($cleaned === '' || $cleaned === '+') {
            return '';
        }
        // A `+` only makes sense at the start.
        if (str_contains(substr($cleaned, 1), '+')) {
            return '';
        }
        // Need at least three digits to be a plausible number. Most
        // shortcodes are at least three; everything shorter is almost
        // certainly a parse error.
        $digits = preg_replace('/\D/', '', $cleaned) ?? '';
        if (strlen($digits) < 3) {
            return '';
        }
        return $cleaned;
    }

    /**
     * Whether a string looks like an HH:MM 24-hour time.
     */
    protected static function isHHMM(string $raw): bool
    {
        return (bool) preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $raw);
    }
}
