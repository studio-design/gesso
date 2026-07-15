<?php

declare(strict_types=1);

namespace Examples\Core\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\OpenApiResponseValidator;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;

final class PetContractTest extends TestCase
{
    #[Test]
    public function validates_a_response_without_a_framework_adapter(): void
    {
        $result = (new OpenApiResponseValidator(new StrictRequiredTracker()))->validate(
            'petstore',
            'GET',
            '/pets',
            200,
            [['id' => 1, 'name' => 'Fido']],
            'application/json',
        );

        self::assertTrue($result->isValid(), $result->errorMessage());

        OpenApiCoverageTracker::recordResponse(
            'petstore',
            'GET',
            $result->matchedPath() ?? '/pets',
            $result->matchedStatusCode() ?? '200',
            $result->matchedContentType(),
            schemaValidated: true,
        );
    }
}
