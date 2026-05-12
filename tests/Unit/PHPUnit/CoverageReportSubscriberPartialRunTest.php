<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\PHPUnit;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\Internal\PartialRunDetector;
use Studio\OpenApiContractTesting\PHPUnit\ConsoleOutput;
use Studio\OpenApiContractTesting\PHPUnit\CoverageReportSubscriber;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;

use function file_get_contents;
use function file_put_contents;
use function getenv;
use function glob;
use function is_dir;
use function mkdir;
use function ob_get_clean;
use function ob_start;
use function putenv;
use function rmdir;
use function substr_count;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Pins the issue #221 contract on {@see CoverageReportSubscriber}: when
 * a {@see PartialRunDetector} is supplied with `isPartial=true`, the
 * subscriber must skip every persistent file write (output_file,
 * junit_output, json_output, html_output, GITHUB_STEP_SUMMARY) and
 * emit a single stderr WARNING enumerating the skipped targets.
 * Console rendering and the threshold gate must remain unaffected.
 */
class CoverageReportSubscriberPartialRunTest extends TestCase
{
    private string $tmpDir = '';
    private ?string $previousTestToken = null;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');

        $this->tmpDir = sys_get_temp_dir() . '/openapi-coverage-partial-' . uniqid('', true);
        mkdir($this->tmpDir, 0o755, recursive: true);

