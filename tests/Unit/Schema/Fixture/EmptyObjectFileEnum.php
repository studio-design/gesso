<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Schema\Fixture;

use Studio\Gesso\Attribute\BoundToOpenApiEnum;

#[BoundToOpenApiEnum('enum-drift/enum-empty-object.json')]
enum EmptyObjectFileEnum: string
{
    case Red = 'red';
}
