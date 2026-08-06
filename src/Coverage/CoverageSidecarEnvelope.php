<?php

declare(strict_types=1);

namespace Studio\Gesso\Coverage;

use InvalidArgumentException;
use Studio\Gesso\Baseline\ViolationBaselineFile;
use Studio\Gesso\Internal\Deprecations;
use Studio\Gesso\Validation\Strict\StrictAdditionalPropertiesTracker;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;

use function array_key_exists;
use function get_debug_type;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function sprintf;

/**
 * Wire-format helper for the v2 sidecar envelope.
 *
 * v2 wraps two tracker payloads — the existing {@see OpenApiCoverageTracker}
 * coverage state and the {@see StrictRequiredTracker} observations — under
 * a single JSON object so paratest workers can hand both off in one atomic
 * write. The merge CLI then aggregates each side via its tracker's
 * `importState()` and runs the strict_required gate against the union.
 *
 * Wire shape (v2):
 * ```json
 * {
 *   "envelopeVersion": 2,
 *   "coverage":       { "version": 1, "specs":        { ... } },
 *   "strictRequired": { "version": 2, "observations": { ... } }
 * }
 * ```
 *
 * Wire shape (v3, issue #417) adds the violation-baseline half collected
 * during a parallel generation run (`OPENAPI_BASELINE_GENERATE` under
 * paratest). Its value is the baseline-file document verbatim
 * ({@see ViolationBaselineFile}), so the inner
 * `baseline_version` stays owned by the baseline format:
 * ```json
 * {
 *   "envelopeVersion": 3,
 *   "coverage":       { "version": 1, "specs":        { ... } },
 *   "strictRequired": { "version": 2, "observations": { ... } },
 *   "baseline":       { "baseline_version": 1, "violations": [ ... ] }
 * }
 * ```
 *
 * Wire shapes v4/v5 add `strictAdditionalProperties` state. v4 is the
 * legacy plain-worker format; v5 is v4 plus the v3 baseline half.
 * Older v2/v3 envelopes remain readable and contribute no strict
 * additional-properties observations.
 *
 * Wire shapes v6/v7 add `sdkExercise` state. Versions v2-v5 remain readable
 * and contribute no SDK exercise observations.
 *
 * Wire shapes v8/v9 add the `deprecations` half (issue #499) — the per-id
 * counts {@see Deprecations} accumulated in the worker.
 * v8 is the current plain-worker format; v9 is v8 plus the baseline half. The
 * half is written even when the worker used no deprecated surface, because
 * "this worker saw none" is what the merged report has to be able to prove;
 * v2-v7 remain readable but cannot distinguish that from "not recorded", so
 * the merge CLI says so rather than reporting a zero it cannot stand behind.
 *
 * Backwards compatibility: workers running an older library version write
 * a bare v1 coverage payload (`{ "version": 1, "specs": { ... } }`) with no
 * `envelopeVersion` key. {@see self::parse()} detects this shape and
 * returns the legacy payload as `coverage` with `strictRequired => null`
 * and `strictAdditionalProperties => null`, so a mixed-version fleet can
 * still merge coverage cleanly.
 *
 * The envelope intentionally does NOT flatten the inner tracker payloads —
 * each `version` field remains owned by its respective tracker and can
 * evolve independently of the envelope version. The wrapper key is
 * `envelopeVersion` (not `version`) precisely so legacy v1 bare coverage
 * payloads — which already use `version` at the top level — remain
 * distinguishable; see {@see self::parse()}'s discriminator order.
 *
 * @internal Used by the PHPUnit extension and merge CLI. The accepted wire
 *           formats remain versioned compatibility inputs.
 *
 * @phpstan-import-type CoverageStatePayload from OpenApiCoverageTracker
 * @phpstan-import-type StrictRequiredStatePayload from StrictRequiredTracker
 * @phpstan-import-type StrictAdditionalPropertiesState from StrictAdditionalPropertiesTracker
 *
 * @phpstan-type SidecarEnvelopePayload array{
 *     envelopeVersion: int,
 *     coverage: CoverageStatePayload,
 *     strictRequired: StrictRequiredStatePayload,
 *     strictAdditionalProperties?: StrictAdditionalPropertiesState,
 *     sdkExercise?: array<string, mixed>,
 *     deprecations?: array<string, mixed>,
 *     baseline?: array<string, mixed>,
 * }
 * @phpstan-type ParsedEnvelope array{
 *     coverage: array<string, mixed>,
 *     strictRequired: array<string, mixed>|null,
 *     strictAdditionalProperties: array<string, mixed>|null,
 *     sdkExercise: array<string, mixed>|null,
 *     deprecations: array<string, mixed>|null,
 *     baseline: array<string, mixed>|null,
 * }
 */
