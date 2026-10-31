<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Schema\Fixture;

use Studio\Gesso\Attribute\BoundToOpenApiEnum;

#[BoundToOpenApiEnum('IntegerBacked')]
enum IntegerBackedEnum: int
{
    case Ok = 200;
    case Created = 201;
    case NoContent = 204;
}
