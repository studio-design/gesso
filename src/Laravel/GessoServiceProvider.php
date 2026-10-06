<?php

declare(strict_types=1);

namespace Studio\Gesso\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Studio\Gesso\Config\ConfigurationBridge;
use Studio\Gesso\Config\GessoConfig;
use Studio\Gesso\Laravel\Commands\OpenApiRoutesCommand;
use Studio\Gesso\Laravel\Commands\OpenApiStubsCommand;

use function is_file;

class GessoServiceProvider extends ServiceProvider
{
    private const CONFIG_KEY = 'gesso';
    private const LEGACY_CONFIG_KEY = 'openapi-contract-testing';

    public function register(): void
    {
        /** @var Repository $configuration */
        $configuration = $this->app->make('config');

        if ($configuration->has(self::LEGACY_CONFIG_KEY)) {
            throw new LogicException(
                'Gesso v2 detected the legacy Laravel configuration key '
                . '"openapi-contract-testing". Before installing v2, clear Laravel\'s '
                . 'configuration cache, rename config/openapi-contract-testing.php to '
                . 'config/gesso.php, and update direct config lookups to use [gesso].',
            );
        }

        // config:cache stores these resolved values in Laravel's repository.
        // Do not execute the root file again while using that snapshot.
        if (!($this->app instanceof CachesConfiguration && $this->app->configurationIsCached())) {
            $path = $this->app->basePath(GessoConfig::FILENAME);
            if (is_file($path)) {
                foreach (ConfigurationBridge::laravel(GessoConfig::load($path)) as $key => $value) {
                    // Existing v2 app configuration and test overrides keep priority.
                    if (!$configuration->has(self::CONFIG_KEY . '.' . $key)) {
                        $configuration->set(self::CONFIG_KEY . '.' . $key, $value);
                    }
                }
            }
        }

        $this->mergeConfigFrom(__DIR__ . '/config.php', self::CONFIG_KEY);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/config.php' => config_path('gesso.php'),
        ], self::CONFIG_KEY);

        if ($this->app->runningInConsole()) {
            $this->commands([OpenApiRoutesCommand::class, OpenApiStubsCommand::class]);
        }
    }
}
