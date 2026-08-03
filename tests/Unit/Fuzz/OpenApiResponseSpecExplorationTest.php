<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Fuzz;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Fuzz\ExploredOperation;
use Studio\Gesso\Fuzz\GeneratedResponseCase;
use Studio\Gesso\Fuzz\OpenApiResponseExplorer;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function array_keys;
use function sort;

final class OpenApiResponseSpecExplorationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function executes_every_json_schema_registered_for_an_exact_operation_status(): void
    {
        $contentTypes = [];
        $operationSeeds = [];

        $summary = OpenApiResponseExplorer::exploreSpec('sdk-roundtrip-plan', seed: 41)
            ->includeOperations(['listPets'])
            ->mapResponse(
                'listPets',
                200,
                static function (GeneratedResponseCase $case, ExploredOperation $operation) use (&$contentTypes, &$operationSeeds): array {
                    $contentTypes[(string) $case->contentType] = true;
                    $operationSeeds[$operation->seed] = true;

                    return $case->bodyAsArray() ?? [];
                },
                static fn(array $decoded): array => $decoded,
            )
            ->assertRoundTrips();

        $actualContentTypes = array_keys($contentTypes);
        sort($actualContentTypes);

        $this->assertSame(['application/json', 'application/problem+json'], $actualContentTypes);
        $this->assertCount(1, $operationSeeds);
        $this->assertSame(1, $summary->executedOperations);
        $this->assertSame(2, $summary->executedResponses);
        $this->assertGreaterThanOrEqual(2, $summary->executedCases);
        $this->assertSame([], $summary->decodeFailures);
        $this->assertSame([], $summary->roundTripFailures);
    }
}
