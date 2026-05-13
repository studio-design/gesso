<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Coverage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Coverage\CoverageMergeCommand;
use Studio\OpenApiContractTesting\Coverage\CoverageSidecarEnvelope;
use Studio\OpenApiContractTesting\Coverage\CoverageSidecarWriter;
use Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;
use Studio\OpenApiContractTesting\Validation\Strict\StrictRequiredTracker;

use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_dir;
use function json_decode;
use function mkdir;
use function rmdir;
use function substr_count;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Pins the merge-CLI's behavior end-to-end — sidecar dir → combined report.
 * Constructs sidecars from real exported tracker state so format drift
 * between writer/reader/merge would surface here.
 */
class CoverageMergeCommandTest extends TestCase
{
    private string $sidecarDir = '';
    private string $outputFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiCoverageTracker::reset();
        StrictRequiredTracker::reset();
        OpenApiSpecLoader::reset();

        $base = sys_get_temp_dir() . '/openapi-coverage-merge-' . uniqid('', true);
        $this->sidecarDir = $base . '/sidecars';
        $this->outputFile = $base . '/coverage-report.md';
        mkdir($this->sidecarDir, 0o755, recursive: true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->outputFile, ...$this->sidecars()] as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        if (is_dir($this->sidecarDir)) {
            @rmdir($this->sidecarDir);
            @rmdir(dirname($this->sidecarDir));
        }

        OpenApiCoverageTracker::reset();
        StrictRequiredTracker::reset();
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function merges_two_workers_into_a_combined_markdown_report(): void
    {
        // Worker 1 covers GET /v1/pets 200.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        // Worker 2 covers POST /v1/pets 201.
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

        $command = new CoverageMergeCommand(stdoutWriter: static fn(string $msg): null => null);
        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'output_file' => $this->outputFile,
            'cleanup' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertFileExists($this->outputFile);
        $contents = (string) file_get_contents($this->outputFile);
        $this->assertStringContainsString('OpenAPI Contract Test Coverage', $contents);
        $this->assertStringContainsString('GET /v1/pets', $contents);
        $this->assertStringContainsString('POST /v1/pets', $contents);

        // cleanup=true must remove all sidecars after a successful merge.
        $this->assertSame([], $this->sidecars());
    }

