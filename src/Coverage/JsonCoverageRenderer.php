<?php

declare(strict_types=1);

namespace Studio\Gesso\Coverage;

use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

use Composer\InstalledVersions;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

use function json_encode;
use function json_last_error_msg;
use function sprintf;

/**
 * Render coverage results as a JSON document for downstream consumers
 * (custom dashboards, contract-coverage analytics, scripted gating).
 *
 * Shape mirrors the computed {@see CoverageResult} array (not the paratest
 * sidecar wire format) — fields are snake_case, enum values surface as
 * strings, and the two state enums get distinct namespaced field names
 * (`endpoint_state` / `response_state`) to avoid value-string collisions
 * (e.g. `"uncovered"` exists in both enums).
 *
 * Top-level shape:
 *  - `schema_version`: int, bumped on incompatible structural changes
 *  - `generated_at`: ISO-8601 timestamp
 *  - `tool`: `{ name, version }` for downstream consumers diagnosing drift
 *  - `aggregate`: rollup across all specs (lets consumers read one "total"
 *    without re-summing)
 *  - `specs`: per-spec `{ aggregates, endpoints }`
 *
 * See `docs/coverage-json-schema.md` for the full field reference.
 *
 * @internal Output is exposed through the PHPUnit extension and merge CLI.
 *           The versioned JSON document remains a compatibility surface.
 *
 * @phpstan-import-type CoverageResult from OpenApiCoverageTracker
 * @phpstan-import-type EndpointSummary from OpenApiCoverageTracker
 * @phpstan-import-type ResponseRow from OpenApiCoverageTracker
 *
 * @phpstan-type JsonAggregate array{
 *     endpoint_total: int,
 *     endpoint_fully_covered: int,
 *     endpoint_partial: int,
 *     endpoint_uncovered: int,
 *     endpoint_request_only: int,
 *     response_total: int,
 *     response_covered: int,
 *     response_skipped: int,
 *     response_uncovered: int,
 * }
 * @phpstan-type JsonResponseRow array{
 *     status_key: string,
 *     content_type_key: string,
 *     response_state: string,
 *     hits: int,
 *     skip_reason: ?string,
 * }
 * @phpstan-type JsonUnexpected array{
 *     status_key: string,
 *     content_type_key: string,
 * }
 * @phpstan-type JsonEndpoint array{
 *     endpoint: string,
 *     method: string,
 *     path: string,
 *     operation_id: ?string,
 *     endpoint_state: string,
 *     request_reached: bool,
 *     responses: list<JsonResponseRow>,
 *     covered_response_count: int,
 *     skipped_response_count: int,
 *     total_response_count: int,
 *     unexpected_observations: list<JsonUnexpected>,
 * }
 * @phpstan-type JsonSpec array{
 *     aggregates: JsonAggregate,
 *     endpoints: list<JsonEndpoint>,
 * }
 */
final class JsonCoverageRenderer
{
    public const SCHEMA_VERSION = 2;
    private const COMPOSER_PACKAGE_NAME = 'studio-design/gesso';
    private const TOOL_NAME = 'studio-design/gesso';

    /**
     * @param array<string, CoverageResult> $results
     * @param null|DateTimeImmutable $generatedAt Override the document timestamp.
     *                                            Defaults to the current time.
     *
     * @return string Empty string when `$results` is empty so callers can
     *                short-circuit a no-coverage run; otherwise a pretty-printed
     *                JSON document terminated by a single `"\n"`.
     */
    public static function render(array $results, ?DateTimeImmutable $generatedAt = null): string
    {
        if ($results === []) {
            return '';
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => ($generatedAt ?? new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
            'tool' => [
                'name' => self::TOOL_NAME,
                'version' => self::resolveToolVersion(),
            ],
            'aggregate' => self::aggregate($results),
            'specs' => self::serialiseSpecs($results),
        ];

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            // Unreachable for the tracker's output (no resources, no NAN, no
            // unsupported types) but surface a clear error instead of an
            // empty file if the upstream shape changes unexpectedly.
            throw new RuntimeException(sprintf(
                'Failed to encode coverage results as JSON: %s',
                json_last_error_msg(),
            ));
        }

        return $encoded . "\n";
    }

