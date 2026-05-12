<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\PHPUnit;

use const FILE_APPEND;
use const PHP_EOL;
use const STDERR;

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use PHPUnit\TextUI\TestSuiteFilterProcessor;
use Studio\OpenApiContractTesting\Coverage\InvalidCoverageOutputPathException;
use Studio\OpenApiContractTesting\Coverage\InvalidThresholdConfigurationException;
use Studio\OpenApiContractTesting\Exception\EnumBindingException;
use Studio\OpenApiContractTesting\Exception\EnumBindingReason;
use Studio\OpenApiContractTesting\Exception\EnumDriftException;
use Studio\OpenApiContractTesting\Exception\InvalidOpenApiSpecException;
use Studio\OpenApiContractTesting\Exception\SpecFileNotFoundException;
use Studio\OpenApiContractTesting\Internal\EnumScanner;
use Studio\OpenApiContractTesting\Internal\PartialRunDetector;
use Studio\OpenApiContractTesting\Schema\EnumDriftAsserter;
use Studio\OpenApiContractTesting\Schema\EnumDriftReport;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;

use function array_filter;
use function array_map;
use function array_values;
use function dirname;
use function explode;
use function fflush;
use function file_put_contents;
use function fwrite;
use function getcwd;
use function getenv;
use function implode;
use function in_array;
use function is_dir;
use function is_numeric;
use function is_writable;
use function sprintf;
use function str_starts_with;
use function sys_get_temp_dir;
use function trim;

final class OpenApiCoverageExtension implements Extension
{
    /**
     * Default location used by paratest workers when no `sidecar_dir`
     * parameter is configured. Kept stable across runs so the merge CLI
     * can find it without coordination, and namespaced enough that other
     * tools won't collide with it.
     */
    public const DEFAULT_SIDECAR_SUBDIR = 'openapi-coverage-sidecars';

    /**
     * Test-only override for STDERR writes.
     *
     * @var null|resource
     */
    private static $stderrOverride;

    public static function defaultSidecarDir(): string
    {
        return sys_get_temp_dir() . '/' . self::DEFAULT_SIDECAR_SUBDIR;
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
        self::$stderrOverride = $stream;
    }

    /**
     * @internal exposed so the extension's subscriber can reuse the stream override.
     */
    public static function writeStderr(string $message): void
    {
        fwrite(self::$stderrOverride ?? STDERR, $message);
    }

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        try {
            $this->setupExtension(
                $facade,
                $parameters,
                getenv('GITHUB_STEP_SUMMARY') ?: null,
                self::detectPartialRun($configuration),
            );
        } catch (EnumBindingException|EnumDriftException|InvalidCoverageOutputPathException|InvalidOpenApiSpecException|InvalidThresholdConfigurationException|SpecFileNotFoundException) {
            // setupExtension() has already written a FATAL line to stderr and
            // (if GITHUB_STEP_SUMMARY is set) appended a fatal block to it.
            // PHPUnit's ExtensionBootstrapper::bootstrap() wraps this call in
            // catch(Throwable) and demotes it to testRunnerTriggeredPhpunitWarning,
            // which only fails the run when consumers opt in via
            // failOnPhpunitWarning (or failOnAllIssues). Depending on that
            // would re-open the silent-pass hole this extension exists to
            // close, so force a non-zero exit here. fflush guards against
            // output ordering surprises on exit().
            if (self::$stderrOverride === null) {
                fflush(STDERR);
            }

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
        ParameterCollection $parameters,
        ?string $githubSummaryPath,
        ?PartialRunDetector $partialRun = null,
    ): void {
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
                $stripPrefixes = array_map('trim', explode(',', $parameters->get('strip_prefixes')));
            }

