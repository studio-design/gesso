<?php

declare(strict_types=1);

namespace Studio\Gesso\Validation\Strict;

use const E_USER_WARNING;

use InvalidArgumentException;
use Studio\Gesso\Coverage\CoverageSidecarEnvelope;
use Studio\Gesso\OpenApiResponseValidator;

use function array_intersect;
use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_unique;
use function array_values;
use function get_debug_type;
use function is_array;
use function is_int;
use function is_string;
use function ksort;
use function sort;
use function sprintf;
use function strtoupper;
use function trigger_error;

/**
 * Tracker that accumulates response body object-node observations per
 * `(spec, METHOD path, statusKey, contentTypeKey)` across a test run.
 * Exposed through a static facade (see {@see self::current()} /
 * {@see self::setCurrent()}) so framework adapters without constructor
 * injection can resolve the run-level instance at their integration boundary.
 * The validator itself requires an instance explicitly; the PHPUnit extension
 * and merge CLI construct and use their owned instances directly.
 *
 * Each observation feeds a JSON-Pointer-like map `pointer => list<string>`
 * (see {@see StrictRequiredBodyWalker}) describing the keys present at every
 * object node walked in the body. The tracker keeps the **per-pointer
 * intersection** of observations: a key only stays in `pointers[$p]` if it
 * appeared in every recorded response for that pointer, AND a pointer only
 * stays at all if every recorded response contributed it (absence drops the
 * pointer).
 *
 * Combined with `hits` (the number of contributing observations), the
 * intersection is what {@see StrictRequiredAsserter} diffs against the
 * matching spec's `required` arrays — descended in parallel — to detect
 * schema under-description at any nesting depth.
 *
 * State format version 2 introduces the per-pointer row shape; v1 rows that
 * carried a flat `alwaysPresent: list<string>` field are explicitly rejected
 * on import so mixed-version paratest fleets fail loudly rather than silently
 * downgrading nested observations to root-only. The envelope version
 * ({@see CoverageSidecarEnvelope::ENVELOPE_VERSION}) is independent.
 *
 * @phpstan-type StrictRequiredRow array{hits: int, pointers: array<string, list<string>>}
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
     * {@see \Studio\Gesso\Coverage\OpenApiCoverageTracker::ANY_CONTENT_TYPE}
     * so the two tracker outputs read consistently in merged reports.
     */
    public const ANY_CONTENT_TYPE = '*';

    /**
     * Format version stamped on every {@see self::exportState()} payload.
     * Importers reject unknown versions to prevent silent misinterpretation
     * of future shape changes. v1 used a flat `alwaysPresent: list<string>`
     * row; v2 carries a `pointers` map keyed by JSON-Pointer-like strings.
     * v1 payloads are rejected loudly on import — see
     * {@see self::validateRow()}.
     */
    public const STATE_FORMAT_VERSION = 2;

    /**
     * Service-locator slot for the framework-adapter facade (Issue #229).
     * Symmetric with {@see OpenApiCoverageTracker::$current}: adapters resolve
     * whichever run-level instance the host installed via
     * {@see self::setCurrent()}, then inject it into
     * {@see OpenApiResponseValidator}.
     */
    private static ?self $current = null;

    /**
     * Per-(spec, endpoint, response key) observations.
     *
     * Endpoint key is `"{METHOD} {path}"` (method upper-cased). Response key
     * is `"{statusKey}:{contentTypeKey}"` matching {@see OpenApiCoverageTracker}'s
     * convention.
     *
     * @var array<string, array<string, array<string, StrictRequiredRow>>>
     */
    private array $observations = [];

    public function __construct() {}

    /**
     * The "current" tracker instance — what the static facade methods
     * delegate to. The PHPUnit extension installs a fresh instance at
     * bootstrap so each test run starts clean; the lazy default exists for
     * unit tests and other host-less call sites that hit the static facade
     * before any setup ran.
     *
     * @internal Refactor seam for the static→instance migration in Issue #229.
     */
    public static function current(): self
    {
        return self::$current ??= new self();
    }

    /**
     * Install the "current" tracker instance. Called from the PHPUnit
     * extension's bootstrap so the suite-wide tracker is the one wired into
     * the subscriber. Drop the slot with {@see self::resetCurrent()} when
     * you want the next {@see self::current()} call to mint a fresh default.
     *
     * Triggers `E_USER_WARNING` when overwriting an installed instance that
     * already has recorded observations — that pattern almost always means
     * a test forgot to call {@see self::resetCurrent()} before re-installing
     * and is about to silently drop observations. Mirrors the same guard on
     * {@see \Studio\Gesso\Coverage\OpenApiCoverageTracker::setCurrent()}.
     *
     * @internal
     */
    public static function setCurrent(self $instance): void
    {
        if (self::$current !== null && self::$current->recordedSpecsOn() !== []) {
            trigger_error(
                '[OpenAPI Strict Required] setCurrent() called while the '
                . 'previous instance still holds recorded observations; '
                . 'those observations will not contribute to drift '
                . 'detection. Call resetCurrent() first if this is intentional.',
                E_USER_WARNING,
            );
        }
        self::$current = $instance;
    }

    /**
     * Drop the installed instance so the next {@see self::current()} call
     * mints a fresh default. Symmetric with {@see self::setCurrent()};
     * splitting the two intents keeps the type signatures honest.
     *
     * @internal
     */
    public static function resetCurrent(): void
    {
        self::$current = null;
    }

    /**
     * Record one observed response body's pointer→keys map. The map is
     * typically produced by {@see StrictRequiredBodyWalker::collectPointers()};
     * the tracker is map-agnostic so unit tests can construct it directly.
     *
     * Cross-observation semantics:
     *  - first observation for a cell: stored verbatim (sorted, deduped) so
     *    the lookup shape is stable from `hits == 1`
     *  - subsequent observations: per pointer present on BOTH sides, keys
     *    are intersected. Pointers present only on one side are dropped —
     *    the asserter requires "always observed" for both the key and its
     *    parent pointer
     *  - `hits` always increments regardless of pointer-set churn
     *
     * Recording happens on every conformance-passing response regardless of
     * `strict_required` mode — the validator does not know the mode, and the
     * asserter is the gated component.
     *
     * Runtime validation: keys must be non-empty strings and every value
     * must be a `list<string>`. The PHPDoc is typed loosely (`array<mixed,
     * mixed>`) deliberately so static analysers do not treat the defensive
     * checks below as unreachable — a future walker-side bug that leaks
     * non-string entries must still surface here as a hard error.
     *
     * @param array<mixed, mixed> $pointers map of JSON-Pointer-like strings
     *                                      to lists of object keys observed
     *                                      at that node
     *
     * @throws InvalidArgumentException when a pointer key is not a
     *                                  non-empty string, or a value is not
     *                                  a list of strings
     *
     * @internal The parameter shape is not part of the SemVer-frozen public
     *           API. The pointer-map shape (introduced in state format v2)
     *           may evolve as the walker gains new pointer notations. Direct
     *           callers outside the library should not exist; the library
     *           routes through {@see OpenApiResponseValidator::validate()}.
     */
    public static function record(
        string $specName,
        string $method,
        string $path,
        string $statusKey,
        string $contentTypeKey,
        array $pointers,
    ): void {
        self::current()->recordOn($specName, $method, $path, $statusKey, $contentTypeKey, $pointers);
    }

    /**
     * Reset all recorded observations. Intended for test isolation between
     * runs and for the extension's bootstrap phase.
     *
     * @internal
     */
    public static function reset(): void
    {
        self::current()->resetOn();
    }

    /**
     * Diagnostic accessor for the recorded observations of a single spec.
     * Returns the same nested shape used internally: `endpointKey =>
     * responseKey => { hits, pointers }`.
     *
     * Empty array when nothing was recorded for the spec — the asserter
     * uses this to short-circuit specs that the run never touched.
     *
     * @return array<string, array<string, StrictRequiredRow>>
     *
     * @internal Consumed by {@see StrictRequiredAsserter} and by unit tests;
     *           the returned array shape is not part of the public API and
     *           may change between minor releases.
     */
    public static function getObservations(string $specName): array
    {
        return self::current()->getObservationsOn($specName);
    }

    /**
     * List the spec names that have at least one recorded observation.
     *
     * @return list<string>
     *
     * @internal Consumed by {@see StrictRequiredAsserter} so it can walk
     *           only the specs actually touched by the test run.
     */
    public static function recordedSpecs(): array
    {
        return self::current()->recordedSpecsOn();
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
        return self::current()->exportStateOn();
    }

    /**
     * Union-merge an exported state into the live tracker. The merge applies
     * the same per-pointer intersection rule as {@see self::record()}:
     * a pointer survives only when both sides observed it, and a key under
     * that pointer only when both sides recorded it. `hits` accumulate.
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
        self::current()->importStateOn($state);
    }

    /**
     * Instance counterpart of {@see self::record()} (Issue #229).
     *
     * @param array<mixed, mixed> $pointers
     *
     * @throws InvalidArgumentException when a pointer key or value is malformed
     */
    public function recordOn(
        string $specName,
        string $method,
        string $path,
        string $statusKey,
        string $contentTypeKey,
        array $pointers,
    ): void {
        $endpointKey = strtoupper($method) . ' ' . $path;
        $responseKey = $statusKey . ':' . $contentTypeKey;

        $normalised = self::normalisePointers($specName, $endpointKey, $responseKey, $pointers);

        $existing = $this->observations[$specName][$endpointKey][$responseKey] ?? null;
        if ($existing === null) {
            $this->observations[$specName][$endpointKey][$responseKey] = [
                'hits' => 1,
                'pointers' => $normalised,
            ];

            return;
        }

        $this->observations[$specName][$endpointKey][$responseKey] = [
            'hits' => $existing['hits'] + 1,
            'pointers' => self::mergePointers($existing['pointers'], $normalised),
        ];
    }

    /**
     * Instance counterpart of {@see self::reset()} (Issue #229). Direct
     * callers can also just drop the instance — there is no global state
     * to clear.
     */
    public function resetOn(): void
    {
        $this->observations = [];
    }

    /**
     * Instance counterpart of {@see self::getObservations()} (Issue #229).
     *
     * @return array<string, array<string, StrictRequiredRow>>
     */
    public function getObservationsOn(string $specName): array
    {
        return $this->observations[$specName] ?? [];
    }

    /**
     * Instance counterpart of {@see self::recordedSpecs()} (Issue #229).
     *
     * @return list<string>
     */
    public function recordedSpecsOn(): array
    {
        return array_keys($this->observations);
    }

    /**
     * Instance counterpart of {@see self::exportState()} (Issue #229).
     *
     * @return StrictRequiredStatePayload
     */
    public function exportStateOn(): array
    {
        return [
            'version' => self::STATE_FORMAT_VERSION,
            'observations' => $this->observations,
        ];
    }

    /**
     * Instance counterpart of {@see self::importState()} (Issue #229).
     *
     * @param array<string, mixed> $state
     *
     * @throws InvalidArgumentException when the payload shape or version is unrecognised
     */
    public function importStateOn(array $state): void
    {
        $normalised = self::validateStatePayload($state);

        foreach ($normalised as $specName => $endpoints) {
            foreach ($endpoints as $endpointKey => $responses) {
                foreach ($responses as $responseKey => $row) {
                    $existing = $this->observations[$specName][$endpointKey][$responseKey] ?? null;
                    if ($existing === null) {
                        $this->observations[$specName][$endpointKey][$responseKey] = $row;

                        continue;
                    }
                    $this->observations[$specName][$endpointKey][$responseKey] = [
                        'hits' => $existing['hits'] + $row['hits'],
                        'pointers' => self::mergePointers($existing['pointers'], $row['pointers']),
                    ];
                }
            }
        }
    }

    /**
     * @param array<string, list<string>> $left
     * @param array<string, list<string>> $right
     *
     * @return array<string, list<string>>
     */
    private static function mergePointers(array $left, array $right): array
    {
        $out = [];
        foreach ($left as $pointer => $leftKeys) {
            if (!array_key_exists($pointer, $right)) {
                // Pointer absent on one side — drop. "Always observed"
                // requires the pointer itself to be always observed.
                continue;
            }
            $intersected = array_values(array_intersect($leftKeys, $right[$pointer]));
            sort($intersected);
            $out[$pointer] = $intersected;
        }
        ksort($out);

        return $out;
    }

    /**
     * Normalise the caller-provided map: sort + dedup each pointer's key
     * list, validate types, and key-sort the outer map for deterministic
     * storage / diff output. Input is typed loosely so the runtime guards
     * are preserved under static analysis (see {@see self::record()}).
     *
     * @param array<mixed, mixed> $pointers
     *
     * @return array<string, list<string>>
     */
    private static function normalisePointers(
        string $specName,
        string $endpointKey,
        string $responseKey,
        array $pointers,
    ): array {
        $out = [];
        foreach ($pointers as $pointer => $keys) {
            if (!is_string($pointer) || $pointer === '') {
                throw new InvalidArgumentException(sprintf(
                    'StrictRequiredTracker::record() expects non-empty string pointers; '
                    . 'got %s at %s :: %s :: %s.',
                    is_string($pointer) ? '""' : get_debug_type($pointer),
                    $specName,
                    $endpointKey,
                    $responseKey,
                ));
            }
            if (!is_array($keys) || !array_is_list($keys)) {
                throw new InvalidArgumentException(sprintf(
                    'StrictRequiredTracker::record() expects list<string> values per pointer; '
                    . 'got %s at %s :: %s :: %s pointer %s.',
                    get_debug_type($keys),
                    $specName,
                    $endpointKey,
                    $responseKey,
                    $pointer,
                ));
            }
            foreach ($keys as $key) {
                if (!is_string($key)) {
                    throw new InvalidArgumentException(sprintf(
                        'StrictRequiredTracker::record() expects list<string> values per pointer; '
                        . 'got %s entry at %s :: %s :: %s pointer %s.',
                        get_debug_type($key),
                        $specName,
                        $endpointKey,
                        $responseKey,
                        $pointer,
                    ));
                }
            }
            $unique = array_values(array_unique($keys));
            sort($unique);
            $out[$pointer] = $unique;
        }
        ksort($out);

        return $out;
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
        if (array_key_exists('alwaysPresent', $row)) {
            // v1 row shape detected. The state version check above should
            // already have rejected this payload; surface the hint here too
            // for callers that hand-build rows without a `version` field.
            throw new InvalidArgumentException(sprintf(
                'strict_required %s / %s / %s carries v1 "alwaysPresent" field; '
                . 'state format v2 expects "pointers" map. Upgrade all paratest workers to the same library version.',
                $specName,
                $endpointKey,
                $responseKey,
            ));
        }
        $pointers = $row['pointers'] ?? null;
        if (!is_array($pointers)) {
            throw new InvalidArgumentException(sprintf(
                'strict_required %s / %s / %s must carry pointers as a map.',
                $specName,
                $endpointKey,
                $responseKey,
            ));
        }

        $normalised = [];
        foreach ($pointers as $pointer => $keys) {
            if (!is_string($pointer) || $pointer === '') {
                throw new InvalidArgumentException(sprintf(
                    'strict_required %s / %s / %s pointer keys must be non-empty strings.',
                    $specName,
                    $endpointKey,
                    $responseKey,
                ));
            }
            if (!is_array($keys) || !array_is_list($keys)) {
                throw new InvalidArgumentException(sprintf(
                    'strict_required %s / %s / %s pointer %s must carry a list of keys.',
                    $specName,
                    $endpointKey,
                    $responseKey,
                    $pointer,
                ));
            }
            $strings = [];
            foreach ($keys as $value) {
                if (!is_string($value)) {
                    throw new InvalidArgumentException(sprintf(
                        'strict_required %s / %s / %s pointer %s entries must be strings; got %s.',
                        $specName,
                        $endpointKey,
                        $responseKey,
                        $pointer,
                        get_debug_type($value),
                    ));
                }
                $strings[] = $value;
            }
            sort($strings);
            $normalised[$pointer] = $strings;
        }
        ksort($normalised);

        return ['hits' => $hits, 'pointers' => $normalised];
    }
}
