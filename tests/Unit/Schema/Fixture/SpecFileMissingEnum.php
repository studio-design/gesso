<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture;

use Studio\OpenApiContractTesting\Attribute\BoundToOpenApiEnum;

#[BoundToOpenApiEnum('enum-drift/does-not-exist.json')]
enum SpecFileMissingEnum: string
{
    case A = 'a';
}