        $current = getenv('TEST_TOKEN');
        $this->previousTestToken = $current === false ? null : $current;
        putenv('TEST_TOKEN');
    }

    protected function tearDown(): void
    {
        if ($this->previousTestToken === null) {
            putenv('TEST_TOKEN');
        } else {
            putenv('TEST_TOKEN=' . $this->previousTestToken);
        }

        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $entry) {
                @unlink($entry);
            }
            @rmdir($this->tmpDir);
        }

        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function partial_run_skips_output_file_write_and_emits_warning(): void
    {
        $this->recordOneEndpoint();
        $outputFile = $this->tmpDir . '/coverage.md';

        $stderr = '';
        $subscriber = new CoverageReportSubscriber(
            specs: ['petstore-3.0'],
            outputFile: $outputFile,
            consoleOutput: ConsoleOutput::DEFAULT,
            githubSummaryPath: null,
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            sidecarDir: $this->tmpDir,
            partialRun: $this->partial('--filter'),
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertFileDoesNotExist($outputFile, 'partial run must not overwrite output_file');
        $this->assertStringContainsString('[OpenAPI Coverage] WARNING', $stderr);
        $this->assertStringContainsString('output_file', $stderr);
        $this->assertStringContainsString('--filter', $stderr);
    }

    #[Test]
    public function partial_run_skips_junit_json_html_outputs_in_a_single_warning(): void
    {
        $this->recordOneEndpoint();
        $outputFile = $this->tmpDir . '/coverage.md';
        $junitPath = $this->tmpDir . '/coverage.junit.xml';
        $jsonPath = $this->tmpDir . '/coverage.json';
        $htmlPath = $this->tmpDir . '/coverage.html';

        $stderr = '';
        $subscriber = new CoverageReportSubscriber(
            specs: ['petstore-3.0'],
            outputFile: $outputFile,
            consoleOutput: ConsoleOutput::DEFAULT,
            githubSummaryPath: null,
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            sidecarDir: $this->tmpDir,
            junitOutput: $junitPath,
            jsonOutput: $jsonPath,
            htmlOutput: $htmlPath,
            partialRun: $this->partial('test paths'),
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertFileDoesNotExist($outputFile);
        $this->assertFileDoesNotExist($junitPath);
        $this->assertFileDoesNotExist($jsonPath);
        $this->assertFileDoesNotExist($htmlPath);

        // One warning enumerating every configured target.
        $this->assertSame(1, substr_count($stderr, '[OpenAPI Coverage] WARNING'), 'one consolidated warning expected');
        $this->assertStringContainsString('output_file', $stderr);
        $this->assertStringContainsString('junit_output', $stderr);
        $this->assertStringContainsString('json_output', $stderr);
        $this->assertStringContainsString('html_output', $stderr);
    }

    #[Test]
    public function partial_run_skips_github_step_summary_append(): void
    {
        // GITHUB_STEP_SUMMARY uses FILE_APPEND (no overwrite), but a
        // partial-run coverage block would still mislead in a CI summary.
        // Treat it as a persistent artifact and skip uniformly.
        $this->recordOneEndpoint();
        $summary = $this->tmpDir . '/step-summary.md';
        file_put_contents($summary, "pre-existing\n");

        $stderr = '';
        $subscriber = new CoverageReportSubscriber(
            specs: ['petstore-3.0'],
            outputFile: null,
            consoleOutput: ConsoleOutput::DEFAULT,
            githubSummaryPath: $summary,
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            sidecarDir: $this->tmpDir,
            partialRun: $this->partial('--group'),
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertSame("pre-existing\n", file_get_contents($summary), 'GITHUB_STEP_SUMMARY must not be appended to on partial runs');
        $this->assertStringContainsString('GITHUB_STEP_SUMMARY', $stderr);
    }

    #[Test]
    public function partial_run_still_renders_console_output(): void
    {
        $this->recordOneEndpoint();

        $subscriber = new CoverageReportSubscriber(
            specs: ['petstore-3.0'],
            outputFile: $this->tmpDir . '/coverage.md',
            consoleOutput: ConsoleOutput::DEFAULT,
            githubSummaryPath: null,
            stderrWriter: static fn(string $msg): null => null,
            sidecarDir: $this->tmpDir,
            partialRun: $this->partial('--filter'),
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        $stdout = (string) ob_get_clean();

        $this->assertNotSame('', $stdout, 'partial run still renders the console summary so developers see what was covered');
    }

    #[Test]
    public function partial_run_still_evaluates_threshold_gate(): void
    {
        // The threshold gate runs against in-memory results; it does not
        // touch persistent files. Issue #221 is about persistent docs, so
        // the gate keeps firing under partial runs (consumers who wanted
        // gate exemption already opt out via non-strict mode).
        $this->recordOneEndpoint();

        $exitCode = null;
        $stderr = '';
        $subscriber = new CoverageReportSubscriber(
            specs: ['petstore-3.0'],
            outputFile: null,
            consoleOutput: ConsoleOutput::DEFAULT,
            githubSummaryPath: null,
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            sidecarDir: $this->tmpDir,
            minEndpointCoverage: 80.0,
            minCoverageStrict: true,
            exitHandler: static function (int $code) use (&$exitCode): void {
                $exitCode = $code;
            },
            partialRun: $this->partial('--filter'),
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertSame(1, $exitCode, 'threshold gate must still fire on partial runs');
        $this->assertStringContainsString('[OpenAPI Coverage] FAIL:', $stderr);
    }

    #[Test]
    public function partial_run_emits_no_warning_when_no_persistent_outputs_configured(): void
    {
        // No false alarm when the user isn't using any persistent output:
        // there is nothing being "skipped", so silence is correct.
        $this->recordOneEndpoint();

        $stderr = '';
        $subscriber = new CoverageReportSubscriber(
            specs: ['petstore-3.0'],
            outputFile: null,
            consoleOutput: ConsoleOutput::DEFAULT,
            githubSummaryPath: null,
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            sidecarDir: $this->tmpDir,
            partialRun: $this->partial('--filter'),
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertSame('', $stderr, 'no WARNING when there are no persistent outputs to skip');
    }

    #[Test]
    public function full_run_behavior_unchanged_when_partial_run_null(): void
    {
        // Backwards-compat smoke: omitting the partialRun parameter means
        // pre-#221 behavior — output_file is written, no WARNING.
        $this->recordOneEndpoint();
        $outputFile = $this->tmpDir . '/coverage.md';

        $stderr = '';
        $subscriber = new CoverageReportSubscriber(
            specs: ['petstore-3.0'],
            outputFile: $outputFile,
            consoleOutput: ConsoleOutput::DEFAULT,
            githubSummaryPath: null,
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            sidecarDir: $this->tmpDir,
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertFileExists($outputFile);
        $this->assertSame('', $stderr);
    }

    #[Test]
    public function full_run_detector_does_not_skip_writes(): void
    {
        // A non-null detector with isPartial=false (i.e. an explicit full
        // run) must behave identically to the null case: write everything.
        $this->recordOneEndpoint();
        $outputFile = $this->tmpDir . '/coverage.md';

        $stderr = '';
        $subscriber = new CoverageReportSubscriber(
            specs: ['petstore-3.0'],
            outputFile: $outputFile,
            consoleOutput: ConsoleOutput::DEFAULT,
            githubSummaryPath: null,
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            sidecarDir: $this->tmpDir,
            partialRun: PartialRunDetector::fromSignals(
                hasCliArguments: false,
                hasFilter: false,
                hasExcludeFilter: false,
                hasGroups: false,
                hasExcludeGroups: false,
                includeTestSuites: [],
                excludeTestSuites: [],
                hasTestsCovering: false,
                hasTestsUsing: false,
                hasTestsRequiringPhpExtension: false,
            ),
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertFileExists($outputFile);
        $this->assertSame('', $stderr);
    }

    private function recordOneEndpoint(): void
    {
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
    }

    /**
     * Build a {@see PartialRunDetector} that flags the given signal as
     * active. Reused across tests to keep the partial-run payload short.
     */
    private function partial(string $signal): PartialRunDetector
    {
        return PartialRunDetector::fromSignals(
            hasCliArguments: $signal === 'test paths',
            hasFilter: $signal === '--filter',
            hasExcludeFilter: false,
            hasGroups: $signal === '--group',
            hasExcludeGroups: false,
            includeTestSuites: [],
            excludeTestSuites: [],
            hasTestsCovering: false,
            hasTestsUsing: false,
            hasTestsRequiringPhpExtension: false,
        );
    }

    /**
     * Build a stub `ExecutionFinished` without invoking its constructor —
     * `notify()` doesn't read the event, and PHPUnit's Telemetry ctor
     * has churned across 11/12/13 (matches the pattern already used in
     * the worker-mode / threshold sibling tests).
     */
    private function fakeExecutionFinished(): ExecutionFinished
    {
        return (new ReflectionClass(ExecutionFinished::class))->newInstanceWithoutConstructor();
    }
}
