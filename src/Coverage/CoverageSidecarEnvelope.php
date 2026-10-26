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
use function in_array;
use function is_array;
use function is_int;
use function sprintf;

/**
 * Wire-format helper for the coverage sidecar envelope.
 *
 * The envelope wraps each tracker's `exportState()` payload under one JSON
 * object so paratest workers hand everything off in one atomic write; the
 * merge CLI feeds each half back through its tracker's `importState()`.
 *
 * Wire shape (v10):
 * ```json
 * {
 *   "envelopeVersion": 10,
 *   "coverage":                   { "version": 1, "specs": { } },
 *   "strictRequired":             { "version": 2, "observations": { } },
 *   "strictAdditionalProperties": { "version": 1, "...": { } },
 *   "sdkExercise":                { "version": 1, "...": { } },
 *   "deprecations":               { "version": 1, "deprecations": { } },
 *   "baseline":                   { "baseline_version": 1, "violations": [ ] }
 * }
 * ```
 *
 * `envelopeVersion` and `coverage` are required. Every other key is optional
 * and its presence is the signal: an absent key means the writer had no such
 * tracker, so a new half is a new optional key, never a new version. Inner
 * `version` fields stay owned by their trackers ({@see StrictRequiredTracker},
 * {@see StrictAdditionalPropertiesTracker}, {@see Deprecations},
 * {@see ViolationBaselineFile}) and evolve independently.
 *
 * The 2.x reader still accepts the legacy envelopes 2-9 (whose version
 * enumerated the halves they carried) and the bare v1 coverage state with
 * no `envelopeVersion` key, each with a one-shot `[Gesso deprecation]`
 * notice since 2.9.0 once accepted (the bare state's from the merge, after
 * the coverage tracker validates it); 3.0 rejects both (#505). See docs/versioning.md.
 * The wrapper key is `envelopeVersion` (not `version`) precisely so bare
 * v1 payloads remain distinguishable; see {@see self::parse()}'s
 * discriminator order.
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
 *     strictRequired?: StrictRequiredStatePayload,
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
    /** Current wire version. Readers reject anything newer; see parse(). */
    public const ENVELOPE_VERSION = 10;

    /**
     * Inputs the 2.x reader still accepts, written by Gesso 1.9-2.6 workers. The
     * version enumerated which optional halves the writer carried; v10 stops that
     * and signals presence by the key itself. Deleted by the v3.0 cutoff (#505).
     *
     * @deprecated since 2.9.0, together with the bare v1 coverage state; reading
     *             either emits a `[Gesso deprecation]` notice. Removed in 3.0.
     */
    private const LEGACY_VERSIONS = [2, 3, 4, 5, 6, 7, 8, 9];

    private const LEGACY_VERSIONS_WITH_STRICT_ADDITIONAL_PROPERTIES = [4, 5, 6, 7, 8, 9];
    private const LEGACY_VERSIONS_WITH_SDK_EXERCISE = [6, 7, 8, 9];
    private const LEGACY_VERSIONS_WITH_DEPRECATIONS = [8, 9];

    /** Legacy versions that must not carry a `baseline` key (their +1 sibling does). */
    private const LEGACY_PLAIN_VERSIONS = [2, 4, 6, 8];

    private function __construct() {}

    /**
     * Compose an envelope from the two tracker `exportState()` payloads.
     * Typed `@phpstan-param`s flow the tracker shapes through so PHPStan
     * catches a tracker-side `exportState()` regression at this boundary.
     *
     * Each optional half is written only when supplied; `$baselineDocument`
     * is the {@see ViolationBaselineFile::toDocument()} document.
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
        $payload = [
            'envelopeVersion' => self::ENVELOPE_VERSION,
            'coverage' => $coverageState,
            'strictRequired' => $strictRequiredState,
        ];

        foreach ([
            'strictAdditionalProperties' => $strictAdditionalPropertiesState,
            'sdkExercise' => $sdkExerciseState,
            'deprecations' => $deprecationsState,
            'baseline' => $baselineDocument,
        ] as $key => $half) {
            if ($half !== null) {
                $payload[$key] = $half;
            }
        }

        return $payload;
    }

    /**
     * Route a sidecar payload into its tracker-shaped parts.
     *
     * Discriminator priority:
     *  1. `envelopeVersion` present → v10 (presence-keyed) or a legacy 2–9
     *     envelope. Unknown values are rejected.
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
            // inner shape on its own terms; the merge announces the #505
            // deprecation once that validation has passed.
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
        if ($version === self::ENVELOPE_VERSION) {
            return [
                'coverage' => self::requiredHalf($payload, 'coverage'),
                'strictRequired' => self::presentHalf($payload, 'strictRequired'),
                'strictAdditionalProperties' => self::presentHalf($payload, 'strictAdditionalProperties'),
                'sdkExercise' => self::presentHalf($payload, 'sdkExercise'),
                'deprecations' => self::presentHalf($payload, 'deprecations'),
                'baseline' => self::presentHalf($payload, 'baseline'),
            ];
        }

        if (!is_int($version) || !in_array($version, self::LEGACY_VERSIONS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported sidecar envelope version: got %s, expected %d (current) or a legacy version 2-9.',
                is_int($version) ? (string) $version : get_debug_type($version),
                self::ENVELOPE_VERSION,
            ));
        }

        // The #505 deprecation is raised by the merge once the trackers
        // accepted every half, so a malformed sidecar records no count.
        return self::parseLegacyEnvelope($payload, $version);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function requiredHalf(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;
        if (!is_array($value)) {
            throw new InvalidArgumentException(sprintf(
                'Sidecar envelope "%s" must be an array; got %s.',
                $key,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return null|array<string, mixed>
     */
    private static function optionalHalf(array $payload, string $key): ?array
    {
        $value = $payload[$key] ?? null;
        if ($value !== null && !is_array($value)) {
            throw new InvalidArgumentException(sprintf(
                'Sidecar envelope "%s" must be an array or absent; got %s.',
                $key,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    /**
     * v10 is presence-keyed: the writer omits an absent half, so a present
     * key must hold an array — an explicit null is malformed, not "absent".
     *
     * @param array<string, mixed> $payload
     *
     * @return null|array<string, mixed>
     */
    private static function presentHalf(array $payload, string $key): ?array
    {
        if (!array_key_exists($key, $payload)) {
            return null;
        }

        return self::requiredHalf($payload, $key);
    }

    /**
     * Envelopes 2-9: the version decides which halves must and must not be
     * present. Removed by the v3.0 cutoff (#505).
     *
     * @param array<string, mixed> $payload
     *
     * @return ParsedEnvelope
     */
    private static function parseLegacyEnvelope(array $payload, int $version): array
    {
        $coverage = self::requiredHalf($payload, 'coverage');
        $strictRequired = self::optionalHalf($payload, 'strictRequired');

        $strictAdditionalProperties = self::readHalf(
            $payload,
            $version,
            'strictAdditionalProperties',
            self::LEGACY_VERSIONS_WITH_STRICT_ADDITIONAL_PROPERTIES,
            '4, 5, 6, 7, 8, or 9',
        );
        $sdkExercise = self::readHalf(
            $payload,
            $version,
            'sdkExercise',
            self::LEGACY_VERSIONS_WITH_SDK_EXERCISE,
            '6, 7, 8, or 9',
        );
        $deprecations = self::readHalf(
            $payload,
            $version,
            'deprecations',
            self::LEGACY_VERSIONS_WITH_DEPRECATIONS,
            '8 or 9',
        );

        $baseline = $payload['baseline'] ?? null;
        if (in_array($version, self::LEGACY_PLAIN_VERSIONS, true)) {
            // A plain envelope must not smuggle a baseline half: accepting it
            // would silently drop generation data whenever a future writer
            // (or a hand-edited sidecar) forgets the version bump — mirror
            // the v1 `strictRequired` guard in parse().
            if (array_key_exists('baseline', $payload)) {
                throw new InvalidArgumentException(sprintf(
                    'Sidecar envelope v%d must not contain a "baseline" key; expected envelopeVersion=%d when baseline data is present.',
                    $version,
                    $version + 1,
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
