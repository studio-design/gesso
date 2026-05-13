<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Coverage;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Coverage\CoverageSidecarEnvelope;

/**
 * Pins the v2 sidecar envelope wire format and the v1 ↔ v2 compatibility
 * routing. The merge CLI relies on this discriminator to keep working when
 * a worker still on an older library version contributes a bare v1 payload.
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
}
