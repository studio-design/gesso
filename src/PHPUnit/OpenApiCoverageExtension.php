<?php

declare(strict_types=1);

namespace Studio\Gesso\PHPUnit;

use const DEBUG_BACKTRACE_IGNORE_ARGS;

use InvalidArgumentException;
use PHPUnit\Event\EventFacadeIsSealedException;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use Studio\Gesso\Baseline\BaselineFileFormat;
use Studio\Gesso\Baseline\BaselineStaleMode;
use Studio\Gesso\Baseline\CoverageBaseline;
use Studio\Gesso\Baseline\CoverageBaselineFile;
use Studio\Gesso\Baseline\InvalidBaselineConfigurationException;
use Studio\Gesso\Baseline\ViolationBaselineCollector;
use Studio\Gesso\Baseline\ViolationBaselineEnforcer;
use Studio\Gesso\Baseline\ViolationBaselineFile;
use Studio\Gesso\Config\InvalidGessoConfigurationException;
use Studio\Gesso\Coverage\CoverageSidecarWriter;
use Studio\Gesso\Coverage\CoverageThresholdEvaluator;
use Studio\Gesso\Coverage\InvalidCoverageOutputPathException;
use Studio\Gesso\Coverage\InvalidThresholdConfigurationException;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Coverage\SdkExerciseCoverageTracker;
use Studio\Gesso\Exception\EnumBindingException;
use Studio\Gesso\Exception\EnumBindingReason;
use Studio\Gesso\Exception\EnumDriftException;
use Studio\Gesso\Exception\InvalidOpenApiSpecException;
use Studio\Gesso\Exception\SpecFileNotFoundException;
use Studio\Gesso\Internal\ArgvParser;
use Studio\Gesso\Internal\Deprecations;
use Studio\Gesso\Internal\Diagnostics;
use Studio\Gesso\Internal\EnumScanner;
use Studio\Gesso\Internal\PartialRunDecision;
use Studio\Gesso\Schema\EnumDriftAsserter;
use Studio\Gesso\Schema\EnumDriftReport;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Request\AcknowledgedSecuritySchemes;
use Studio\Gesso\Validation\Strict\StrictAdditionalPropertiesMode;
use Studio\Gesso\Validation\Strict\StrictAdditionalPropertiesPerCallChecker;
use Studio\Gesso\Validation\Strict\StrictAdditionalPropertiesPerCallMode;
use Studio\Gesso\Validation\Strict\StrictAdditionalPropertiesTracker;
use Studio\Gesso\Validation\Strict\StrictRequiredMode;
use Studio\Gesso\Validation\Strict\StrictRequiredPerCallChecker;
use Studio\Gesso\Validation\Strict\StrictRequiredPerCallMode;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;
use Studio\Gesso\Validation\Support\DiscriminatorEnforcement;
use Studio\Gesso\Validation\Support\StatusCodePatternSet;
use Studio\Gesso\Validation\Support\ValidationPolicyDefaults;
use Studio\Gesso\ValidationOutput;
use Studio\Gesso\ValidationOutputFormat;

use function array_filter;
use function array_slice;
use function array_values;
use function ctype_digit;
use function debug_backtrace;
use function dirname;
use function explode;
use function getcwd;
use function getenv;
use function implode;
use function is_array;
use function is_dir;
use function is_string;
use function is_writable;
use function method_exists;
use function mkdir;
use function sprintf;
use function str_starts_with;
use function strtolower;
use function trim;

final class OpenApiCoverageExtension implements Extension
{
    /**
     * Default location used by paratest workers when no `sidecar_dir`
     * parameter is configured. Kept stable across runs so the merge CLI
     * can find it without coordination, and namespaced enough that other
     * tools won't collide with it.
     */
    public const DEFAULT_SIDECAR_SUBDIR = CoverageSidecarWriter::DEFAULT_SUBDIR;

    public static function defaultSidecarDir(): string
    {
        return CoverageSidecarWriter::defaultDir();
    }

