<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Coverage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\EndpointCoverageState;
use Studio\Gesso\Coverage\MarkdownCoverageRenderer;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Coverage\ResponseCoverageState;
use Studio\Gesso\Coverage\SdkExerciseCoverageReportBuilder;
use Studio\Gesso\Tests\Helpers\SdkExerciseCoverageResultFixture;

use function dirname;
use function escapeshellarg;
use function exec;
use function explode;
use function file_exists;
use function file_put_contents;
use function implode;
use function preg_match;
use function sprintf;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * @phpstan-import-type CoverageResult from OpenApiCoverageTracker
 * @phpstan-import-type EndpointSummary from OpenApiCoverageTracker
 * @phpstan-import-type SdkExerciseCoverageResult from SdkExerciseCoverageReportBuilder
 */
final class MarkdownCoverageRendererLintTest extends TestCase
{
    #[Test]
    public function standard_coverage_rendered_markdown_passes_markdownlint(): void
    {
        $this->assertRenderedMarkdownPassesLint(self::standardFixture());
    }

    #[Test]
    public function edge_cases_rendered_markdown_passes_markdownlint(): void
    {
        $this->assertRenderedMarkdownPassesLint(self::edgeCasesFixture());
    }

    #[Test]
    public function sdk_exercise_rendered_markdown_passes_markdownlint(): void
    {
        $this->assertRenderedMarkdownPassesLint(
            [],
            ['front' => SdkExerciseCoverageResultFixture::result()],
        );
    }

    /**
     * @return array<string, CoverageResult>
     */
    private static function standardFixture(): array
    {
        return [
            'front' => [
                'endpoints' => [
                    self::endpoint(
                        'GET /v1/pets',
                        EndpointCoverageState::AllCovered,
                        operationId: 'listPets',
                        responses: [
                            self::row('200', 'application/json', ResponseCoverageState::Validated, hits: 3),
                        ],
                        coveredResponseCount: 1,
                        totalResponseCount: 1,
                    ),
                    self::endpoint(
                        'POST /v1/pets',
                        EndpointCoverageState::Partial,
                        responses: [
                            self::row('201', 'application/json', ResponseCoverageState::Validated, hits: 1),
                            self::row('422', 'application/problem+json', ResponseCoverageState::Uncovered),
                        ],
                        coveredResponseCount: 1,
                        totalResponseCount: 2,
                    ),
                    self::endpoint(
                        'GET /v1/health',
                        EndpointCoverageState::Uncovered,
                        responses: [
                            self::row('200', 'application/json', ResponseCoverageState::Uncovered),
                        ],
                        totalResponseCount: 1,
                    ),
                ],
                'endpointTotal' => 3,
                'endpointFullyCovered' => 1,
                'endpointPartial' => 1,
                'endpointUncovered' => 1,
                'endpointRequestOnly' => 0,
                'responseTotal' => 4,
                'responseCovered' => 2,
                'responseSkipped' => 0,
                'responseUncovered' => 2,
            ],
        ];
    }

    /**
     * @return array<string, CoverageResult>
     */
    private static function edgeCasesFixture(): array
    {
        return [
            'admin' => [
                'endpoints' => [
                    self::endpoint(
                        'GET /v1/loose',
                        EndpointCoverageState::RequestOnly,
                        requestReached: true,
                    ),
                    self::endpoint(
                        'PATCH /v1/admin/{id}',
                        EndpointCoverageState::Uncovered,
                        requestReached: false,
                    ),
                    self::endpoint(
                        'DELETE /v1/pets/{petId}',
                        EndpointCoverageState::Partial,
                        responses: [
                            self::row('204', '*', ResponseCoverageState::Validated, hits: 2),
                            self::row('5XX', '*', ResponseCoverageState::Skipped, hits: 1, skipReason: 'status 503 matched skip pattern 5\d\d'),
                        ],
                        coveredResponseCount: 1,
                        skippedResponseCount: 1,
                        totalResponseCount: 2,
                        unexpectedObservations: [
                            ['statusKey' => '418', 'contentTypeKey' => 'application/json'],
                        ],
                    ),
                ],
                'endpointTotal' => 3,
                'endpointFullyCovered' => 0,
                'endpointPartial' => 1,
                'endpointUncovered' => 1,
                'endpointRequestOnly' => 1,
                'responseTotal' => 2,
                'responseCovered' => 1,
                'responseSkipped' => 1,
                'responseUncovered' => 0,
            ],
        ];
    }

    /**
     * @param list<array{statusKey: string, contentTypeKey: string, state: ResponseCoverageState, hits: int, skipReason: ?string}> $responses
     * @param list<array{statusKey: string, contentTypeKey: string}> $unexpectedObservations
     *
     * @return EndpointSummary
     */
    private static function endpoint(
        string $endpoint,
        EndpointCoverageState $state,
        bool $requestReached = false,
        ?string $operationId = null,
        array $responses = [],
        int $coveredResponseCount = 0,
        int $skippedResponseCount = 0,
        int $totalResponseCount = 0,
        array $unexpectedObservations = [],
    ): array {
        [$method, $path] = explode(' ', $endpoint, 2);

        return [
            'endpoint' => $endpoint,
            'method' => $method,
            'path' => $path,
            'operationId' => $operationId,
            'state' => $state,
            'requestReached' => $requestReached,
            'responses' => $responses,
            'coveredResponseCount' => $coveredResponseCount,
            'skippedResponseCount' => $skippedResponseCount,
            'totalResponseCount' => $totalResponseCount,
            'unexpectedObservations' => $unexpectedObservations,
        ];
    }

    /**
     * @return array{statusKey: string, contentTypeKey: string, state: ResponseCoverageState, hits: int, skipReason: ?string}
     */
    private static function row(
        string $statusKey,
        string $contentTypeKey,
        ResponseCoverageState $state,
        int $hits = 0,
        ?string $skipReason = null,
    ): array {
        return [
            'statusKey' => $statusKey,
            'contentTypeKey' => $contentTypeKey,
            'state' => $state,
            'hits' => $hits,
            'skipReason' => $skipReason,
        ];
    }

    /**
     * @param array<string, CoverageResult> $results
     * @param array<string, SdkExerciseCoverageResult> $sdkResults
     */
    private function assertRenderedMarkdownPassesLint(array $results, array $sdkResults = []): void
    {
        $probeOutput = [];
        $probeExit = 1;
        exec('npx --version 2>/dev/null', $probeOutput, $probeExit);
        if ($probeExit !== 0 || !preg_match('/^\d+\.\d+/', $probeOutput[0] ?? '')) {
            $this->markTestSkipped('npx is not available; install Node.js to run this test');
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'mdlint_');
        if ($tmpFile === false) {
            $this->fail('tempnam() failed to create a temp file');
        }
        $output = MarkdownCoverageRenderer::render($results, $sdkResults);
        if (file_put_contents($tmpFile, $output) === false) {
            unlink($tmpFile);
            $this->fail('file_put_contents() failed to write the rendered markdown');
        }
        $configPath = dirname(__DIR__, 3) . '/.markdownlint.jsonc';

        try {
            $cmd = sprintf(
                'npx --yes markdownlint-cli2@0.22 --config %s %s 2>&1',
                escapeshellarg($configPath),
                escapeshellarg($tmpFile),
            );
            $stdout = [];
            $exitCode = 1;
            exec($cmd, $stdout, $exitCode);

            $this->assertSame(
                0,
                $exitCode,
                "markdownlint-cli2 failed with exit code {$exitCode}:\n" . implode("\n", $stdout),
            );
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }
}
