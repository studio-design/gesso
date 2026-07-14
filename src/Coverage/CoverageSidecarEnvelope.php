<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Coverage;

use InvalidArgumentException;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredTracker;

use function array_key_exists;
use function get_debug_type;
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
 * Backwards compatibility: workers running an older library version write
 * a bare v1 coverage payload (`{ "version": 1, "specs": { ... } }`) with no
 * `envelopeVersion` key. {@see self::parse()} detects this shape and
 * returns the legacy payload as `coverage` with `strictRequired => null`,
 * so a mixed-version fleet can still merge coverage cleanly.
 *
 * The envelope intentionally does NOT flatten the inner tracker payloads —
 * each `version` field remains owned by its respective tracker and can
 * evolve independently of the envelope version. The wrapper key is
 * `envelopeVersion` (not `version`) precisely so legacy v1 bare coverage
 * payloads — which already use `version` at the top level — remain
 * distinguishable; see {@see self::parse()}'s discriminator order.
 *
 * @phpstan-import-type CoverageStatePayload from OpenApiCoverageTracker
 * @phpstan-import-type StrictRequiredStatePayload from StrictRequiredTracker
 *
 * @phpstan-type SidecarEnvelopePayload array{
 *     envelopeVersion: int,
 *     coverage: CoverageStatePayload,
 *     strictRequired: StrictRequiredStatePayload,
 * }
 * @phpstan-type ParsedEnvelope array{
 *     coverage: array<string, mixed>,
 *     strictRequired: array<string, mixed>|null,
 * }
 */
final class CoverageSidecarEnvelope
{
    /**
     * Envelope wire-format version. Importers reject unknown values rather
     * than guessing — a future v3 worker writing into an older merge CLI
     * must fail loudly so partial-upgrade silos surface immediately.
     */
    public const ENVELOPE_VERSION = 2;

    private function __construct() {}

    /**
     * Compose a v2 envelope from the two tracker `exportState()` payloads.
     * Typed `@phpstan-param`s flow the tracker shapes through so PHPStan
     * catches a tracker-side `exportState()` regression at this boundary.
     *
     * @phpstan-param CoverageStatePayload $coverageState
     * @phpstan-param StrictRequiredStatePayload $strictRequiredState
     *
     * @return SidecarEnvelopePayload
     */
    public static function build(array $coverageState, array $strictRequiredState): array
    {
        return [
            'envelopeVersion' => self::ENVELOPE_VERSION,
            'coverage' => $coverageState,
            'strictRequired' => $strictRequiredState,
        ];
    }

    /**
     * Route a sidecar payload into its tracker-shaped parts.
     *
     * Discriminator priority:
     *  1. `envelopeVersion` present → v2 path. Unknown values are rejected.
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
            if (array_key_exists('strictRequired', $payload)) {
                throw new InvalidArgumentException(
                    'Legacy v1 sidecar payload must not contain a top-level "strictRequired" key; '
                    . 'expected an envelopeVersion=2 envelope when strict_required data is present.',
                );
            }

            // Legacy v1 bare coverage payload. Hand it back as the coverage
            // half so OpenApiCoverageTracker::importState() validates the
            // inner shape on its own terms.
            return [
                'coverage' => $payload,
                'strictRequired' => null,
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
        if (!is_int($version) || $version !== self::ENVELOPE_VERSION) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported sidecar envelope version: got %s, expected %d.',
                is_int($version) ? (string) $version : get_debug_type($version),
                self::ENVELOPE_VERSION,
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

        return [
            'coverage' => $coverage,
            'strictRequired' => $strictRequired,
        ];
    }
}
