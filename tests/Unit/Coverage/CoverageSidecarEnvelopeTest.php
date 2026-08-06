<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Coverage;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\CoverageSidecarEnvelope;

/**
 * Pins the v2/v3 sidecar envelope wire formats and the v1 ↔ v2 ↔ v3
 * compatibility routing. The merge CLI relies on this discriminator to keep
 * working when a worker still on an older library version contributes a
 * bare v1 payload — and to fail loudly on shapes it cannot represent.
 */
class CoverageSidecarEnvelopeTest extends TestCase
{
    #[Test]
    public function build_emits_v2_shape_with_both_states(): void
    {
        $coverage = ['version' => 1, 'specs' => ['petstore' => []]];
        $strictRequired = ['version' => 1, 'observations' => ['petstore' => []]];

        $envelope = CoverageSidecarEnvelope::build($coverage, $strictRequired);

        $this->assertSame(2, $envelope['envelopeVersion']);
        $this->assertSame($coverage, $envelope['coverage']);
        $this->assertSame($strictRequired, $envelope['strictRequired']);
    }

    #[Test]
    public function parse_accepts_v2_payload_and_returns_both_states(): void
    {
        $payload = [
            'envelopeVersion' => 2,
            'coverage' => ['version' => 1, 'specs' => ['petstore' => []]],
            'strictRequired' => ['version' => 1, 'observations' => ['petstore' => []]],
        ];

        $parsed = CoverageSidecarEnvelope::parse($payload);

        $this->assertSame($payload['coverage'], $parsed['coverage']);
        $this->assertSame($payload['strictRequired'], $parsed['strictRequired']);
    }

    #[Test]
    public function build_with_a_baseline_document_emits_v3_shape(): void
    {
        // Issue #417: the baseline half upgrades the envelope to v3. Plain
        // runs must keep the exact v2 shape (previous test) so pre-#417
        // merge CLIs stay compatible with coverage-only fleets.
        $coverage = ['version' => 1, 'specs' => ['petstore' => []]];
        $strictRequired = ['version' => 1, 'observations' => ['petstore' => []]];
        $baseline = ['baseline_version' => 1, 'violations' => []];

        $envelope = CoverageSidecarEnvelope::build($coverage, $strictRequired, $baseline);

        $this->assertSame(3, $envelope['envelopeVersion']);
        $this->assertSame($coverage, $envelope['coverage']);
        $this->assertSame($strictRequired, $envelope['strictRequired']);
        $this->assertSame($baseline, $envelope['baseline']);
    }

    #[Test]
    public function parse_accepts_v3_payload_and_returns_the_baseline_half(): void
    {
        $payload = [
            'envelopeVersion' => 3,
            'coverage' => ['version' => 1, 'specs' => ['petstore' => []]],
            'strictRequired' => ['version' => 1, 'observations' => ['petstore' => []]],
            'baseline' => ['baseline_version' => 1, 'violations' => []],
        ];

        $parsed = CoverageSidecarEnvelope::parse($payload);

        $this->assertSame($payload['coverage'], $parsed['coverage']);
        $this->assertSame($payload['strictRequired'], $parsed['strictRequired']);
        $this->assertSame($payload['baseline'], $parsed['baseline']);
    }

    #[Test]
    public function parse_returns_null_baseline_for_v2_payload(): void
    {
        $parsed = CoverageSidecarEnvelope::parse([
            'envelopeVersion' => 2,
            'coverage' => ['version' => 1, 'specs' => []],
            'strictRequired' => ['version' => 1, 'observations' => []],
        ]);

        $this->assertNull($parsed['baseline']);
    }

    #[Test]
    public function parse_rejects_v2_payload_carrying_a_baseline_key(): void
    {
        // A v2 envelope smuggling a baseline half is ambiguous — either the
        // writer forgot the version bump or the payload was hand-edited.
        // Accepting it would silently drop generation data on older readers
        // that ignore unknown keys, so the v3-aware reader rejects it.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('baseline');

        CoverageSidecarEnvelope::parse([
            'envelopeVersion' => 2,
            'coverage' => ['version' => 1, 'specs' => []],
            'strictRequired' => ['version' => 1, 'observations' => []],
            'baseline' => ['baseline_version' => 1, 'violations' => []],
        ]);
    }

