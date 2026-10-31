<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Integration\Laravel;

use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Studio\Gesso\Laravel\GessoServiceProvider;

use function config;
use function copy;
use function dirname;
use function file_get_contents;
use function is_file;
use function mkdir;
use function putenv;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class GessoConfigMigrateCommandIntegrationTest extends TestCase
{
    private string $project;

    /** @var array<mixed> */
    private array $argv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->argv = $_SERVER['argv'] ?? [];
        $this->project = sys_get_temp_dir() . '/gesso-artisan-config-migrate-' . uniqid('', true);
        mkdir($this->project . '/config', 0o777, true);
        copy(dirname(__DIR__, 2) . '/fixtures/config-migrate/legacy-laravel/config/gesso.php', $this->project . '/config/gesso.php');
    }

    protected function tearDown(): void
    {
        $_SERVER['argv'] = $this->argv;
        putenv('GESSO_FIXTURE_SPEC_PATH');
        foreach (['/gesso.php', '/config/gesso.php'] as $file) {
            if (is_file($this->project . $file)) {
                unlink($this->project . $file);
            }
        }
        @rmdir($this->project . '/config');
        @rmdir($this->project);
        parent::tearDown();
    }

    #[Test]
    public function the_provider_boots_past_flat_keys_only_for_config_migrate(): void
    {
        config()->set('gesso', ['auto_assert' => true]);
        $_SERVER['argv'] = ['artisan', 'gesso:config-migrate'];

        (new GessoServiceProvider($this->app))->register();

        $this->assertTrue(config('gesso.auto_assert'), 'the flat key is left for the command to read');
    }

    #[Test]
    public function artisan_writes_gesso_php_with_env_values_as_literals(): void
    {
        putenv('GESSO_FIXTURE_SPEC_PATH=contracts');
        $this->app->setBasePath($this->project);

        $this->assertSame(0, Artisan::call('gesso:config-migrate', ['--write' => true]));

        $written = (string) file_get_contents($this->project . '/gesso.php');
        $this->assertStringContainsString("'base_path' => 'contracts',", $written);
        $this->assertStringContainsString("'auto_inject_dummy_credentials' => 'bearer',", $written);
        $this->assertStringContainsString('calls env() or other functions', Artisan::output());

        // config/gesso.php is still there, so the migration is not finished.
        $this->assertSame(1, Artisan::call('gesso:config-migrate', ['--check' => true]));
        unlink($this->project . '/config/gesso.php');
        $this->assertSame(0, Artisan::call('gesso:config-migrate', ['--check' => true]));
    }

    protected function getPackageProviders($app): array
    {
        return [GessoServiceProvider::class];
    }
}
