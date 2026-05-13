<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Strict;

use InvalidArgumentException;

/**
 * Result of a single (endpoint, status, content-type) strict-required drift
 * comparison.
 *
 * `missingFromRequired` lists keys that appeared in **every** recorded
 * response body for this endpoint × status × content-type but are not
 * declared in the schema's `required` array. An empty list means no drift
 * was detected — {@see self::hasDrift()} returns the canonical flag.
 *
 * `hits` is the number of recorded observations that contributed to the
 * intersection, always >= 1. It is surfaced in diagnostic messages so
 * reviewers can gauge confidence (a single observation may be coincidental;
 * dozens are strong evidence the field is always returned).
 */
final class StrictRequiredReport
{
    /**
     * @param list<string> $missingFromRequired
     */
    public function __construct(
        public readonly string $specName,
        public readonly string $method,
        public readonly string $path,
        public readonly string $statusKey,
        public readonly string $contentTypeKey,
        public readonly array $missingFromRequired,
        public readonly int $hits,
    ) {
        if ($hits < 1) {
            throw new InvalidArgumentException(
                'StrictRequiredReport requires hits >= 1 — a report describes at least one observed response.',
            );
        }
    }

    public function hasDrift(): bool
    {
        return $this->missingFromRequired !== [];
    }
}
