<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Integration\Schema\Fixture\AutoDiscovery\Clean;

use Studio\Gesso\Attribute\BoundToOpenApiEnum;

#[BoundToOpenApiEnum('PetStatusEnum')]
enum AutoDiscoveryCleanEnum: string
{
    case Available = 'available';
    case Pending = 'pending';
    case Sold = 'sold';
}
