<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\PHPUnit;

use RuntimeException;
use Throwable;

/**
 * Thrown by {@see OpenApiCoverageExtension} when either the run-level
 * `strict_required` parameter or the per-call `strict_required_per_call`
 * parameter carries an unrecognised value.
 *
 * The two parameters have different accepted-value sets:
 *  - `strict_required`          accepts `off` / `warn` / `fail`
 *  - `strict_required_per_call` accepts `off` / `warn` only
 *
 * `fail` IS valid for the run-level parameter but is intentionally rejected
 * for the per-call one: per-call mode warns on every legitimately-optional
 * field present in any one observation, and a fail-gate over that surface
 * would shower false positives. Silently demoting a per-call `fail` typo to
 * `warn` would mislead a CI that opted into a hard gate by accident.
 *
 * Bootstrap catches this alongside the other extension-config exceptions
 * and translates it to `exit(1)`. A typo in either parameter must fail
 * loud rather than silently disabling the feature — silently dropping the
 * gate is exactly the silent-pass mode the extension exists to prevent.
 */
final class InvalidStrictRequiredConfigurationException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
