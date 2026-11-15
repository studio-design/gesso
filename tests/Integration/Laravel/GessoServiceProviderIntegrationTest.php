<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Integration\Laravel;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

use Illuminate\Support\ServiceProvider;
use LogicException;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Studio\Gesso\Config\ConfigurationBridge;
use Studio\Gesso\Internal\Deprecations;
use Studio\Gesso\Laravel\GessoServiceProvider;
use Studio\Gesso\Laravel\LaravelConfig;

use function array_keys;
use function config;
use function config_path;
use function dirname;
use function file_get_contents;
use function json_encode;

final class GessoServiceProviderIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Deprecations::resetForTesting();
    }

    protected function tearDown(): void
    {
        Deprecations::resetForTesting();
        parent::tearDown();
    }

    #[Test]
    public function provider_merges_v2_defaults_under_the_gesso_key(): void
    {
        /** @var array<string, mixed> $configuration */
        $configuration = require dirname(__DIR__, 3) . '/src/Laravel/config.php';
        $expected = file_get_contents(
            dirname(__DIR__, 2) . '/fixtures/compatibility/v2-laravel-config.json',
        );

        $this->assertIsString($expected);
        $this->assertSame(
            $expected,
            json_encode(
                $configuration,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ) . "\n",
        );

        $this->provider()->register();

        $this->assertSame($configuration, config('gesso'));
        $this->assertNull(config('openapi-contract-testing'));
    }

    #[Test]
    public function provider_preserves_application_overrides_under_the_gesso_key(): void
    {
        config()->set('gesso', [
            'default_spec' => 'application-spec',
            'max_errors' => 7,
        ]);

        $this->provider()->register();

        $this->assertSame('application-spec', config('gesso.default_spec'));
        $this->assertSame(7, config('gesso.max_errors'));
        $this->assertSame('openapi', config('gesso.spec_base_path'));
    }

    #[Test]
    public function provider_deprecates_each_flat_key_the_application_sets(): void
    {
        config()->set('gesso', [
            'max_errors' => 7,
            'auto_assert' => true,
            'route_parity' => ['excluded_route_names' => ['horizon.*']],
        ]);

        $this->provider()->register();

        $this->assertSame([
            'config.max_errors' => 1,
            'laravel.config.auto_assert' => 1,
            'laravel.config.route_parity' => 1,
        ], Deprecations::counts());
        // The flat value keeps working.
        $this->assertSame(7, config('gesso.max_errors'));
    }

    #[Test]
    public function provider_defaults_alone_raise_no_deprecation(): void
    {
        $this->provider()->register();

        $this->assertSame([], Deprecations::counts());
    }

    #[Test]
    public function a_gesso_php_key_path_set_after_register_wins_without_a_notice(): void
    {
        $this->provider()->register();
        config()->set('gesso.max_errors', 7);
        config()->set('gesso.validation.max_errors', 3);

        $this->assertSame(3, LaravelConfig::get('max_errors'));
        $this->assertSame('gesso.validation.max_errors', LaravelConfig::key('max_errors'));
        $this->assertSame([], Deprecations::counts());
    }

    #[Test]
    public function a_flat_key_set_after_register_keeps_working_and_is_deprecated(): void
    {
        $this->provider()->register();
        config()->set('gesso.skip_response_codes', ['4[0-9]{2}']);
        config()->set('gesso.route_parity.excluded_route_names', ['horizon.*']);

        $this->assertSame(['4[0-9]{2}'], LaravelConfig::get('skip_response_codes'));
        $this->assertSame(['horizon.*'], LaravelConfig::get('route_parity.excluded_route_names'));
        $this->assertSame('gesso.skip_response_codes', LaravelConfig::key('skip_response_codes'));
        $this->assertSame([
            'config.skip_response_codes' => 1,
            'laravel.config.route_parity' => 1,
        ], Deprecations::counts());
    }

    #[Test]
    public function reading_the_values_the_provider_resolved_raises_no_notice(): void
    {
        $this->provider()->register();
        // Re-setting a resolved value is not an override.
        config()->set('gesso.max_errors', 20);

        foreach (array_keys(ConfigurationBridge::LARAVEL) as $flat) {
            LaravelConfig::get($flat);
        }

        $this->assertSame([], Deprecations::counts());
    }

    #[Test]
    public function provider_publishes_only_the_gesso_config_destination_and_tag(): void
    {
        $source = dirname(__DIR__, 3) . '/src/Laravel/config.php';

        $this->provider()->boot();

        $this->assertSame(
            [$source => config_path('gesso.php')],
            ServiceProvider::pathsToPublish(GessoServiceProvider::class, 'gesso'),
        );
        $this->assertSame(
            [],
            ServiceProvider::pathsToPublish(
                GessoServiceProvider::class,
                'openapi-contract-testing',
            ),
        );
    }

    #[Test]
    public function provider_rejects_the_legacy_configuration_key(): void
    {
        config()->set('openapi-contract-testing', ['default_spec' => 'legacy']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('rename config/openapi-contract-testing.php to config/gesso.php');

        $this->provider()->register();
    }

    #[Test]
    public function provider_rejects_dual_configuration_keys_without_choosing_precedence(): void
    {
        config()->set('openapi-contract-testing', ['default_spec' => 'legacy']);
        config()->set('gesso', ['default_spec' => 'canonical']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('legacy Laravel configuration key "openapi-contract-testing"');

        $this->provider()->register();
    }

    private function provider(): GessoServiceProvider
    {
        return new GessoServiceProvider($this->app);
    }
}
