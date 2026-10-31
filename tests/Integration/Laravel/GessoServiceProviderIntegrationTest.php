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
use Studio\Gesso\Config\GessoConfig;
use Studio\Gesso\Laravel\GessoServiceProvider;
use Studio\Gesso\Laravel\LaravelConfig;

use function base_path;
use function config;
use function dirname;
use function file_get_contents;
use function json_encode;

final class GessoServiceProviderIntegrationTest extends TestCase
{
    #[Test]
    public function provider_merges_gesso_php_defaults_under_the_gesso_key(): void
    {
        $this->provider()->register();

        // The `gesso` namespace has the shape of gesso.php itself, every key
        // at its documented default when the application has no root file.
        $this->assertSame(
            file_get_contents(dirname(__DIR__, 2) . '/fixtures/compatibility/v2-laravel-config.json'),
            json_encode(
                config('gesso'),
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ) . "\n",
        );
        $this->assertNull(config('openapi-contract-testing'));
    }

    #[Test]
    public function provider_preserves_application_overrides_under_the_gesso_key(): void
    {
        config()->set('gesso', [
            'spec' => ['default' => 'application-spec'],
            'validation' => ['max_errors' => 7],
        ]);

        $this->provider()->register();

        $this->assertSame('application-spec', config('gesso.spec.default'));
        $this->assertSame(7, config('gesso.validation.max_errors'));
        $this->assertFalse(config('gesso.laravel.auto_assert'));
    }

    #[Test]
    public function provider_rejects_every_removed_flat_key_with_its_replacement(): void
    {
        // A published v2 config/gesso.php: v3 fails instead of ignoring it.
        config()->set('gesso', [
            'max_errors' => 7,
            'auto_assert' => true,
            'auto_inject_dummy_bearer' => true,
            'route_parity' => ['excluded_route_names' => ['horizon.*']],
        ]);

        try {
            $this->provider()->register();
            $this->fail('Expected a LogicException.');
        } catch (LogicException $e) {
            $this->assertStringContainsString("'max_errors' -> validation.max_errors", $e->getMessage());
            $this->assertStringContainsString("'auto_assert' -> laravel.auto_assert", $e->getMessage());
            $this->assertStringContainsString(
                "'auto_inject_dummy_bearer' -> laravel.auto_inject_dummy_credentials = 'bearer'",
                $e->getMessage(),
            );
            $this->assertStringContainsString("'route_parity' -> laravel.route_parity", $e->getMessage());
        }
    }

    #[Test]
    public function a_flat_key_set_at_test_time_fails_the_read(): void
    {
        $this->provider()->register();
        config()->set('gesso.auto_assert', true);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("'auto_assert' -> laravel.auto_assert");

        LaravelConfig::get('laravel.auto_assert');
    }

    #[Test]
    public function provider_publishes_a_root_gesso_php_under_the_gesso_tag(): void
    {
        $source = dirname(__DIR__, 3) . '/src/Laravel/config.php';

        $this->provider()->boot();

        $this->assertSame(
            [$source => base_path('gesso.php')],
            ServiceProvider::pathsToPublish(GessoServiceProvider::class, 'gesso'),
        );
        $this->assertSame(
            [],
            ServiceProvider::pathsToPublish(
                GessoServiceProvider::class,
                'openapi-contract-testing',
            ),
        );

        // The published template is itself a valid gesso.php.
        $this->assertSame('/app/openapi', GessoConfig::fromArray(require $source, '/app')->string('spec.base_path'));
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
