<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Strict;

use InvalidArgumentException;

use function array_map;
use function implode;
use function sprintf;
use function strtolower;
use function trim;

/**
 * Operating mode for the strict-required (schema under-description) check.
 *
 * Configured via the PHPUnit extension parameter `strict_required`:
 *
 *  - `off`  — default; observations are not recorded and no report runs
 *  - `warn` — observations are recorded; on `ExecutionFinished` the asserter
 *             emits an `E_USER_WARNING` for every endpoint whose response
 *             body consistently contains keys not declared in the matching
 *             schema's `required` array
 *  - `fail` — same as `warn`, but a {@see StrictRequiredDriftException} is
 *             thrown so the run exits non-zero
 *
 * Separate from the existing `additionalProperties: false` conformance check,
 * which catches the inverse direction (impl returning fields the spec does
 * not document). Strict-required catches **spec under-description** — impl
 * always returns a field but the spec marks it optional, which causes
 * SDK consumers to receive `T | undefined` types.
 */
enum StrictRequiredMode: string
{
    case Off = 'off';
    case Warn = 'warn';
    case Fail = 'fail';

    /**
     * Parse the `strict_required` extension parameter. `null` and the empty
     * string both resolve to {@see self::Off} so the extension can call this
     * with `$parameters->has('strict_required') ? $parameters->get(...) : null`
     * without a separate fallback path.
     *
     * Whitespace is trimmed and the value is matched case-insensitively to
     * stay tolerant of `<parameter>Warn</parameter>` typos in phpunit.xml.
     *
     * @throws InvalidArgumentException when a non-empty value does not match
     *                                  any case
     */
    public static function fromConfigValue(?string $value): self
    {
        if ($value === null) {
            return self::Off;
        }

        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return self::Off;
        }

        $match = self::tryFrom($normalized);
        if ($match !== null) {
            return $match;
        }

        $accepted = implode(', ', array_map(static fn(self $c): string => $c->value, self::cases()));

        throw new InvalidArgumentException(sprintf(
            "Unknown strict_required value '%s'. Accepted: %s.",
            $value,
            $accepted,
        ));
    }

    public function isEnabled(): bool
    {
        return $this !== self::Off;
    }
}
