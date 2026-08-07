<?php

declare(strict_types=1);

namespace Studio\Gesso\PHPUnit;

use RuntimeException;
use Throwable;

/**
 * Thrown by {@see OpenApiCoverageExtension} when one of the
 * validation-policy parameters (`max_errors`, `skip_response_codes`,
 * `skip_request_validation_response_codes`) carries an unusable value
 * (issue #502, additive half).
 *
 * Bootstrap catches this alongside the other extension-config exceptions and
 * translates it to `exit(1)`. A typo must fail loud rather than silently
 * running the suite with the built-in defaults — a suite that believes it
 * capped `max_errors` or disabled a skip pattern would otherwise pass on a
 * configuration it never applied.
 *
 * @internal PHPUnit extension configuration boundary. Do not catch from user code.
 */
final class InvalidValidationPolicyConfigurationException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
