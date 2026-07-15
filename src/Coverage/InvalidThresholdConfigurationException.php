<?php

declare(strict_types=1);

namespace Studio\Gesso\Coverage;

use RuntimeException;
use Studio\Gesso\Exception\InvalidOpenApiSpecException;
use Studio\Gesso\PHPUnit\OpenApiCoverageExtension;
use Throwable;

/**
 * Thrown by {@see OpenApiCoverageExtension}
 * when a `min_endpoint_coverage` / `min_response_coverage` parameter is invalid
 * (non-numeric or out of `0..100` range) AND `min_coverage_strict` is on.
 *
 * Distinct from {@see InvalidOpenApiSpecException} because the failure has
 * nothing to do with a spec — it's a misconfigured CI gate. A strict run
 * that opted into fail-fast must not have its gate silently disabled by a
 * typo (issue #135 review C1); bootstrap() catches this alongside
 * `InvalidOpenApiSpecException` and translates it to `exit(1)`.
 *
 * In warn-only mode the same condition is downgraded to a `WARNING` and the
 * gate is dropped, so this exception is reserved for the strict path.
 *
 * @internal PHPUnit extension configuration boundary. Do not catch from user code.
 */
final class InvalidThresholdConfigurationException extends RuntimeException
{
    public function __construct(
        public readonly string $parameterName,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
