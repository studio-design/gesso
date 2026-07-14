<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Integration\Laravel;

use const JSON_THROW_ON_ERROR;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Studio\OpenApiContractTesting\Laravel\OpenApiContractTestingServiceProvider;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;

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
        config()->set('openapi-contract-testing.default_spec', 'route-parity');
        config()->set('openapi-contract-testing.spec_base_path', dirname(__DIR__, 2) . '/fixtures/specs');
        config()->set('openapi-contract-testing.strip_prefixes', ['/api']);
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
        $this->assertSame(1, $decoded['schema_version']);
        $this->assertSame(['route-parity', 'route-parity-admin'], $decoded['specs']);
        $this->assertSame(6, $decoded['summary']['matched']);
        $this->assertSame(1, $decoded['summary']['documented_but_not_registered']);
        $this->assertSame(1, $decoded['summary']['registered_but_undocumented']);
        $this->assertSame(0, $decoded['summary']['ambiguous']);
    }

    #[Test]
    public function json_output_matches_the_v1_9_compatibility_fixture(): void
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
            dirname(__DIR__, 2) . '/fixtures/compatibility/v1.9-laravel-route-parity.json',
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

        $this->assertSame(1, $decoded['schema_version']);
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
    public function invalid_format_returns_invalid_exit_code(): void
    {
        $this->assertSame(2, Artisan::call('openapi:routes', ['--format' => 'xml']));
        $this->assertStringContainsString('Unsupported format', Artisan::output());
    }

    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [OpenApiContractTestingServiceProvider::class];
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
