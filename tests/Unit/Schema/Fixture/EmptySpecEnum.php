<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Schema\Fixture;

use Studio\Gesso\Attribute\BoundToOpenApiEnum;

/**
 * Spec carries `enum: []` — the asserter should report all PHP cases as
 * php-only drift rather than treating an empty array as a clean match.
 */
#[BoundToOpenApiEnum('Empty')]
enum EmptySpecEnum: string
{
    case A = 'a';
    case B = 'b';
}
