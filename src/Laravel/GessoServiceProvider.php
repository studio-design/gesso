<?php

declare(strict_types=1);

namespace Studio\Gesso\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Studio\Gesso\Config\GessoConfig;
use Studio\Gesso\Laravel\Commands\GessoConfigMigrateCommand;
use Studio\Gesso\Laravel\Commands\OpenApiRoutesCommand;
use Studio\Gesso\Laravel\Commands\OpenApiStubsCommand;

use function array_is_list;
use function is_array;
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
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        // Before gesso.php is merged in, a present key is one the app set —
        // a published v2 config/gesso.php, whose flat keys v3 removed. The
        // command that migrates that file must still be able to boot.
        if (!$this->runningConfigMigrate()) {
            LaravelConfig::rejectRemovedKeys();
        }

        $path = $this->app->basePath(GessoConfig::FILENAME);
        $shared = is_file($path) ? GessoConfig::load($path) : GessoConfig::defaults();
        $existing = $configuration->get(self::CONFIG_KEY, []);

        // Values the app already set under the same key paths keep priority;
        // lists are replaced whole, never concatenated.
        $configuration->set(self::CONFIG_KEY, self::merge($shared->toArray(), is_array($existing) ? $existing : []));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/config.php' => $this->app->basePath(GessoConfig::FILENAME),
        ], self::CONFIG_KEY);

        if ($this->app->runningInConsole()) {
            $this->commands([OpenApiRoutesCommand::class, OpenApiStubsCommand::class, GessoConfigMigrateCommand::class]);
        }
    }

    /**
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $override
     *
     * @return array<array-key, mixed>
     */
    private static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            $base[$key] = is_array($value) && !array_is_list($value) && is_array($base[$key] ?? null)
                ? self::merge($base[$key], $value)
                : $value;
        }

        return $base;
    }

    /** `php artisan gesso:config-migrate` — the console has no resolved command yet during register(). */
    private function runningConfigMigrate(): bool
    {
        $argv = $_SERVER['argv'] ?? [];

        return $this->app->runningInConsole() && is_array($argv) && ($argv[1] ?? null) === GessoConfigMigrateCommand::NAME;
    }
}
