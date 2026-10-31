<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Config;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Cli\StubsCommand;
use Studio\Gesso\Config\ConfigMigrator;
use Studio\Gesso\Config\ConfigurationBridge;
use Studio\Gesso\Config\GessoConfig;
use Studio\Gesso\Coverage\CoverageMergeCommand;
use Studio\Gesso\Internal\LegacyIdentity;
use Studio\Gesso\Laravel\LaravelConfig;
use Studio\Gesso\PHPUnit\ExtensionParameters;

use function array_column;
use function array_keys;
use function array_map;
use function count;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function htmlspecialchars;
use function is_dir;
use function json_decode;
use function ksort;
use function mkdir;
use function rmdir;
use function scandir;
use function str_replace;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function var_export;

final class ConfigMigratorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/gesso-config-migrate-' . uniqid('', true);
        mkdir($this->dir . '/config', 0o777, true);
    }

    protected function tearDown(): void
    {
        self::remove($this->dir);
    }

    /**
     * The migrator reads the tables the runtime rejects with; together they
     * must name every v3 removal the ledger lists, with the same replacement.
     */
    #[Test]
    public function the_runtime_tables_cover_every_removed_v3_spelling(): void
    {
        $ledger = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/compatibility/v3-renames.json'), true, 512, JSON_THROW_ON_ERROR);

        $expected = [];
        foreach ($ledger['renames'] as $old => $entry) {
            if ($entry['channel'] !== 'unchanged-spelling') {
                $expected[$entry['surface'] . ' ' . $old] = $entry['replacement'];
            }
        }

        $actual = [];
        foreach (ConfigurationBridge::PHPUNIT as $name => [$key]) {
            $actual['config-key ' . $name] = $key;
        }
        foreach (LaravelConfig::REMOVED as $name => $key) {
            $actual['config-key ' . $name] = $key;
        }
        foreach (ExtensionParameters::REMOVED_WITHOUT_SUCCESSOR as $name => $_) {
            $actual['config-key ' . $name] = 'none';
        }
        foreach ([...CoverageMergeCommand::REMOVED_FLAGS, ...CoverageMergeCommand::REMOVED_BARE_MODES, ...StubsCommand::REMOVED_FLAGS] as $name => $flag) {
            $actual['cli-flag --' . str_replace('_', '-', $name)] = $flag;
        }
        foreach (LegacyIdentity::ENV_NAMES as $old => $new) {
            $actual['env-var ' . $old] = $new;
        }
        foreach (LegacyIdentity::COMMAND_NAMES as $old => $new) {
            $actual['artisan-command ' . $old] = $new;
        }

        $normalise = static fn(array $map): array => array_map(ConfigMigrator::normaliseKey(...), $map);
        $expected = $normalise($expected);
        $actual = $normalise($actual);
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
    }

    /**
     * Every v2 phpunit.xml parameter becomes a gesso.php key the runtime
     * accepts and reads back as the same setting.
     */
    #[Test]
    public function every_phpunit_parameter_migrates_to_a_valid_gesso_php(): void
    {
        $samples = [
            'string' => 'value',
            'strings' => 'a, b',
            'int' => '7',
            'number' => '80.5',
            'bool' => 'on',
        ];
        $specific = [
            'validation_output' => 'json',
            'strict_required' => 'warn',
            'strict_required_per_call' => 'warn',
            'strict_additional_properties' => 'fail',
            'strict_additional_properties_per_call' => 'warn',
            'console_output' => 'all',
            'baseline_stale' => 'fail',
            'coverage_baseline_stale' => 'off',
            'skip_response_codes' => '5\d\d,429',
            'skip_request_validation_response_codes' => '422',
            'enforce_discriminator' => 'off',
            'enum_drift_fail_on_drift' => 'no',
        ];
        $parameters = '';
        foreach (ConfigurationBridge::PHPUNIT as $name => [, $type]) {
            $value = $specific[$name] ?? $samples[$type];
            $parameters .= '<parameter name="' . $name . '" value="' . htmlspecialchars($value) . "\"/>\n";
        }
        file_put_contents($this->dir . '/phpunit.xml', <<<XML
            <?xml version="1.0"?>
            <phpunit><extensions><bootstrap class="Studio\\Gesso\\PHPUnit\\OpenApiCoverageExtension">
            {$parameters}</bootstrap></extensions></phpunit>
            XML);

        $result = ConfigMigrator::scan($this->dir);

        $this->assertNotNull($result['config']);
        $this->assertNotContains('invalid-value', array_column($result['findings'], 'category'));
        $this->assertCount(count(ConfigurationBridge::PHPUNIT), $result['findings']);
        $config = self::evaluate(ConfigMigrator::render($result['config']), $this->dir);
        $settings = ConfigurationBridge::phpunit(GessoConfig::fromArray($config, $this->dir));
        $this->assertSame(array_keys(ConfigurationBridge::PHPUNIT), array_keys($settings));
        $this->assertFalse($settings['enforce_discriminator'], "'off' is false, as the word says");
        $this->assertSame(['a', 'b'], $settings['specs']);
        $this->assertSame(['5\d\d', '429'], $settings['skip_response_codes']);
        $this->assertSame(7, $settings['max_errors']);
        $this->assertSame(80.5, $settings['min_endpoint_coverage']);
        $this->assertSame('fail', $settings['baseline_stale']);
    }

    #[Test]
    public function every_laravel_flat_key_migrates_and_defaults_are_dropped(): void
    {
        file_put_contents($this->dir . '/config/gesso.php', '<?php return ' . var_export([
            'spec_base_path' => 'openapi',
            'default_spec' => 'front',
            'specs' => ['front'],
            'strip_prefixes' => ['/api'],
            'max_errors' => 20,
            'enforce_discriminator' => false,
            'acknowledged_unvalidatable_schemes' => ['oauth'],
            'skip_response_codes' => ['5\d\d'],
            'skip_request_validation_response_codes' => ['400'],
            'auto_assert' => true,
            'auto_validate_request' => true,
            'auto_inject_dummy_credentials' => true,
            'auto_inject_dummy_bearer' => true,
            'route_parity' => ['excluded_route_names' => ['horizon.*'], 'external_openapi_paths' => []],
        ], true) . ';');

        $result = ConfigMigrator::scan($this->dir);

        $this->assertCount(count(LaravelConfig::REMOVED) + 1, $result['findings']);
        $this->assertSame([
            'spec' => ['base_path' => 'openapi', 'default' => 'front', 'names' => ['front'], 'strip_prefixes' => ['/api']],
            'validation' => [
                'enforce_discriminator' => false,
                'acknowledged_unvalidatable_schemes' => ['oauth'],
                'skip_request_validation_response_codes' => ['400'],
            ],
            'laravel' => [
                'auto_assert' => true,
                'auto_validate_request' => true,
                // `true` already covered Bearer in v2, so it wins over the narrower flag.
                'auto_inject_dummy_credentials' => true,
                'route_parity' => ['excluded_route_names' => ['horizon.*']],
            ],
        ], $result['config']);
    }

    #[Test]
    public function a_legacy_value_overrides_an_existing_gesso_php_with_a_warning(): void
    {
        file_put_contents($this->dir . '/gesso.php', "<?php return ['spec' => ['default' => 'admin', 'base_path' => 'specs']];");
        file_put_contents($this->dir . '/phpunit.xml', '<phpunit><extensions><bootstrap class="Studio\Gesso\PHPUnit\OpenApiCoverageExtension">'
            . '<parameter name="default_spec" value="front"/></bootstrap></extensions></phpunit>');

        $result = ConfigMigrator::scan($this->dir);

        $this->assertTrue($result['existing']);
        $this->assertSame(['spec' => ['base_path' => 'specs', 'default' => 'front']], $result['config']);
        $this->assertStringContainsString('spec.default: phpunit.xml overrides the value from gesso.php', $result['warnings'][0]);
    }

    #[Test]
    public function an_existing_gesso_php_alone_is_nothing_to_migrate(): void
    {
        file_put_contents($this->dir . '/gesso.php', "<?php return ['spec' => ['default' => 'front']];");

        $result = ConfigMigrator::scan($this->dir);

        $this->assertNull($result['config']);
        $this->assertSame([], $result['findings']);
    }

    #[Test]
    public function an_invalid_legacy_value_is_a_finding(): void
    {
        file_put_contents($this->dir . '/phpunit.xml', '<phpunit><extensions><bootstrap class="Studio\Gesso\PHPUnit\OpenApiCoverageExtension">'
            . '<parameter name="strict_required" value="loud"/></bootstrap></extensions></phpunit>');

        $result = ConfigMigrator::scan($this->dir);

        $this->assertContains('invalid-value', array_column($result['findings'], 'category'));
    }

    #[Test]
    public function the_test_runner_check_prefers_the_lock_file(): void
    {
        file_put_contents($this->dir . '/composer.json', '{"require-dev": {"phpunit/phpunit": "^11.5 || ^12.0", "pestphp/pest": "^3.0 || ^4.0"}}');
        file_put_contents($this->dir . '/composer.lock', '{"packages": [], "packages-dev": [{"name": "phpunit/phpunit", "version": "11.5.2"}, {"name": "pestphp/pest", "version": "v4.1.0"}]}');

        $result = ConfigMigrator::scan($this->dir);

        $this->assertSame(['test-runner'], array_column($result['findings'], 'category'));
        $this->assertSame(ConfigMigrator::PHPUNIT_UPGRADE, $result['findings'][0]['action']);

        unlink($this->dir . '/composer.lock');
        $this->assertSame([], ConfigMigrator::scan($this->dir)['findings'], 'a constraint that allows a supported major passes');
    }

    #[Test]
    public function render_is_a_loadable_gesso_php(): void
    {
        $source = ConfigMigrator::render(['spec' => ['names' => ['a', "b'c"]], 'validation' => ['max_errors' => 0]]);

        $this->assertStringStartsWith("<?php\n\ndeclare(strict_types=1);\n", $source);
        $this->assertSame(['spec' => ['names' => ['a', "b'c"]], 'validation' => ['max_errors' => 0]], self::evaluate($source, $this->dir));
    }

    /** @return array<string, mixed> */
    private static function evaluate(string $source, string $dir): array
    {
        $path = $dir . '/rendered-' . uniqid() . '.php';
        file_put_contents($path, $source);

        /** @var array<string, mixed> */
        return require $path;
    }

    private static function remove(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
