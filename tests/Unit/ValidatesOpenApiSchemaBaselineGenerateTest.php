<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Baseline\ViolationBaselineCollector;
use Studio\Gesso\Baseline\ViolationFingerprint;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\HttpMethod;
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Tests\Helpers\CreatesTestResponse;

use function json_encode;

require_once __DIR__ . '/../Helpers/LaravelConfigMock.php';

class ValidatesOpenApiSchemaBaselineGenerateTest extends TestCase
{
    use CreatesTestResponse;
    use ValidatesOpenApiSchema;
    private ViolationBaselineCollector $collector;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
        OpenApiCoverageTracker::reset();
        $this->collector = new ViolationBaselineCollector();
        ViolationBaselineCollector::setCurrent($this->collector);
    }

    protected function tearDown(): void
    {
        ViolationBaselineCollector::resetCurrent();
        self::resetValidatorCache();
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        parent::tearDown();
    }

    #[Test]
    public function a_failing_response_assertion_is_demoted_and_its_fingerprint_recorded(): void
    {
        $body = (string) json_encode(
            ['data' => [['id' => 'not-an-integer', 'name' => 'Fido']]],
            JSON_THROW_ON_ERROR,
        );
        $response = $this->makeTestResponse($body, 200);

        $this->assertResponseMatchesOpenApiSchema($response, HttpMethod::GET, '/v1/pets');

        $this->assertTrue($this->collector->baseline()->contains(new ViolationFingerprint(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            'response.body',
            '/data/*/id',
            'type',
        )));
    }

    #[Test]
    public function a_valid_response_records_nothing(): void
    {
        $body = (string) json_encode(
            ['data' => [['id' => 1, 'name' => 'Fido']]],
            JSON_THROW_ON_ERROR,
        );
        $response = $this->makeTestResponse($body, 200);

        $this->assertResponseMatchesOpenApiSchema($response, HttpMethod::GET, '/v1/pets');

        $this->assertSame(0, $this->collector->baseline()->count());
    }

    protected function openApiSpec(): string
    {
        return 'petstore-3.0';
    }
}
