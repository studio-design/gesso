<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Integration\Schema\Fixture;

use Studio\OpenApiContractTesting\Attribute\BoundToOpenApiEnum;

/**
 * Drifts on purpose: 'betaFeature' is a PHP-only addition (drift type 1),
 * 'deprecated' is in the spec but missing here (drift type 2). Used to
 * exercise the headline "31 silent drift cases" scenario from the issue.
 */
#[BoundToOpenApiEnum('enum-drift/_shared/components/schemas/enums/NotificationCodeEnum.json')]
enum IntegrationNotificationCodeEnum: string
{
    case StudioPaymentOld = 'studioPaymentOld';
    case StudioPaymentNew = 'studioPaymentNew';
    case BetaFeature = 'betaFeature'; // PHP-only — not in spec
    // Note: spec also has 'deprecated' which has no PHP case here.
}
