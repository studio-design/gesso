<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\PHPUnit;

use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Studio\Gesso\Baseline\BaselineStaleMode;
use Studio\Gesso\Baseline\ViolationBaseline;
use Studio\Gesso\Baseline\ViolationBaselineEnforcer;
use Studio\Gesso\Baseline\ViolationFingerprint;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Internal\PartialRunDecision;
use Studio\Gesso\OpenApiValidationResult;
use Studio\Gesso\PHPUnit\ConsoleOutput;
use Studio\Gesso\PHPUnit\CoverageReportSubscriber;
use Studio\Gesso\PHPUnit\TestRunDefectTracer;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\ValidationIssue;

use function file_get_contents;
use function ob_get_clean;
use function ob_start;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

class CoverageReportSubscriberBaselineEnforceTest extends TestCase
{
    private ?string $githubSummaryPath = null;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');
        ViolationBaselineEnforcer::resetCurrent();
    }

    protected function tearDown(): void
    {
        ViolationBaselineEnforcer::resetCurrent();
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::reset();
        if ($this->githubSummaryPath !== null) {
            @unlink($this->githubSummaryPath);
            $this->githubSummaryPath = null;
        }
        parent::tearDown();
    }

    #[Test]
    public function no_enforcer_means_no_baseline_summary(): void
    {
        $stderr = '';
        $subscriber = $this->subscriber($stderr);

        $this->notify($subscriber);

        $this->assertSame('', $stderr);
    }

    #[Test]
    public function a_full_run_reports_entries_hits_and_stale(): void
    {
        $this->installEnforcerWithTwoEntriesOneHit();

        $stderr = '';
        $subscriber = $this->subscriber($stderr);

        $this->notify($subscriber);

        $this->assertStringContainsString('[Gesso] baseline: 2 entries, 1 hit, 1 stale (removable).', $stderr);
        $this->assertStringContainsString('[Gesso] NOTE: 1 baseline entry(ies) no longer occurred', $stderr);
        $this->assertStringContainsString('[petstore-3.0] POST /v1/pets status=201 content-type=application/json response.body instance_path=/name keyword=required', $stderr);
    }

    #[Test]
    public function a_fully_hit_baseline_reports_zero_stale_without_a_listing(): void
    {
        $baseline = new ViolationBaseline();
        $hit = new ViolationFingerprint('petstore-3.0', 'GET', '/v1/pets', '200', 'application/json', 'response.body', '/data/*/id', 'type');
        $baseline->add($hit);
        $enforcer = new ViolationBaselineEnforcer($baseline);
        ViolationBaselineEnforcer::setCurrent($enforcer);
        $this->markHit($enforcer, $hit);

        $stderr = '';
        $subscriber = $this->subscriber($stderr);

        $this->notify($subscriber);

        $this->assertStringContainsString('[Gesso] baseline: 1 entries, 1 hit, 0 stale (removable).', $stderr);
        $this->assertStringNotContainsString('NOTE', $stderr);
    }

    #[Test]
    public function stale_mode_off_skips_stale_evaluation(): void
    {
        $this->installEnforcerWithTwoEntriesOneHit();

        $stderr = '';
        $subscriber = $this->subscriber($stderr, staleMode: BaselineStaleMode::Off);

        $this->notify($subscriber);

        $this->assertStringContainsString('[Gesso] baseline: 2 entries, 1 hit.', $stderr);
        $this->assertStringNotContainsString('stale', $stderr);
    }

    #[Test]
    public function stale_mode_fail_exits_non_zero_on_stale_entries(): void
    {
        $this->installEnforcerWithTwoEntriesOneHit();

        $stderr = '';
        $exitCode = null;
        $subscriber = $this->subscriber($stderr, staleMode: BaselineStaleMode::Fail, exitCode: $exitCode);

        $this->notify($subscriber);

        $this->assertStringContainsString('[Gesso] FATAL: 1 baseline entry(ies) no longer occurred', $stderr);
        $this->assertSame(1, $exitCode, 'baseline_stale=fail must terminate the run on stale entries');
    }

    #[Test]
    public function stale_mode_fail_passes_when_every_entry_is_hit(): void
    {
        $baseline = new ViolationBaseline();
        $hit = new ViolationFingerprint('petstore-3.0', 'GET', '/v1/pets', '200', 'application/json', 'response.body', '/data/*/id', 'type');
        $baseline->add($hit);
        $enforcer = new ViolationBaselineEnforcer($baseline);
        ViolationBaselineEnforcer::setCurrent($enforcer);
        $this->markHit($enforcer, $hit);

        $stderr = '';
        $exitCode = null;
        $subscriber = $this->subscriber($stderr, staleMode: BaselineStaleMode::Fail, exitCode: $exitCode);

        $this->notify($subscriber);

        $this->assertNull($exitCode);
    }

    #[Test]
    public function a_run_with_test_defects_skips_stale_evaluation_with_a_note(): void
    {
        // A failed / errored / skipped test means later assertions may never
        // have run, so an unhit entry proves nothing — it must not be
        // reported as removable, and baseline_stale=fail must not trip.
        $this->installEnforcerWithTwoEntriesOneHit();
        $tracer = new TestRunDefectTracer();
        $tracer->trace((new ReflectionClass(Failed::class))->newInstanceWithoutConstructor());

        $stderr = '';
        $exitCode = null;
        $subscriber = $this->subscriber(
            $stderr,
            staleMode: BaselineStaleMode::Fail,
            exitCode: $exitCode,
            defectTracer: $tracer,
        );

        $this->notify($subscriber);

        $this->assertStringContainsString('[Gesso] baseline: 2 entries, 1 hit.', $stderr);
        $this->assertStringContainsString('stale evaluation is skipped because the run did not complete cleanly (1 failed)', $stderr);
        $this->assertNull($exitCode, 'a defective run must never fail the stale gate');
    }

    #[Test]
    public function a_clean_defect_tracer_leaves_stale_evaluation_active(): void
    {
        $this->installEnforcerWithTwoEntriesOneHit();

        $stderr = '';
        $subscriber = $this->subscriber($stderr, defectTracer: new TestRunDefectTracer());

        $this->notify($subscriber);

        $this->assertStringContainsString('[Gesso] baseline: 2 entries, 1 hit, 1 stale (removable).', $stderr);
    }

    #[Test]
    public function a_partial_run_skips_stale_evaluation_with_a_note(): void
    {
        $this->installEnforcerWithTwoEntriesOneHit();

        $stderr = '';
        $exitCode = null;
        $subscriber = $this->subscriber(
            $stderr,
            staleMode: BaselineStaleMode::Fail,
            partialRun: PartialRunDecision::partial('--filter'),
            exitCode: $exitCode,
        );

        $this->notify($subscriber);

        $this->assertStringContainsString('[Gesso] baseline: 2 entries, 1 hit.', $stderr);
        $this->assertStringContainsString('stale evaluation is skipped on partial runs', $stderr);
        $this->assertNull($exitCode, 'a partial run must never fail the stale gate');
    }

    #[Test]
    public function stale_entries_are_appended_to_the_github_step_summary(): void
    {
        $this->installEnforcerWithTwoEntriesOneHit();
        $path = tempnam(sys_get_temp_dir(), 'gesso-summary-');
        $this->assertNotFalse($path);
        $this->githubSummaryPath = $path;

        $stderr = '';
        $subscriber = $this->subscriber($stderr, githubSummaryPath: $path);

        $this->notify($subscriber);

        $summary = (string) file_get_contents($path);
        $this->assertStringContainsString('OpenAPI baseline stale entries', $summary);
        $this->assertStringContainsString('[petstore-3.0] POST /v1/pets', $summary);
    }

    private function installEnforcerWithTwoEntriesOneHit(): ViolationBaselineEnforcer
    {
        $baseline = new ViolationBaseline();
        $hit = new ViolationFingerprint('petstore-3.0', 'GET', '/v1/pets', '200', 'application/json', 'response.body', '/data/*/id', 'type');
        $baseline->add($hit);
        $baseline->add(new ViolationFingerprint('petstore-3.0', 'POST', '/v1/pets', '201', 'application/json', 'response.body', '/name', 'required'));
        $enforcer = new ViolationBaselineEnforcer($baseline);
        ViolationBaselineEnforcer::setCurrent($enforcer);
        $this->markHit($enforcer, $hit);

        return $enforcer;
    }

    /**
     * Mark one baselined fingerprint as hit through the enforcer's public
     * path (a suppressed decode-failure-style lookup is not available for
     * arbitrary entries, so route a matching one-issue result through
     * suppressesResult()).
     */
    private function markHit(ViolationBaselineEnforcer $enforcer, ViolationFingerprint $fingerprint): void
    {
        $result = OpenApiValidationResult::failure(
            ['violation'],
            $fingerprint->path,
            $fingerprint->statusCode,
            $fingerprint->contentType,
            [new ValidationIssue(
                $fingerprint->category,
                'violation',
                instancePath: $fingerprint->instancePath,
                keyword: $fingerprint->keyword,
                method: $fingerprint->method,
                path: $fingerprint->path,
                statusCode: $fingerprint->statusCode,
                contentType: $fingerprint->contentType,
                parameter: $fingerprint->parameter,
            )],
        );
        $enforcer->suppressesResult($fingerprint->spec, $result, $fingerprint->method, $fingerprint->path);
    }

    private function subscriber(
        string &$stderr,
        BaselineStaleMode $staleMode = BaselineStaleMode::Note,
        ?PartialRunDecision $partialRun = null,
        ?int &$exitCode = null,
        ?string $githubSummaryPath = null,
        ?TestRunDefectTracer $defectTracer = null,
    ): CoverageReportSubscriber {
        return new CoverageReportSubscriber(
            specs: ['petstore-3.0'],
            outputFile: null,
            consoleOutput: ConsoleOutput::DEFAULT,
            githubSummaryPath: $githubSummaryPath,
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            exitHandler: static function (int $code) use (&$exitCode): void {
                $exitCode = $code;
            },
            partialRun: $partialRun,
            baselineStaleMode: $staleMode,
            baselineDefectTracer: $defectTracer,
        );
    }

    private function notify(CoverageReportSubscriber $subscriber): void
    {
        ob_start();
        $subscriber->notify(
            (new ReflectionClass(ExecutionFinished::class))->newInstanceWithoutConstructor(),
        );
        ob_get_clean();
    }
}
