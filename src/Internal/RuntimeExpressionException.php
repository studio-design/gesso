<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use RuntimeException;

use function sprintf;

/**
 * @internal Runtime-expression plumbing for Links exploration; not public API.
 */
final class RuntimeExpressionException extends RuntimeException
{
    public function __construct(
        public readonly RuntimeExpressionFailure $reason,
        public readonly string $expression,
        string $detail,
    ) {
        parent::__construct(sprintf("Runtime expression '%s': %s", $expression, $detail));
    }
}
