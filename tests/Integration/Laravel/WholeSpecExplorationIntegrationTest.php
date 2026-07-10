<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Integration\Laravel;

use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use LogicException;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\Fuzz\ExploredCase;
use Studio\OpenApiContractTesting\Fuzz\ExploredOperation;
use Studio\OpenApiContractTesting\HttpMethod;
use Studio\OpenApiContractTesting\Laravel\ExploresOpenApiEndpoint;
use Studio\OpenApiContractTesting\Laravel\OpenApiContractTestingServiceProvider;
use Studio\OpenApiContractTesting\Laravel\ValidatesOpenApiSchema;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;

use function dirname;

class WholeSpecExplorationIntegrationTest extends TestCase
{
    use ExploresOpenApiEndpoint;
    use ValidatesOpenApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(dirname(__DIR__, 2) . '/fixtures/specs');
        OpenApiCoverageTracker::reset();
        config()->set('openapi-contract-testing.default_spec', 'whole-spec-exploration');
        config()->set('openapi-contract-testing.auto_assert', true);
    }

    protected function tearDown(): void
    {
        self::resetValidatorCache();
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        parent::tearDown();
    }

    #[Test]
    public function dispatches_generated_cases_through_laravel_and_records_coverage(): void
    {
        $summary = $this->exploreSpec(casesPerOperation: 2, seed: 5)
            ->includeOperations(['listPets', 'createPet'])
            ->dispatchUsing(function (ExploredCase $case, ExploredOperation $operation): TestResponse {
                return match ($case->method) {
                    HttpMethod::GET => $this->get($operation->path, $case->headers),
                    HttpMethod::POST => $this->postJson($operation->path, $case->body, $case->headers),
                    default => throw new LogicException('Unexpected method in Laravel exploration example.'),
                };
            })
            ->assertResponseUsing(static function (mixed $response): void {
                self::assertInstanceOf(TestResponse::class, $response);
                $response->assertSuccessful();
            })
            ->assertResponses();

        $this->assertSame(2, $summary->executedOperations);
        $this->assertSame(4, $summary->executedCases);
        $covered = OpenApiCoverageTracker::getCovered()['whole-spec-exploration'] ?? [];
        $this->assertArrayHasKey('GET /pets', $covered);
        $this->assertArrayHasKey('POST /pets', $covered);
    }

    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [OpenApiContractTestingServiceProvider::class];
    }

    protected function defineRoutes($router): void
    {
        Route::get('/pets', static fn() => response()->noContent(200));
        Route::post('/pets', static fn() => response()->noContent(201));
    }
}
