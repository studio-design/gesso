<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Coverage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Coverage\ConsoleCoverageRenderer;
use Studio\OpenApiContractTesting\Coverage\EndpointCoverageState;
use Studio\OpenApiContractTesting\Coverage\HtmlCoverageRenderer;
use Studio\OpenApiContractTesting\Coverage\JsonCoverageRenderer;
use Studio\OpenApiContractTesting\Coverage\JUnitCoverageRenderer;
use Studio\OpenApiContractTesting\Coverage\MarkdownCoverageRenderer;
use Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\Coverage\ResponseCoverageState;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;

use function array_column;
use function implode;
use function restore_error_handler;
use function set_error_handler;

class OpenApiCoverageTrackerTest extends TestCase
{
    private OpenApiCoverageTracker $tracker;

    protected function setUp(): void
    {
        parent::setUp();
        // Issue #229: each test gets its own tracker instance, so isolation
        // no longer depends on a process-global ::reset() call.
        $this->tracker = new OpenApiCoverageTracker();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function record_request_marks_endpoint_request_reached(): void
    {
        $this->tracker->recordRequestOn('petstore-3.0', 'GET', '/v1/pets');

        $this->assertTrue($this->tracker->hasAnyCoverageOn('petstore-3.0'));
    }

    #[Test]
    public function record_response_uppercases_method(): void
    {
        // /widgets-default has a single declared response (default:application/json)
        // so we can pin the resulting state without juggling other rows.
        $this->tracker->recordResponseOn(
            'range-keys',
            'get',
            '/widgets-default',
            '200',
            'application/json',
            schemaValidated: true,
        );

        $endpoint = $this->endpointSummary('range-keys', 'GET /widgets-default');
        $this->assertSame(EndpointCoverageState::AllCovered, $endpoint['state']);
    }

    #[Test]
    public function record_response_increments_hits_per_pair(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->tracker->recordResponseOn(
                'petstore-3.0',
                'GET',
                '/v1/pets',
                '200',
                'application/json',
                schemaValidated: true,
            );
        }

        $endpoint = $this->endpointSummary('petstore-3.0', 'GET /v1/pets');
        $row = $this->responseRow($endpoint['responses'], '200', 'application/json');
        $this->assertSame(3, $row['hits']);
        $this->assertSame(ResponseCoverageState::Validated, $row['state']);
    }

    #[Test]
    public function validated_promotes_prior_skipped_for_same_pair(): void
    {
        $this->tracker->recordResponseOn(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: false,
            skipReason: 'manually skipped',
        );
        $this->tracker->recordResponseOn(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );

        $endpoint = $this->endpointSummary('petstore-3.0', 'GET /v1/pets');
        $row = $this->responseRow($endpoint['responses'], '200', 'application/json');
        $this->assertSame(ResponseCoverageState::Validated, $row['state']);
        $this->assertNull($row['skipReason']);
    }

