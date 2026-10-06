<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Integration;

use const PHP_BINARY;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function mkdir;
use function str_replace;
use function sys_get_temp_dir;
use function uniqid;
use function var_export;

final class SharedConfigurationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/gesso-shared-' . uniqid();
        mkdir($this->directory . '/subdir', 0o755, true);
        mkdir($this->directory . '/specs');
        file_put_contents($this->directory . '/specs/api.json', '{"openapi":"3.1.0","info":{"title":"test","version":"1"},"paths":{}}');
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        file_put_contents($this->directory . '/phpunit.xml', <<<XML
            <phpunit bootstrap="{$autoload}" defaultTestSuite="Contract">
                <testsuites><testsuite name="Contract"><file>ContractTest.php</file></testsuite></testsuites>
                <extensions><bootstrap class="Studio\Gesso\PHPUnit\OpenApiCoverageExtension">
                    <parameter name="config" value="gesso.php"/>
                </bootstrap></extensions>
            </phpunit>
            XML);
        file_put_contents($this->directory . '/ContractTest.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace Studio\Gesso\Tests\SharedFixture;
            use Illuminate\Config\Repository;
            use Illuminate\Foundation\Application;
            use PHPUnit\Framework\TestCase;
            use Studio\Gesso\Laravel\GessoServiceProvider;
            use Studio\Gesso\Spec\OpenApiSpecLoader;
            use Studio\Gesso\Validation\Support\DiscriminatorEnforcement;
            use Studio\Gesso\Validation\Support\ValidationPolicyDefaults;
            use Studio\Gesso\ValidationOutput;
            use Studio\Gesso\ValidationOutputFormat;
            final class ContractTest extends TestCase {
                public function test_shared_settings(): void {
                    $app = new Application(__DIR__);
                    $config = new Repository();
                    $app->instance('config', $config);
                    (new GessoServiceProvider($app))->register();
                    self::assertSame(__DIR__ . '/specs', OpenApiSpecLoader::getBasePath());
                    self::assertSame(['/api,v2'], OpenApiSpecLoader::getStripPrefixes());
                    self::assertSame('/api,v2', $config->get('gesso.strip_prefixes')[0]);
                    self::assertSame(__DIR__ . '/specs', $config->get('gesso.spec_base_path'));
                    self::assertSame('api', $config->get('gesso.default_spec'));
                    self::assertSame('api', ValidationPolicyDefaults::defaultSpec());
                    self::assertSame(7, ValidationPolicyDefaults::maxErrors());
                    self::assertSame(7, $config->get('gesso.max_errors'));
                    self::assertSame(['5[0-9]{2,2}'], ValidationPolicyDefaults::skipResponseCodes());
                    self::assertSame(['5[0-9]{2,2}'], $config->get('gesso.skip_response_codes'));
                    self::assertSame([], ValidationPolicyDefaults::skipRequestValidationResponseCodes());
                    self::assertSame([], $config->get('gesso.skip_request_validation_response_codes'));
                    self::assertFalse(DiscriminatorEnforcement::isEnabled());
                    self::assertFalse($config->get('gesso.enforce_discriminator'));
                    self::assertSame(ValidationOutputFormat::Json, ValidationOutput::format());
                }
            }
            PHP);
        $this->writeConfig();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->directory);
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideMalformed_configuration_is_fatal_even_without_fail_on_warningCases(): iterable
    {
        yield 'unknown key' => ["<?php return ['validation' => ['max_error' => 2]];", 'validation.max_error'];
        yield 'invalid boolean' => ["<?php return ['validation' => ['enforce_discriminator' => 'nope']];", 'validation.enforce_discriminator'];
        yield 'wrong return' => ['<?php return false;', 'array'];
        yield 'syntax error' => ['<?php return [;', 'could not be evaluated'];
        yield 'exception' => ["<?php throw new RuntimeException('config failed');", 'config failed'];
    }

    /** @return iterable<string, array{array<string, mixed>, null|string, list<string>}> */
    public static function providePrefixes_without_shared_base_path_preserve_bootstrap_loader_settingsCases(): iterable
    {
        yield 'shared prefixes' => [['strip_prefixes' => ['/api']], null, ['/api']];
        yield 'null base path' => [['base_path' => null, 'strip_prefixes' => ['/api']], null, ['/api']];
        yield 'comma stays in prefix' => [['strip_prefixes' => ['/api,v2']], null, ['/api,v2']];
        yield 'explicit empty list clears bootstrap prefixes' => [['strip_prefixes' => []], null, []];
        yield 'omitted prefixes preserve bootstrap prefixes' => [[], null, ['/bootstrap']];
        yield 'XML overrides shared prefixes' => [['strip_prefixes' => ['/api']], '/legacy', ['/legacy']];
    }

    /**
     * @param array<string, mixed> $specSettings
     * @param list<string> $expectedPrefixes
     */
    #[Test]
    #[DataProvider('providePrefixes_without_shared_base_path_preserve_bootstrap_loader_settingsCases')]
    public function prefixes_without_shared_base_path_preserve_bootstrap_loader_settings(
        array $specSettings,
        ?string $xmlPrefixes,
        array $expectedPrefixes,
    ): void {
        file_put_contents($this->directory . '/gesso.php', '<?php return ' . var_export([
            'spec' => $specSettings + ['names' => ['api']],
        ], true) . ';');
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        file_put_contents($this->directory . '/bootstrap.php', '<?php require ' . var_export($autoload, true) . ';' . <<<'PHP'
            $client = new \Studio\Gesso\Tests\Helpers\FakeHttpClient([
                'https://specs.example.com/api.json' => static fn () =>
                    \Studio\Gesso\Tests\Helpers\FakeHttpClient::jsonResponse(
                        '{"openapi":"3.1.0","info":{"title":"remote","version":"1"},"paths":{"/v1/pets":{"get":{"responses":{"200":{"description":"OK"}}}}}}',
                    ),
            ]);
            \Studio\Gesso\Spec\OpenApiSpecLoader::configure(
                __DIR__ . '/specs',
                ['/bootstrap'],
                httpClient: $client,
                requestFactory: new \GuzzleHttp\Psr7\HttpFactory(),
                allowRemoteRefs: true,
                enumBasePath: __DIR__ . '/enums',
                allowedRemoteRefHosts: ['specs.example.com'],
                maxRemoteRefBytes: 2048,
                remoteSpecs: ['api' => 'https://specs.example.com/api.json'],
            );
            \Studio\Gesso\Spec\OpenApiSpecLoader::load('api');
            $GLOBALS['sharedConfigHttpClient'] = $client;
            PHP);
        $this->replace('phpunit.xml', $autoload, $this->directory . '/bootstrap.php');
        if ($xmlPrefixes !== null) {
            $this->replace('phpunit.xml', '</bootstrap>', '<parameter name="strip_prefixes" value="' . $xmlPrefixes . '"/></bootstrap>');
        }
        file_put_contents($this->directory . '/ContractTest.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace Studio\Gesso\Tests\SharedFixture;
            use Illuminate\Config\Repository;
            use Illuminate\Foundation\Application;
            use PHPUnit\Framework\TestCase;
            use Studio\Gesso\Laravel\GessoServiceProvider;
            use Studio\Gesso\OpenApiRequestValidator;
            use Studio\Gesso\Spec\OpenApiSpecLoader;
            final class ContractTest extends TestCase {
                public function test_bootstrap_settings(): void {
                    $expected = EXPECTED_PREFIXES;
                    self::assertSame($expected, OpenApiSpecLoader::getStripPrefixes());
                    self::assertSame(__DIR__ . '/specs', OpenApiSpecLoader::getBasePath());
                    self::assertSame(__DIR__ . '/enums', OpenApiSpecLoader::getEnumBasePath());
                    self::assertCount(1, $GLOBALS['sharedConfigHttpClient']->sentUrls());
                    OpenApiSpecLoader::clearCache();
                    self::assertSame('remote', OpenApiSpecLoader::load('api')['info']['title']);
                    self::assertCount(2, $GLOBALS['sharedConfigHttpClient']->sentUrls());
                    $result = (new OpenApiRequestValidator())->validate(
                        'api', 'GET', ($expected[0] ?? '') . '/v1/pets', [], [], null,
                    );
                    self::assertTrue($result->isValid());
                    self::assertSame('/v1/pets', $result->matchedPath());
                    $app = new Application(__DIR__);
                    $config = new Repository();
                    $app->instance('config', $config);
                    (new GessoServiceProvider($app))->register();
                    self::assertSame(SHARED_PREFIXES, $config->get('gesso.strip_prefixes'));
                }
            }
            PHP);
        $this->replace('ContractTest.php', 'EXPECTED_PREFIXES', var_export($expectedPrefixes, true));
        $this->replace('ContractTest.php', 'SHARED_PREFIXES', var_export($specSettings['strip_prefixes'] ?? [], true));

        $process = $this->runPhpunit($this->directory);
        $this->assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    }

    #[Test]
    public function both_adapters_share_typed_settings_and_paths_from_another_working_directory(): void
    {
        foreach ([$this->directory, $this->directory . '/subdir'] as $cwd) {
            $process = $this->runPhpunit($cwd);
            $this->assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
            $this->assertFileExists($this->directory . '/build/coverage.json');
        }
    }

    #[Test]
    public function empty_string_boolean_is_false_in_both_adapters(): void
    {
        $this->replace('gesso.php', "'off'", "''");
        $process = $this->runPhpunit($this->directory);
        $this->assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    }

    #[Test]
    public function legacy_laravel_values_win_and_arrays_are_replaced_whole(): void
    {
        $this->replace('ContractTest.php', '$config = new Repository();', <<<'PHP'
            $config = new Repository(['gesso' => [
                'max_errors' => 3,
                'skip_response_codes' => [],
                'route_parity' => ['external_operation_ids' => ['local']],
            ]]);
            PHP);
        $this->replace('ContractTest.php', "assertSame(7, \$config->get('gesso.max_errors'))", "assertSame(3, \$config->get('gesso.max_errors'))");
        $this->replace('ContractTest.php', "assertSame(['5[0-9]{2,2}'], \$config->get('gesso.skip_response_codes'))", "assertSame([], \$config->get('gesso.skip_response_codes'))");
        $this->replace('gesso.php', "'spec' =>", "'laravel' => ['auto_inject_dummy_credentials' => 'bearer', 'route_parity' => ['external_operation_ids' => ['root1', 'root2'], 'external_openapi_paths' => ['/external']]], 'spec' =>");
        $this->replace('ContractTest.php', '(new GessoServiceProvider($app))->register();', <<<'PHP'
            (new GessoServiceProvider($app))->register();
            self::assertSame('bearer', $config->get('gesso.auto_inject_dummy_credentials'));
            self::assertSame(['local'], $config->get('gesso.route_parity.external_operation_ids'));
            self::assertSame(['/external'], $config->get('gesso.route_parity.external_openapi_paths'));
            PHP);
        $process = $this->runPhpunit($this->directory);
        $this->assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    }

    #[Test]
    public function shared_coverage_threshold_fails_a_run_with_an_uncovered_response(): void
    {
        file_put_contents($this->directory . '/specs/api.json', '{"openapi":"3.1.0","info":{"title":"test","version":"1"},"paths":{"/pets":{"get":{"responses":{"200":{"description":"OK"}}}}}}');
        $this->replace('gesso.php', "'report_output' =>", "'min_coverage' => ['response' => 100, 'strict' => true], 'report_output' =>");
        $process = $this->runPhpunit($this->directory);
        $this->assertNotSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        $this->assertStringContainsString('100', $process->getOutput() . $process->getErrorOutput());
        $this->assertFileExists($this->directory . '/build/coverage.json');
    }

    #[Test]
    public function conventional_file_is_discovered_beside_phpunit_xml(): void
    {
        $this->replace('phpunit.xml', '<parameter name="config" value="gesso.php"/>', '');
        $process = $this->runPhpunit($this->directory . '/subdir');
        $this->assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    }

    #[Test]
    public function explicit_v2_parameters_and_environment_keep_priority(): void
    {
        $this->replace('phpunit.xml', '</bootstrap>', '<parameter name="max_errors" value="3"/><parameter name="validation_output" value="text"/></bootstrap>');
        $this->replace('ContractTest.php', 'assertSame(7, ValidationPolicyDefaults::maxErrors())', 'assertSame(3, ValidationPolicyDefaults::maxErrors())');
        $process = $this->runPhpunit($this->directory, ['GESSO_VALIDATION_FORMAT' => 'json']);
        $this->assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    }

    #[Test]
    #[DataProvider('provideMalformed_configuration_is_fatal_even_without_fail_on_warningCases')]
    public function malformed_configuration_is_fatal_even_without_fail_on_warning(string $source, string $diagnostic): void
    {
        file_put_contents($this->directory . '/gesso.php', $source);
        $process = $this->runPhpunit($this->directory);
        $this->assertSame(1, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        $this->assertStringContainsString('FATAL', $process->getErrorOutput());
        $this->assertStringContainsString($diagnostic, $process->getErrorOutput());
    }

    #[Test]
    public function missing_explicit_file_is_fatal(): void
    {
        $this->replace('phpunit.xml', 'value="gesso.php"', 'value="missing.php"');
        $process = $this->runPhpunit($this->directory);
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('missing.php', $process->getErrorOutput());
    }

    private function writeConfig(): void
    {
        file_put_contents($this->directory . '/gesso.php', '<?php return ' . var_export([
            'spec' => ['base_path' => 'specs', 'names' => ['api'], 'default' => 'api', 'strip_prefixes' => ['/api,v2']],
            'validation' => [
                'max_errors' => 7,
                'enforce_discriminator' => 'off',
                'skip_response_codes' => ['5[0-9]{2,2}'],
                'skip_request_validation_response_codes' => [],
                'format' => 'json',
            ],
            'coverage' => ['report_output' => ['json' => 'build/coverage.json']],
            'phpunit' => ['default_testsuite_as_full' => true],
        ], true) . ';');
    }

    /** @param array<string, string> $environment */
    private function runPhpunit(string $cwd, array $environment = []): Process
    {
        $process = new Process([
            PHP_BINARY, dirname(__DIR__, 2) . '/vendor/bin/phpunit',
            '--configuration=' . $this->directory . '/phpunit.xml', '--colors=never',
        ], $cwd, $environment + ['GESSO_VALIDATION_FORMAT' => false, 'GESSO_BASELINE_GENERATE' => false]);
        $process->run();

        return $process;
    }

    private function replace(string $file, string $from, string $to): void
    {
        $path = $this->directory . '/' . $file;
        file_put_contents($path, str_replace($from, $to, file_get_contents($path)));
    }
}
