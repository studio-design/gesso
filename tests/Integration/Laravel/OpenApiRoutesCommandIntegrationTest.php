<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Integration\Laravel;

use const JSON_THROW_ON_ERROR;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Studio\Gesso\Laravel\GessoServiceProvider;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function array_map;
use function count;
use function dirname;
use function explode;
use function file_get_contents;
use function json_decode;
use function max;
use function strlen;
use function trim;

final class OpenApiRoutesCommandIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        config()->set('gesso.default_spec', 'route-parity');
        config()->set('gesso.spec_base_path', dirname(__DIR__, 2) . '/fixtures/specs');
        config()->set('gesso.strip_prefixes', ['/api']);
        config()->set('gesso.route_parity.external_operation_ids', []);
        config()->set('gesso.route_parity.external_openapi_paths', []);
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function command_is_registered_and_renders_route_parity(): void
    {
        $exitCode = Artisan::call('openapi:routes', [
            '--prefix' => 'api',
            '--middleware' => ['api'],
            '--domain' => ['api.example.test'],
            '--exclude-route' => ['internal.*'],
        ]);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('OpenAPI route parity', $output);
        $this->assertStringContainsString('Documented but not registered', $output);
        $this->assertStringContainsString('/v1/missing', $output);
        $this->assertStringContainsString('Registered but undocumented', $output);
        $this->assertStringContainsString('/api/v1/undocumented', $output);
    }

    #[Test]
    public function json_output_is_versioned_and_supports_multiple_specs(): void
    {
        $exitCode = Artisan::call('openapi:routes', [
            '--spec' => ['route-parity', 'route-parity-admin'],
            '--prefix' => 'api',
            '--middleware' => ['api'],
            '--domain' => ['api.example.test'],
            '--exclude-route' => ['internal.*'],
            '--format' => 'json',
        ]);

        $this->assertSame(0, $exitCode);
        $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(2, $decoded['schema_version']);
        $this->assertSame(['route-parity', 'route-parity-admin'], $decoded['specs']);
        $this->assertSame(6, $decoded['summary']['matched']);
        $this->assertSame(1, $decoded['summary']['documented_but_not_registered']);
        $this->assertSame(0, $decoded['summary']['external_operations']);
        $this->assertSame(1, $decoded['summary']['registered_but_undocumented']);
        $this->assertSame(0, $decoded['summary']['ambiguous']);
    }

    #[Test]
    public function json_output_matches_the_v2_compatibility_fixture(): void
    {
        $exitCode = Artisan::call('openapi:routes', [
            '--spec' => ['route-parity', 'route-parity-admin'],
            '--prefix' => 'api',
            '--middleware' => ['api'],
            '--domain' => ['api.example.test'],
            '--exclude-route' => ['internal.*'],
            '--format' => 'json',
        ]);

        $expected = file_get_contents(
            dirname(__DIR__, 2) . '/fixtures/compatibility/v2-laravel-route-parity.json',
        );

        $this->assertIsString($expected);
        $this->assertSame(0, $exitCode);
        $this->assertSame(trim($expected), trim(Artisan::output()));
    }

    #[Test]
    public function large_json_output_is_split_across_observable_lines(): void
    {
        for ($index = 0; $index < 250; $index++) {
            Route::get("/api/v1/undocumented-{$index}", static fn() => null)
                ->name("undocumented.{$index}");
        }

        $exitCode = Artisan::call('openapi:routes', [
            '--format' => 'json',
        ]);

        $this->assertSame(0, $exitCode);
        $output = trim(Artisan::output());
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $lines = explode("\n", $output);

        $this->assertSame(2, $decoded['schema_version']);
        $this->assertGreaterThan(1_000, count($lines));
        $this->assertLessThan(1_000, max(array_map(strlen(...), $lines)));
    }

    #[Test]
    public function strict_exit_flags_fail_independently(): void
    {
        $options = [
            '--prefix' => 'api',
            '--middleware' => ['api'],
            '--domain' => ['api.example.test'],
            '--exclude-route' => ['internal.*'],
            '--format' => 'json',
        ];

        $this->assertSame(1, Artisan::call('openapi:routes', [
            ...$options,
            '--fail-on-undocumented' => true,
        ]));
        $this->assertSame(1, Artisan::call('openapi:routes', [
            ...$options,
            '--fail-on-unimplemented' => true,
        ]));
    }

    #[Test]
    public function documented_side_exclusions_are_reported_and_ignored_by_the_strict_gate(): void
    {
        $exitCode = Artisan::call('openapi:routes', [
            '--prefix' => 'api',
            '--middleware' => ['api'],
            '--domain' => ['api.example.test'],
            '--exclude-route' => ['internal.*', 'undocumented'],
            '--exclude-operation' => ['delete*'],
            '--format' => 'json',
            '--fail-on-unimplemented' => true,
        ]);

        $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, $decoded['summary']['documented_but_not_registered']);
        $this->assertSame(1, $decoded['summary']['external_operations']);
        $this->assertSame('deleteMissing', $decoded['external_operations'][0]['operation_id']);
    }

    #[Test]
    public function documented_side_exclusions_are_loaded_from_config_and_merged_with_options(): void
    {
        config()->set('gesso.route_parity.external_operation_ids', ['does-not-match']);
        config()->set('gesso.route_parity.external_openapi_paths', ['/v1/miss*']);

        $exitCode = Artisan::call('openapi:routes', [
            '--prefix' => 'api',
            '--middleware' => ['api'],
            '--domain' => ['api.example.test'],
            '--exclude-route' => ['internal.*'],
            '--exclude-operation' => ['also-does-not-match'],
            '--format' => 'json',
        ]);

        $decoded = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $decoded['summary']['external_operations']);
        $this->assertSame('/v1/missing', $decoded['external_operations'][0]['openapi_path']);
    }

    #[Test]
    public function invalid_documented_side_exclusion_config_fails_loudly(): void
    {
        config()->set('gesso.route_parity.external_operation_ids', 'forms.*');

        $this->assertSame(1, Artisan::call('openapi:routes'));
        $this->assertStringContainsString(
            'gesso.route_parity.external_operation_ids must be an array of strings',
            Artisan::output(),
        );
    }

    #[Test]
    public function invalid_format_returns_invalid_exit_code(): void
    {
        $this->assertSame(2, Artisan::call('openapi:routes', ['--format' => 'xml']));
        $this->assertStringContainsString('Unsupported format', Artisan::output());
    }

    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [GessoServiceProvider::class];
    }

    protected function defineRoutes($router): void
    {
        Route::domain('api.example.test')->middleware('api')->group(static function (): void {
            Route::match(['GET', 'POST'], '/api/v1/pets', static fn() => null)->name('pets.index');
            Route::get('/api/v1/pets/{pet}', static fn() => null)->name('pets.show');
            Route::get('/api/v1/optional/{optional?}', static fn() => null)->name('optional.show');
            Route::get('/api/v1/undocumented', static fn() => null)->name('undocumented');
            Route::get('/api/v1/internal', static fn() => null)->name('internal.status');
        });

        Route::domain('other.example.test')->middleware('api')->get(
            '/api/v1/other-domain',
            static fn() => null,
        )->name('other-domain');

        Route::fallback(static fn() => null)->name('fallback');
    }
}
