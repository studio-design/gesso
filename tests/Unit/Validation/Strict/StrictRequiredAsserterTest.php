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
}
