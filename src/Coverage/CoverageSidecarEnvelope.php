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
 * Gesso 3 reads only envelope 10 (#505): the legacy envelopes 2-9 and the
 * bare v1 coverage state are rejected. Sidecars are per-run artifacts, so the
 * fix is to re-run the suite with the same Gesso version as the merge step.
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
    /** The only wire version; parse() rejects every other one. */
    public const ENVELOPE_VERSION = 10;

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
     * Route a v10 sidecar payload into its tracker-shaped parts.
     *
     * @param array<string, mixed> $payload
     *
     * @return ParsedEnvelope
     *
     * @throws InvalidArgumentException on any envelope version other than
     *                                  10, or a malformed half
     */
    public static function parse(array $payload): array
    {
        if (($payload['envelopeVersion'] ?? null) !== self::ENVELOPE_VERSION) {
            throw new InvalidArgumentException(sprintf(
                '%s; Gesso 3 reads envelopeVersion %d only. The worker and merge steps are running '
                . 'different Gesso versions: install the same version in both (check with '
                . '`composer show studio-design/gesso`), delete the sidecar directory, and re-run the '
                . 'parallel suite to regenerate the sidecars. Old sidecars cannot be converted.',
                self::describeOrigin($payload),
                self::ENVELOPE_VERSION,
            ));
        }

        return [
            'coverage' => self::requiredHalf($payload, 'coverage'),
            'strictRequired' => self::presentHalf($payload, 'strictRequired'),
            'strictAdditionalProperties' => self::presentHalf($payload, 'strictAdditionalProperties'),
            'sdkExercise' => self::presentHalf($payload, 'sdkExercise'),
            'deprecations' => self::presentHalf($payload, 'deprecations'),
            'baseline' => self::presentHalf($payload, 'baseline'),
        ];
    }

    /**
     * Name the writer behind a rejected payload: the operator's problem is a
     * split fleet, not the envelope number.
     *
     * @param array<string, mixed> $payload
     */
    private static function describeOrigin(array $payload): string
    {
        if (!array_key_exists('envelopeVersion', $payload)) {
            return array_key_exists('version', $payload) && array_key_exists('specs', $payload)
                ? 'This sidecar is a bare coverage state with no envelopeVersion, written by a Gesso 1.x worker'
                : 'Unrecognised sidecar payload with no envelopeVersion';
        }

        $version = $payload['envelopeVersion'];
        if (!is_int($version)) {
            return sprintf('Unsupported sidecar envelopeVersion of type %s', get_debug_type($version));
        }

        return match (true) {
            $version >= 2 && $version <= 9 => sprintf('Sidecar envelope version %d was written by a Gesso 1.9-2.6 worker', $version),
            $version > self::ENVELOPE_VERSION => sprintf('Sidecar envelope version %d was written by a newer Gesso worker', $version),
            default => sprintf('Unsupported sidecar envelope version %d', $version),
        };
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
}
