<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Coverage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Baseline\CoverageBaselineEntry;
use Studio\Gesso\Baseline\CoverageBaselineFile;
use Studio\Gesso\Coverage\CoverageMergeCommand;
use Studio\Gesso\Coverage\CoverageSidecarEnvelope;
use Studio\Gesso\Coverage\CoverageSidecarWriter;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Internal\LegacyIdentity;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;

use function dirname;
use function file_exists;
use function file_put_contents;
use function glob;
use function is_dir;
use function mkdir;
use function putenv;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Issue #481: the coverage baseline gate on the parallel-run path. The merge
 * command owns both halves here — a worker's slice cannot answer "is this
 * response covered by the suite".
 */
class CoverageMergeCommandCoverageBaselineTest extends TestCase
{
    private string $sidecarDir = '';
    private string $baselinePath = '';

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiCoverageTracker::reset();
        StrictRequiredTracker::reset();
        OpenApiSpecLoader::reset();
        LegacyIdentity::resetEnvForTesting('GESSO_BASELINE_GENERATE');

        $base = sys_get_temp_dir() . '/gesso-merge-coverage-baseline-' . uniqid('', true);
        $this->sidecarDir = $base . '/sidecars';
        $this->baselinePath = $base . '/gesso-coverage-baseline.json';
        mkdir($this->sidecarDir, 0o755, recursive: true);
    }

    protected function tearDown(): void
    {
        LegacyIdentity::resetEnvForTesting('GESSO_BASELINE_GENERATE');
        foreach (glob($this->sidecarDir . '/*') ?: [] as $path) {
            @unlink($path);
        }
        if (file_exists($this->baselinePath)) {
            @unlink($this->baselinePath);
        }
        if (is_dir($this->sidecarDir)) {
            @rmdir($this->sidecarDir);
            @rmdir(dirname($this->sidecarDir));
        }

        OpenApiCoverageTracker::resetCurrent();
        OpenApiCoverageTracker::reset();
        StrictRequiredTracker::resetCurrent();
        StrictRequiredTracker::reset();
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function parse_argv_accepts_the_coverage_baseline_flags(): void
    {
        $opts = CoverageMergeCommand::parseArgv([
            '--coverage-baseline-file=gesso-coverage-baseline.json',
            '--coverage-baseline-stale=fail',
        ]);

        $this->assertSame('gesso-coverage-baseline.json', $opts['coverage_baseline_file'] ?? null);
        $this->assertSame('fail', $opts['coverage_baseline_stale'] ?? null);
    }

    #[Test]
    public function generation_writes_the_merged_uncovered_set(): void
    {
        // Two workers cover one response each; the baseline must reflect the
        // union, not either slice.
        $this->writeWorkerSidecar('1', [['GET', '/v1/pets', '200', 'application/json']]);
        $this->writeWorkerSidecar('2', [['GET', '/v1/pets/search', '200', 'application/json']]);
        putenv('GESSO_BASELINE_GENERATE=1');

        $stderr = '';
        $exit = $this->merge($stderr, $this->baselinePath);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('[Gesso] Coverage baseline written:', $stderr);
        $written = CoverageBaselineFile::read($this->baselinePath);
        $this->assertFalse($written->contains($this->entry('GET', '/v1/pets', '200', 'application/json')));
        $this->assertFalse($written->contains($this->entry('GET', '/v1/pets/search', '200', 'application/json')));
        $this->assertTrue($written->contains($this->entry('GET', '/v1/pets', '500', 'application/json')));
    }

    /** Issue #504: this read site goes through {@see LegacyIdentity}, not bare getenv(). */
    #[Test]
    public function the_legacy_generation_env_name_still_writes_the_baseline(): void
    {
        $this->writeWorkerSidecar('1', [['GET', '/v1/pets', '200', 'application/json']]);
        putenv('OPENAPI_BASELINE_GENERATE=1');

        $stderr = '';
        $exit = $this->merge($stderr, $this->baselinePath);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('[Gesso] Coverage baseline written:', $stderr);
        $this->assertSame(
            ['[Gesso] WARNING: OPENAPI_BASELINE_GENERATE is deprecated and will be removed in Gesso '
                . LegacyIdentity::REMOVED_IN . '. Use GESSO_BASELINE_GENERATE.'],
            LegacyIdentity::warnings(),
        );
    }

    #[Test]
    public function enforcement_passes_when_the_uncovered_set_matches(): void
    {
        $this->writeGeneratedBaseline([['GET', '/v1/pets', '200', 'application/json']]);
        $this->writeWorkerSidecar('1', [['GET', '/v1/pets', '200', 'application/json']]);

        $stderr = '';
        $exit = $this->merge($stderr, $this->baselinePath);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('[Gesso] coverage baseline:', $stderr);
        $this->assertStringNotContainsString('[Gesso] FATAL', $stderr);
    }

    #[Test]
    public function enforcement_fails_and_names_a_newly_uncovered_response(): void
    {
        $this->writeGeneratedBaseline([['GET', '/v1/pets', '200', 'application/json']]);
        // This run lost the test that covered `GET /v1/pets 200`.
        $this->writeWorkerSidecar('1', [['GET', '/v1/pets/search', '200', 'application/json']]);

        $stderr = '';
        $exit = $this->merge($stderr, $this->baselinePath);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('[Gesso] FATAL: 1 response(s) are not covered', $stderr);
        $this->assertStringContainsString(
            '  - [petstore-3.0] GET /v1/pets status=200 content-type=application/json',
            $stderr,
        );
        $this->assertStringContainsString('GESSO_BASELINE_GENERATE=1 gesso coverage:merge', $stderr);
    }

    #[Test]
    public function a_covered_baseline_entry_is_reported_as_stale_without_failing(): void
    {
        // Generated while only the logout endpoint was covered; this run
        // additionally covers `GET /v1/pets 200`, so that entry is removable.
        $this->writeGeneratedBaseline([['GET', '/v1/logout', '200', 'text/html']]);
        $this->writeWorkerSidecar('1', [
            ['GET', '/v1/logout', '200', 'text/html'],
            ['GET', '/v1/pets', '200', 'application/json'],
        ]);

        $stderr = '';
        $exit = $this->merge($stderr, $this->baselinePath);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('[Gesso] NOTE: 1 coverage baseline entry(ies) are covered now', $stderr);
    }

    #[Test]
    public function stale_fail_mode_exits_one(): void
    {
        // Generated while only the logout endpoint was covered; this run
        // additionally covers `GET /v1/pets 200`, so that entry is removable.
        $this->writeGeneratedBaseline([['GET', '/v1/logout', '200', 'text/html']]);
        $this->writeWorkerSidecar('1', [
            ['GET', '/v1/logout', '200', 'text/html'],
            ['GET', '/v1/pets', '200', 'application/json'],
        ]);

        $stderr = '';
        $exit = $this->merge($stderr, $this->baselinePath, 'fail');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('[Gesso] FATAL: 1 coverage baseline entry(ies) are covered now', $stderr);
    }

    #[Test]
    public function a_failed_gate_keeps_the_sidecars_for_a_retry(): void
    {
        // Recovery from any coverage-baseline failure re-runs *this command*
        // — fix the path, free disk, or accept the regressions with
        // GESSO_BASELINE_GENERATE=1. Cleaning up would make each of those
        // cost a full parallel suite run.
        $this->writeGeneratedBaseline([['GET', '/v1/pets', '200', 'application/json']]);
        $this->writeWorkerSidecar('1', [['GET', '/v1/pets/search', '200', 'application/json']]);

        $stderr = '';
        $exit = $this->merge($stderr, $this->baselinePath, cleanup: true);

        $this->assertSame(1, $exit);
        $this->assertNotSame([], glob($this->sidecarDir . '/*') ?: []);
        $this->assertStringContainsString('Sidecars kept in', $stderr);
    }

    #[Test]
    public function a_passing_gate_still_cleans_up_the_sidecars(): void
    {
        $this->writeGeneratedBaseline([['GET', '/v1/pets', '200', 'application/json']]);
        $this->writeWorkerSidecar('1', [['GET', '/v1/pets', '200', 'application/json']]);

        $stderr = '';
        $exit = $this->merge($stderr, $this->baselinePath, cleanup: true);

        $this->assertSame(0, $exit);
        $this->assertSame([], glob($this->sidecarDir . '/*') ?: []);
        $this->assertStringNotContainsString('Sidecars kept in', $stderr);
    }

    #[Test]
    public function no_sidecars_fails_instead_of_passing_the_gate_vacuously(): void
    {
        $stderr = '';
        $exit = $this->merge($stderr, $this->baselinePath);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no sidecars were found', $stderr);
        $this->assertStringContainsString('cannot be evaluated', $stderr);
    }

    #[Test]
    public function an_unreadable_coverage_baseline_file_fails_the_merge(): void
    {
        $this->writeWorkerSidecar('1', [['GET', '/v1/pets', '200', 'application/json']]);
        file_put_contents($this->baselinePath, '{"coverage_baseline_version": 99}');

        $stderr = '';
        $exit = $this->merge($stderr, $this->baselinePath);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--coverage-baseline-file could not be loaded', $stderr);
    }

    #[Test]
    public function an_unknown_stale_mode_is_a_usage_error(): void
    {
        $stderr = '';
        $exit = $this->merge($stderr, $this->baselinePath, 'warn');

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('Unknown baseline_stale value', $stderr);
    }

    #[Test]
    public function a_stale_mode_without_the_file_is_a_usage_error(): void
    {
        $stderr = '';
        $exit = $this->merge($stderr, staleMode: 'fail');

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('--coverage-baseline-stale is set but --coverage-baseline-file', $stderr);
    }

    private function merge(
        string &$stderr,
        ?string $baselineFile = null,
        ?string $staleMode = null,
        bool $cleanup = false,
    ): int {
        $command = new CoverageMergeCommand(
            stdoutWriter: static fn(string $msg): null => null,
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
        );

        $options = [
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'cleanup' => $cleanup,
        ];
        if ($baselineFile !== null) {
            $options['coverage_baseline_file'] = $baselineFile;
        }
        if ($staleMode !== null) {
            $options['coverage_baseline_stale'] = $staleMode;
        }

        return $command->run($options);
    }

    /**
     * Write the baseline a suite covering exactly `$covered` would generate.
     *
     * @param list<array{0: string, 1: string, 2: string, 3: string}> $covered
     */
    private function writeGeneratedBaseline(array $covered): void
    {
        $this->writeWorkerSidecar('9', $covered);
        putenv('GESSO_BASELINE_GENERATE=1');
        $stderr = '';
        $this->merge($stderr, $this->baselinePath);
        LegacyIdentity::resetEnvForTesting('GESSO_BASELINE_GENERATE');

        foreach (glob($this->sidecarDir . '/*') ?: [] as $path) {
            @unlink($path);
        }
        OpenApiCoverageTracker::reset();
    }

    /**
     * @param list<array{0: string, 1: string, 2: string, 3: string}> $covered
     */
    private function writeWorkerSidecar(string $token, array $covered): void
    {
        OpenApiCoverageTracker::reset();
        StrictRequiredTracker::reset();
        foreach ($covered as [$method, $path, $status, $contentType]) {
            OpenApiCoverageTracker::recordResponse(
                'petstore-3.0',
                $method,
                $path,
                $status,
                $contentType,
                schemaValidated: true,
            );
        }

        CoverageSidecarWriter::write($this->sidecarDir, $token, CoverageSidecarEnvelope::build(
            OpenApiCoverageTracker::exportState(),
            StrictRequiredTracker::exportState(),
        ));
        OpenApiCoverageTracker::reset();
        StrictRequiredTracker::reset();
    }

    private function entry(string $method, string $path, string $status, string $contentType): CoverageBaselineEntry
    {
        return new CoverageBaselineEntry('petstore-3.0', $method, $path, $status, $contentType);
    }
}
