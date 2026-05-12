<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\PHPUnit;

use const FILE_APPEND;
use const STDERR;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use RuntimeException;
use Studio\OpenApiContractTesting\Coverage\ConsoleCoverageRenderer;
use Studio\OpenApiContractTesting\Coverage\CoverageMergeCommand;
use Studio\OpenApiContractTesting\Coverage\CoverageSidecarWriter;
use Studio\OpenApiContractTesting\Coverage\CoverageThresholdEvaluator;
use Studio\OpenApiContractTesting\Coverage\HtmlCoverageRenderer;
use Studio\OpenApiContractTesting\Coverage\JsonCoverageRenderer;
use Studio\OpenApiContractTesting\Coverage\JUnitCoverageRenderer;
use Studio\OpenApiContractTesting\Coverage\MarkdownCoverageRenderer;
use Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\Exception\InvalidOpenApiSpecException;
use Studio\OpenApiContractTesting\Exception\SpecFileNotFoundException;
use Studio\OpenApiContractTesting\Internal\PartialRunDecision;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;
use Throwable;

use function fflush;
use function file_put_contents;
use function getenv;
use function implode;
use function is_callable;
use function sprintf;
use function strlen;
use function trim;

/**
 * @phpstan-import-type CoverageResult from OpenApiCoverageTracker
 * @phpstan-import-type CoverageReportEntry from OpenApiCoverageTracker
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final readonly class CoverageReportSubscriber implements ExecutionFinishedSubscriber
{
    /**
     * @param string[] $specs
     * @param null|callable(string): void $stderrWriter Optional sink for warnings (stale/invalid specs,
     *                                                  failed file_put_contents). Falls back to {@see OpenApiCoverageExtension::writeStderr()} when
     *                                                  null. Injected for testability — the extension stays the default backstop in production.
     * @param null|string $sidecarDir Directory the worker-mode branch will drop its JSON sidecar into. When the
     *                                subscriber detects `TEST_TOKEN` (set by paratest in every child process) it
     *                                short-circuits rendering and writes the tracker state here for the merge CLI
     *                                to pick up. `null` falls back to a default under `sys_get_temp_dir()`.
     * @param null|float $minEndpointCoverage Optional gate: when not null and `endpointFullyCovered/endpointTotal`
     *                                        (rolled across `$specs`) is below this percent, the subscriber prints
     *                                        a FAIL/WARN line. See issue #135.
     * @param null|float $minResponseCoverage Same idea, but at `(method, path, status, content-type)` granularity.
     * @param bool $minCoverageStrict Treat threshold misses as exit non-zero (default warn-only).
     * @param null|callable(int): void $exitHandler Test seam for the strict-miss exit. Defaults to native `exit()`
     *                                              so production behavior matches PHPUnit's own coverage gate.
     * @param null|PartialRunDecision $partialRun Issue #221: when non-null (the run is partial), the subscriber
     *                                            skips every persistent file write (output_file, junit_output,
     *                                            json_output, html_output, GITHUB_STEP_SUMMARY) and emits one
     *                                            stderr WARNING listing the skipped targets. Console rendering
     *                                            and the threshold gate are unaffected — they read in-memory
     *                                            state and don't risk overwriting a committed doc with subset
     *                                            data. `null` (the backwards-compat default) means full-run
     *                                            behavior.
     */
    public function __construct(
        private array $specs,
        private ?string $outputFile,
        private ConsoleOutput $consoleOutput,
        private ?string $githubSummaryPath,
        private mixed $stderrWriter = null,
        private ?string $sidecarDir = null,
        private ?float $minEndpointCoverage = null,
        private ?float $minResponseCoverage = null,
        private bool $minCoverageStrict = false,
        private mixed $exitHandler = null,
        private ?string $junitOutput = null,
        private ?string $jsonOutput = null,
        private ?string $htmlOutput = null,
        private ?PartialRunDecision $partialRun = null,
    ) {}

    /** @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter */
    public function notify(ExecutionFinished $event): void
    {
        $workerToken = self::resolveWorkerToken();
        if ($workerToken !== null) {
            $this->writeWorkerSidecar($workerToken);

            // Free cached spec data; the merge CLI re-loads on its own.
            OpenApiSpecLoader::clearCache();

            return;
        }

        $results = $this->computeAllResults();

        // Free cached spec data now that coverage has been computed
        OpenApiSpecLoader::clearCache();

        if ($results === []) {
            // C2: a strict CI gate must not silently pass when zero contract
            // assertions ran. Pre-fix, this branch quietly returned 0 even
            // though the user had opted into fail-fast via min_*_coverage.
            $this->failOnEmptyResultsIfGated();

            return;
        }

        echo ConsoleCoverageRenderer::render($results, $this->consoleOutput);

        $this->writeReports($results);

        $this->evaluateThresholdGate($results);
    }

    /**
     * Resolve the paratest worker token from the environment. Paratest sets
     * `TEST_TOKEN` for every worker process (currently a 1..N slot index)
     * and unsets it for sequential PHPUnit runs. We treat the presence of
     * this var as the single signal that puts the subscriber into
     * sidecar-only mode.
     *
     * Parallel runners that wrap paratest (e.g. Pest `--parallel`) inherit
     * the same env var, so no per-runner detection is needed.
     */
    private static function resolveWorkerToken(): ?string
    {
        $token = getenv('TEST_TOKEN');
        if ($token === false || trim($token) === '') {
            return null;
        }

        return $token;
    }

    /**
     * Issue #135: in sequential PHPUnit, evaluate the optional coverage
     * threshold after the report renders. Worker mode never reaches here
     * (the worker-token branch returns earlier) — the merge CLI is the gate
     * for paratest, so this method runs only on the in-process path.
     *
     * @param array<string, CoverageResult> $results
     */
    private function evaluateThresholdGate(array $results): void
    {
        if ($this->minEndpointCoverage === null && $this->minResponseCoverage === null) {
            return;
        }

        $evaluation = CoverageThresholdEvaluator::evaluate(
            $results,
            $this->minEndpointCoverage,
            $this->minResponseCoverage,
            $this->minCoverageStrict,
        );

        if ($evaluation['passed']) {
            return;
        }

        $this->writeStderr($evaluation['message']);

        if (!$this->minCoverageStrict) {
            return;
        }

        // Mirror OpenApiCoverageExtension::bootstrap()'s fail-fast pattern:
        // PHPUnit's own exit code path doesn't propagate subscriber failures,
        // so a strict threshold miss has to terminate the process directly to
        // be visible to CI.
        $exit = $this->exitHandler;
        if ($this->stderrWriter === null) {
            fflush(STDERR);
        }

        if (is_callable($exit)) {
            $exit(1);

            return;
        }

        exit(1);
    }

    /**
     * Issue #135 review C2: when no spec produced any coverage, the
     * regular gate path never runs (the evaluator would receive an empty
     * results array and report 100% vacuously). A strict run must still
     * fail-fast — otherwise a CI that opted into the gate silently passes
     * when its tests didn't actually validate anything.
     */
    private function failOnEmptyResultsIfGated(): void
    {
        if ($this->minEndpointCoverage === null && $this->minResponseCoverage === null) {
            return;
        }

        $severity = $this->minCoverageStrict ? 'FATAL' : 'WARNING';
        $this->writeStderr(sprintf(
            "[OpenAPI Coverage] %s: no contract test coverage was recorded; configured threshold cannot be evaluated.\n",
            $severity,
        ));

        if (!$this->minCoverageStrict) {
            return;
        }

        $exit = $this->exitHandler;
        if ($this->stderrWriter === null) {
            fflush(STDERR);
        }

        if (is_callable($exit)) {
            $exit(1);

            return;
        }

        exit(1);
    }

    private function writeWorkerSidecar(string $token): void
    {
        $dir = $this->sidecarDir ?? OpenApiCoverageExtension::defaultSidecarDir();

        try {
            CoverageSidecarWriter::write($dir, $token, OpenApiCoverageTracker::exportState());
        } catch (RuntimeException $e) {
            // The contract assertion that triggered notify() has already
            // passed; we don't fail the test run on sidecar I/O. But we
            // MUST drop a failure marker so the downstream merge CLI can
            // detect this worker is missing and exit non-zero. Without the
            // marker the merge would silently under-count coverage by one
            // worker's worth of data.
            $this->writeStderr("[OpenAPI Coverage] WARNING: failed to write sidecar (token={$token}): {$e->getMessage()}\n");
            CoverageSidecarWriter::writeFailureMarker($dir, $token, $e->getMessage());
        }
    }

    private function writeStderr(string $message): void
    {
        $writer = $this->stderrWriter;
        if (is_callable($writer)) {
            $writer($message);

            return;
        }

        OpenApiCoverageExtension::writeStderr($message);
    }

    /**
     * @return array<string, CoverageResult>
     */
    private function computeAllResults(): array
    {
        $hasCoverage = false;
        foreach ($this->specs as $spec) {
            if (OpenApiCoverageTracker::hasAnyCoverage($spec)) {
                $hasCoverage = true;

                break;
            }
        }

        if (!$hasCoverage) {
            return [];
        }

        $results = [];

        foreach ($this->specs as $spec) {
            try {
                $results[$spec] = OpenApiCoverageTracker::computeCoverage($spec);
            } catch (SpecFileNotFoundException $e) {
                // Unlike bootstrap (which hard-fails missing files since
                // issue #134), the subscriber runs after tests finished —
                // so we tolerate a mid-run unlink and let partial coverage
                // reports still render rather than discarding the run's
                // observations.
                $this->writeStderr("[OpenAPI Coverage] WARNING: Skipping spec '{$spec}': {$e->getMessage()}\n");

                continue;
            } catch (InvalidOpenApiSpecException $e) {
                // Defensive: only reachable if OpenApiSpecLoader::evict()
                // was called mid-run and the on-disk spec was edited
                // between bootstrap and ExecutionFinished. Preserves
                // the hard-fail contract in that edge case.
                $this->writeStderr("[OpenAPI Coverage] FATAL: Invalid OpenAPI spec '{$spec}': {$e->getMessage()}\n");

                throw $e;
            }
        }

        return $results;
    }

    /**
     * Dispatch each configured renderer to its output target. Per-entry render
     * or write failures emit a WARNING and continue — one format's broken path
     * must not suppress the others or block the threshold gate that runs after
     * this.
     *
     * GITHUB_STEP_SUMMARY is Markdown-only by design and handled separately.
     *
     * @param array<string, CoverageResult> $results
     */
    private function writeReports(array $results): void
    {
        if ($this->partialRun !== null) {
            $this->emitPartialRunSkipWarning($this->partialRun);

            // Skip every persistent artifact — output_file, junit_output,
            // json_output, html_output, AND the GITHUB_STEP_SUMMARY append
            // below — to honour issue #221: a partial run must never
            // mutate a committed coverage doc or a CI summary that
            // outlives the terminal session.
            return;
        }

        foreach ($this->buildReportEntries() as $entry) {
            if ($entry['outputFile'] === null) {
                continue;
            }

            try {
                $rendered = ($entry['renderer'])($results);
            } catch (Throwable $e) {
                $this->writeStderr(sprintf(
                    "[OpenAPI Coverage] WARNING: Failed to render %s report: %s\n",
                    $entry['label'],
                    $e->getMessage(),
                ));

                continue;
            }

            // Suppress PHP warning on failure — we surface the error via the
            // WARNING stderr line below, and the raw PHP warning is redundant
            // noise that breaks `beStrictAboutOutputDuringTests` test runs.
            // Mirrors the CLI dispatch loop's @ suppression.
            $bytes = @file_put_contents($entry['outputFile'], $rendered);
            if ($bytes === false) {
                $this->writeStderr(sprintf(
                    "[OpenAPI Coverage] WARNING: Failed to write %s report to %s\n",
                    $entry['label'],
                    $entry['outputFile'],
                ));

                continue;
            }

            $expected = strlen($rendered);
            if ($bytes !== $expected) {
                // Partial write — disk full / quota exceeded mid-write leaves
                // a truncated file. Surface explicitly so consumers don't
                // parse half a document several CI steps later.
                $this->writeStderr(sprintf(
                    "[OpenAPI Coverage] WARNING: Truncated %s report at %s (%d of %d bytes written)\n",
                    $entry['label'],
                    $entry['outputFile'],
                    $bytes,
                    $expected,
                ));
            }
        }

        $this->appendGithubStepSummary($results);
    }

    /**
     * Renderer dispatch table. Adding a new format here does not require
     * changes to the loop in {@see self::writeReports()}. The merge CLI keeps
     * a parallel table in {@see CoverageMergeCommand}, so any new format must
     * be added to both in lockstep — note the severity asymmetry (subscriber
     * warns; CLI counts failures toward exit code).
     *
     * @return list<CoverageReportEntry>
     */
    private function buildReportEntries(): array
    {
        return [
            [
                'label' => 'Markdown',
                'renderer' => static fn(array $r): string => MarkdownCoverageRenderer::render($r),
                'outputFile' => $this->outputFile,
            ],
            [
                'label' => 'JUnit XML',
                'renderer' => static fn(array $r): string => JUnitCoverageRenderer::render($r),
                'outputFile' => $this->junitOutput,
            ],
            [
                'label' => 'JSON',
                'renderer' => static fn(array $r): string => JsonCoverageRenderer::render($r),
                'outputFile' => $this->jsonOutput,
            ],
            [
                'label' => 'HTML',
                'renderer' => static fn(array $r): string => HtmlCoverageRenderer::render($r),
                'outputFile' => $this->htmlOutput,
            ],
        ];
    }

    /**
     * Issue #221: emit a single stderr WARNING enumerating the persistent
     * artifacts we are choosing not to write because PHPUnit is running a
     * subset of the suite. Silent when no persistent target is configured
     * (a `--filter` run without `output_file` etc. has nothing to skip and
     * a WARNING would be noise).
     */
    private function emitPartialRunSkipWarning(PartialRunDecision $decision): void
    {
        $targets = [];
        if ($this->outputFile !== null) {
            $targets[] = 'output_file';
        }
        if ($this->junitOutput !== null) {
            $targets[] = 'junit_output';
        }
        if ($this->jsonOutput !== null) {
            $targets[] = 'json_output';
        }
        if ($this->htmlOutput !== null) {
            $targets[] = 'html_output';
        }
        if ($this->githubSummaryPath !== null) {
            $targets[] = 'GITHUB_STEP_SUMMARY';
        }

        if ($targets === []) {
            return;
        }

        $this->writeStderr(sprintf(
            "[OpenAPI Coverage] WARNING: Skipping %s write because PHPUnit is running a partial subset (%s). Coverage reports are not written on partial runs to avoid overwriting persistent docs with subset data. Re-run the full suite to refresh.\n",
            implode(', ', $targets),
            $decision->reason,
        ));
    }

    /**
     * GITHUB_STEP_SUMMARY is Markdown-only by design — the file is a single
     * shared sink that GitHub consumes as Markdown, so additional output
     * formats do not get appended here. Failures emit a WARNING and do not
     * affect any exit code (the subscriber has no exit gate of its own for
     * this path).
     *
     * @param array<string, CoverageResult> $results
     */
    private function appendGithubStepSummary(array $results): void
    {
        if ($this->githubSummaryPath === null) {
            return;
        }

        $markdown = MarkdownCoverageRenderer::render($results);
        // Same @ rationale as writeReports().
        $written = @file_put_contents($this->githubSummaryPath, $markdown . "\n", FILE_APPEND);

        if ($written === false) {
            $this->writeStderr("[OpenAPI Coverage] WARNING: Failed to append Markdown report to GITHUB_STEP_SUMMARY ({$this->githubSummaryPath})\n");
        }
    }
}
