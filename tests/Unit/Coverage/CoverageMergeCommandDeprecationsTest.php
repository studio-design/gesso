<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Coverage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\CoverageMergeCommand;
use Studio\Gesso\Coverage\CoverageSidecarEnvelope;
use Studio\Gesso\Coverage\CoverageSidecarWriter;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Coverage\SdkExerciseCoverageTracker;
use Studio\Gesso\Internal\Deprecations;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Strict\StrictAdditionalPropertiesTracker;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;

use function dirname;
use function file_exists;
use function glob;
use function is_dir;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Issue #499: a paratest worker returns from `notify()` before the end-of-run
 * report, so the merge CLI is the only place a parallel run's residual
 * deprecation count can surface. Without it, every parallel suite would print
 * nothing — and printing nothing is the documented "ready for the next major"
 * signal.
 */
final class CoverageMergeCommandDeprecationsTest extends TestCase
{
    private string $sidecarDir = '';
    private string $outputFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiCoverageTracker::reset();
        StrictRequiredTracker::reset();
        StrictAdditionalPropertiesTracker::resetCurrent();
        SdkExerciseCoverageTracker::resetCurrent();
        OpenApiSpecLoader::reset();
        Deprecations::resetForTesting();

        $base = sys_get_temp_dir() . '/gesso-merge-deprecations-' . uniqid('', true);
        $this->sidecarDir = $base . '/sidecars';
        $this->outputFile = $base . '/coverage-report.md';
        mkdir($this->sidecarDir, 0o755, recursive: true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->outputFile, ...(glob($this->sidecarDir . '/*.json') ?: [])] as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        if (is_dir($this->sidecarDir)) {
            @rmdir($this->sidecarDir);
            @rmdir(dirname($this->sidecarDir));
        }

        OpenApiCoverageTracker::resetCurrent();
        StrictRequiredTracker::resetCurrent();
        StrictAdditionalPropertiesTracker::resetCurrent();
        SdkExerciseCoverageTracker::resetCurrent();
        OpenApiSpecLoader::reset();
        Deprecations::resetForTesting();
        parent::tearDown();
    }

    #[Test]
    public function counts_from_every_worker_are_summed_into_one_line(): void
    {
        $this->writeSidecar('1', [
            'laravel.config.auto_inject_dummy_bearer' => ['count' => 20, 'removed_in' => '3.0'],
            'phpunit.enum_spec_base_path' => ['count' => 9, 'removed_in' => '3.0'],
        ]);
        $this->writeSidecar('2', [
            'laravel.config.auto_inject_dummy_bearer' => ['count' => 11, 'removed_in' => '3.0'],
        ]);

        $stderr = $this->merge();

        $this->assertStringContainsString(
            '[Gesso deprecation] 2 deprecated surface(s) still in use, 40 call(s):'
            . ' laravel.config.auto_inject_dummy_bearer (31), phpunit.enum_spec_base_path (9).'
            . ' All are removed in Gesso 3.0.',
            $stderr,
        );
    }

    #[Test]
    public function a_fleet_that_used_none_produces_no_line(): void
    {
        $this->writeSidecar('1', []);
        $this->writeSidecar('2', []);

        $this->assertStringNotContainsString('[Gesso deprecation]', $this->merge());
    }

    #[Test]
    public function a_sidecar_without_the_deprecation_half_makes_a_zero_count_explicit(): void
    {
        // A worker on a pre-#499 Gesso stages no deprecation half, which is
        // indistinguishable from "that worker used none". Reporting silence
        // here would assert v3-readiness the merge cannot prove.
        $this->writeSidecar('1', []);
        $this->writeLegacySidecar('2');

        $stderr = $this->merge();

        $this->assertStringContainsString(
            '[Gesso deprecation] NOTE: 1 of 2 worker sidecar(s) carry no deprecation state',
            $stderr,
        );
        $this->assertStringContainsString(
            'the absence of a deprecation report above does not prove the suite uses none',
            $stderr,
        );
    }

    #[Test]
    public function a_sidecar_without_the_deprecation_half_makes_a_non_zero_count_a_lower_bound(): void
    {
        $this->writeSidecar('1', [
            'phpunit.enum_spec_base_path' => ['count' => 4, 'removed_in' => '3.0'],
        ]);
        $this->writeLegacySidecar('2');

        $stderr = $this->merge();

        $this->assertStringContainsString('phpunit.enum_spec_base_path (4)', $stderr);
        $this->assertStringContainsString('the counts above are a lower bound', $stderr);
    }

    #[Test]
    public function a_malformed_deprecation_half_warns_without_failing_the_merge(): void
    {
        // Coverage and the gates parsed cleanly; only the migration report is
        // unreadable. Failing the merge would be a worse outcome than saying
        // so — but silence would read as "no deprecations".
        $envelope = $this->envelope([]);
        $envelope['deprecations'] = ['version' => 99, 'deprecations' => []];
        CoverageSidecarWriter::write($this->sidecarDir, '1', $envelope);

        $stderr = '';
        $exit = $this->command($stderr)->run($this->options());

        $this->assertSame(0, $exit);
        $this->assertStringContainsString(
            '[Gesso deprecation] WARNING: could not read the staged deprecation state',
            $stderr,
        );
        $this->assertStringContainsString('Unsupported deprecation state version: got 99', $stderr);
    }

    /** @param array<string, array{count: int, removed_in: string}> $entries */
    private function writeSidecar(string $token, array $entries): void
    {
        CoverageSidecarWriter::write($this->sidecarDir, $token, $this->envelope($entries));
    }

    /** A v6 envelope, as a worker predating the deprecation channel wrote it. */
    private function writeLegacySidecar(string $token): void
    {
        CoverageSidecarWriter::write($this->sidecarDir, $token, CoverageSidecarEnvelope::build(
            coverageState: (new OpenApiCoverageTracker())->exportStateOn(),
            strictRequiredState: (new StrictRequiredTracker())->exportStateOn(),
            strictAdditionalPropertiesState: (new StrictAdditionalPropertiesTracker())->exportStateOn(),
            sdkExerciseState: (new SdkExerciseCoverageTracker())->exportStateOn(),
        ));
    }

    /**
     * @param array<string, array{count: int, removed_in: string}> $entries
     *
     * @return array<string, mixed>
     */
    private function envelope(array $entries): array
    {
        return CoverageSidecarEnvelope::build(
            coverageState: (new OpenApiCoverageTracker())->exportStateOn(),
            strictRequiredState: (new StrictRequiredTracker())->exportStateOn(),
            strictAdditionalPropertiesState: (new StrictAdditionalPropertiesTracker())->exportStateOn(),
            sdkExerciseState: (new SdkExerciseCoverageTracker())->exportStateOn(),
            deprecationsState: ['version' => Deprecations::STATE_VERSION, 'deprecations' => $entries],
        );
    }

    private function merge(): string
    {
        $stderr = '';
        $this->command($stderr)->run($this->options());

        return $stderr;
    }

    private function command(string &$stderr): CoverageMergeCommand
    {
        return new CoverageMergeCommand(
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            stdoutWriter: static fn(string $msg): null => null,
        );
    }

    /**
     * @return array{sidecar_dir: string, spec_base_path: string, specs: list<string>, output_file: string}
     */
    private function options(): array
    {
        return [
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'output_file' => $this->outputFile,
        ];
    }
}
