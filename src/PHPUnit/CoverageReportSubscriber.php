<?php

declare(strict_types=1);

namespace Studio\Gesso\PHPUnit;

use const STDERR;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use RuntimeException;
use Studio\Gesso\Baseline\BaselineStaleMode;
use Studio\Gesso\Baseline\CoverageBaseline;
use Studio\Gesso\Baseline\CoverageBaselineEvaluator;
use Studio\Gesso\Baseline\CoverageBaselineFile;
use Studio\Gesso\Baseline\ViolationBaselineCollector;
use Studio\Gesso\Baseline\ViolationBaselineEnforcer;
use Studio\Gesso\Baseline\ViolationBaselineFile;
use Studio\Gesso\Coverage\ConsoleCoverageRenderer;
use Studio\Gesso\Coverage\CoverageSidecarEnvelope;
use Studio\Gesso\Coverage\CoverageSidecarWriter;
use Studio\Gesso\Coverage\CoverageThresholdEvaluator;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Coverage\SdkExerciseCoverageReportBuilder;
use Studio\Gesso\Coverage\SdkExerciseCoverageTracker;
use Studio\Gesso\Exception\InvalidOpenApiSpecException;
use Studio\Gesso\Exception\InvalidOpenApiSpecReason;
use Studio\Gesso\Internal\CoverageReportWriter;
use Studio\Gesso\Internal\Deprecations;
use Studio\Gesso\Internal\PartialRunDecision;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Strict\StrictAdditionalPropertiesAsserter;
use Studio\Gesso\Validation\Strict\StrictAdditionalPropertiesMode;
use Studio\Gesso\Validation\Strict\StrictAdditionalPropertiesTracker;
use Studio\Gesso\Validation\Strict\StrictRequiredAsserter;
use Studio\Gesso\Validation\Strict\StrictRequiredMode;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;

use function count;
use function fflush;
use function getenv;
use function implode;
use function is_callable;
use function sprintf;
use function trim;

