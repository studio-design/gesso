<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\OpenApiRequestValidator;
use Studio\Gesso\OpenApiResponseValidator;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;

/**
 * Issue #535: the framework-independent validator records its own coverage
 * observation, so the minimal Core/PHPUnit setup prints the coverage table
 * without a manual {@see OpenApiCoverageTracker::recordResponse()} call.
 *
 * The recording contract deliberately mirrors what the Laravel, Symfony, and
 * PSR-7 adapters did by hand before the wiring moved here: record whenever the
 * request path matched the spec (success, failure, and skip alike), use the
 * resolved spec status key when one exists and the literal wire status
 * otherwise, and flag `schemaValidated` as "the body was actually checked",
 * which a schema-violating response still satisfies.
 */
final class OpenApiResponseValidatorCoverageRecordingTest extends TestCase
{
    private OpenApiResponseValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
        $this->validator = new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker());
    }

    protected function tearDown(): void
    {
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function successful_validation_records_the_observation(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            200,
            ['data' => [['id' => 1, 'name' => 'Fido']]],
            'application/json',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame(
            ['200:application/json' => ['state' => 'validated', 'hits' => 1, 'skipReason' => null]],
            $this->recordedResponses('GET /v1/pets'),
        );
    }

    #[Test]
    public function schema_violating_response_is_still_recorded_as_checked(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            200,
            ['data' => [['id' => 'not-an-int', 'name' => 42]]],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertSame(
            ['200:application/json' => ['state' => 'validated', 'hits' => 1, 'skipReason' => null]],
            $this->recordedResponses('GET /v1/pets'),
        );
    }

    #[Test]
    public function undeclared_status_records_under_the_literal_wire_status(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            418,
            ['data' => []],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertSame(
            ['418:*' => ['state' => 'validated', 'hits' => 1, 'skipReason' => null]],
            $this->recordedResponses('GET /v1/pets'),
        );
    }

    #[Test]
    public function skip_pattern_status_records_a_skipped_observation(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            500,
            null,
            'application/json',
        );

        $this->assertTrue($result->isSkipped());
        $this->assertSame(
            ['500:*' => ['state' => 'skipped', 'hits' => 1, 'skipReason' => 'status 500 matched skip pattern 5\d\d']],
            $this->recordedResponses('GET /v1/pets'),
        );
    }

    #[Test]
    public function unmatched_path_records_nothing(): void
    {
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/no/such/path',
            200,
            null,
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertSame([], OpenApiCoverageTracker::exportState()['specs']);
    }

    #[Test]
    public function request_validation_records_request_reached(): void
    {
        $result = (new OpenApiRequestValidator())->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            [],
            [],
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
        $state = OpenApiCoverageTracker::exportState();
        $this->assertTrue($state['specs']['petstore-3.0']['GET /v1/pets']['requestReached'] ?? false);
    }

    #[Test]
    public function two_validations_of_the_same_operation_accumulate_hits(): void
    {
        $body = ['data' => [['id' => 1, 'name' => 'Fido']]];
        $this->validator->validate('petstore-3.0', 'GET', '/v1/pets', 200, $body, 'application/json');
        $this->validator->validate('petstore-3.0', 'GET', '/v1/pets', 200, $body, 'application/json');

        $this->assertSame(
            ['200:application/json' => ['state' => 'validated', 'hits' => 2, 'skipReason' => null]],
            $this->recordedResponses('GET /v1/pets'),
        );
    }

    /**
     * @return array<string, array{state: string, hits: int, skipReason: null|string}>
     */
    private function recordedResponses(string $endpointKey): array
    {
        $state = OpenApiCoverageTracker::exportState();

        return $state['specs']['petstore-3.0'][$endpointKey]['responses'] ?? [];
    }
}
