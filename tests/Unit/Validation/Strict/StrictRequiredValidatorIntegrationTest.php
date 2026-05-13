<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Strict;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Studio\OpenApiContractTesting\OpenApiResponseValidator;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredAsserter;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredMode;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredTracker;

class StrictRequiredValidatorIntegrationTest extends TestCase
{
    private OpenApiResponseValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../../fixtures/specs');
        StrictRequiredTracker::reset();
        $this->validator = new OpenApiResponseValidator();
    }

    protected function tearDown(): void
    {
        StrictRequiredTracker::reset();
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function validator_records_observation_on_successful_response(): void
    {
        $result = $this->validator->validate(
            'under-described',
            'PUT',
            '/signed-url',
            200,
            ['expires' => 3600, 'signed_url' => 's3://...', 'url' => 'https://...'],
            'application/json',
        );

        $this->assertTrue($result->isValid());

        $reports = StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn);
        $this->assertCount(1, $reports);
        $this->assertSame(['expires', 'signed_url', 'url'], $reports[0]->missingFromRequired);
        $this->assertSame('PUT', $reports[0]->method);
        $this->assertSame('/signed-url', $reports[0]->path);
    }

    #[Test]
    public function validator_does_not_record_when_body_fails_validation(): void
    {
        // Body lacks "id" (required) — validator returns failure, so the
        // tracker must not record this observation; doing so would poison
        // the intersection for the next legitimate (passing) call.
        $result = $this->validator->validate(
            'under-described',
            'GET',
            '/users/{id}',
            200,
            ['name' => 'alice'],
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertSame([], StrictRequiredTracker::getObservations('under-described'));
    }

    #[Test]
    public function multiple_passing_observations_intersect_to_always_present_keys(): void
    {
        $this->validator->validate(
            'under-described',
            'GET',
            '/projects/{id}',
            200,
            ['id' => '1', 'name' => 'A', 'created_at' => '2026-01-01T00:00:00Z'],
            'application/json',
        );
        $this->validator->validate(
            'under-described',
            'GET',
            '/projects/{id}',
            200,
            ['id' => '2', 'name' => 'B', 'created_at' => '2026-02-01T00:00:00Z'],
            'application/json',
        );

        $reports = StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn);
        $this->assertCount(1, $reports);
        $this->assertSame(['created_at'], $reports[0]->missingFromRequired);
        $this->assertSame(2, $reports[0]->hits);
    }

    #[Test]
    public function single_call_without_optional_field_collapses_intersection(): void
    {
        $this->validator->validate(
            'under-described',
            'GET',
            '/projects/{id}',
            200,
            ['id' => '1', 'name' => 'A', 'created_at' => '2026-01-01T00:00:00Z'],
            'application/json',
        );
        $this->validator->validate(
            'under-described',
            'GET',
            '/projects/{id}',
            200,
            ['id' => '2', 'name' => 'B'],
            'application/json',
        );

        // Second call omits created_at, so it's no longer "always present" →
        // no under-description drift to report.
        $reports = StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn);
        $this->assertSame([], $reports);
    }

    #[Test]
    public function scalar_body_is_not_recorded(): void
    {
        // /scalar returns `type: string`. The validator should pass the body
        // but maybeRecordStrictRequired must skip recording — a scalar has
        // no top-level keys to compare against `required`.
        $this->validator->validate(
            'under-described',
            'GET',
            '/scalar',
            200,
            'hello-world',
            'application/json',
        );

        $this->assertSame([], StrictRequiredTracker::getObservations('under-described'));
    }

    #[Test]
    public function null_body_is_not_recorded(): void
    {
        // null body skips the body validator entirely (no schema branch for
        // null), but the validator may still hit the Success path on a
        // 204-style spec. Recording must be skipped.
        $this->validator->validate(
            'under-described',
            'GET',
            '/scalar',
            200,
            null,
            'application/json',
        );

        $this->assertSame([], StrictRequiredTracker::getObservations('under-described'));
    }

    #[Test]
    public function list_body_is_not_recorded(): void
    {
        // /items returns `type: array, items: {...}`. Recording a list-shape
        // body would collapse a sibling object observation's intersection to
        // [], so the validator skips list-shape bodies even when conformance
        // passes. `required` only makes sense on object schemas.
        $this->validator->validate(
            'under-described',
            'GET',
            '/items',
            200,
            [['id' => '1'], ['id' => '2']],
            'application/json',
        );

        $this->assertSame([], StrictRequiredTracker::getObservations('under-described'));
    }

    #[Test]
    public function stdclass_body_is_coerced_and_recorded(): void
    {
        // Framework-agnostic callers may decode with `json_decode($_, false)`
        // and pass a stdClass body. The validator coerces to an associative
        // array for recording so strict_required does not silently miss
        // observations from non-Laravel adapters.
        $body = new stdClass();
        $body->expires = 3600;
        $body->signed_url = 's3://...';
        $body->url = 'https://...';

        $this->validator->validate(
            'under-described',
            'PUT',
            '/signed-url',
            200,
            $body,
            'application/json',
        );

        $reports = StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn);
        $this->assertCount(1, $reports);
        $this->assertSame(['expires', 'signed_url', 'url'], $reports[0]->missingFromRequired);
    }

    #[Test]
    public function empty_object_body_is_recorded_as_empty_observation(): void
    {
        // Empty object {} lands here as PHP [] after json_decode($_, true).
        // The body validator already disambiguated [] vs {} via stdClass
        // coercion before validating, so recording [] is legitimate: a
        // single observation with no keys collapses the intersection.
        // /users/{id} requires id+name, so an empty body fails conformance —
        // but the documented invariant is "if it reached Success, record [].
        // Use a fixture that accepts an empty object: an additionalProperties-
        // permissive schema would, but we don't have one. Use the loose
        // /signed-url (no required) which accepts {} but with
        // additionalProperties:false, an empty object is valid.
        $this->validator->validate(
            'under-described',
            'PUT',
            '/signed-url',
            200,
            [],
            'application/json',
        );

        $observations = StrictRequiredTracker::getObservations('under-described');
        $this->assertArrayHasKey('PUT /signed-url', $observations);
        $this->assertSame(
            ['hits' => 1, 'alwaysPresent' => []],
            $observations['PUT /signed-url']['200:application/json'],
        );
    }
}
