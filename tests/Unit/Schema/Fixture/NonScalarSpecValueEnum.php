<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Schema\Fixture;

use Studio\Gesso\Attribute\BoundToOpenApiEnum;

#[BoundToOpenApiEnum('NonScalarValue')]
enum NonScalarSpecValueEnum: string
{
    case Red = 'red';
    case Blue = 'blue';
}
