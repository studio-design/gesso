<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\PHPUnit;

use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionStarted;
use PHPUnit\Event\TestSuite\TestSuite;
use PHPUnit\Event\TestSuite\TestSuiteWithName;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Studio\Gesso\Baseline\BaselineStaleMode;
use Studio\Gesso\Baseline\CoverageBaseline;
use Studio\Gesso\Baseline\CoverageBaselineEntry;
use Studio\Gesso\Baseline\CoverageBaselineFile;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Internal\PartialRunDecision;
use Studio\Gesso\PHPUnit\ConsoleOutput;
use Studio\Gesso\PHPUnit\CoverageReportSubscriber;
use Studio\Gesso\PHPUnit\TestRunCompletionTracer;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function file_put_contents;
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

class CoverageReportSubscriberCoverageBaselineTest extends TestCase
{
    private string $tmpDir = '';
    private ?string $previousTestToken = null;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');

        $this->tmpDir = sys_get_temp_dir() . '/gesso-coverage-baseline-' . uniqid('', true);
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
    public function a_full_generation_run_writes_every_uncovered_response(): void
    {
        $this->recordCoveredPetsList();
        $path = $this->tmpDir . '/gesso-coverage-baseline.json';

        $stderr = '';
        $this->notify($this->subscriber($stderr, generatePath: $path));

        $this->assertFileExists($path);
        $written = CoverageBaselineFile::read($path);
        $this->assertFalse(
            $written->contains(new CoverageBaselineEntry('petstore-3.0', 'GET', '/v1/pets', '200', 'application/json')),
            'a validated response must not be baselined',
        );
        $this->assertTrue(
            $written->contains(new CoverageBaselineEntry('petstore-3.0', 'GET', '/v1/pets', '500', 'application/json')),
        );
        $this->assertStringContainsString('[Gesso] Coverage baseline written:', $stderr);
        $this->assertStringContainsString('uncovered response(s)', $stderr);
    }

    #[Test]
    public function a_partial_generation_run_refuses_to_write_and_exits_non_zero(): void
    {
        $this->recordCoveredPetsList();
        $path = $this->tmpDir . '/gesso-coverage-baseline.json';

        $stderr = '';
        $exitCode = null;
        $this->notify($this->subscriber(
            $stderr,
            generatePath: $path,
            partialRun: PartialRunDecision::partial('--filter'),
            exitCode: $exitCode,
        ));

        $this->assertFileDoesNotExist($path);
        $this->assertStringContainsString('[Gesso] WARNING: coverage baseline generation refused on a partial run', $stderr);
        $this->assertSame(1, $exitCode);
    }

    #[Test]
    public function a_generation_run_with_no_recorded_coverage_refuses_to_write(): void
    {
        // Without this guard the file would list every declared response and
        // permanently baseline the whole contract.
        $path = $this->tmpDir . '/gesso-coverage-baseline.json';

        $stderr = '';
        $exitCode = null;
        $this->notify($this->subscriber($stderr, generatePath: $path, exitCode: $exitCode));

        $this->assertFileDoesNotExist($path);
        $this->assertStringContainsString('no contract test coverage was recorded', $stderr);
        $this->assertSame(1, $exitCode);
    }

    #[Test]
    public function enforcement_passes_when_the_uncovered_set_matches_the_baseline(): void
    {
        $this->recordCoveredPetsList();

        $stderr = '';
        $exitCode = null;
        $this->notify($this->subscriber(
            $stderr,
            baseline: $this->generatedBaseline(),
            exitCode: $exitCode,
        ));

        $this->assertNull($exitCode);
        $this->assertStringContainsString('[Gesso] coverage baseline:', $stderr);
        $this->assertStringContainsString('0 covered now', $stderr);
        $this->assertStringNotContainsString('[Gesso] FATAL', $stderr);
    }

    #[Test]
    public function enforcement_fails_and_names_a_newly_uncovered_response(): void
    {
        // The baseline was generated while `GET /v1/pets 200` was covered;
        // this run does not cover it, so the row itself is reported.
        $baseline = $this->generatedBaseline();
        OpenApiCoverageTracker::reset();
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets/search',
            '200',
            'application/json',
            schemaValidated: true,
        );

