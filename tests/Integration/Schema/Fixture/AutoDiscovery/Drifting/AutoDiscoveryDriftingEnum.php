<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Integration\Schema\Fixture\AutoDiscovery\Drifting;

use Studio\Gesso\Attribute\BoundToOpenApiEnum;

#[BoundToOpenApiEnum('NotificationCodeEnum')]
enum AutoDiscoveryDriftingEnum: string
{
    case StudioPaymentOld = 'studioPaymentOld';
    case StudioPaymentNew = 'studioPaymentNew';
    // Spec lists "deprecated" but this enum does not (spec-only drift).
    case BetaFeature = 'betaFeature'; // PHP-only drift.
}