final class CoverageSidecarEnvelope
{
    /**
     * Envelope wire-format version. Importers reject unknown values rather
     * than guessing — a future writer's version landing in an older merge
     * CLI must fail loudly so partial-upgrade silos surface immediately.
     */
    public const ENVELOPE_VERSION = 2;

    /**
     * Legacy envelope version carrying the violation-baseline half
     * (issue #417). Current workers use v5 because they also export strict
     * additional-properties state; v3 remains a supported input.
     */
    public const ENVELOPE_VERSION_WITH_BASELINE = 3;

    public const ENVELOPE_VERSION_WITH_STRICT_ADDITIONAL_PROPERTIES = 4;
    public const ENVELOPE_VERSION_WITH_STRICT_ADDITIONAL_PROPERTIES_AND_BASELINE = 5;
    public const ENVELOPE_VERSION_WITH_SDK_EXERCISE = 6;
    public const ENVELOPE_VERSION_WITH_SDK_EXERCISE_AND_BASELINE = 7;
    public const ENVELOPE_VERSION_WITH_DEPRECATIONS = 8;
    public const ENVELOPE_VERSION_WITH_DEPRECATIONS_AND_BASELINE = 9;

    /**
     * Every accepted envelope version, in the order they were introduced.
     * The pairs alternate plain / with-baseline because the baseline half is
     * present only during an `OPENAPI_BASELINE_GENERATE` run, so it is
     * orthogonal to the tracker half that raised the version.
     */
    private const ACCEPTED_VERSIONS = [
        self::ENVELOPE_VERSION,
        self::ENVELOPE_VERSION_WITH_BASELINE,
        self::ENVELOPE_VERSION_WITH_STRICT_ADDITIONAL_PROPERTIES,
        self::ENVELOPE_VERSION_WITH_STRICT_ADDITIONAL_PROPERTIES_AND_BASELINE,
        self::ENVELOPE_VERSION_WITH_SDK_EXERCISE,
        self::ENVELOPE_VERSION_WITH_SDK_EXERCISE_AND_BASELINE,
        self::ENVELOPE_VERSION_WITH_DEPRECATIONS,
        self::ENVELOPE_VERSION_WITH_DEPRECATIONS_AND_BASELINE,
    ];

    /** Versions whose writer always emits the `strictAdditionalProperties` half. */
    private const VERSIONS_WITH_STRICT_ADDITIONAL_PROPERTIES = [
        self::ENVELOPE_VERSION_WITH_STRICT_ADDITIONAL_PROPERTIES,
        self::ENVELOPE_VERSION_WITH_STRICT_ADDITIONAL_PROPERTIES_AND_BASELINE,
        self::ENVELOPE_VERSION_WITH_SDK_EXERCISE,
        self::ENVELOPE_VERSION_WITH_SDK_EXERCISE_AND_BASELINE,
        self::ENVELOPE_VERSION_WITH_DEPRECATIONS,
        self::ENVELOPE_VERSION_WITH_DEPRECATIONS_AND_BASELINE,
    ];

