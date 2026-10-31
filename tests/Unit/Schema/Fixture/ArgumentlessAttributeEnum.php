<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Schema\Fixture;

use Studio\Gesso\Attribute\BoundToOpenApiEnum;

// @phpstan-ignore arguments.count (deliberately missing its argument)
#[BoundToOpenApiEnum]
enum ArgumentlessAttributeEnum: string
{
    case Red = 'red';
}
