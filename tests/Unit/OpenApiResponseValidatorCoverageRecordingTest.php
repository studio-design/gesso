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
    public function manual_record_after_validate_counts_as_its_own_observation(): void
    {
        // recordResponse() carries no exchange identity — only the coverage
        // tuple — so the tracker cannot tell "the pre-2.6 quickstart
        // re-recording the exchange validate() just saw" apart from "a second
        // exchange of the same operation recorded by hand". Guessing folded
        // real exchanges and missed batched re-records, so every call counts:
        // validate() records once, and a manual call is always one more
        // observation. Suites still carrying the pre-2.6 manual call see
        // doubled hits (display only — covered/skipped states and gates do
        // not read hits) until they delete that line.
        $result = $this->validator->validate(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            200,
            ['data' => [['id' => 1, 'name' => 'Fido']]],
            'application/json',
        );

        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            $result->matchedPath() ?? '/v1/pets',
            $result->matchedStatusCode() ?? '200',
            $result->matchedContentType(),
            schemaValidated: true,
        );

        $this->assertSame(
            ['200:application/json' => ['state' => 'validated', 'hits' => 2, 'skipReason' => null]],
            $this->recordedResponses('GET /v1/pets'),
        );
    }

    #[Test]
    public function batched_manual_records_after_repeated_validates_all_count(): void
    {
        // validate() twice, then record twice by hand: four observations,
        // four hits. No pairing is inferred between validator records and
        // later manual records.
        $body = ['data' => [['id' => 1, 'name' => 'Fido']]];
        $this->validator->validate('petstore-3.0', 'GET', '/v1/pets', 200, $body, 'application/json');
        $this->validator->validate('petstore-3.0', 'GET', '/v1/pets', 200, $body, 'application/json');
        OpenApiCoverageTracker::recordResponse('petstore-3.0', 'GET', '/v1/pets', '200', 'application/json', schemaValidated: true);
        OpenApiCoverageTracker::recordResponse('petstore-3.0', 'GET', '/v1/pets', '200', 'application/json', schemaValidated: true);

        $this->assertSame(
            ['200:application/json' => ['state' => 'validated', 'hits' => 4, 'skipReason' => null]],
            $this->recordedResponses('GET /v1/pets'),
        );
    }

    #[Test]
    public function validate_without_recording_records_nothing(): void
    {
        $result = $this->validator->validateWithoutRecording(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            200,
            ['data' => [['id' => 1, 'name' => 'Fido']]],
            'application/json',
        );

        $this->assertTrue($result->isValid());
        $this->assertSame([], OpenApiCoverageTracker::exportState()['specs']);
    }

    #[Test]
    public function request_validate_without_recording_records_nothing(): void
    {
        $result = (new OpenApiRequestValidator())->validateWithoutRecording(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            [],
            [],
            null,
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
        $this->assertSame([], OpenApiCoverageTracker::exportState()['specs']);
    }

    #[Test]
    public function manual_record_alone_still_counts(): void
    {
        // Manual recording without a validator call (observations that never
        // went through validate()) must keep working unchanged.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );

        $this->assertSame(
            ['200:application/json' => ['state' => 'validated', 'hits' => 1, 'skipReason' => null]],
            $this->recordedResponses('GET /v1/pets'),
        );
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
