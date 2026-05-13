<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Strict;

use const E_USER_WARNING;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Studio\OpenApiContractTesting\OpenApiResponseValidator;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredAsserter;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredMode;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredPerCallChecker;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredPerCallMode;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredTracker;

use function restore_error_handler;
use function set_error_handler;

final class StrictRequiredValidatorIntegrationTest extends TestCase
{
    private OpenApiResponseValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../../fixtures/specs');
        StrictRequiredTracker::reset();
        StrictRequiredPerCallChecker::reset();
        $this->validator = new OpenApiResponseValidator();
    }

    protected function tearDown(): void
    {
        StrictRequiredTracker::reset();
        StrictRequiredPerCallChecker::reset();
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
    public function list_body_records_star_pointer_observations(): void
    {
        // /items returns `type: array, items: { required: ["id"], ... }`.
        // Post-#227 the validator walks into the list and records `[*]`
        // pointers for the element-shape. Clean case: every element has
        // exactly `id`, so no drift is reported.
        $this->validator->validate(
            'under-described',
            'GET',
            '/items',
            200,
            [['id' => '1'], ['id' => '2']],
            'application/json',
        );

        $observations = StrictRequiredTracker::getObservations('under-described');
        $this->assertSame(
            ['hits' => 1, 'pointers' => ['[*]' => ['id']]],
            $observations['GET /items']['200:application/json'],
        );
        $this->assertSame([], StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn));
    }

    #[Test]
    public function list_body_under_described_per_element_is_reported(): void
    {
        // Under-described case: each /items element returns `id`+`name`,
        // but spec only requires `id`. Expect a drift report at `[*]`.
        $this->validator->validate(
            'under-described',
            'GET',
            '/items',
            200,
            [['id' => '1', 'name' => 'A'], ['id' => '2', 'name' => 'B']],
            'application/json',
        );
        $this->validator->validate(
            'under-described',
            'GET',
            '/items',
            200,
            [['id' => '3', 'name' => 'C']],
            'application/json',
        );

        $reports = StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn);
        $this->assertCount(1, $reports);
        $this->assertSame('[*]', $reports[0]->schemaPointer);
        $this->assertSame(['name'], $reports[0]->missingFromRequired);
    }

    #[Test]
    public function nested_object_property_drift_is_recorded(): void
    {
        // /teams/{id} → data.required=["name"], impl always returns
        // created_at too. Walker records `/data` → ['created_at','name'],
        // asserter diffs against ['name'] and reports `created_at`.
        $this->validator->validate(
            'under-described',
            'GET',
            '/teams/{id}',
            200,
            [
                'id' => '1',
                'data' => ['name' => 'A', 'created_at' => '2026-01-01T00:00:00Z'],
            ],
            'application/json',
        );
        $this->validator->validate(
            'under-described',
            'GET',
            '/teams/{id}',
            200,
            [
                'id' => '2',
                'data' => ['name' => 'B', 'created_at' => '2026-02-01T00:00:00Z'],
            ],
            'application/json',
        );

        $reports = StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn);
        $this->assertCount(1, $reports);
        $this->assertSame('/data', $reports[0]->schemaPointer);
        $this->assertSame(['created_at'], $reports[0]->missingFromRequired);
        $this->assertSame(2, $reports[0]->hits);
    }

    #[Test]
    public function array_of_objects_under_envelope_is_recorded(): void
    {
        // /catalog has items.required=["id"]; impl always returns
        // name+created_at per item. Walker records `/items[*]` with the
        // intersection of element keys.
        $this->validator->validate(
            'under-described',
            'GET',
            '/catalog',
            200,
            [
                'items' => [
                    ['id' => '1', 'name' => 'A', 'created_at' => '2026-01-01T00:00:00Z'],
                    ['id' => '2', 'name' => 'B', 'created_at' => '2026-02-01T00:00:00Z'],
                ],
            ],
            'application/json',
        );

        $reports = StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn);
        $this->assertCount(1, $reports);
        $this->assertSame('/items[*]', $reports[0]->schemaPointer);
        $this->assertSame(['created_at', 'name'], $reports[0]->missingFromRequired);
    }

    #[Test]
    public function empty_nested_array_drops_star_pointer_across_observations(): void
    {
        // obs#1 has items=[{id,name}], obs#2 has items=[]. The empty array
        // does not contribute a `[*]` pointer to obs#2, so the tracker's
        // "absence drops the pointer" rule kills the [*] row entirely.
        $this->validator->validate(
            'under-described',
            'GET',
            '/catalog',
            200,
            ['items' => [['id' => '1', 'name' => 'A']]],
            'application/json',
        );
        $this->validator->validate(
            'under-described',
            'GET',
            '/catalog',
            200,
            ['items' => []],
            'application/json',
        );

        $observations = StrictRequiredTracker::getObservations('under-described');
        $row = $observations['GET /catalog']['200:application/json'];
        $this->assertSame(2, $row['hits']);
        $this->assertSame(['/' => ['items']], $row['pointers']);
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
    public function per_call_warn_emits_warning_on_first_observation(): void
    {
        // Per-call mode (Issue #228) is the lightweight gate: a single
        // observation with optional fields present must surface as a
        // warning immediately, without waiting for the run-level
        // intersection at ExecutionFinished. /signed-url has no `required`
        // declared, so all three keys drift on the first call.
        StrictRequiredPerCallChecker::configure(StrictRequiredPerCallMode::Warn);

        $captured = $this->captureFirstWarning(function (): void {
            $this->validator->validate(
                'under-described',
                'PUT',
                '/signed-url',
                200,
                ['expires' => 3600, 'signed_url' => 's3://...', 'url' => 'https://...'],
                'application/json',
            );
        });

        $this->assertNotNull($captured);
        $this->assertStringContainsString('[OpenAPI Strict Required per-call] WARN:', $captured);
        $this->assertStringContainsString('PUT /signed-url', $captured);
    }

    #[Test]
    public function per_call_off_emits_no_warning_even_with_drift(): void
    {
        // Default mode is Off. The same body that fires above must stay
        // silent here so existing users see zero behaviour change after
        // upgrading to a release that ships per-call mode.
        $captured = $this->captureFirstWarning(function (): void {
            $this->validator->validate(
                'under-described',
                'PUT',
                '/signed-url',
                200,
                ['expires' => 3600, 'signed_url' => 's3://...', 'url' => 'https://...'],
                'application/json',
            );
        });

        $this->assertNull($captured);
    }

    #[Test]
    public function per_call_does_not_fire_when_response_fails_conformance(): void
    {
        // Per-call hangs off the same Success-only branch as the tracker:
        // a conformance-failing response must not trigger a per-call
        // warning. /users/{id} requires `id`+`name`; omitting `id` fails.
        StrictRequiredPerCallChecker::configure(StrictRequiredPerCallMode::Warn);

        $captured = $this->captureFirstWarning(function (): void {
            $this->validator->validate(
                'under-described',
                'GET',
                '/users/{id}',
                200,
                ['name' => 'alice'],
                'application/json',
            );
        });

        $this->assertNull($captured);
    }

    #[Test]
    public function per_call_warn_and_run_level_warn_are_independent(): void
    {
        // CIs may run per-call=warn for early visibility AND run-level=warn
        // for the safer aggregate. A single observation must trigger the
        // per-call warning AND record into the tracker so the run-level
        // asserter still has data to diff at ExecutionFinished.
        StrictRequiredPerCallChecker::configure(StrictRequiredPerCallMode::Warn);

        $captured = $this->captureFirstWarning(function (): void {
            $this->validator->validate(
                'under-described',
                'PUT',
                '/signed-url',
                200,
                ['expires' => 3600, 'signed_url' => 's3://...', 'url' => 'https://...'],
                'application/json',
            );
        });

        $this->assertNotNull($captured);

        $observations = StrictRequiredTracker::getObservations('under-described');
        $this->assertSame(
            ['hits' => 1, 'pointers' => ['/' => ['expires', 'signed_url', 'url']]],
            $observations['PUT /signed-url']['200:application/json'],
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
            ['hits' => 1, 'pointers' => ['/' => []]],
            $observations['PUT /signed-url']['200:application/json'],
        );
    }

    /**
     * Capture the first `E_USER_WARNING` triggered by `$callable` and
     * return its message. Returns `null` if no warning was triggered.
     */
    private function captureFirstWarning(callable $callable): ?string
    {
        $captured = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            if ($captured === null && $errno === E_USER_WARNING) {
                $captured = $errstr;
            }

            return true;
        }, E_USER_WARNING);

        try {
            $callable();
        } finally {
            restore_error_handler();
        }

        return $captured;
    }
}
