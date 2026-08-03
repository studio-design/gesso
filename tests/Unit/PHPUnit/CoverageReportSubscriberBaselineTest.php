<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\PHPUnit;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Studio\Gesso\Baseline\ViolationBaselineCollector;
use Studio\Gesso\Baseline\ViolationBaselineFile;
use Studio\Gesso\Baseline\ViolationFingerprint;
use Studio\Gesso\Coverage\CoverageSidecarEnvelope;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Internal\PartialRunDecision;
use Studio\Gesso\PHPUnit\ConsoleOutput;
use Studio\Gesso\PHPUnit\CoverageReportSubscriber;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function file_get_contents;
use function file_put_contents;
use function getenv;
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

class CoverageReportSubscriberBaselineTest extends TestCase
{
    private string $tmpDir = '';
    private ?string $previousTestToken = null;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');
        ViolationBaselineCollector::resetCurrent();

        $this->tmpDir = sys_get_temp_dir() . '/openapi-baseline-' . uniqid('', true);
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

        ViolationBaselineCollector::resetCurrent();
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function a_full_generation_run_writes_the_baseline_file(): void
    {
        $collector = $this->installCollectorWithOneViolation();
        $baselinePath = $this->tmpDir . '/gesso-baseline.json';

        $stderr = '';
        $subscriber = $this->subscriber($baselinePath, $stderr);

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertFileExists($baselinePath);
        $written = ViolationBaselineFile::read($baselinePath);
        $this->assertSame(1, $written->count());
        $this->assertTrue($written->contains($collector->baseline()->sorted()[0]));
        $this->assertStringContainsString('[Gesso] Baseline written: 1 violation(s)', $stderr);
    }

    #[Test]
    public function a_generation_run_with_no_violations_writes_an_empty_baseline(): void
    {
        ViolationBaselineCollector::setCurrent(new ViolationBaselineCollector());
        $baselinePath = $this->tmpDir . '/gesso-baseline.json';

        $stderr = '';
        $subscriber = $this->subscriber($baselinePath, $stderr);

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertFileExists($baselinePath);
        $this->assertSame(0, ViolationBaselineFile::read($baselinePath)->count());
        $this->assertStringContainsString('[Gesso] Baseline written: 0 violation(s)', $stderr);
    }

    #[Test]
    public function a_partial_run_refuses_to_write_and_exits_non_zero(): void
    {
        $this->installCollectorWithOneViolation();
        $baselinePath = $this->tmpDir . '/gesso-baseline.json';

        $stderr = '';
        $exitCode = null;
        $subscriber = $this->subscriber(
            $baselinePath,
            $stderr,
            partialRun: PartialRunDecision::partial('--filter'),
            exitCode: $exitCode,
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertFileDoesNotExist($baselinePath);
        $this->assertStringContainsString('[Gesso] WARNING', $stderr);
        $this->assertStringContainsString('partial run', $stderr);
        $this->assertSame(1, $exitCode, 'a refused generation run must exit non-zero');
    }

    #[Test]
    public function a_paratest_worker_stages_the_baseline_in_the_sidecar_envelope(): void
    {
        // Issue #417: worker mode does NOT write the baseline file — the
        // per-worker view is a subset. Instead the fingerprints ride the v7
        // sidecar envelope for `gesso coverage:merge --baseline-file` to
        // union, and the worker exits normally.
        $this->installCollectorWithOneViolation();
        putenv('TEST_TOKEN=3');
        $baselinePath = $this->tmpDir . '/gesso-baseline.json';

        $stderr = '';
        $exitCode = null;
        $subscriber = $this->subscriber($baselinePath, $stderr, exitCode: $exitCode);

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertFileDoesNotExist($baselinePath, 'workers must never write the baseline file themselves');
        $this->assertStringContainsString('coverage:merge --baseline-file=', $stderr);
        $this->assertStringContainsString($baselinePath, $stderr);
        $this->assertNull($exitCode, 'a staged parallel generation run must not exit non-zero');

        $sidecars = glob($this->tmpDir . '/part-3-*.json') ?: [];
        $this->assertCount(1, $sidecars, 'the sidecar must still be written in worker mode');
        $envelope = json_decode((string) file_get_contents($sidecars[0]), true);
        $this->assertSame(
            CoverageSidecarEnvelope::ENVELOPE_VERSION_WITH_SDK_EXERCISE_AND_BASELINE,
            $envelope['envelopeVersion'],
        );
        $this->assertSame(1, $envelope['sdkExercise']['version']);
        $this->assertSame(ViolationBaselineFile::BASELINE_VERSION, $envelope['baseline']['baseline_version']);
        $this->assertCount(1, $envelope['baseline']['violations']);
        $this->assertSame('/data/*/id', $envelope['baseline']['violations'][0]['instance_path']);
    }

    #[Test]
    public function a_generation_worker_exits_non_zero_when_the_sidecar_write_fails(): void
    {
        // Worst case of issue #417: the sidecar write fails AND the failure
        // marker cannot be dropped either (here: the sidecar dir path is an
        // existing file, so ensureDir fails for both). The merge would then
        // see only the other workers' complete baseline halves and write an
        // incomplete baseline — the worker itself must fail the parallel
        // run. Contrast with coverage-only workers, which stay green on
        // sidecar I/O errors (pinned in CoverageReportSubscriberWorkerModeTest).
        $this->installCollectorWithOneViolation();
        putenv('TEST_TOKEN=5');
        $baselinePath = $this->tmpDir . '/gesso-baseline.json';

        $blocker = $this->tmpDir . '/not-a-dir';
        file_put_contents($blocker, 'blocks the sidecar dir');

        $stderr = '';
        $exitCode = null;
        $subscriber = new CoverageReportSubscriber(
            specs: ['petstore-3.0'],
            outputFile: null,
            consoleOutput: ConsoleOutput::DEFAULT,
            githubSummaryPath: null,
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            sidecarDir: $blocker,
            exitHandler: static function (int $code) use (&$exitCode): void {
                $exitCode = $code;
            },
            baselineGeneratePath: $baselinePath,
        );

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertFileDoesNotExist($baselinePath);
        $this->assertStringContainsString('[Gesso] FATAL', $stderr);
        $this->assertStringContainsString('incomplete baseline', $stderr);
        $this->assertSame(1, $exitCode, 'a generation worker that lost its sidecar must fail the run');
    }

    #[Test]
    public function no_baseline_path_means_no_write_and_no_output(): void
    {
        $stderr = '';
        $subscriber = $this->subscriber(null, $stderr);

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertSame([], glob($this->tmpDir . '/*.json') ?: []);
        $this->assertSame('', $stderr);
    }

    private function installCollectorWithOneViolation(): ViolationBaselineCollector
    {
        $collector = new ViolationBaselineCollector();
        $collector->record(new ViolationFingerprint(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            'response.body',
            '/data/*/id',
            'type',
        ));
        ViolationBaselineCollector::setCurrent($collector);

        return $collector;
    }

    private function subscriber(
        ?string $baselinePath,
        string &$stderr,
        ?PartialRunDecision $partialRun = null,
        ?int &$exitCode = null,
    ): CoverageReportSubscriber {
        return new CoverageReportSubscriber(
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
            partialRun: $partialRun,
            baselineGeneratePath: $baselinePath,
        );
    }

    private function fakeExecutionFinished(): ExecutionFinished
    {
        return (new ReflectionClass(ExecutionFinished::class))->newInstanceWithoutConstructor();
    }
}
