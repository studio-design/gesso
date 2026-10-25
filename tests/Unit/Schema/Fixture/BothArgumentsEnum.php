<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Schema\Fixture;

use Studio\Gesso\Attribute\BoundToOpenApiEnum;

#[BoundToOpenApiEnum('enum-drift/matching.json', component: 'Color')]
enum BothArgumentsEnum: string
{
    case Red = 'red';
}
