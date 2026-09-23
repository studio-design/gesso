<?php

declare(strict_types=1);

namespace Studio\Gesso\Coverage;

use InvalidArgumentException;
use RuntimeException;
use Studio\Gesso\Baseline\BaselineStaleMode;
use Studio\Gesso\Baseline\CoverageBaselineEvaluator;
use Studio\Gesso\Baseline\CoverageBaselineFile;
use Studio\Gesso\Baseline\ViolationBaseline;
use Studio\Gesso\Baseline\ViolationBaselineFile;
use Studio\Gesso\Internal\ArgvParser;
use Studio\Gesso\Internal\CliIo;
use Studio\Gesso\Internal\CoverageReportWriter;
use Studio\Gesso\Internal\Deprecations;
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

use function count;
use function ctype_digit;
use function getenv;
use function implode;
use function is_int;
use function is_numeric;
use function is_string;
use function sprintf;
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
 *     coverage_baseline_file?: string,
 *     coverage_baseline_stale?: string,
 *     expect_sidecars?: int|string,
 *     help?: bool,
 *     invalid_options?: list<string>,
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
    use CliIo;

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
        $opts = ArgvParser::parse(
            $argv,
            'coverage:merge',
            flags: ['cleanup', 'no_cleanup', 'min_coverage_strict'],
            values: [
                'sidecar_dir', 'spec_base_path', 'output_file', 'junit_output', 'json_output', 'html_output',
                'github_step_summary', 'console_output', 'strict_required', 'strict_additional_properties',
                'baseline_file', 'coverage_baseline_file', 'coverage_baseline_stale',
                'min_endpoint_coverage', 'min_response_coverage', 'min_sdk_exercise_coverage', 'expect_sidecars',
            ],
            lists: ['specs', 'strip_prefixes'],
        );

        if (isset($opts['no_cleanup'])) {
            $opts['cleanup'] = false;
            unset($opts['no_cleanup']);
        }
        // Cast numeric values up-front so phpstan can prove the 0..100 range
        // check in run(). Non-numeric values pass through as the raw string:
        // run()'s resolveThreshold is the single point of validation, so a
        // typo'd `--min-endpoint-coverage=eighty` reaches the user as one
        // WARNING/FATAL instead of being dropped silently (issue #135 review C3).
        foreach (['min_endpoint_coverage', 'min_response_coverage', 'min_sdk_exercise_coverage'] as $threshold) {
            if (isset($opts[$threshold]) && is_numeric($opts[$threshold])) {
                $opts[$threshold] = (float) $opts[$threshold];
            }
        }
        // `ctype_digit` rather than `is_numeric`: `--expect-sidecars=3.7` is a
        // mistake, not a request for three shards.
        if (isset($opts['expect_sidecars']) && ctype_digit($opts['expect_sidecars'])) {
            $opts['expect_sidecars'] = (int) $opts['expect_sidecars'];
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
                                            parallel `GESSO_BASELINE_GENERATE=1` run and
                                            write the merged baseline here (Issue #417).
              --coverage-baseline-file=<path>
                                            Gate the merged coverage against a committed set of
                                            known-uncovered responses: any uncovered response
                                            missing from the file fails the merge by name
                                            (Issue #481). With GESSO_BASELINE_GENERATE=1 set,
                                            the file is (re)written instead of enforced.
              --coverage-baseline-stale=<mode>
                                            off | note | fail. How baseline entries that are
                                            covered now are reported. Defaults to note.
              --expect-sidecars=<n>         Fail unless exactly <n> sidecars are present. Use it
                                            when the shard count is known up front (a CI matrix):
                                            a cancelled or failed job writes no sidecar at all,
                                            and the merge would otherwise under-count silently
                                            (Issue #434).
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

        $sidecarDir = $this->pathOption($options['sidecar_dir'] ?? null) ?? OpenApiCoverageExtension::defaultSidecarDir();

        // Empty `--specs=` is treated as "use default" rather than "use no
        // specs". Otherwise a misconfigured CLI invocation would silently
        // exit with "no coverage recorded" instead of warning the user.
        $specs = isset($options['specs']) && $options['specs'] !== [] ? $options['specs'] : ['front'];

        $specBasePath = $this->pathOption($options['spec_base_path'] ?? null);
        $stripPrefixes = $options['strip_prefixes'] ?? [];
        $outputFile = $this->pathOption($options['output_file'] ?? null);
        $junitOutput = $this->pathOption($options['junit_output'] ?? null);
        $jsonOutput = $this->pathOption($options['json_output'] ?? null);
        $htmlOutput = $this->pathOption($options['html_output'] ?? null);
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

        $baselineFile = $this->pathOption($options['baseline_file'] ?? null);

        // Issue #481: the coverage baseline gate for parallel runs. Unlike
        // the violation baseline — whose entries are staged per worker — the
        // merged coverage state already is the whole-suite view, so this
        // command both generates and enforces from it.
        $coverageBaselineFile = $this->pathOption($options['coverage_baseline_file'] ?? null);
        $coverageBaselineGenerate = $coverageBaselineFile !== null && OpenApiCoverageExtension::baselineGenerationRequested();

        try {
            $coverageBaselineStaleMode = BaselineStaleMode::fromConfigValue($options['coverage_baseline_stale'] ?? null);
        } catch (InvalidArgumentException $e) {
            // Same severity as a malformed threshold (exit 2): a typo here
            // silently changes the gate the user opted into.
            $this->writeStderr(sprintf("[Gesso] FATAL: %s\n", $e->getMessage()));

            return 2;
        }

        if ($coverageBaselineFile === null && isset($options['coverage_baseline_stale'])) {
            $this->writeStderr(
                "[Gesso] FATAL: --coverage-baseline-stale is set but --coverage-baseline-file was not given; there is no baseline to evaluate staleness against.\n",
            );

            return 2;
        }

        if ($specBasePath === null) {
            $this->writeStderr("[OpenAPI Coverage] FATAL: --spec-base-path is required\n");

            return 2;
        }

        // Issue #434: the expected shard count, validated alongside the other
        // configuration errors. A typo has to exit 2 rather than quietly drop
        // the guard — the flag exists precisely to catch a shard that never
        // reported, so a silently inert one is worse than none.
        $expectSidecars = null;
        if (isset($options['expect_sidecars'])) {
            $rawExpectSidecars = $options['expect_sidecars'];
            if (!is_int($rawExpectSidecars) || $rawExpectSidecars < 1) {
                $this->writeStderr(sprintf(
                    "[Gesso] FATAL: --expect-sidecars=%s is not a positive integer.\n",
                    (string) $rawExpectSidecars,
                ));

                return 2;
            }
            $expectSidecars = $rawExpectSidecars;
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

        // Issue #434: a shard that was cancelled, or that died before
        // ExecutionFinished, leaves neither a sidecar nor a failure marker —
        // the marker only covers "ran but could not write". Comparing against
        // a count the user knows up front is the only way that absence is
        // visible from here. Evaluated before the empty-payloads branch below
        // so "every shard died" fails for the same reason as "one shard did",
        // and before cleanup so the surviving sidecars stay for a retry.
        if ($expectSidecars !== null && count($payloads) !== $expectSidecars) {
            $this->writeStderr(sprintf(
                "[Gesso] FATAL: expected %d sidecar(s) in %s but found %d; the merged report would %s. Check for a cancelled or failed shard, or correct --expect-sidecars.\n",
                $expectSidecars,
                $sidecarDir,
                count($payloads),
                count($payloads) < $expectSidecars
                    ? 'under-count coverage'
                    : 'include sidecars from another run',
            ));

            return 1;
        }

        if ($payloads === []) {
            // Issue #417: a generation run that produced no sidecars cannot
            // yield a baseline — but the workers already demoted every
            // failure, so returning 0 without a file would hide all of them.
            if ($baselineFile !== null) {
                $this->writeStderr(sprintf(
                    "[Gesso] FATAL: --baseline-file requested but no sidecars were found in %s; no baseline was written. Run the parallel suite with GESSO_BASELINE_GENERATE=1 first.\n",
                    $sidecarDir,
                ));

                return 1;
            }

            if ($coverageBaselineFile !== null) {
                $this->writeStderr(sprintf(
                    "[Gesso] FATAL: --coverage-baseline-file was given but no sidecars were found in %s; the coverage baseline cannot be %s.\n",
                    $sidecarDir,
                    $coverageBaselineGenerate ? 'generated' : 'evaluated',
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
        $sidecarsWithoutDeprecations = 0;
        $deprecationStates = [];
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
                if ($parsed['deprecations'] !== null) {
                    $deprecationStates[] = $parsed['deprecations'];
                } else {
                    $sidecarsWithoutDeprecations++;
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

        // Reported before the gates below, and before any of them can return
        // non-zero: a parallel run's deprecation report is the only place the
        // residual count surfaces at all, so it must not depend on the merge
        // succeeding. Issue #499.
        $this->reportDeprecations($deprecationStates, $sidecarsWithoutDeprecations, count($payloads));

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

        // CLI severity: a failed report write is FATAL and counts toward the exit code.
        $writer = new CoverageReportWriter($this->writeStderr(...), 'FATAL');
        $results = $writer->computeResults($specs, $coverageTracker);
        $hasSdkState = $sidecarsWithoutSdkExercise < count($payloads);
        $sdkResults = $hasSdkState || $minSdkExercisePct !== null
            ? $writer->computeSdkResults($specs, $sdkExerciseCoverageTracker)
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

            if ($coverageBaselineFile !== null) {
                // The uncovered set would be empty for lack of data, not for
                // lack of gaps — enforcing it would pass a run that validated
                // nothing, generating it would write an empty baseline.
                $this->writeStderr(
                    "[Gesso] FATAL: no contract test coverage was recorded; the coverage baseline cannot be evaluated.\n",
                );
            }

            if ($cleanup && !$this->cleanup($sidecarDir)) {
                return 1;
            }

            return $strictGated || $strictAdditionalPropertiesFailure || $coverageBaselineFile !== null ? 1 : 0;
        }

        $this->writeStdout(ConsoleCoverageRenderer::render($results, $consoleOutput, $sdkResults));

        $writeFailures = $writer->writeReports($results, $sdkResults, $outputFile, $junitOutput, $jsonOutput, $htmlOutput);
        $writer->appendGithubStepSummary($results, $sdkResults, $githubSummaryPath);

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

        $coverageBaselineFailure = $coverageBaselineFile !== null && !$this->handleCoverageBaseline(
            $coverageBaselineFile,
            $coverageBaselineGenerate,
            $coverageBaselineStaleMode,
            $results,
        );

        // Issue #481, mirroring the violation baseline's fail-before-cleanup
        // contract: keep the sidecars when the coverage baseline step failed.
        // Every recovery re-runs *this command*, not the suite — fixing a
        // typo'd path, freeing disk for the write, or accepting the reported
        // regressions with `GESSO_BASELINE_GENERATE=1`. Deleting the
        // sidecars would make each of those cost a full parallel run.
        $cleanupFailure = $cleanup &&
            !$coverageBaselineFailure &&
            !$this->cleanup($sidecarDir);
        if ($coverageBaselineFailure && $cleanup) {
            $this->writeStderr(sprintf(
                "[Gesso] Sidecars kept in %s so the merge can be retried without re-running the suite.\n",
                $sidecarDir,
            ));
        }

        return $writeFailures > 0 ||
            $thresholdFailure ||
            $strictFailure ||
            $strictAdditionalPropertiesFailure ||
            $coverageBaselineFailure ||
            $cleanupFailure
            ? 1
            : 0;
    }

    /** An option's absolutised path, or `null` when absent or empty. */
    private function pathOption(?string $value): ?string
    {
        return $value !== null && $value !== '' ? $this->absolutise($value) : null;
    }

    /**
     * Issue #499: report the deprecation counts the workers staged, summed
     * across the fleet. Silence means no worker used a deprecated surface —
     * the documented "ready for the next major" signal — so a fleet that
     * cannot prove that has to say so instead of printing nothing.
     *
     * A sidecar written by a pre-#499 worker carries no deprecation half,
     * which is indistinguishable from "that worker used none". The note is
     * therefore emitted whether or not the merged count is empty: an
     * incomplete zero and an incomplete non-zero are both unsafe to read as
     * the readiness verdict.
     *
     * @param list<array<string, mixed>> $states
     */
    private function reportDeprecations(array $states, int $sidecarsWithoutDeprecations, int $sidecarCount): void
    {
        try {
            $merged = Deprecations::mergeStates($states);
        } catch (InvalidArgumentException $e) {
            // Never fatal: a malformed deprecation half must not fail a merge
            // whose coverage and gate data parsed cleanly. Report it instead,
            // because the alternative is silence that reads as "no
            // deprecations".
            $this->writeStderr(sprintf(
                "%s WARNING: could not read the staged deprecation state: %s The residual count is not reported for this run.\n",
                Deprecations::PREFIX,
                $e->getMessage(),
            ));

            return;
        }

        $summary = Deprecations::renderSummary($merged);
        if ($summary !== null) {
            $this->writeStderr($summary);
        }

        if ($sidecarsWithoutDeprecations === 0) {
            return;
        }

        $this->writeStderr(sprintf(
            "%s NOTE: %d of %d worker sidecar(s) carry no deprecation state (written by a Gesso version older than the deprecation channel), so %s. Upgrade every worker to the same version before reading this as a v3-readiness verdict.\n",
            Deprecations::PREFIX,
            $sidecarsWithoutDeprecations,
            $sidecarCount,
            $summary === null
                ? 'the absence of a deprecation report above does not prove the suite uses none'
                : 'the counts above are a lower bound',
        ));
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
                "[Gesso] FATAL: %d of %d sidecar(s) carry violation-baseline data from an GESSO_BASELINE_GENERATE run, but --baseline-file was not given; discarding it would silently hide the demoted violations. Re-run the merge with --baseline-file=<path>.\n",
                $sidecarsWithBaseline,
                $sidecarCount,
            ));

            return false;
        }

        if ($sidecarsWithoutBaseline > 0) {
            $this->writeStderr(sprintf(
                "[Gesso] FATAL: --baseline-file requested but %d of %d sidecar(s) carry no baseline data; the union would be an incomplete baseline. Ensure every worker ran with GESSO_BASELINE_GENERATE=1 on a library version that stages baseline sidecars. No baseline was written.\n",
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
     * Issue #481: generate or enforce the coverage baseline against the
     * merged whole-suite coverage state. Returns `false` when the merge must
     * exit non-zero.
     *
     * Unlike the sequential path this has no view of whether the suite
     * passed, so a red parallel run can report responses of failed tests as
     * newly uncovered. That is the same blind spot the threshold gate has
     * here, and the merge already runs after a suite whose exit code CI
     * checks separately.
     *
     * @param array<string, CoverageResult> $results
     */
    private function handleCoverageBaseline(
        string $baselineFile,
        bool $generate,
        BaselineStaleMode $staleMode,
        array $results,
    ): bool {
        if ($results === []) {
            $this->writeStderr(
                "[Gesso] FATAL: no contract test coverage was recorded; the coverage baseline cannot be evaluated.\n",
            );

            return false;
        }

        if ($generate) {
            $collected = CoverageBaselineEvaluator::collect($results);

            try {
                CoverageBaselineFile::write($baselineFile, $collected);
            } catch (RuntimeException $e) {
                $this->writeStderr("[Gesso] FATAL: {$e->getMessage()}\n");

                return false;
            }

            $this->writeStderr(sprintf(
                "[Gesso] Coverage baseline written: %d uncovered response(s) → %s\n",
                $collected->count(),
                $baselineFile,
            ));

            return true;
        }

        try {
            $baseline = CoverageBaselineFile::read($baselineFile);
        } catch (InvalidArgumentException $e) {
            $this->writeStderr(
                "[Gesso] FATAL: --coverage-baseline-file could not be loaded: {$e->getMessage()}\n",
            );

            return false;
        }

        $verdict = CoverageBaselineEvaluator::evaluate($baseline, $results);

        $this->writeStderr(sprintf(
            "[Gesso] coverage baseline: %d entries, %d uncovered response(s) in this run, %d covered now.\n",
            $baseline->count(),
            $verdict['uncovered'],
            count($verdict['stale']),
        ));

        if ($verdict['regressions'] !== []) {
            $this->writeStderr(CoverageBaselineEvaluator::renderRegressionMessage(
                $verdict['regressions'],
                'GESSO_BASELINE_GENERATE=1 ' . $this->invocation,
            ));

            return false;
        }

        if ($verdict['stale'] === [] || $staleMode === BaselineStaleMode::Off) {
            return true;
        }

        $isFatal = $staleMode === BaselineStaleMode::Fail;
        $this->writeStderr(CoverageBaselineEvaluator::renderStaleMessage($verdict['stale'], $isFatal));

        return !$isFatal;
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

        $parsed = CoverageThresholdEvaluator::parseThreshold($name, (string) $value);
        if (is_string($parsed)) {
            $this->writeCoverageDiagnostic($strict ? 'FATAL' : 'WARNING', $parsed . '; skipping threshold gate.');

            return ['value' => null, 'fatal' => $strict];
        }

        return ['value' => $parsed, 'fatal' => false];
    }

    /**
     * Delete the merged sidecars and markers. Returns `false` when the
     * directory could not be inspected, so the caller exits non-zero.
     */
    private function cleanup(string $sidecarDir): bool
    {
        try {
            $paths = [
                ...CoverageSidecarReader::listPaths($sidecarDir),
                ...CoverageSidecarReader::listFailureMarkerPaths($sidecarDir),
            ];
        } catch (RuntimeException $e) {
            $this->writeCoverageDiagnostic(
                'FATAL',
                sprintf('failed to inspect sidecars for cleanup: %s', $e->getMessage()),
            );

            return false;
        }

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

        return true;
    }

    private function writeCoverageDiagnostic(string $severity, string $detail): void
    {
        $this->writeStderr(sprintf("[OpenAPI Coverage] %s: %s\n", $severity, $detail));
    }
}
