<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Validation\Strict;

use const E_USER_WARNING;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Exception\StrictRequiredDriftException;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Strict\StrictRequiredAsserter;
use Studio\Gesso\Validation\Strict\StrictRequiredMode;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;

use function array_map;
use function restore_error_handler;
use function set_error_handler;

final class StrictRequiredAsserterTest extends TestCase
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
        StrictRequiredTracker::record(self::SPEC_NAME, 'PUT', '/signed-url', '200', 'application/json', [
            '/' => ['expires', 'signed_url', 'url'],
        ]);

        StrictRequiredAsserter::assertNoDrift(StrictRequiredMode::Off);

        $this->assertSame([], StrictRequiredAsserter::detectAll(StrictRequiredMode::Off));
    }

    #[Test]
    public function detects_keys_missing_from_required_when_schema_omits_required(): void
    {
        StrictRequiredTracker::record(self::SPEC_NAME, 'PUT', '/signed-url', '200', 'application/json', [
            '/' => ['expires', 'signed_url', 'url'],
        ]);
        StrictRequiredTracker::record(self::SPEC_NAME, 'PUT', '/signed-url', '200', 'application/json', [
            '/' => ['expires', 'signed_url', 'url'],
        ]);

        $reports = StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn);

        $this->assertCount(1, $reports);
        $this->assertSame(['expires', 'signed_url', 'url'], $reports[0]->missingFromRequired);
        $this->assertSame(2, $reports[0]->hits);
        $this->assertSame('/', $reports[0]->schemaPointer);
    }

    #[Test]
    public function detects_optional_field_observed_in_every_call(): void
    {
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/projects/{id}', '200', 'application/json', [
            '/' => ['id', 'name', 'created_at'],
        ]);
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/projects/{id}', '200', 'application/json', [
            '/' => ['id', 'name', 'created_at'],
        ]);

        $reports = StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn);

        $this->assertCount(1, $reports);
        $this->assertSame(['created_at'], $reports[0]->missingFromRequired);
        $this->assertSame('/', $reports[0]->schemaPointer);
    }

    #[Test]
    public function does_not_report_when_always_present_matches_required_exactly(): void
    {
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/users/{id}', '200', 'application/json', [
            '/' => ['id', 'name'],
        ]);
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/users/{id}', '200', 'application/json', [
            '/' => ['id', 'name'],
        ]);

        $reports = StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn);

        $this->assertSame([], $reports);
    }

    #[Test]
    public function does_not_report_when_always_present_is_subset_of_required(): void
    {
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/users/{id}', '200', 'application/json', [
            '/' => ['id'],
        ]);

        $reports = StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn);

        $this->assertSame([], $reports);
    }

    #[Test]
    public function does_not_report_when_no_observations_recorded(): void
    {
        $this->assertSame([], StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn));
    }

    #[Test]
    public function walks_all_of_when_collecting_required(): void
    {
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/orders/{id}', '200', 'application/json', [
            '/' => ['id', 'total', 'currency'],
        ]);
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/orders/{id}', '200', 'application/json', [
            '/' => ['id', 'total', 'currency'],
        ]);

        $reports = StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn);

        $this->assertCount(1, $reports);
        $this->assertSame(['currency', 'total'], $reports[0]->missingFromRequired);
        $this->assertSame('/', $reports[0]->schemaPointer);
    }

    #[Test]
    public function warn_mode_triggers_user_warning_with_pointer_suffix(): void
    {
        StrictRequiredTracker::record(self::SPEC_NAME, 'PUT', '/signed-url', '200', 'application/json', [
            '/' => ['expires', 'signed_url', 'url'],
        ]);

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
        // Renderer now includes `:<pointer>` after the content-type — root
        // pointer renders as `:/` uniformly.
        $this->assertStringContainsString('PUT /signed-url  200  application/json:/', $captured['errstr']);
        $this->assertStringContainsString('expires', $captured['errstr']);
    }

    #[Test]
    public function fail_mode_throws_strict_required_drift_exception(): void
    {
        StrictRequiredTracker::record(self::SPEC_NAME, 'PUT', '/signed-url', '200', 'application/json', [
            '/' => ['expires', 'signed_url', 'url'],
        ]);

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
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/users/{id}', '200', 'application/json', [
            '/' => ['id', 'name'],
        ]);

        StrictRequiredAsserter::assertNoDrift(StrictRequiredMode::Warn);
    }

    #[Test]
    public function assert_no_drift_in_fail_mode_does_not_throw_when_clean(): void
    {
        $this->expectNotToPerformAssertions();
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/users/{id}', '200', 'application/json', [
            '/' => ['id', 'name'],
        ]);

        StrictRequiredAsserter::assertNoDrift(StrictRequiredMode::Fail);
    }

    #[Test]
    public function detect_unresolved_groups_returns_empty_when_all_match(): void
    {
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/users/{id}', '200', 'application/json', [
            '/' => ['id', 'name'],
        ]);

        $this->assertSame([], StrictRequiredAsserter::detectUnresolvedGroups(StrictRequiredMode::Warn));
    }

    #[Test]
    public function detect_unresolved_groups_lists_method_mismatch(): void
    {
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/signed-url', '200', 'application/json', [
            '/' => ['expires'],
        ]);

        $unresolved = StrictRequiredAsserter::detectUnresolvedGroups(StrictRequiredMode::Warn);
        $this->assertCount(1, $unresolved);
        $this->assertStringContainsString('GET /signed-url', $unresolved[0]);
    }

    #[Test]
    public function detect_unresolved_groups_lists_content_type_mismatch(): void
    {
        StrictRequiredTracker::record(self::SPEC_NAME, 'PUT', '/signed-url', '200', 'text/plain', [
            '/' => ['expires'],
        ]);

        $unresolved = StrictRequiredAsserter::detectUnresolvedGroups(StrictRequiredMode::Warn);
        $this->assertCount(1, $unresolved);
        $this->assertStringContainsString('text/plain', $unresolved[0]);
    }

    #[Test]
    public function unresolved_group_does_not_produce_drift_report(): void
    {
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/signed-url', '200', 'application/json', [
            '/' => ['expires'],
        ]);

        $this->assertSame([], StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn));
    }

    #[Test]
    public function any_of_top_level_schema_yields_unwalkable_note_not_drift(): void
    {
        // anyOf nodes are NOT descended into (no AND-semantic for required
        // across disjunctions). Rather than emit misleading "add to
        // required" drift advice, the asserter surfaces these as an
        // unwalkable NOTE that the subscriber renders separately.
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/either-shape', '200', 'application/json', [
            '/' => ['a', 'b'],
        ]);

        $this->assertSame([], StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn));

        $unwalkable = StrictRequiredAsserter::detectUnwalkableNodes(StrictRequiredMode::Warn);
        $this->assertCount(1, $unwalkable);
        $this->assertStringContainsString('GET /either-shape', $unwalkable[0]);
        $this->assertStringContainsString('anyOf', $unwalkable[0]);
    }

    #[Test]
    public function one_of_top_level_schema_yields_unwalkable_note_not_drift(): void
    {
        // Symmetric pin for `oneOf` — same descent-stop rationale as
        // `anyOf`. Without this, a future refactor that accidentally added
        // `oneOf` handling to inferShape() / collectPropertyBranches()
        // would silently change drift output for every oneOf-rooted spec.
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/either-shape-oneof', '200', 'application/json', [
            '/' => ['a', 'b'],
        ]);

        $this->assertSame([], StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn));

        $unwalkable = StrictRequiredAsserter::detectUnwalkableNodes(StrictRequiredMode::Warn);
        $this->assertCount(1, $unwalkable);
        $this->assertStringContainsString('GET /either-shape-oneof', $unwalkable[0]);
        $this->assertStringContainsString('oneOf', $unwalkable[0]);
    }

    #[Test]
    public function malformed_non_string_entries_in_required_are_ignored(): void
    {
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/malformed-required', '200', 'application/json', [
            '/' => ['id', 'name'],
        ]);

        $reports = StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn);
        $this->assertCount(1, $reports);
        $this->assertSame(['name'], $reports[0]->missingFromRequired);
    }

    #[Test]
    public function detects_nested_required_drift_in_object_property(): void
    {
        // /teams/{id} has data.required=["name"]; impl always returns
        // created_at too — should drift at pointer `/data`.
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/teams/{id}', '200', 'application/json', [
            '/' => ['data', 'id'],
            '/data' => ['created_at', 'name'],
        ]);

        $reports = StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn);

        $this->assertCount(1, $reports);
        $this->assertSame('/data', $reports[0]->schemaPointer);
        $this->assertSame(['created_at'], $reports[0]->missingFromRequired);
    }

    #[Test]
    public function detects_array_element_required_drift_using_star_pointer(): void
    {
        // /catalog has items[*].required=["id"]; impl always returns
        // name + created_at per element — drift at pointer `/items[*]`.
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/catalog', '200', 'application/json', [
            '/' => ['items'],
            '/items[*]' => ['created_at', 'id', 'name'],
        ]);

        $reports = StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn);

        $this->assertCount(1, $reports);
        $this->assertSame('/items[*]', $reports[0]->schemaPointer);
        $this->assertSame(['created_at', 'name'], $reports[0]->missingFromRequired);
    }

    #[Test]
    public function nested_drift_emits_one_report_per_drifting_pointer(): void
    {
        // /deep's allOf merges `required=["meta"]` only at root, so
        // observing `rows` at `/` is also drift. Combined with meta.total
        // (optional but always-returned) and rows[*].label (optional but
        // always-returned) the expectation is three pointer-distinct
        // reports, sorted by pointer string.
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/deep', '200', 'application/json', [
            '/' => ['meta', 'rows'],
            '/meta' => ['page', 'total'],
            '/rows[*]' => ['id', 'label'],
        ]);

        $reports = StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn);

        $this->assertCount(3, $reports);
        $this->assertSame('/', $reports[0]->schemaPointer);
        $this->assertSame(['rows'], $reports[0]->missingFromRequired);
        $this->assertSame('/meta', $reports[1]->schemaPointer);
        $this->assertSame(['total'], $reports[1]->missingFromRequired);
        $this->assertSame('/rows[*]', $reports[2]->schemaPointer);
        $this->assertSame(['label'], $reports[2]->missingFromRequired);
    }

    #[Test]
    public function nested_pointer_appears_in_renderer_message(): void
    {
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/catalog', '200', 'application/json', [
            '/' => ['items'],
            '/items[*]' => ['created_at', 'id'],
        ]);

        $reports = StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn);
        $message = StrictRequiredAsserter::renderMessage($reports, false);

        $this->assertStringContainsString('GET /catalog  200  application/json:/items[*]', $message);
        $this->assertStringContainsString('created_at', $message);
    }

    #[Test]
    public function report_ordering_is_deterministic_by_pointer(): void
    {
        // Three pointers all drifting; renderer must emit them in ksort
        // order regardless of recording sequence.
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/deep', '200', 'application/json', [
            '/rows[*]' => ['id', 'label'],
            '/' => ['extra', 'meta', 'rows'],
            '/meta' => ['page', 'total'],
        ]);

        $reports = StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn);
        $pointers = array_map(static fn($r) => $r->schemaPointer, $reports);

        // Sort with PHP's ksort-like semantics — expectation is `/` first,
        // then `/meta`, then `/rows[*]` alphabetically.
        $this->assertSame(['/', '/meta', '/rows[*]'], $pointers);
    }

    #[Test]
    public function map_shaped_additional_properties_node_is_not_reported_as_drift(): void
    {
        // Issue #437: /dict declares `entries` with `additionalProperties:
        // <schema>` and no `properties` — the author has said "this is a
        // map". Its observed keys are data, not shape: adding them to
        // `required` would be factually wrong, so neither the map node nor
        // anything beneath it may surface as drift. (Descending into the
        // additionalProperties schema for the *values* is still out of
        // scope — /entries/foo is suppressed as map-covered, not diffed
        // against the additionalProperties' required: ["id"].)
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/dict', '200', 'application/json', [
            '/' => ['entries'],
            '/entries' => ['foo'],
            '/entries/foo' => ['id', 'label'],
        ]);

        $reports = StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn);

        $this->assertSame([], $reports);

        // Map-covered observations are silent by design — they are the
        // documented "this is a map" case, not an unwalkable diagnostic.
        $this->assertSame([], StrictRequiredAsserter::detectUnwalkableNodes(StrictRequiredMode::Warn));
    }

    #[Test]
    public function map_shaped_node_does_not_suppress_sibling_drift(): void
    {
        // Drift outside the map subtree must keep reporting: here the
        // root object of /dict is observed with an undeclared sibling key
        // alongside the map-covered /entries observation.
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/dict', '200', 'application/json', [
            '/' => ['entries', 'extra'],
            '/entries' => ['foo'],
        ]);

        $reports = StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn);

        $this->assertCount(1, $reports);
        $this->assertSame('/', $reports[0]->schemaPointer);
        $this->assertSame(['extra'], $reports[0]->missingFromRequired);
    }

    #[Test]
    public function spec_load_failure_surfaces_cause_in_unresolved_diagnostic(): void
    {
        // Tracker has observations against a spec that vanishes between
        // bootstrap and assertion. The asserter's catch block must (a)
        // emit unresolved entries (no drift can be computed) and (b)
        // include the load failure's message so the user knows the spec
        // file is the cause — not a strict_required bug.
        StrictRequiredTracker::record('does-not-exist', 'GET', '/x', '200', 'application/json', [
            '/' => ['a'],
        ]);

        $unresolved = StrictRequiredAsserter::detectUnresolvedGroups(StrictRequiredMode::Warn);

        $this->assertCount(1, $unresolved);
        $this->assertStringContainsString('does-not-exist', $unresolved[0]);
        $this->assertStringContainsString('GET /x', $unresolved[0]);
        $this->assertStringContainsString('spec failed to load', $unresolved[0]);

        // No drift report for an unloadable spec.
        $this->assertSame([], StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn));
    }

    #[Test]
    public function clean_nested_observation_produces_no_report(): void
    {
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/teams/{id}', '200', 'application/json', [
            '/' => ['data', 'id'],
            '/data' => ['name'],
        ]);

        $this->assertSame([], StrictRequiredAsserter::detectAll(StrictRequiredMode::Warn));
    }
}
