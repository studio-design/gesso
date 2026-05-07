<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Integration\Schema\Fixture;

use Studio\OpenApiContractTesting\Attribute\BoundToOpenApiEnum;

/**
 * Mimics the bundle layout described in issue #165 — a shared schema folder
 * containing standalone JSON files per enum, referenced by relative path.
 */
#[BoundToOpenApiEnum('enum-drift/_shared/components/schemas/enums/PetStatusEnum.json')]
enum IntegrationPetStatusEnum: string
{
    case Available = 'available';
    case Pending = 'pending';
    case Sold = 'sold';
}
