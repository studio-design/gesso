<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\PHPUnit;

use RuntimeException;
use Throwable;

/**
 * Thrown by {@see OpenApiCoverageExtension} when the `strict_required`
 * parameter carries an unrecognised value (anything outside `off` / `warn`
 * / `fail`).
 *
 * Bootstrap catches this alongside the other extension-config exceptions
 * and translates it to `exit(1)`. A typo in `strict_required` must fail
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
