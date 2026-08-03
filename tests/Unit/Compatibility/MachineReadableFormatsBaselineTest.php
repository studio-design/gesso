<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Compatibility;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\CoverageSidecarEnvelope;
use Studio\Gesso\Coverage\EndpointCoverageState;
use Studio\Gesso\Coverage\JsonCoverageRenderer;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Coverage\ResponseCoverageState;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;

use function dirname;
use function file_get_contents;
use function json_decode;
use function json_encode;

/**
 * Consumer-style golden tests for the versioned payloads that must remain
 * readable while v1 workers and v2 merge/report tooling overlap.
 *
 * @phpstan-import-type CoverageResult from OpenApiCoverageTracker
 */
final class MachineReadableFormatsBaselineTest extends TestCase
{
    #[Test]
    public function coverage_json_matches_the_v3_fixture(): void
    {
        /** @var array<string, CoverageResult> $results */
        $results = [
            'front' => [
                'endpoints' => [
                    [
                        'endpoint' => 'GET /v1/pets',
                        'method' => 'GET',
                        'path' => '/v1/pets',
                        'operationId' => 'listPets',
                        'state' => EndpointCoverageState::Partial,
                        'requestReached' => true,
                        'responses' => [
                            [
                                'statusKey' => '200',
                                'contentTypeKey' => 'application/json',
                                'state' => ResponseCoverageState::Validated,
                                'hits' => 2,
                                'skipReason' => null,
                            ],
                            [
                                'statusKey' => '503',
                                'contentTypeKey' => '*',
                                'state' => ResponseCoverageState::Skipped,
                                'hits' => 1,
                                'skipReason' => 'status 503 matched skip pattern 5\\d\\d',
                            ],
                        ],
                        'coveredResponseCount' => 1,
                        'skippedResponseCount' => 1,
                        'totalResponseCount' => 2,
                        'unexpectedObservations' => [
                            ['statusKey' => '418', 'contentTypeKey' => 'application/json'],
                        ],
                    ],
                    [
                        'endpoint' => 'POST /v1/pets',
                        'method' => 'POST',
                        'path' => '/v1/pets',
                        'operationId' => null,
                        'state' => EndpointCoverageState::RequestOnly,
                        'requestReached' => true,
                        'responses' => [
                            [
                                'statusKey' => '201',
                                'contentTypeKey' => 'application/json',
                                'state' => ResponseCoverageState::Uncovered,
                                'hits' => 0,
                                'skipReason' => null,
                            ],
                        ],
                        'coveredResponseCount' => 0,
                        'skippedResponseCount' => 0,
                        'totalResponseCount' => 1,
                        'unexpectedObservations' => [],
                    ],
                ],
                'endpointTotal' => 2,
                'endpointFullyCovered' => 0,
                'endpointPartial' => 1,
                'endpointUncovered' => 0,
                'endpointRequestOnly' => 1,
                'responseTotal' => 3,
                'responseCovered' => 1,
                'responseSkipped' => 1,
                'responseUncovered' => 1,
            ],
        ];

        $rendered = JsonCoverageRenderer::render(
            $results,
            new DateTimeImmutable('2026-07-14T00:00:00+00:00'),
        );
        /** @var array<string, mixed> $payload */
        $payload = json_decode($rendered, true, flags: JSON_THROW_ON_ERROR);
        /** @var array{name: string, version: string} $tool */
        $tool = $payload['tool'];
        $tool['version'] = '<runtime-version>';
        $payload['tool'] = $tool;

        $this->assertSame(
            $this->fixture('v3-coverage-report.json'),
            json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ) . "\n",
        );
    }

    #[Test]
    public function sidecar_envelope_matches_the_v1_9_fixture(): void
    {
        $envelope = $this->buildSidecarEnvelope();

        $this->assertSame(
            $this->fixture('v1.9-coverage-sidecar-envelope.json'),
            json_encode(
                $envelope,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ) . "\n",
        );
    }

    #[Test]
    public function v1_9_sidecar_fixture_is_consumable_by_the_reader_and_trackers(): void
    {
        /** @var array<string, mixed> $fixture */
        $fixture = json_decode(
            $this->fixture('v1.9-coverage-sidecar-envelope.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $parsed = CoverageSidecarEnvelope::parse($fixture);

        $coverage = new OpenApiCoverageTracker();
        $coverage->importStateOn($parsed['coverage']);
        $this->assertSame($fixture['coverage'], $coverage->exportStateOn());

        $this->assertIsArray($parsed['strictRequired']);
        $strictRequired = new StrictRequiredTracker();
        $strictRequired->importStateOn($parsed['strictRequired']);
        $this->assertSame($fixture['strictRequired'], $strictRequired->exportStateOn());
    }

    #[Test]
    public function embedded_coverage_state_remains_accepted_as_a_legacy_bare_sidecar(): void
    {
        /** @var array{coverage: array<string, mixed>} $fixture */
        $fixture = json_decode(
            $this->fixture('v1.9-coverage-sidecar-envelope.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $parsed = CoverageSidecarEnvelope::parse($fixture['coverage']);

        $this->assertSame($fixture['coverage'], $parsed['coverage']);
        $this->assertNull($parsed['strictRequired']);
    }

    /** @return array<string, mixed> */
    private function buildSidecarEnvelope(): array
    {
        $coverage = new OpenApiCoverageTracker();
        $coverage->recordRequestOn('front', 'GET', '/v1/pets');
        $coverage->recordResponseOn(
            'front',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        $coverage->recordResponseOn(
            'front',
            'GET',
            '/v1/pets',
            '503',
            null,
            schemaValidated: false,
            skipReason: 'status 503 matched skip pattern 5\\d\\d',
        );

        $strictRequired = new StrictRequiredTracker();
        $strictRequired->recordOn('front', 'GET', '/v1/pets', '200', 'application/json', [
            '/' => ['data', 'meta'],
            '/data' => ['id', 'name'],
        ]);
        $strictRequired->recordOn('front', 'GET', '/v1/pets', '200', 'application/json', [
            '/' => ['data'],
            '/data' => ['extra', 'id', 'name'],
        ]);

        return CoverageSidecarEnvelope::build(
            $coverage->exportStateOn(),
            $strictRequired->exportStateOn(),
        );
    }

    private function fixture(string $name): string
    {
        $path = dirname(__DIR__, 2) . '/fixtures/compatibility/' . $name;
        $contents = file_get_contents($path);

        $this->assertIsString($contents, "Unable to read {$path}");

        return $contents;
    }
}
