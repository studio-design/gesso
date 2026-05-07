<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Attribute;

use Attribute;
use Studio\OpenApiContractTesting\Schema\EnumDriftAsserter;

/**
 * Bind a backed PHP enum to its OpenAPI `enum` definition file.
 *
 * `$specPath` is resolved relative to the configured spec root
 * (`OpenApiSpecLoader::getBasePath()`). Detection of drift between the
 * PHP enum cases and the spec's `enum` array is performed by
 * {@see EnumDriftAsserter}.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class BoundToOpenApiEnum
{
    public function __construct(
        public readonly string $specPath,
    ) {}
}
