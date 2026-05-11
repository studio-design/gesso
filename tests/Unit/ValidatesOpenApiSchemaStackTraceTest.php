<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\HttpMethod;
use Studio\OpenApiContractTesting\Laravel\ValidatesOpenApiSchema;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\Tests\Helpers\CreatesTestResponse;

use function dirname;
use function is_string;
use function json_encode;
use function str_replace;

// Load namespace-level config() mock before the trait resolves the function call.
require_once __DIR__ . '/../Helpers/LaravelConfigMock.php';

class ValidatesOpenApiSchemaStackTraceTest extends TestCase
{
    use CreatesTestResponse;
    use ValidatesOpenApiSchema;
    private string $libraryLaravelDir;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
        OpenApiCoverageTracker::reset();
        $GLOBALS['__openapi_testing_config'] = [
            'openapi-contract-testing.default_spec' => 'petstore-3.0',
        ];

        $traitFile = (new ReflectionClass(ValidatesOpenApiSchema::class))->getFileName();
        // The trait sits under src/Laravel/ — its directory is the only one
        // we need to detect for "library frames" in this Laravel-specific test.
        // Deriving the directory at runtime keeps the assertions robust against
        // CI checkout paths that don't happen to contain the literal string
        // "/openapi-contract-testing/src/Laravel/".
        $this->libraryLaravelDir = str_replace('\\', '/', dirname((string) $traitFile)) . '/';
    }

    protected function tearDown(): void
    {
        self::resetValidatorCache();
        unset($GLOBALS['__openapi_testing_config']);
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        parent::tearDown();
    }

    #[Test]
    public function explicit_assert_path_produces_clean_trace(): void
    {
        $body = (string) json_encode(['wrong_key' => 'value'], JSON_THROW_ON_ERROR);
        $response = $this->makeTestResponse($body, 200);

        try {
            $this->assertResponseMatchesOpenApiSchema(
                $response,
                HttpMethod::GET,
                '/v1/pets',
            );
            $this->fail('Expected AssertionFailedError was not thrown.');
        } catch (AssertionFailedError $e) {
            $this->assertNoLibraryFrames($e);
            $this->assertUserFramePresent($e);
            $this->assertStringContainsString('OpenAPI schema validation failed', $e->getMessage());
        }
    }

    #[Test]
    public function auto_assert_path_produces_clean_trace(): void
    {
        // The user-reported scenario in #131: failure originates inside the
        // auto-assert hook (maybeAutoAssertOpenApiSchema), which Laravel calls
        // from createTestResponse() during $this->get(...) requests.
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.auto_assert'] = true;

        $body = (string) json_encode(['wrong_key' => 'value'], JSON_THROW_ON_ERROR);
        $response = $this->makeTestResponse($body, 200);

        try {
            $this->maybeAutoAssertOpenApiSchema($response, HttpMethod::GET, '/v1/pets');
            $this->fail('Expected AssertionFailedError was not thrown.');
        } catch (AssertionFailedError $e) {
            $this->assertNoLibraryFrames($e);
            $this->assertUserFramePresent($e);
        }
    }

    #[Test]
    public function fail_path_produces_clean_trace(): void
    {
        // Triggers the failOpenApi() route (line ~433 in the trait): an empty
        // default_spec + no #[OpenApiSpec] attribute resolves to '', which the
        // trait reports via its own fail-with-message branch — different from
        // the assertTrue path the explicit-assert test exercises.
        $GLOBALS['__openapi_testing_config']['openapi-contract-testing.default_spec'] = '';

        $body = (string) json_encode(['data' => []], JSON_THROW_ON_ERROR);
        $response = $this->makeTestResponse($body, 200);

        try {
            $this->assertResponseMatchesOpenApiSchema(
                $response,
                HttpMethod::GET,
                '/v1/pets',
            );
            $this->fail('Expected AssertionFailedError was not thrown.');
        } catch (AssertionFailedError $e) {
            $this->assertNoLibraryFrames($e);
            $this->assertUserFramePresent($e);
            $this->assertStringContainsString('openApiSpec()', $e->getMessage());
        }
    }

    private function assertNoLibraryFrames(AssertionFailedError $e): void
    {
        foreach ($e->getTrace() as $index => $frame) {
            $file = $frame['file'] ?? null;
            if (!is_string($file)) {
                continue;
            }
            $normalized = str_replace('\\', '/', $file);
            $this->assertStringStartsNotWith(
                $this->libraryLaravelDir,
                $normalized,
                "Frame #{$index} should not be inside {$this->libraryLaravelDir} but was: {$file}",
            );
        }
    }

    private function assertUserFramePresent(AssertionFailedError $e): void
    {
        $thisFile = str_replace('\\', '/', __FILE__);
        foreach ($e->getTrace() as $frame) {
            $file = $frame['file'] ?? null;
            if (is_string($file) && str_replace('\\', '/', $file) === $thisFile) {
                return;
            }
        }
        $this->fail('Expected a frame from this test file to survive filtering — only library and Laravel testing-concern frames should be dropped.');
    }
}
