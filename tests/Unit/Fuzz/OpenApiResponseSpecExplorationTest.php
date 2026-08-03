<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Fuzz;

use InvalidArgumentException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Studio\Gesso\Fuzz\ExploredOperation;
use Studio\Gesso\Fuzz\GeneratedResponseCase;
use Studio\Gesso\Fuzz\OpenApiResponseExplorer;
use Studio\Gesso\Fuzz\ResponseSpecExplorationSkip;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function array_filter;
use function array_keys;
use function array_map;
use function array_unique;
use function explode;
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

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideMalformed_spec_nodes_fail_at_the_shared_resolver_locationCases(): iterable
    {
        yield 'responses map' => ['malformedResponses', "Malformed 'paths[\"/responses\"].get.responses'"];
        yield 'response entry' => ['malformedResponse', "Malformed 'responses[200]'"];
        yield 'content map' => ['malformedContent', "Malformed 'responses[200].content'"];
        yield 'schema node' => ['malformedSchema', "Malformed 'responses[200].content[\"application/json\"].schema'"];
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

    #[Test]
    public function maps_range_and_default_response_keys_with_deterministic_wire_statuses(): void
    {
        $statuses = [];

        $summary = OpenApiResponseExplorer::exploreSpec('sdk-roundtrip-plan', seed: 7)
            ->includeOperations(['statusFallbacks'])
            ->mapResponse(
                'statusFallbacks',
                '2XX',
                static function (GeneratedResponseCase $case) use (&$statuses): mixed {
                    $statuses[] = $case->status;

                    return $case->bodyAsObject();
                },
                static fn(mixed $decoded): mixed => $decoded,
            )
            ->mapResponse(
                'statusFallbacks',
                'default',
                static function (GeneratedResponseCase $case) use (&$statuses): mixed {
                    $statuses[] = $case->status;

                    return $case->bodyAsObject();
                },
                static fn(mixed $decoded): mixed => $decoded,
            )
            ->assertRoundTrips();

        $actualStatuses = array_unique($statuses);
        sort($actualStatuses);

        $this->assertSame([100, 200], $actualStatuses);
        $this->assertSame(2, $summary->executedResponses);
    }

    #[Test]
    public function unmapped_json_schemas_are_explicit_skips(): void
    {
        $summary = OpenApiResponseExplorer::exploreSpec('sdk-roundtrip-plan')
            ->includeOperations(['listPets'])
            ->mapResponse(
                'listPets',
                200,
                static fn(GeneratedResponseCase $case): mixed => $case->bodyAsObject(),
                static fn(mixed $decoded): mixed => $decoded,
            )
            ->assertRoundTrips();

        $mappingGaps = array_filter(
            $summary->skips,
            static fn(ResponseSpecExplorationSkip $skip): bool => $skip->mappingGap,
        );

        $this->assertCount(1, $mappingGaps);
        $this->assertSame(
            ['202'],
            array_map(static fn(ResponseSpecExplorationSkip $skip): string => $skip->status, $mappingGaps),
        );
        $this->assertSame(
            ['application/json'],
            array_map(static fn(ResponseSpecExplorationSkip $skip): ?string => $skip->contentType, $mappingGaps),
        );
        $this->assertTrue($summary->hasMappingGaps());
    }

    #[Test]
    public function strict_mapping_mode_fails_after_reporting_every_gap(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Unmapped response schemas');
        $this->expectExceptionMessage('status=202');
        $this->expectExceptionMessage('application/json');

        OpenApiResponseExplorer::exploreSpec('sdk-roundtrip-plan')
            ->includeOperations(['listPets'])
            ->mapResponse(
                'listPets',
                200,
                static fn(GeneratedResponseCase $case): mixed => $case->bodyAsObject(),
                static fn(mixed $decoded): mixed => $decoded,
            )
            ->failOnUnmapped()
            ->assertRoundTrips();
    }

    #[Test]
    public function operation_without_an_id_is_an_explicit_mapping_gap(): void
    {
        $summary = OpenApiResponseExplorer::exploreSpec('sdk-roundtrip-plan')
            ->includePaths(['/anonymous'])
            ->assertRoundTrips();

        $this->assertCount(1, $summary->skips);
        $this->assertTrue($summary->skips[0]->mappingGap);
        $this->assertNull($summary->skips[0]->operation->operationId);
        $this->assertStringContainsString("operation '(none)'", $summary->skips[0]->reason);
    }

    #[Test]
    public function unsupported_responses_are_explicit_non_mapping_skips(): void
    {
        $summary = OpenApiResponseExplorer::exploreSpec('sdk-roundtrip-plan')
            ->includeTags(['unsupported'])
            ->assertRoundTrips();

        $this->assertCount(4, $summary->skips);
        $this->assertFalse($summary->hasMappingGaps());
        $outcomes = array_map(
            static fn(ResponseSpecExplorationSkip $skip): string => explode(':', $skip->reason, 2)[0],
            $summary->skips,
        );
        sort($outcomes);

        $this->assertSame(['ItemSchemaStreaming', 'MissingSchema', 'NoContent', 'NoJsonContent'], $outcomes);
    }

    #[Test]
    public function operation_filters_and_deprecated_default_match_request_exploration(): void
    {
        $summary = OpenApiResponseExplorer::exploreSpec('sdk-roundtrip-plan')
            ->includeTags(['public'])
            ->includeMethods(['get'])
            ->includePaths(['/pets'])
            ->includeOperations(['listPets', 'deprecatedPets'])
            ->mapResponse(
                'listPets',
                200,
                static fn(GeneratedResponseCase $case): mixed => $case->bodyAsObject(),
                static fn(mixed $decoded): mixed => $decoded,
            )
            ->assertRoundTrips();

        $this->assertSame(['listPets'], array_map(
            static fn(ExploredOperation $operation): ?string => $operation->operationId,
            $summary->operations,
        ));
    }

    #[Test]
    public function including_deprecated_operations_is_opt_in(): void
    {
        $summary = OpenApiResponseExplorer::exploreSpec('sdk-roundtrip-plan')
            ->includeOperations(['deprecatedPets'])
            ->includeDeprecated()
            ->mapResponse(
                'deprecatedPets',
                200,
                static fn(GeneratedResponseCase $case): mixed => $case->bodyAsObject(),
                static fn(mixed $decoded): mixed => $decoded,
            )
            ->assertRoundTrips();

        $this->assertSame(1, $summary->executedOperations);
    }

    #[Test]
    public function per_operation_seed_is_stable_when_mapping_registration_order_changes(): void
    {
        $firstSeeds = [];
        $secondSeeds = [];

        OpenApiResponseExplorer::exploreSpec('sdk-roundtrip-plan', seed: 41)
            ->includeOperations(['listPets', 'createPet'])
            ->mapResponse('createPet', 201, self::captureSeed($firstSeeds), static fn(mixed $value): mixed => $value)
            ->mapResponse('listPets', 200, self::captureSeed($firstSeeds), static fn(mixed $value): mixed => $value)
            ->assertRoundTrips();

        OpenApiResponseExplorer::exploreSpec('sdk-roundtrip-plan', seed: 41)
            ->includeOperations(['listPets', 'createPet'])
            ->mapResponse('listPets', 200, self::captureSeed($secondSeeds), static fn(mixed $value): mixed => $value)
            ->mapResponse('createPet', 201, self::captureSeed($secondSeeds), static fn(mixed $value): mixed => $value)
            ->assertRoundTrips();

        $this->assertSame($firstSeeds, $secondSeeds);
        $this->assertSame(209691301, $firstSeeds['listPets']);
    }

    #[Test]
    public function duplicate_and_invalid_mappings_fail_before_execution(): void
    {
        $plan = OpenApiResponseExplorer::exploreSpec('sdk-roundtrip-plan')
            ->mapResponse('listPets', 200, static fn(): null => null, static fn(): null => null);

        try {
            $plan->mapResponse('listPets', '200', static fn(): null => null, static fn(): null => null);
            $this->fail('Expected duplicate mapping registration to fail.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('already registered', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exact status, range status, or default');
        OpenApiResponseExplorer::exploreSpec('sdk-roundtrip-plan')
            ->mapResponse('listPets', 'success', static fn(): null => null, static fn(): null => null);
    }

    #[Test]
    public function aggregate_failure_distinguishes_decode_and_round_trip_failures(): void
    {
        $decodeAttempts = 0;
        $roundTripAttempts = 0;

        try {
            OpenApiResponseExplorer::exploreSpec('sdk-roundtrip-plan', seed: 9)
                ->includeOperations(['listPets', 'createPet'])
                ->mapResponse(
                    'listPets',
                    200,
                    static function () use (&$decodeAttempts): never {
                        $decodeAttempts++;

                        throw new RuntimeException('decoder rejected payload');
                    },
                    static fn(mixed $decoded): mixed => $decoded,
                )
                ->mapResponse(
                    'createPet',
                    201,
                    static fn(GeneratedResponseCase $case): mixed => $case->bodyAsObject(),
                    static function () use (&$roundTripAttempts): array {
                        $roundTripAttempts++;

                        return [];
                    },
                )
                ->assertRoundTrips();
            $this->fail('Expected aggregate SDK round-trip failure.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('Decode failures', $e->getMessage());
            $this->assertStringContainsString('decoder rejected payload', $e->getMessage());
            $this->assertStringContainsString('Round-trip failures', $e->getMessage());
            $this->assertStringContainsString('operation=createPet', $e->getMessage());
            $this->assertStringContainsString('replay:', $e->getMessage());
        }

        $this->assertGreaterThanOrEqual(2, $decodeAttempts);
        $this->assertGreaterThanOrEqual(1, $roundTripAttempts);
    }

    #[Test]
    #[DataProvider('provideMalformed_spec_nodes_fail_at_the_shared_resolver_locationCases')]
    public function malformed_spec_nodes_fail_at_the_shared_resolver_location(
        string $operationId,
        string $expectedLocation,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedLocation);

        OpenApiResponseExplorer::exploreSpec('sdk-roundtrip-plan-malformed')
            ->includeOperations([$operationId])
            ->assertRoundTrips();
    }

    /**
     * @param array<string, int> $seeds
     *
     * @return callable(GeneratedResponseCase, ExploredOperation): mixed
     */
    private static function captureSeed(array &$seeds): callable
    {
        return static function (GeneratedResponseCase $case, ExploredOperation $operation) use (&$seeds): mixed {
            $seeds[(string) $operation->operationId] = $case->seed;

            return $case->bodyAsObject();
        };
    }
}
