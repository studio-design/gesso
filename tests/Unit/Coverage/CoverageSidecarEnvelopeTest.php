<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Coverage;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\CoverageSidecarEnvelope;

use function array_key_exists;
use function array_keys;
use function get_debug_type;
use function sprintf;

/**
 * Pins the sidecar envelope wire format: the v10 writer, and the v3 reader
 * that accepts envelope 10 only (#505). The merge CLI relies on this to fail
 * loudly on a sidecar written by another Gesso version instead of guessing.
 */
class CoverageSidecarEnvelopeTest extends TestCase
{
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

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideParse_rejects_every_payload_but_envelope_10Cases(): iterable
    {
        $coverage = ['version' => 1, 'specs' => []];
        // The legacy envelopes the 2.x reader accepted, written by Gesso 1.9-2.6.
        for ($version = 2; $version <= 9; $version++) {
            yield 'legacy envelope ' . $version => [
                ['envelopeVersion' => $version, 'coverage' => $coverage, 'strictRequired' => ['version' => 2, 'observations' => []]],
                'Sidecar envelope version ' . $version . ' was written by a Gesso 1.9-2.6 worker;',
            ];
        }

        yield 'bare v1 coverage state' => [$coverage, 'This sidecar is a bare coverage state with no envelopeVersion, written by a Gesso 1.x worker;'];
        yield 'newer envelope' => [['envelopeVersion' => 11, 'coverage' => $coverage], 'Sidecar envelope version 11 was written by a newer Gesso worker;'];
        yield 'string envelope version' => [['envelopeVersion' => '10', 'coverage' => $coverage], 'Unsupported sidecar envelopeVersion of type string;'];
        yield 'unrecognised shape' => [['foo' => 'bar'], 'Unrecognised sidecar payload with no envelopeVersion;'];
        yield 'envelope 1' => [['envelopeVersion' => 1, 'coverage' => $coverage], 'Unsupported sidecar envelope version 1;'];
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

    /** @param array<string, mixed> $payload */
    #[Test]
    #[DataProvider('provideParse_rejects_every_payload_but_envelope_10Cases')]
    public function parse_rejects_every_payload_but_envelope_10(array $payload, string $found): void
    {
        try {
            CoverageSidecarEnvelope::parse($payload);
            $this->fail('expected the payload to be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($found, $e->getMessage());
            $this->assertStringContainsString('Gesso 3 reads envelopeVersion 10 only.', $e->getMessage());
            $this->assertStringContainsString('install the same version in both', $e->getMessage());
            $this->assertStringContainsString('Old sidecars cannot be converted.', $e->getMessage());
        }
    }
}
