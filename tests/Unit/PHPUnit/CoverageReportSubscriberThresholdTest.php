<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\PHPUnit;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Internal\PartialRunDecision;
use Studio\Gesso\PHPUnit\ConsoleOutput;
use Studio\Gesso\PHPUnit\CoverageReportSubscriber;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function getenv;
use function glob;
use function is_dir;
use function ob_get_clean;
use function ob_start;
use function putenv;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Pins the issue #135 contract on {@see CoverageReportSubscriber}: in
 * sequential mode the threshold gate runs after rendering; in worker mode
 * the gate is skipped entirely (merge CLI is the gating point for paratest).
 */
class CoverageReportSubscriberThresholdTest extends TestCase
{
    private string $tmpDir = '';
    private ?string $previousTestToken = null;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');

        $this->tmpDir = sys_get_temp_dir() . '/openapi-coverage-threshold-' . uniqid('', true);

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
    public function strict_threshold_miss_invokes_exit_handler_with_one(): void
    {
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );

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
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertSame(1, $exitCode, 'strict threshold miss must request exit(1)');
        $this->assertStringContainsString('[OpenAPI Coverage] FAIL:', $stderr);
        $this->assertStringContainsString('endpoint coverage', $stderr);
    }

    #[Test]
    public function non_strict_threshold_miss_only_warns(): void
    {
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );

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
            minCoverageStrict: false,
            exitHandler: static function (int $code) use (&$exitCode): void {
                $exitCode = $code;
            },
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertNull($exitCode, 'warn-only mode must not exit');
        $this->assertStringContainsString('[OpenAPI Coverage] WARN:', $stderr);
    }

    #[Test]
    public function worker_mode_does_not_evaluate_threshold(): void
    {
        // In paratest the merge CLI is the gate. Worker subscribers must not
        // double-evaluate — that would short-circuit a worker before its
        // sidecar reaches the merge step.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        putenv('TEST_TOKEN=3');

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
        );

        $subscriber->notify($this->fakeExecutionFinished());

        $this->assertNull($exitCode);
        $this->assertStringNotContainsString('FAIL:', $stderr);
        $this->assertStringNotContainsString('WARN:', $stderr);
    }

    #[Test]
    public function strict_threshold_fails_when_no_coverage_recorded(): void
    {
        // C2: a strict CI gate must not silently pass when zero contract
        // assertions ran. Pre-fix, the subscriber's `if ($results === [])`
        // early-return skipped the gate entirely → exit 0, missed signal.
        // No `recordResponse()` call here — `computeAllResults()` returns [].

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
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('FATAL', $stderr);
        $this->assertStringContainsString('no contract test coverage was recorded', $stderr);
    }

    #[Test]
    public function warn_only_threshold_logs_when_no_coverage_recorded(): void
    {
        // Symmetric to the strict case but stays exit 0 — a warn-only CI
        // still surfaces the misconfiguration without breaking the build.
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
            // strict omitted → warn-only
            exitHandler: static function (int $code) use (&$exitCode): void {
                $exitCode = $code;
            },
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertNull($exitCode);
        $this->assertStringContainsString('WARNING', $stderr);
        $this->assertStringContainsString('no contract test coverage was recorded', $stderr);
    }

    #[Test]
    public function no_coverage_without_threshold_stays_silent(): void
    {
        // Backwards compat: when no threshold is configured, an empty run
        // must not start emitting noise — that would break callers who
        // don't use the gate.
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
            exitHandler: static function (int $code) use (&$exitCode): void {
                $exitCode = $code;
            },
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertNull($exitCode);
        $this->assertSame('', $stderr);
    }

    #[Test]
    public function strict_threshold_miss_on_response_only_invokes_exit_handler(): void
    {
        // Symmetric coverage: the endpoint-only path is exercised above; pin
        // the response-only path so a future refactor that swaps
        // minEndpoint/minResponse parameters would surface here.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );

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
            minEndpointCoverage: null,
            minResponseCoverage: 80.0,
            minCoverageStrict: true,
            exitHandler: static function (int $code) use (&$exitCode): void {
                $exitCode = $code;
            },
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('response coverage', $stderr);
        $this->assertStringNotContainsString('endpoint coverage', $stderr);
    }

    #[Test]
    public function passing_threshold_does_not_emit_anything(): void
    {
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );

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
            minEndpointCoverage: 0.0, // anything >= 0 passes
            minCoverageStrict: true,
            exitHandler: static function (int $code) use (&$exitCode): void {
                $exitCode = $code;
            },
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertNull($exitCode);
        $this->assertStringNotContainsString('FAIL:', $stderr);
        $this->assertStringNotContainsString('WARN:', $stderr);
    }

    #[Test]
    public function partial_run_skips_strict_threshold_gate_with_note(): void
    {
        // Issue #438: a subset run cannot prove a suite-wide coverage rate
        // any more than it can prove a strict_required intersection. The
        // gate must skip (with a NOTE naming the selection signal) instead
        // of failing a correct-but-partial run.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );

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
            partialRun: PartialRunDecision::partial('--testsuite'),
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertNull($exitCode, 'partial run must not trip the strict threshold gate');
        $this->assertStringNotContainsString('FAIL:', $stderr);
        $this->assertStringContainsString('[Gesso] NOTE:', $stderr);
        $this->assertStringContainsString('skipped on partial runs', $stderr);
        $this->assertStringContainsString('--testsuite', $stderr);
    }

    #[Test]
    public function partial_run_with_no_coverage_skips_strict_gate_with_note(): void
    {
        // Issue #438 reproduction: `--testsuite=Unit` records zero contract
        // coverage — correctly — and the empty-results strict gate exited 1.
        // No recordResponse() call here so computeAllResults() returns [].
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
            partialRun: PartialRunDecision::partial('--testsuite'),
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertNull($exitCode, 'a partial run with no coverage must not exit non-zero');
        $this->assertStringNotContainsString('FATAL', $stderr);
        $this->assertStringContainsString('[Gesso] NOTE:', $stderr);
        $this->assertStringContainsString('skipped on partial runs', $stderr);
    }

    #[Test]
    public function partial_run_skips_warn_only_threshold_gate_with_note(): void
    {
        // The warn-only gate skips too: a subset rate compared against a
        // whole-suite threshold is noise, not signal. The NOTE replaces it.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );

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
            minCoverageStrict: false,
            exitHandler: static function (int $code) use (&$exitCode): void {
                $exitCode = $code;
            },
            partialRun: PartialRunDecision::partial('--filter'),
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertNull($exitCode);
        $this->assertStringNotContainsString('WARN:', $stderr);
        $this->assertStringContainsString('[Gesso] NOTE:', $stderr);
        $this->assertStringContainsString('skipped on partial runs', $stderr);
        $this->assertStringContainsString('--filter', $stderr);
    }

    #[Test]
    public function partial_run_without_threshold_emits_no_gate_note(): void
    {
        // No threshold configured → the skip NOTE would be noise about a
        // gate the user never opted into.
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
            exitHandler: static function (int $code) use (&$exitCode): void {
                $exitCode = $code;
            },
            partialRun: PartialRunDecision::partial('--testsuite'),
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertNull($exitCode);
        $this->assertSame('', $stderr);
    }

    private function fakeExecutionFinished(): ExecutionFinished
    {
        return (new ReflectionClass(ExecutionFinished::class))->newInstanceWithoutConstructor();
    }
}
