<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit;

use const E_USER_DEPRECATED;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Attribute\OpenApiSpec;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\HttpMethod;
use Studio\Gesso\Internal\Deprecations;
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Tests\Helpers\CreatesTestResponse;

use function restore_error_handler;
use function set_error_handler;

require_once __DIR__ . '/../Helpers/LaravelConfigMock.php';

#[OpenApiSpec('petstore-3.0')]
final class ValidatesOpenApiSchemaRenamedAssertionTest extends TestCase
{
    use CreatesTestResponse;
    use ValidatesOpenApiSchema;

    /** @var list<string> */
    private array $captured = [];

    protected function setUp(): void
    {
        parent::setUp();
        Deprecations::resetForTesting();
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
        $GLOBALS['__openapi_testing_config'] = [];
        $this->captured = [];
        set_error_handler(function (int $errno, string $message): bool {
            $this->captured[] = $message;

            return true;
        }, E_USER_DEPRECATED);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        unset($GLOBALS['__openapi_testing_config']);
        Deprecations::resetForTesting();
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        parent::tearDown();
    }

    #[Test]
    public function the_old_name_notices_and_still_validates(): void
    {
        try {
            $this->assertResponseMatchesOpenApiSchema($this->makeTestResponse('{"wrong_key":"value"}', 200), HttpMethod::GET, '/v1/pets');
            $this->fail('expected the contract assertion to fail');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('OpenAPI schema validation failed for GET /v1/pets', $e->getMessage());
        }

        $this->assertSame(['trait.validates_open_api_schema.assert_response_matches_open_api_schema' => 1], Deprecations::counts());
        $this->assertCount(1, $this->captured);
    }

    #[Test]
    public function the_new_name_does_not_notice(): void
    {
        $this->assertOpenApiResponse($this->makeTestResponse('{"data":[]}', 200), HttpMethod::GET, '/v1/pets');

        $this->assertSame([], Deprecations::counts());
    }
}