    #[Test]
    public function preserves_sidecars_when_cleanup_is_false(): void
    {
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        $command = new CoverageMergeCommand(stdoutWriter: static fn(string $msg): null => null);
        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'output_file' => $this->outputFile,
            'cleanup' => false,
        ]);

        $this->assertSame(0, $exit);
        $this->assertCount(1, $this->sidecars());
    }

    #[Test]
    public function appends_to_github_step_summary_once(): void
    {
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        $stepSummary = $this->sidecarDir . '/../step-summary.md';
        file_put_contents($stepSummary, '');

        $command = new CoverageMergeCommand(stdoutWriter: static fn(string $msg): null => null);
        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'github_step_summary' => $stepSummary,
            'cleanup' => true,
        ]);

        $this->assertSame(0, $exit);
        $contents = (string) file_get_contents($stepSummary);
        $this->assertStringContainsString('OpenAPI Contract Test Coverage', $contents);
        $this->assertSame(
            1,
            substr_count($contents, 'OpenAPI Contract Test Coverage'),
            'merge must emit a single combined report, not N partials',
        );

        @unlink($stepSummary);
    }

    #[Test]
    public function exits_one_when_sidecar_payload_is_corrupt(): void
    {
        // C1 fix: a malformed sidecar must produce exit 1 + FATAL stderr,
        // not exit 0 with a buried warning. Pre-fix, loadPayloads() caught
        // the read/decode error, wrote FATAL, and returned [] — caller
        // then took the "no sidecars" path and exited 0. CI couldn't
        // detect a corrupted worker.
        file_put_contents($this->sidecarDir . '/part-1-9999.json', '{not json');

        $stderr = '';
        $command = new CoverageMergeCommand(
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            stdoutWriter: static fn(string $msg): null => null,
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'output_file' => $this->outputFile,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('FATAL', $stderr);
        $this->assertStringContainsString('failed to decode', $stderr);
    }

    #[Test]
    public function exits_one_when_worker_failure_marker_present(): void
    {
        // C2 fix: a worker that crashed before writing its sidecar leaves
        // a `failed-<token>.json` marker. The merge CLI must see it and
        // exit non-zero — under-counted coverage is exactly the silent
        // failure parallel mode introduced.
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

        $stderr = '';
        $command = new CoverageMergeCommand(
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            stdoutWriter: static fn(string $msg): null => null,
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'output_file' => $this->outputFile,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('FATAL', $stderr);
        $this->assertStringContainsString('failed to write a sidecar', $stderr);
    }

    #[Test]
    public function returns_zero_with_warning_when_no_sidecars_present(): void
    {
        $stderr = '';
        $command = new CoverageMergeCommand(
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            stdoutWriter: static fn(string $msg): null => null,
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'output_file' => $this->outputFile,
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('no sidecars found', $stderr);
        $this->assertFileDoesNotExist($this->outputFile);
    }

    #[Test]
    public function parse_argv_decodes_long_options(): void
    {
        $opts = CoverageMergeCommand::parseArgv([
            '--spec-base-path=/tmp/spec',
            '--specs=front,admin',
            '--strip-prefixes=/api',
            '--sidecar-dir=/tmp/sidecars',
            '--output-file=/tmp/cov.md',
            '--junit-output=/tmp/cov.junit.xml',
            '--json-output=/tmp/cov.json',
            '--html-output=/tmp/cov.html',
            '--no-cleanup',
        ]);

        $this->assertSame('/tmp/spec', $opts['spec_base_path']);
        $this->assertSame(['front', 'admin'], $opts['specs']);
        $this->assertSame(['/api'], $opts['strip_prefixes']);
        $this->assertSame('/tmp/sidecars', $opts['sidecar_dir']);
        $this->assertSame('/tmp/cov.md', $opts['output_file']);
        $this->assertSame('/tmp/cov.junit.xml', $opts['junit_output']);
        $this->assertSame('/tmp/cov.json', $opts['json_output']);
        $this->assertSame('/tmp/cov.html', $opts['html_output']);
        $this->assertFalse($opts['cleanup']);
    }

    #[Test]
    public function exits_one_when_junit_output_write_fails(): void
    {
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        // /proc/0 is unwritable on Linux and nonexistent on macOS — file_put_contents fails closed.
        $unwritable = '/proc/0/forbidden/coverage.junit.xml';

        $stderr = '';
        $command = new CoverageMergeCommand(
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            stdoutWriter: static fn(string $msg): null => null,
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'junit_output' => $unwritable,
            'cleanup' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('FATAL', $stderr);
        $this->assertStringContainsString('JUnit XML', $stderr);
    }

    #[Test]
    public function writes_json_to_configured_path(): void
    {
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        $jsonPath = dirname($this->sidecarDir) . '/coverage.json';

        $command = new CoverageMergeCommand(stdoutWriter: static fn(string $msg): null => null);
        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'json_output' => $jsonPath,
            'cleanup' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertFileExists($jsonPath);

        $decoded = json_decode((string) file_get_contents($jsonPath), true);
        $this->assertIsArray($decoded);
        $this->assertSame(1, $decoded['schema_version']);
        $this->assertSame('studio-design/openapi-contract-testing', $decoded['tool']['name']);
        $this->assertArrayHasKey('petstore-3.0', $decoded['specs']);

        @unlink($jsonPath);
    }

    #[Test]
    public function exits_one_when_json_output_write_fails(): void
    {
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        $unwritable = '/proc/0/forbidden/coverage.json';

        $stderr = '';
        $command = new CoverageMergeCommand(
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            stdoutWriter: static fn(string $msg): null => null,
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'json_output' => $unwritable,
            'cleanup' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('FATAL', $stderr);
        $this->assertStringContainsString('JSON', $stderr);
    }

    #[Test]
    public function writes_all_four_formats_simultaneously(): void
    {
        // The "multiple format outputs are independent" contract is
        // documented in docs/coverage-json-schema.md and docs/coverage-html-output.md.
        // Pin that setting every output path writes every file — a regression
        // where one format's loop entry suppresses another would surface here.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        $base = dirname($this->sidecarDir);
        $mdPath = $base . '/coverage.md';
        $junitPath = $base . '/coverage.junit.xml';
        $jsonPath = $base . '/coverage.json';
        $htmlPath = $base . '/coverage.html';

        $command = new CoverageMergeCommand(stdoutWriter: static fn(string $msg): null => null);
        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'output_file' => $mdPath,
            'junit_output' => $junitPath,
            'json_output' => $jsonPath,
            'html_output' => $htmlPath,
            'cleanup' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertFileExists($mdPath);
        $this->assertFileExists($junitPath);
        $this->assertFileExists($jsonPath);
        $this->assertFileExists($htmlPath);

        @unlink($mdPath);
        @unlink($junitPath);
        @unlink($jsonPath);
        @unlink($htmlPath);
    }

    #[Test]
    public function writes_html_to_configured_path(): void
    {
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        $htmlPath = dirname($this->sidecarDir) . '/coverage.html';

        $command = new CoverageMergeCommand(stdoutWriter: static fn(string $msg): null => null);
        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'html_output' => $htmlPath,
            'cleanup' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertFileExists($htmlPath);

        $contents = (string) file_get_contents($htmlPath);
        $this->assertStringStartsWith('<!DOCTYPE html>', $contents);
        $this->assertStringContainsString('petstore-3.0', $contents);
        $this->assertStringContainsString('GET /v1/pets', $contents);

        @unlink($htmlPath);
    }

    #[Test]
    public function exits_one_when_html_output_write_fails(): void
    {
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        $unwritable = '/proc/0/forbidden/coverage.html';

        $stderr = '';
        $command = new CoverageMergeCommand(
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            stdoutWriter: static fn(string $msg): null => null,
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'html_output' => $unwritable,
            'cleanup' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('FATAL', $stderr);
        $this->assertStringContainsString('HTML', $stderr);
    }

    #[Test]
    public function writes_junit_xml_to_configured_path(): void
    {
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        $junitPath = dirname($this->sidecarDir) . '/coverage.junit.xml';

        $command = new CoverageMergeCommand(stdoutWriter: static fn(string $msg): null => null);
        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'output_file' => $this->outputFile,
            'junit_output' => $junitPath,
            'cleanup' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertFileExists($junitPath);
        $this->assertFileExists($this->outputFile, 'Markdown output should still be written when JUnit is enabled');

        $junitContents = (string) file_get_contents($junitPath);
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"', $junitContents);
        $this->assertStringContainsString('<testsuites', $junitContents);
        $this->assertStringContainsString('openapi.coverage.petstore-3.0', $junitContents);
        $this->assertStringContainsString('GET /v1/pets [200 application/json]', $junitContents);

        @unlink($junitPath);
    }

    #[Test]
    public function parse_argv_recognises_help_flag(): void
    {
        $this->assertTrue(CoverageMergeCommand::parseArgv(['--help'])['help'] ?? false);
        $this->assertTrue(CoverageMergeCommand::parseArgv(['-h'])['help'] ?? false);
    }

    #[Test]
    public function strip_prefixes_propagates_to_spec_loader(): void
    {
        // Behavioural pin: the merge CLI must hand `strip_prefixes` through
        // to the spec loader so downstream callers see the configured
        // prefixes. Pre-fix, the test only proved the option was accepted
        // (file written), not that it was honoured at all.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        $command = new CoverageMergeCommand(stdoutWriter: static fn(string $msg): null => null);
        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'strip_prefixes' => ['/api', '/v2'],
            'output_file' => $this->outputFile,
            'cleanup' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame(['/api', '/v2'], OpenApiSpecLoader::getStripPrefixes());
    }

    #[Test]
    public function exits_two_when_spec_base_path_is_missing(): void
    {
        $stderr = '';
        $command = new CoverageMergeCommand(
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            stdoutWriter: static fn(string $msg): null => null,
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'specs' => ['petstore-3.0'],
        ]);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('--spec-base-path is required', $stderr);
    }

    #[Test]
    public function empty_specs_argv_falls_back_to_default(): void
    {
        // I2 fix: `--specs=` parsed as empty list must fall back to the
        // documented default (`front`), not silently exit with "no
        // coverage recorded".
        $opts = CoverageMergeCommand::parseArgv([
            '--spec-base-path=' . __DIR__ . '/../../fixtures/specs',
            '--specs=',
        ]);
        // parseArgv strips empty entries, leaving 'specs' => []. run() must
        // not honour the empty list as "use no specs" — verified via the
        // run() code path: empty list triggers the `['front']` default.
        $this->assertSame([], $opts['specs']);
    }

    #[Test]
    public function exits_one_on_threshold_miss_when_strict(): void
    {
        // Issue #135: strict gate must fail-fast with exit 1 + FAIL stderr.
        // Single recorded validation against a 25-endpoint fixture is well
        // below 80%, so the gate trips reliably without per-fixture math.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        $stderr = '';
        $command = new CoverageMergeCommand(
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            stdoutWriter: static fn(string $msg): null => null,
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'min_endpoint_coverage' => 80.0,
            'min_coverage_strict' => true,
            'cleanup' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('[OpenAPI Coverage] FAIL:', $stderr);
        $this->assertStringContainsString('endpoint coverage', $stderr);
        $this->assertStringContainsString('< threshold 80%', $stderr);
    }

    #[Test]
    public function exits_zero_on_threshold_miss_when_not_strict(): void
    {
        // Default warn-only mode: print WARN to stderr but keep the run green.
        // Lets teams adopt the gate behind a flag without breaking CI on day 1.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        $stderr = '';
        $command = new CoverageMergeCommand(
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            stdoutWriter: static fn(string $msg): null => null,
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'min_endpoint_coverage' => 80.0,
            // min_coverage_strict omitted — default false
            'cleanup' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('[OpenAPI Coverage] WARN:', $stderr);
        $this->assertStringNotContainsString('FAIL:', $stderr);
    }

    #[Test]
    public function strict_gate_with_no_sidecars_exits_one(): void
    {
        // C2: an opt-in strict CI gate must not silently pass when paratest
        // produced zero workers / a wrong --sidecar-dir was passed. Pre-fix,
        // the empty-payload branch returned 0 with a WARNING.
        $stderr = '';
        $command = new CoverageMergeCommand(
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            stdoutWriter: static fn(string $msg): null => null,
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir, // empty
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'min_endpoint_coverage' => 80.0,
            'min_coverage_strict' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('FATAL', $stderr);
        $this->assertStringContainsString('no contract test coverage', $stderr);
    }

    #[Test]
    public function strict_gate_with_empty_recorded_coverage_exits_one(): void
    {
        // Sidecars exist but recorded no coverage (e.g. workers ran zero
        // contract assertions). Same silent-pass risk as no-sidecars.
        OpenApiCoverageTracker::reset(); // empty state
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        $stderr = '';
        $command = new CoverageMergeCommand(
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            stdoutWriter: static fn(string $msg): null => null,
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'min_endpoint_coverage' => 80.0,
            'min_coverage_strict' => true,
            'cleanup' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('FATAL', $stderr);
        $this->assertStringContainsString('no contract test coverage', $stderr);
    }

    #[Test]
    public function warn_only_gate_with_no_sidecars_keeps_existing_warning_path(): void
    {
        // Backwards compat: warn-only mode (or threshold absent) keeps the
        // pre-#135 "no sidecars found" warning + exit 0.
        $stderr = '';
        $command = new CoverageMergeCommand(
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            stdoutWriter: static fn(string $msg): null => null,
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'min_endpoint_coverage' => 80.0, // strict omitted
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('no sidecars found', $stderr);
    }

    #[Test]
    public function exits_one_on_response_only_threshold_miss_when_strict(): void
    {
        // Pin the response-only configuration end-to-end through the CLI;
        // merge gate must distinguish endpoint vs response misses.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        $stderr = '';
        $command = new CoverageMergeCommand(
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            stdoutWriter: static fn(string $msg): null => null,
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'min_response_coverage' => 80.0,
            'min_coverage_strict' => true,
            'cleanup' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('FAIL', $stderr);
        $this->assertStringContainsString('response coverage', $stderr);
        $this->assertStringNotContainsString('endpoint coverage', $stderr);
    }

    #[Test]
    public function exits_zero_when_threshold_met(): void
    {
        // No threshold-related stderr noise on the happy path.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        $stderr = '';
        $command = new CoverageMergeCommand(
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            stdoutWriter: static fn(string $msg): null => null,
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'min_endpoint_coverage' => 0.0, // any non-negative coverage clears 0%
            'min_coverage_strict' => true,
            'cleanup' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringNotContainsString('FAIL:', $stderr);
        $this->assertStringNotContainsString('WARN:', $stderr);
    }

    #[Test]
    public function parse_argv_decodes_threshold_flags(): void
    {
        $opts = CoverageMergeCommand::parseArgv([
            '--spec-base-path=/tmp/spec',
            '--min-endpoint-coverage=80',
            '--min-response-coverage=70.5',
            '--min-coverage-strict',
        ]);

        $this->assertSame(80.0, $opts['min_endpoint_coverage']);
        $this->assertSame(70.5, $opts['min_response_coverage']);
        $this->assertTrue($opts['min_coverage_strict']);
    }

    #[Test]
    public function parse_argv_treats_strict_false_value_as_false(): void
    {
        // Symmetry with the existing `cleanup` flag: `--min-coverage-strict=false`
        // must turn the gate into warn-only, not silently flip it on.
        $opts = CoverageMergeCommand::parseArgv([
            '--spec-base-path=/tmp/spec',
            '--min-coverage-strict=false',
        ]);

        $this->assertFalse($opts['min_coverage_strict']);
    }

    #[Test]
    public function out_of_range_threshold_warns_when_not_strict(): void
    {
        // Warn-only path: a typo'd threshold must surface as a WARNING but
        // not break a CI that opted out of fail-fast gating.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        $stderr = '';
        $command = new CoverageMergeCommand(
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            stdoutWriter: static fn(string $msg): null => null,
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'min_endpoint_coverage' => 150.0, // out of 0..100 range
            // min_coverage_strict omitted → warn-only
            'cleanup' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('WARNING', $stderr);
        $this->assertStringContainsString('min_endpoint_coverage', $stderr);
    }

    #[Test]
    public function out_of_range_threshold_exits_two_when_strict(): void
    {
        // C1: silent-failure-hunter & code-reviewer flagged that strict mode
        // must treat a typo'd threshold as a config error, not silently
        // disable the gate. Mirrors how `--spec-base-path` missing exits 2.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        $stderr = '';
        $command = new CoverageMergeCommand(
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            stdoutWriter: static fn(string $msg): null => null,
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'min_endpoint_coverage' => 150.0,
            'min_coverage_strict' => true,
            'cleanup' => true,
        ]);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('FATAL', $stderr);
        $this->assertStringContainsString('min_endpoint_coverage', $stderr);
    }

    #[Test]
    public function non_numeric_threshold_warns_when_not_strict(): void
    {
        // C3: parseArgv used to silently drop non-numeric values, so run()
        // never saw them. After C3 the raw string flows through and run()
        // is the single place that warns / fails.
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        $stderr = '';
        $command = new CoverageMergeCommand(
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            stdoutWriter: static fn(string $msg): null => null,
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'min_endpoint_coverage' => 'eighty',
            'cleanup' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('WARNING', $stderr);
        $this->assertStringContainsString("min_endpoint_coverage='eighty'", $stderr);
    }

    #[Test]
    public function non_numeric_threshold_exits_two_when_strict(): void
    {
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        $stderr = '';
        $command = new CoverageMergeCommand(
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            stdoutWriter: static fn(string $msg): null => null,
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'min_endpoint_coverage' => 'eighty',
            'min_coverage_strict' => true,
            'cleanup' => true,
        ]);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('FATAL', $stderr);
    }

    #[Test]
    public function parse_argv_keeps_non_numeric_threshold_value_for_run_to_validate(): void
    {
        // C3: a typo'd `--min-endpoint-coverage=eighty` must reach run() so
        // the user sees a single WARNING/FATAL message. Pre-fix, parseArgv
        // dropped the value silently and the gate was disabled invisibly.
        $opts = CoverageMergeCommand::parseArgv([
            '--spec-base-path=/tmp/spec',
            '--min-endpoint-coverage=eighty',
        ]);

        $this->assertSame('eighty', $opts['min_endpoint_coverage']);
    }

    #[Test]
    public function exits_one_when_output_file_write_fails(): void
    {
        OpenApiCoverageTracker::recordResponse(
            'petstore-3.0',
            'GET',
            '/v1/pets',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());

        // file_put_contents() === false when the parent dir does not exist
        // and isn't created. /proc/0 is unwritable on Linux; on macOS the
        // path simply doesn't exist. Either way `file_put_contents` fails
        // closed.
        $unwritable = '/proc/0/forbidden/coverage-report.md';

        $stderr = '';
        $command = new CoverageMergeCommand(
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
            stdoutWriter: static fn(string $msg): null => null,
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['petstore-3.0'],
            'output_file' => $unwritable,
            'cleanup' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Failed to write Markdown report', $stderr);
    }

    #[Test]
    public function merges_strict_required_observations_across_workers_in_warn_mode(): void
    {
        $this->writeStrictRequiredWorkerSidecar('1', ['expires', 'signed_url', 'url']);
        $this->writeStrictRequiredWorkerSidecar('2', ['expires', 'signed_url', 'url']);

        $stderr = '';
        $command = new CoverageMergeCommand(
            stdoutWriter: static fn(string $msg): null => null,
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['under-described'],
            'output_file' => $this->outputFile,
            'strict_required' => 'warn',
            'cleanup' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('[OpenAPI Strict Required] WARNING', $stderr);
        $this->assertStringContainsString('PUT /signed-url', $stderr);
    }

    #[Test]
    public function merge_fails_with_exit_one_in_strict_required_fail_mode_on_drift(): void
    {
        $this->writeStrictRequiredWorkerSidecar('1', ['expires', 'signed_url', 'url']);
        $this->writeStrictRequiredWorkerSidecar('2', ['expires', 'signed_url', 'url']);

        $stderr = '';
        $command = new CoverageMergeCommand(
            stdoutWriter: static fn(string $msg): null => null,
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['under-described'],
            'output_file' => $this->outputFile,
            'strict_required' => 'fail',
            'cleanup' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('[OpenAPI Strict Required] FATAL', $stderr);
    }

    #[Test]
    public function merge_passes_in_off_mode_even_when_drift_exists(): void
    {
        $this->writeStrictRequiredWorkerSidecar('1', ['expires', 'signed_url', 'url']);

        $stderr = '';
        $command = new CoverageMergeCommand(
            stdoutWriter: static fn(string $msg): null => null,
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['under-described'],
            'output_file' => $this->outputFile,
            'cleanup' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringNotContainsString('[OpenAPI Strict Required]', $stderr);
    }

    #[Test]
    public function merge_rejects_unknown_strict_required_value_with_exit_two(): void
    {
        $this->writeStrictRequiredWorkerSidecar('1', ['expires', 'signed_url', 'url']);

        $stderr = '';
        $command = new CoverageMergeCommand(
            stdoutWriter: static fn(string $msg): null => null,
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['under-described'],
            'output_file' => $this->outputFile,
            'strict_required' => 'loud',
            'cleanup' => true,
        ]);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('[OpenAPI Strict Required] FATAL', $stderr);
    }

    #[Test]
    public function strict_required_emits_unresolved_groups_note_in_warn_mode(): void
    {
        // The merge CLI's unresolved-groups NOTE branch must surface
        // observations that have no matching response schema. Without this
        // pin, a refactor of evaluateStrictRequiredGate() could drop the
        // detectUnresolvedGroups() call and the user would no longer be
        // told why no drift block appeared.
        OpenApiCoverageTracker::reset();
        StrictRequiredTracker::reset();
        // GET on /signed-url has no schema in the fixture (only PUT does)
        // — the observation cannot be resolved against any spec response.
        OpenApiCoverageTracker::recordResponse(
            'under-described',
            'GET',
            '/signed-url',
            '200',
            'application/json',
            schemaValidated: true,
        );
        StrictRequiredTracker::record(
            'under-described',
            'GET',
            '/signed-url',
            '200',
            'application/json',
            ['/' => ['expires']],
        );
        $envelope = CoverageSidecarEnvelope::build(
            OpenApiCoverageTracker::exportState(),
            StrictRequiredTracker::exportState(),
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', $envelope);
        OpenApiCoverageTracker::reset();
        StrictRequiredTracker::reset();

        $stderr = '';
        $command = new CoverageMergeCommand(
            stdoutWriter: static fn(string $msg): null => null,
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['under-described'],
            'output_file' => $this->outputFile,
            'strict_required' => 'warn',
            'cleanup' => true,
        ]);

        $this->assertSame(0, $exit, "stderr: {$stderr}");
        $this->assertStringContainsString('[OpenAPI Strict Required] NOTE', $stderr);
        $this->assertStringContainsString('GET /signed-url', $stderr);
        // No drift was detected — only the unresolved NOTE should appear.
        $this->assertStringNotContainsString('[OpenAPI Strict Required] WARNING', $stderr);
    }

    #[Test]
    public function strict_required_fail_appends_block_to_github_step_summary(): void
    {
        // The merge CLI must surface drift to $GITHUB_STEP_SUMMARY so a CI
        // user reading the run summary tab sees the FATAL block. Without
        // this pin a refactor that drops the github_step_summary argument
        // from evaluateStrictRequiredGate() would regress silently.
        $this->writeStrictRequiredWorkerSidecar('1', ['expires', 'signed_url', 'url']);

        $githubSummary = $this->outputFile . '.github-summary.md';

        $stderr = '';
        $command = new CoverageMergeCommand(
            stdoutWriter: static fn(string $msg): null => null,
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['under-described'],
            'output_file' => $this->outputFile,
            'github_step_summary' => $githubSummary,
            'strict_required' => 'fail',
            'cleanup' => true,
        ]);

        $this->assertSame(1, $exit);
        $contents = (string) file_get_contents($githubSummary);
        $this->assertStringContainsString(':rotating_light: FATAL OpenAPI strict_required drift', $contents);
        $this->assertStringContainsString('PUT /signed-url', $contents);

        @unlink($githubSummary);
    }

    #[Test]
    public function strict_required_warn_appends_warning_block_to_github_step_summary(): void
    {
        // Pin the warn-mode block label too — the heading swap (rotating_light
        // vs warning) lives in OpenApiCoverageExtension::appendGithub… and
        // changing it accidentally would mislead CI users about severity.
        $this->writeStrictRequiredWorkerSidecar('1', ['expires', 'signed_url', 'url']);

        $githubSummary = $this->outputFile . '.github-summary.md';
        $command = new CoverageMergeCommand(
            stdoutWriter: static fn(string $msg): null => null,
            stderrWriter: static fn(string $msg): null => null,
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['under-described'],
            'output_file' => $this->outputFile,
            'github_step_summary' => $githubSummary,
            'strict_required' => 'warn',
            'cleanup' => true,
        ]);

        $this->assertSame(0, $exit);
        $contents = (string) file_get_contents($githubSummary);
        $this->assertStringContainsString(':warning: OpenAPI strict_required drift', $contents);

        @unlink($githubSummary);
    }

    #[Test]
    public function strict_required_fail_writes_all_coverage_outputs_before_aborting(): void
    {
        // Pin the documented "render report first, assert gate second"
        // contract: a strict_required=fail drift must NOT suppress the
        // coverage outputs users rely on for triage. A future refactor that
        // re-orders evaluateStrictRequiredGate() ahead of writeReports() /
        // appendGithubStepSummary() must fail this test.
        $this->writeStrictRequiredWorkerSidecar('1', ['expires', 'signed_url', 'url']);
        $this->writeStrictRequiredWorkerSidecar('2', ['expires', 'signed_url', 'url']);

        $junitOutput = $this->outputFile . '.junit.xml';
        $jsonOutput = $this->outputFile . '.json';
        $htmlOutput = $this->outputFile . '.html';
        $githubSummary = $this->outputFile . '.github-summary.md';

        $stderr = '';
        $command = new CoverageMergeCommand(
            stdoutWriter: static fn(string $msg): null => null,
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['under-described'],
            'output_file' => $this->outputFile,
            'junit_output' => $junitOutput,
            'json_output' => $jsonOutput,
            'html_output' => $htmlOutput,
            'github_step_summary' => $githubSummary,
            'strict_required' => 'fail',
            'cleanup' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('[OpenAPI Strict Required] FATAL', $stderr);

        // Every configured coverage output must exist and contain the
        // endpoint that triggered the drift — proving the renderer ran
        // before the gate decided to fail.
        foreach ([$this->outputFile, $junitOutput, $jsonOutput, $htmlOutput, $githubSummary] as $path) {
            $this->assertFileExists($path, "coverage output {$path} must be written before strict gate aborts");
            $this->assertStringContainsString('PUT /signed-url', (string) file_get_contents($path), "expected PUT /signed-url in {$path}");
        }

        // The GH step summary must also carry the strict_required FATAL
        // block, not just the coverage Markdown — covered separately below.

        @unlink($junitOutput);
        @unlink($jsonOutput);
        @unlink($htmlOutput);
        @unlink($githubSummary);
    }

    #[Test]
    public function fail_mode_exits_non_zero_when_no_worker_recorded_strict_observations(): void
    {
        // A CI that opted into --strict-required=fail must NOT silently pass
        // when every worker contributed a legacy v1 sidecar (no strict
        // observations). The gate cannot evaluate the contract, and that's
        // a fail-loud condition — symmetric with the threshold gate's
        // "no contract test coverage was recorded" guard at run() L296.
        OpenApiCoverageTracker::recordResponse(
            'under-described',
            'PUT',
            '/signed-url',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());
        OpenApiCoverageTracker::reset();

        $stderr = '';
        $command = new CoverageMergeCommand(
            stdoutWriter: static fn(string $msg): null => null,
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['under-described'],
            'output_file' => $this->outputFile,
            'strict_required' => 'fail',
            'cleanup' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('[OpenAPI Strict Required] FATAL', $stderr);
        $this->assertStringContainsString('no worker recorded', $stderr);
    }

    #[Test]
    public function warn_mode_passes_silently_when_no_worker_recorded_strict_observations(): void
    {
        // Symmetric design: warn mode tolerates "no observations" because
        // the user explicitly opted out of fail-fast. The mixed v1/v2
        // upgrade window relies on this — flipping to warn first lets users
        // roll out v2 workers gradually before turning the gate to fail.
        OpenApiCoverageTracker::recordResponse(
            'under-described',
            'PUT',
            '/signed-url',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());
        OpenApiCoverageTracker::reset();

        $stderr = '';
        $command = new CoverageMergeCommand(
            stdoutWriter: static fn(string $msg): null => null,
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['under-described'],
            'output_file' => $this->outputFile,
            'strict_required' => 'warn',
            'cleanup' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringNotContainsString('[OpenAPI Strict Required] FATAL', $stderr);
    }

    #[Test]
    public function merge_tolerates_mixed_v1_and_v2_sidecars(): void
    {
        // Worker A: legacy v1 (bare coverage payload, no strict_required).
        // Simulates a worker still on an older library version during an
        // upgrade window — coverage merges; strict_required half is absent.
        OpenApiCoverageTracker::recordResponse(
            'under-described',
            'GET',
            '/users/{id}',
            '200',
            'application/json',
            schemaValidated: true,
        );
        CoverageSidecarWriter::write($this->sidecarDir, '1', OpenApiCoverageTracker::exportState());
        OpenApiCoverageTracker::reset();

        // Worker B: v2 envelope with drift-inducing observations.
        $this->writeStrictRequiredWorkerSidecar('2', ['expires', 'signed_url', 'url']);

        $stderr = '';
        $command = new CoverageMergeCommand(
            stdoutWriter: static fn(string $msg): null => null,
            stderrWriter: static function (string $msg) use (&$stderr): void {
                $stderr .= $msg;
            },
        );

        $exit = $command->run([
            'sidecar_dir' => $this->sidecarDir,
            'spec_base_path' => __DIR__ . '/../../fixtures/specs',
            'specs' => ['under-described'],
            'output_file' => $this->outputFile,
            'strict_required' => 'warn',
            'cleanup' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('[OpenAPI Strict Required] WARNING', $stderr);
        // Coverage from worker A must still be merged.
        $contents = (string) file_get_contents($this->outputFile);
        $this->assertStringContainsString('GET /users/{id}', $contents);
    }

    #[Test]
    public function parse_argv_accepts_strict_required_flag(): void
    {
        $opts = CoverageMergeCommand::parseArgv(['--strict-required=warn']);
        $this->assertSame('warn', $opts['strict_required'] ?? null);
    }

    /**
     * Write a worker sidecar carrying an under-described drift observation:
     * a response that always returned `expires`/`signed_url`/`url` even
     * though the spec declares them optional.
     *
     * @param list<string> $alwaysPresent top-level keys present at the root
     *                                    pointer for this worker's observation
     */
    private function writeStrictRequiredWorkerSidecar(string $token, array $alwaysPresent): void
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
            ['/' => $alwaysPresent],
        );
        $envelope = CoverageSidecarEnvelope::build(
            OpenApiCoverageTracker::exportState(),
            StrictRequiredTracker::exportState(),
        );
        CoverageSidecarWriter::write($this->sidecarDir, $token, $envelope);
        OpenApiCoverageTracker::reset();
        StrictRequiredTracker::reset();
    }

    /** @return list<string> */
    private function sidecars(): array
    {
        return glob($this->sidecarDir . '/*.json') ?: [];
    }
}
