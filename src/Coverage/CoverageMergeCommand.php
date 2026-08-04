<?php

declare(strict_types=1);

namespace Studio\Gesso\Coverage;

use const FILE_APPEND;

use InvalidArgumentException;
use RuntimeException;
use Studio\Gesso\Baseline\ViolationBaseline;
use Studio\Gesso\Baseline\ViolationBaselineFile;
use Studio\Gesso\Exception\InvalidOpenApiSpecException;
use Studio\Gesso\Exception\SpecFileNotFoundException;
use Studio\Gesso\PHPUnit\ConsoleOutput;
use Studio\Gesso\PHPUnit\CoverageReportSubscriber;
use Studio\Gesso\PHPUnit\OpenApiCoverageExtension;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Strict\StrictAdditionalPropertiesAsserter;
use Studio\Gesso\Validation\Strict\StrictAdditionalPropertiesMode;
use Studio\Gesso\Validation\Strict\StrictAdditionalPropertiesTracker;
use Studio\Gesso\Validation\Strict\StrictRequiredAsserter;
use Studio\Gesso\Validation\Strict\StrictRequiredMode;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;
use Throwable;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function explode;
use function file_put_contents;
use function getcwd;
use function getenv;
use function implode;
use function in_array;
use function is_callable;
use function is_numeric;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function unlink;

/**
 * Aggregates worker sidecars (produced by {@see CoverageReportSubscriber} in
 * paratest mode) into a single coverage report — the parallel-runner
 * counterpart to the in-process subscriber rendering.
 *
 * Designed to be invoked as a separate step after the parallel test run
 * finishes (e.g. via `gesso coverage:merge`), but the actual work
 * lives here as a class so it can be unit-tested without spawning a
 * subprocess.
 *
 * @phpstan-import-type CoverageResult from OpenApiCoverageTracker
 * @phpstan-import-type SdkExerciseCoverageResult from SdkExerciseCoverageReportBuilder
 *
 * @phpstan-type MergeReportEntry array{
 *     label: string,
 *     renderer: callable(array<string, CoverageResult>, array<string, SdkExerciseCoverageResult>): string,
 *     outputFile: ?string,
 * }
 * @phpstan-type MergeOptions array{
 *     sidecar_dir?: string,
 *     spec_base_path?: string,
 *     specs?: list<string>,
 *     strip_prefixes?: list<string>,
 *     output_file?: string,
 *     junit_output?: string,
 *     json_output?: string,
 *     html_output?: string,
 *     github_step_summary?: string,
 *     console_output?: string,
 *     cleanup?: bool,
 *     min_endpoint_coverage?: float|string,
 *     min_response_coverage?: float|string,
 *     min_sdk_exercise_coverage?: float|string,
 *     min_coverage_strict?: bool,
 *     strict_required?: string,
 *     strict_additional_properties?: string,
 *     baseline_file?: string,
 *     help?: bool,
 * }
 *
 * @internal Not part of the package's public API. Do not use from user code.
 *           The `gesso coverage:merge` CLI surface is the documented
 *           invocation path; this class's
 *           constructor / methods may change in any release without a SemVer
 *           bump.
 */
final class CoverageMergeCommand
{
    /**
     * @param null|callable(string): void $stderrWriter Optional sink for warnings; defaults to STDERR.
     */
    public function __construct(
        private mixed $stderrWriter = null,
        private mixed $stdoutWriter = null,
        private readonly string $invocation = 'gesso coverage:merge',
    ) {}

    /**
     * Parse argv into the option array consumed by {@see self::run()}. Kept
     * separate so unit tests can drive `run()` directly with a structured
     * payload while the CLI binary parses real `--flag=value` arguments.
     *
     * @param list<string> $argv excluding the script name
     *
     * @return MergeOptions
     */
    public static function parseArgv(array $argv): array
    {
        $opts = [];
        foreach ($argv as $arg) {
            if ($arg === '--help' || $arg === '-h') {
                $opts['help'] = true;

                continue;
            }
            if (!str_starts_with($arg, '--')) {
                continue;
            }
            $rest = substr($arg, 2);
            if (str_contains($rest, '=')) {
                [$name, $value] = explode('=', $rest, 2);
            } else {
                $name = $rest;
                $value = 'true';
            }
            $name = str_replace('-', '_', $name);

            switch ($name) {
                case 'specs':
                case 'strip_prefixes':
                    $opts[$name] = array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $v): bool => $v !== ''));

                    break;
                case 'cleanup':
                    $opts['cleanup'] = !in_array($value, ['0', 'false', 'no'], true);

                    break;
                case 'no_cleanup':
                    $opts['cleanup'] = false;

