<?php

declare(strict_types=1);

namespace Studio\Gesso\Arazzo;

use Studio\Gesso\Internal\OperationIndex;

/**
 * A source description mapped to the named OpenAPI spec the validators use.
 *
 * @internal Becomes public with the Arazzo workflow runner (#428).
 */
final readonly class ArazzoResolvedSource
{
    /**
     * @param array<string, mixed> $spec the document exactly as OpenApiSpecLoader::load() returns it
     */
    public function __construct(
        public string $name,
        public string $specName,
        public array $spec,
        public OperationIndex $operations,
    ) {}
}