    /**
     * @param array<string, CoverageResult> $results
     *
     * @return JsonAggregate
     */
    private static function aggregate(array $results): array
    {
        $totals = [
            'endpoint_total' => 0,
            'endpoint_fully_covered' => 0,
            'endpoint_partial' => 0,
            'endpoint_uncovered' => 0,
            'endpoint_request_only' => 0,
            'response_total' => 0,
            'response_covered' => 0,
            'response_skipped' => 0,
            'response_uncovered' => 0,
        ];

        foreach ($results as $result) {
            $totals['endpoint_total'] += $result['endpointTotal'];
            $totals['endpoint_fully_covered'] += $result['endpointFullyCovered'];
            $totals['endpoint_partial'] += $result['endpointPartial'];
            $totals['endpoint_uncovered'] += $result['endpointUncovered'];
            $totals['endpoint_request_only'] += $result['endpointRequestOnly'];
            $totals['response_total'] += $result['responseTotal'];
            $totals['response_covered'] += $result['responseCovered'];
            $totals['response_skipped'] += $result['responseSkipped'];
            $totals['response_uncovered'] += $result['responseUncovered'];
        }

        return $totals;
    }

    /**
     * @param array<string, CoverageResult> $results
     *
     * @return array<string, JsonSpec>
     */
    private static function serialiseSpecs(array $results): array
    {
        $specs = [];
        foreach ($results as $specName => $result) {
            $specs[$specName] = [
                'aggregates' => self::aggregate([$specName => $result]),
                'endpoints' => self::serialiseEndpoints($result['endpoints']),
            ];
        }

        return $specs;
    }

    /**
     * @param list<EndpointSummary> $endpoints
     *
     * @return list<JsonEndpoint>
     */
    private static function serialiseEndpoints(array $endpoints): array
    {
        $rows = [];
        foreach ($endpoints as $endpoint) {
            $rows[] = [
                'endpoint' => $endpoint['endpoint'],
                'method' => $endpoint['method'],
                'path' => $endpoint['path'],
                'operation_id' => $endpoint['operationId'],
                'endpoint_state' => $endpoint['state']->value,
                'request_reached' => $endpoint['requestReached'],
                'responses' => self::serialiseResponses($endpoint['responses']),
                'covered_response_count' => $endpoint['coveredResponseCount'],
                'skipped_response_count' => $endpoint['skippedResponseCount'],
                'total_response_count' => $endpoint['totalResponseCount'],
                'unexpected_observations' => self::serialiseUnexpected($endpoint['unexpectedObservations']),
            ];
        }

        return $rows;
    }

    /**
     * @param list<ResponseRow> $responses
     *
     * @return list<array{status_key: string, content_type_key: string, response_state: string, hits: int, skip_reason: ?string}>
     */
    private static function serialiseResponses(array $responses): array
    {
        $rows = [];
        foreach ($responses as $row) {
            $rows[] = [
                'status_key' => $row['statusKey'],
                'content_type_key' => $row['contentTypeKey'],
                'response_state' => $row['state']->value,
                'hits' => $row['hits'],
                'skip_reason' => $row['skipReason'],
            ];
        }

        return $rows;
    }

    /**
     * @param list<array{statusKey: string, contentTypeKey: string}> $observations
     *
     * @return list<array{status_key: string, content_type_key: string}>
     */
    private static function serialiseUnexpected(array $observations): array
    {
        $rows = [];
        foreach ($observations as $obs) {
            $rows[] = [
                'status_key' => $obs['statusKey'],
                'content_type_key' => $obs['contentTypeKey'],
            ];
        }

        return $rows;
    }

    /**
     * Resolve the running tool version. The field is cosmetic — any failure
     * here must never abort the report, so the catch is intentionally broad:
     * `OutOfBoundsException` is the documented Composer 2.x "package not
     * installed" path, but corrupted `installed.php`, Composer 1.x/2.x metadata
     * mismatches, or stripped vendor directories can surface as other throwables.
     * Silent by design — `'unknown'` is the documented sentinel and the schema
     * forbids null, so emitting a string is enough.
     */
    private static function resolveToolVersion(): string
    {
        try {
            $version = InstalledVersions::getVersion(self::COMPOSER_PACKAGE_NAME);
        } catch (Throwable) {
            return 'unknown';
        }

        return $version ?? 'unknown';
    }
}