    /** Versions whose writer always emits the `sdkExercise` half. */
    private const VERSIONS_WITH_SDK_EXERCISE = [
        self::ENVELOPE_VERSION_WITH_SDK_EXERCISE,
        self::ENVELOPE_VERSION_WITH_SDK_EXERCISE_AND_BASELINE,
        self::ENVELOPE_VERSION_WITH_DEPRECATIONS,
        self::ENVELOPE_VERSION_WITH_DEPRECATIONS_AND_BASELINE,
    ];

    /** Versions whose writer always emits the `deprecations` half. */
    private const VERSIONS_WITH_DEPRECATIONS = [
        self::ENVELOPE_VERSION_WITH_DEPRECATIONS,
        self::ENVELOPE_VERSION_WITH_DEPRECATIONS_AND_BASELINE,
    ];

    private function __construct() {}

    /**
     * Compose an envelope from the two tracker `exportState()` payloads.
     * Typed `@phpstan-param`s flow the tracker shapes through so PHPStan
     * catches a tracker-side `exportState()` regression at this boundary.
     *
     * `$baselineDocument` (the baseline-file document from
     * {@see ViolationBaselineFile::toDocument()})
     * upgrades a legacy v2 envelope to v3. When strict additional-properties
     * state is supplied, the corresponding pair is v4 (plain) / v5
     * (baseline).
     *
     * @param null|array<string, mixed> $baselineDocument
     * @param null|array<string, mixed> $strictAdditionalPropertiesState
     * @param null|array<string, mixed> $sdkExerciseState
     * @param null|array<string, mixed> $deprecationsState
     *
     * @phpstan-param CoverageStatePayload $coverageState
     * @phpstan-param StrictRequiredStatePayload $strictRequiredState
     * @phpstan-param null|StrictAdditionalPropertiesState $strictAdditionalPropertiesState
     *
     * @return SidecarEnvelopePayload
     */
    public static function build(
        array $coverageState,
        array $strictRequiredState,
        ?array $baselineDocument = null,
        ?array $strictAdditionalPropertiesState = null,
        ?array $sdkExerciseState = null,
        ?array $deprecationsState = null,
    ): array {
        // The halves were introduced in order, and each version implies every
        // earlier one, so a caller that supplies a later half without an
        // earlier one is describing an envelope no reader can represent.
        if ($sdkExerciseState !== null && $strictAdditionalPropertiesState === null) {
            throw new InvalidArgumentException('SDK exercise sidecar envelopes require strict additional-properties state.');
        }

        if ($deprecationsState !== null && $sdkExerciseState === null) {
            throw new InvalidArgumentException('Deprecation sidecar envelopes require SDK exercise state.');
        }

        $withBaseline = $baselineDocument !== null;
        $payload = [
            'envelopeVersion' => match (true) {
                $deprecationsState !== null => $withBaseline
                    ? self::ENVELOPE_VERSION_WITH_DEPRECATIONS_AND_BASELINE
                    : self::ENVELOPE_VERSION_WITH_DEPRECATIONS,
                $sdkExerciseState !== null => $withBaseline
                    ? self::ENVELOPE_VERSION_WITH_SDK_EXERCISE_AND_BASELINE
                    : self::ENVELOPE_VERSION_WITH_SDK_EXERCISE,
                $strictAdditionalPropertiesState !== null => $withBaseline
                    ? self::ENVELOPE_VERSION_WITH_STRICT_ADDITIONAL_PROPERTIES_AND_BASELINE
                    : self::ENVELOPE_VERSION_WITH_STRICT_ADDITIONAL_PROPERTIES,
                default => $withBaseline
                    ? self::ENVELOPE_VERSION_WITH_BASELINE
                    : self::ENVELOPE_VERSION,
            },
            'coverage' => $coverageState,
            'strictRequired' => $strictRequiredState,
        ];

        if ($strictAdditionalPropertiesState !== null) {
            $payload['strictAdditionalProperties'] = $strictAdditionalPropertiesState;
        }

        if ($sdkExerciseState !== null) {
            $payload['sdkExercise'] = $sdkExerciseState;
        }

        if ($deprecationsState !== null) {
            $payload['deprecations'] = $deprecationsState;
        }

        if ($baselineDocument !== null) {
            $payload['baseline'] = $baselineDocument;
        }

        return $payload;
    }

