<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Integration\Schema\Fixture\AutoDiscovery\Issue170Drifting;

use Studio\OpenApiContractTesting\Attribute\BoundToOpenApiEnum;

/**
 * Auto-discovery + enum_spec_base_path drift fixture (issue #170).
 *
 * Bound to the same JSON as the clean fixture, but with a PHP-only `Delta`
 * case missing from the spec. Pins that drift detected through the
 * `enum_spec_base_path` resolution path correctly fails the run when
 * `enum_drift_fail_on_drift` defaults to true.
 */
#[BoundToOpenApiEnum('_shared/components/schemas/enums/BundledExternalEnum.json')]
enum AutoDiscoveryIssue170DriftingEnum: string
{
    case Alpha = 'alpha';
    case Beta = 'beta';
    case Gamma = 'gamma';
    case Delta = 'delta'; // PHP-only — not in spec
}
