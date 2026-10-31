<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Schema\Fixture;

use Studio\Gesso\Attribute\BoundToOpenApiEnum;

#[BoundToOpenApiEnum('Matching')]
enum MatchingEnum: string
{
    case Red = 'red';
    case Green = 'green';
    case Blue = 'blue';
}
