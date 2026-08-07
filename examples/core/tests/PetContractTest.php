<?php

declare(strict_types=1);

namespace Examples\Core\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\OpenApiResponseValidator;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;

final class PetContractTest extends TestCase
{
    #[Test]
    public function validates_a_response_without_a_framework_adapter(): void
    {
        // The validator records the coverage observation itself (issue #535),
        // so this assertion is the whole test — the coverage table appears
        // without a manual OpenApiCoverageTracker call.
        $result = (new OpenApiResponseValidator(new StrictRequiredTracker()))->validate(
            'petstore',
            'GET',
            '/pets',
            200,
            [['id' => 1, 'name' => 'Fido']],
            'application/json',
        );

        self::assertTrue($result->isValid(), $result->errorMessage());
    }
}
