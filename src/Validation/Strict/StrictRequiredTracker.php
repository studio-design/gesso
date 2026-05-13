<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Validation\Strict;

use InvalidArgumentException;

use function array_intersect;
use function array_is_list;
use function array_keys;
use function array_unique;
use function array_values;
use function get_debug_type;
use function is_array;
use function is_int;
use function is_string;
use function sort;
use function sprintf;
use function strtoupper;

/**
 * Static singleton that accumulates response body top-level key observations
 * per `(spec, METHOD path, statusKey, contentTypeKey)` across a test run.
 *
 * For each group the tracker holds the **intersection** of observed key sets
 * — the set of keys that appeared in *every* recorded response. Combined
 * with `hits` (the number of contributing observations), the intersection is
 * what {@see StrictRequiredAsserter} diffs against the matching spec's
 * `required` array to detect schema under-description (Issue #224).
 *
 * Intersection semantics:
 *  - first observation: stored verbatim (sorted, deduped) so the lookup
 *    shape is stable from `hits == 1`
 *  - subsequent observations: `array_intersect` against the running set
 *  - once `alwaysPresent` shrinks to `[]` it stays empty for the rest of
 *    the run; reporting is then trivially a no-op for this group
 *
 * The tracker is paratest-safe via the {@see self::exportState()} /
 * {@see self::importState()} round-trip used by the sidecar writer and the
 * merge CLI — see {@see \Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker}
 * for the parallel pattern this mirrors.
 *
 * @phpstan-type StrictRequiredRow array{hits: int, alwaysPresent: list<string>}
 * @phpstan-type StrictRequiredEndpoint array<string, StrictRequiredRow>
 * @phpstan-type StrictRequiredStatePayload array{
 *     version: int,
 *     observations: array<string, array<string, StrictRequiredEndpoint>>,
 * }
 */
final class StrictRequiredTracker
{
    /**
     * Wildcard sentinel for "no content type negotiated" — used when a
     * response body is observed but the validator could not pin a specific
     * content-type spec key. Mirrors
     * {@see \Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker::ANY_CONTENT_TYPE}
     * so the two tracker outputs read consistently in merged reports.
     */
    public const ANY_CONTENT_TYPE = '*';

    /**
     * Format version stamped on every {@see self::exportState()} payload.
     * Importers reject unknown versions to prevent silent misinterpretation
     * of future shape changes.
     */
    public const STATE_FORMAT_VERSION = 1;

    /**
     * Per-(spec, endpoint, response key) observations.
     *
     * Endpoint key is `"{METHOD} {path}"` (method upper-cased). Response key
     * is `"{statusKey}:{contentTypeKey}"` matching {@see OpenApiCoverageTracker}'s
     * convention.
     *
     * @var array<string, array<string, array<string, StrictRequiredRow>>>
     */
    private static array $observations = [];

    /** Static-only utility — no instances. */
    private function __construct() {}

    /**
     * Record one observed response body. `$topLevelKeys` is the list of
     * top-level keys present in the decoded body (`array_keys(...)` on the
     * caller side); duplicate / unsorted lists are tolerated — the tracker
     * normalises before storing.
     *
     * Calls are cheap and idempotent in the no-strict-mode path: the
     * extension wires `record()` only when `strict_required != off`, so
     * users on the default configuration pay zero overhead.
     *
     * @param list<string> $topLevelKeys
     */
    public static function record(
        string $specName,
        string $method,
        string $path,
        string $statusKey,
        string $contentTypeKey,
        array $topLevelKeys,
    ): void {
        $endpointKey = strtoupper($method) . ' ' . $path;
        $responseKey = $statusKey . ':' . $contentTypeKey;

        $normalised = array_values(array_unique($topLevelKeys));
        sort($normalised);

        $existing = self::$observations[$specName][$endpointKey][$responseKey] ?? null;
        if ($existing === null) {
            self::$observations[$specName][$endpointKey][$responseKey] = [
                'hits' => 1,
                'alwaysPresent' => $normalised,
            ];

            return;
        }

        self::$observations[$specName][$endpointKey][$responseKey] = [
            'hits' => $existing['hits'] + 1,
            'alwaysPresent' => self::intersect($existing['alwaysPresent'], $normalised),
        ];
    }

    /**
     * Reset all recorded observations. Intended for test isolation between
     * runs and for the extension's bootstrap phase.
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$observations = [];
    }

    /**
     * Diagnostic accessor for the recorded observations of a single spec.
     * Returns the same nested shape used internally: `endpointKey =>
     * responseKey => { hits, alwaysPresent }`.
     *
     * Empty array when nothing was recorded for the spec — the asserter
     * uses this to short-circuit specs that the run never touched.
     *
     * @return array<string, array<string, StrictRequiredRow>>
     */
    public static function getObservations(string $specName): array
    {
        return self::$observations[$specName] ?? [];
    }

    /**
     * List the spec names that have at least one recorded observation.
     *
     * @return list<string>
     */
    public static function recordedSpecs(): array
    {
        return array_keys(self::$observations);
    }

