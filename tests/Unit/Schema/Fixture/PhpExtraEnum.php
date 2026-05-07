<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture;

use Studio\OpenApiContractTesting\Attribute\BoundToOpenApiEnum;

#[BoundToOpenApiEnum('enum-drift/php-extra.json')]
enum PhpExtraEnum: string
{
    case Red = 'red';
    case Green = 'green';
    case Blue = 'blue'; // not in spec
}
