<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Integration;

use const JSON_THROW_ON_ERROR;

use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Psr7\OpenApiPsr7Validator;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Symfony\OpenApiAssertions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

use function file_get_contents;
use function json_decode;

final class FrameworkAdapterIdentityParityTest extends TestCase
{
    use OpenApiAssertions;

    /** @var array<string, mixed> */
    private array $baseline;

    protected function setUp(): void
    {
        parent::setUp();

        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
        OpenApiCoverageTracker::reset();

        $contents = file_get_contents(__DIR__ . '/../fixtures/compatibility/v1.9-framework-adapter-parity.json');
        $this->assertIsString($contents);
        $baseline = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($baseline);
        $this->baseline = $baseline;
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();

        parent::tearDown();
    }

    #[Test]
    public function psr7_exchange_result_and_coverage_match_the_v1_9_consumer_baseline(): void
    {
        $request = (new ServerRequest(
            'POST',
            'https://example.test/widgets/42?q=blue',
            ['Content-Type' => 'application/json', 'X-Token' => 'secret'],
            '{"message":"hello"}',
        ))
            ->withQueryParams(['q' => 'blue'])
            ->withCookieParams(['session' => 'abc']);
        $response = new Psr7Response(
            201,
            ['Content-Type' => 'application/json; charset=utf-8', 'X-Trace' => 'trace-1'],
            '{"id":42}',
        );

        $exchange = (new OpenApiPsr7Validator('psr7'))->validateExchange($request, $response);

        $this->assertSame($this->baseline['psr7'], [
            'request' => [
                'outcome' => $exchange->requestResult()->outcome()->value,
                'matched_path' => $exchange->requestResult()->matchedPath(),
            ],
            'response' => [
                'outcome' => $exchange->responseResult()->outcome()->value,
                'matched_path' => $exchange->responseResult()->matchedPath(),
                'matched_status_code' => $exchange->responseResult()->matchedStatusCode(),
                'matched_content_type' => $exchange->responseResult()->matchedContentType(),
            ],
            'coverage' => $this->coverageSummary('psr7'),
        ]);
    }

    #[Test]
    public function symfony_assertions_and_coverage_match_the_v1_9_consumer_baseline(): void
    {
        $request = Request::create('/v1/pets', 'GET');
        $response = new JsonResponse(['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]]);

        $this->assertRequestMatchesOpenApiSchema($request);
        $this->assertResponseMatchesOpenApiSchema($request, $response);

        $this->assertSame($this->baseline['symfony'], [
            'assertions' => ['request' => 'passed', 'response' => 'passed'],
            'coverage' => $this->coverageSummary('petstore-3.0'),
        ]);
    }

    protected function openApiSpec(): string
    {
        return 'petstore-3.0';
    }

    /** @return array{response_covered: int, covered: array<string, true>} */
    private function coverageSummary(string $spec): array
    {
        $coverage = OpenApiCoverageTracker::computeCoverage($spec);

        return [
            'response_covered' => $coverage['responseCovered'],
            'covered' => OpenApiCoverageTracker::getCovered()[$spec] ?? [],
        ];
    }
}
