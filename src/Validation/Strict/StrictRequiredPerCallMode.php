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
 * Operating mode for the per-call strict-required check (Issue #228).
 *
 * Configured via the PHPUnit extension parameter `strict_required_per_call`,
 * orthogonal to the run-level {@see StrictRequiredMode} parameter:
 *
 *  - `off`  — default; the per-call checker short-circuits and no warnings
 *             are emitted on the validator success path
 *  - `warn` — every conformance-passing response is diffed in-place against
 *             the matching schema's `required` array, and any
 *             always-present-yet-optional key triggers `E_USER_WARNING`
 *             with the `[OpenAPI Strict Required per-call]` prefix so
 *             `failOnWarning=true` test runs surface the gap as a per-test
 *             failure
 *
 * Why no `fail` mode? Per-call necessarily warns on legitimately-optional
 * fields that happen to be present in any one observation (a nullable field,
 * a conditional payload). The run-level intersection mode is the safer
 * fail-gate (issue #224 / PR #225 docs); per-call is the lightweight
 * single-observation surface and is intentionally warn-only. Users that
 * want hard failures on per-call drift can enable `failOnWarning` in
 * `phpunit.xml`.
 *
 * Run-level mode and per-call mode are independent: a CI may run
 * `strict_required=fail` for the safe aggregate gate AND
 * `strict_required_per_call=warn` for early visibility on single-observation
 * endpoints.
 */
enum StrictRequiredPerCallMode: string
{
    case Off = 'off';
    case Warn = 'warn';

    /**
     * Parse the `strict_required_per_call` extension parameter. `null` and
     * the empty string both resolve to {@see self::Off} so the extension can
     * call this with `$parameters->has('strict_required_per_call') ?
     * $parameters->get(...) : null` without a separate fallback path.
     *
     * Whitespace is trimmed and the value is matched case-insensitively to
     * stay tolerant of `<parameter>Warn</parameter>` typos in phpunit.xml.
     *
     * `fail` is rejected loudly even though it is a valid run-level value —
     * the per-call mode is intentionally warn-only and silently demoting
     * `fail` to `warn` would defeat a CI that mistakenly typed `fail`
     * thinking it was the same gate.
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
            "Unknown strict_required_per_call value '%s'. Accepted: %s.",
            $value,
            $accepted,
        ));
    }

    public function isEnabled(): bool
    {
        return $this !== self::Off;
    }
}
