<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Validation\Strict;

use const E_USER_WARNING;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Exception\StrictRequiredDriftException;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredAsserter;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredMode;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredTracker;

use function restore_error_handler;
use function set_error_handler;

class StrictRequiredAsserterTest extends TestCase
{
    private const SPEC_BASE_PATH = __DIR__ . '/../../../fixtures/specs';
    private const SPEC_NAME = 'under-described';

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(self::SPEC_BASE_PATH);
        StrictRequiredTracker::reset();
    }

    protected function tearDown(): void
    {
        StrictRequiredTracker::reset();
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function off_mode_is_noop_even_with_observations(): void
    {
        StrictRequiredTracker::record(self::SPEC_NAME, 'PUT', '/signed-url', '200', 'application/json', ['expires', 'signed_url', 'url']);

        StrictRequiredAsserter::assertNoDrift(StrictRequiredMode::Off);

        $this->assertSame([], StrictRequiredAsserter::detectAll(StrictRequiredMode::Off));
    }

    #[Test]
    public function detects_keys_missing_from_required_when_schema_omits_required(): void
    {
        StrictRequiredTracker::record(self::SPEC_NAME, 'PUT', '/signed-url', '200', 'application/json', ['expires', 'signed_url', 'url']);
        StrictRequiredTracker::record(self::SPEC_NAME, 'PUT', '/signed-url', '200', 'application/json', ['expires', 'signed_url', 'url']);

        $reports = StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn);

        $this->assertCount(1, $reports);
        $this->assertSame(['expires', 'signed_url', 'url'], $reports[0]->missingFromRequired);
        $this->assertSame(2, $reports[0]->hits);
    }

    #[Test]
    public function detects_optional_field_observed_in_every_call(): void
    {
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/projects/{id}', '200', 'application/json', ['id', 'name', 'created_at']);
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/projects/{id}', '200', 'application/json', ['id', 'name', 'created_at']);

        $reports = StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn);

        $this->assertCount(1, $reports);
        $this->assertSame(['created_at'], $reports[0]->missingFromRequired);
    }

    #[Test]
    public function does_not_report_when_always_present_matches_required_exactly(): void
    {
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/users/{id}', '200', 'application/json', ['id', 'name']);
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/users/{id}', '200', 'application/json', ['id', 'name']);

        $reports = StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn);

        $this->assertSame([], $reports);
    }

    #[Test]
    public function does_not_report_when_always_present_is_subset_of_required(): void
    {
        // Even though the response body sometimes omits "name", it's still in the spec's required —
        // a conformance violation, but not an under-description. Conformance is handled by the
        // existing validator, not this asserter.
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/users/{id}', '200', 'application/json', ['id']);

        $reports = StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn);

        $this->assertSame([], $reports);
    }

    #[Test]
    public function does_not_report_when_no_observations_recorded(): void
    {
        $reports = StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn);

        $this->assertSame([], $reports);
    }

    #[Test]
    public function walks_all_of_when_collecting_required(): void
    {
        // The /orders/{id} schema has allOf: [{required: ["id"]}, {properties: {total, currency}}].
        // total + currency are always observed but are not declared required in either branch.
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/orders/{id}', '200', 'application/json', ['id', 'total', 'currency']);
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/orders/{id}', '200', 'application/json', ['id', 'total', 'currency']);

        $reports = StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn);

        $this->assertCount(1, $reports);
        $this->assertSame(['currency', 'total'], $reports[0]->missingFromRequired);
    }

    #[Test]
    public function warn_mode_triggers_user_warning(): void
    {
        StrictRequiredTracker::record(self::SPEC_NAME, 'PUT', '/signed-url', '200', 'application/json', ['expires', 'signed_url', 'url']);

        $captured = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            $captured = ['errno' => $errno, 'errstr' => $errstr];

            return true;
        }, E_USER_WARNING);

        try {
            StrictRequiredAsserter::assertNoDrift(StrictRequiredMode::Warn);
        } finally {
            restore_error_handler();
        }

        $this->assertNotNull($captured);
        $this->assertSame(E_USER_WARNING, $captured['errno']);
        $this->assertStringContainsString('[OpenAPI Strict Required] WARNING', $captured['errstr']);
        $this->assertStringContainsString('PUT /signed-url', $captured['errstr']);
        $this->assertStringContainsString('expires', $captured['errstr']);
    }

    #[Test]
    public function fail_mode_throws_strict_required_drift_exception(): void
    {
        StrictRequiredTracker::record(self::SPEC_NAME, 'PUT', '/signed-url', '200', 'application/json', ['expires', 'signed_url', 'url']);

        try {
            StrictRequiredAsserter::assertNoDrift(StrictRequiredMode::Fail);
            $this->fail('expected StrictRequiredDriftException');
        } catch (StrictRequiredDriftException $e) {
            $this->assertCount(1, $e->reports);
            $this->assertSame('PUT', $e->reports[0]->method);
            $this->assertSame('/signed-url', $e->reports[0]->path);
            $this->assertStringContainsString('[OpenAPI Strict Required] FATAL', $e->getMessage());
        }
    }

    #[Test]
    public function assert_no_drift_in_warn_mode_does_not_throw_when_clean(): void
    {
        $this->expectNotToPerformAssertions();
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/users/{id}', '200', 'application/json', ['id', 'name']);

        // No drift → no warning, no exception. expectNotToPerformAssertions()
        // makes "no thrown exception / no warning" the implicit success.
        StrictRequiredAsserter::assertNoDrift(StrictRequiredMode::Warn);
    }

    #[Test]
    public function assert_no_drift_in_fail_mode_does_not_throw_when_clean(): void
    {
        $this->expectNotToPerformAssertions();
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/users/{id}', '200', 'application/json', ['id', 'name']);

        StrictRequiredAsserter::assertNoDrift(StrictRequiredMode::Fail);
    }

    #[Test]
    public function detect_unresolved_groups_returns_empty_when_all_match(): void
    {
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/users/{id}', '200', 'application/json', ['id', 'name']);

        $this->assertSame([], StrictRequiredAsserter::detectUnresolvedGroups(StrictRequiredMode::Warn));
    }

    #[Test]
    public function detect_unresolved_groups_lists_method_mismatch(): void
    {
        // Spec only declares PUT /signed-url; recording GET for the same path
        // hits the operation-lookup null branch.
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/signed-url', '200', 'application/json', ['expires']);

        $unresolved = StrictRequiredAsserter::detectUnresolvedGroups(StrictRequiredMode::Warn);
        $this->assertCount(1, $unresolved);
        $this->assertStringContainsString('GET /signed-url', $unresolved[0]);
    }

    #[Test]
    public function detect_unresolved_groups_lists_content_type_mismatch(): void
    {
        StrictRequiredTracker::record(self::SPEC_NAME, 'PUT', '/signed-url', '200', 'text/plain', ['expires']);

        $unresolved = StrictRequiredAsserter::detectUnresolvedGroups(StrictRequiredMode::Warn);
        $this->assertCount(1, $unresolved);
        $this->assertStringContainsString('text/plain', $unresolved[0]);
    }

    #[Test]
    public function unresolved_group_does_not_produce_drift_report(): void
    {
        // A lookup-miss must not be reported as drift (would falsely flag
        // every observed key as missing-from-required).
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/signed-url', '200', 'application/json', ['expires']);

        $this->assertSame([], StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn));
    }

    #[Test]
    public function any_of_top_level_schema_yields_noisy_drift_report(): void
    {
        // Documented limitation: anyOf/oneOf are not walked, so the
        // collected `required` is [] and every always-present key surfaces
        // as drift. This pins the documented behavior so a future
        // "let's walk anyOf for symmetry" refactor cannot silently flip it.
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/either-shape', '200', 'application/json', ['a', 'b']);

        $reports = StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn);
        $this->assertCount(1, $reports);
        $this->assertSame(['a', 'b'], $reports[0]->missingFromRequired);
    }

    #[Test]
    public function malformed_non_string_entries_in_required_are_ignored(): void
    {
        // /malformed-required has `required: ["id", 42, null]`. The asserter
        // filters non-strings rather than crashing, so a spec author's typo
        // produces a usable diff against `["id"]`.
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/malformed-required', '200', 'application/json', ['id', 'name']);

        $reports = StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn);
        $this->assertCount(1, $reports);
        $this->assertSame(['name'], $reports[0]->missingFromRequired);
    }
}