                    break;
                case 'min_endpoint_coverage':
                case 'min_response_coverage':
                case 'min_sdk_exercise_coverage':
                    // Cast numeric values up-front so phpstan can prove the
                    // 0..100 range check in run(). For non-numeric values
                    // pass the raw string through — run()'s resolveThreshold
                    // is the single point of validation, so a typo'd
                    // `--min-endpoint-coverage=eighty` reaches the user as
                    // one WARNING/FATAL instead of being dropped silently
                    // (issue #135 review C3).
                    $opts[$name] = is_numeric($value) ? (float) $value : $value;

                    break;
                case 'min_coverage_strict':
                    $opts['min_coverage_strict'] = !in_array($value, ['0', 'false', 'no'], true);

                    break;
                default:
                    $opts[$name] = $value;
            }
        }

        /** @var MergeOptions $opts */
        return $opts;
    }

    public static function usage(string $invocation = 'gesso coverage:merge'): string
    {
        return <<<USAGE
            {$invocation} — combine paratest worker sidecars into one coverage report.

            Usage:
              {$invocation} --spec-base-path=<path> [options]

            Options:
              --spec-base-path=<path>       Path to bundled spec directory (required).
              --specs=<a,b>                 Comma-separated spec names. Defaults to "front".
              --strip-prefixes=<a,b>        Comma-separated request-path prefixes to strip.
              --sidecar-dir=<path>          Worker sidecar directory. Defaults to
                                            sys_get_temp_dir()/openapi-coverage-sidecars.
              --output-file=<path>          Markdown report output path.
              --junit-output=<path>         JUnit XML report output path (for CI dashboards
                                            like GitLab CI test reports, Jenkins, SonarQube).
              --json-output=<path>          JSON report output path (machine-readable; see
                                            docs/coverage-json-schema.md for the schema).
              --html-output=<path>          Self-contained HTML report output path (for PR
                                            comments, CI artifact preview, offline review).
              --github-step-summary=<path>  Append Markdown report to this file (also
                                            consults GITHUB_STEP_SUMMARY env var).
              --console-output=<mode>       default | all | uncovered_only.
              --min-endpoint-coverage=<pct> Fail-fast (with --min-coverage-strict) when fully-
                                            covered-endpoint percent is below this value (0-100).
              --min-response-coverage=<pct> Same, at (method, path, status, content-type) granularity.
              --min-sdk-exercise-coverage=<pct>
                                            Minimum eligible response schemas exercised by an
                                            SDK decoder callback (0-100).
              --min-coverage-strict[=BOOL]  Treat threshold misses as exit non-zero (default
                                            warn-only).
              --strict-required=<mode>      off | warn | fail. Aggregate worker observations
                                            and assert no schema under-description drift
                                            (Issue #224 / #226). Defaults to off.
              --strict-additional-properties=<mode>
                                            off | warn | fail. Report response properties
                                            absent from schema declarations. Defaults to off.
              --baseline-file=<path>        Union the violation-baseline halves staged by a
                                            parallel `OPENAPI_BASELINE_GENERATE=1` run and
                                            write the merged baseline here (Issue #417).
              --no-cleanup                  Keep sidecar files after merge (default: cleanup).
              --help                        Show this message.

            USAGE;
    }

    /**
     * @param MergeOptions $options
     *
     * @return int 0 on success, non-zero on misconfiguration / I/O failure
     */
    public function run(array $options): int
    {
        if (($options['help'] ?? false) === true) {
            $this->writeStdout(self::usage($this->invocation));

            return 0;
        }

        $sidecarDir = isset($options['sidecar_dir']) && $options['sidecar_dir'] !== ''
            ? $this->absolutise($options['sidecar_dir'])
            : OpenApiCoverageExtension::defaultSidecarDir();

        // Empty `--specs=` is treated as "use default" rather than "use no
        // specs". Otherwise a misconfigured CLI invocation would silently
        // exit with "no coverage recorded" instead of warning the user.
        $specs = isset($options['specs']) && $options['specs'] !== [] ? $options['specs'] : ['front'];

        $specBasePath = isset($options['spec_base_path']) && $options['spec_base_path'] !== ''
            ? $this->absolutise($options['spec_base_path'])
            : null;
        $stripPrefixes = $options['strip_prefixes'] ?? [];
        $outputFile = isset($options['output_file']) && $options['output_file'] !== ''
            ? $this->absolutise($options['output_file'])
            : null;
        $junitOutput = isset($options['junit_output']) && $options['junit_output'] !== ''
            ? $this->absolutise($options['junit_output'])
            : null;
        $jsonOutput = isset($options['json_output']) && $options['json_output'] !== ''
            ? $this->absolutise($options['json_output'])
            : null;
        $htmlOutput = isset($options['html_output']) && $options['html_output'] !== ''
            ? $this->absolutise($options['html_output'])
            : null;
        $githubSummaryPath = isset($options['github_step_summary']) && $options['github_step_summary'] !== ''
            ? $options['github_step_summary']
            : (getenv('GITHUB_STEP_SUMMARY') ?: null);
        $consoleOutput = ConsoleOutput::resolve($options['console_output'] ?? null);
        $cleanup = $options['cleanup'] ?? true;
        $minStrict = $options['min_coverage_strict'] ?? false;
        $endpointResolution = $this->resolveThreshold('min_endpoint_coverage', $options['min_endpoint_coverage'] ?? null, $minStrict);
        $responseResolution = $this->resolveThreshold('min_response_coverage', $options['min_response_coverage'] ?? null, $minStrict);
        $sdkResolution = $this->resolveThreshold('min_sdk_exercise_coverage', $options['min_sdk_exercise_coverage'] ?? null, $minStrict);
        if ($endpointResolution['fatal'] || $responseResolution['fatal'] || $sdkResolution['fatal']) {
            // Strict-mode misconfiguration: a typo'd / out-of-range threshold
            // would otherwise silently disable the gate the user opted into.
            // Exit 2 mirrors the `--spec-base-path is required` config error.
            return 2;
        }
        $minEndpointPct = $endpointResolution['value'];
        $minResponsePct = $responseResolution['value'];
        $minSdkExercisePct = $sdkResolution['value'];

        try {
            $strictRequiredMode = StrictRequiredMode::fromConfigValue($options['strict_required'] ?? null);
        } catch (InvalidArgumentException $e) {
            // Same severity as a malformed threshold (exit 2). A typo here
            // silently disables the gate the user opted into via CI flag.
            $this->writeStderr(sprintf("[OpenAPI Strict Required] FATAL: %s\n", $e->getMessage()));

            return 2;
        }

        try {
            $strictAdditionalPropertiesMode = StrictAdditionalPropertiesMode::fromConfigValue(
                $options['strict_additional_properties'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            $this->writeStderr(sprintf("[OpenAPI Strict Additional Properties] FATAL: %s\n", $e->getMessage()));

            return 2;
        }

        $baselineFile = isset($options['baseline_file']) && $options['baseline_file'] !== ''
            ? $this->absolutise($options['baseline_file'])
            : null;

        if ($specBasePath === null) {
            $this->writeStderr("[OpenAPI Coverage] FATAL: --spec-base-path is required\n");

            return 2;
        }

        // Detect worker failure markers BEFORE attempting the merge: even
        // one missing worker means the report under-counts coverage, which
        // is exactly the silent failure parallel-mode introduced. Fail
        // loudly so CI gating sees a non-zero exit.
        try {
            $failureMarkers = CoverageSidecarReader::listFailureMarkerPaths($sidecarDir);
            if ($failureMarkers !== []) {
                $this->writeStderr(sprintf(
                    "[OpenAPI Coverage] FATAL: %d worker(s) failed to write a sidecar; merge would under-count coverage. Markers in %s\n",
                    count($failureMarkers),
                    $sidecarDir,
                ));

                return 1;
            }

            $payloads = CoverageSidecarReader::readDir($sidecarDir);
        } catch (RuntimeException $e) {
            $this->writeStderr(sprintf("[OpenAPI Coverage] FATAL: %s\n", $e->getMessage()));

            return 1;
        }

        if ($payloads === []) {
            // Issue #417: a generation run that produced no sidecars cannot
            // yield a baseline — but the workers already demoted every
            // failure, so returning 0 without a file would hide all of them.
            if ($baselineFile !== null) {
                $this->writeStderr(sprintf(
                    "[Gesso] FATAL: --baseline-file requested but no sidecars were found in %s; no baseline was written. Run the parallel suite with OPENAPI_BASELINE_GENERATE=1 first.\n",
                    $sidecarDir,
                ));

                return 1;
            }

            if ($strictAdditionalPropertiesMode === StrictAdditionalPropertiesMode::Fail) {
                $this->writeStderr(sprintf(
                    "[OpenAPI Strict Additional Properties] FATAL: no sidecars were found in %s; strict additional-properties evaluation cannot be completed.\n",
                    $sidecarDir,
                ));

                return 1;
            }

            // Strict-mode gate must fail-fast even before sidecars exist —
            // otherwise a misconfigured paratest dir or zero workers would
            // silently pass an opt-in CI gate (issue #135 review C2).
            if ($minStrict && ($minEndpointPct !== null || $minResponsePct !== null || $minSdkExercisePct !== null)) {
                $this->writeStderr(sprintf(
                    "[OpenAPI Coverage] FATAL: no contract test coverage was recorded; configured threshold cannot be evaluated. (no sidecars in %s)\n",
                    $sidecarDir,
                ));

                return 1;
            }
            $this->writeStderr(sprintf("[OpenAPI Coverage] WARNING: no sidecars found in %s\n", $sidecarDir));

            return 0;
        }

        OpenApiSpecLoader::reset();
        // Issue #172: $enumBasePath is deliberately left unwired. The merge
        // command never invokes EnumDriftAsserter, so #[BoundToOpenApiEnum]
        // resolution never runs here and the omission is inert. If enum-drift
        // responsibilities are ever added to this command, plumb an
        // `--enum-spec-base-path` flag through to the `enumBasePath:` argument
        // and mirror the validation in
        // OpenApiCoverageExtension::resolveEnumSpecBasePathParameter()
        // (empty / orphaned value => FATAL, EnumSpecBasePathOrphaned).
        OpenApiSpecLoader::configure($specBasePath, $stripPrefixes);

        // Issue #229: each merge invocation owns its trackers. Installing
        // them via setCurrent() routes the static facade (used by the
        // StrictRequiredAsserter helpers) into these same instances, so the
        // gate evaluates against exactly the state this run aggregated.
        // resetCurrent() first drops any prior process-global state so the
        // setCurrent() overwrite-guard does not trip on a leftover instance
        // from an earlier merge invocation in the same process (the
        // PHPUnit-extension-driven test harness can call run() multiple
        // times per process).
        $coverageTracker = new OpenApiCoverageTracker();
        $strictRequiredTracker = new StrictRequiredTracker();
        $strictAdditionalPropertiesTracker = new StrictAdditionalPropertiesTracker();
        $sdkExerciseCoverageTracker = new SdkExerciseCoverageTracker();
        OpenApiCoverageTracker::resetCurrent();
        StrictRequiredTracker::resetCurrent();
        StrictAdditionalPropertiesTracker::resetCurrent();
        SdkExerciseCoverageTracker::resetCurrent();
        OpenApiCoverageTracker::setCurrent($coverageTracker);
        StrictRequiredTracker::setCurrent($strictRequiredTracker);
        StrictAdditionalPropertiesTracker::setCurrent($strictAdditionalPropertiesTracker);
        SdkExerciseCoverageTracker::setCurrent($sdkExerciseCoverageTracker);
        $mergedBaseline = new ViolationBaseline();
        $sidecarsWithoutBaseline = 0;
        $sidecarsWithoutStrictAdditionalProperties = 0;
        $sidecarsWithoutSdkExercise = 0;
        foreach ($payloads as $payload) {
            try {
                $parsed = CoverageSidecarEnvelope::parse($payload);
                $coverageTracker->importStateOn($parsed['coverage']);
                if ($parsed['strictRequired'] !== null) {
                    $strictRequiredTracker->importStateOn($parsed['strictRequired']);
                }
                if ($parsed['strictAdditionalProperties'] !== null) {
                    $strictAdditionalPropertiesTracker->importStateOn($parsed['strictAdditionalProperties']);
                } else {
                    $sidecarsWithoutStrictAdditionalProperties++;
                }
                if ($parsed['sdkExercise'] !== null) {
                    $sdkExerciseCoverageTracker->importStateOn($parsed['sdkExercise']);
                } else {
                    $sidecarsWithoutSdkExercise++;
                }
                if ($parsed['baseline'] !== null) {
                    // parseDocument re-validates the embedded document
                    // (unknown baseline_version fails loudly — the
                    // mixed-fleet contract of issue #417).
                    foreach (ViolationBaselineFile::parseDocument($parsed['baseline'])->sorted() as $fingerprint) {
                        $mergedBaseline->add($fingerprint);
                    }
                } else {
                    $sidecarsWithoutBaseline++;
                }
            } catch (InvalidArgumentException $e) {
                $this->writeStderr(sprintf("[OpenAPI Coverage] FATAL: invalid sidecar payload: %s\n", $e->getMessage()));

                return 1;
            }
        }

        if (!$this->handleBaseline($baselineFile, $mergedBaseline, $sidecarsWithoutBaseline, count($payloads))) {
            return 1;
        }

        if ($minSdkExercisePct !== null && $sidecarsWithoutSdkExercise > 0) {
            $this->writeCoverageDiagnostic(
                $minStrict ? 'FATAL' : 'WARNING',
                sprintf(
                    '%d worker sidecar(s) have no SDK exercise state; '
                    . 'the merged SDK exercise gate may be incomplete. Upgrade every worker to the v6/v7 sidecar envelope.',
                    $sidecarsWithoutSdkExercise,
                ),
            );
            if ($minStrict) {
                return 1;
            }
        }

        $results = $this->computeResults($specs, $coverageTracker);
        $hasSdkState = $sidecarsWithoutSdkExercise < count($payloads);
        $sdkResults = $hasSdkState || $minSdkExercisePct !== null
            ? $this->computeSdkResults($specs, $sdkExerciseCoverageTracker)
            : [];
        if ($results === [] && $sdkResults === []) {
            $strictGated = $minStrict && (
                $minEndpointPct !== null ||
                $minResponsePct !== null ||
                $minSdkExercisePct !== null
            );
            $strictAdditionalPropertiesFailure = $this->evaluateStrictAdditionalPropertiesGate(
                $strictAdditionalPropertiesMode,
                $githubSummaryPath,
                $strictAdditionalPropertiesTracker,
                $sidecarsWithoutStrictAdditionalProperties,
            );
            $this->writeStderr(sprintf(
                "[OpenAPI Coverage] %s: no contract test coverage was recorded; %s\n",
                $strictGated ? 'FATAL' : 'WARNING',
                $strictGated
                    ? 'configured threshold cannot be evaluated. (sidecars present but recorded no observations)'
                    : 'no coverage recorded across sidecars',
            ));

            if ($cleanup && !$this->cleanupSafely($sidecarDir)) {
                return 1;
            }

            return $strictGated || $strictAdditionalPropertiesFailure ? 1 : 0;
        }

        $this->writeStdout(ConsoleCoverageRenderer::render($results, $consoleOutput, $sdkResults));

        $writeFailures = $this->writeReports($results, $sdkResults, $outputFile, $junitOutput, $jsonOutput, $htmlOutput);
        $this->appendGithubStepSummary($results, $sdkResults, $githubSummaryPath);

        $httpThresholdUnavailable = $results === [] && ($minEndpointPct !== null || $minResponsePct !== null);
        if ($httpThresholdUnavailable) {
            $this->writeCoverageDiagnostic(
                $minStrict ? 'FATAL' : 'WARNING',
                'no contract test coverage was recorded; configured HTTP threshold cannot be evaluated.',
            );
        }
        $thresholdFailure = ($httpThresholdUnavailable && $minStrict) || $this->evaluateThresholdGate(
            $results,
            $sdkResults,
            $httpThresholdUnavailable ? null : $minEndpointPct,
            $httpThresholdUnavailable ? null : $minResponsePct,
            $minSdkExercisePct,
            $minStrict,
        );

        // Issue #226: aggregate strict_required across workers and run the
        // gate after the report is rendered so a fatal drift doesn't suppress
        // the coverage output users rely on for triage.
        $strictFailure = $this->evaluateStrictRequiredGate($strictRequiredMode, $githubSummaryPath, $strictRequiredTracker);
        $strictAdditionalPropertiesFailure = $this->evaluateStrictAdditionalPropertiesGate(
            $strictAdditionalPropertiesMode,
            $githubSummaryPath,
            $strictAdditionalPropertiesTracker,
            $sidecarsWithoutStrictAdditionalProperties,
        );

        $cleanupFailure = $cleanup && !$this->cleanupSafely($sidecarDir);

        return $writeFailures > 0 ||
            $thresholdFailure ||
            $strictFailure ||
            $strictAdditionalPropertiesFailure ||
            $cleanupFailure
            ? 1
            : 0;
    }

    /**
     * Issue #417: union the violation-baseline halves staged by
     * generation-mode workers and write the merged file. Returns `false`
     * when the merge must exit non-zero; every failure path runs before
     * sidecar cleanup so the staged data survives for a corrected retry.
     *
     * Fail-loud cases mirror the sequential generation contract:
     *  - baseline data present but no `--baseline-file`: discarding it
     *    would silently hide the violations the workers demoted.
     *  - `--baseline-file` given but some sidecar carries no baseline half
     *    (a non-generation or pre-#417 worker): the union would be a
     *    partial baseline — the exact incomplete-file hazard the
     *    sequential path refuses partial runs for.
     */
    private function handleBaseline(
        ?string $baselineFile,
        ViolationBaseline $merged,
        int $sidecarsWithoutBaseline,
        int $sidecarCount,
    ): bool {
        $sidecarsWithBaseline = $sidecarCount - $sidecarsWithoutBaseline;

        if ($baselineFile === null) {
            if ($sidecarsWithBaseline === 0) {
                return true;
            }

            $this->writeStderr(sprintf(
                "[Gesso] FATAL: %d of %d sidecar(s) carry violation-baseline data from an OPENAPI_BASELINE_GENERATE run, but --baseline-file was not given; discarding it would silently hide the demoted violations. Re-run the merge with --baseline-file=<path>.\n",
                $sidecarsWithBaseline,
                $sidecarCount,
            ));

            return false;
        }

        if ($sidecarsWithoutBaseline > 0) {
            $this->writeStderr(sprintf(
                "[Gesso] FATAL: --baseline-file requested but %d of %d sidecar(s) carry no baseline data; the union would be an incomplete baseline. Ensure every worker ran with OPENAPI_BASELINE_GENERATE=1 on a library version that stages baseline sidecars. No baseline was written.\n",
                $sidecarsWithoutBaseline,
                $sidecarCount,
            ));

            return false;
        }

        try {
            ViolationBaselineFile::write($baselineFile, $merged);
        } catch (RuntimeException $e) {
            $this->writeStderr("[Gesso] FATAL: {$e->getMessage()}\n");

            return false;
        }

        $this->writeStderr(sprintf(
            "[Gesso] Baseline written: %d violation(s) → %s\n",
            $merged->count(),
            $baselineFile,
        ));

        return true;
    }

    /**
     * Dispatch each configured renderer to its output target. Per-entry write
     * failures emit a FATAL line, bump the counter the caller turns into a
     * non-zero exit, and continue to the next entry — one format's broken
     * path must not suppress the others or block the threshold gate that
     * runs after this.
     *
     * @param array<string, CoverageResult> $results
     * @param array<string, SdkExerciseCoverageResult> $sdkResults
     *
     * @return int Number of format outputs that failed to write
     */
    private function writeReports(
        array $results,
        array $sdkResults,
        ?string $outputFile,
        ?string $junitOutput,
        ?string $jsonOutput,
        ?string $htmlOutput,
    ): int {
        $writeFailures = 0;

        foreach ($this->buildReportEntries($outputFile, $junitOutput, $jsonOutput, $htmlOutput) as $entry) {
            if ($entry['outputFile'] === null) {
                continue;
            }

            try {
                $rendered = ($entry['renderer'])($results, $sdkResults);
            } catch (Throwable $e) {
                $this->writeStderr(sprintf(
                    "[OpenAPI Coverage] FATAL: Failed to render %s report: %s\n",
                    $entry['label'],
                    $e->getMessage(),
                ));
                $writeFailures++;

                continue;
            }

            // Suppress PHP warning on failure — we surface the error
            // ourselves via stderr + exit code so the warning is redundant
            // noise that breaks `beStrictAboutOutputDuringTests` test runs.
            $bytes = @file_put_contents($entry['outputFile'], $rendered);
            if ($bytes === false) {
                $this->writeStderr(sprintf(
                    "[OpenAPI Coverage] FATAL: Failed to write %s report to %s\n",
                    $entry['label'],
                    $entry['outputFile'],
                ));
                $writeFailures++;

                continue;
            }

            $expected = strlen($rendered);
            if ($bytes !== $expected) {
                // Partial write — disk full / quota exceeded mid-write leaves
                // a truncated file. Surface explicitly so downstream consumers
                // don't parse half a document several CI steps later.
                $this->writeStderr(sprintf(
                    "[OpenAPI Coverage] FATAL: Truncated %s report at %s (%d of %d bytes written)\n",
                    $entry['label'],
                    $entry['outputFile'],
                    $bytes,
                    $expected,
                ));
                $writeFailures++;
            }
        }

        return $writeFailures;
    }

    /**
     * Renderer dispatch table. Adding a new format here does not require
     * changes to the loop in {@see self::writeReports()}. The PHPUnit subscriber
     * keeps a parallel table in {@see CoverageReportSubscriber}, so any new
     * format must be added to both in lockstep — note the severity asymmetry
     * (subscriber warns; CLI counts failures toward exit code).
     *
     * @return list<MergeReportEntry>
     */
    private function buildReportEntries(?string $outputFile, ?string $junitOutput, ?string $jsonOutput, ?string $htmlOutput): array
    {
        return [
            [
                'label' => 'Markdown',
                'renderer' => static fn(array $r, array $s): string => MarkdownCoverageRenderer::render($r, $s),
                'outputFile' => $outputFile,
            ],
            [
                'label' => 'JUnit XML',
                'renderer' => static fn(array $r, array $s): string => JUnitCoverageRenderer::render($r, $s),
                'outputFile' => $junitOutput,
            ],
            [
                'label' => 'JSON',
                'renderer' => static fn(array $r, array $s): string => JsonCoverageRenderer::render($r, sdkResults: $s),
                'outputFile' => $jsonOutput,
            ],
            [
                'label' => 'HTML',
                'renderer' => static fn(array $r, array $s): string => HtmlCoverageRenderer::render($r, $s),
                'outputFile' => $htmlOutput,
            ],
        ];
    }

    /**
     * GITHUB_STEP_SUMMARY is Markdown-only by design — the file is a single
     * shared sink that GitHub consumes as Markdown, so JUnit/JSON/HTML do not
     * get appended here. A failure here is non-fatal: the merge CLI's exit
     * code stays driven by the primary output writes.
     *
     * @param array<string, CoverageResult> $results
     * @param array<string, SdkExerciseCoverageResult> $sdkResults
     */
    private function appendGithubStepSummary(array $results, array $sdkResults, ?string $githubSummaryPath): void
    {
        if ($githubSummaryPath === null) {
            return;
        }

        $markdown = MarkdownCoverageRenderer::render($results, $sdkResults);

        if (@file_put_contents($githubSummaryPath, $markdown . "\n", FILE_APPEND) === false) {
            $this->writeStderr(sprintf(
                "[OpenAPI Coverage] WARNING: Failed to append Markdown report to GITHUB_STEP_SUMMARY (%s)\n",
                $githubSummaryPath,
            ));
        }
    }

    /**
     * Run the threshold gate against rolled-up results. Prints the evaluator's
     * pre-formatted message to stderr when at least one threshold misses; the
     * caller decides what to do with the return value (only `strict=true`
     * misses propagate to a non-zero exit).
     *
     * @param array<string, CoverageResult> $results
     * @param array<string, SdkExerciseCoverageResult> $sdkResults
     */
    private function evaluateThresholdGate(
        array $results,
        array $sdkResults,
        ?float $minEndpointPct,
        ?float $minResponsePct,
        ?float $minSdkExercisePct,
        bool $strict,
    ): bool {
        if ($minEndpointPct === null && $minResponsePct === null && $minSdkExercisePct === null) {
            return false;
        }

        $evaluation = CoverageThresholdEvaluator::evaluate(
            $results,
            $sdkResults,
            $minEndpointPct,
            $minResponsePct,
            $minSdkExercisePct,
            $strict,
        );

        if ($evaluation['passed']) {
            return false;
        }

        $this->writeStderr($evaluation['message']);

        return $strict;
    }

    /**
     * Aggregate strict_required observations imported from worker sidecars
     * and surface any drift. Returns `true` when the run should exit
     * non-zero (Fail mode + drift, or Fail mode + zero observations);
     * Warn mode reports drift but returns `false` so the merge CLI's exit
     * stays driven by the user's intent.
     *
     * Mirrors {@see CoverageReportSubscriber::evaluateStrictRequiredGate()}
     * but translates fatality into a return value instead of `exit()`ing —
     * the CLI surface joins it with `writeFailures`/`thresholdFailure` into
     * one exit code. The subscriber's partial-run skip branch is omitted
     * here because aggregated worker output carries no per-process filter
     * context.
     *
     * Off mode short-circuits before invoking the asserter so unrelated
     * runs do not pay for spec loading and intersection diffing.
     */
    private function evaluateStrictRequiredGate(
        StrictRequiredMode $mode,
        ?string $githubSummaryPath,
        StrictRequiredTracker $strictRequiredTracker,
    ): bool {
        if ($mode === StrictRequiredMode::Off) {
            return false;
        }

        // Fail-loud guard: when the user opted into --strict-required=fail
        // but no worker recorded any observation (e.g., a fleet still on the
        // pre-envelope library version, or a misconfigured paratest run),
        // the gate cannot evaluate the contract. Silent-pass here would
        // defeat the whole point of opting into fail-fast — symmetric with
        // the threshold gate's no-coverage FATAL in `run()`.
        if ($mode === StrictRequiredMode::Fail && $strictRequiredTracker->recordedSpecsOn() === []) {
            $this->writeStderr(
                '[OpenAPI Strict Required] FATAL: --strict-required=fail requested but '
                . 'no worker recorded any strict_required observations; the gate cannot '
                . "be evaluated. Verify all workers are running the v2 sidecar envelope.\n",
            );

            return true;
        }

        $reports = StrictRequiredAsserter::detectAll($mode);
        $unresolved = StrictRequiredAsserter::detectUnresolvedGroups($mode);

        if ($unresolved !== []) {
            // Observed an endpoint with no matching response schema. The user
            // cannot distinguish "no drift" from "no schema to compare" by
            // reading the no-drift report alone — surface every offender so
            // they can spot the spec lookup miss.
            $this->writeStderr(sprintf(
                "[OpenAPI Strict Required] NOTE: %d observation group(s) had no matching response schema; skipped from drift detection:\n  - %s\n",
                count($unresolved),
                implode("\n  - ", $unresolved),
            ));
        }

        if ($reports === []) {
            return false;
        }

        $isFatal = $mode === StrictRequiredMode::Fail;
        $message = StrictRequiredAsserter::renderMessage($reports, $isFatal);
        $this->writeStderr($message . "\n");
        OpenApiCoverageExtension::appendGithubStepSummaryStrictRequiredBlock(
            $githubSummaryPath,
            $message,
            $isFatal,
        );

        return $isFatal;
    }

    private function evaluateStrictAdditionalPropertiesGate(
        StrictAdditionalPropertiesMode $mode,
        ?string $githubSummaryPath,
        StrictAdditionalPropertiesTracker $tracker,
        int $sidecarsWithoutTracker,
    ): bool {
        if ($mode === StrictAdditionalPropertiesMode::Off) {
            return false;
        }
        if ($mode === StrictAdditionalPropertiesMode::Fail && $sidecarsWithoutTracker > 0) {
            $this->writeStderr(sprintf(
                '[OpenAPI Strict Additional Properties] FATAL: %d worker sidecar(s) have no strict additional-properties state; '
                . "the merged gate would be incomplete. Upgrade every worker to the v4/v5 sidecar envelope.\n",
                $sidecarsWithoutTracker,
            ));

            return true;
        }
        if ($mode === StrictAdditionalPropertiesMode::Fail && $tracker->evaluationsOn() === 0) {
            $this->writeStderr(
                '[OpenAPI Strict Additional Properties] FATAL: --strict-additional-properties=fail requested but '
                . "no worker exported strict additional-properties evaluations. Verify all workers use the v4/v5 sidecar envelope.\n",
            );

            return true;
        }

        $reports = StrictAdditionalPropertiesAsserter::detectAll($tracker);
        if ($reports === []) {
            return false;
        }

        $isFatal = $mode === StrictAdditionalPropertiesMode::Fail;
        $message = StrictAdditionalPropertiesAsserter::renderMessage($reports, $isFatal);
        $this->writeStderr($message . "\n");
        OpenApiCoverageExtension::appendGithubStepSummaryStrictAdditionalPropertiesBlock(
            $githubSummaryPath,
            $message,
            $isFatal,
        );

        return $isFatal;
    }

    /**
     * Validate a percentage threshold from CLI options. Returns the parsed
     * value (or `null` when the option is absent / invalid) plus a fatal
     * flag the caller uses to short-circuit `run()` with exit 2.
     *
     * Severity follows `min_coverage_strict`:
     *  - non-strict: invalid values become a WARNING and the gate is dropped
     *    — opt-in mode tolerates misconfiguration.
     *  - strict:     invalid values become a FATAL exit-2 — a CI that opted
     *    into fail-fast must not silently lose its gate to a typo
     *    (issue #135 review C1).
     *
     * @return array{value: ?float, fatal: bool}
     */
    private function resolveThreshold(string $name, mixed $value, bool $strict): array
    {
        if ($value === null) {
            return ['value' => null, 'fatal' => false];
        }

        if (!is_numeric($value)) {
            return $this->reportThresholdProblem(
                $strict,
                sprintf("%s='%s' is not a number; skipping threshold gate.", $name, (string) $value),
            );
        }

        $float = (float) $value;
        if ($float < 0.0 || $float > 100.0) {
            return $this->reportThresholdProblem(
                $strict,
                sprintf('%s=%s is out of range (expected 0-100); skipping threshold gate.', $name, (string) $float),
            );
        }

        return ['value' => $float, 'fatal' => false];
    }

    /**
     * @return array{value: null, fatal: bool}
     */
    private function reportThresholdProblem(bool $strict, string $detail): array
    {
        $severity = $strict ? 'FATAL' : 'WARNING';
        $this->writeCoverageDiagnostic($severity, $detail);

        return ['value' => null, 'fatal' => $strict];
    }

    /**
     * @param list<string> $specs
     *
     * @return array<string, CoverageResult>
     */
    private function computeResults(array $specs, OpenApiCoverageTracker $tracker): array
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
                $this->writeStderr(sprintf("[OpenAPI Coverage] WARNING: Skipping spec '%s': %s\n", $spec, $e->getMessage()));
            } catch (InvalidOpenApiSpecException $e) {
                $this->writeStderr(sprintf("[OpenAPI Coverage] FATAL: Invalid OpenAPI spec '%s': %s\n", $spec, $e->getMessage()));

                throw $e;
            }
        }

        return $results;
    }

    /**
     * @param list<string> $specs
     *
     * @return array<string, SdkExerciseCoverageResult>
     */
    private function computeSdkResults(array $specs, SdkExerciseCoverageTracker $tracker): array
    {
        $results = [];
        foreach ($specs as $spec) {
            try {
                $results[$spec] = SdkExerciseCoverageReportBuilder::build($spec, $tracker);
            } catch (SpecFileNotFoundException $e) {
                $this->writeCoverageDiagnostic('WARNING', sprintf("Skipping spec '%s': %s", $spec, $e->getMessage()));
            } catch (InvalidOpenApiSpecException $e) {
                $this->writeCoverageDiagnostic('FATAL', sprintf("Invalid OpenAPI spec '%s': %s", $spec, $e->getMessage()));

                throw $e;
            }
        }

        return $results;
    }

    private function cleanup(string $sidecarDir): void
    {
        $paths = [
            ...CoverageSidecarReader::listPaths($sidecarDir),
            ...CoverageSidecarReader::listFailureMarkerPaths($sidecarDir),
        ];
        foreach ($paths as $path) {
            // Surface unlink failures: a leftover sidecar is silently merged
            // into the next run and would over-count coverage.
            if (!@unlink($path)) {
                $this->writeStderr(sprintf(
                    "[OpenAPI Coverage] WARNING: Failed to delete sidecar/marker after merge: %s\n",
                    $path,
                ));
            }
        }
    }

    private function cleanupSafely(string $sidecarDir): bool
    {
        try {
            $this->cleanup($sidecarDir);
        } catch (RuntimeException $e) {
            $this->writeCoverageDiagnostic(
                'FATAL',
                sprintf('failed to inspect sidecars for cleanup: %s', $e->getMessage()),
            );

            return false;
        }

        return true;
    }

    private function writeCoverageDiagnostic(string $severity, string $detail): void
    {
        $this->writeStderr(sprintf("[OpenAPI Coverage] %s: %s\n", $severity, $detail));
    }

    private function absolutise(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return (getcwd() ?: '.') . '/' . $path;
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

    private function writeStdout(string $message): void
    {
        $writer = $this->stdoutWriter;
        if (is_callable($writer)) {
            $writer($message);

            return;
        }

        echo $message;
    }
}
