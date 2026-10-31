<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Coverage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\CoverageMergeCommand;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Internal\Deprecations;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function glob;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

/**
 * Issue #507: every v3 `coverage:merge` spelling folds onto the merge's
 * internal option, and every v2 spelling Gesso 3.0 removed is a usage error
 * that names its replacement.
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

    /** @return iterable<string, array{list<string>, array<string, mixed>}> */
    public static function provideThe_v3_spelling_parses_to_the_merge_optionCases(): iterable
    {
        yield 'spec names' => [['--spec-name=a', '--spec-name=b'], ['specs' => ['a', 'b']]];
        yield 'strip prefixes' => [['--strip-prefix=/api,/v2'], ['strip_prefixes' => ['/api', '/v2']]];
        yield 'report sinks' => [
            ['--report=json:j.json', '--report=junit:u.xml', '--report=html:h.html'],
            ['json_output' => 'j.json', 'junit_output' => 'u.xml', 'html_output' => 'h.html'],
        ];
        yield 'console report' => [['--console-report=all'], ['console_output' => 'all']];
        yield 'thresholds' => [
            ['--min-coverage=endpoint=80,response=70,sdk_exercise=50,strict'],
            ['min_coverage_strict' => true, 'min_endpoint_coverage' => 80.0, 'min_response_coverage' => 70.0, 'min_sdk_exercise_coverage' => 50.0],
        ];
        yield 'strict false' => [['--min-coverage=strict=false'], ['min_coverage_strict' => false]];
        yield 'baselines' => [['--baseline=violations=v.json,coverage=c.json'], ['baseline_file' => 'v.json', 'coverage_baseline_file' => 'c.json']];
        yield 'baseline staleness' => [['--baseline-stale=coverage=fail'], ['coverage_baseline_stale' => 'fail']];
        yield 'paths with commas' => [
            ['--report=json:build/a,b.json', '--baseline=coverage=c,d.json'],
            ['json_output' => 'build/a,b.json', 'coverage_baseline_file' => 'c,d.json'],
        ];
        yield 'grouped members across occurrences' => [
            ['--baseline=violations=v,1.json', '--baseline=coverage=c.json'],
            ['baseline_file' => 'v,1.json', 'coverage_baseline_file' => 'c.json'],
        ];
        yield 'strict modes' => [
            ['--strict-required=run=warn,per_call=off', '--strict-additional-properties=run=fail'],
            ['strict_required' => 'warn', 'strict_additional_properties' => 'fail'],
        ];
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function provideA_removed_v2_spelling_is_a_usage_error_naming_its_replacementCases(): iterable
    {
        yield 'specs' => [['--specs=a'], '--specs (removed in Gesso 3.0; use --spec-name)'];
        yield 'strip prefixes' => [['--strip-prefixes=/api'], '--strip-prefixes (removed in Gesso 3.0; use --strip-prefix)'];
        yield 'json output' => [['--json-output=a.json'], '--json-output (removed in Gesso 3.0; use --report=json:<path>)'];
        yield 'junit output' => [['--junit-output=a.xml'], '--junit-output (removed in Gesso 3.0; use --report=junit:<path>)'];
        yield 'html output' => [['--html-output=a.html'], '--html-output (removed in Gesso 3.0; use --report=html:<path>)'];
        yield 'console output' => [['--console-output=all'], '--console-output (removed in Gesso 3.0; use --console-report)'];
        yield 'min endpoint' => [['--min-endpoint-coverage=80'], '--min-endpoint-coverage (removed in Gesso 3.0; use --min-coverage="endpoint=…")'];
        yield 'min response' => [['--min-response-coverage=80'], '--min-response-coverage (removed in Gesso 3.0; use --min-coverage="response=…")'];
        yield 'min sdk' => [['--min-sdk-exercise-coverage=80'], '--min-sdk-exercise-coverage (removed in Gesso 3.0; use --min-coverage="sdk_exercise=…")'];
        yield 'min strict' => [['--min-coverage-strict'], '--min-coverage-strict (removed in Gesso 3.0; use --min-coverage="strict")'];
        yield 'baseline file' => [['--baseline-file=v.json'], '--baseline-file (removed in Gesso 3.0; use --baseline="violations=…")'];
        yield 'coverage baseline file' => [['--coverage-baseline-file=c.json'], '--coverage-baseline-file (removed in Gesso 3.0; use --baseline="coverage=…")'];
        yield 'coverage baseline stale' => [['--coverage-baseline-stale=fail'], '--coverage-baseline-stale (removed in Gesso 3.0; use --baseline-stale="coverage=…")'];
        yield 'bare strict required' => [['--strict-required=fail'], '--strict-required=fail (removed in Gesso 3.0; use --strict-required="run=fail")'];
        yield 'bare strict additional properties' => [['--strict-additional-properties=warn'], '--strict-additional-properties=warn (removed in Gesso 3.0; use --strict-additional-properties="run=warn")'];
        yield 'bare mode beside the v3 one' => [['--strict-additional-properties=run=off', '--strict-additional-properties=fail'], '--strict-additional-properties=fail (removed in Gesso 3.0;'];
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function provideA_conflicting_or_malformed_spelling_is_a_usage_errorCases(): iterable
    {
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
        yield 'bad per_call' => [['--strict-required=run=warn,per_call=fail'], 'per_call=fail is not one of off, warn'];
    }

    /**
     * @param list<string> $argv
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('provideThe_v3_spelling_parses_to_the_merge_optionCases')]
    public function the_v3_spelling_parses_to_the_merge_option(array $argv, array $expected): void
    {
        $options = CoverageMergeCommand::parseArgv($argv);

        $this->assertArrayNotHasKey('usage_error', $options);
        $this->assertSame([], $options['invalid_options'] ?? null);
        foreach ($expected as $key => $value) {
            $this->assertSame($value, $options[$key] ?? null, $key);
        }
    }

    /** @param list<string> $argv */
    #[Test]
    #[DataProvider('provideA_removed_v2_spelling_is_a_usage_error_naming_its_replacementCases')]
    public function a_removed_v2_spelling_is_a_usage_error_naming_its_replacement(array $argv, string $message): void
    {
        [$exit, $stderr] = $this->merge([...$argv, '--spec-base-path=' . __DIR__ . '/../../fixtures/specs']);

        $this->assertSame(CoverageMergeCommand::EXIT_USAGE, $exit);
        $this->assertStringContainsString($message, $stderr);
        $this->assertSame([], Deprecations::counts());
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
    public function an_unknown_argument_is_a_usage_error(): void
    {
        [$exit, $stderr] = $this->merge(['--spec-base-path=' . __DIR__ . '/../../fixtures/specs', '--min-endpont-coverage=90', 'stray']);

        $this->assertSame(CoverageMergeCommand::EXIT_USAGE, $exit);
        $this->assertStringContainsString('[Gesso] Unknown argument(s): --min-endpont-coverage, stray', $stderr);
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
