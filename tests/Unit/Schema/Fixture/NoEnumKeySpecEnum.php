<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture;

use Studio\OpenApiContractTesting\Attribute\BoundToOpenApiEnum;

#[BoundToOpenApiEnum('enum-drift/no-enum-key.json')]
enum NoEnumKeySpecEnum: string
{
    case A = 'a';
}
