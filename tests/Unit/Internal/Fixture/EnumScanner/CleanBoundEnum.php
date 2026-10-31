<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Internal\Fixture\EnumScanner;

use Studio\Gesso\Attribute\BoundToOpenApiEnum;

#[BoundToOpenApiEnum('Clean')]
enum CleanBoundEnum: string
{
    case Alpha = 'alpha';
    case Beta = 'beta';
}