/**
 * @phpstan-import-type CoverageResult from OpenApiCoverageTracker
 * @phpstan-import-type SdkExerciseCoverageResult from SdkExerciseCoverageReportBuilder
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final readonly class CoverageReportSubscriber implements ExecutionFinishedSubscriber
{
    private OpenApiCoverageTracker $coverageTracker;
    private StrictAdditionalPropertiesTracker $strictAdditionalPropertiesTracker;
    private StrictRequiredTracker $strictRequiredTracker;
    private SdkExerciseCoverageTracker $sdkExerciseCoverageTracker;
    private ?string $specBasePath;

    /** @var string[] */
    private array $specStripPrefixes;
    private ?string $enumBasePath;

    /**
     * @param string[] $specs
     * @param null|OpenApiCoverageTracker $coverageTracker Production callers (the PHPUnit extension) pass the
     *                                                     run-level instance they own. Passing `null` resolves
     *                                                     once at construction via {@see OpenApiCoverageTracker::current()},
     *                                                     so the field remains non-null after the ctor and the
     *                                                     `readonly` invariant holds end-to-end.
     * @param null|StrictRequiredTracker $strictRequiredTracker Same shape as $coverageTracker.
     * @param null|callable(string): void $stderrWriter Optional sink for warnings (stale/invalid specs,
     *                                                  failed file_put_contents). Falls back to {@see OpenApiCoverageExtension::writeStderr()} when
     *                                                  null. Injected for testability — the extension stays the default backstop in production.
     * @param null|string $sidecarDir Directory the worker-mode branch will drop its JSON sidecar into. When
     *                                {@see self::resolveWorkerToken()} finds a token — `TEST_TOKEN` from paratest,
     *                                or `GESSO_SIDECAR_TOKEN` naming a CI shard — the subscriber short-circuits
     *                                rendering and writes the tracker state here for the merge CLI to pick up.
     *                                `null` falls back to a default under `sys_get_temp_dir()`.
     * @param null|float $minEndpointCoverage Optional gate: when not null and `endpointFullyCovered/endpointTotal`
     *                                        (rolled across `$specs`) is below this percent, the subscriber prints
     *                                        a FAIL/WARN line. See issue #135.
     * @param null|float $minResponseCoverage Same idea, but at `(method, path, status, content-type)` granularity.
     * @param null|float $minSdkExerciseCoverage Gate SDK decoder exercise at eligible response-schema granularity.
     * @param bool $minCoverageStrict Treat threshold misses as exit non-zero (default warn-only).
     * @param null|callable(int): void $exitHandler Test seam for the strict-miss exit. Defaults to native `exit()`
     *                                              so production behavior matches PHPUnit's own coverage gate.
     * @param null|string $baselineGeneratePath Issue #402: destination of a violation-baseline generation run
     *                                          (`GESSO_BASELINE_GENERATE`). When non-null the subscriber writes the
     *                                          collected fingerprints here at run end; partial runs refuse the write
     *                                          (an incomplete baseline would hide violations). Worker mode instead
     *                                          stages the fingerprints in the sidecar envelope for
     *                                          `gesso coverage:merge --baseline-file` to union (issue #417).
     * @param null|TestRunCompletionTracer $baselineCompletionTracer Issue #402: registered by the extension for
     *                                                               enforcement runs. Unless it can prove every
     *                                                               planned test finished with no defects, stale
     *                                                               evaluation is skipped — an unhit baseline entry
     *                                                               proves nothing if later assertions never ran.
     * @param null|PartialRunDecision $partialRun Issue #221: when non-null (the run is partial), the subscriber
     *                                            skips every persistent file write (output_file, junit_output,
     *                                            json_output, html_output, GITHUB_STEP_SUMMARY) and emits one
     *                                            stderr WARNING listing the skipped targets. Issue #438: the
     *                                            threshold gate is skipped too (with a one-line NOTE) — a subset
     *                                            cannot prove a suite-wide coverage rate, so evaluating it would
     *                                            fail every suite-selecting CI shard. Console rendering is
     *                                            unaffected — it reads in-memory state and is transient. `null`
     *                                            (the backwards-compat default) means full-run behavior.
     * @param null|CoverageBaseline $coverageBaseline Issue #481: the committed set of known-uncovered responses,
     *                                                loaded and validated by the extension. Non-null puts the run in
     *                                                coverage-baseline enforcement — an uncovered response missing
     *                                                from the set fails the run by name, independent of any
     *                                                percentage.
     * @param null|string $coverageBaselineGeneratePath Issue #481: destination of a coverage-baseline generation run
     *                                                  (`GESSO_BASELINE_GENERATE`, shared with the violation
     *                                                  baseline). Mutually exclusive with $coverageBaseline.
     * @param BaselineStaleMode $coverageBaselineStaleMode Issue #481: how entries that are covered now — the
     *                                                     ratchet-down signal — are reported.
     */
    public function __construct(
        private array $specs,
        private ?string $outputFile,
        private ConsoleOutput $consoleOutput,
        private ?string $githubSummaryPath,
        ?OpenApiCoverageTracker $coverageTracker = null,
        ?StrictRequiredTracker $strictRequiredTracker = null,
        private mixed $stderrWriter = null,
        private ?string $sidecarDir = null,
        private ?float $minEndpointCoverage = null,
        private ?float $minResponseCoverage = null,
        private ?float $minSdkExerciseCoverage = null,
        private bool $minCoverageStrict = false,
        private mixed $exitHandler = null,
        private ?string $junitOutput = null,
        private ?string $jsonOutput = null,
        private ?string $htmlOutput = null,
        private ?PartialRunDecision $partialRun = null,
        private StrictRequiredMode $strictRequiredMode = StrictRequiredMode::Off,
        private ?string $baselineGeneratePath = null,
        private BaselineStaleMode $baselineStaleMode = BaselineStaleMode::Note,
        private ?TestRunCompletionTracer $baselineCompletionTracer = null,
        ?StrictAdditionalPropertiesTracker $strictAdditionalPropertiesTracker = null,
        private StrictAdditionalPropertiesMode $strictAdditionalPropertiesMode = StrictAdditionalPropertiesMode::Off,
        ?SdkExerciseCoverageTracker $sdkExerciseCoverageTracker = null,
        private ?CoverageBaseline $coverageBaseline = null,
        private ?string $coverageBaselineGeneratePath = null,
        private BaselineStaleMode $coverageBaselineStaleMode = BaselineStaleMode::Note,
    ) {
        // Eager resolution at construction time keeps the readonly invariant
        // honest: by the time any other method runs, $coverageTracker and
        // $strictRequiredTracker are guaranteed non-null and pinned. Tests
        // can still pass `null` (or omit the args) and inherit whatever the
        // process-global locator was wired with at the call site, but the
        // subscriber's runtime view does not flip-flop between an injected
        // ref and a live `current()` lookup.
        $this->coverageTracker = $coverageTracker ?? OpenApiCoverageTracker::current();
        $this->strictRequiredTracker = $strictRequiredTracker ?? StrictRequiredTracker::current();
        $this->strictAdditionalPropertiesTracker = $strictAdditionalPropertiesTracker ?? StrictAdditionalPropertiesTracker::current();
        $this->sdkExerciseCoverageTracker = $sdkExerciseCoverageTracker ?? SdkExerciseCoverageTracker::current();

        $specBasePath = null;
        $specStripPrefixes = [];
        $enumBasePath = null;

        try {
            $specBasePath = OpenApiSpecLoader::getBasePath();
            $specStripPrefixes = OpenApiSpecLoader::getStripPrefixes();
            $enumBasePath = OpenApiSpecLoader::getEnumBasePath();
        } catch (InvalidOpenApiSpecException $e) {
            if ($e->reason !== InvalidOpenApiSpecReason::BasePathNotConfigured) {
                throw $e;
            }
        }

        $this->specBasePath = $specBasePath;
        $this->specStripPrefixes = $specStripPrefixes;
        $this->enumBasePath = $enumBasePath;
    }

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

        $this->restoreSpecLoaderConfigurationIfReset();

        // Written before the gates below because `strict_*` in fail mode can
        // terminate the process; the residual count is the migration signal for
        // the next major and must not depend on the run passing.
        $this->reportDeprecations();

        $results = $this->computeAllResults();
        $sdkResults = $this->computeAllSdkResults();

        // Free cached spec data now that coverage has been computed
        OpenApiSpecLoader::clearCache();

        if ($results === [] && $sdkResults === []) {
            // C2: a strict CI gate must not silently pass when zero contract
            // assertions ran. Pre-fix, this branch quietly returned 0 even
            // though the user had opted into fail-fast via min_*_coverage.
            $this->failOnEmptyResultsIfGated();
            $this->writeBaselineFile();
            $this->reportBaselineEnforcement();
            $this->handleCoverageBaseline($results);
            $this->evaluateStrictRequiredGate();
            $this->evaluateStrictAdditionalPropertiesGate();

            return;
        }

        echo ConsoleCoverageRenderer::render($results, $this->consoleOutput, $sdkResults);

        $this->writeReports($results, $sdkResults);

        $this->writeBaselineFile();

        $this->reportBaselineEnforcement();

        if ($results === [] && ($this->minEndpointCoverage !== null || $this->minResponseCoverage !== null)) {
            $this->failOnEmptyResultsIfGated(httpOnly: true);
            if (!$this->minCoverageStrict && $this->partialRun === null) {
                $this->evaluateThresholdGate($results, $sdkResults, includeHttp: false);
            }
        } else {
            $this->evaluateThresholdGate($results, $sdkResults);
        }

        $this->handleCoverageBaseline($results);

        // Issue #224: schema under-description detection runs after coverage
        // rendering so a strict-mode fail does not suppress the coverage
        // report users rely on for triaging the failure.
        $this->evaluateStrictRequiredGate();
        $this->evaluateStrictAdditionalPropertiesGate();
    }

    /**
     * Resolve the token that puts this process into sidecar-only mode, or
     * `null` for the normal in-process rendering path.
     *
     * Two sources, in priority order:
     *
     *  - `GESSO_SIDECAR_TOKEN` (issue #434): the explicit opt-in for any
     *    process. Sharding a suite across CI *jobs* produces the same "N
     *    processes each hold a slice" situation paratest produces, but
     *    nothing in the environment says so — so the user names the shard
     *    and that name is both the request to export and the sidecar's
     *    identity. One knob: an export mode with no name could not produce
     *    distinct filenames across runners, and a name with no export mode
     *    would do nothing. It wins over `TEST_TOKEN` so a sharded job that
     *    *also* runs paratest namespaces its sidecars by shard; the pid in
     *    the filename keeps that job's workers apart.
     *  - `TEST_TOKEN`: set by paratest in every worker process (currently a
     *    1..N slot index) and unset for sequential PHPUnit runs. Runners
     *    that wrap paratest (e.g. Pest `--parallel`) inherit it, so no
     *    per-runner detection is needed.
     */
    private static function resolveWorkerToken(): ?string
    {
        foreach (['GESSO_SIDECAR_TOKEN', 'TEST_TOKEN'] as $name) {
            $token = getenv($name);
            if ($token !== false && trim($token) !== '') {
                return $token;
            }
        }

        return null;
    }

    /**
     * Run the strict_required asserter against the tracker state and route
     * the message to stderr + GitHub Step Summary. `Fail` mode terminates
     * the process so paratest CI surfaces the non-zero exit; `Warn` mode
     * leaves the run successful but prints the diagnostic block.
     *
     * Off mode, "no drift", partial-run, and unresolved-only short-circuit
     * cleanly so this method is safe to invoke unconditionally from the
     * sequential branch.
     */
    private function evaluateStrictRequiredGate(): void
    {
        if ($this->strictRequiredMode === StrictRequiredMode::Off) {
            return;
        }

        // Issue #221 alignment: strict_required's intersection is reliable
        // only when the full suite ran. A `--filter` subset can show a key
        // as "always present" simply because the broader suite's omissions
        // were excluded from the run. Skip the gate and emit a one-line
        // NOTE so the user understands why no drift block appeared.
        if ($this->partialRun !== null) {
            $this->writeStderr(
                '[OpenAPI Strict Required] NOTE: strict_required is skipped on partial runs (--filter / --testsuite / etc.) '
                . "because the intersection requires the full suite to be reliable. Run without filters to evaluate the gate.\n",
            );

            return;
        }

        $reports = StrictRequiredAsserter::detectAll($this->strictRequiredMode);
        $unresolved = StrictRequiredAsserter::detectUnresolvedGroups($this->strictRequiredMode);
        $unwalkable = StrictRequiredAsserter::detectUnwalkableNodes($this->strictRequiredMode);

        if ($unresolved !== []) {
            // Validator only records on Success, so reaching this branch
            // means a spec lookup miss the user cannot diagnose by reading
            // the no-drift output. Surface every offender so they can
            // distinguish "no drift" from "no schema to compare against".
            $this->writeStderr(sprintf(
                "[OpenAPI Strict Required] NOTE: %d observation group(s) had no matching response schema; skipped from drift detection:\n  - %s\n",
                count($unresolved),
                implode("\n  - ", $unresolved),
            ));
        }

        if ($unwalkable !== []) {
            // Pointers landed on schema nodes (anyOf / oneOf) where
            // "required" has no AND-semantic; "add to required" drift
            // advice would actively mislead. Surface as a NOTE so users
            // can pin those shapes via `allOf` or accept the gap.
            $this->writeStderr(sprintf(
                "[OpenAPI Strict Required] NOTE: %d observation pointer(s) landed on disjunction (anyOf/oneOf) schema nodes; skipped from drift detection because `required` is not safely AND-mergeable across disjunctions. Pin the shape with `allOf` if you need strict_required coverage there:\n  - %s\n",
                count($unwalkable),
                implode("\n  - ", $unwalkable),
            ));
        }

        if ($reports === []) {
            return;
        }

        $isFatal = $this->strictRequiredMode === StrictRequiredMode::Fail;
        $message = StrictRequiredAsserter::renderMessage($reports, $isFatal);
        $this->writeStderr($message . "\n");
        OpenApiCoverageExtension::appendGithubStepSummaryStrictRequiredBlock(
            $this->githubSummaryPath,
            $message,
            $isFatal,
        );

        if (!$isFatal) {
            return;
        }

        $this->exitNonZero();
    }

    private function evaluateStrictAdditionalPropertiesGate(): void
    {
        if ($this->strictAdditionalPropertiesMode === StrictAdditionalPropertiesMode::Off) {
            return;
        }

        $reports = StrictAdditionalPropertiesAsserter::detectAll($this->strictAdditionalPropertiesTracker);
        if ($reports === []) {
            return;
        }

        $isFatal = $this->strictAdditionalPropertiesMode === StrictAdditionalPropertiesMode::Fail;
        $message = StrictAdditionalPropertiesAsserter::renderMessage($reports, $isFatal);
        $this->writeStderr($message . "\n");
        OpenApiCoverageExtension::appendGithubStepSummaryStrictAdditionalPropertiesBlock(
            $this->partialRun === null ? $this->githubSummaryPath : null,
            $message,
            $isFatal,
        );

        if (!$isFatal) {
            return;
        }
        $this->exitNonZero();
    }

    /**
     * Issue #135: in sequential PHPUnit, evaluate the optional coverage
     * threshold after the report renders. Worker mode never reaches here
     * (the worker-token branch returns earlier) — the merge CLI is the gate
     * for paratest, so this method runs only on the in-process path.
     *
     * @param array<string, CoverageResult> $results
     * @param array<string, SdkExerciseCoverageResult> $sdkResults
     */
    private function evaluateThresholdGate(
        array $results,
        array $sdkResults,
        bool $includeHttp = true,
    ): void {
        if (
            (!$includeHttp || $this->minEndpointCoverage === null) &&
            (!$includeHttp || $this->minResponseCoverage === null) &&
            $this->minSdkExerciseCoverage === null
        ) {
            return;
        }

        // Issue #438 alignment with the other partial-aware gates: a subset
        // run cannot prove a suite-wide coverage rate — `--testsuite=Feature`
        // reports the Feature-only rate against a threshold set for the whole
        // suite. Skip and say why, matching strict_required's shape.
        if ($this->partialRun !== null) {
            $this->emitThresholdGatePartialRunNote();

            return;
        }

        $evaluation = CoverageThresholdEvaluator::evaluate(
            $results,
            $sdkResults,
            $includeHttp ? $this->minEndpointCoverage : null,
            $includeHttp ? $this->minResponseCoverage : null,
            $this->minSdkExerciseCoverage,
            $this->minCoverageStrict,
        );

        if ($evaluation['passed']) {
            return;
        }

        $this->writeStderr($evaluation['message']);

        if (!$this->minCoverageStrict) {
            return;
        }

        $this->exitNonZero();
    }

    /**
     * Issue #135 review C2: when no spec produced any coverage, the
     * regular gate path never runs (the evaluator would receive an empty
     * results array and report 100% vacuously). A strict run must still
     * fail-fast — otherwise a CI that opted into the gate silently passes
     * when its tests didn't actually validate anything.
     */
    private function failOnEmptyResultsIfGated(bool $httpOnly = false): void
    {
        if (
            $this->minEndpointCoverage === null &&
            $this->minResponseCoverage === null &&
            ($httpOnly || $this->minSdkExerciseCoverage === null)
        ) {
            return;
        }

        // Issue #438: `--testsuite=Unit` records zero contract coverage —
        // correctly. There is nothing wrong with the run; the gate simply
        // cannot be evaluated from it, so failing (or even warning) here
        // would punish every suite-selecting CI shard.
        if ($this->partialRun !== null) {
            $this->emitThresholdGatePartialRunNote();

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

        $this->exitNonZero();
    }

    /**
     * One-line NOTE mirroring the strict_required partial-run skip, so a
     * user who configured the gate can see why no FAIL/WARN/FATAL line
     * appeared. Callers guarantee a threshold is configured and
     * `$partialRun` is non-null. Uses the branded `[Gesso]` prefix per the
     * v2 identity policy: identity-neutral categories (here
     * `[OpenAPI Coverage]`) are frozen at their v1.9 shape.
     */
    private function emitThresholdGatePartialRunNote(): void
    {
        $partialRun = $this->partialRun;
        if ($partialRun === null) {
            return;
        }

        $this->writeStderr(sprintf(
            '[Gesso] NOTE: the coverage threshold gate is skipped on partial runs (%s) '
            . "because a subset cannot prove a suite-wide coverage rate. Run the full suite to evaluate the gate.\n",
            $partialRun->reason,
        ));
    }

    private function writeWorkerSidecar(string $token): void
    {
        $dir = $this->sidecarDir ?? OpenApiCoverageExtension::defaultSidecarDir();

        // Issue #417: a generation-mode worker hands its collected
        // fingerprints to the merge CLI through the envelope's baseline
        // half — the per-worker view is a subset, so only the merged union
        // may reach ViolationBaselineFile::write(). One guidance line per
        // worker keeps a forgotten merge step from silently discarding the
        // demoted failures.
        $baselineDocument = null;
        $collector = $this->baselineGeneratePath === null ? null : ViolationBaselineCollector::current();
        if ($collector !== null) {
            $baselineDocument = ViolationBaselineFile::toDocument($collector->baseline());
            $this->writeStderr(sprintf(
                "[Gesso] baseline: %d violation(s) staged in this worker's sidecar. Run `gesso coverage:merge --baseline-file=%s` after the parallel run to write the merged baseline.\n",
                $collector->baseline()->count(),
                $this->baselineGeneratePath,
            ));
        }

        // Sidecar envelope carries coverage and strict_required observations
        // so the merge CLI can aggregate the gate across all paratest
        // workers. The strict_required half is always exported, independent
        // of the worker's `strict_required` mode — the merge CLI decides
        // whether to assert (Issue #226).
        //
        // Issue #499: the deprecation half travels the same way. A worker
        // returns before the end-of-run report, so without this the residual
        // count would be empty for every parallel run — and an empty count is
        // exactly the "ready for the next major" signal.
        $envelope = CoverageSidecarEnvelope::build(
            coverageState: $this->coverageTracker->exportStateOn(),
            strictRequiredState: $this->strictRequiredTracker->exportStateOn(),
            baselineDocument: $baselineDocument,
            strictAdditionalPropertiesState: $this->strictAdditionalPropertiesTracker->exportStateOn(),
            sdkExerciseState: $this->sdkExerciseCoverageTracker->exportStateOn(),
            deprecationsState: Deprecations::exportState(),
        );

        try {
            CoverageSidecarWriter::write($dir, $token, $envelope);
        } catch (RuntimeException $e) {
            // The contract assertion that triggered notify() has already
            // passed; we don't fail the test run on sidecar I/O. But we
            // MUST drop a failure marker so the downstream merge CLI can
            // detect this worker is missing and exit non-zero. Without the
            // marker the merge would silently under-count coverage by one
            // worker's worth of data.
            $this->writeStderr("[OpenAPI Coverage] WARNING: failed to write sidecar (token={$token}): {$e->getMessage()}\n");
            CoverageSidecarWriter::writeFailureMarker($dir, $token, $e->getMessage());

            // Issue #417 / #481: a lost sidecar cannot be recovered by the
            // marker alone — the marker write is itself best-effort, and
            // when it also fails (full disk, revoked permissions) the merge
            // sees N-1 complete halves and writes an incomplete baseline.
            // For the violation baseline that silently drops failures this
            // worker already demoted; for the coverage baseline it records
            // this worker's covered responses as uncovered, permanently
            // loosening the ratchet. Either way, fail the worker so the
            // parallel run cannot end green.
            //
            // Only generation runs need this: an enforcing worker stages
            // nothing, and a merge missing one worker's coverage fails loudly
            // with the "newly uncovered" listing instead of writing a file.
            $lostGeneration = match (true) {
                $baselineDocument !== null => "this worker's violations",
                $this->coverageBaselineGeneratePath !== null => "this worker's covered responses",
                default => null,
            };
            if ($lostGeneration !== null) {
                $this->writeStderr(sprintf(
                    "[Gesso] FATAL: baseline generation could not stage %s in the sidecar; failing the worker so the parallel run does not produce an incomplete baseline.\n",
                    $lostGeneration,
                ));
                $this->exitNonZero();
            }
        }
    }

    /**
     * Issue #402: persist the violation baseline collected during a
     * generation run. A partial run refuses the write — a subset run would
     * produce an incomplete baseline that then fails CI on every violation
     * the filtered-out tests would have recorded — and exits non-zero so
     * the generation invocation surfaces the refusal instead of looking
     * like a successful (empty) generation.
     */
    private function writeBaselineFile(): void
    {
        if ($this->baselineGeneratePath === null) {
            return;
        }

        $collector = ViolationBaselineCollector::current();
        if ($collector === null) {
            // The extension installs the collector together with the path,
            // so a missing collector means a test seam constructed the
            // subscriber directly; nothing was recorded, nothing to write.
            return;
        }

        if ($this->partialRun !== null) {
            $this->writeStderr(sprintf(
                "[Gesso] WARNING: baseline generation refused on a partial run (%s) — a subset run would write an incomplete baseline. No file was written.\n",
                $this->partialRun->reason,
            ));
            $this->exitNonZero();

            return;
        }

        try {
            ViolationBaselineFile::write($this->baselineGeneratePath, $collector->baseline());
        } catch (RuntimeException $e) {
            $this->writeStderr("[Gesso] WARNING: {$e->getMessage()}\n");
            $this->exitNonZero();

            return;
        }

        $this->writeStderr(sprintf(
            "[Gesso] Baseline written: %d violation(s) → %s\n",
            $collector->baseline()->count(),
            $this->baselineGeneratePath,
        ));
    }

    /**
     * Write the one-line residual deprecation count, or nothing when no
     * deprecated surface was used in this run. {@see Deprecations::summaryLine()}
     * owns the wording; the subscriber only decides where it goes and that it
     * is stderr, for the reason recorded on `writeStderr()`.
     */
    private function reportDeprecations(): void
    {
        $line = Deprecations::summaryLine();
        if ($line === null) {
            return;
        }

        $this->writeStderr($line);
    }

    /**
     * Issue #402: end-of-run summary for an enforcement run — how much
     * baselined debt exists, how much of it still occurs, and which entries
     * no longer occur (stale, removable — the ratchet-down signal, matching
     * PHPStan's baseline behavior).
     *
     * Stale evaluation needs the full suite: a subset run cannot prove an
     * entry no longer occurs, so partial runs report entries/hits only and
     * never trip the `baseline_stale=fail` gate. Worker mode never reaches
     * this method (the worker-token branch returns earlier), so per-worker
     * partial views cannot mis-report staleness either.
     */
    private function reportBaselineEnforcement(): void
    {
        $enforcer = ViolationBaselineEnforcer::current();
        if ($enforcer === null) {
            return;
        }

        $entries = $enforcer->baseline()->count();
        $hits = $enforcer->hitCount();

        if ($this->partialRun !== null) {
            $this->writeStderr(sprintf(
                "[Gesso] baseline: %d entries, %d hit. NOTE: stale evaluation is skipped on partial runs (%s) because a subset run cannot prove an entry no longer occurs. Run the full suite to evaluate removable entries.\n",
                $entries,
                $hits,
                $this->partialRun->reason,
            ));

            return;
        }

        if ($this->baselineStaleMode === BaselineStaleMode::Off) {
            $this->writeStderr(sprintf("[Gesso] baseline: %d entries, %d hit.\n", $entries, $hits));

            return;
        }

        // Stale evaluation is only trustworthy when the tracer can prove
        // every planned test finished with no defects. A truncated run
        // (--stop-on-* of any kind, hook failures) or a failed / errored /
        // skipped / incomplete test means later assertions may never have
        // run, so an unhit entry proves nothing — reporting it as removable
        // would mark still-live debt for deletion.
        if ($this->baselineCompletionTracer !== null && !$this->baselineCompletionTracer->completedCleanly()) {
            $this->writeStderr(sprintf(
                "[Gesso] baseline: %d entries, %d hit. NOTE: stale evaluation is skipped because the run did not complete cleanly (%s). Re-run with all tests passing to evaluate removable entries.\n",
                $entries,
                $hits,
                $this->baselineCompletionTracer->describe(),
            ));

            return;
        }

        $stale = $enforcer->staleEntries();
        $this->writeStderr(sprintf(
            "[Gesso] baseline: %d entries, %d hit, %d stale (removable).\n",
            $entries,
            $hits,
            count($stale),
        ));

        if ($stale === []) {
            return;
        }

        $isFatal = $this->baselineStaleMode === BaselineStaleMode::Fail;
        $listing = [];
        foreach ($stale as $fingerprint) {
            $listing[] = '  - ' . $fingerprint->describe();
        }
        $body = implode("\n", $listing);

        $this->writeStderr(sprintf(
            "[Gesso] %s: %d baseline entry(ies) no longer occurred and can be removed from the baseline file:\n%s\n",
            $isFatal ? 'FATAL' : 'NOTE',
            count($stale),
            $body,
        ));
        OpenApiCoverageExtension::appendGithubStepSummaryBaselineStaleBlock(
            $this->githubSummaryPath,
            $body,
            $isFatal,
        );

        if ($isFatal) {
            $this->exitNonZero();
        }
    }

    /**
     * Issue #481: generate or enforce the coverage baseline — the set-based
     * replacement for `min_response_coverage`. Generation writes today's
     * uncovered responses; enforcement fails the run for any uncovered
     * response the committed file does not list, and reports entries that
     * are covered now as stale.
     *
     * Both halves need the full suite: a subset run leaves most responses
     * uncovered, which would either bake a bogus baseline or report every
     * filtered-out response as a regression. Partial runs are skipped with a
     * NOTE, mirroring the threshold gate. Enforcement additionally requires
     * the run to have completed cleanly — a failed or truncated test never
     * reached its contract assertion, so its responses would be reported as
     * newly uncovered on top of the failure the user is already looking at.
     *
     * @param array<string, CoverageResult> $results
     */
    private function handleCoverageBaseline(array $results): void
    {
        if ($this->coverageBaselineGeneratePath !== null) {
            $this->writeCoverageBaselineFile($results);

            return;
        }

        $baseline = $this->coverageBaseline;
        if ($baseline === null) {
            return;
        }

        if ($this->partialRun !== null) {
            $this->writeStderr(sprintf(
                '[Gesso] NOTE: the coverage baseline gate is skipped on partial runs (%s) '
                . "because a subset run leaves responses uncovered that the full suite covers. Run the full suite to evaluate the gate.\n",
                $this->partialRun->reason,
            ));

            return;
        }

        if ($this->baselineCompletionTracer !== null && !$this->baselineCompletionTracer->completedCleanly()) {
            $this->writeStderr(sprintf(
                "[Gesso] NOTE: the coverage baseline gate is skipped because the run did not complete cleanly (%s); responses of tests that never reached their contract assertion would be reported as newly uncovered. Re-run with all tests passing to evaluate the gate.\n",
                $this->baselineCompletionTracer->describe(),
            ));

            return;
        }

        if ($results === []) {
            // Same hazard as the threshold gate's empty-results branch: the
            // uncovered set would be empty for lack of data, not for lack of
            // gaps, and the gate would pass a run that validated nothing.
            $this->writeStderr(
                "[Gesso] FATAL: no contract test coverage was recorded; the coverage baseline gate cannot be evaluated.\n",
            );
            $this->exitNonZero();

            return;
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
                'GESSO_BASELINE_GENERATE=1 vendor/bin/phpunit',
            ));
            $this->exitNonZero();

            return;
        }

        if ($verdict['stale'] === [] || $this->coverageBaselineStaleMode === BaselineStaleMode::Off) {
            return;
        }

        $isFatal = $this->coverageBaselineStaleMode === BaselineStaleMode::Fail;
        $this->writeStderr(CoverageBaselineEvaluator::renderStaleMessage($verdict['stale'], $isFatal));

        if ($isFatal) {
            $this->exitNonZero();
        }
    }

    /**
     * @param array<string, CoverageResult> $results
     */
    private function writeCoverageBaselineFile(array $results): void
    {
        $path = $this->coverageBaselineGeneratePath;
        if ($path === null) {
            return;
        }

        if ($this->partialRun !== null) {
            $this->writeStderr(sprintf(
                "[Gesso] WARNING: coverage baseline generation refused on a partial run (%s) — a subset run would record responses the full suite covers. No file was written.\n",
                $this->partialRun->reason,
            ));
            $this->exitNonZero();

            return;
        }

        if ($results === []) {
            $this->writeStderr(
                "[Gesso] WARNING: coverage baseline generation refused because no contract test coverage was recorded; the file would list every declared response. No file was written.\n",
            );
            $this->exitNonZero();

            return;
        }

        $baseline = CoverageBaselineEvaluator::collect($results);

        try {
            CoverageBaselineFile::write($path, $baseline);
        } catch (RuntimeException $e) {
            $this->writeStderr("[Gesso] WARNING: {$e->getMessage()}\n");
            $this->exitNonZero();

            return;
        }

        $this->writeStderr(sprintf(
            "[Gesso] Coverage baseline written: %d uncovered response(s) → %s\n",
            $baseline->count(),
            $path,
        ));
    }

    /**
     * PHPUnit does not propagate subscriber failures to the exit code, so a
     * fatal gate or a failed baseline generation must terminate the process
     * itself to be visible to CI.
     */
    private function exitNonZero(): void
    {
        if ($this->stderrWriter === null) {
            fflush(STDERR);
        }
        $exit = $this->exitHandler;
        if (is_callable($exit)) {
            $exit(1);

            return;
        }

        exit(1);
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
        return $this->reportWriter()->computeResults($this->specs, $this->coverageTracker);
    }

    /**
     * @return array<string, SdkExerciseCoverageResult>
     */
    private function computeAllSdkResults(): array
    {
        return $this->reportWriter()->computeSdkResults($this->specs, $this->sdkExerciseCoverageTracker);
    }

    /** Subscriber severity: a failed report write warns and never drives the exit code. */
    private function reportWriter(): CoverageReportWriter
    {
        return new CoverageReportWriter($this->writeStderr(...), 'WARNING');
    }

    private function restoreSpecLoaderConfigurationIfReset(): void
    {
        if ($this->specBasePath === null) {
            return;
        }

        try {
            OpenApiSpecLoader::getBasePath();

            return;
        } catch (InvalidOpenApiSpecException $e) {
            if ($e->reason !== InvalidOpenApiSpecReason::BasePathNotConfigured) {
                throw $e;
            }
        }

        OpenApiSpecLoader::configure(
            $this->specBasePath,
            $this->specStripPrefixes,
            enumBasePath: $this->enumBasePath,
        );
    }

    /**
     * Write every configured report, then the GITHUB_STEP_SUMMARY append —
     * or none of them on a partial run.
     *
     * @param array<string, CoverageResult> $results
     * @param array<string, SdkExerciseCoverageResult> $sdkResults
     */
    private function writeReports(array $results, array $sdkResults): void
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

        $writer = $this->reportWriter();
        $writer->writeReports($results, $sdkResults, $this->outputFile, $this->junitOutput, $this->jsonOutput, $this->htmlOutput);
        $writer->appendGithubStepSummary($results, $sdkResults, $this->githubSummaryPath);
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
}
