<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Fuzz;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Fuzz\GeneratedResponseCase;
use Studio\Gesso\Fuzz\OpenApiResponseExplorer;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use TypeError;

use function is_array;
use function is_string;
use function json_decode;
use function json_encode;

class SdkRoundTripIncidentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function branch_complete_payloads_reach_a_throwing_primitive_one_of_decoder(): void
    {
        $cases = OpenApiResponseExplorer::explore(
            'sdk-roundtrip',
            'POST',
            '/oauth/introspect',
            200,
            seed: 1,
        );

        $this->expectException(TypeError::class);
        $this->expectExceptionMessage('primitive string aud');

        $cases->each(static function (GeneratedResponseCase $case): void {
            IncidentThrowingSdk::decode($case->bodyAsObject());
        });
    }

    #[Test]
    public function round_trip_rejects_a_silently_value_swallowing_decoder(): void
    {
        $cases = OpenApiResponseExplorer::explore(
            'sdk-roundtrip',
            'POST',
            '/oauth/introspect',
            200,
            seed: 1,
        );

        try {
            $cases->each(static function (GeneratedResponseCase $case): void {
                $decoded = IncidentSwallowingSdk::decode($case->bodyAsObject());
                $case->assertRoundTrip(IncidentSwallowingSdk::encode($decoded));
            });
            $this->fail('Expected the silently swallowed aud value to fail round-trip fidelity.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString("missing generated key 'aud'", $e->getMessage());
            $this->assertStringContainsString('seed=1', $e->getMessage());
            $this->assertStringContainsString('pinned=/properties/aud', $e->getMessage());
        }
    }
}

/** @internal Test-only generated SDK stand-in for studio-auth#1520. */
final class IncidentThrowingSdk
{
    /** @return array<string, mixed> */
    public static function decode(mixed $payload): array
    {
        $decoded = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new TypeError('Expected an object payload.');
        }
        if (isset($decoded['aud']) && is_string($decoded['aud'])) {
            throw new TypeError('Generated SDK cannot decode primitive string aud.');
        }

        return $decoded;
    }
}

/** @internal Test-only generated SDK stand-in that silently drops aud. */
final class IncidentSwallowingSdk
{
    /** @return array<string, mixed> */
    public static function decode(mixed $payload): array
    {
        $decoded = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new TypeError('Expected an object payload.');
        }

        unset($decoded['aud']);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $model
     *
     * @return array<string, mixed>
     */
    public static function encode(array $model): array
    {
        return $model;
    }
}
