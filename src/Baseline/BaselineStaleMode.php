<?php

declare(strict_types=1);

namespace Studio\Gesso\Baseline;

use InvalidArgumentException;
use Studio\Gesso\Validation\Strict\StrictRequiredMode;

use function array_map;
use function implode;
use function sprintf;
use function strtolower;
use function trim;

/**
 * How stale baseline entries — entries that no longer occurred during a
 * full run — are reported at the end of an enforcement run (issue #402).
 *
 * Configured via the PHPUnit extension parameter `baseline_stale`:
 *
 *  - `off`  — stale entries are not evaluated or reported
 *  - `note` — default; stale entries are listed as a NOTE so the ratchet
 *             can be tightened by removing them from the baseline file
 *  - `fail` — same listing, but the run exits non-zero so CI enforces the
 *             ratchet (matching PHPStan's baseline behavior)
 *
 * @internal Implementation detail of the violation baseline; the
 *           `baseline_stale` parameter values are the supported surface.
 */
enum BaselineStaleMode: string
{
    case Off = 'off';
    case Note = 'note';
    case Fail = 'fail';

    /**
     * Parse the `baseline_stale` extension parameter. `null` and the empty
     * string both resolve to {@see self::Note} — reporting removable debt
     * is the useful default; silencing it is the opt-out. Whitespace is
     * trimmed and matching is case-insensitive, mirroring
     * {@see StrictRequiredMode::fromConfigValue()}.
     *
     * @throws InvalidArgumentException when a non-empty value does not match
     *                                  any case
     */
    public static function fromConfigValue(?string $value): self
    {
        if ($value === null) {
            return self::Note;
        }

        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return self::Note;
        }

        $match = self::tryFrom($normalized);
        if ($match !== null) {
            return $match;
        }

        $accepted = implode(', ', array_map(static fn(self $c): string => $c->value, self::cases()));

        throw new InvalidArgumentException(sprintf(
            "Unknown baseline_stale value '%s'. Accepted: %s.",
            $value,
            $accepted,
        ));
    }
}
