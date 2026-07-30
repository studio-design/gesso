<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\CoverageMergeCommand;
use Studio\Gesso\Coverage\CoverageSidecarEnvelope;
use Studio\Gesso\Coverage\CoverageSidecarWriter;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;

use function dirname;
use function escapeshellarg;
use function explode;
use function fclose;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_resource;
use function mkdir;
use function proc_close;
use function proc_open;
use function realpath;
use function rmdir;
use function sprintf;
use function str_replace;
use function stream_get_contents;
use function sys_get_temp_dir;
use function trim;
use function uniqid;
use function unlink;

/**
 * Spawns the real `gesso coverage:merge` command against fixture sidecars and
 * asserts the combined Markdown report it produces. The unit tests cover
 * `CoverageMergeCommand::run()` directly; this test pins the bin shim
 * (autoload discovery, argv parsing, exit code) on the actual filesystem so
 * a downstream `composer require` install path keeps working.
 */
class CoverageMergeCliIntegrationTest extends TestCase
{
    private string $repoRoot = '';
    private string $sidecarDir = '';
    private string $outputFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiCoverageTracker::reset();
        StrictRequiredTracker::reset();
        OpenApiSpecLoader::reset();

        $this->repoRoot = realpath(__DIR__ . '/../..') ?: __DIR__ . '/../..';
        $base = sys_get_temp_dir() . '/openapi-coverage-merge-cli-' . uniqid('', true);
        $this->sidecarDir = $base . '/sidecars';
        $this->outputFile = $base . '/coverage-report.md';
        mkdir($this->sidecarDir, 0o755, recursive: true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->sidecarDir . '/*') ?: [] as $path) {
            @unlink($path);
        }
        if (file_exists($this->outputFile)) {
            @unlink($this->outputFile);
        }
        @rmdir($this->sidecarDir);
        @rmdir(dirname($this->sidecarDir));

        OpenApiCoverageTracker::reset();
        StrictRequiredTracker::reset();
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function bin_merges_two_worker_sidecars_into_one_report(): void
    {
        // Worker 1.
        OpenApiSpecLoader::configure($this->repoRoot . '/tests/fixtures/specs');
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        // Worker 2.
        OpenApiCoverageTracker::reset();
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'POST',
            '/v1/pets',
            '201',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '2', OpenApiCoverageTracker::exportState());

        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::reset();

        [$exit, $stdout, $stderr] = $this->runCli([
            '--spec-base-path=' . $this->repoRoot . '/tests/fixtures/specs',
            '--specs=petstore-3.0',
            '--sidecar-dir=' . $this->sidecarDir,
            '--output-file=' . $this->outputFile,
        ]);

        $this->assertSame(0, $exit, "stderr: {$stderr}\nstdout: {$stdout}");
        $this->assertFileExists($this->outputFile);
        $contents = (string) file_get_contents($this->outputFile);
        $this->assertStringContainsString('GET /v1/pets', $contents);
        $this->assertStringContainsString('POST /v1/pets', $contents);
    }

    #[Test]
    public function bin_returns_zero_when_no_sidecars_present(): void
    {
        [$exit, , $stderr] = $this->runCli([
            '--spec-base-path=' . $this->repoRoot . '/tests/fixtures/specs',
            '--specs=petstore-3.0',
            '--sidecar-dir=' . $this->sidecarDir,
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('no sidecars found', $stderr);
    }

    #[Test]
    public function bin_help_flag_prints_usage_and_exits_zero(): void
    {
        [$exit, $stdout, $stderr] = $this->runCli(['--help']);

        $this->assertSame(0, $exit);
        $this->assertSame('', $stderr);
        // The bin shim must route --help to the canonical usage text …
        $this->assertSame(CoverageMergeCommand::usage(), $stdout);
        // … and the v1.x CLI compatibility policy requires every v1.9 help
        // line (flags, wording) to survive. New flags may be appended, so
        // this is containment, not equality, against the frozen capture.
        $v19Help = str_replace(
            'openapi-coverage-merge',
            'gesso coverage:merge',
            (string) file_get_contents($this->repoRoot . '/tests/fixtures/compatibility/v1.9-openapi-coverage-merge-help.txt'),
        );
        foreach (explode("\n", $v19Help) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $this->assertStringContainsString($line, $stdout, 'v1.9 help line must be retained');
        }
    }

    #[Test]
    public function bin_exits_two_when_spec_base_path_is_missing(): void
    {
        [$exit, $stdout, $stderr] = $this->runCli(['--specs=petstore-3.0']);

        $this->assertSame(2, $exit);
        $this->assertSame('', $stdout);
        $this->assertSame(
            str_replace(
                'openapi-coverage-merge',
                'gesso coverage:merge',
                (string) file_get_contents($this->repoRoot . '/tests/fixtures/compatibility/v1.9-openapi-coverage-merge-usage-error.txt'),
            ),
            $stderr,
        );
    }

    #[Test]
    public function bin_no_cleanup_preserves_sidecars(): void
    {
        OpenApiSpecLoader::configure($this->repoRoot . '/tests/fixtures/specs');
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::reset();

        [$exit] = $this->runCli([
            '--spec-base-path=' . $this->repoRoot . '/tests/fixtures/specs',
            '--specs=petstore-3.0',
            '--sidecar-dir=' . $this->sidecarDir,
            '--output-file=' . $this->outputFile,
            '--no-cleanup',
        ]);

        $this->assertSame(0, $exit);
        $this->assertCount(1, glob($this->sidecarDir . '/*.json') ?: [], '--no-cleanup must preserve sidecars');
    }

    #[Test]
    public function bin_exits_one_on_corrupt_sidecar(): void
    {
        file_put_contents($this->sidecarDir . '/part-1-9999.json', '{not json');

        [$exit, , $stderr] = $this->runCli([
            '--spec-base-path=' . $this->repoRoot . '/tests/fixtures/specs',
            '--specs=petstore-3.0',
            '--sidecar-dir=' . $this->sidecarDir,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('FATAL', $stderr);
        $this->assertStringContainsString('failed to decode', $stderr);
    }

    #[Test]
    public function bin_exits_one_when_worker_failure_marker_present(): void
    {
        OpenApiSpecLoader::configure($this->repoRoot . '/tests/fixtures/specs');
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());
        CoverageSidecarWriter::writeFailureMarker($this->sidecarDir, '2', 'simulated I/O failure');

        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::reset();

        [$exit, , $stderr] = $this->runCli([
            '--spec-base-path=' . $this->repoRoot . '/tests/fixtures/specs',
            '--specs=petstore-3.0',
            '--sidecar-dir=' . $this->sidecarDir,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('failed to write a sidecar', $stderr);
    }

    #[Test]
    public function bin_aggregates_strict_required_and_fails_in_fail_mode(): void
    {
        // Two workers each observe that PUT /signed-url 200 always returns
        // expires/signed_url/url — but the under-described spec marks them
        // optional. With --strict-required=fail this must exit 1.
        OpenApiSpecLoader::configure($this->repoRoot . '/tests/fixtures/specs');
        $this->writeStrictRequiredSidecar('1');
        $this->writeStrictRequiredSidecar('2');
        OpenApiCoverageTracker::reset();
        StrictRequiredTracker::reset();
        OpenApiSpecLoader::reset();

        [$exit, $stdout, $stderr] = $this->runCli([
            '--spec-base-path=' . $this->repoRoot . '/tests/fixtures/specs',
            '--specs=under-described',
            '--sidecar-dir=' . $this->sidecarDir,
            '--output-file=' . $this->outputFile,
            '--strict-required=fail',
        ]);

        $this->assertSame(1, $exit, "stdout: {$stdout}\nstderr: {$stderr}");
        $this->assertStringContainsString('[OpenAPI Strict Required] FATAL', $stderr);
        $this->assertStringContainsString('PUT /signed-url', $stderr);
    }

    private function writeStrictRequiredSidecar(string $token): void
    {
        OpenApiCoverageTracker::reset();
        StrictRequiredTracker::reset();
        OpenApiCoverageTracker::recordResponse(
            'under-described',
            'PUT',
            '/signed-url',
            '200',
            'application/json',
            schemaValidated: true,
        );
        StrictRequiredTracker::record(
            'under-described',
            'PUT',
            '/signed-url',
            '200',
            'application/json',
            ['/' => ['expires', 'signed_url', 'url']],
        );
        CoverageSidecarWriter::write(
            $this->sidecarDir,
            $token,
            CoverageSidecarEnvelope::build(
                OpenApiCoverageTracker::exportState(),
                StrictRequiredTracker::exportState(),
            ),
        );
    }

    /**
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function runCli(array $args): array
    {
        $cmd = sprintf('php %s coverage:merge', escapeshellarg($this->repoRoot . '/bin/gesso'));
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $process = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->repoRoot,
        );
        if (!is_resource($process)) {
            $this->fail('failed to spawn merge CLI');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        $exit = proc_close($process);

        return [$exit, $stdout, $stderr];
    }
}