    #[Test]
    public function parse_rejects_v3_payload_without_a_baseline_half(): void
    {
        // v3 exists solely to carry the baseline half; a v3 envelope without
        // it is malformed, not "generation off" (that shape is v2).
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('baseline');

        CoverageSidecarEnvelope::parse([
            'envelopeVersion' => 3,
            'coverage' => ['version' => 1, 'specs' => []],
            'strictRequired' => ['version' => 1, 'observations' => []],
        ]);
    }

    #[Test]
    public function parse_accepts_legacy_v1_payload_and_returns_null_strict_required(): void
    {
        // v1 bare coverage payload — written by workers running an older
        // library version. The merge CLI must still load these so a partial
        // upgrade does not silently break coverage aggregation.
        $payload = ['version' => 1, 'specs' => ['petstore' => []]];

        $parsed = CoverageSidecarEnvelope::parse($payload);

        $this->assertSame($payload, $parsed['coverage']);
        $this->assertNull($parsed['strictRequired']);
    }

    #[Test]
    public function parse_rejects_unknown_envelope_version(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported sidecar envelope version');

        CoverageSidecarEnvelope::parse([
            'envelopeVersion' => 99,
            'coverage' => ['version' => 1, 'specs' => []],
            'strictRequired' => ['version' => 1, 'observations' => []],
        ]);
    }

    #[Test]
    public function parse_rejects_v2_payload_with_non_array_coverage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('coverage');

