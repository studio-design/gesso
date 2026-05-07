<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture;

use Studio\OpenApiContractTesting\Attribute\BoundToOpenApiEnum;

#[BoundToOpenApiEnum('enum-drift/spec-extra.json')]
enum SpecExtraEnum: string
{
    case Red = 'red';
    case Green = 'green';
    case Blue = 'blue';
    // spec also has 'yellow' which has no PHP case
}
