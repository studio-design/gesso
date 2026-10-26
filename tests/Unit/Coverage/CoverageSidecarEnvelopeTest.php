<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Coverage;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\CoverageSidecarEnvelope;
use Studio\Gesso\Internal\Deprecations;

use function array_key_exists;
use function array_keys;
use function get_debug_type;
use function sprintf;

/**
 * Pins the sidecar envelope wire format: the v10 writer, plus the legacy 2–9
 * and bare v1 inputs the 2.x reader still routes. The merge CLI relies on this
 * discriminator to keep working when a worker still on an older library
 * version contributes a legacy payload — and to fail loudly on shapes it
 * cannot represent.
 *
 * Every legacy input raises the #505 deprecation; the tests that are not about
 * the notice ignore it, and the dedup state is reset around each test.
 */
#[IgnoreDeprecations]
class CoverageSidecarEnvelopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Deprecations::resetForTesting();
    }

    protected function tearDown(): void
    {
        Deprecations::resetForTesting();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideBuild_emits_version_10_with_only_the_supplied_optional_keysCases(): iterable
    {
        for ($mask = 0; $mask < 16; $mask++) {
            yield sprintf('mask %04b', $mask) => [$mask];
        }
    }

    /**
     * @return iterable<string, array{string, null|string}>
     */
    public static function provideParse_rejects_version_10_with_non_array_halvesCases(): iterable
    {
        foreach (['coverage', 'strictRequired', 'strictAdditionalProperties', 'sdkExercise', 'deprecations', 'baseline'] as $key) {
            yield $key => [$key, 'invalid'];
            // v10 is presence-keyed: an explicit null is malformed, not "absent".
            yield $key . ' null' => [$key, null];
        }
    }

    #[Test]
    public function only_legacy_inputs_record_their_deprecation(): void
    {
        $coverage = ['version' => 1, 'specs' => []];

        CoverageSidecarEnvelope::parse(['envelopeVersion' => 10, 'coverage' => $coverage]);
        $this->assertSame([], Deprecations::counts());

        CoverageSidecarEnvelope::parse(['envelopeVersion' => 2, 'coverage' => $coverage, 'strictRequired' => null]);
        CoverageSidecarEnvelope::parse(['envelopeVersion' => 2, 'coverage' => $coverage, 'strictRequired' => null]);
        CoverageSidecarEnvelope::parse($coverage);

        $this->assertSame(
            ['coverage.sidecar.bare_state' => 1, 'coverage.sidecar.legacy_envelope' => 2],
            Deprecations::counts(),
        );
    }

    #[Test]
    public function a_rejected_legacy_envelope_records_no_deprecation(): void
    {
        try {
            CoverageSidecarEnvelope::parse(['envelopeVersion' => 2, 'coverage' => 'not-an-array']);
            $this->fail('expected the malformed envelope to be rejected');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame([], Deprecations::counts());
    }

    #[Test]
    #[DataProvider('provideBuild_emits_version_10_with_only_the_supplied_optional_keysCases')]
    public function build_emits_version_10_with_only_the_supplied_optional_keys(int $mask): void
    {
        // Every combination is representable now, including the ones the
        // legacy versions could not express (SDK exercise without strict
        // additional-properties, deprecations without SDK exercise).
        $halves = [
            'baseline' => ['baseline_version' => 1, 'violations' => []],
            'strictAdditionalProperties' => ['version' => 1, 'evaluations' => 0, 'observations' => []],
            'sdkExercise' => ['version' => 1, 'observations' => []],
            'deprecations' => ['version' => 1, 'deprecations' => []],
        ];
        $supplied = [];
        foreach (array_keys($halves) as $bit => $key) {
            $supplied[$key] = ($mask & (1 << $bit)) !== 0;
        }

        $envelope = CoverageSidecarEnvelope::build(
            coverageState: ['version' => 1, 'specs' => []],
            strictRequiredState: ['version' => 2, 'observations' => []],
            baselineDocument: $supplied['baseline'] ? $halves['baseline'] : null,
            strictAdditionalPropertiesState: $supplied['strictAdditionalProperties'] ? $halves['strictAdditionalProperties'] : null,
            sdkExerciseState: $supplied['sdkExercise'] ? $halves['sdkExercise'] : null,
            deprecationsState: $supplied['deprecations'] ? $halves['deprecations'] : null,
        );
        $parsed = CoverageSidecarEnvelope::parse($envelope);

        $this->assertSame(10, $envelope['envelopeVersion']);
        foreach ($halves as $key => $half) {
            $this->assertSame($supplied[$key], array_key_exists($key, $envelope), $key);
            $this->assertSame($supplied[$key] ? $half : null, $parsed[$key], $key);
        }
    }

    #[Test]
    public function build_keeps_the_optional_keys_in_writer_order(): void
    {
        $envelope = CoverageSidecarEnvelope::build(
            coverageState: ['version' => 1, 'specs' => []],
            strictRequiredState: ['version' => 2, 'observations' => []],
            baselineDocument: ['baseline_version' => 1, 'violations' => []],
            strictAdditionalPropertiesState: ['version' => 1, 'evaluations' => 0, 'observations' => []],
            sdkExerciseState: ['version' => 1, 'observations' => []],
            deprecationsState: ['version' => 1, 'deprecations' => []],
        );

        $this->assertSame(
            ['envelopeVersion', 'coverage', 'strictRequired', 'strictAdditionalProperties', 'sdkExercise', 'deprecations', 'baseline'],
            array_keys($envelope),
        );
    }

    #[Test]
    public function parse_accepts_version_10_with_only_coverage(): void
    {
        $parsed = CoverageSidecarEnvelope::parse([
            'envelopeVersion' => 10,
            'coverage' => ['version' => 1, 'specs' => []],
        ]);

        $this->assertSame(['version' => 1, 'specs' => []], $parsed['coverage']);
        foreach (['strictRequired', 'strictAdditionalProperties', 'sdkExercise', 'deprecations', 'baseline'] as $key) {
            $this->assertNull($parsed[$key], $key);
        }
    }

    #[Test]
    #[DataProvider('provideParse_rejects_version_10_with_non_array_halvesCases')]
    public function parse_rejects_version_10_with_non_array_halves(string $key, ?string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"' . $key . '" must be an array; got ' . get_debug_type($value));

        CoverageSidecarEnvelope::parse([
            'envelopeVersion' => 10,
            'coverage' => ['version' => 1, 'specs' => []],
            $key => $value,
        ]);
    }

    #[Test]
    public function parse_rejects_unknown_version_naming_the_current_one(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('expected 10');

        CoverageSidecarEnvelope::parse([
            'envelopeVersion' => 11,
            'coverage' => ['version' => 1, 'specs' => []],
        ]);
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
    public function parse_keeps_accepting_an_explicit_null_strict_required_in_v2(): void
    {
        $parsed = CoverageSidecarEnvelope::parse([
            'envelopeVersion' => 2,
            'coverage' => ['version' => 1, 'specs' => []],
            'strictRequired' => null,
        ]);

        $this->assertNull($parsed['strictRequired']);
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
            // Still an unknown version. v10 became the current writer when
            // the envelope switched to presence-keyed halves (#505).
            ['envelopeVersion' => 11, ...$base, 'sdkExercise' => ['version' => 1, 'observations' => []]],
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
