<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Schema\Fixture;

use Studio\Gesso\Attribute\BoundToOpenApiEnum;

/**
 * Spec carries `[200, 201, "201"]` — the string `"201"` must surface as
 * spec-only drift via strict comparison, not silently match the int 201.
 */
#[BoundToOpenApiEnum('IntegerBackedDrift')]
enum IntegerBackedDriftEnum: int
{
    case Ok = 200;
    case Created = 201;
}
