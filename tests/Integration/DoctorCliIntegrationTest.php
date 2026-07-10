<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Integration;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function escapeshellarg;
use function fclose;
use function is_resource;
use function json_decode;
use function proc_close;
use function proc_open;
use function realpath;
use function sprintf;
use function stream_get_contents;

class DoctorCliIntegrationTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repoRoot = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
    }

    #[Test]
    public function composer_bin_diagnoses_json_yaml_local_refs_and_multiple_specs(): void
    {
        [$exit, $stdout, $stderr] = $this->runCli([
            'doctor',
            '--spec=tests/fixtures/specs/petstore-3.0.json',
            '--spec=tests/fixtures/specs/external-refs/multi-file.yaml',
            '--format=json',
        ]);

        $this->assertSame(0, $exit, "stderr: {$stderr}\nstdout: {$stdout}");
        $report = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $report['schemaVersion']);
        $this->assertSame(2, $report['summary']['specs']);
        $this->assertGreaterThan(0, $report['summary']['operations']);
    }

    #[Test]
    public function composer_bin_reports_malformed_and_http_ref_configuration_failures(): void
    {
        [$malformedExit, $malformedOutput] = $this->runCli([
            'doctor',
            '--spec=tests/fixtures/specs/malformed-json.json',
            '--format=json',
        ]);
        $this->assertSame(1, $malformedExit);
        $this->assertSame('parser', json_decode($malformedOutput, true, flags: JSON_THROW_ON_ERROR)['issues'][0]['category']);

        [$httpExit, $httpOutput] = $this->runCli([
            'doctor',
            '--spec=tests/fixtures/specs/http-ref.json',
            '--format=json',
        ]);
        $httpReport = json_decode($httpOutput, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $httpExit);
        $this->assertSame('references', $httpReport['issues'][0]['category']);
        $this->assertStringContainsString('--allow-remote-refs', $httpReport['issues'][0]['suggestion']);
    }

    #[Test]
    public function composer_bin_rejects_malformed_response_object(): void
    {
        [$exit, $stdout] = $this->runCli([
            'doctor',
            '--spec=tests/fixtures/specs/malformed-response.json',
            '--format=json',
        ]);
        $report = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('error', $report['status']);
        $this->assertSame(0, $report['summary']['responses']);
        $this->assertStringContainsString('responses[200]', $report['issues'][0]['message']);
    }

    #[Test]
    public function composer_bin_help_documents_exit_codes(): void
    {
        [$exit, $stdout] = $this->runCli(['doctor', '--help']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Exit codes:', $stdout);
        $this->assertStringContainsString('--spec', $stdout);
    }

    /**
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function runCli(array $args): array
    {
        $command = sprintf('php %s', escapeshellarg($this->repoRoot . '/bin/openapi-contract'));
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->repoRoot);
        if (!is_resource($process)) {
            $this->fail('failed to spawn doctor CLI');
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        return [proc_close($process), $stdout, $stderr];
    }
}
