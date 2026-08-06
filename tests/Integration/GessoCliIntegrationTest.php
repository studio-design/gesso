<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Cli\CoverageGateCommand;
use Studio\Gesso\Cli\StubsCommand;
use Studio\Gesso\Coverage\CoverageMergeCommand;

use function dirname;
use function escapeshellarg;
use function explode;
use function fclose;
use function file_get_contents;
use function is_resource;
use function proc_close;
use function proc_open;
use function realpath;
use function sprintf;
use function str_replace;
use function stream_get_contents;
use function trim;

final class GessoCliIntegrationTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repoRoot = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
    }

    /** @return iterable<string, array{0: list<string>}> */
    public static function provideRoot_help_lists_the_v2_gesso_commandsCases(): iterable
    {
        yield 'no arguments' => [[]];
        yield 'list' => [['list']];
        yield 'long help' => [['--help']];
        yield 'short help' => [['-h']];
    }

    /** @param list<string> $arguments */
    #[Test]
    #[DataProvider('provideRoot_help_lists_the_v2_gesso_commandsCases')]
    public function root_help_lists_the_v2_gesso_commands(array $arguments): void
    {
        [$exit, $stdout, $stderr] = $this->runCli($arguments);

        $this->assertSame(0, $exit);
        $this->assertSame('', $stderr);
        $this->assertSame(
            file_get_contents($this->repoRoot . '/tests/fixtures/compatibility/v2-gesso-help.txt'),
            $stdout,
        );
        // The global-options section is additive, so every v1.10 help line must
        // survive it — containment against the frozen capture, not equality.
        foreach (explode("\n", (string) file_get_contents($this->repoRoot . '/tests/fixtures/compatibility/v1.10-gesso-help.txt')) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $this->assertStringContainsString($line, $stdout, 'v1.10 help line must be retained');
        }
    }

    #[Test]
    public function version_prints_the_installed_version_and_exits_zero(): void
    {
        [$exit, $stdout, $stderr] = $this->runCli(['--version']);

        $this->assertSame(0, $exit);
        $this->assertSame('', $stderr);
        $this->assertMatchesRegularExpression('/^gesso \S+\n$/', $stdout);
    }

    #[Test]
    public function help_documents_the_version_flag(): void
    {
        [, $stdout] = $this->runCli(['--help']);

        $this->assertStringContainsString('Global options:', $stdout);
        $this->assertStringContainsString('--version', $stdout);
    }

    #[Test]
    public function doctor_uses_the_gesso_invocation_in_help_and_usage_errors(): void
    {
        [$helpExit, $helpStdout, $helpStderr] = $this->runCli(['doctor', '--help']);
        [$errorExit, $errorStdout, $errorStderr] = $this->runCli(['doctor']);

        $this->assertSame(0, $helpExit);
        $this->assertSame('', $helpStderr);
        $this->assertSame(
            file_get_contents($this->repoRoot . '/tests/fixtures/compatibility/v2-gesso-doctor-help.txt'),
            $helpStdout,
        );
        $this->assertSame(2, $errorExit);
        $this->assertSame('', $errorStdout);
        $this->assertSame(
            file_get_contents($this->repoRoot . '/tests/fixtures/compatibility/v2-gesso-doctor-usage-error.txt'),
            $errorStderr,
        );
    }

    #[Test]
    public function coverage_merge_uses_the_gesso_invocation_in_help_and_usage_errors(): void
    {
        [$helpExit, $helpStdout, $helpStderr] = $this->runCli(['coverage:merge', '--help']);
        [$errorExit, $errorStdout, $errorStderr] = $this->runCli([
            'coverage:merge',
            '--specs=petstore-3.0',
        ]);

        $this->assertSame(0, $helpExit);
        $this->assertSame('', $helpStderr);
        // The subcommand must route --help to the canonical usage text under
        // the `gesso coverage:merge` invocation …
        $this->assertSame(CoverageMergeCommand::usage(), $helpStdout);
        // … and every v1.9 help line must survive per the v1.x CLI
        // compatibility policy. New flags may be appended, so this is
        // containment, not equality, against the frozen capture.
        $v19Help = str_replace(
            'openapi-coverage-merge',
            'gesso coverage:merge',
            (string) file_get_contents($this->repoRoot . '/tests/fixtures/compatibility/v1.9-openapi-coverage-merge-help.txt'),
        );
        foreach (explode("\n", $v19Help) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $this->assertStringContainsString($line, $helpStdout, 'v1.9 help line must be retained');
        }
        $this->assertSame(2, $errorExit);
        $this->assertSame('', $errorStdout);
        $this->assertSame(
            str_replace(
                'openapi-coverage-merge',
                'gesso coverage:merge',
                (string) file_get_contents($this->repoRoot . '/tests/fixtures/compatibility/v1.9-openapi-coverage-merge-usage-error.txt'),
            ),
            $errorStderr,
        );
    }

    #[Test]
    public function coverage_gate_uses_the_gesso_invocation_in_help_and_usage_errors(): void
    {
        [$helpExit, $helpStdout, $helpStderr] = $this->runCli(['coverage:gate', '--help']);
        [$errorExit, $errorStdout, $errorStderr] = $this->runCli(['coverage:gate', '--spec=openapi.json']);

        $this->assertSame(0, $helpExit);
        $this->assertSame('', $helpStderr);
        $this->assertSame(CoverageGateCommand::usage(), $helpStdout);
        $this->assertSame(2, $errorExit);
        $this->assertSame('', $errorStdout);
        $this->assertStringContainsString('[Gesso] --base-spec is required.', $errorStderr);
    }

    #[Test]
    public function stubs_uses_the_gesso_invocation_in_help_and_usage_errors(): void
    {
        [$helpExit, $helpStdout, $helpStderr] = $this->runCli(['stubs', '--help']);
        [$errorExit, $errorStdout, $errorStderr] = $this->runCli(['stubs']);

        $this->assertSame(0, $helpExit);
        $this->assertSame('', $helpStderr);
        $this->assertSame(StubsCommand::usage(), $helpStdout);
        $this->assertSame(2, $errorExit);
        $this->assertSame('', $errorStdout);
        $this->assertStringContainsString('[Gesso] --spec is required.', $errorStderr);
    }

    #[Test]
    public function unknown_command_is_a_usage_error(): void
    {
        [$exit, $stdout, $stderr] = $this->runCli(['unknown']);

        $this->assertSame(2, $exit);
        $this->assertSame('', $stdout);
        $this->assertSame(
            '[Gesso] Unknown command: unknown' . "\n\n"
                . file_get_contents($this->repoRoot . '/tests/fixtures/compatibility/v2-gesso-help.txt'),
            $stderr,
        );
    }

    /**
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function runCli(array $args): array
    {
        $command = sprintf('php %s', escapeshellarg($this->repoRoot . '/bin/gesso'));
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->repoRoot);
        if (!is_resource($process)) {
            $this->fail('failed to spawn Gesso CLI');
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        return [proc_close($process), $stdout, $stderr];
    }
}
