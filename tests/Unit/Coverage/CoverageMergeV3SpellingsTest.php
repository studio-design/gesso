<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Coverage;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\CoverageMergeCommand;
use Studio\Gesso\Coverage\CoverageSidecarWriter;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Internal\Deprecations;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function file_get_contents;
use function glob;
use function json_decode;
use function mkdir;
use function rmdir;
use function str_replace;
use function substr_count;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Issue #507, v2 half: every v3 `coverage:merge` spelling is accepted and
 * folds onto the same option as the v2 spelling it replaces, which keeps
 * working with one deprecation notice.
 */
final class CoverageMergeV3SpellingsTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        Deprecations::resetForTesting();
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::reset();
        $this->dir = sys_get_temp_dir() . '/gesso-merge-v3-' . uniqid('', true);
        mkdir($this->dir . '/sidecars', 0o755, recursive: true);
    }

    protected function tearDown(): void
    {
        foreach ([...(glob($this->dir . '/sidecars/*') ?: []), ...(glob($this->dir . '/*.*') ?: [])] as $path) {
            @unlink($path);
        }
        @rmdir($this->dir . '/sidecars');
        @rmdir($this->dir);
        Deprecations::resetForTesting();
        OpenApiCoverageTracker::resetCurrent();
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    /** @return iterable<string, array{list<string>, list<string>, list<string>}> */
    public static function provideThe_v3_spelling_parses_to_the_v2_optionCases(): iterable
    {
        yield 'spec names' => [['--specs=a,b'], ['--spec-name=a', '--spec-name=b'], ['--specs']];
        yield 'strip prefixes' => [['--strip-prefixes=/api,/v2'], ['--strip-prefix=/api,/v2'], ['--strip-prefixes']];
        yield 'report sinks' => [
            ['--json-output=j.json', '--junit-output=u.xml', '--html-output=h.html'],
            ['--report=json:j.json', '--report=junit:u.xml', '--report=html:h.html'],
            ['--json-output', '--junit-output', '--html-output'],
        ];
        yield 'console report' => [['--console-output=all'], ['--console-report=all'], ['--console-output']];
        yield 'thresholds' => [
            ['--min-endpoint-coverage=80', '--min-response-coverage=70', '--min-sdk-exercise-coverage=50', '--min-coverage-strict'],
            ['--min-coverage=endpoint=80,response=70,sdk_exercise=50,strict'],
            ['--min-endpoint-coverage', '--min-response-coverage', '--min-sdk-exercise-coverage', '--min-coverage-strict'],
        ];
        yield 'strict false' => [['--min-coverage-strict=false'], ['--min-coverage=strict=false'], ['--min-coverage-strict']];
        yield 'baselines' => [
            ['--baseline-file=v.json', '--coverage-baseline-file=c.json'],
            ['--baseline=violations=v.json,coverage=c.json'],
            ['--baseline-file', '--coverage-baseline-file'],
        ];
        yield 'baseline staleness' => [['--coverage-baseline-stale=fail'], ['--baseline-stale=coverage=fail'], ['--coverage-baseline-stale']];
        yield 'paths with commas' => [
            ['--json-output=build/a,b.json', '--coverage-baseline-file=c,d.json'],
            ['--report=json:build/a,b.json', '--baseline=coverage=c,d.json'],
            ['--json-output', '--coverage-baseline-file'],
        ];
        yield 'grouped members across occurrences' => [
            ['--baseline-file=v,1.json', '--coverage-baseline-file=c.json'],
            ['--baseline=violations=v,1.json', '--baseline=coverage=c.json'],
            ['--baseline-file', '--coverage-baseline-file'],
        ];
        yield 'strict modes' => [
            ['--strict-required=warn', '--strict-additional-properties=fail'],
            ['--strict-required=run=warn,per_call=off', '--strict-additional-properties=run=fail'],
            ['--strict-required', '--strict-additional-properties'],
        ];
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function provideA_conflicting_or_malformed_spelling_is_a_usage_errorCases(): iterable
    {
        yield 'spec name twice over' => [['--specs=a', '--spec-name=b'], '--specs and --spec-name set the same option'];
        yield 'report and its sink' => [['--json-output=a.json', '--report=json:b.json'], '--json-output and --report=json:<path> set the same option'];
        yield 'strict both ways' => [['--min-coverage-strict', '--min-coverage=strict'], '--min-coverage-strict and --min-coverage="strict"'];
        yield 'staleness both ways' => [['--coverage-baseline-stale=note', '--baseline-stale=coverage=fail'], 'set the same option'];
        yield 'unknown report format' => [['--report=xml:a.xml'], '--report=xml:a.xml must be'];
        yield 'report without a path' => [['--report=json'], '--report=json must be'];
        yield 'report twice' => [['--report=json:a.json', '--report=json:b.json'], 'names the json report twice'];
        yield 'unknown member' => [['--min-coverage=endpont=80'], '--min-coverage does not take "endpont"'];
        yield 'member without value' => [['--min-coverage=endpoint'], 'needs a value for endpoint'];
        yield 'violations staleness' => [['--baseline-stale=violations=fail'], '--baseline-stale does not take "violations"'];
        yield 'typo after a comma' => [['--min-coverage=response=80,strcit'], '--min-coverage does not take "strcit"'];
        yield 'empty grouped flag' => [['--min-coverage='], '--min-coverage has an empty member'];
        yield 'empty baseline' => [['--baseline= '], '--baseline has an empty member'];
        yield 'blank value' => [['--baseline=coverage='], 'needs a value for coverage'];
        yield 'blank strict mode' => [['--strict-additional-properties=run= '], 'needs a value for run'];
        yield 'mixed strict spellings' => [['--strict-additional-properties=fail', '--strict-additional-properties=run=off'], 'set the same option'];
        yield 'bad per_call' => [['--strict-required=run=warn,per_call=fail'], 'per_call=fail is not one of off, warn'];
    }

    /**
     * @param list<string> $v2
     * @param list<string> $v3
     * @param list<string> $deprecated
     */
    #[Test]
    #[DataProvider('provideThe_v3_spelling_parses_to_the_v2_optionCases')]
    public function the_v3_spelling_parses_to_the_v2_option(array $v2, array $v3, array $deprecated): void
    {
        $old = CoverageMergeCommand::parseArgv($v2);
        $new = CoverageMergeCommand::parseArgv($v3);

        $this->assertSame($deprecated, $old['deprecated_flags'] ?? null);
        $this->assertSame([], $new['deprecated_flags'] ?? null);
        unset($old['deprecated_flags'], $new['deprecated_flags']);
        $this->assertSame($old, $new);
        $this->assertArrayNotHasKey('usage_error', $new);
    }

    /** @param list<string> $argv */
    #[Test]
    #[DataProvider('provideA_conflicting_or_malformed_spelling_is_a_usage_errorCases')]
    public function a_conflicting_or_malformed_spelling_is_a_usage_error(array $argv, string $message): void
    {
        [$exit, $stderr] = $this->merge([...$argv, '--spec-base-path=' . __DIR__ . '/../../fixtures/specs']);

        $this->assertSame(CoverageMergeCommand::EXIT_USAGE, $exit);
        $this->assertStringContainsString($message, $stderr);
    }

    #[Test]
    public function each_v2_spelling_is_announced_once_per_process(): void
    {
        $argv = ['--spec-base-path=' . __DIR__ . '/../../fixtures/specs', '--specs=petstore-3.0', '--console-output=all'];

        [, $first] = $this->merge($argv);
        [, $second] = $this->merge($argv);

        $this->assertSame(1, substr_count($first . $second, "[Gesso deprecation] The CLI flag '--specs' is deprecated. Use --spec-name"));
        $this->assertSame(1, substr_count($first . $second, "The CLI flag '--console-output' is deprecated. Use --console-report instead."));
        $this->assertSame(['cli.coverage_merge.console_output' => 2, 'cli.coverage_merge.specs' => 2], Deprecations::counts());
    }

    #[Test]
    public function an_unknown_argument_warns_and_keeps_the_exit_code(): void
    {
        [$exit, $stderr] = $this->merge(['--spec-base-path=' . __DIR__ . '/../../fixtures/specs', '--min-endpont-coverage=90', 'stray']);

        $this->assertSame(CoverageMergeCommand::EXIT_OK, $exit);
        $this->assertStringContainsString('[Gesso] WARNING: ignoring unknown argument --min-endpont-coverage;', $stderr);
        $this->assertStringContainsString('[Gesso] WARNING: ignoring unknown argument stray;', $stderr);
    }

    #[Test]
    public function both_spellings_produce_the_same_reports(): void
    {
        OpenApiCoverageTracker::recordResponse('petstore-3.0', 'GET', '/v1/pets', '200', 'application/json', schemaValidated: true);
        CoverageSidecarWriter::write($this->dir . '/sidecars', '1', OpenApiCoverageTracker::exportState());
        OpenApiCoverageTracker::reset();

        $reports = [];
        foreach (['v2' => ['--specs=petstore-3.0', '--json-output=%s/v2.json', '--console-output=all', '--min-endpoint-coverage=10'], 'v3' => ['--spec-name=petstore-3.0', '--report=json:%s/v3.json', '--console-report=all', '--min-coverage=endpoint=10']] as $spelling => $flags) {
            $argv = ['--spec-base-path=' . __DIR__ . '/../../fixtures/specs', '--no-cleanup', '--output-file=' . $this->dir . "/{$spelling}.md"];
            foreach ($flags as $flag) {
                $argv[] = str_replace('%s', $this->dir, $flag);
            }
            [$exit, $stderr] = $this->merge($argv);
            $this->assertSame(CoverageMergeCommand::EXIT_OK, $exit, $stderr);

            $json = json_decode((string) file_get_contents($this->dir . "/{$spelling}.json"), true, flags: JSON_THROW_ON_ERROR);
            unset($json['generated_at']);
            $reports[$spelling] = [(string) file_get_contents($this->dir . "/{$spelling}.md"), $json];
        }

        $this->assertNotSame('', $reports['v3'][0]);
        $this->assertSame($reports['v2'], $reports['v3']);
    }

    /**
     * @param list<string> $argv
     *
     * @return array{int, string}
     */
    private function merge(array $argv): array
    {
        $stderr = '';
        $command = new CoverageMergeCommand(
            stdoutWriter: static fn(string $message): null => null,
            stderrWriter: static function (string $message) use (&$stderr): void {
                $stderr .= $message;
            },
        );
        $exit = $command->run(CoverageMergeCommand::parseArgv([
            '--sidecar-dir=' . $this->dir . '/sidecars',
            ...$argv,
        ]));

        return [$exit, $stderr];
    }
}
