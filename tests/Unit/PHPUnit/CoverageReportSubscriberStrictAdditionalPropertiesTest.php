<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\PHPUnit;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Studio\Gesso\Coverage\CoverageSidecarEnvelope;
use Studio\Gesso\Coverage\CoverageSidecarReader;
use Studio\Gesso\Internal\PartialRunDecision;
use Studio\Gesso\PHPUnit\ConsoleOutput;
use Studio\Gesso\PHPUnit\CoverageReportSubscriber;
use Studio\Gesso\Validation\Strict\StrictAdditionalPropertiesMode;
use Studio\Gesso\Validation\Strict\StrictAdditionalPropertiesTracker;

use function getenv;
use function glob;
use function is_dir;
use function mkdir;
use function ob_get_clean;
use function ob_start;
use function putenv;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class CoverageReportSubscriberStrictAdditionalPropertiesTest extends TestCase
{
    private ?string $previousTestToken = null;
    private string $tmpSidecarDir;

    protected function setUp(): void
    {
        parent::setUp();
        $token = getenv('TEST_TOKEN');
        $this->previousTestToken = $token === false ? null : $token;
        putenv('TEST_TOKEN');
        $this->tmpSidecarDir = sys_get_temp_dir() . '/gesso-strict-additional-' . uniqid('', true);
        mkdir($this->tmpSidecarDir, 0o755, recursive: true);
    }

    protected function tearDown(): void
    {
        if ($this->previousTestToken === null) {
            putenv('TEST_TOKEN');
        } else {
            putenv('TEST_TOKEN=' . $this->previousTestToken);
        }
        if (is_dir($this->tmpSidecarDir)) {
            foreach (glob($this->tmpSidecarDir . '/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($this->tmpSidecarDir);
        }
        parent::tearDown();
    }

    #[Test]
    public function warn_mode_reports_pointer_and_operation_without_exiting(): void
    {
        $tracker = $this->trackerWithFinding();
        $stderr = '';
        $exitCode = null;
        $subscriber = $this->subscriber($tracker, StrictAdditionalPropertiesMode::Warn, $stderr, $exitCode);

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertNull($exitCode);
        $this->assertStringContainsString('[OpenAPI Strict Additional Properties] WARNING', $stderr);
        $this->assertStringContainsString('/trace_id', $stderr);
        $this->assertStringContainsString('GET /users', $stderr);
    }

    #[Test]
    public function fail_mode_requests_non_zero_exit(): void
    {
        $stderr = '';
        $exitCode = null;
        $subscriber = $this->subscriber(
            $this->trackerWithFinding(),
            StrictAdditionalPropertiesMode::Fail,
            $stderr,
            $exitCode,
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('[OpenAPI Strict Additional Properties] FATAL', $stderr);
    }

    #[Test]
    public function worker_sidecar_contains_tracker_even_when_run_level_mode_is_off(): void
    {
        putenv('TEST_TOKEN=7');
        $stderr = '';
        $exitCode = null;
        $subscriber = $this->subscriber(
            $this->trackerWithFinding(),
            StrictAdditionalPropertiesMode::Off,
            $stderr,
            $exitCode,
            $this->tmpSidecarDir,
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $payloads = CoverageSidecarReader::readDir($this->tmpSidecarDir);
        $this->assertCount(1, $payloads);
        $this->assertSame('', $stderr);
        $this->assertNull($exitCode);
        $parsed = CoverageSidecarEnvelope::parse($payloads[0]);
        $this->assertNotNull($parsed['strictAdditionalProperties']);
        $this->assertSame(1, $parsed['strictAdditionalProperties']['evaluations']);
    }

    #[Test]
    public function partial_run_reports_to_stderr_without_writing_step_summary(): void
    {
        $stderr = '';
        $summaryPath = $this->tmpSidecarDir . '/summary.md';
        $subscriber = new CoverageReportSubscriber(
            specs: [],
            outputFile: null,
            consoleOutput: ConsoleOutput::DEFAULT,
            githubSummaryPath: $summaryPath,
            stderrWriter: static function (string $message) use (&$stderr): void {
                $stderr .= $message;
            },
            partialRun: PartialRunDecision::fromSignals(
                hasCliArguments: true,
                hasFilter: true,
                hasExcludeFilter: false,
                hasGroups: false,
                hasExcludeGroups: false,
                includeTestSuites: [],
                excludeTestSuites: [],
                hasTestsCovering: false,
                hasTestsUsing: false,
                hasTestsRequiringPhpExtension: false,
            ),
            strictAdditionalPropertiesTracker: $this->trackerWithFinding(),
            strictAdditionalPropertiesMode: StrictAdditionalPropertiesMode::Warn,
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertStringContainsString('[OpenAPI Strict Additional Properties] WARNING', $stderr);
        $this->assertFileDoesNotExist($summaryPath);
    }

    private function trackerWithFinding(): StrictAdditionalPropertiesTracker
    {
        $tracker = new StrictAdditionalPropertiesTracker();
        $tracker->recordOn('front', 'GET', '/users', '200', 'application/json', [
            '/trace_id' => 'trace_id',
        ]);

        return $tracker;
    }

    /**
     * @param-out string $stderr
     * @param-out null|int $exitCode
     */
    private function subscriber(
        StrictAdditionalPropertiesTracker $tracker,
        StrictAdditionalPropertiesMode $mode,
        string &$stderr,
        ?int &$exitCode,
        ?string $sidecarDir = null,
    ): CoverageReportSubscriber {
        return new CoverageReportSubscriber(
            specs: [],
            outputFile: null,
            consoleOutput: ConsoleOutput::DEFAULT,
            githubSummaryPath: null,
            stderrWriter: static function (string $message) use (&$stderr): void {
                $stderr .= $message;
            },
            sidecarDir: $sidecarDir,
            exitHandler: static function (int $code) use (&$exitCode): void {
                $exitCode = $code;
            },
            strictAdditionalPropertiesTracker: $tracker,
            strictAdditionalPropertiesMode: $mode,
        );
    }

    private function fakeExecutionFinished(): ExecutionFinished
    {
        return (new ReflectionClass(ExecutionFinished::class))->newInstanceWithoutConstructor();
    }
}