    /**
     * Redirect STDERR writes to a test-supplied stream.
     *
     * @param null|resource $stream
     *
     * @internal
     */
    public static function overrideStderrForTesting($stream): void
    {
        Diagnostics::overrideStderrForTesting($stream);
    }

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        try {
            $parameters = ExtensionParameters::load(
                $parameters,
                $configuration->hasConfigurationFile() ? dirname($configuration->configurationFile()) : null,
            );
            $this->setupExtension(
                $facade,
                $parameters,
                getenv('GITHUB_STEP_SUMMARY') ?: null,
                self::detectPartialRun($configuration, $parameters),
            );
        } catch (EnumBindingException|EnumDriftException|InvalidBaselineConfigurationException|InvalidCoverageOutputPathException|InvalidGessoConfigurationException|InvalidOpenApiSpecException|InvalidStrictRequiredConfigurationException|InvalidThresholdConfigurationException|InvalidValidationPolicyConfigurationException|SpecFileNotFoundException) {
            // setupExtension() has already written a FATAL line to stderr and
            // (if GITHUB_STEP_SUMMARY is set) appended a fatal block to it.
            // PHPUnit's ExtensionBootstrapper::bootstrap() wraps this call in
            // catch(Throwable) and demotes it to testRunnerTriggeredPhpunitWarning,
            // which only fails the run when consumers opt in via
            // failOnPhpunitWarning (or failOnAllIssues). Depending on that
            // would re-open the silent-pass hole this extension exists to
            // close, so force a non-zero exit here. fflush guards against
            // output ordering surprises on exit().
            Diagnostics::flushStderr();

            exit(1);
        }
    }

    /**
     * Exposed for testing: accepts the injectable parts of bootstrap without
     * requiring a real PHPUnit `Configuration`, which is a `final readonly`
     * class with over 150 ctor parameters and is not reasonable to stub.
     *
     * `$facade` is nullable so unit tests can exercise the eager-load path
     * without supplying a real `Facade`. Its shape changed between PHPUnit
     * 11/12 (class) and 13 (interface), so a portable stub is not possible;
     * skipping subscriber registration in tests is the clean fix.
     *
     * @internal
     */
    public function setupExtension(
        ?Facade $facade,
        ExtensionParameters|ParameterCollection $parameters,
        ?string $githubSummaryPath,
        ?PartialRunDecision $partialRun = null,
    ): void {
        if ($parameters instanceof ParameterCollection) {
            $parameters = ExtensionParameters::load($parameters);
        }

        // Issue #170: secondary base path used only for
        // #[BoundToOpenApiEnum] resolution. Read independently of
        // spec_base_path so that an orphaned `enum_spec_base_path`
        // (e.g. a typo in the spec_base_path attribute) is detected as a
        // misconfiguration instead of being silently dropped together.
        $enumBasePath = self::resolveEnumSpecBasePathParameter($parameters, $githubSummaryPath);

        if ($parameters->has('spec_base_path')) {
            $basePath = $parameters->get('spec_base_path');
            if (!str_starts_with($basePath, '/')) {
                $basePath = getcwd() . '/' . $basePath;
            }

            $stripPrefixes = [];
            if ($parameters->has('strip_prefixes')) {
                $stripPrefixes = $parameters->strings('strip_prefixes');
            }

            OpenApiSpecLoader::configure($basePath, $stripPrefixes);
            if ($enumBasePath !== null) {
                OpenApiSpecLoader::setEnumBasePath($enumBasePath);
            }
        } elseif ($parameters->has('strip_prefixes')) {
            OpenApiSpecLoader::setStripPrefixes($parameters->strings('strip_prefixes'));
        }

        $specs = ['front'];
        if ($parameters->has('specs')) {
            $specs = $parameters->strings('specs');
        }

        // Eager-load every registered spec so structural problems surface at
        // PHPUnit bootstrap (hard fail via bootstrap()) rather than being
        // silently swallowed when a test happens not to exercise the broken
        // spec. A spec named in `specs=` that doesn't resolve to a file is a
        // configuration error — not a stale leftover — so it is fatal too
        // (issue #134). Defensive warn-and-continue for missing files lives
        // downstream in CoverageReportSubscriber / CoverageMergeCommand,
        // where a mid-run unlink shouldn't lose the report.
        foreach ($specs as $spec) {
            try {
                OpenApiSpecLoader::load($spec);
            } catch (SpecFileNotFoundException $e) {
                Diagnostics::stderr(
                    "[OpenAPI Coverage] FATAL: spec '{$spec}' configured in spec.names is not loadable: {$e->getMessage()}\n"
                    . "  Action: regenerate the bundle (e.g. `cd openapi && npm run bundle`) or remove '{$spec}' from spec.names.\n",
                );
                Diagnostics::appendGithubStepSummaryFatalSpecBlock($githubSummaryPath, $spec, $e->getMessage());

                throw $e;
            } catch (InvalidOpenApiSpecException $e) {
                Diagnostics::stderr("[OpenAPI Coverage] FATAL: Invalid OpenAPI spec '{$spec}': {$e->getMessage()}\n");
                Diagnostics::appendGithubStepSummaryFatalSpecBlock($githubSummaryPath, $spec, $e->getMessage());

                throw $e;
            }
        }

        self::runEnumDriftCheck($parameters, $githubSummaryPath, $specs);

        $outputFile = null;
        if ($parameters->has('output_file')) {
            $outputFile = $parameters->get('output_file');
            if (!str_starts_with($outputFile, '/')) {
                $outputFile = getcwd() . '/' . $outputFile;
            }
        }

        $junitOutput = self::resolveOutputPathParameter($parameters, 'junit_output', $githubSummaryPath);
        $jsonOutput = self::resolveOutputPathParameter($parameters, 'json_output', $githubSummaryPath);
        $htmlOutput = self::resolveOutputPathParameter($parameters, 'html_output', $githubSummaryPath);

        // Issue #282: run-wide validation failure output selection. Only a
        // valid parameter reaches ValidationOutput::use(); the environment
        // variable keeps priority inside ValidationOutput::format() itself.
        if ($parameters->has('validation_output') && trim($parameters->get('validation_output')) !== '') {
            $rawValidationOutput = $parameters->get('validation_output');
            $validationOutput = ValidationOutputFormat::tryFrom(strtolower(trim($rawValidationOutput)));

            if ($validationOutput === null) {
                // An invalid value never changes the selection — a prior
                // ValidationOutput::use() from a test bootstrap survives,
                // mirroring how ValidationOutput::format() treats an invalid
                // environment value.
                //
                // [Gesso] rather than [OpenAPI Coverage]: the setting selects
                // gesso-wide validation failure output, and the migration
                // baseline pins identity-neutral prefix counts (ADR 0001) —
                // new diagnostics go through the branded channel.
                Diagnostics::stderr("[Gesso] WARNING: Invalid validation_output parameter '{$rawValidationOutput}'. Valid values: text, json. Falling back to the configured format.\n");
            } else {
                ValidationOutput::use($validationOutput);
            }
        }

        $consoleOutput = ConsoleOutput::resolve(
            $parameters->has('console_output') ? $parameters->get('console_output') : null,
        );

        $sidecarDir = null;
        if ($parameters->has('sidecar_dir')) {
            $sidecarDir = $parameters->get('sidecar_dir');
            if (!str_starts_with($sidecarDir, '/')) {
                $sidecarDir = getcwd() . '/' . $sidecarDir;
            }
        }

        // Issue #402: violation baseline generation. `baseline_file` names
        // the committed baseline; the run only demotes failures when the
        // user explicitly asked for a generation run via the environment
        // variable, so a plain `vendor/bin/phpunit` never masks violations.
        $baselineFile = self::resolveBaselineFileParameter($parameters, 'baseline_file');

        // Issue #481: the coverage baseline is the set-based counterpart —
        // same generation entry point, its own committed file.
        $coverageBaselineFile = self::resolveBaselineFileParameter($parameters, 'coverage_baseline_file');

        // Resolve baseline_stale before any baseline wiring so a typo'd or
        // orphaned parameter aborts bootstrap first (fail-loud policy).
        $baselineStaleMode = self::resolveBaselineStaleMode(
            $parameters,
            'baseline_stale',
            'baseline_file',
            $baselineFile !== null,
        );
        $coverageBaselineStaleMode = self::resolveBaselineStaleMode(
            $parameters,
            'coverage_baseline_stale',
            'coverage_baseline_file',
            $coverageBaselineFile !== null,
        );

        $generationRequested = BaselineFileFormat::generationRequested();
        if ($generationRequested && $baselineFile === null && $coverageBaselineFile === null) {
            // A generation run with nowhere to write would complete "green"
            // (all failures demoted) and then drop every recorded violation
            // on the floor — abort bootstrap instead.
            Diagnostics::stderr(
                "[Gesso] FATAL: GESSO_BASELINE_GENERATE is set but neither `baseline_file` nor `coverage_baseline_file` is configured.\n"
                . "  Action: set 'baseline' => ['violations' => 'gesso-baseline.json'] and/or\n"
                . "          ['coverage' => 'gesso-coverage-baseline.json'] in gesso.php.\n",
            );

            throw new InvalidBaselineConfigurationException(
                'GESSO_BASELINE_GENERATE requires the baseline_file or coverage_baseline_file extension parameter.',
            );
        }

        // Issue #481: enforcement loads and validates the committed file at
        // bootstrap for the same fail-loud reason as the violation baseline.
        // Worker processes load it too and then never evaluate it (the
        // subscriber's worker branch returns early) — `gesso coverage:merge`
        // is the gate for parallel runs.
        $coverageBaseline = null;
        $coverageBaselineGeneratePath = null;
        if ($generationRequested) {
            $coverageBaselineGeneratePath = $coverageBaselineFile;
        } elseif ($coverageBaselineFile !== null) {
            $coverageBaseline = self::readCoverageBaseline($coverageBaselineFile);
        }

        ViolationBaselineCollector::resetCurrent();
        ViolationBaselineEnforcer::resetCurrent();
        $baselineGeneratePath = null;
        if ($generationRequested && $baselineFile !== null) {
            // Issue #417: paratest workers (TEST_TOKEN set) run generation
            // like a sequential run — the collector demotes failures and the
            // subscriber's worker branch stages the fingerprints in the
            // sidecar envelope for `gesso coverage:merge --baseline-file`
            // to union into the committed file.
            ViolationBaselineCollector::setCurrent(new ViolationBaselineCollector());
            $baselineGeneratePath = $baselineFile;
        } elseif (!$generationRequested && $baselineFile !== null) {
            // Issue #402: enforcement. A configured baseline_file that cannot
            // be loaded is FATAL — swallowing a typo'd path or a corrupted
            // file would silently disable suppression (all-red run) or, worse,
            // look like "no known violations" to anyone reading the config.
            try {
                $baseline = ViolationBaselineFile::read($baselineFile);
            } catch (InvalidArgumentException $e) {
                Diagnostics::stderr(
                    "[Gesso] FATAL: baseline_file could not be loaded: {$e->getMessage()}\n"
                    . "  Action: generate it with `GESSO_BASELINE_GENERATE=1 vendor/bin/phpunit`, fix the path, or remove the `baseline_file` parameter.\n",
                );

                throw new InvalidBaselineConfigurationException(
                    'baseline_file could not be loaded: ' . $e->getMessage(),
                    previous: $e,
                );
            }

            ViolationBaselineEnforcer::setCurrent(new ViolationBaselineEnforcer($baseline));
        }

        // Resolve strict first so threshold validation can promote bad values
        // to FATAL when the user opted in to fail-fast (issue #135 review C1).
        $minCoverageStrict = self::resolveBooleanFlag($parameters, 'min_coverage_strict', false);
        $minEndpointCoverage = self::resolveThresholdParameter($parameters, 'min_endpoint_coverage', $minCoverageStrict);
        $minResponseCoverage = self::resolveThresholdParameter($parameters, 'min_response_coverage', $minCoverageStrict);
        $minSdkExerciseCoverage = self::resolveThresholdParameter($parameters, 'min_sdk_exercise_coverage', $minCoverageStrict);

        // Issue #229: install fresh tracker instances for this run and route
        // the static facades (used by the Laravel trait that can't take DI)
        // through them. Replacing the locator instance gives us a clean
        // start without depending on a process-global ::reset(); the previous
        // bootstrap-reset pattern is preserved by the fresh instances. Test
        // seams that invoke setupExtension() multiple times in one PHP
        // process re-install fresh instances each call, dropping any state
        // accumulated since the previous bootstrap. Worker observations are
        // aggregated across paratest workers via the sidecar envelope
        // (Issue #226); the run-level instances installed here are exactly
        // what the merge CLI's tracker reconstructs from those sidecars.
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

        // Issue #224: schema under-description detection mode is read from
        // phpunit.xml here so a misspelled `strict_required=` value
        // hard-fails bootstrap before any subscriber wiring.
        $strictRequiredMode = self::resolveMode(
            $parameters,
            'strict_required',
            StrictRequiredMode::fromConfigValue(...),
            StrictRequiredMode::Off,
            'off, warn, fail',
            '[OpenAPI Strict Required]',
            Diagnostics::appendGithubStepSummaryStrictRequiredBlock(...),
            $githubSummaryPath,
        );

        // Issue #228: per-call strict_required mode. Independent of the
        // run-level parameter above — both gates can be wired in the same
        // run. Resolve first so an invalid value FATAL-throws BEFORE we
        // touch the checker state; reset then runs only on the happy path
        // and on test seams where setupExtension() is invoked multiple
        // times in one PHP process. The follow-up `configure()` overwrites
        // unconditionally, so the reset matters specifically for the
        // failed-resolve early-return path (where configure never runs)
        // and for repeated bootstrap calls.
        $strictRequiredPerCallMode = self::resolveMode(
            $parameters,
            'strict_required_per_call',
            StrictRequiredPerCallMode::fromConfigValue(...),
            StrictRequiredPerCallMode::Off,
            'off, warn',
            '[OpenAPI Strict Required per-call]',
            Diagnostics::appendGithubStepSummaryStrictRequiredBlock(...),
            $githubSummaryPath,
        );
        StrictRequiredPerCallChecker::reset();
        StrictRequiredPerCallChecker::configure($strictRequiredPerCallMode);

        $strictAdditionalPropertiesMode = self::resolveMode(
            $parameters,
            'strict_additional_properties',
            StrictAdditionalPropertiesMode::fromConfigValue(...),
            StrictAdditionalPropertiesMode::Off,
            'off, warn, fail',
            '[OpenAPI Strict Additional Properties]',
            Diagnostics::appendGithubStepSummaryStrictAdditionalPropertiesBlock(...),
            $githubSummaryPath,
        );
        $strictAdditionalPropertiesPerCallMode = self::resolveMode(
            $parameters,
            'strict_additional_properties_per_call',
            StrictAdditionalPropertiesPerCallMode::fromConfigValue(...),
            StrictAdditionalPropertiesPerCallMode::Off,
            'off, warn',
            '[OpenAPI Strict Additional Properties per-call]',
            Diagnostics::appendGithubStepSummaryStrictAdditionalPropertiesBlock(...),
            $githubSummaryPath,
        );
        StrictAdditionalPropertiesPerCallChecker::reset();
        StrictAdditionalPropertiesPerCallChecker::configure($strictAdditionalPropertiesPerCallMode);

        // Skip the per-response body walk and inspection when nothing reads
        // the result: both modes off and no sidecar export. A parallel worker
        // keeps recording because the merge CLI's `--strict-*` flags gate on
        // worker observations independently of this process's modes.
        $exportsSidecar = CoverageReportSubscriber::resolveWorkerToken() !== null;
        $strictRequiredTracker->setRecording(
            $exportsSidecar ||
            $strictRequiredMode->isEnabled() ||
            $strictRequiredPerCallMode !== StrictRequiredPerCallMode::Off,
        );
        $strictAdditionalPropertiesTracker->setRecording(
            $exportsSidecar ||
            $strictAdditionalPropertiesMode !== StrictAdditionalPropertiesMode::Off ||
            $strictAdditionalPropertiesPerCallMode !== StrictAdditionalPropertiesPerCallMode::Off,
        );

        // Issue #262: discriminator.mapping enforcement gate. Default ON —
        // enforcement is the correct contract-testing behaviour; `value="false"`
        // (or `0` / `no`) is the escape hatch for specs that rely on the loose
        // union semantics. resolveBooleanFlag reads the `enforce_discriminator`
        // parameter (absent → the default), and configure() overwrites
        // unconditionally so a process reused across bootstraps reflects the
        // current run.
        DiscriminatorEnforcement::configure(
            self::resolveBooleanFlag($parameters, 'enforce_discriminator', true),
        );

        // Issue #445: scheme-scoped acknowledgement of unvalidatable security
        // schemes. Comma-separated `components.securitySchemes` names; absent
        // parameter resets the registry so a process reused across bootstraps
        // reflects the current run. Rot checks (unknown / validatable names)
        // live in SecurityValidator, next to the warning they guard.
        $acknowledgedSchemes = [];
        if ($parameters->has('acknowledged_unvalidatable_schemes')) {
            $acknowledgedSchemes = array_values(array_filter(
                $parameters->strings('acknowledged_unvalidatable_schemes'),
                static fn(string $name): bool => $name !== '',
            ));
        }
        AcknowledgedSecuritySchemes::configure($acknowledgedSchemes);

        // Issue #502 (additive half): validation-policy defaults that
        // previously existed only as Laravel config keys, so a plain PHPUnit
        // suite can set them at all. Resolve first so a bad value FATALs
        // before any state mutation; configure() overwrites unconditionally,
        // so absent parameters reset a process reused across bootstraps.
        // The validators read these only when a constructor argument is
        // omitted — framework adapters pass explicit arguments and keep
        // their own configuration surfaces.
        $maxErrors = self::resolveMaxErrorsParameter($parameters);
        $skipResponseCodes = self::resolveStatusCodeListParameter($parameters, 'skip_response_codes');
        $skipRequestValidationResponseCodes = self::resolveStatusCodeListParameter(
            $parameters,
            'skip_request_validation_response_codes',
        );
        $defaultSpec = null;
        if ($parameters->has('default_spec') && trim($parameters->get('default_spec')) !== '') {
            $defaultSpec = trim($parameters->get('default_spec'));
        }
        ValidationPolicyDefaults::configure(
            maxErrors: $maxErrors,
            skipResponseCodes: $skipResponseCodes,
            skipRequestValidationResponseCodes: $skipRequestValidationResponseCodes,
            defaultSpec: $defaultSpec,
        );

        if ($facade === null) {
            return;
        }

        // Issue #402: on enforcement runs, verify that every planned test
        // finished with no defects — the stale gate must not report unhit
        // entries as removable when later assertions never ran (truncated
        // --stop-on-* runs, hook failures, failed/skipped tests).
        //
        // Issue #481: the coverage baseline needs the same proof for the
        // opposite reason — a test that never reached its contract assertion
        // leaves its responses uncovered, which would be reported as a
        // regression on top of the failure the user is already looking at.
        //
        // Issue #598: Pest's parallel runner seals PHPUnit's event facade
        // before paratest's SuiteLoader bootstraps extensions in the
        // orchestrator, so both registrations below throw there. That process
        // runs no tests; workers register normally and write sidecars. Any
        // other sealed context is rethrown and stays a PHPUnit warning.
        try {
            $baselineCompletionTracer = null;
            if (ViolationBaselineEnforcer::current() !== null || $coverageBaseline !== null) {
                $baselineCompletionTracer = new TestRunCompletionTracer();
                $facade->registerTracer($baselineCompletionTracer);
            }

            $facade->registerSubscriber(new CoverageReportSubscriber(
                specs: $specs,
                outputFile: $outputFile,
                consoleOutput: $consoleOutput,
                githubSummaryPath: $githubSummaryPath,
                coverageTracker: $coverageTracker,
                strictRequiredTracker: $strictRequiredTracker,
                sidecarDir: $sidecarDir,
                minEndpointCoverage: $minEndpointCoverage,
                minResponseCoverage: $minResponseCoverage,
                minSdkExerciseCoverage: $minSdkExerciseCoverage,
                minCoverageStrict: $minCoverageStrict,
                junitOutput: $junitOutput,
                jsonOutput: $jsonOutput,
                htmlOutput: $htmlOutput,
                partialRun: $partialRun,
                strictRequiredMode: $strictRequiredMode,
                strictAdditionalPropertiesTracker: $strictAdditionalPropertiesTracker,
                strictAdditionalPropertiesMode: $strictAdditionalPropertiesMode,
                sdkExerciseCoverageTracker: $sdkExerciseCoverageTracker,
                baselineGeneratePath: $baselineGeneratePath,
                baselineStaleMode: $baselineStaleMode,
                baselineCompletionTracer: $baselineCompletionTracer,
                coverageBaseline: $coverageBaseline,
                coverageBaselineGeneratePath: $coverageBaselineGeneratePath,
                coverageBaselineStaleMode: $coverageBaselineStaleMode,
            ));
        } catch (EventFacadeIsSealedException $e) {
            if (!self::isPestParallelOrchestrator()) {
                throw $e;
            }

            Diagnostics::stderr(
                '[Gesso] NOTE: PHPUnit\'s event facade was already sealed when OpenApiCoverageExtension bootstrapped in the Pest --parallel orchestrator,'
                . " so this process registers no coverage subscriber. It runs no tests; worker sidecars and `gesso coverage:merge` are unaffected.\n",
            );
        }
    }

    /**
     * Positive evidence for the #598 orchestrator: Pest's own parallel switch
     * and paratest's SuiteLoader bootstrapping this extension right now. The
     * stack check excludes Pest's sequential fallback (e.g. `--parallel
     * --retry`), which keeps the flag but never goes through SuiteLoader.
     */
    private static function isPestParallelOrchestrator(): bool
    {
        $argv = $_SERVER['argv'] ?? [];
        if (!is_array($argv)) {
            return false;
        }

        // Mirrors `Pest\Plugins\Parallel::isEnabled()`, i.e. Symfony's
        // `ArgvInput::hasParameterOption()` for `--parallel` and `-p`: the
        // long flag may carry `=value`, and any token starting with `-p`
        // counts (`-p2`, `-p=4`). argv[0] is the script, not a token.
        $parallel = false;
        foreach (array_slice($argv, 1) as $token) {
            if (is_string($token) && ($token === '--parallel' || str_starts_with($token, '--parallel=') || str_starts_with($token, '-p'))) {
                $parallel = true;

                break;
            }
        }
        if (!$parallel) {
            return false;
        }

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (($frame['class'] ?? null) === 'ParaTest\WrapperRunner\SuiteLoader') {
                return true;
            }
        }

        return false;
    }

    /**
     * Read a baseline path parameter, resolving a relative value against the
     * working directory. Empty values are treated as "not configured" so a
     * blank `value=""` does not point the baseline at the cwd itself.
     */
    private static function resolveBaselineFileParameter(
        ExtensionParameters $parameters,
        string $name,
    ): ?string {
        if (!$parameters->has($name) || trim($parameters->get($name)) === '') {
            return null;
        }

        $path = trim($parameters->get($name));

        return str_starts_with($path, '/') ? $path : getcwd() . '/' . $path;
    }

    /**
     * Issue #481: enforcement counterpart of the violation baseline's
     * bootstrap read. A typo'd path or a corrupted file must not silently
     * disable the gate.
     */
    private static function readCoverageBaseline(string $path): CoverageBaseline
    {
        try {
            return CoverageBaselineFile::read($path);
        } catch (InvalidArgumentException $e) {
            Diagnostics::stderr(
                "[Gesso] FATAL: coverage_baseline_file could not be loaded: {$e->getMessage()}\n"
                . "  Action: generate it with `GESSO_BASELINE_GENERATE=1 vendor/bin/phpunit`, fix the path, or remove the `coverage_baseline_file` parameter.\n",
            );

            throw new InvalidBaselineConfigurationException(
                'coverage_baseline_file could not be loaded: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }

    /**
     * Issue #402 / #481: read a `*_stale` parameter. Missing and empty values
     * resolve to {@see BaselineStaleMode::Note}; unrecognised values and a
     * stale mode without its baseline file (nothing to evaluate staleness
     * against) are FATAL — silently dropping the parameter would defeat the
     * opt-in fail-loud policy this extension enforces.
     */
    private static function resolveBaselineStaleMode(
        ExtensionParameters $parameters,
        string $name,
        string $fileName,
        bool $hasBaselineFile,
    ): BaselineStaleMode {
        if (!$parameters->has($name)) {
            return BaselineStaleMode::Note;
        }

        if (!$hasBaselineFile) {
            $reason = sprintf(
                '%s is set but no `%s` extension parameter is configured. '
                . 'Stale evaluation needs a baseline to compare against; set `%s` or remove `%s`.',
                $name,
                $fileName,
                $fileName,
                $name,
            );
            Diagnostics::stderr("[Gesso] FATAL: {$reason}\n");

            throw new InvalidBaselineConfigurationException($reason);
        }

        $raw = $parameters->get($name);

        try {
            return BaselineStaleMode::fromConfigValue($raw);
        } catch (InvalidArgumentException $e) {
            Diagnostics::stderr("[Gesso] FATAL: {$e->getMessage()}\n");

            throw new InvalidBaselineConfigurationException($e->getMessage(), previous: $e);
        }
    }

    /**
     * Issue #221: read PHPUnit's selection signals off the
     * {@see Configuration} object so the subscriber can skip persistent
     * writes on partial runs. The signal set, rationale, and why the
     * `TestSuite\Filtered` event is not used are documented on
     * {@see PartialRunDecision} — keeping that explanation in one place.
     *
     * Issue #236: the `default_testsuite_as_full` xml parameter is forwarded
     * here together with `Configuration::defaultTestSuite()` so the
     * `defaultTestSuite`-resolved `includeTestSuites` payload can be treated
     * as a canonical full run when the user opts in.
     */
    private static function detectPartialRun(
        Configuration $configuration,
        ExtensionParameters $parameters,
    ): ?PartialRunDecision {
        $treatDefaultAsFull = self::resolveBooleanFlag($parameters, 'default_testsuite_as_full', false);

        return PartialRunDecision::fromSignals(
            hasCliArguments: $configuration->hasCliArguments(),
            hasFilter: $configuration->hasFilter(),
            hasExcludeFilter: $configuration->hasExcludeFilter(),
            hasGroups: $configuration->hasGroups(),
            hasExcludeGroups: $configuration->hasExcludeGroups(),
            includeTestSuites: self::readTestSuiteList($configuration, 'includeTestSuites', 'includeTestSuite'),
            excludeTestSuites: self::readTestSuiteList($configuration, 'excludeTestSuites', 'excludeTestSuite'),
            hasTestsCovering: $configuration->hasTestsCovering(),
            hasTestsUsing: $configuration->hasTestsUsing(),
            hasTestsRequiringPhpExtension: $configuration->hasTestsRequiringPhpExtension(),
            defaultTestSuite: self::readDefaultTestSuite($configuration, warnOnInertOptIn: $treatDefaultAsFull),
            treatDefaultTestSuiteAsFull: $treatDefaultAsFull,
        );
    }

    /**
     * Cross-version reader for the `--testsuite` / `--exclude-testsuite`
     * selection. PHPUnit 13 exposes only the plural array form, PHPUnit
     * 11 exposes only the singular comma-joined string form, and
     * PHPUnit 12 happens to ship both — so picking one at compile time
     * would break the matrix CI (PHP 8.3/8.4/8.5 × PHPUnit 12/13).
     * The dynamic method call also avoids a static analysis error on
     * whichever PHPUnit version PHPStan is resolving against locally.
     *
     * @return list<non-empty-string>
     */
    private static function readTestSuiteList(
        Configuration $configuration,
        string $pluralMethod,
        string $singularMethod,
    ): array {
        // Dynamic method calls deliberately bypass PHPStan's static
        // resolution against whichever PHPUnit version it happens to be
        // analysing locally. We narrow the `mixed` result back to
        // `list<non-empty-string>` via runtime checks rather than `@var`
        // (the project's PHPStan policy forbids `@var` type overrides).
        if (method_exists($configuration, $pluralMethod)) {
            $plural = $configuration->{$pluralMethod}();

            return self::coerceToNonEmptyStringList($plural);
        }

        $singular = $configuration->{$singularMethod}();
        if (!is_string($singular) || $singular === '') {
            return [];
        }

        return self::coerceToNonEmptyStringList(explode(',', $singular));
    }

    /**
     * Filter an arbitrary value down to `list<non-empty-string>` for
     * `readTestSuiteList()`. Defensive: PHPUnit's contracts already
     * guarantee strings, but funneling the result through a single
     * narrowing helper keeps PHPStan happy without resorting to `@var`.
     *
     * @return list<non-empty-string>
     */
    private static function coerceToNonEmptyStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $list = [];
        foreach ($value as $entry) {
            if (is_string($entry) && $entry !== '') {
                $list[] = $entry;
            }
        }

        return $list;
    }

    /**
     * Read the `defaultTestSuite` xml attribute via PHPUnit's
     * {@see Configuration} accessors. Both `hasDefaultTestSuite()` and
     * `defaultTestSuite()` exists on PHPUnit 12/13, so a direct call is
     * safe across the CI matrix (unlike {@see readTestSuiteList()}, which
     * needs the dynamic dispatch because PHPUnit 13 dropped the singular
     * `includeTestSuite()` accessor). The `hasDefaultTestSuite()` guard is
     * mandatory: `defaultTestSuite()` throws `NoDefaultTestSuiteException`
     * when the xml attribute is absent.
     *
     * `$warnOnInertOptIn` surfaces the two misconfigurations that make
     * `default_testsuite_as_full=true` a silent no-op: (1) the user opted
     * in but never set `<phpunit defaultTestSuite="...">`, and (2) the
     * attribute is present but empty. The WARN is gated on the opt-in
     * itself so it never fires for callers that did not request the
     * neutralisation (the same path the rest of the extension uses).
     */
    private static function readDefaultTestSuite(
        Configuration $configuration,
        bool $warnOnInertOptIn,
    ): ?string {
        if (!$configuration->hasDefaultTestSuite()) {
            if ($warnOnInertOptIn) {
                Diagnostics::stderr(
                    '[OpenAPI Coverage] WARNING: default_testsuite_as_full=true but phpunit.xml does not declare '
                    . 'a `defaultTestSuite` attribute on <phpunit>. The opt-in will be inert; partial-run '
                    . 'detection remains active. Either set `defaultTestSuite="..."` on <phpunit> or remove '
                    . "`default_testsuite_as_full`.\n",
                );
            }

            return null;
        }

        // PHPUnit 13.2.6 documents defaultTestSuite() as non-empty-string,
        // but an XML `defaultTestSuite=""` attribute still reaches this code
        // as '' at runtime (pinned by OpenApiCoverageExtensionBootstrapTest),
        // and PHPUnit 12 does not carry the narrowed PHPDoc at all. Widen the
        // type locally so the guard below stays analyzable on every PHPUnit
        // version in the support matrix.
        /** @var string $value */
        $value = $configuration->defaultTestSuite();

        if ($value === '') {
            if ($warnOnInertOptIn) {
                Diagnostics::stderr(
                    '[OpenAPI Coverage] WARNING: default_testsuite_as_full=true but phpunit.xml declares an '
                    . 'empty `defaultTestSuite=""` attribute. The opt-in cannot match an empty default and '
                    . "will be inert. Set a non-empty `defaultTestSuite` or remove `default_testsuite_as_full`.\n",
                );
            }

            return null;
        }

        return $value;
    }

    /**
     * Read and validate the optional `enum_spec_base_path` parameter (issue
     * #170). Returns the absolutised path or `null` when the parameter is
     * absent (or set to whitespace-only, which is treated as absent so XML
     * editing artefacts like a leading newline don't silently coerce the
     * value to `getcwd()`).
     *
     * Detected misconfigurations are FATAL — a silent drop would defeat the
     * fail-loud-on-misconfiguration policy this extension enforces:
     *  - empty / whitespace value: rejected with a hint to remove the
     *    parameter.
     *  - set without `spec_base_path`: rejected because the loader's
     *    `configure()` requires `spec_base_path` to be passed too, so an
     *    orphaned `enum_spec_base_path` would never reach the loader.
     */
    private static function resolveEnumSpecBasePathParameter(
        ExtensionParameters $parameters,
        ?string $githubSummaryPath,
    ): ?string {
        if (!$parameters->has('enum_spec_base_path')) {
            return null;
        }

        Deprecations::notice(
            id: 'phpunit.enum_spec_base_path',
            subject: "The PHPUnit config key 'enum_spec_base_path'",
            replacement: 'none (delete the parameter: enum bindings resolve against components.schemas of the configured specs)',
            removedIn: '3.0',
        );

        $raw = trim($parameters->get('enum_spec_base_path'));
        if ($raw === '') {
            $reason = 'enum_spec_base_path is set but empty. '
                . 'Either provide a directory path or remove the parameter to fall back to spec_base_path.';
            Diagnostics::stderr("[OpenAPI Enum Drift] FATAL: {$reason}\n");
            Diagnostics::appendGithubStepSummaryEnumDriftBlock($githubSummaryPath, $reason, isFatal: true);

            throw EnumBindingException::forConfig(
                EnumBindingReason::EnumSpecBasePathOrphaned,
                $reason,
            );
        }

        if (!$parameters->has('spec_base_path')) {
            $reason = 'enum_spec_base_path is set but spec_base_path is not. '
                . 'enum_spec_base_path is a secondary root that complements spec_base_path; '
                . 'the loader currently requires spec_base_path to be configured for any spec-name lookup. '
                . "Set spec_base_path too, or remove enum_spec_base_path if you don't need it.";
            Diagnostics::stderr("[OpenAPI Enum Drift] FATAL: {$reason}\n");
            Diagnostics::appendGithubStepSummaryEnumDriftBlock($githubSummaryPath, $reason, isFatal: true);

            throw EnumBindingException::forConfig(
                EnumBindingReason::EnumSpecBasePathOrphaned,
                $reason,
            );
        }

        if (!str_starts_with($raw, '/')) {
            $raw = getcwd() . '/' . $raw;
        }

        return $raw;
    }

    /**
     * Generic helper for output-file-path parameters (`junit_output`,
     * `json_output`, `html_output`). Empty or whitespace-only
     * values are FATAL — silently dropping the parameter would defeat the
     * fail-loud-on-misconfiguration policy this extension enforces. Parent
     * directory writability is checked here so misconfigurations surface at
     * bootstrap rather than as a runtime WARN after tests ran.
     *
     * A missing parent directory is not treated as a misconfiguration (issue
     * #448): the conventional target is a gitignored build directory that
     * does not exist on a fresh clone or CI runner, so bootstrap creates it
     * recursively. Only a failed creation (permissions, a file occupying the
     * path, a read-only mount) or an existing-but-unwritable directory stays
     * FATAL.
     *
     * Note the bootstrap-vs-runtime severity asymmetry: the parent-dir check
     * here hard-fails the run, but a `dirname()` that disappears mid-run will
     * trip the dispatch loop's existing `file_put_contents() === false` branch,
     * which only emits a WARN in subscriber mode (FATAL+exit in the merge CLI).
     * Don't read "validated at bootstrap" as "guaranteed at write".
     *
     * Returns the absolutised path or `null` when the parameter is absent.
     */
    private static function resolveOutputPathParameter(
        ExtensionParameters $parameters,
        string $name,
        ?string $githubSummaryPath,
    ): ?string {
        if (!$parameters->has($name)) {
            return null;
        }

        $raw = trim($parameters->get($name));
        if ($raw === '') {
            $reason = sprintf(
                '%s is set but empty. Either provide an output file path or remove the parameter.',
                $name,
            );
            Diagnostics::stderr("[OpenAPI Coverage] FATAL: {$reason}\n");
            Diagnostics::appendGithubStepSummaryFatalSpecBlock($githubSummaryPath, $name, $reason);

            throw new InvalidCoverageOutputPathException($name, $reason);
        }

        if (!str_starts_with($raw, '/')) {
            $raw = getcwd() . '/' . $raw;
        }

        // Single emission site on purpose: the identity-neutral
        // "[OpenAPI Coverage]" diagnostic category is frozen at its v1.9
        // shape (see DiagnosticPrefixesBaselineTest), so both failure
        // branches share one prefixed literal.
        $parentDir = dirname($raw);
        $reason = null;
        if (!is_dir($parentDir) && !@mkdir($parentDir, 0o777, true) && !is_dir($parentDir)) {
            $reason = sprintf(
                '%s=%s: parent directory %s does not exist and could not be created. '
                . 'Create it manually or point the parameter at a writable location.',
                $name,
                $raw,
                $parentDir,
            );
        } elseif (!is_writable($parentDir)) {
            $reason = sprintf(
                '%s=%s: parent directory %s is not writable. '
                . 'Fix its permissions or point the parameter at a writable location.',
                $name,
                $raw,
                $parentDir,
            );
        }

        if ($reason !== null) {
            Diagnostics::stderr("[OpenAPI Coverage] FATAL: {$reason}\n");
            Diagnostics::appendGithubStepSummaryFatalSpecBlock($githubSummaryPath, $name, $reason);

            throw new InvalidCoverageOutputPathException($name, $reason);
        }

        return $raw;
    }

    /**
     * Read a percentage parameter (`min_endpoint_coverage` /
     * `min_response_coverage` / `min_sdk_exercise_coverage`) from
     * `phpunit.xml`. Mirrors the merge CLI's
     * resolveThreshold():
     *  - non-strict (warn-only): bad values become a `WARNING` and the gate
     *    is dropped so a misconfigured XML attribute surfaces in the log
     *    without breaking opt-in users.
     *  - strict:                 bad values become a `FATAL` and we throw
     *    {@see InvalidThresholdConfigurationException}, which `bootstrap()`
     *    catches and converts to `exit(1)`. A CI that opted into fail-fast
     *    must not silently lose its gate to a typo (issue #135 review C1).
     */
    private static function resolveThresholdParameter(
        ExtensionParameters $parameters,
        string $name,
        bool $strict,
    ): ?float {
        if (!$parameters->has($name)) {
            return null;
        }
        $raw = trim($parameters->get($name));
        if ($raw === '') {
            return null;
        }
        $parsed = CoverageThresholdEvaluator::parseThreshold($name, $raw);
        if (is_string($parsed)) {
            self::reportInvalidThreshold($name, $parsed, $strict);

            return null;
        }

        return $parsed;
    }

    /**
     * Emit a FATAL/WARNING line per `$strict`, then either drop the gate
     * (warn-only) or throw to short-circuit bootstrap with exit(1) (strict).
     * Suffix is identical for both branches so log greps match either way.
     */
    private static function reportInvalidThreshold(string $name, string $detail, bool $strict): void
    {
        $severity = $strict ? 'FATAL' : 'WARNING';
        $message = sprintf('%s; skipping threshold gate.', $detail);
        Diagnostics::stderr(sprintf("[OpenAPI Coverage] %s: %s\n", $severity, $message));

        if ($strict) {
            throw new InvalidThresholdConfigurationException($name, $message);
        }
    }

    /**
     * Only explicit falsey strings disable a flag; the empty value (the
     * `<parameter name="..." />` shorthand) counts as set, so the XML side
     * agrees with the merge CLI's no-value `--min-coverage-strict`.
     */
    private static function resolveBooleanFlag(
        ExtensionParameters $parameters,
        string $name,
        bool $default,
    ): bool {
        if (!$parameters->has($name)) {
            return $default;
        }
        $raw = trim($parameters->get($name));

        return ArgvParser::bool($raw);
    }

    /**
     * Issue #502 (additive half): read the `max_errors` parameter. Missing
     * and empty values resolve to null (unconfigured — the validators keep
     * their built-in default of 20); anything but a non-negative integer is
     * FATAL. `0` means unlimited, matching the validator constructors.
     */
    private static function resolveMaxErrorsParameter(ExtensionParameters $parameters): ?int
    {
        if (!$parameters->has('max_errors')) {
            return null;
        }

        $raw = trim($parameters->get('max_errors'));
        if ($raw === '') {
            return null;
        }

        if (!ctype_digit($raw)) {
            $reason = "Invalid max_errors parameter '{$raw}'. Expected a non-negative integer (0 = unlimited).";
            Diagnostics::stderr("[Gesso] FATAL: {$reason}\n");

            throw new InvalidValidationPolicyConfigurationException($reason);
        }

        return (int) $raw;
    }

    /**
     * Issue #502 (additive half): read a comma-separated status-code pattern
     * list (`skip_response_codes` / `skip_request_validation_response_codes`).
     * A missing parameter resolves to null (unconfigured — the validators
     * keep their built-in defaults); an explicitly empty value resolves to
     * `[]`, the Laravel config's "no skip patterns" (for
     * `skip_response_codes` that turns 5xx body validation on). Blank
     * entries and malformed regex patterns are FATAL at bootstrap — the
     * throwaway {@see StatusCodePatternSet} runs the same validation the
     * validators would, so a typo'd pattern cannot surface later as a
     * constructor error inside the first test that builds one.
     *
     * @return null|string[]
     */
    private static function resolveStatusCodeListParameter(
        ExtensionParameters $parameters,
        string $name,
    ): ?array {
        if (!$parameters->has($name)) {
            return null;
        }

        $patterns = $parameters->strings($name);
        if ($patterns === ['']) {
            return [];
        }

        try {
            new StatusCodePatternSet($patterns, $name);
        } catch (InvalidArgumentException $e) {
            Diagnostics::stderr("[Gesso] FATAL: {$e->getMessage()}\n");

            throw new InvalidValidationPolicyConfigurationException($e->getMessage(), $e);
        }

        return $patterns;
    }

    /**
     * Issues #224 / #228: read one `strict_*` mode parameter. A missing
     * parameter resolves to `$default`; an unrecognised value (the per-call
     * enums reject `fail` — per-call is warn-only) is FATAL, because
     * silently dropping a misspelled parameter would defeat the opt-in
     * fail-loud policy this extension enforces.
     *
     * @template T of object
     *
     * @param callable(string): T $parse
     * @param T $default
     * @param string $accepted the accepted values, for the FATAL line
     * @param string $prefix the diagnostic channel, e.g. `[OpenAPI Strict Required]`
     * @param callable(?string, string, bool): void $summaryBlock
     *
     * @return T
     */
    private static function resolveMode(
        ExtensionParameters $parameters,
        string $name,
        callable $parse,
        object $default,
        string $accepted,
        string $prefix,
        callable $summaryBlock,
        ?string $githubSummaryPath,
    ): object {
        if (!$parameters->has($name)) {
            return $default;
        }

        $raw = $parameters->get($name);

        try {
            return $parse($raw);
        } catch (InvalidArgumentException $e) {
            $reason = sprintf(
                '%s=%s is not recognised. Accepted: %s.',
                $name,
                trim($raw) === '' ? '<empty>' : $raw,
                $accepted,
            );
            Diagnostics::stderr("{$prefix} FATAL: {$reason}\n");
            $summaryBlock($githubSummaryPath, $reason, true);

            throw new InvalidStrictRequiredConfigurationException($reason, $e);
        }
    }

    /**
     * Auto-discover backed enums under the configured namespace prefixes and
     * run a static drift check at bootstrap for every one bound by attribute
     * or by a `components.schemas` name in `$specs`; unbound enums are
     * skipped. A misconfiguration or strict-mode drift hard-fails the run;
     * lenient mode only emits a WARNING block.
     *
     * Runs after the spec eager-load loop so every spec is cached and
     * `OpenApiSpecLoader::getBasePath()` is configured.
     *
     * @param list<string> $specs
     */
    private static function runEnumDriftCheck(ExtensionParameters $parameters, ?string $githubSummaryPath, array $specs): void
    {
        if (!self::resolveBooleanFlag($parameters, 'enum_drift_enabled', false)) {
            return;
        }

        // Issue #597: paratest bootstraps this extension in its master (SuiteLoader)
        // and again in every worker. The scan reads the same files in each, so a
        // worker skips it and the master — the Pest --parallel orchestrator included —
        // is the one process that evaluates and writes the step-summary block.
        $slot = getenv('TEST_TOKEN');
        if ($slot !== false && trim($slot) !== '') {
            return;
        }

        $namespaces = [];
        if ($parameters->has('enum_drift_scan_namespaces')) {
            $namespaces = array_values(array_filter(
                $parameters->strings('enum_drift_scan_namespaces'),
                static fn(string $entry): bool => $entry !== '',
            ));
        }

        if ($namespaces === []) {
            $reason = 'enum_drift_enabled=true but enum_drift_scan_namespaces is empty.';
            Diagnostics::stderr(
                "[OpenAPI Enum Drift] FATAL: {$reason}\n"
                . '  Action: provide one or more PSR-4 namespace prefixes '
                . "(e.g. enum_drift_scan_namespaces=\"App\\Enums\").\n",
            );
            Diagnostics::appendGithubStepSummaryEnumDriftBlock($githubSummaryPath, $reason, isFatal: true);

            throw EnumBindingException::forScan(
                EnumBindingReason::NoNamespacesConfigured,
                $reason,
            );
        }

        try {
            $fqcns = EnumScanner::scan($namespaces);
        } catch (EnumBindingException $e) {
            Diagnostics::stderr("[OpenAPI Enum Drift] FATAL: {$e->getMessage()}\n");
            Diagnostics::appendGithubStepSummaryEnumDriftBlock($githubSummaryPath, $e->getMessage(), isFatal: true);

            throw $e;
        }

        $failOnDrift = self::resolveBooleanFlag($parameters, 'enum_drift_fail_on_drift', true);

        try {
            $reports = EnumDriftAsserter::detectAll($fqcns, $specs, skipUnbound: true);
        } catch (EnumBindingException $e) {
            // Misconfigured bindings are setup errors, not drift signals,
            // and they fail loud regardless of $failOnDrift.
            Diagnostics::stderr("[OpenAPI Enum Drift] FATAL: {$e->getMessage()}\n");
            Diagnostics::appendGithubStepSummaryEnumDriftBlock($githubSummaryPath, $e->getMessage(), isFatal: true);

            throw $e;
        }

        if ($reports === []) {
            // Common when a codebase has not bound any enum yet (must not
            // fail) or when `enum_drift_scan_namespaces` is typo'd (the
            // user wants to see it). A NOTE surfaces the typo without
            // breaking the first case.
            Diagnostics::stderr(
                '[OpenAPI Enum Drift] NOTE: scan matched zero bound enums under '
                . 'configured prefixes (' . implode(', ', $namespaces) . '): no #[BoundToOpenApiEnum] '
                . 'and no components.schemas enum named after a class. '
                . "Check enum_drift_scan_namespaces if you expected matches.\n",
            );

            return;
        }

        $drifting = array_values(array_filter(
            $reports,
            static fn(EnumDriftReport $r): bool => $r->hasDrift(),
        ));

        if ($drifting === []) {
            return;
        }

        $message = EnumDriftAsserter::renderMessage($drifting, $failOnDrift);
        Diagnostics::stderr($message . "\n");
        Diagnostics::appendGithubStepSummaryEnumDriftBlock($githubSummaryPath, $message, isFatal: $failOnDrift);

        if ($failOnDrift) {
            throw new EnumDriftException($drifting, $message);
        }
    }
}
