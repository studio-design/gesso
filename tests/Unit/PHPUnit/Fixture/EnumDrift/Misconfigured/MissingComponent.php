<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\PHPUnit\Fixture\EnumDrift\Misconfigured;

use Studio\Gesso\Attribute\BoundToOpenApiEnum;

#[BoundToOpenApiEnum('DoesNotExist')]
enum MissingComponent: string
{
    case Anything = 'anything';
}
