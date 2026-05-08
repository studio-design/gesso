<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Integration\Schema\Fixture\AutoDiscovery\Issue170;

use Studio\OpenApiContractTesting\Attribute\BoundToOpenApiEnum;

/**
 * Auto-discovery + enum_spec_base_path round-trip fixture (issue #170).
 *
 * The attribute path is bundle-relative (no `..` traversal) and resolves
 * only when `enum_spec_base_path` is set to one level above the bundled
 * root. Used by `OpenApiCoverageExtensionEnumDriftBootstrapTest` to pin
 * that the extension's auto-discovery path consults the new parameter
 * after bootstrap, not the legacy `spec_base_path`.
 */
#[BoundToOpenApiEnum('_shared/components/schemas/enums/BundledExternalEnum.json')]
enum AutoDiscoveryIssue170BundledExternalEnum: string
{
    case Alpha = 'alpha';
    case Beta = 'beta';
    case Gamma = 'gamma';
}
