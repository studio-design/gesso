<?php

declare(strict_types=1);

namespace Studio\Gesso\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Studio\Gesso\Laravel\Commands\OpenApiRoutesCommand;

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

        $this->mergeConfigFrom(__DIR__ . '/config.php', self::CONFIG_KEY);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/config.php' => config_path('gesso.php'),
        ], self::CONFIG_KEY);

        if ($this->app->runningInConsole()) {
            $this->commands([OpenApiRoutesCommand::class]);
        }
    }
}
