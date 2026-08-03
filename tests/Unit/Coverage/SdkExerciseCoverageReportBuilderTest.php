<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Coverage;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\SdkExerciseCoverageReportBuilder;
use Studio\Gesso\Coverage\SdkExerciseCoverageTracker;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function array_column;
use function array_map;
use function array_unique;
use function array_values;

final class SdkExerciseCoverageReportBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideMalformed_response_structure_fails_loudly_through_the_shared_resolverCases(): iterable
    {
        yield 'responses map' => [
            'sdk-roundtrip-plan-malformed',
            "Malformed 'paths[\"/responses\"].get.responses'",
        ];
        yield 'content map' => [
            'sdk-exercise-coverage-malformed-content',
            "Malformed 'responses[200].content'",
        ];
        yield 'media type' => [
            'sdk-exercise-coverage-malformed-media-type',
            "Malformed 'responses[200].content[\"application/json\"]'",
        ];
    }

    #[Test]
    public function build_lists_only_eligible_json_response_schemas_in_stable_order(): void
    {
        $report = SdkExerciseCoverageReportBuilder::build(
            'sdk-exercise-coverage',
            new SdkExerciseCoverageTracker(),
        );

        $this->assertSame(5, $report['responseTotal']);
        $this->assertSame(0, $report['responseExercised']);
        $this->assertSame(5, $report['responseUnexercised']);
        $this->assertSame([], $report['unexpectedObservations']);
        $this->assertSame([
            ['GET /pets', '200', 'Application/Vnd.Pet+JSON', 'listPets'],
            ['GET /pets', '200', 'application/json', 'listPets'],
            ['GET /pets', '2xx', 'application/json', 'listPets'],
            ['GET /pets', 'default', 'application/problem+json', 'listPets'],
            ['SUBSCRIBE /events', '200', 'application/json', 'subscribeEvents'],
        ], array_map(
            static fn(array $row): array => [
                $row['endpoint'],
                $row['statusKey'],
                $row['contentTypeKey'],
                $row['operationId'],
            ],
            $report['responses'],
        ));
        $this->assertSame([false], array_values(array_unique(array_column($report['responses'], 'exercised'))));
    }

    #[Test]
    public function build_reconciles_hits_and_preserves_custom_method_case_identity(): void
    {
        $tracker = new SdkExerciseCoverageTracker();
        $tracker->recordOn('sdk-exercise-coverage', 'GET', '/pets', '200', 'application/json');
        $tracker->recordOn('sdk-exercise-coverage', 'get', '/pets', '200', 'application/json');
        $tracker->recordOn('sdk-exercise-coverage', 'GET', '/pets', '2xx', 'application/json');
        $tracker->recordOn('sdk-exercise-coverage', 'SUBSCRIBE', '/events', '200', 'application/json');
        $tracker->recordOn('sdk-exercise-coverage', 'subscribe', '/events', '200', 'application/json');
        $tracker->recordOn('sdk-exercise-coverage', 'DELETE', '/orphan', '404', 'application/problem+json');

        $report = SdkExerciseCoverageReportBuilder::build('sdk-exercise-coverage', $tracker);

        $this->assertSame(5, $report['responseTotal']);
        $this->assertSame(3, $report['responseExercised']);
        $this->assertSame(2, $report['responseUnexercised']);
        $this->assertSame([0, 2, 1, 0, 1], array_column($report['responses'], 'hits'));
        $this->assertSame([false, true, true, false, true], array_column($report['responses'], 'exercised'));
        $this->assertSame([
            [
                'endpoint' => 'DELETE /orphan',
                'statusKey' => '404',
                'contentTypeKey' => 'application/problem+json',
                'hits' => 1,
            ],
            [
                'endpoint' => 'subscribe /events',
                'statusKey' => '200',
                'contentTypeKey' => 'application/json',
                'hits' => 1,
            ],
        ], $report['unexpectedObservations']);
    }

    #[Test]
    #[DataProvider('provideMalformed_response_structure_fails_loudly_through_the_shared_resolverCases')]
    public function malformed_response_structure_fails_loudly_through_the_shared_resolver(
        string $specName,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        SdkExerciseCoverageReportBuilder::build(
            $specName,
            new SdkExerciseCoverageTracker(),
        );
    }
}
