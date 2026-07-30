<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Strict;

/**
 * One undocumented response property observed for an operation.
 *
 * @internal Rendered by the PHPUnit extension and merge CLI.
 */
final readonly class StrictAdditionalPropertiesReport
{
    public function __construct(
        public string $specName,
        public string $method,
        public string $path,
        public string $statusKey,
        public string $contentTypeKey,
        public string $instancePointer,
        public string $propertyName,
        public int $hits,
    ) {}
}