    /**
     * Route a sidecar payload into its tracker-shaped parts.
     *
     * Discriminator priority:
     *  1. `envelopeVersion` present → v2–v5 path. Unknown values are rejected.
     *  2. `version` + `specs` keys → legacy v1 bare coverage payload;
     *     returns `strictRequired => null`.
     *  3. Otherwise reject (unrecognised shape).
     *
     * @param array<string, mixed> $payload
     *
     * @return ParsedEnvelope
     *
     * @throws InvalidArgumentException on unknown envelope version or
     *                                  unrecognised payload shape
     */
    public static function parse(array $payload): array
    {
        if (array_key_exists('envelopeVersion', $payload)) {
            return self::parseEnvelope($payload);
        }

        if (array_key_exists('version', $payload) && array_key_exists('specs', $payload)) {
            // Forward-compat: a v1 shape must NOT carry a `strictRequired`
            // half. Accepting it would silently discard observations when
            // (a) a future writer ships a coverage-only v3 wire that drops
            // `envelopeVersion`, or (b) a hand-edited sidecar happens to
            // land in the v1 fast-path. Mirror the strict version check
            // {@see StrictRequiredTracker::importState()} performs on its
            // own payload.
            if (
                array_key_exists('strictRequired', $payload) ||
                array_key_exists('strictAdditionalProperties', $payload) ||
                array_key_exists('sdkExercise', $payload) ||
                array_key_exists('deprecations', $payload)
            ) {
                throw new InvalidArgumentException(
                    'Legacy v1 sidecar payload must not contain top-level "strictRequired" '
                    . ', "strictAdditionalProperties", "sdkExercise", or "deprecations" keys; '
                    . 'expected a versioned envelope when strict data is present.',
                );
            }

            // Legacy v1 bare coverage payload. Hand it back as the coverage
            // half so OpenApiCoverageTracker::importState() validates the
            // inner shape on its own terms.
            return [
                'coverage' => $payload,
                'strictRequired' => null,
                'strictAdditionalProperties' => null,
                'sdkExercise' => null,
                'deprecations' => null,
                'baseline' => null,
            ];
        }

        throw new InvalidArgumentException(
            'Unrecognised sidecar payload: missing both "envelopeVersion" (v2) and "version"+"specs" (v1).',
        );
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return ParsedEnvelope
     */
    private static function parseEnvelope(array $payload): array
    {
        $version = $payload['envelopeVersion'] ?? null;
        if (!is_int($version) || !in_array($version, self::ACCEPTED_VERSIONS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported sidecar envelope version: got %s, expected one of %s.',
                is_int($version) ? (string) $version : get_debug_type($version),
                implode(', ', self::ACCEPTED_VERSIONS),
            ));
        }

        $coverage = $payload['coverage'] ?? null;
        if (!is_array($coverage)) {
            throw new InvalidArgumentException(sprintf(
                'Sidecar envelope "coverage" must be an array; got %s.',
                get_debug_type($coverage),
            ));
        }

        $strictRequired = $payload['strictRequired'] ?? null;
        if ($strictRequired !== null && !is_array($strictRequired)) {
            throw new InvalidArgumentException(sprintf(
                'Sidecar envelope "strictRequired" must be an array or absent; got %s.',
                get_debug_type($strictRequired),
            ));
        }

        $strictAdditionalProperties = self::readHalf(
            $payload,
            $version,
            'strictAdditionalProperties',
            self::VERSIONS_WITH_STRICT_ADDITIONAL_PROPERTIES,
            '4, 5, 6, 7, 8, or 9',
        );
        $sdkExercise = self::readHalf(
            $payload,
            $version,
            'sdkExercise',
            self::VERSIONS_WITH_SDK_EXERCISE,
            '6, 7, 8, or 9',
        );
        $deprecations = self::readHalf(
            $payload,
            $version,
            'deprecations',
            self::VERSIONS_WITH_DEPRECATIONS,
            '8 or 9',
        );

        $baseline = $payload['baseline'] ?? null;
        if (in_array($version, [
            self::ENVELOPE_VERSION,
            self::ENVELOPE_VERSION_WITH_STRICT_ADDITIONAL_PROPERTIES,
            self::ENVELOPE_VERSION_WITH_SDK_EXERCISE,
            self::ENVELOPE_VERSION_WITH_DEPRECATIONS,
        ], true)) {
            // A plain envelope must not smuggle a baseline half: accepting it
            // would silently drop generation data whenever a future writer
            // (or a hand-edited sidecar) forgets the version bump — mirror
            // the v1 `strictRequired` guard in parse().
            if (array_key_exists('baseline', $payload)) {
                $expectedVersion = match ($version) {
                    self::ENVELOPE_VERSION => self::ENVELOPE_VERSION_WITH_BASELINE,
                    self::ENVELOPE_VERSION_WITH_STRICT_ADDITIONAL_PROPERTIES => self::ENVELOPE_VERSION_WITH_STRICT_ADDITIONAL_PROPERTIES_AND_BASELINE,
                    self::ENVELOPE_VERSION_WITH_SDK_EXERCISE => self::ENVELOPE_VERSION_WITH_SDK_EXERCISE_AND_BASELINE,
                    self::ENVELOPE_VERSION_WITH_DEPRECATIONS => self::ENVELOPE_VERSION_WITH_DEPRECATIONS_AND_BASELINE,
                };

                throw new InvalidArgumentException(sprintf(
                    'Sidecar envelope v%d must not contain a "baseline" key; expected envelopeVersion=%d when baseline data is present.',
                    $version,
                    $expectedVersion,
                ));
            }
        } elseif (!is_array($baseline)) {
            // v3/v5 exist to carry the baseline half — omitting it is
            // malformed, not "generation off".
            throw new InvalidArgumentException(sprintf(
                'Sidecar envelope v%d "baseline" must be an array; got %s.',
                $version,
                get_debug_type($baseline),
            ));
        }

        return [
            'coverage' => $coverage,
            'strictRequired' => $strictRequired,
            'strictAdditionalProperties' => $strictAdditionalProperties,
            'sdkExercise' => $sdkExercise,
            'deprecations' => $deprecations,
            'baseline' => $baseline,
        ];
    }

    /**
     * Read one optional half whose presence is determined by the envelope
     * version: required where the version declares it, forbidden elsewhere.
     *
     * A half present under a version that does not declare it is rejected for
     * the same reason as the `baseline` guard below — silently ignoring it
     * would drop a worker's data whenever a writer forgets the version bump.
     *
     * @param array<string, mixed> $payload
     * @param list<int> $versionsCarryingHalf
     *
     * @return null|array<string, mixed>
     */
    private static function readHalf(
        array $payload,
        int $version,
        string $key,
        array $versionsCarryingHalf,
        string $expectedVersions,
    ): ?array {
        $value = $payload[$key] ?? null;

        if (in_array($version, $versionsCarryingHalf, true)) {
            if (!is_array($value)) {
                throw new InvalidArgumentException(sprintf(
                    'Sidecar envelope v%d "%s" must be an array; got %s.',
                    $version,
                    $key,
                    get_debug_type($value),
                ));
            }

            return $value;
        }

        if (array_key_exists($key, $payload)) {
            throw new InvalidArgumentException(sprintf(
                'Sidecar envelope v%d must not contain "%s"; expected envelopeVersion=%s.',
                $version,
                $key,
                $expectedVersions,
            ));
        }

        return null;
    }
}
