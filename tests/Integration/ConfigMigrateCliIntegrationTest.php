<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Integration;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Studio\Gesso\Cli\ConfigMigrateCommand;
use Studio\Gesso\Config\ConfigMigrator;

use function array_column;
use function array_unique;
use function array_values;
use function copy;
use function dirname;
use function escapeshellarg;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_resource;
use function json_decode;
use function mkdir;
use function proc_close;
use function proc_open;
use function rmdir;
use function sort;
use function sprintf;
use function stream_get_contents;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class ConfigMigrateCliIntegrationTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/config-migrate';

    /** @var list<string> */
    private array $temporary = [];

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            self::remove($path);
        }
    }

    /** @return iterable<string, array{string, array<string, mixed>, list<string>, string}> */
    public static function provideEvery_fixture_project_migratesCases(): iterable
    {
        yield 'legacy phpunit.xml' => ['legacy-phpunit', [
            'spec' => ['base_path' => 'openapi', 'names' => ['front', 'admin'], 'strip_prefixes' => ['/api']],
            'validation' => ['max_errors' => 5, 'enforce_discriminator' => false, 'skip_response_codes' => ['5\d\d', '429']],
            'strict' => ['required' => ['run' => 'warn']],
            'coverage' => ['min_coverage' => ['endpoint' => 80], 'report_output' => ['json' => 'build/coverage.json']],
        ], ['artisan-command', 'cli-flag', 'env-var', 'phpunit-parameter', 'test-runner'], 'composer require --dev "phpunit/phpunit:^12.5" --with-all-dependencies'];
        yield 'legacy Laravel config/gesso.php' => ['legacy-laravel', [
            'spec' => ['base_path' => 'openapi', 'default' => 'front', 'strip_prefixes' => ['/api']],
            'laravel' => [
                'auto_assert' => true,
                'auto_inject_dummy_credentials' => 'bearer',
                'route_parity' => ['external_operation_ids' => ['healthCheck']],
            ],
        ], ['laravel-config'], 'env() or other functions'];
        yield 'Spectator' => ['spectator', [
            'spec' => ['base_path' => 'specs', 'default' => 'api', 'strip_prefixes' => ['/v1']],
            'validation' => ['format' => 'json'],
            'coverage' => ['min_coverage' => ['endpoint' => 80]],
        ], ['competitor-config', 'competitor-package', 'competitor-usage'], ConfigMigrator::GUIDE_URL . '#spectator'];
        yield 'kirschbaum' => ['kirschbaum', [
            'spec' => ['base_path' => 'openapi', 'default' => 'api'],
            'laravel' => ['auto_assert' => true, 'auto_validate_request' => true],
        ], ['competitor-config', 'competitor-package', 'competitor-usage'], 'replace with use ValidatesOpenApiSchema;'];
        yield 'osteel' => ['osteel', [
            'spec' => ['base_path' => 'openapi', 'default' => 'api'],
        ], ['competitor-package', 'competitor-usage'], 'assertClientMatchesOpenApiSchema($client)'];
        yield 'league' => ['league', [
            'spec' => ['base_path' => 'openapi', 'default' => 'front'],
        ], ['competitor-package', 'competitor-usage'], '->validateResponseForOperation($method, $path, $response)'];
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $categories
     */
    #[Test]
    #[DataProvider('provideEvery_fixture_project_migratesCases')]
    public function every_fixture_project_migrates(string $fixture, array $config, array $categories, string $reportLine): void
    {
        [$exit, $stdout, $stderr] = $this->migrate(self::FIXTURES . '/' . $fixture, []);

        $this->assertSame(0, $exit, $stderr);
        $this->assertSame($config, $this->evaluate($stdout));
        $this->assertStringContainsString($reportLine, $stderr);

        [$checkExit, $checkStdout] = $this->migrate(self::FIXTURES . '/' . $fixture, ['--check']);
        $this->assertSame(1, $checkExit);
        $this->assertSame('', $checkStdout, '--check prints only the report');

        [$jsonExit, $json] = $this->migrate(self::FIXTURES . '/' . $fixture, ['--format=json']);
        $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $jsonExit);
        $this->assertSame(ConfigMigrateCommand::JSON_SCHEMA_VERSION, $document['schemaVersion']);
        $this->assertFalse($document['clean']);
        $this->assertFalse($document['written']);
        $this->assertSame($stdout, $document['config']);
        $found = array_values(array_unique(array_column($document['findings'], 'category')));
        sort($found);
        $this->assertSame($categories, $found);
    }

    #[Test]
    public function the_report_names_each_legacy_spelling_and_ignores_other_tools(): void
    {
        [, , $stderr] = $this->migrate(self::FIXTURES . '/legacy-phpunit', []);

        $this->assertStringStartsWith("[Gesso] config:migrate: 19 item(s) to migrate.\n\nStep 0: upgrade the test runner", $stderr);
        $this->assertStringContainsString("  8: coverage:merge --specs was removed in Gesso 3.0.\n    -> use --spec-name\n", $stderr);
        $this->assertStringContainsString("  9: stubs --output was removed in Gesso 3.0.\n    -> use --output-dir\n", $stderr);
        $this->assertStringContainsString("  8: coverage:merge --strip-prefixes was removed in Gesso 3.0.\n", $stderr);
        $this->assertStringContainsString('  7: openapi:routes is the v2 spelling', $stderr);
        $this->assertStringContainsString('  2: OPENAPI_CONSOLE_OUTPUT is the v2 spelling', $stderr);
        $this->assertStringContainsString('<parameter name="enum_spec_base_path"> was removed', $stderr);

        $document = json_decode($this->migrate(self::FIXTURES . '/legacy-phpunit', ['--format=json'])[1], true, 512, JSON_THROW_ON_ERROR);
        $lines = [];
        foreach ($document['findings'] as $finding) {
            if ($finding['file'] === 'composer.json' && $finding['category'] === 'cli-flag') {
                $lines[] = $finding['line'];
            }
        }
        $this->assertSame([8, 8, 8, 9], $lines, 'the phpunit script on line 10 is not a Gesso command');
    }

    #[Test]
    public function a_clean_project_passes_the_check(): void
    {
        [$exit, $stdout, $stderr] = $this->migrate(self::FIXTURES . '/clean', ['--check']);

        $this->assertSame(0, $exit);
        $this->assertSame('', $stdout);
        $this->assertSame("[Gesso] config:migrate: nothing left to migrate.\n", $stderr);

        [$jsonExit, $json] = $this->migrate(self::FIXTURES . '/clean', ['--check', '--format=json']);
        $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $jsonExit);
        $this->assertTrue($document['clean']);
        $this->assertNull($document['config']);
        $this->assertSame([], $document['findings']);
    }

    #[Test]
    public function write_refuses_to_replace_a_gesso_php_without_force(): void
    {
        $project = $this->copyFixture('legacy-phpunit');

        [$exit, $stdout, $stderr] = $this->migrate($project, ['--write']);
        $this->assertSame(0, $exit, $stderr);
        $this->assertSame('', $stdout);
        $this->assertStringEndsWith("[Gesso] Wrote gesso.php.\n", $stderr);
        $written = (string) file_get_contents($project . '/gesso.php');

        file_put_contents($project . '/gesso.php', "<?php return ['spec' => ['base_path' => 'specs']];");
        [$refused, , $refusal] = $this->migrate($project, ['--write']);
        $this->assertSame(2, $refused);
        $this->assertStringContainsString('--write --force', $refusal);

        [$forced] = $this->migrate($project, ['--write', '--force']);
        $this->assertSame(0, $forced);
        // The phpunit.xml value wins over the existing file, as it did in v2.9.
        $this->assertSame($written, file_get_contents($project . '/gesso.php'));
    }

    #[Test]
    public function an_empty_gesso_php_is_replaced_without_force(): void
    {
        $project = $this->copyFixture('legacy-laravel');
        file_put_contents($project . '/gesso.php', '');

        [$exit] = $this->migrate($project, ['--write']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString("'auto_assert' => true", (string) file_get_contents($project . '/gesso.php'));
    }

    #[Test]
    public function unknown_options_and_formats_are_usage_errors(): void
    {
        [$exit, $stdout, $stderr] = $this->migrate(self::FIXTURES . '/clean', ['--phpunit=phpunit.xml']);
        $this->assertSame(2, $exit);
        $this->assertSame('', $stdout);
        $this->assertStringStartsWith("[Gesso] Unknown argument(s): --phpunit\n\n" . ConfigMigrateCommand::usage(), $stderr);

        [$formatExit, , $formatError] = $this->migrate(self::FIXTURES . '/clean', ['--format=xml']);
        $this->assertSame(2, $formatExit);
        $this->assertStringStartsWith('[Gesso] Unsupported --format=xml.', $formatError);

        [$helpExit, $help] = $this->migrate(self::FIXTURES . '/clean', ['--help']);
        $this->assertSame(0, $helpExit);
        $this->assertSame(ConfigMigrateCommand::usage(), $help);
    }

    private static function remove(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($path);
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private function migrate(string $cwd, array $args): array
    {
        $command = sprintf('php %s config:migrate', escapeshellarg(dirname(__DIR__, 2) . '/bin/gesso'));
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        if (!is_resource($process)) {
            $this->fail('failed to spawn Gesso CLI');
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    /** @return array<string, mixed> */
    private function evaluate(string $source): array
    {
        $path = sys_get_temp_dir() . '/gesso-migrated-' . uniqid('', true) . '.php';
        $this->temporary[] = $path;
        file_put_contents($path, $source);

        /** @var array<string, mixed> */
        return require $path;
    }

    private function copyFixture(string $fixture): string
    {
        $source = self::FIXTURES . '/' . $fixture;
        $target = sys_get_temp_dir() . '/gesso-config-migrate-' . uniqid('', true);
        $this->temporary[] = $target;
        mkdir($target);
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            $destination = $target . substr($file->getPathname(), strlen($source));
            $file->isDir() ? mkdir($destination) : copy($file->getPathname(), $destination);
        }

        return $target;
    }
}
