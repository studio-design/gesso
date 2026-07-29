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
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Internal\PartialRunDecision;
use Studio\Gesso\PHPUnit\ConsoleOutput;
use Studio\Gesso\PHPUnit\CoverageReportSubscriber;
use Studio\Gesso\Spec\OpenApiSpecLoader;

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
    public function a_paratest_worker_warns_and_exits_non_zero_without_writing_a_baseline(): void
    {
        $this->installCollectorWithOneViolation();
        putenv('TEST_TOKEN=3');
        $baselinePath = $this->tmpDir . '/gesso-baseline.json';

        $stderr = '';
        $exitCode = null;
        $subscriber = $this->subscriber($baselinePath, $stderr, exitCode: $exitCode);

        ob_start();
        $subscriber->notify($this->fakeExecutionFinished());
        ob_get_clean();

        $this->assertFileDoesNotExist($baselinePath);
        $this->assertStringContainsString('[Gesso] WARNING', $stderr);
        $this->assertStringContainsString('parallel', $stderr);
        $this->assertCount(
            1,
            glob($this->tmpDir . '/part-3-*.json') ?: [],
            'the coverage sidecar must still be written in worker mode',
        );
        $this->assertSame(1, $exitCode, 'a refused parallel generation run must not look successful');
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
