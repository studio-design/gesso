<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\HttpMethod;
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Tests\Helpers\CreatesTestResponse;
use Studio\Gesso\ValidationOutput;
use Studio\Gesso\ValidationOutputFormat;

use function explode;
use function json_decode;
use function json_encode;
use function putenv;

require_once __DIR__ . '/../Helpers/LaravelConfigMock.php';

class ValidatesOpenApiSchemaJsonOutputTest extends TestCase
{
    use CreatesTestResponse;
    use ValidatesOpenApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
        OpenApiCoverageTracker::reset();
        putenv('OPENAPI_VALIDATION_OUTPUT');
        ValidationOutput::reset();
    }

    protected function tearDown(): void
    {
        self::resetValidatorCache();
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        putenv('OPENAPI_VALIDATION_OUTPUT');
        ValidationOutput::reset();
        parent::tearDown();
    }

    #[Test]
    public function response_assertion_failure_renders_the_json_document_after_the_header(): void
    {
        ValidationOutput::use(ValidationOutputFormat::Json);
        $body = (string) json_encode(
            ['data' => [['id' => 'not-an-integer', 'name' => 'Fido']]],
            JSON_THROW_ON_ERROR,
        );
        $response = $this->makeTestResponse($body, 200);

        try {
            $this->assertResponseMatchesOpenApiSchema($response, HttpMethod::GET, '/v1/pets');
            $this->fail('Expected the response assertion to fail.');
        } catch (AssertionFailedError $e) {
            [$header, $document] = explode("\n", $e->getMessage(), 2);

            $this->assertSame(
                'OpenAPI schema validation failed for GET /v1/pets (spec: petstore-3.0):',
                $header,
            );

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($document, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(1, $decoded['schema_version']);
            $this->assertSame('failure', $decoded['outcome']);
            $this->assertSame('response.body', $decoded['issues'][0]['category']);
            $this->assertSame('/data/0/id', $decoded['issues'][0]['instance_path']);
            $this->assertStringContainsString('curl -X GET', $decoded['reproduce_command']);
        }
    }

    #[Test]
    public function json_mode_is_selected_by_the_environment_variable(): void
    {
        putenv('OPENAPI_VALIDATION_OUTPUT=json');
        $body = (string) json_encode(['wrong_key' => 'value'], JSON_THROW_ON_ERROR);
        $response = $this->makeTestResponse($body, 200);

        try {
            $this->assertResponseMatchesOpenApiSchema($response, HttpMethod::GET, '/v1/pets');
            $this->fail('Expected the response assertion to fail.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('"schema_version": 1', $e->getMessage());
            $this->assertStringNotContainsString("\nReproduce: ", $e->getMessage());
            $this->assertStringNotContainsString('Failed asserting that false is true.', $e->getMessage());
        }
    }

    #[Test]
    public function text_mode_failure_is_unchanged_by_default(): void
    {
        $body = (string) json_encode(['wrong_key' => 'value'], JSON_THROW_ON_ERROR);
        $response = $this->makeTestResponse($body, 200);

        try {
            $this->assertResponseMatchesOpenApiSchema($response, HttpMethod::GET, '/v1/pets');
            $this->fail('Expected the response assertion to fail.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString("Reproduce: curl -X GET '/v1/pets'", $e->getMessage());
            $this->assertStringContainsString('Failed asserting that false is true.', $e->getMessage());
            $this->assertStringNotContainsString('schema_version', $e->getMessage());
        }
    }

    protected function openApiSpec(): string
    {
        return 'petstore-3.0';
    }
}
