<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Integration\Schema\Fixture;

use Studio\Gesso\Attribute\BoundToOpenApiEnum;

/**
 * Mimics the bundle layout described in issue #165 — a shared schema folder
 * containing standalone JSON files per enum, referenced by relative path.
 */
#[BoundToOpenApiEnum('PetStatusEnum')]
enum IntegrationPetStatusEnum: string
{
    case Available = 'available';
    case Pending = 'pending';
    case Sold = 'sold';
}