        CoverageSidecarEnvelope::parse([
            'envelopeVersion' => 2,
            'coverage' => 'not-an-array',
            'strictRequired' => ['version' => 1, 'observations' => []],
        ]);
    }

    #[Test]
    public function parse_rejects_payload_with_unrecognised_shape(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unrecognised sidecar payload');

        // Neither v2 (no envelopeVersion) nor v1 (no specs key) — must fail
        // fast rather than silently treating as empty coverage.
        CoverageSidecarEnvelope::parse(['hello' => 'world']);
    }

    #[Test]
    public function parse_rejects_v1_shape_carrying_unexpected_strict_required_key(): void
    {
        // Forward-compat guard: a v1 payload that also carries a top-level
        // `strictRequired` key is ambiguous — either the writer is using an
        // unknown wire variant or the envelopeVersion key was dropped. Fail
        // loudly rather than silently discarding observations.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('strictRequired');

        CoverageSidecarEnvelope::parse([
            'version' => 1,
            'specs' => [],
            'strictRequired' => ['version' => 1, 'observations' => []],
        ]);
    }

    #[Test]
    public function parse_treats_missing_strict_required_in_v2_as_null(): void
    {
        // Forward-compat: if a future writer omits strictRequired entirely
        // (rather than emitting empty observations), parse() degrades to
        // null so the merge CLI skips strict_required import cleanly.
        $payload = [
            'envelopeVersion' => 2,
            'coverage' => ['version' => 1, 'specs' => []],
        ];

        $parsed = CoverageSidecarEnvelope::parse($payload);

        $this->assertNull($parsed['strictRequired']);
    }

    #[Test]
    public function build_with_sdk_exercise_emits_v6_and_v7_envelopes(): void
    {
        $coverage = ['version' => 1, 'specs' => []];
        $strictRequired = ['version' => 2, 'observations' => []];
        $strictAdditional = ['version' => 1, 'evaluations' => 0, 'observations' => []];
        $sdkExercise = ['version' => 1, 'observations' => []];

        $plain = CoverageSidecarEnvelope::build(
            coverageState: $coverage,
            strictRequiredState: $strictRequired,
            strictAdditionalPropertiesState: $strictAdditional,
            sdkExerciseState: $sdkExercise,
        );
        $baseline = CoverageSidecarEnvelope::build(
            coverageState: $coverage,
            strictRequiredState: $strictRequired,
            baselineDocument: ['baseline_version' => 1, 'violations' => []],
            strictAdditionalPropertiesState: $strictAdditional,
            sdkExerciseState: $sdkExercise,
        );

        $this->assertSame(6, $plain['envelopeVersion']);
        $this->assertSame($sdkExercise, $plain['sdkExercise']);
        $this->assertSame(7, $baseline['envelopeVersion']);
        $this->assertSame($sdkExercise, $baseline['sdkExercise']);
    }

    #[Test]
    public function parse_routes_sdk_exercise_and_returns_null_for_legacy_versions(): void
    {
        $sdkExercise = ['version' => 1, 'observations' => []];
        $parsed = CoverageSidecarEnvelope::parse([
            'envelopeVersion' => 6,
            'coverage' => ['version' => 1, 'specs' => []],
            'strictRequired' => ['version' => 2, 'observations' => []],
            'strictAdditionalProperties' => ['version' => 1, 'evaluations' => 0, 'observations' => []],
            'sdkExercise' => $sdkExercise,
        ]);

        $this->assertSame($sdkExercise, $parsed['sdkExercise']);
        $legacy = CoverageSidecarEnvelope::parse([
            'envelopeVersion' => 4,
            'coverage' => ['version' => 1, 'specs' => []],
            'strictRequired' => ['version' => 2, 'observations' => []],
            'strictAdditionalProperties' => ['version' => 1, 'evaluations' => 0, 'observations' => []],
        ]);
        $this->assertNull($legacy['sdkExercise']);
    }

    #[Test]
    public function parse_rejects_missing_misplaced_or_unknown_sdk_envelope_shapes(): void
    {
        $base = [
            'coverage' => ['version' => 1, 'specs' => []],
            'strictRequired' => ['version' => 2, 'observations' => []],
            'strictAdditionalProperties' => ['version' => 1, 'evaluations' => 0, 'observations' => []],
        ];

        foreach ([
            ['envelopeVersion' => 6, ...$base],
            ['envelopeVersion' => 6, ...$base, 'sdkExercise' => 'invalid'],
            ['envelopeVersion' => 4, ...$base, 'sdkExercise' => ['version' => 1, 'observations' => []]],
            ['envelopeVersion' => 7, ...$base, 'sdkExercise' => ['version' => 1, 'observations' => []]],
            // Still an unknown version. v8/v9 became known when the
            // deprecation half landed, so the unknown-version row moved up.
            ['envelopeVersion' => 10, ...$base, 'sdkExercise' => ['version' => 1, 'observations' => []]],
        ] as $payload) {
            try {
                CoverageSidecarEnvelope::parse($payload);
                $this->fail('Malformed or unknown SDK envelope shape must be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function legacy_bare_payload_rejects_a_stray_sdk_exercise_half(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sdkExercise');

        CoverageSidecarEnvelope::parse([
            'version' => 1,
            'specs' => [],
            'sdkExercise' => ['version' => 1, 'observations' => []],
        ]);
    }

    #[Test]
    public function build_with_deprecations_emits_v8_and_v9_envelopes(): void
    {
        $coverage = ['version' => 1, 'specs' => []];
        $strictRequired = ['version' => 2, 'observations' => []];
        $strictAdditional = ['version' => 1, 'evaluations' => 0, 'observations' => []];
        $sdkExercise = ['version' => 1, 'observations' => []];
        $deprecations = ['version' => 1, 'deprecations' => []];

        $plain = CoverageSidecarEnvelope::build(
            coverageState: $coverage,
            strictRequiredState: $strictRequired,
            strictAdditionalPropertiesState: $strictAdditional,
            sdkExerciseState: $sdkExercise,
            deprecationsState: $deprecations,
        );
        $baseline = CoverageSidecarEnvelope::build(
            coverageState: $coverage,
            strictRequiredState: $strictRequired,
            baselineDocument: ['baseline_version' => 1, 'violations' => []],
            strictAdditionalPropertiesState: $strictAdditional,
            sdkExerciseState: $sdkExercise,
            deprecationsState: $deprecations,
        );

        $this->assertSame(8, $plain['envelopeVersion']);
        $this->assertSame($deprecations, $plain['deprecations']);
        $this->assertArrayNotHasKey('baseline', $plain);
        $this->assertSame(9, $baseline['envelopeVersion']);
        $this->assertSame($deprecations, $baseline['deprecations']);
        $this->assertSame(['baseline_version' => 1, 'violations' => []], $baseline['baseline']);
    }

    #[Test]
    public function build_rejects_deprecations_without_the_earlier_halves(): void
    {
        // Every version implies the halves below it, so an envelope with
        // deprecation state but no SDK exercise state is one no reader can
        // represent — building it would produce a v8 whose own parse() rejects.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('require SDK exercise state');

        CoverageSidecarEnvelope::build(
            coverageState: ['version' => 1, 'specs' => []],
            strictRequiredState: ['version' => 2, 'observations' => []],
            strictAdditionalPropertiesState: ['version' => 1, 'evaluations' => 0, 'observations' => []],
            deprecationsState: ['version' => 1, 'deprecations' => []],
        );
    }

    #[Test]
    public function parse_routes_deprecations_and_returns_null_for_legacy_versions(): void
    {
        $deprecations = ['version' => 1, 'deprecations' => ['a' => ['count' => 2, 'removed_in' => '3.0']]];
        $parsed = CoverageSidecarEnvelope::parse([
            'envelopeVersion' => 8,
            'coverage' => ['version' => 1, 'specs' => []],
            'strictRequired' => ['version' => 2, 'observations' => []],
            'strictAdditionalProperties' => ['version' => 1, 'evaluations' => 0, 'observations' => []],
            'sdkExercise' => ['version' => 1, 'observations' => []],
            'deprecations' => $deprecations,
        ]);

        $this->assertSame($deprecations, $parsed['deprecations']);

        $legacy = CoverageSidecarEnvelope::parse([
            'envelopeVersion' => 6,
            'coverage' => ['version' => 1, 'specs' => []],
            'strictRequired' => ['version' => 2, 'observations' => []],
            'strictAdditionalProperties' => ['version' => 1, 'evaluations' => 0, 'observations' => []],
            'sdkExercise' => ['version' => 1, 'observations' => []],
        ]);

        // Null, not an empty map: the merge has to be able to tell "this worker
        // recorded none" from "this worker could not record any".
        $this->assertNull($legacy['deprecations']);
    }

    #[Test]
    public function parse_rejects_missing_misplaced_or_stray_deprecation_halves(): void
    {
        $base = [
            'coverage' => ['version' => 1, 'specs' => []],
            'strictRequired' => ['version' => 2, 'observations' => []],
            'strictAdditionalProperties' => ['version' => 1, 'evaluations' => 0, 'observations' => []],
            'sdkExercise' => ['version' => 1, 'observations' => []],
        ];
        $deprecations = ['version' => 1, 'deprecations' => []];

        foreach ([
            // v8 declares the half; omitting it is malformed, not "none used".
            ['envelopeVersion' => 8, ...$base],
            ['envelopeVersion' => 8, ...$base, 'deprecations' => 'invalid'],
            // v9 additionally declares the baseline half.
            ['envelopeVersion' => 9, ...$base, 'deprecations' => $deprecations],
            // A version that does not declare the half must not smuggle it.
            ['envelopeVersion' => 6, ...$base, 'deprecations' => $deprecations],
            ['envelopeVersion' => 8, ...$base, 'deprecations' => $deprecations, 'baseline' => ['baseline_version' => 1, 'violations' => []]],
        ] as $payload) {
            try {
                CoverageSidecarEnvelope::parse($payload);
                $this->fail('Malformed deprecation envelope shape must be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function legacy_bare_payload_rejects_a_stray_deprecations_half(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('deprecations');

        CoverageSidecarEnvelope::parse([
            'version' => 1,
            'specs' => [],
            'deprecations' => ['version' => 1, 'deprecations' => []],
        ]);
    }
}