            OpenApiSpecLoader::configure(
                $basePath,
                $stripPrefixes,
                enumBasePath: $enumBasePath,
            );
        }

        $specs = ['front'];
        if ($parameters->has('specs')) {
            $specs = array_map('trim', explode(',', $parameters->get('specs')));
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
                self::writeStderr(
                    "[OpenAPI Coverage] FATAL: spec '{$spec}' configured in `specs=` is not loadable: {$e->getMessage()}\n"
                    . "  Action: regenerate the bundle (e.g. `cd openapi && npm run bundle`) or remove '{$spec}' from `specs=`.\n",
                );
                self::appendGithubStepSummaryFatalBlock($githubSummaryPath, $spec, $e->getMessage());

                throw $e;
            } catch (InvalidOpenApiSpecException $e) {
                self::writeStderr("[OpenAPI Coverage] FATAL: Invalid OpenAPI spec '{$spec}': {$e->getMessage()}\n");
                self::appendGithubStepSummaryFatalBlock($githubSummaryPath, $spec, $e->getMessage());

                throw $e;
            }
        }

        self::runEnumDriftCheck($parameters, $githubSummaryPath);

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

        // Resolve strict first so threshold validation can promote bad values
        // to FATAL when the user opted in to fail-fast (issue #135 review C1).
        $minCoverageStrict = self::resolveStrictFlag($parameters);
        $minEndpointCoverage = self::resolveThresholdParameter($parameters, 'min_endpoint_coverage', $minCoverageStrict);
        $minResponseCoverage = self::resolveThresholdParameter($parameters, 'min_response_coverage', $minCoverageStrict);

        if ($facade === null) {
            return;
        }

        $facade->registerSubscriber(new CoverageReportSubscriber(
            specs: $specs,
            outputFile: $outputFile,
            consoleOutput: $consoleOutput,
            githubSummaryPath: $githubSummaryPath,
            sidecarDir: $sidecarDir,
            minEndpointCoverage: $minEndpointCoverage,
            minResponseCoverage: $minResponseCoverage,
            minCoverageStrict: $minCoverageStrict,
            junitOutput: $junitOutput,
            jsonOutput: $jsonOutput,
            htmlOutput: $htmlOutput,
            partialRun: $partialRun,
        ));
    }

    /**
     * Issue #221: read PHPUnit's structured selection signals off
     * {@see Configuration} so the subscriber can skip persistent writes
     * on partial runs. The set mirrors {@see TestSuiteFilterProcessor::process()}'s
     * own filter detection (`hasFilter` / `hasExcludeFilter` / `hasGroups` /
     * `hasExcludeGroups` / `hasTestsCovering` / `hasTestsUsing` /
     * `hasTestsRequiringPhpExtension`) plus the TestSuiteBuilder-stage
     * selections that bypass the filter pipeline: `hasCliArguments()`
     * (positional path args like `phpunit tests/Feature/Foo/`) and
     * `includeTestSuites()` / `excludeTestSuites()` (`--testsuite` /
     * `--exclude-testsuite`). The `TestSuite\Filtered` event was
     * deliberately not used as the signal because it does not fire for
     * those builder-stage selections — and issue #221's primary
     * reproducer is the CLI path-arg case.
     */
    private static function detectPartialRun(Configuration $configuration): PartialRunDetector
    {
        return PartialRunDetector::fromSignals(
            hasCliArguments: $configuration->hasCliArguments(),
            hasFilter: $configuration->hasFilter(),
            hasExcludeFilter: $configuration->hasExcludeFilter(),
            hasGroups: $configuration->hasGroups(),
            hasExcludeGroups: $configuration->hasExcludeGroups(),
            includeTestSuites: $configuration->includeTestSuites(),
            excludeTestSuites: $configuration->excludeTestSuites(),
            hasTestsCovering: $configuration->hasTestsCovering(),
            hasTestsUsing: $configuration->hasTestsUsing(),
            hasTestsRequiringPhpExtension: $configuration->hasTestsRequiringPhpExtension(),
        );
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
        ParameterCollection $parameters,
        ?string $githubSummaryPath,
    ): ?string {
        if (!$parameters->has('enum_spec_base_path')) {
            return null;
        }

        $raw = trim($parameters->get('enum_spec_base_path'));
        if ($raw === '') {
            $reason = 'enum_spec_base_path is set but empty. '
                . 'Either provide a directory path or remove the parameter to fall back to spec_base_path.';
            self::writeStderr("[OpenAPI Enum Drift] FATAL: {$reason}\n");
            self::appendGithubStepSummaryEnumDriftBlock($githubSummaryPath, $reason, isFatal: true);

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
            self::writeStderr("[OpenAPI Enum Drift] FATAL: {$reason}\n");
            self::appendGithubStepSummaryEnumDriftBlock($githubSummaryPath, $reason, isFatal: true);

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
     * Note the bootstrap-vs-runtime severity asymmetry: the parent-dir check
     * here hard-fails the run, but a `dirname()` that disappears mid-run will
     * trip the dispatch loop's existing `file_put_contents() === false` branch,
     * which only emits a WARN in subscriber mode (FATAL+exit in the merge CLI).
     * Don't read "validated at bootstrap" as "guaranteed at write".
     *
     * Returns the absolutised path or `null` when the parameter is absent.
     */
    private static function resolveOutputPathParameter(
        ParameterCollection $parameters,
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
            self::writeStderr("[OpenAPI Coverage] FATAL: {$reason}\n");
            self::appendGithubStepSummaryFatalBlock($githubSummaryPath, $name, $reason);

            throw new InvalidCoverageOutputPathException($name, $reason);
        }

        if (!str_starts_with($raw, '/')) {
            $raw = getcwd() . '/' . $raw;
        }

        $parentDir = dirname($raw);
        if (!is_dir($parentDir) || !is_writable($parentDir)) {
            $reason = sprintf(
                '%s=%s: parent directory %s does not exist or is not writable.',
                $name,
                $raw,
                $parentDir,
            );
            self::writeStderr("[OpenAPI Coverage] FATAL: {$reason}\n");
            self::appendGithubStepSummaryFatalBlock($githubSummaryPath, $name, $reason);

            throw new InvalidCoverageOutputPathException($name, $reason);
        }

        return $raw;
    }

    /**
     * Read a percentage parameter (`min_endpoint_coverage` /
     * `min_response_coverage`) from `phpunit.xml`. Mirrors the merge CLI's
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
        ParameterCollection $parameters,
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
        if (!is_numeric($raw)) {
            self::reportInvalidThreshold($name, sprintf("%s='%s' is not a number", $name, $raw), $strict);

            return null;
        }
        $value = (float) $raw;
        if ($value < 0.0 || $value > 100.0) {
            self::reportInvalidThreshold(
                $name,
                sprintf('%s=%s is out of range (expected 0-100)', $name, (string) $value),
                $strict,
            );

            return null;
        }

        return $value;
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
        self::writeStderr(sprintf("[OpenAPI Coverage] %s: %s\n", $severity, $message));

        if ($strict) {
            throw new InvalidThresholdConfigurationException($name, $message);
        }
    }

    private static function resolveStrictFlag(ParameterCollection $parameters): bool
    {
        if (!$parameters->has('min_coverage_strict')) {
            return false;
        }
        $raw = trim($parameters->get('min_coverage_strict'));

        // Symmetric with the merge CLI's `--min-coverage-strict` (no-value
        // form): only explicit falsey strings disable strict mode. Empty
        // value (the `<parameter name="..." />` shorthand) is treated as
        // "set" so the XML and CLI sides agree.
        return !in_array($raw, ['0', 'false', 'no'], true);
    }

    /**
     * Generalization of {@see resolveStrictFlag()} with a configurable
     * default for the missing-parameter case.
     */
    private static function resolveBooleanFlag(
        ParameterCollection $parameters,
        string $name,
        bool $default,
    ): bool {
        if (!$parameters->has($name)) {
            return $default;
        }
        $raw = trim($parameters->get($name));

        return !in_array($raw, ['0', 'false', 'no'], true);
    }

    /**
     * Auto-discover `#[BoundToOpenApiEnum]` enums under the configured
     * namespace prefixes and run a static drift check at bootstrap. A
     * misconfiguration or strict-mode drift hard-fails the run; lenient
     * mode only emits a WARNING block.
     *
     * Runs after the spec eager-load loop so `OpenApiSpecLoader::getBasePath()`
     * is already configured by the time `EnumDriftAsserter` resolves
     * `#[BoundToOpenApiEnum]` paths.
     */
    private static function runEnumDriftCheck(ParameterCollection $parameters, ?string $githubSummaryPath): void
    {
        if (!self::resolveBooleanFlag($parameters, 'enum_drift_enabled', false)) {
            return;
        }

        $namespaces = [];
        if ($parameters->has('enum_drift_scan_namespaces')) {
            $namespaces = array_values(array_filter(
                array_map('trim', explode(',', $parameters->get('enum_drift_scan_namespaces'))),
                static fn(string $entry): bool => $entry !== '',
            ));
        }

        if ($namespaces === []) {
            $reason = 'enum_drift_enabled=true but enum_drift_scan_namespaces is empty.';
            self::writeStderr(
                "[OpenAPI Enum Drift] FATAL: {$reason}\n"
                . '  Action: provide one or more PSR-4 namespace prefixes '
                . "(e.g. enum_drift_scan_namespaces=\"App\\Enums\").\n",
            );
            self::appendGithubStepSummaryEnumDriftBlock($githubSummaryPath, $reason, isFatal: true);

            throw EnumBindingException::forScan(
                EnumBindingReason::NoNamespacesConfigured,
                $reason,
            );
        }

        try {
            $fqcns = EnumScanner::scan($namespaces);
        } catch (EnumBindingException $e) {
            self::writeStderr("[OpenAPI Enum Drift] FATAL: {$e->getMessage()}\n");
            self::appendGithubStepSummaryEnumDriftBlock($githubSummaryPath, $e->getMessage(), isFatal: true);

            throw $e;
        }

        $failOnDrift = self::resolveBooleanFlag($parameters, 'enum_drift_fail_on_drift', true);

        if ($fqcns === []) {
            // No bound enums under the configured prefixes. Common in two
            // cases: (a) a codebase mid-migration that hasn't annotated any
            // enums yet, and (b) a typo'd `enum_drift_scan_namespaces`
            // (e.g. `App\Enum` vs `App\Enums`). The first is intentional
            // and must not fail; the second is a misconfiguration the user
            // wants to see. A NOTE line surfaces the typo without breaking
            // the migration use case.
            self::writeStderr(
                '[OpenAPI Enum Drift] NOTE: scan matched zero #[BoundToOpenApiEnum] enums under '
                . 'configured prefixes (' . implode(', ', $namespaces) . '). '
                . "Check enum_drift_scan_namespaces if you expected matches.\n",
            );

            return;
        }

        if ($failOnDrift) {
            try {
                EnumDriftAsserter::assertNoDrift($fqcns, true);
            } catch (EnumBindingException|EnumDriftException $e) {
                self::writeStderr($e->getMessage() . "\n");
                self::appendGithubStepSummaryEnumDriftBlock($githubSummaryPath, $e->getMessage(), isFatal: true);

                throw $e;
            }

            return;
        }

        try {
            $reports = EnumDriftAsserter::detectAll($fqcns);
        } catch (EnumBindingException $e) {
            // Misconfigured bindings are setup errors, not drift signals,
            // and they fail loud regardless of $failOnDrift — same policy
            // as the asserter (`EnumDriftAsserter::detectOne`).
            self::writeStderr("[OpenAPI Enum Drift] FATAL: {$e->getMessage()}\n");
            self::appendGithubStepSummaryEnumDriftBlock($githubSummaryPath, $e->getMessage(), isFatal: true);

            throw $e;
        }

        $drifting = array_values(array_filter(
            $reports,
            static fn(EnumDriftReport $r): bool => $r->hasDrift(),
        ));

        if ($drifting === []) {
            return;
        }

        $message = EnumDriftAsserter::renderMessage($drifting, false);
        self::writeStderr($message . "\n");
        self::appendGithubStepSummaryEnumDriftBlock($githubSummaryPath, $message, isFatal: false);
    }

    /**
     * Append a Markdown block describing an enum-drift outcome to the
     * GitHub Actions Step Summary file. Mirrors
     * {@see appendGithubStepSummaryFatalBlock()} but keeps a separate body
     * so the spec-load and enum-drift narratives stay distinct.
     */
    private static function appendGithubStepSummaryEnumDriftBlock(
        ?string $path,
        string $body,
        bool $isFatal,
    ): void {
        if ($path === null) {
            return;
        }

        $title = $isFatal
            ? '## :rotating_light: FATAL OpenAPI enum drift'
            : '## :warning: OpenAPI enum drift';

        $block = $title . PHP_EOL
            . PHP_EOL
            . ($isFatal
                ? 'One or more `#[BoundToOpenApiEnum]` checks failed and the test run was aborted.'
                : 'One or more `#[BoundToOpenApiEnum]` checks reported drift.') . PHP_EOL
            . PHP_EOL
            . '```' . PHP_EOL
            . $body . PHP_EOL
            . '```' . PHP_EOL
            . PHP_EOL;

        $written = file_put_contents($path, $block, FILE_APPEND);
        if ($written === false) {
            self::writeStderr("[OpenAPI Enum Drift] WARNING: Failed to append block to GITHUB_STEP_SUMMARY ({$path})\n");
        }
    }

    private static function appendGithubStepSummaryFatalBlock(?string $path, string $spec, string $reason): void
    {
        if ($path === null) {
            return;
        }

        $block = '## :rotating_light: FATAL OpenAPI spec error' . PHP_EOL
            . PHP_EOL
            . "Spec `{$spec}` could not be loaded and the test run was aborted." . PHP_EOL
            . PHP_EOL
            . '```' . PHP_EOL
            . $reason . PHP_EOL
            . '```' . PHP_EOL
            . PHP_EOL;

        $written = file_put_contents($path, $block, FILE_APPEND);
        if ($written === false) {
            self::writeStderr("[OpenAPI Coverage] WARNING: Failed to append FATAL block to GITHUB_STEP_SUMMARY ({$path})\n");
        }
    }
}
