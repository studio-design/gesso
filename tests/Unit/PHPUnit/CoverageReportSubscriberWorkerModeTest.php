<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\PHPUnit;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Studio\OpenApiContractTesting\Coverage\CoverageSidecarReader;
use Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\PHPUnit\ConsoleOutput;
use Studio\OpenApiContractTesting\PHPUnit\CoverageReportSubscriber;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredTracker;
use Throwable;

use function file_get_contents;
use function file_put_contents;
use function getenv;
use function getmypid;
use function glob;
use function is_dir;
use function json_decode;
use function mkdir;
use function ob_get_clean;
use function ob_start;
use function putenv;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Pins the paratest worker-mode contract on {@see CoverageReportSubscriber}:
 * when `TEST_TOKEN` is set the subscriber writes a sidecar and emits NO
 * stdout / output_file / GITHUB_STEP_SUMMARY output. When `TEST_TOKEN` is
 * unset the subscriber renders normally — sequential PHPUnit must not be
 * affected by these changes.
 */
class CoverageReportSubscriberWorkerModeTest extends TestCase
{
    private string $tmpDir = '';
    private ?string $previousTestToken;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');

        $this->tmpDir = sys_get_temp_dir() . '/openapi-coverage-subscriber-' . uniqid('', true);

        // Snapshot whatever TEST_TOKEN was set to (some CI runners pre-set it).
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
            $entries = glob($this->tmpDir . '/*') ?: [];
            foreach ($entries as $entry) {
                @unlink($entry);
            }
            @rmdir($this->tmpDir);
        }

        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function worker_mode_writes_sidecar_and_skips_rendering(): void
    {
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        putenv('TEST_TOKEN=4');

        $subscriber = new CoverageReportSubscriber(
            specs: ['petstore-3.0'],
            outputFile: $this->tmpDir . '/coverage-report.md',
            consoleOutput: ConsoleOutput::DEFAULT,
            githubSummaryPath: null,
            sidecarDir: $this->tmpDir,
            junitOutput: $this->tmpDir . '/coverage.junit.xml',
            jsonOutput: $this->tmpDir . '/coverage.json',
            htmlOutput: $this->tmpDir . '/coverage.html',
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        $stdout = (string) ob_get_clean();

        $this->assertSame('', $stdout, 'worker mode must not emit console output');
        $this->assertFileDoesNotExist($this->tmpDir . '/coverage-report.md', 'worker mode must not write output_file');
        $this->assertFileDoesNotExist(
            $this->tmpDir . '/coverage.junit.xml',
            'worker mode must not write junit_output (merge CLI is the canonical paratest renderer)',
        );
        $this->assertFileDoesNotExist(
            $this->tmpDir . '/coverage.json',
            'worker mode must not write json_output (merge CLI is the canonical paratest renderer)',
        );
        $this->assertFileDoesNotExist(
            $this->tmpDir . '/coverage.html',
            'worker mode must not write html_output (merge CLI is the canonical paratest renderer)',
        );

        $loaded = CoverageSidecarReader::readDir($this->tmpDir);
        $this->assertCount(1, $loaded);
        // v2 envelope: coverage payload nested under "coverage" key.
        $this->assertSame(2, $loaded[0]['envelopeVersion']);
        $this->assertSame(1, $loaded[0]['coverage']['version']);
        $this->assertArrayHasKey('petstore-3.0', $loaded[0]['coverage']['specs']);
    }

    #[Test]
    public function injected_trackers_drive_worker_sidecar_payload(): void
    {
        // Issue #229: when production code (the extension) injects tracker
        // instances into the subscriber, the worker-mode sidecar must
        // serialize THOSE instances — not whatever the process-global
        // ::current() locator happens to point at. A regression that drops
        // the field assignment would silently serialize the wrong state and
        // the merge CLI would aggregate empty payloads.
        $coverageTracker = new OpenApiCoverageTracker();
        $coverageTracker->recordResponseOn(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            '201',
            'application/json',
            schemaValidated: true,
        );
        $strictRequiredTracker = new StrictRequiredTracker();
        $strictRequiredTracker->recordOn(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            '201',
            'application/json',
            ['/' => ['id', 'name']],
        );

        // Process-global locator points at fresh, EMPTY trackers — if the
        // subscriber ever read from current() instead of the injected refs,
        // the sidecar would serialize this empty state.
        OpenApiCoverageTracker::resetCurrent();
        StrictRequiredTracker::resetCurrent();
        OpenApiCoverageTracker::setCurrent(new OpenApiCoverageTracker());
        StrictRequiredTracker::setCurrent(new StrictRequiredTracker());

        putenv('TEST_TOKEN=9');

        $subscriber = new CoverageReportSubscriber(
            specs: ['petstore-3.0'],
            outputFile: null,
            consoleOutput: ConsoleOutput::DEFAULT,
            githubSummaryPath: null,
            coverageTracker: $coverageTracker,
            strictRequiredTracker: $strictRequiredTracker,
            sidecarDir: $this->tmpDir,
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $loaded = CoverageSidecarReader::readDir($this->tmpDir);
        $this->assertCount(1, $loaded);
        // Sidecar must contain the INJECTED coverage tracker's POST recording.
        $this->assertArrayHasKey(
            'POST /v1/pets',
            $loaded[0]['coverage']['specs']['petstore-3.0'],
            'sidecar must serialize the injected coverage tracker, not ::current()',
        );
        // And the injected strict_required tracker's observation.
        $this->assertArrayHasKey(
            'POST /v1/pets',
            $loaded[0]['strictRequired']['observations']['petstore-3.0'],
            'sidecar must serialize the injected strict_required tracker, not ::current()',
        );
    }

    #[Test]
    public function worker_mode_writes_empty_sidecar_when_no_coverage_recorded(): void
    {
        // Pinning that workers always emit a sidecar — even an empty one —
        // so the merge CLI never silently misses a worker that happened to
        // run no contract assertions. Skipping the write here would make
        // "0 tests touched the spec" indistinguishable from "the worker
        // crashed before writing".
        putenv('TEST_TOKEN=2');

        $subscriber = new CoverageReportSubscriber(
            specs: ['petstore-3.0'],
            outputFile: null,
            consoleOutput: ConsoleOutput::DEFAULT,
            githubSummaryPath: null,
            sidecarDir: $this->tmpDir,
        );

        $subscriber->notify($this->fakeExecutionFinished());

        $loaded = CoverageSidecarReader::readDir($this->tmpDir);
        $this->assertCount(1, $loaded);
        $this->assertSame([], $loaded[0]['coverage']['specs']);
    }

    #[Test]
    public function sequential_mode_renders_as_before_when_test_token_unset(): void
    {
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );

        $outputFile = $this->tmpDir . '/coverage-report.md';
        mkdir($this->tmpDir, 0o755, recursive: true);

        $subscriber = new CoverageReportSubscriber(
            specs: ['petstore-3.0'],
            outputFile: $outputFile,
            consoleOutput: ConsoleOutput::DEFAULT,
            githubSummaryPath: null,
            sidecarDir: $this->tmpDir,
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        $stdout = (string) ob_get_clean();

        $this->assertNotSame('', $stdout, 'sequential mode must render to stdout');
        $this->assertFileExists($outputFile, 'sequential mode must write output_file');
        $this->assertSame([], CoverageSidecarReader::readDir($this->tmpDir));
    }

    #[Test]
    public function sequential_mode_writes_junit_output_when_configured(): void
    {
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );

        mkdir($this->tmpDir, 0o755, recursive: true);
        $junitPath = $this->tmpDir . '/coverage.junit.xml';

        $subscriber = new CoverageReportSubscriber(
            specs: ['petstore-3.0'],
            outputFile: null,
            consoleOutput: ConsoleOutput::DEFAULT,
            githubSummaryPath: null,
            sidecarDir: $this->tmpDir,
            junitOutput: $junitPath,
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertFileExists($junitPath, 'sequential mode must write junit_output when configured');
        $contents = (string) file_get_contents($junitPath);
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"', $contents);
        $this->assertStringContainsString('<testsuites', $contents);
        $this->assertStringContainsString('openapi.coverage.petstore-3.0', $contents);
    }

    #[Test]
    public function sequential_mode_writes_json_output_when_configured(): void
    {
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );

        mkdir($this->tmpDir, 0o755, recursive: true);
        $jsonPath = $this->tmpDir . '/coverage.json';

        $subscriber = new CoverageReportSubscriber(
            specs: ['petstore-3.0'],
            outputFile: null,
            consoleOutput: ConsoleOutput::DEFAULT,
            githubSummaryPath: null,
            sidecarDir: $this->tmpDir,
            jsonOutput: $jsonPath,
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertFileExists($jsonPath, 'sequential mode must write json_output when configured');
        $decoded = json_decode((string) file_get_contents($jsonPath), true);
        $this->assertIsArray($decoded);
        $this->assertSame(1, $decoded['schema_version']);
        $this->assertArrayHasKey('petstore-3.0', $decoded['specs']);
    }

    #[Test]
    public function sequential_mode_warns_and_continues_when_junit_write_fails(): void
    {
        // Severity asymmetry from PR #206: a subscriber-side write failure
        // emits a WARN and keeps going (no exit). The merge CLI's contract is
        // FATAL+exit-1 (pinned by CoverageMergeCommandTest). This test pins
        // the subscriber half so the asymmetry can't drift unnoticed.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );

        mkdir($this->tmpDir, 0o755, recursive: true);
        // Unwritable target — parent dir exists check passes (we are not
        // exercising the bootstrap-time gate), but the actual write will fail
        // because the path is a directory rather than a regular file.
        $junitPath = $this->tmpDir . '/coverage.junit.xml';
        mkdir($junitPath, 0o755, recursive: true);

        $stderrLog = '';
        $subscriber = new CoverageReportSubscriber(
            specs: ['petstore-3.0'],
            outputFile: null,
            consoleOutput: ConsoleOutput::DEFAULT,
            githubSummaryPath: null,
            stderrWriter: static function (string $msg) use (&$stderrLog): void {
                $stderrLog .= $msg;
            },
            sidecarDir: $this->tmpDir,
            junitOutput: $junitPath,
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        @rmdir($junitPath);

        $this->assertStringContainsString('WARNING', $stderrLog);
        $this->assertStringContainsString('JUnit XML', $stderrLog);
    }

    #[Test]
    public function sequential_mode_writes_html_output_when_configured(): void
    {
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );

        mkdir($this->tmpDir, 0o755, recursive: true);
        $htmlPath = $this->tmpDir . '/coverage.html';

        $subscriber = new CoverageReportSubscriber(
            specs: ['petstore-3.0'],
            outputFile: null,
            consoleOutput: ConsoleOutput::DEFAULT,
            githubSummaryPath: null,
            sidecarDir: $this->tmpDir,
            htmlOutput: $htmlPath,
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertFileExists($htmlPath, 'sequential mode must write html_output when configured');
        $contents = (string) file_get_contents($htmlPath);
        $this->assertStringStartsWith('<!DOCTYPE html>', $contents);
        $this->assertStringContainsString('petstore-3.0', $contents);
    }

    #[Test]
    public function sequential_mode_warns_and_continues_when_html_write_fails(): void
    {
        // Parity with the JUnit and JSON WARN tests — the HTML dispatch path
        // must obey the same WARN-and-continue contract.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );

        mkdir($this->tmpDir, 0o755, recursive: true);
        $htmlPath = $this->tmpDir . '/coverage.html';
        mkdir($htmlPath, 0o755, recursive: true);

        $stderrLog = '';
        $subscriber = new CoverageReportSubscriber(
            specs: ['petstore-3.0'],
            outputFile: null,
            consoleOutput: ConsoleOutput::DEFAULT,
            githubSummaryPath: null,
            stderrWriter: static function (string $msg) use (&$stderrLog): void {
                $stderrLog .= $msg;
            },
            sidecarDir: $this->tmpDir,
            htmlOutput: $htmlPath,
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        @rmdir($htmlPath);

        $this->assertStringContainsString('WARNING', $stderrLog);
        $this->assertStringContainsString('HTML', $stderrLog);
    }

    #[Test]
    public function sequential_mode_warns_and_continues_when_json_write_fails(): void
    {
        // Parity with sequential_mode_warns_and_continues_when_junit_write_fails
        // — the JSON dispatch path must obey the same WARN-and-continue
        // contract so the asymmetry is consistent across all formats.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );

        mkdir($this->tmpDir, 0o755, recursive: true);
        $jsonPath = $this->tmpDir . '/coverage.json';
        mkdir($jsonPath, 0o755, recursive: true);

        $stderrLog = '';
        $subscriber = new CoverageReportSubscriber(
            specs: ['petstore-3.0'],
            outputFile: null,
            consoleOutput: ConsoleOutput::DEFAULT,
            githubSummaryPath: null,
            stderrWriter: static function (string $msg) use (&$stderrLog): void {
                $stderrLog .= $msg;
            },
            sidecarDir: $this->tmpDir,
            jsonOutput: $jsonPath,
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        @rmdir($jsonPath);

        $this->assertStringContainsString('WARNING', $stderrLog);
        $this->assertStringContainsString('JSON', $stderrLog);
    }

    #[Test]
    public function worker_mode_keeps_running_when_sidecar_write_fails(): void
    {
        // The contract assertion that triggered notify() must never be
        // demoted to a hard failure by a sidecar I/O error — the user's
        // tests already passed; sidecar trouble is a CI artifact concern.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        putenv('TEST_TOKEN=1');

        // Pass a path that points at an existing FILE, not a directory, so
        // mkdir/rename both fail. The subscriber must catch, warn, and
        // (per C2) drop a failure marker the merge CLI can detect.
        $blocker = sys_get_temp_dir() . '/openapi-coverage-blocker-' . uniqid('', true);
        file_put_contents($blocker, 'not a dir');

        $stderrLog = '';
        $subscriber = new CoverageReportSubscriber(
            specs: ['petstore-3.0'],
            outputFile: null,
            consoleOutput: ConsoleOutput::DEFAULT,
            githubSummaryPath: null,
            sidecarDir: $blocker,
            stderrWriter: static function (string $msg) use (&$stderrLog): void {
                $stderrLog .= $msg;
            },
        );

        $exception = null;

        try {
            $subscriber->notify($this->fakeExecutionFinished());
        } catch (Throwable $e) {
            $exception = $e;
        } finally {
            @unlink($blocker);
        }

        $this->assertNull($exception, 'sidecar write failure must NOT bubble out of notify()');
        $this->assertStringContainsString('WARNING', $stderrLog);
        $this->assertStringContainsString('sidecar', $stderrLog);
    }

    #[Test]
    public function worker_mode_writes_failure_marker_when_sidecar_write_fails(): void
    {
        // C2: when the sidecar write fails, the worker drops a
        // `failed-<token>.json` marker in the sidecar dir so the merge CLI
        // can detect a missing worker and exit non-zero. Without the
        // marker, the merge would silently under-count coverage.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        putenv('TEST_TOKEN=7');

        // sidecarDir is writable but the sidecar write itself fails by
        // pointing the writer at a target whose `*.tmp` rename target is
        // a directory rather than a file. We simulate this more simply
        // by pre-occupying the final filename with a directory.
        mkdir($this->tmpDir, 0o755, recursive: true);
        $expectedSidecar = $this->tmpDir . '/part-7-' . (string) getmypid() . '.json';
        mkdir($expectedSidecar, 0o755);

        $subscriber = new CoverageReportSubscriber(
            specs: ['petstore-3.0'],
            outputFile: null,
            consoleOutput: ConsoleOutput::DEFAULT,
            githubSummaryPath: null,
            sidecarDir: $this->tmpDir,
            stderrWriter: static fn(string $msg): null => null,
        );

        $subscriber->notify($this->fakeExecutionFinished());

        $markerPath = $this->tmpDir . '/failed-7-' . (string) getmypid() . '.json';
        $this->assertFileExists($markerPath, 'C2: failure marker must be dropped');
        $payload = json_decode((string) file_get_contents($markerPath), true);
        $this->assertSame('7', $payload['testToken']);
        $this->assertNotEmpty($payload['reason']);

        @unlink($markerPath);
        @rmdir($expectedSidecar);
    }

    /**
     * Build a stub `ExecutionFinished` without invoking its constructor. The
     * subscriber's `notify()` does not read the event, so a structurally
     * valid stand-in is enough — and the real Telemetry constructor signature
     * has churned across PHPUnit 11/12/13, which would force version-gated
     * stub builders that add no test value.
     */
    private function fakeExecutionFinished(): ExecutionFinished
    {
        return (new ReflectionClass(ExecutionFinished::class))->newInstanceWithoutConstructor();
    }
}
