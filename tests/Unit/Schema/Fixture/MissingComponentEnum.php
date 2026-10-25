<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Schema\Fixture;

use Studio\Gesso\Attribute\BoundToOpenApiEnum;

#[BoundToOpenApiEnum(component: 'DoesNotExist')]
enum MissingComponentEnum: string
{
    case A = 'a';
}
