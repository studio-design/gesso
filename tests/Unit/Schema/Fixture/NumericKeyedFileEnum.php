<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Schema\Fixture;

use Studio\Gesso\Attribute\BoundToOpenApiEnum;

#[BoundToOpenApiEnum('enum-drift/enum-numeric-keyed-object.json')]
enum NumericKeyedFileEnum: string
{
    case Red = 'red';
}