    #[Test]
    public function skipped_does_not_demote_validated_pair(): void
    {
        $this->tracker->recordResponseOn(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        $this->tracker->recordResponseOn(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: false,
            skipReason: 'should not win',
        );

        $endpoint = $this->endpointSummary('petstore-3.0', 'GET /v1/pets');
        $row = $this->responseRow($endpoint['responses'], '200', 'application/json');
        $this->assertSame(ResponseCoverageState::Validated, $row['state']);
        $this->assertNull($row['skipReason']);
    }

    #[Test]
    public function reset_clears_all_coverage(): void
    {
        $this->tracker->recordResponseOn(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );

        $this->tracker->resetOn();

        $this->assertFalse($this->tracker->hasAnyCoverageOn('petstore-3.0'));
    }

    #[Test]
    public function endpoint_with_partial_coverage_marks_remaining_as_uncovered(): void
    {
        // GET /v1/pets declares 4 (status, content-type) pairs in the petstore
        // fixture: 200, 422, 500, 400. Hitting only 200 → partial.
        $this->tracker->recordResponseOn(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );

        $endpoint = $this->endpointSummary('petstore-3.0', 'GET /v1/pets');
        $this->assertSame(EndpointCoverageState::Partial, $endpoint['state']);
        $this->assertSame(1, $endpoint['coveredResponseCount']);
        $this->assertSame(0, $endpoint['skippedResponseCount']);
        $this->assertSame(4, $endpoint['totalResponseCount']);
    }

    #[Test]
    public function endpoint_with_all_responses_validated_marks_all_covered(): void
    {
        foreach (
            [
                ['200', 'application/json'],
                ['422', 'Application/Problem+JSON'],
                ['500', 'application/json'],
                ['400', 'application/problem+json'],
            ] as [$status, $contentType]
        ) {
            $this->tracker->recordResponseOn(
                'petstore-3.0',
                'GET',
                '/v1/pets',
                $status,
                $contentType,
                schemaValidated: true,
            );
        }

        $endpoint = $this->endpointSummary('petstore-3.0', 'GET /v1/pets');
        $this->assertSame(EndpointCoverageState::AllCovered, $endpoint['state']);
        $this->assertSame(4, $endpoint['coveredResponseCount']);
    }

    #[Test]
    public function endpoint_with_no_records_is_uncovered(): void
    {
        $endpoint = $this->endpointSummary('petstore-3.0', 'GET /v1/pets');

        $this->assertSame(EndpointCoverageState::Uncovered, $endpoint['state']);
        foreach ($endpoint['responses'] as $row) {
            $this->assertSame(ResponseCoverageState::Uncovered, $row['state']);
        }
    }

    #[Test]
    public function request_only_endpoint_has_request_only_state(): void
    {
        $this->tracker->recordRequestOn('petstore-3.0', 'GET', '/v1/pets');

        $endpoint = $this->endpointSummary('petstore-3.0', 'GET /v1/pets');
        $this->assertSame(EndpointCoverageState::RequestOnly, $endpoint['state']);
        $this->assertTrue($endpoint['requestReached']);
    }

    #[Test]
    public function record_request_with_skip_reason_stores_reason(): void
    {
        // Issue #179: when the trait downgrades a request validation failure
        // (because the response is a documented 4xx), it forwards the skip
        // reason so coverage can surface the downgrade rather than report a
        // clean validated request.
        $this->tracker->recordRequestOn(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            'request validation skipped: response 422 is documented',
        );

        $state = $this->tracker->exportStateOn();
        $endpoint = $state['specs']['petstore-3.0']['GET /v1/pets'];
        $this->assertTrue($endpoint['requestReached']);
        $this->assertSame(
            'request validation skipped: response 422 is documented',
            $endpoint['requestSkipReason'],
        );
    }

    #[Test]
    public function record_request_promotes_skipped_to_validated_when_called_again_without_reason(): void
    {
        // Mirror of the response-side promotion: a later "clean" recording
        // (no skipReason) wins over an earlier skipped one — same test
        // method may run a downgraded path first, then a non-downgraded
        // path, and the endpoint should end up as cleanly request-validated.
        $this->tracker->recordRequestOn(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            'first run: downgraded',
        );
        $this->tracker->recordRequestOn('petstore-3.0', 'GET', '/v1/pets');

        $state = $this->tracker->exportStateOn();
        $endpoint = $state['specs']['petstore-3.0']['GET /v1/pets'];
        $this->assertTrue($endpoint['requestReached']);
        $this->assertNull(
            $endpoint['requestSkipReason'],
            'a later non-skipped recording must clear the prior skip reason',
        );
    }

    #[Test]
    public function record_request_keeps_validated_when_skipped_arrives_later(): void
    {
        // Inverse of the promotion test: once an endpoint has been cleanly
        // request-validated, a subsequent downgrade must NOT demote it back
        // to skipped. The "validated wins over skipped" rule is the
        // request-side mirror of the response-side promotion semantics.
        $this->tracker->recordRequestOn('petstore-3.0', 'GET', '/v1/pets');
        $this->tracker->recordRequestOn(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            'late downgrade: should be ignored',
        );

        $state = $this->tracker->exportStateOn();
        $endpoint = $state['specs']['petstore-3.0']['GET /v1/pets'];
        $this->assertTrue($endpoint['requestReached']);
        $this->assertNull($endpoint['requestSkipReason']);
    }

    #[Test]
    public function record_request_skipped_then_skipped_keeps_latest_reason(): void
    {
        // Two different downgrade reasons against the same endpoint —
        // mirrors the response-side "latest non-null reason wins" rule so
        // per-test overrides aren't silently dropped.
        $this->tracker->recordRequestOn('petstore-3.0', 'GET', '/v1/pets', 'first reason');
        $this->tracker->recordRequestOn('petstore-3.0', 'GET', '/v1/pets', 'second reason');

        $state = $this->tracker->exportStateOn();
        $endpoint = $state['specs']['petstore-3.0']['GET /v1/pets'];
        $this->assertSame('second reason', $endpoint['requestSkipReason']);
    }

    #[Test]
    public function record_request_after_record_response_preserves_skip_reason(): void
    {
        // Regression guard for the C1 reconciliation bug: if recordResponseOn
        // initialises an entry first (creating requestReached=false +
        // requestSkipReason=null), a subsequent recordRequestOn with a skip
        // reason must store that reason. Pre-fix, the reconciliation
        // treated the response-only entry as "already cleanly
        // request-validated" and silently dropped the incoming reason.
        $this->tracker->recordResponseOn(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        $this->tracker->recordRequestOn(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            'downgraded after response',
        );

        $state = $this->tracker->exportStateOn();
        $endpoint = $state['specs']['petstore-3.0']['GET /v1/pets'];
        $this->assertTrue($endpoint['requestReached']);
        $this->assertSame(
            'downgraded after response',
            $endpoint['requestSkipReason'],
            'first request-side recording must store the reason even when '
            . 'recordResponseOn initialised the entry',
        );
    }

    #[Test]
    public function content_type_match_is_case_insensitive(): void
    {
        // The 422 response in petstore-3.0 declares `Application/Problem+JSON`
        // (mixed case). A real response Content-Type is normalised to lower
        // case (`application/problem+json`). The reconciliation should still
        // recognise the recorded entry under the spec's casing.
        $this->tracker->recordResponseOn(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '422',
            'Application/Problem+JSON',
            schemaValidated: true,
        );

        $endpoint = $this->endpointSummary('petstore-3.0', 'GET /v1/pets');
        $row = $this->responseRow($endpoint['responses'], '422', 'Application/Problem+JSON');
        $this->assertSame(ResponseCoverageState::Validated, $row['state']);
        // Spec author casing must be preserved verbatim in the report.
        $this->assertSame('Application/Problem+JSON', $row['contentTypeKey']);
    }

    #[Test]
    public function literal_status_skipped_reconciles_to_spec_range_key(): void
    {
        // The validator records `503:*` (literal status, content-* sentinel)
        // for skipped responses. Spec might only declare `5XX` — reconciliation
        // surfaces the spec-declared range key as `state: skipped`.
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');
        $this->recordCoverageForFakeSpec();

        $endpoint = $this->endpointSummary('range-keys', 'GET /widgets');
        $row = $this->responseRow($endpoint['responses'], '5XX', 'application/json');
        $this->assertSame(ResponseCoverageState::Skipped, $row['state']);
        $this->assertSame('status 503 matched skip pattern 5\d\d', $row['skipReason']);
    }

    #[Test]
    public function default_spec_key_matches_any_recorded_status(): void
    {
        // Spec declares `default` as a catch-all. A literal `418` recording
        // (validated) should mark it covered.
        $this->tracker->recordResponseOn(
            'range-keys',
            'GET',
            '/widgets-default',
            '418',
            'application/json',
            schemaValidated: true,
        );

        $endpoint = $this->endpointSummary('range-keys', 'GET /widgets-default');
        $row = $this->responseRow($endpoint['responses'], 'default', 'application/json');
        $this->assertSame(ResponseCoverageState::Validated, $row['state']);
    }

    #[Test]
    public function unexpected_observations_surface_status_not_in_spec(): void
    {
        // petstore-3.0 GET /v1/pets does not declare 418. Recording 418 surfaces
        // it as an unexpected observation rather than counting toward coverage.
        $this->tracker->recordResponseOn(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '418',
            'application/json',
            schemaValidated: true,
        );

        $endpoint = $this->endpointSummary('petstore-3.0', 'GET /v1/pets');
        $this->assertSame(
            [['statusKey' => '418', 'contentTypeKey' => 'application/json']],
            $endpoint['unexpectedObservations'],
        );
        $this->assertSame(0, $endpoint['coveredResponseCount']);
    }

    #[Test]
    public function endpoint_summary_includes_operation_id_when_declared(): void
    {
        $endpoint = $this->endpointSummary('petstore-3.0', 'GET /v1/pets');

        // petstore-3.0 declares operationId 'listPets' for GET /v1/pets.
        $this->assertSame('listPets', $endpoint['operationId']);
    }

    #[Test]
    public function compute_coverage_aggregates_response_level_counts(): void
    {
        // petstore-3.0 declares 31 (status, content-type) pairs across 24
        // endpoints. Pin the totals so future spec drift is caught.
        $result = $this->tracker->computeCoverageOn('petstore-3.0');

        $this->assertSame(24, $result['endpointTotal']);
        $this->assertSame(31, $result['responseTotal']);
        $this->assertSame(0, $result['responseCovered']);
        $this->assertSame(0, $result['responseSkipped']);
        $this->assertSame(31, $result['responseUncovered']);
        $this->assertSame(24, $result['endpointUncovered']);
    }

    #[Test]
    public function response_rows_sorted_with_wildcard_content_last(): void
    {
        // Trigger a no-content response (skipped 503 → `503:*`) alongside a
        // concrete content-type response, then verify the sub-row ordering.
        $this->tracker->recordResponseOn(
            'range-keys',
            'GET',
            '/widgets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        $this->tracker->recordResponseOn(
            'range-keys',
            'GET',
            '/widgets',
            '503',
            null,
            schemaValidated: false,
            skipReason: 'status 503 matched skip pattern 5\d\d',
        );

        $endpoint = $this->endpointSummary('range-keys', 'GET /widgets');

        $orderedKeys = [];
        foreach ($endpoint['responses'] as $row) {
            $orderedKeys[] = $row['statusKey'] . ':' . $row['contentTypeKey'];
        }
        $this->assertSame(['200:application/json', '5XX:application/json'], $orderedKeys);
    }

    #[Test]
    public function record_response_with_range_key_reconciles_to_literal_spec_status(): void
    {
        // Pin the symmetric branch in statusKeyMatches() — recordings normally
        // carry literal statuses, but external callers (and future skip-pattern
        // refactors) may pass spec range keys. The reverse direction must
        // reconcile too.
        $this->tracker->recordResponseOn(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '5XX',
            'application/json',
            schemaValidated: true,
        );

        $endpoint = $this->endpointSummary('petstore-3.0', 'GET /v1/pets');
        // petstore-3.0 declares 500:application/json literally; the 5XX recording
        // should reconcile against it.
        $row = $this->responseRow($endpoint['responses'], '500', 'application/json');
        $this->assertSame(ResponseCoverageState::Validated, $row['state']);
    }

    #[Test]
    public function default_spec_key_matches_literal_default_recording(): void
    {
        // Edge case: caller passes the literal string "default" as statusKey.
        // statusKeyMatches() should resolve via the case-insensitive exact match
        // BEFORE falling through to the `default` wildcard branch.
        $this->tracker->recordResponseOn(
            'range-keys',
            'GET',
            '/widgets-default',
            'default',
            'application/json',
            schemaValidated: true,
        );

        $endpoint = $this->endpointSummary('range-keys', 'GET /widgets-default');
        $row = $this->responseRow($endpoint['responses'], 'default', 'application/json');
        $this->assertSame(ResponseCoverageState::Validated, $row['state']);
    }

    #[Test]
    public function endpoint_with_no_spec_responses_renders_request_only_when_request_fired(): void
    {
        // Pin the deriveEndpointState() $totalDeclared === 0 branch.
        // The fixture's GET /widgets-no-responses operation has no responses
        // block at all.
        $this->tracker->recordRequestOn('range-keys', 'GET', '/widgets-no-responses');

        $endpoint = $this->endpointSummary('range-keys', 'GET /widgets-no-responses');
        $this->assertSame(EndpointCoverageState::RequestOnly, $endpoint['state']);
        $this->assertSame(0, $endpoint['totalResponseCount']);
    }

    #[Test]
    public function endpoint_with_no_spec_responses_renders_uncovered_when_no_record(): void
    {
        $endpoint = $this->endpointSummary('range-keys', 'GET /widgets-no-responses');
        $this->assertSame(EndpointCoverageState::Uncovered, $endpoint['state']);
    }

    #[Test]
    public function endpoint_with_only_unexpected_observation_renders_request_only(): void
    {
        // Pin the hasAnyResponseObservation branch in deriveEndpointState():
        // a recording that doesn't reconcile to any declared spec entry
        // (lands in unexpectedObservations) but no recordRequestOn call —
        // endpoint state must be `request-only`, not `uncovered`.
        $this->tracker->recordResponseOn(
            'range-keys',
            'GET',
            '/widgets',
            '700',
            'application/xml',
            schemaValidated: true,
        );

        $endpoint = $this->endpointSummary('range-keys', 'GET /widgets');
        $this->assertSame(EndpointCoverageState::RequestOnly, $endpoint['state']);
        $this->assertCount(1, $endpoint['unexpectedObservations']);
    }

    #[Test]
    public function cross_recording_hits_accumulate_into_validated_pair(): void
    {
        // Two distinct recordings (`503` and `599`) both reconcile to spec `5XX`
        // via range matching. The validated row's `hits` should be the sum.
        $this->tracker->recordResponseOn(
            'range-keys',
            'GET',
            '/widgets',
            '503',
            'application/json',
            schemaValidated: true,
        );
        $this->tracker->recordResponseOn(
            'range-keys',
            'GET',
            '/widgets',
            '599',
            'application/json',
            schemaValidated: true,
        );

        $endpoint = $this->endpointSummary('range-keys', 'GET /widgets');
        $row = $this->responseRow($endpoint['responses'], '5XX', 'application/json');
        $this->assertSame(ResponseCoverageState::Validated, $row['state']);
        $this->assertSame(2, $row['hits']);
    }

    #[Test]
    public function validated_promotion_drops_skipped_hits_from_count(): void
    {
        // Two skipped recordings followed by a validated one should NOT roll
        // the skipped hit count into the validated total — the displayed
        // "validated (N hits)" must reflect validations only.
        $this->tracker->recordResponseOn(
            'range-keys',
            'GET',
            '/widgets',
            '503',
            null,
            schemaValidated: false,
            skipReason: 'first skip',
        );
        $this->tracker->recordResponseOn(
            'range-keys',
            'GET',
            '/widgets',
            '599',
            null,
            schemaValidated: false,
            skipReason: 'second skip',
        );
        $this->tracker->recordResponseOn(
            'range-keys',
            'GET',
            '/widgets',
            '500',
            'application/json',
            schemaValidated: true,
        );

        $endpoint = $this->endpointSummary('range-keys', 'GET /widgets');
        $row = $this->responseRow($endpoint['responses'], '5XX', 'application/json');
        $this->assertSame(ResponseCoverageState::Validated, $row['state']);
        $this->assertSame(1, $row['hits'], 'hits should only count validated recordings');
    }

    #[Test]
    public function latest_skip_reason_wins_for_same_pair_recordings(): void
    {
        $this->tracker->recordResponseOn(
            'range-keys',
            'GET',
            '/widgets',
            '503',
            null,
            schemaValidated: false,
            skipReason: 'reason A',
        );
        $this->tracker->recordResponseOn(
            'range-keys',
            'GET',
            '/widgets',
            '503',
            null,
            schemaValidated: false,
            skipReason: 'reason B',
        );

        $endpoint = $this->endpointSummary('range-keys', 'GET /widgets');
        $row = $this->responseRow($endpoint['responses'], '5XX', 'application/json');
        $this->assertSame('reason B', $row['skipReason']);
    }

    #[Test]
    public function latest_skip_reason_wins_across_cross_recording_skips(): void
    {
        // Two distinct skip recordings (different literal statuses) both
        // reconcile to the same spec `5XX:application/json` declaration.
        // Latest skipReason should win in buildResponseRows too.
        $this->tracker->recordResponseOn(
            'range-keys',
            'GET',
            '/widgets',
            '503',
            null,
            schemaValidated: false,
            skipReason: 'reason A',
        );
        $this->tracker->recordResponseOn(
            'range-keys',
            'GET',
            '/widgets',
            '599',
            null,
            schemaValidated: false,
            skipReason: 'reason B',
        );

        $endpoint = $this->endpointSummary('range-keys', 'GET /widgets');
        $row = $this->responseRow($endpoint['responses'], '5XX', 'application/json');
        $this->assertSame(ResponseCoverageState::Skipped, $row['state']);
        $this->assertSame('reason B', $row['skipReason']);
    }

    #[Test]
    public function default_and_5xx_overlap_double_counts_response_definitions(): void
    {
        // Documented overlap behaviour: when a spec declares BOTH `default`
        // and `5XX` for the same content-type, a single `503` recording marks
        // both rows validated and contributes to both counts. Spec authors are
        // unlikely to write both, but this pin documents the arithmetic so a
        // future refactor doesn't silently change the totals.
        $this->tracker->recordResponseOn(
            'range-keys-overlap',
            'GET',
            '/widgets',
            '503',
            'application/json',
            schemaValidated: true,
        );

        $endpoint = $this->endpointSummary('range-keys-overlap', 'GET /widgets');
        // Spec declares both 5XX:application/json and default:application/json
        $this->assertSame(2, $endpoint['totalResponseCount']);
        $this->assertSame(2, $endpoint['coveredResponseCount']);
        $this->assertSame(EndpointCoverageState::AllCovered, $endpoint['state']);
    }

    #[Test]
    public function malformed_response_entry_emits_warning_and_omits(): void
    {
        // Pin the trigger_error path in collectDeclaredEndpoints — silently
        // dropping a non-array response would understate totals; without
        // a warning the user wouldn't notice.
        $captured = [];
        $previous = set_error_handler(static function (int $errno, string $message) use (&$captured): bool {
            $captured[] = $message;

            return true;
        });

        try {
            $this->tracker->computeCoverageOn('malformed-response');
        } finally {
            restore_error_handler();
        }

        $joined = implode(' | ', $captured);
        $this->assertStringContainsString("spec 'malformed-response'", $joined);
        $this->assertStringContainsString('not an object', $joined);
    }

    #[Test]
    public function openapi_32_query_and_additional_operations_appear_in_coverage(): void
    {
        $this->tracker->recordResponseOn(
            'openapi-3.2',
            'QUERY',
            '/v1/search',
            '200',
            'application/json',
            schemaValidated: true,
        );
        $this->tracker->recordResponseOn(
            'openapi-3.2',
            'COPY',
            '/v1/pets/{petId}',
            '200',
            'application/json',
            schemaValidated: true,
        );

        $result = $this->tracker->computeCoverageOn('openapi-3.2');
        $endpointKeys = array_column($result['endpoints'], 'endpoint');

        $this->assertContains('QUERY /v1/search', $endpointKeys);
        $this->assertContains('COPY /v1/pets/{petId}', $endpointKeys);
        $this->assertSame(6, $result['endpointTotal']);
        $this->assertSame(2, $result['endpointFullyCovered']);
    }

    #[Test]
    public function coverage_keeps_custom_method_capitalization_distinct(): void
    {
        $this->tracker->recordRequestOn('custom-methods', 'COPY', '/resources');
        $this->tracker->recordRequestOn('custom-methods', 'copy', '/resources');
        $this->tracker->recordRequestOn('custom-methods', 'get', '/resources');

        $covered = $this->tracker->getCoveredOn();

        $this->assertArrayHasKey('COPY /resources', $covered['custom-methods']);
        $this->assertArrayHasKey('copy /resources', $covered['custom-methods']);
        $this->assertArrayHasKey('GET /resources', $covered['custom-methods']);
        $this->assertCount(3, $covered['custom-methods']);
    }

    #[Test]
    public function openapi_32_operation_forms_render_in_every_report_format(): void
    {
        $result = $this->tracker->computeCoverageOn('openapi-3.2');
        $results = ['openapi-3.2' => $result];

        $outputs = [
            ConsoleCoverageRenderer::render($results),
            MarkdownCoverageRenderer::render($results),
            JUnitCoverageRenderer::render($results),
            JsonCoverageRenderer::render($results),
            HtmlCoverageRenderer::render($results),
        ];

        foreach ($outputs as $output) {
            $this->assertStringContainsString('QUERY /v1/search', $output);
            $this->assertStringContainsString('COPY /v1/pets/{petId}', $output);
        }
    }

    #[Test]
    public function has_any_coverage_returns_true_for_request_only(): void
    {
        $this->tracker->recordRequestOn('petstore-3.0', 'GET', '/v1/pets');

        $this->assertTrue($this->tracker->hasAnyCoverageOn('petstore-3.0'));
        $this->assertFalse($this->tracker->hasAnyCoverageOn('other-spec'));
    }

    /**
     * Record a 503 against the range-keys fixture's GET /widgets so the
     * range-key reconciliation test has data to assert against. Inlined into
     * a helper so the test body stays focused on the assertion.
     */
    private function recordCoverageForFakeSpec(): void
    {
        $this->tracker->recordResponseOn(
            'range-keys',
            'GET',
            '/widgets',
            '503',
            null,
            schemaValidated: false,
            skipReason: 'status 503 matched skip pattern 5\d\d',
        );
    }

    /**
     * @return array{
     *     endpoint: string,
     *     method: string,
     *     path: string,
     *     operationId: ?string,
     *     state: EndpointCoverageState,
     *     requestReached: bool,
     *     responses: list<array{statusKey: string, contentTypeKey: string, state: ResponseCoverageState, hits: int, skipReason: ?string}>,
     *     coveredResponseCount: int,
     *     skippedResponseCount: int,
     *     totalResponseCount: int,
     *     unexpectedObservations: list<array{statusKey: string, contentTypeKey: string}>,
     * }
     */
    private function endpointSummary(string $specName, string $endpointKey): array
    {
        $result = $this->tracker->computeCoverageOn($specName);
        foreach ($result['endpoints'] as $summary) {
            if ($summary['endpoint'] === $endpointKey) {
                return $summary;
            }
        }

        $this->fail("No endpoint summary for {$endpointKey} in spec {$specName}");
    }

    /**
     * @param list<array{statusKey: string, contentTypeKey: string, state: ResponseCoverageState, hits: int, skipReason: ?string}> $rows
     *
     * @return array{statusKey: string, contentTypeKey: string, state: ResponseCoverageState, hits: int, skipReason: ?string}
     */
    private function responseRow(array $rows, string $statusKey, string $contentTypeKey): array
    {
        foreach ($rows as $row) {
            if ($row['statusKey'] === $statusKey && $row['contentTypeKey'] === $contentTypeKey) {
                return $row;
            }
        }

        $this->fail("No response row for {$statusKey}:{$contentTypeKey}");
    }
}
