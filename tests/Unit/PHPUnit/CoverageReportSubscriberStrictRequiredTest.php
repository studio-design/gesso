<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\PHPUnit;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Studio\OpenApiContractTesting\Coverage\CoverageSidecarEnvelope;
use Studio\OpenApiContractTesting\Coverage\CoverageSidecarReader;
use Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\Internal\PartialRunDecision;
use Studio\OpenApiContractTesting\PHPUnit\ConsoleOutput;
use Studio\OpenApiContractTesting\PHPUnit\CoverageReportSubscriber;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredMode;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredTracker;

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

class CoverageReportSubscriberStrictRequiredTest extends TestCase
{
    private const SPEC_NAME = 'under-described';
    private ?string $previousTestToken = null;
    private string $tmpSidecarDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiCoverageTracker::reset();
        StrictRequiredTracker::reset();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');

        $current = getenv('TEST_TOKEN');
        $this->previousTestToken = $current === false ? null : $current;
        putenv('TEST_TOKEN');

        $this->tmpSidecarDir = sys_get_temp_dir() . '/openapi-strict-worker-' . uniqid('', true);
        mkdir($this->tmpSidecarDir, 0o755, recursive: true);
    }

    protected function tearDown(): void
    {
        if ($this->previousTestToken === null) {
            putenv('TEST_TOKEN');
        } else {
            putenv('TEST_TOKEN=' . $this->previousTestToken);
        }
        StrictRequiredTracker::reset();
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::reset();
        if (is_dir($this->tmpSidecarDir)) {
            $entries = glob($this->tmpSidecarDir . '/*') ?: [];
            foreach ($entries as $entry) {
                @unlink($entry);
            }
            @rmdir($this->tmpSidecarDir);
        }
        parent::tearDown();
    }

    #[Test]
    public function off_mode_does_not_invoke_asserter(): void
    {
        StrictRequiredTracker::record(self::SPEC_NAME, 'PUT', '/signed-url', '200', 'application/json', ['expires', 'signed_url', 'url']);

        $stderr = '';
        $subscriber = $this->makeSubscriber(
            mode: StrictRequiredMode::Off,
            stderr: $stderr,
            exitCode: $exitCode,
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertSame('', $stderr);
        $this->assertNull($exitCode);
    }

    #[Test]
    public function warn_mode_writes_diagnostic_but_does_not_exit(): void
    {
        StrictRequiredTracker::record(self::SPEC_NAME, 'PUT', '/signed-url', '200', 'application/json', ['expires', 'signed_url', 'url']);

        $stderr = '';
        $exitCode = null;
        $subscriber = $this->makeSubscriber(
            mode: StrictRequiredMode::Warn,
            stderr: $stderr,
            exitCode: $exitCode,
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertNull($exitCode, 'warn mode must not exit');
        $this->assertStringContainsString('[OpenAPI Strict Required] WARNING', $stderr);
        $this->assertStringContainsString('PUT /signed-url', $stderr);
        $this->assertStringContainsString('expires', $stderr);
    }

    #[Test]
    public function fail_mode_invokes_exit_handler_with_one(): void
    {
        StrictRequiredTracker::record(self::SPEC_NAME, 'PUT', '/signed-url', '200', 'application/json', ['expires', 'signed_url', 'url']);

        $stderr = '';
        $exitCode = null;
        $subscriber = $this->makeSubscriber(
            mode: StrictRequiredMode::Fail,
            stderr: $stderr,
            exitCode: $exitCode,
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertSame(1, $exitCode, 'fail mode must request exit(1)');
        $this->assertStringContainsString('[OpenAPI Strict Required] FATAL', $stderr);
    }

    #[Test]
    public function no_drift_in_warn_mode_emits_nothing(): void
    {
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/users/{id}', '200', 'application/json', ['id', 'name']);

        $stderr = '';
        $exitCode = null;
        $subscriber = $this->makeSubscriber(
            mode: StrictRequiredMode::Warn,
            stderr: $stderr,
            exitCode: $exitCode,
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertSame('', $stderr);
        $this->assertNull($exitCode);
    }

    #[Test]
    public function worker_mode_writes_strict_required_observations_to_sidecar(): void
    {
        // Worker branch must export strict_required observations alongside
        // coverage so the merge CLI can aggregate across paratest workers
        // and assert the gate. Without this the gate is silent-pass in CI.
        putenv('TEST_TOKEN=2');
        StrictRequiredTracker::record(
            self::SPEC_NAME,
            'PUT',
            '/signed-url',
            '200',
            'application/json',
            ['expires', 'signed_url', 'url'],
        );

        $stderr = '';
        $subscriber = $this->makeSubscriberWithSidecarDir(
            mode: StrictRequiredMode::Warn,
            stderr: $stderr,
            exitCode: $exitCode,
            sidecarDir: $this->tmpSidecarDir,
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        // Legacy NOTE must be gone — workers now contribute to the gate.
        $this->assertStringNotContainsString('[OpenAPI Strict Required] NOTE', $stderr);
        $this->assertStringNotContainsString('issue #226', $stderr);
        $this->assertNull($exitCode);

        $payloads = CoverageSidecarReader::readDir($this->tmpSidecarDir);
        $this->assertCount(1, $payloads);
        $parsed = CoverageSidecarEnvelope::parse($payloads[0]);
        $this->assertNotNull($parsed['strictRequired']);
        $observations = $parsed['strictRequired']['observations'] ?? null;
        $this->assertIsArray($observations);
        $this->assertArrayHasKey(self::SPEC_NAME, $observations);
        $row = $observations[self::SPEC_NAME]['PUT /signed-url']['200:application/json'];
        $this->assertSame(1, $row['hits']);
        $this->assertSame(['expires', 'signed_url', 'url'], $row['alwaysPresent']);
    }

    #[Test]
    public function worker_mode_writes_envelope_even_when_strict_required_is_off(): void
    {
        // Workers export observations unconditionally so the merge CLI can
        // decide at aggregation time whether to assert. This keeps mode
        // changes a single-knob operation without per-worker reruns.
        putenv('TEST_TOKEN=3');
        StrictRequiredTracker::record(
            self::SPEC_NAME,
            'PUT',
            '/signed-url',
            '200',
            'application/json',
            ['expires', 'signed_url', 'url'],
        );

        $stderr = '';
        $subscriber = $this->makeSubscriberWithSidecarDir(
            mode: StrictRequiredMode::Off,
            stderr: $stderr,
            exitCode: $exitCode,
            sidecarDir: $this->tmpSidecarDir,
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertSame('', $stderr);
        $payloads = CoverageSidecarReader::readDir($this->tmpSidecarDir);
        $this->assertCount(1, $payloads);
        $parsed = CoverageSidecarEnvelope::parse($payloads[0]);
        $this->assertNotNull($parsed['strictRequired']);
        $this->assertArrayHasKey(self::SPEC_NAME, $parsed['strictRequired']['observations']);
    }

    #[Test]
    public function partial_run_skips_gate_with_note(): void
    {
        StrictRequiredTracker::record(self::SPEC_NAME, 'PUT', '/signed-url', '200', 'application/json', ['expires', 'signed_url', 'url']);

        $stderr = '';
        $exitCode = null;
        $subscriber = new CoverageReportSubscriber(
            specs: [self::SPEC_NAME],
            outputFile: null,
            consoleOutput: ConsoleOutput::DEFAULT,
            githubSummaryPath: null,
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            sidecarDir: null,
            exitHandler: static function (int $code) use (&$exitCode): void {
                $exitCode = $code;
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
            strictRequiredMode: StrictRequiredMode::Fail,
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertNull($exitCode, 'partial run must not fail-fast the gate');
        $this->assertStringContainsString('[OpenAPI Strict Required] NOTE', $stderr);
        $this->assertStringContainsString('skipped on partial runs', $stderr);
        $this->assertStringNotContainsString('FATAL', $stderr);
    }

    #[Test]
    public function unresolved_groups_emit_diagnostic_note(): void
    {
        // Record an observation that the asserter cannot resolve (GET on a
        // path that only declares PUT).
        StrictRequiredTracker::record(self::SPEC_NAME, 'GET', '/signed-url', '200', 'application/json', ['expires']);

        $stderr = '';
        $subscriber = $this->makeSubscriber(
            mode: StrictRequiredMode::Warn,
            stderr: $stderr,
            exitCode: $exitCode,
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertStringContainsString('NOTE: 1 observation group(s) had no matching response schema', $stderr);
        $this->assertStringContainsString('GET /signed-url', $stderr);
        $this->assertStringNotContainsString('WARNING', $stderr);
    }

    /**
     * @param-out null|int $exitCode
     * @param-out string $stderr
     */
    private function makeSubscriber(
        StrictRequiredMode $mode,
        string &$stderr,
        ?int &$exitCode,
    ): CoverageReportSubscriber {
        $exitCode = null;

        return new CoverageReportSubscriber(
            specs: [self::SPEC_NAME],
            outputFile: null,
            consoleOutput: ConsoleOutput::DEFAULT,
            githubSummaryPath: null,
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            sidecarDir: null,
            exitHandler: static function (int $code) use (&$exitCode): void {
                $exitCode = $code;
            },
            strictRequiredMode: $mode,
        );
    }

    /**
     * @param-out null|int $exitCode
     * @param-out string $stderr
     */
    private function makeSubscriberWithSidecarDir(
        StrictRequiredMode $mode,
        string &$stderr,
        ?int &$exitCode,
        string $sidecarDir,
    ): CoverageReportSubscriber {
        $exitCode = null;

        return new CoverageReportSubscriber(
            specs: [self::SPEC_NAME],
            outputFile: null,
            consoleOutput: ConsoleOutput::DEFAULT,
            githubSummaryPath: null,
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            sidecarDir: $sidecarDir,
            exitHandler: static function (int $code) use (&$exitCode): void {
                $exitCode = $code;
            },
            strictRequiredMode: $mode,
        );
    }

    private function fakeExecutionFinished(): ExecutionFinished
    {
        return (new ReflectionClass(ExecutionFinished::class))->newInstanceWithoutConstructor();
    }
}
