<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use const FILE_APPEND;

use Closure;
use Studio\Gesso\Coverage\HtmlCoverageRenderer;
use Studio\Gesso\Coverage\JsonCoverageRenderer;
use Studio\Gesso\Coverage\JUnitCoverageRenderer;
use Studio\Gesso\Coverage\MarkdownCoverageRenderer;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Coverage\SdkExerciseCoverageReportBuilder;
use Studio\Gesso\Coverage\SdkExerciseCoverageTracker;
use Studio\Gesso\Exception\InvalidOpenApiSpecException;
use Studio\Gesso\Exception\SpecFileNotFoundException;
use Throwable;

use function file_put_contents;
use function sprintf;
use function strlen;

/**
 * Coverage computation and report dispatch shared by the in-process PHPUnit
 * subscriber and the `gesso coverage:merge` CLI. The two differ only in how
 * a failed write is graded: the subscriber warns, the CLI counts the failure
 * toward its exit code — hence `$severity` and the returned failure count.
 *
 * @internal Shared implementation of the subscriber and merge command.
 *
 * @phpstan-import-type CoverageResult from OpenApiCoverageTracker
 * @phpstan-import-type SdkExerciseCoverageResult from SdkExerciseCoverageReportBuilder
 */
final class CoverageReportWriter
{
    /**
     * @param Closure(string): void $stderr
     * @param 'FATAL'|'WARNING' $severity
     */
    public function __construct(
        private readonly Closure $stderr,
        private readonly string $severity,
    ) {}

    /**
     * Dispatch each configured renderer to its output target. Per-entry render
     * or write failures emit one diagnostic line and continue — one format's
     * broken path must not suppress the others or block the threshold gate
     * that runs after this. GITHUB_STEP_SUMMARY is Markdown-only by design
     * and handled by {@see self::appendGithubStepSummary()}.
     *
     * @param array<string, CoverageResult> $results
     * @param array<string, SdkExerciseCoverageResult> $sdkResults
     *
     * @return int Number of format outputs that failed to write
     */
    public function writeReports(
        array $results,
        array $sdkResults,
        ?string $outputFile,
        ?string $junitOutput,
        ?string $jsonOutput,
        ?string $htmlOutput,
    ): int {
        $entries = [
            ['Markdown', static fn(array $r, array $s): string => MarkdownCoverageRenderer::render($r, $s), $outputFile],
            ['JUnit XML', static fn(array $r, array $s): string => JUnitCoverageRenderer::render($r, $s), $junitOutput],
            ['JSON', static fn(array $r, array $s): string => JsonCoverageRenderer::render($r, sdkResults: $s), $jsonOutput],
            ['HTML', static fn(array $r, array $s): string => HtmlCoverageRenderer::render($r, $s), $htmlOutput],
        ];
        $failures = 0;

        foreach ($entries as [$label, $renderer, $target]) {
            if ($target === null) {
                continue;
            }

            try {
                $rendered = $renderer($results, $sdkResults);
            } catch (Throwable $e) {
                $this->diagnostic(sprintf('Failed to render %s report: %s', $label, $e->getMessage()));
                $failures++;

                continue;
            }

            // Suppress the PHP warning on failure — the diagnostic line below
            // surfaces it, and the raw warning breaks
            // `beStrictAboutOutputDuringTests` runs.
            $bytes = @file_put_contents($target, $rendered);
            if ($bytes === false) {
                $this->diagnostic(sprintf('Failed to write %s report to %s', $label, $target));
                $failures++;

                continue;
            }

            $expected = strlen($rendered);
            if ($bytes !== $expected) {
                // Partial write — disk full / quota exceeded mid-write leaves
                // a truncated file that consumers would parse several CI
                // steps later.
                $this->diagnostic(sprintf(
                    'Truncated %s report at %s (%d of %d bytes written)',
                    $label,
                    $target,
                    $bytes,
                    $expected,
                ));
                $failures++;
            }
        }

        return $failures;
    }

    /**
     * GITHUB_STEP_SUMMARY is a single shared sink GitHub consumes as Markdown,
     * so only the Markdown report is appended. A failure is always a WARNING:
     * neither caller lets it drive an exit code.
     *
     * @param array<string, CoverageResult> $results
     * @param array<string, SdkExerciseCoverageResult> $sdkResults
     */
    public function appendGithubStepSummary(array $results, array $sdkResults, ?string $path): void
    {
        if ($path === null) {
            return;
        }

        $markdown = MarkdownCoverageRenderer::render($results, $sdkResults);
        if (@file_put_contents($path, $markdown . "\n", FILE_APPEND) === false) {
            ($this->stderr)("[OpenAPI Coverage] WARNING: Failed to append Markdown report to GITHUB_STEP_SUMMARY ({$path})\n");
        }
    }

    /**
     * Per-spec HTTP coverage, or `[]` when no spec recorded anything so the
     * callers can tell "nothing ran" from "everything is uncovered".
     *
     * @param string[] $specs
     *
     * @return array<string, CoverageResult>
     */
    public function computeResults(array $specs, OpenApiCoverageTracker $tracker): array
    {
        $hasCoverage = false;
        foreach ($specs as $spec) {
            if ($tracker->hasAnyCoverageOn($spec)) {
                $hasCoverage = true;

                break;
            }
        }
        if (!$hasCoverage) {
            return [];
        }

        $results = [];
        foreach ($specs as $spec) {
            try {
                $results[$spec] = $tracker->computeCoverageOn($spec);
            } catch (SpecFileNotFoundException $e) {
                // Bootstrap hard-fails missing files (issue #134); here the
                // tests already ran, so a mid-run unlink degrades to a
                // partial report instead of discarding the observations.
                ($this->stderr)(sprintf("[OpenAPI Coverage] WARNING: Skipping spec '%s': %s\n", $spec, $e->getMessage()));
            } catch (InvalidOpenApiSpecException $e) {
                ($this->stderr)(sprintf("[OpenAPI Coverage] FATAL: Invalid OpenAPI spec '%s': %s\n", $spec, $e->getMessage()));

                throw $e;
            }
        }

        return $results;
    }

    /**
     * Build the denominator even when no decoder ran so reports can show the
     * eligible response schemas that remain unexercised.
     *
     * @param string[] $specs
     *
     * @return array<string, SdkExerciseCoverageResult>
     */
    public function computeSdkResults(array $specs, SdkExerciseCoverageTracker $tracker): array
    {
        $results = [];
        foreach ($specs as $spec) {
            try {
                $results[$spec] = SdkExerciseCoverageReportBuilder::build($spec, $tracker);
            } catch (SpecFileNotFoundException $e) {
                ($this->stderr)(sprintf("[OpenAPI Coverage] WARNING: Skipping spec '%s': %s\n", $spec, $e->getMessage()));
            } catch (InvalidOpenApiSpecException $e) {
                ($this->stderr)(sprintf("[OpenAPI Coverage] FATAL: Invalid OpenAPI spec '%s': %s\n", $spec, $e->getMessage()));

                throw $e;
            }
        }

        return $results;
    }

    private function diagnostic(string $detail): void
    {
        ($this->stderr)(sprintf("[OpenAPI Coverage] %s: %s\n", $this->severity, $detail));
    }
}
