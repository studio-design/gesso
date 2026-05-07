<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Schema\Fixture;

use Studio\OpenApiContractTesting\Attribute\BoundToOpenApiEnum;

#[BoundToOpenApiEnum('enum-drift/matching.json')]
final class NotAnEnum
{
    // Plain class with the BoundToOpenApiEnum attribute. The asserter must
    // reject this — set-membership semantics only make sense for enums.
}
