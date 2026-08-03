<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Fuzz;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Fuzz\GeneratedResponseCase;
use Studio\Gesso\Fuzz\GeneratedResponseCases;
use Studio\Gesso\Fuzz\OpenApiResponseExplorer;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function array_filter;
use function array_map;
use function array_values;
use function json_encode;

class OpenApiResponseExplorerTest extends TestCase
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
     * @return iterable<string, array{string, string, int, ?string, string}>
     */
    public static function provideRejects_unsupported_response_outcomes_loudlyCases(): iterable
    {
        yield 'no content' => ['/no-content', 'GET', 204, null, 'NoContent'];
        yield 'non-JSON only' => ['/text-only', 'GET', 200, null, 'NoJsonContent'];
        yield 'selected non-JSON schema' => ['/text-only', 'GET', 200, 'text/plain', 'NonJsonSchema'];
        yield 'JSON media type without schema' => ['/missing-schema', 'GET', 200, null, 'MissingSchema'];
        yield 'itemSchema streaming response' => ['/stream', 'GET', 200, null, 'ItemSchemaStreaming'];
    }

    /**
     * @return iterable<string, array{string, string, int, string}>
     */
    public static function providePreserves_structured_resolution_failuresCases(): iterable
    {
        yield 'unknown path' => ['/unknown', 'GET', 200, 'PathNotFound'];
        yield 'unknown method' => ['/oauth/introspect', 'GET', 200, 'MethodNotDefined'];
        yield 'unknown status' => ['/oauth/introspect', 'POST', 201, 'StatusNotDeclared'];
    }

    #[Test]
    public function generates_every_incident_branch_plus_requested_extra_cases(): void
    {
        $cases = OpenApiResponseExplorer::explore(
            'sdk-roundtrip',
            'POST',
            '/oauth/introspect',
            200,
            seed: 1,
            extraCases: 2,
        );

        $this->assertInstanceOf(GeneratedResponseCases::class, $cases);
        $this->assertCount(6, $cases);
        $this->assertSame(
            [
                '/properties/aud@0',
                '/properties/aud@1',
                '/properties/aud/oneOf@0',
                '/properties/aud/oneOf@1',
            ],
            array_values(array_filter(array_map(
                static fn(GeneratedResponseCase $case): ?string => $case->pinnedBranch,
                $cases->cases,
            ))),
        );

        foreach ($cases as $index => $case) {
            $this->assertSame(200, $case->status);
            $this->assertSame('application/json', $case->contentType);
            $this->assertSame(1, $case->seed);
            $this->assertSame($index, $case->caseIndex);
        }
        $this->assertStringContainsString('extraCases: 2', $cases->cases[5]->replaySnippet());
    }

    #[Test]
    public function is_deterministic_for_the_same_response_schema_and_seed(): void
    {
        $first = OpenApiResponseExplorer::explore('sdk-roundtrip', 'POST', '/oauth/introspect', 200, seed: 17);
        $second = OpenApiResponseExplorer::explore('sdk-roundtrip', 'POST', '/oauth/introspect', 200, seed: 17);

        $this->assertSame(
            json_encode(array_map(static fn(GeneratedResponseCase $case): mixed => $case->body, $first->cases)),
            json_encode(array_map(static fn(GeneratedResponseCase $case): mixed => $case->body, $second->cases)),
        );
        $this->assertSame(
            array_map(static fn(GeneratedResponseCase $case): ?string => $case->pinnedBranch, $first->cases),
            array_map(static fn(GeneratedResponseCase $case): ?string => $case->pinnedBranch, $second->cases),
        );
    }

    #[Test]
    public function omitted_seed_uses_a_replayable_deterministic_zero_seed(): void
    {
        $first = OpenApiResponseExplorer::explore('sdk-roundtrip', 'POST', '/oauth/introspect', 200);
        $second = OpenApiResponseExplorer::explore('sdk-roundtrip', 'POST', '/oauth/introspect', 200);
        $replayed = OpenApiResponseExplorer::explore(
            'sdk-roundtrip',
            'POST',
            '/oauth/introspect',
            200,
            seed: 0,
        );

        $this->assertSame(0, $first->cases[0]->seed);
        $this->assertStringContainsString('seed: 0', $first->cases[0]->replaySnippet());
        $this->assertSame(
            json_encode(array_map(static fn(GeneratedResponseCase $case): mixed => $case->body, $first->cases)),
            json_encode(array_map(static fn(GeneratedResponseCase $case): mixed => $case->body, $second->cases)),
        );
        $this->assertSame(
            json_encode(array_map(static fn(GeneratedResponseCase $case): mixed => $case->body, $first->cases)),
            json_encode(array_map(static fn(GeneratedResponseCase $case): mixed => $case->body, $replayed->cases)),
        );
    }

    #[Test]
    public function normalizes_content_type_and_replays_the_matched_path_template(): void
    {
        $cases = OpenApiResponseExplorer::explore(
            'sdk-roundtrip',
            'GET',
            '/accounts/123',
            200,
            contentType: 'Application/JSON; charset=UTF-8',
            seed: 9,
        );

        $this->assertCount(1, $cases);
        $this->assertSame('application/json', $cases->cases[0]->contentType);
        $this->assertStringContainsString("'GET', '/accounts/{accountId}', 200", $cases->cases[0]->replaySnippet());
    }

    #[Test]
    public function appends_requested_extra_cases_when_the_schema_has_no_choice_points(): void
    {
        $this->assertCount(
            1,
            OpenApiResponseExplorer::explore('sdk-roundtrip', 'GET', '/accounts/123', 200, extraCases: 0),
        );
        $this->assertCount(
            2,
            OpenApiResponseExplorer::explore('sdk-roundtrip', 'GET', '/accounts/123', 200, extraCases: 1),
        );
        $this->assertCount(
            3,
            OpenApiResponseExplorer::explore('sdk-roundtrip', 'GET', '/accounts/123', 200, extraCases: 2),
        );
    }

    #[Test]
    public function preserves_case_sensitive_openapi_32_additional_operation_names(): void
    {
        $cases = OpenApiResponseExplorer::explore(
            'sdk-roundtrip',
            'CopyThing',
            '/custom-operation',
            200,
            seed: 1,
        );

        $this->assertCount(1, $cases);
        $this->assertStringContainsString("'CopyThing', '/custom-operation'", $cases->cases[0]->replaySnippet());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outcome=MethodNotDefined');

        OpenApiResponseExplorer::explore('sdk-roundtrip', 'copything', '/custom-operation', 200, seed: 1);
    }

    #[Test]
    public function rejects_negative_extra_cases_at_the_public_boundary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('OpenApiResponseExplorer::explore() requires extraCases >= 0');

        OpenApiResponseExplorer::explore(
            'sdk-roundtrip',
            'POST',
            '/oauth/introspect',
            200,
            extraCases: -1,
        );
    }

    #[Test]
    #[DataProvider('provideRejects_unsupported_response_outcomes_loudlyCases')]
    public function rejects_unsupported_response_outcomes_loudly(
        string $path,
        string $method,
        int $status,
        ?string $contentType,
        string $outcome,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outcome=' . $outcome);

        OpenApiResponseExplorer::explore(
            'sdk-roundtrip',
            $method,
            $path,
            $status,
            $contentType,
        );
    }

    #[Test]
    #[DataProvider('providePreserves_structured_resolution_failuresCases')]
    public function preserves_structured_resolution_failures(
        string $path,
        string $method,
        int $status,
        string $outcome,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outcome=' . $outcome);

        OpenApiResponseExplorer::explore('sdk-roundtrip', $method, $path, $status);
    }
}