    /**
     * Snapshot the tracker as a JSON-safe payload. Worker processes write
     * this to their sidecar; the merge CLI reconstructs state via
     * {@see self::importState()}.
     *
     * @internal Stable wire format for sidecar writers / merge CLI. The
     * `version` field gates compatibility — see {@see self::STATE_FORMAT_VERSION}.
     *
     * @return StrictRequiredStatePayload
     */
    public static function exportState(): array
    {
        return [
            'version' => self::STATE_FORMAT_VERSION,
            'observations' => self::$observations,
        ];
    }

    /**
     * Union-merge an exported state into the live tracker. The merge is
     * intersection-based so worker semantics match single-process semantics:
     * a key only stays in `alwaysPresent` if it was always present in both
     * sides. `hits` accumulate.
     *
     * Validation is two-pass: the entire payload is parsed and rejected up
     * front before any mutation, so a malformed entry deep in the payload
     * cannot leave the tracker in a partially-merged state.
     *
     * @internal Companion to {@see self::exportState()}.
     *
     * @param array<string, mixed> $state
     *
     * @throws InvalidArgumentException when the payload shape or version
     *                                  is unrecognised
     */
    public static function importState(array $state): void
    {
        $normalised = self::validateStatePayload($state);

        foreach ($normalised as $specName => $endpoints) {
            foreach ($endpoints as $endpointKey => $responses) {
                foreach ($responses as $responseKey => $row) {
                    $existing = self::$observations[$specName][$endpointKey][$responseKey] ?? null;
                    if ($existing === null) {
                        self::$observations[$specName][$endpointKey][$responseKey] = $row;

                        continue;
                    }
                    self::$observations[$specName][$endpointKey][$responseKey] = [
                        'hits' => $existing['hits'] + $row['hits'],
                        'alwaysPresent' => self::intersect(
                            $existing['alwaysPresent'],
                            $row['alwaysPresent'],
                        ),
                    ];
                }
            }
        }
    }

    /**
     * @param list<string> $left
     * @param list<string> $right
     *
     * @return list<string>
     */
    private static function intersect(array $left, array $right): array
    {
        $intersected = array_values(array_intersect($left, $right));
        sort($intersected);

        return $intersected;
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, array<string, array<string, StrictRequiredRow>>>
     */
    private static function validateStatePayload(array $state): array
    {
        $version = $state['version'] ?? null;
        if (!is_int($version) || $version !== self::STATE_FORMAT_VERSION) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported strict_required state version: got %s, expected %d.',
                is_int($version) ? (string) $version : get_debug_type($version),
                self::STATE_FORMAT_VERSION,
            ));
        }

        $observations = $state['observations'] ?? null;
        if (!is_array($observations)) {
            throw new InvalidArgumentException(
                'strict_required state must contain an "observations" object.',
            );
        }

        $out = [];
        foreach ($observations as $specName => $endpoints) {
            if (!is_string($specName) || !is_array($endpoints)) {
                throw new InvalidArgumentException(
                    'strict_required observations must be keyed by spec name.',
                );
            }
            $specOut = [];
            foreach ($endpoints as $endpointKey => $responses) {
                if (!is_string($endpointKey) || !is_array($responses)) {
                    throw new InvalidArgumentException(sprintf(
                        'strict_required observations[%s] must be keyed by endpoint string.',
                        $specName,
                    ));
                }
                $endpointOut = [];
                foreach ($responses as $responseKey => $row) {
                    if (!is_string($responseKey) || !is_array($row)) {
                        throw new InvalidArgumentException(sprintf(
                            'strict_required observations[%s][%s] must be keyed by "status:contentType" string.',
                            $specName,
                            $endpointKey,
                        ));
                    }
                    $endpointOut[$responseKey] = self::validateRow($specName, $endpointKey, $responseKey, $row);
                }
                $specOut[$endpointKey] = $endpointOut;
            }
            $out[$specName] = $specOut;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return StrictRequiredRow
     */
    private static function validateRow(string $specName, string $endpointKey, string $responseKey, array $row): array
    {
        $hits = $row['hits'] ?? null;
        if (!is_int($hits) || $hits < 1) {
            throw new InvalidArgumentException(sprintf(
                'strict_required %s / %s / %s requires integer hits >= 1.',
                $specName,
                $endpointKey,
                $responseKey,
            ));
        }
        $keys = $row['alwaysPresent'] ?? null;
        if (!is_array($keys) || !array_is_list($keys)) {
            throw new InvalidArgumentException(sprintf(
                'strict_required %s / %s / %s must carry alwaysPresent as a list.',
                $specName,
                $endpointKey,
                $responseKey,
            ));
        }
        $strings = [];
        foreach ($keys as $value) {
            if (!is_string($value)) {
                throw new InvalidArgumentException(sprintf(
                    'strict_required %s / %s / %s alwaysPresent entries must be strings; got %s.',
                    $specName,
                    $endpointKey,
                    $responseKey,
                    get_debug_type($value),
                ));
            }
            $strings[] = $value;
        }
        sort($strings);

        return ['hits' => $hits, 'alwaysPresent' => $strings];
    }
}