        $stderr = '';
        $exitCode = null;
        $this->notify($this->subscriber($stderr, baseline: $baseline, exitCode: $exitCode));

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('[Gesso] FATAL: 1 response(s) are not covered', $stderr);
        $this->assertStringContainsString(
            '  - [petstore-3.0] GET /v1/pets status=200 content-type=application/json',
            $stderr,
        );
        $this->assertStringContainsString('OPENAPI_BASELINE_GENERATE=1 vendor/bin/phpunit', $stderr);
    }

    #[Test]
    public function enforcement_is_independent_of_the_denominator(): void
    {
        // Issue #481 problem 2: a response documented after the baseline was
        // generated moves the percentage but must not fail the gate as long
        // as it is covered.
        $baseline = new CoverageBaseline();
        $baseline->add(new CoverageBaselineEntry('petstore-3.0', 'GET', '/v1/pets', '500', 'application/json'));
        $this->recordCoveredPetsList();

        $stderr = '';
        $exitCode = null;
        $this->notify($this->subscriber($stderr, baseline: $baseline, exitCode: $exitCode));

        // Every other declared response is uncovered here, so this run *does*
        // fail — but only for rows the baseline omits, never for the newly
        // covered one.
        $this->assertSame(1, $exitCode);
        $this->assertStringNotContainsString(
            '[petstore-3.0] GET /v1/pets status=200 content-type=application/json',
            $stderr,
        );
    }

    #[Test]
    public function a_covered_baseline_entry_is_reported_as_stale(): void
    {
        $baseline = $this->generatedBaseline();
        $baseline->add(new CoverageBaselineEntry('petstore-3.0', 'GET', '/v1/pets', '200', 'application/json'));

        $stderr = '';
        $exitCode = null;
        $this->notify($this->subscriber($stderr, baseline: $baseline, exitCode: $exitCode));

        $this->assertNull($exitCode, 'note mode must not fail the run');
        $this->assertStringContainsString('1 covered now', $stderr);
        $this->assertStringContainsString('[Gesso] NOTE: 1 coverage baseline entry(ies) are covered now', $stderr);
        $this->assertStringContainsString(
            '  - [petstore-3.0] GET /v1/pets status=200 content-type=application/json',
            $stderr,
        );
    }

    #[Test]
    public function stale_fail_mode_exits_non_zero(): void
    {
        $baseline = $this->generatedBaseline();
        $baseline->add(new CoverageBaselineEntry('petstore-3.0', 'GET', '/v1/pets', '200', 'application/json'));

        $stderr = '';
        $exitCode = null;
        $this->notify($this->subscriber(
            $stderr,
            baseline: $baseline,
            staleMode: BaselineStaleMode::Fail,
            exitCode: $exitCode,
        ));

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('[Gesso] FATAL: 1 coverage baseline entry(ies) are covered now', $stderr);
    }

    #[Test]
    public function stale_off_mode_stays_silent(): void
    {
        $baseline = $this->generatedBaseline();
        $baseline->add(new CoverageBaselineEntry('petstore-3.0', 'GET', '/v1/pets', '200', 'application/json'));

        $stderr = '';
        $exitCode = null;
        $this->notify($this->subscriber(
            $stderr,
            baseline: $baseline,
            staleMode: BaselineStaleMode::Off,
            exitCode: $exitCode,
        ));

        $this->assertNull($exitCode);
        $this->assertStringContainsString('1 covered now', $stderr);
        $this->assertStringNotContainsString('can be removed from the baseline file', $stderr);
    }

    #[Test]
    public function a_partial_run_skips_the_gate_with_a_note(): void
    {
        $baseline = $this->generatedBaseline();
        OpenApiCoverageTracker::reset();

        $stderr = '';
        $exitCode = null;
        $this->notify($this->subscriber(
            $stderr,
            baseline: $baseline,
            partialRun: PartialRunDecision::partial('--filter'),
            exitCode: $exitCode,
        ));

        $this->assertNull($exitCode);
        $this->assertStringContainsString('[Gesso] NOTE: the coverage baseline gate is skipped on partial runs', $stderr);
    }

    #[Test]
    public function a_run_that_did_not_complete_cleanly_skips_the_gate(): void
    {
        // A test that failed before its contract assertion leaves responses
        // uncovered; reporting those as regressions would bury the real
        // failure under a wall of coverage noise.
        $baseline = $this->generatedBaseline();
        OpenApiCoverageTracker::reset();
        $this->recordCoveredPetsList();

        $stderr = '';
        $exitCode = null;
        $this->notify($this->subscriber(
            $stderr,
            baseline: $baseline,
            completionTracer: $this->completionTracer(plannedTests: 3, finishedTests: 2),
            exitCode: $exitCode,
        ));

        $this->assertNull($exitCode);
        $this->assertStringContainsString('the coverage baseline gate is skipped because the run did not complete cleanly', $stderr);
    }

    #[Test]
    public function enforcement_fails_when_no_coverage_was_recorded_at_all(): void
    {
        $stderr = '';
        $exitCode = null;
        $this->notify($this->subscriber(
            $stderr,
            baseline: new CoverageBaseline(),
            exitCode: $exitCode,
        ));

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            '[Gesso] FATAL: no contract test coverage was recorded; the coverage baseline gate cannot be evaluated.',
            $stderr,
        );
    }

    #[Test]
    public function a_paratest_worker_neither_generates_nor_enforces(): void
    {
        // The merge CLI owns both halves for parallel runs — a worker sees
        // only its slice, so its uncovered set is meaningless.
        $baseline = $this->generatedBaseline();
        putenv('TEST_TOKEN=2');
        $path = $this->tmpDir . '/gesso-coverage-baseline.json';

        $stderr = '';
        $exitCode = null;
        $this->notify($this->subscriber(
            $stderr,
            baseline: $baseline,
            generatePath: $path,
            exitCode: $exitCode,
        ));

        $this->assertFileDoesNotExist($path);
        $this->assertNull($exitCode);
        $this->assertStringNotContainsString('coverage baseline', $stderr);
    }

    #[Test]
    public function a_generation_worker_exits_non_zero_when_the_sidecar_write_fails(): void
    {
        // Worst case: the sidecar write fails AND the failure marker cannot
        // be dropped either (the sidecar dir path is an existing file, so
        // ensureDir fails for both). The merge would then generate a
        // baseline from N-1 workers, recording this worker's covered
        // responses as uncovered and permanently loosening the ratchet.
        $this->recordCoveredPetsList();
        putenv('TEST_TOKEN=5');
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
            coverageBaselineGeneratePath: $this->tmpDir . '/gesso-coverage-baseline.json',
        );
        $this->notify($subscriber);

        $this->assertStringContainsString('[Gesso] FATAL', $stderr);
        $this->assertStringContainsString("this worker's covered responses", $stderr);
        $this->assertStringContainsString('incomplete baseline', $stderr);
        $this->assertSame(1, $exitCode, 'a generation worker that lost its sidecar must fail the run');
    }

    #[Test]
    public function an_enforcing_worker_stays_green_when_the_sidecar_write_fails(): void
    {
        // Nothing is staged for enforcement, and a merge missing one
        // worker's coverage fails loudly with its "newly uncovered" listing
        // rather than writing a file — so this worker must keep the
        // coverage-only sidecar contract of staying green on I/O errors.
        $this->recordCoveredPetsList();
        $baseline = $this->generatedBaseline();
        putenv('TEST_TOKEN=6');
        $blocker = $this->tmpDir . '/not-a-dir-enforcing';
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
            coverageBaseline: $baseline,
        );
        $this->notify($subscriber);

        $this->assertNull($exitCode);
        $this->assertStringNotContainsString('[Gesso] FATAL', $stderr);
    }

    #[Test]
    public function no_coverage_baseline_configuration_stays_silent(): void
    {
        $this->recordCoveredPetsList();

        $stderr = '';
        $this->notify($this->subscriber($stderr));

        $this->assertStringNotContainsString('coverage baseline', $stderr);
    }

    private function recordCoveredPetsList(): void
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
     * The baseline a generation run writes for a suite that covers
     * `GET /v1/pets 200` and nothing else — the starting point every
     * enforcement test varies from.
     */
    private function generatedBaseline(): CoverageBaseline
    {
        $this->recordCoveredPetsList();
        $path = $this->tmpDir . '/generated.json';
        $stderr = '';
        $this->notify($this->subscriber($stderr, generatePath: $path));

        return CoverageBaselineFile::read($path);
    }

    private function completionTracer(int $plannedTests, int $finishedTests): TestRunCompletionTracer
    {
        $suite = (new ReflectionClass(TestSuiteWithName::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(TestSuite::class, 'count'))->setValue($suite, $plannedTests);
        $started = (new ReflectionClass(ExecutionStarted::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(ExecutionStarted::class, 'testSuite'))->setValue($started, $suite);

        $tracer = new TestRunCompletionTracer();
        $tracer->trace($started);
        for ($i = 0; $i < $finishedTests; $i++) {
            $tracer->trace((new ReflectionClass(Finished::class))->newInstanceWithoutConstructor());
        }

        return $tracer;
    }

    private function subscriber(
        string &$stderr,
        ?CoverageBaseline $baseline = null,
        ?string $generatePath = null,
        BaselineStaleMode $staleMode = BaselineStaleMode::Note,
        ?PartialRunDecision $partialRun = null,
        ?TestRunCompletionTracer $completionTracer = null,
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
            baselineCompletionTracer: $completionTracer,
            coverageBaseline: $baseline,
            coverageBaselineGeneratePath: $generatePath,
            coverageBaselineStaleMode: $staleMode,
        );
    }

    private function notify(CoverageReportSubscriber $subscriber): void
    {
        ob_start();
        $subscriber->notify((new ReflectionClass(ExecutionFinished::class))->newInstanceWithoutConstructor());
        ob_get_clean();
    }
}
