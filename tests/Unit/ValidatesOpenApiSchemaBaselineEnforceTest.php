<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Baseline\ViolationBaseline;
use Studio\Gesso\Baseline\ViolationBaselineEnforcer;
use Studio\Gesso\Baseline\ViolationFingerprint;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\HttpMethod;
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Tests\Helpers\CreatesTestResponse;

use function json_encode;

require_once __DIR__ . '/../Helpers/LaravelConfigMock.php';

class ValidatesOpenApiSchemaBaselineEnforceTest extends TestCase
{
    use CreatesTestResponse;
    use ValidatesOpenApiSchema;
    private ViolationBaseline $baseline;
    private ViolationBaselineEnforcer $enforcer;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
        OpenApiCoverageTracker::reset();
        $this->baseline = new ViolationBaseline();
        $this->enforcer = new ViolationBaselineEnforcer($this->baseline);
        ViolationBaselineEnforcer::setCurrent($this->enforcer);
    }

    protected function tearDown(): void
    {
        ViolationBaselineEnforcer::resetCurrent();
        self::resetValidatorCache();
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        parent::tearDown();
    }

    #[Test]
    public function a_fully_baselined_failing_response_is_suppressed_and_hit(): void
    {
        $this->baseline->add(new ViolationFingerprint(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            'response.body',
            '/data/*/id',
            'type',
        ));

        $body = (string) json_encode(
            ['data' => [['id' => 'not-an-integer', 'name' => 'Fido']]],
            JSON_THROW_ON_ERROR,
        );

        $this->assertResponseMatchesOpenApiSchema($this->makeTestResponse($body, 200), HttpMethod::GET, '/v1/pets');

        $this->assertSame(1, $this->enforcer->hitCount());
        $this->assertSame([], $this->enforcer->staleEntries());
    }

    #[Test]
    public function a_new_violation_fails_with_the_full_unmodified_message(): void
    {
        $body = (string) json_encode(
            ['data' => [['id' => 'not-an-integer', 'name' => 'Fido']]],
            JSON_THROW_ON_ERROR,
        );

        try {
            $this->assertResponseMatchesOpenApiSchema($this->makeTestResponse($body, 200), HttpMethod::GET, '/v1/pets');
            $this->fail('Expected the assertion to fail on the unbaselined violation.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('OpenAPI schema validation failed for GET /v1/pets', $e->getMessage());
        }
    }

    #[Test]
    public function a_baselined_decode_failure_is_suppressed_and_validation_continues(): void
    {
        $this->baseline->add(new ViolationFingerprint(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            null,
            null,
            'response.body',
            null,
            null,
        ));

        $this->assertResponseMatchesOpenApiSchema($this->makeTestResponse('{invalid', 200), HttpMethod::GET, '/v1/pets');

        $this->assertSame(1, $this->enforcer->hitCount());
    }

    #[Test]
    public function a_baselined_decode_failure_does_not_mask_a_new_violation(): void
    {
        $this->baseline->add(new ViolationFingerprint(
            'petstore-3.0',
            'GET',
            '/v1/unknown',
            null,
            null,
            'response.body',
            null,
            null,
        ));

        $this->expectException(AssertionFailedError::class);

        $this->assertResponseMatchesOpenApiSchema($this->makeTestResponse('{invalid', 418), HttpMethod::GET, '/v1/unknown');
    }

    #[Test]
    public function an_unbaselined_decode_failure_raises_the_original_decode_error(): void
    {
        try {
            $this->assertResponseMatchesOpenApiSchema($this->makeTestResponse('{invalid', 200), HttpMethod::GET, '/v1/pets');
            $this->fail('Expected the decode failure to surface.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('could not be parsed as JSON', $e->getMessage());
        }
    }

    #[Test]
    public function a_valid_response_passes_and_leaves_the_baseline_stale(): void
    {
        $stale = new ViolationFingerprint(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            'response.body',
            '/data/*/id',
            'type',
        );
        $this->baseline->add($stale);

        $body = (string) json_encode(
            ['data' => [['id' => 1, 'name' => 'Fido']]],
            JSON_THROW_ON_ERROR,
        );

        $this->assertResponseMatchesOpenApiSchema($this->makeTestResponse($body, 200), HttpMethod::GET, '/v1/pets');

        $this->assertSame(0, $this->enforcer->hitCount());
        $this->assertSame([$stale], $this->enforcer->staleEntries());
    }

    protected function openApiSpec(): string
    {
        return 'petstore-3.0';
    }
}
