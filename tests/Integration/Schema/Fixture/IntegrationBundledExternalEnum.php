<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Integration\Schema\Fixture;

use Studio\OpenApiContractTesting\Attribute\BoundToOpenApiEnum;

/**
 * Issue #170 dogfood case: the bundled aggregate (`bundled/front.json`)
 * lives under `spec_base_path`, but the per-enum source JSON lives under
 * `_shared/...`, deliberately outside the bundle root. The attribute path
 * is written *without* `..` traversal — resolution relies on
 * `enum_spec_base_path` pointing one level above `bundled/`.
 */
#[BoundToOpenApiEnum('_shared/components/schemas/enums/BundledExternalEnum.json')]
enum IntegrationBundledExternalEnum: string
{
    case Alpha = 'alpha';
    case Beta = 'beta';
    case Gamma = 'gamma';
}
