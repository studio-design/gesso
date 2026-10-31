<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Schema\Fixture;

use Studio\Gesso\Attribute\BoundToOpenApiEnum;

#[BoundToOpenApiEnum('SpecExtra')]
enum SpecExtraEnum: string
{
    case Red = 'red';
    case Green = 'green';
    case Blue = 'blue';
    // spec also has 'yellow' which has no PHP case
}
