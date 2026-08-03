<?php

declare(strict_types=1);

namespace Studio\Gesso\Coverage;

use InvalidArgumentException;
use Studio\Gesso\Baseline\ViolationBaselineFile;
use Studio\Gesso\Validation\Strict\StrictAdditionalPropertiesTracker;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;

use function array_key_exists;
use function get_debug_type;
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
 * Wire shapes v6/v7 add `sdkExercise` state. v6 is the current plain-worker
 * format; v7 is v6 plus the baseline half. Versions v2-v5 remain readable
 * and contribute no SDK exercise observations.
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
 *     baseline?: array<string, mixed>,
 * }
 * @phpstan-type ParsedEnvelope array{
 *     coverage: array<string, mixed>,
 *     strictRequired: array<string, mixed>|null,
 *     strictAdditionalProperties: array<string, mixed>|null,
 *     sdkExercise: array<string, mixed>|null,
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
    ): array {
        if ($sdkExerciseState !== null) {
            if ($strictAdditionalPropertiesState === null) {
                throw new InvalidArgumentException('SDK exercise sidecar envelopes require strict additional-properties state.');
            }

            $payload = [
                'envelopeVersion' => $baselineDocument === null
                    ? self::ENVELOPE_VERSION_WITH_SDK_EXERCISE
                    : self::ENVELOPE_VERSION_WITH_SDK_EXERCISE_AND_BASELINE,
                'coverage' => $coverageState,
                'strictRequired' => $strictRequiredState,
                'strictAdditionalProperties' => $strictAdditionalPropertiesState,
                'sdkExercise' => $sdkExerciseState,
            ];
            if ($baselineDocument !== null) {
                $payload['baseline'] = $baselineDocument;
            }

            return $payload;
        }

        if ($strictAdditionalPropertiesState !== null) {
            $payload = [
                'envelopeVersion' => $baselineDocument === null
                    ? self::ENVELOPE_VERSION_WITH_STRICT_ADDITIONAL_PROPERTIES
                    : self::ENVELOPE_VERSION_WITH_STRICT_ADDITIONAL_PROPERTIES_AND_BASELINE,
                'coverage' => $coverageState,
                'strictRequired' => $strictRequiredState,
                'strictAdditionalProperties' => $strictAdditionalPropertiesState,
            ];
            if ($baselineDocument !== null) {
                $payload['baseline'] = $baselineDocument;
            }

            return $payload;
        }

        if ($baselineDocument === null) {
            return [
                'envelopeVersion' => self::ENVELOPE_VERSION,
                'coverage' => $coverageState,
                'strictRequired' => $strictRequiredState,
            ];
        }

        return [
            'envelopeVersion' => self::ENVELOPE_VERSION_WITH_BASELINE,
            'coverage' => $coverageState,
            'strictRequired' => $strictRequiredState,
            'baseline' => $baselineDocument,
        ];
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
                array_key_exists('sdkExercise', $payload)
            ) {
                throw new InvalidArgumentException(
                    'Legacy v1 sidecar payload must not contain top-level "strictRequired" '
                    . ', "strictAdditionalProperties", or "sdkExercise" keys; '
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
        if (
            !is_int($version) ||
            !in_array($version, [
                self::ENVELOPE_VERSION,
                self::ENVELOPE_VERSION_WITH_BASELINE,
                self::ENVELOPE_VERSION_WITH_STRICT_ADDITIONAL_PROPERTIES,
                self::ENVELOPE_VERSION_WITH_STRICT_ADDITIONAL_PROPERTIES_AND_BASELINE,
                self::ENVELOPE_VERSION_WITH_SDK_EXERCISE,
                self::ENVELOPE_VERSION_WITH_SDK_EXERCISE_AND_BASELINE,
            ], true)
        ) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported sidecar envelope version: got %s, expected one of %d, %d, %d, %d, %d, or %d.',
                is_int($version) ? (string) $version : get_debug_type($version),
                self::ENVELOPE_VERSION,
                self::ENVELOPE_VERSION_WITH_BASELINE,
                self::ENVELOPE_VERSION_WITH_STRICT_ADDITIONAL_PROPERTIES,
                self::ENVELOPE_VERSION_WITH_STRICT_ADDITIONAL_PROPERTIES_AND_BASELINE,
                self::ENVELOPE_VERSION_WITH_SDK_EXERCISE,
                self::ENVELOPE_VERSION_WITH_SDK_EXERCISE_AND_BASELINE,
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

        $strictAdditionalProperties = $payload['strictAdditionalProperties'] ?? null;
        $hasStrictAdditionalProperties = in_array($version, [
            self::ENVELOPE_VERSION_WITH_STRICT_ADDITIONAL_PROPERTIES,
            self::ENVELOPE_VERSION_WITH_STRICT_ADDITIONAL_PROPERTIES_AND_BASELINE,
            self::ENVELOPE_VERSION_WITH_SDK_EXERCISE,
            self::ENVELOPE_VERSION_WITH_SDK_EXERCISE_AND_BASELINE,
        ], true);
        if ($hasStrictAdditionalProperties && !is_array($strictAdditionalProperties)) {
            throw new InvalidArgumentException(sprintf(
                'Sidecar envelope v%d "strictAdditionalProperties" must be an array; got %s.',
                $version,
                get_debug_type($strictAdditionalProperties),
            ));
        }
        if (!$hasStrictAdditionalProperties && array_key_exists('strictAdditionalProperties', $payload)) {
            throw new InvalidArgumentException(sprintf(
                'Sidecar envelope v%d must not contain "strictAdditionalProperties"; expected envelopeVersion=4, 5, 6, or 7.',
                $version,
            ));
        }

        $sdkExercise = $payload['sdkExercise'] ?? null;
        $hasSdkExercise = in_array($version, [
            self::ENVELOPE_VERSION_WITH_SDK_EXERCISE,
            self::ENVELOPE_VERSION_WITH_SDK_EXERCISE_AND_BASELINE,
        ], true);
        if ($hasSdkExercise && !is_array($sdkExercise)) {
            throw new InvalidArgumentException(sprintf(
                'Sidecar envelope v%d "sdkExercise" must be an array; got %s.',
                $version,
                get_debug_type($sdkExercise),
            ));
        }
        if (!$hasSdkExercise && array_key_exists('sdkExercise', $payload)) {
            throw new InvalidArgumentException(sprintf(
                'Sidecar envelope v%d must not contain "sdkExercise"; expected envelopeVersion=6 or 7.',
                $version,
            ));
        }

        $baseline = $payload['baseline'] ?? null;
        if (in_array($version, [
            self::ENVELOPE_VERSION,
            self::ENVELOPE_VERSION_WITH_STRICT_ADDITIONAL_PROPERTIES,
            self::ENVELOPE_VERSION_WITH_SDK_EXERCISE,
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
            'baseline' => $baseline,
        ];
    }
}
