<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Integration\Laravel;

use const JSON_THROW_ON_ERROR;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Studio\Gesso\Internal\LegacyIdentity;
use Studio\Gesso\Laravel\GessoServiceProvider;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function array_map;
use function count;
use function dirname;
use function explode;
use function file_get_contents;
use function json_decode;
use function max;
use function preg_replace;
use function strlen;
use function trim;

final class OpenApiRoutesCommandIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        LegacyIdentity::resetForTesting();
        config()->set('gesso.spec.default', 'route-parity');
        config()->set('gesso.spec.base_path', dirname(__DIR__, 2) . '/fixtures/specs');
        config()->set('gesso.spec.strip_prefixes', ['/api']);
        config()->set('gesso.laravel.route_parity.external_operation_ids', []);
        config()->set('gesso.laravel.route_parity.external_openapi_paths', []);
        config()->set('gesso.laravel.route_parity.excluded_route_names', []);
        config()->set('gesso.spec.names', []);
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
        LegacyIdentity::resetForTesting();
        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function provideAn_absolute_spec_base_path_is_used_verbatimCases(): iterable
    {
        yield 'posix' => ['/abs/specs'];
        yield 'drive letter, backslash' => ['C:\\specs'];
        yield 'drive letter, slash' => ['D:/specs'];
        yield 'unc share' => ['\\\\server\\share\\specs'];
    }

    /**
     * Issue #504: `openapi:routes` keeps working through v3. The two paths have
     * to be indistinguishable apart from the one deprecation line, so this
     * compares them rather than asserting a shape twice.
     */
    #[Test]
    public function the_legacy_command_name_is_an_alias_with_identical_output(): void
    {
        $options = ['--prefix' => 'api', '--format' => 'json'];

        $currentExit = Artisan::call('gesso:routes', $options);
        $currentOutput = Artisan::output();
        $this->assertSame([], LegacyIdentity::warnings());

        $legacyExit = Artisan::call('openapi:routes', $options);

        $this->assertSame($currentExit, $legacyExit);
        $this->assertSame($currentOutput, Artisan::output());
        $this->assertSame(
            ['[Gesso] WARNING: openapi:routes is deprecated and will be removed in Gesso '
                . LegacyIdentity::REMOVED_IN . '. Use gesso:routes.'],
            LegacyIdentity::warnings(),
        );
    }

    #[Test]
    public function command_is_registered_and_renders_route_parity(): void
    {
        $exitCode = Artisan::call('gesso:routes', [
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
        $exitCode = Artisan::call('gesso:routes', [
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
        $exitCode = Artisan::call('gesso:routes', [
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

        $exitCode = Artisan::call('gesso:routes', [
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

        $this->assertSame(1, Artisan::call('gesso:routes', [
            ...$options,
            '--fail-on-undocumented' => true,
        ]));
        $this->assertSame(1, Artisan::call('gesso:routes', [
            ...$options,
            '--fail-on-unimplemented' => true,
        ]));
    }

    #[Test]
    public function documented_side_exclusions_are_reported_and_ignored_by_the_strict_gate(): void
    {
        $exitCode = Artisan::call('gesso:routes', [
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
        config()->set('gesso.laravel.route_parity.external_operation_ids', ['does-not-match']);
        config()->set('gesso.laravel.route_parity.external_openapi_paths', ['/v1/miss*']);

        $exitCode = Artisan::call('gesso:routes', [
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
        config()->set('gesso.laravel.route_parity.external_operation_ids', 'forms.*');

        $this->assertSame(1, Artisan::call('gesso:routes'));
        $this->assertStringContainsString(
            'gesso.laravel.route_parity.external_operation_ids must be an array of strings',
            Artisan::output(),
        );
    }

    /**
     * The buggy output was `<root>/C:\\specs`, which still contains the base
     * path, so only the negative check against the root discriminates. Laravel's
     * console components strip `base_path()` from error lines while
     * `path.base` is bound, which would hide that root, so it is unbound here.
     */
    #[Test]
    #[DataProvider('provideAn_absolute_spec_base_path_is_used_verbatimCases')]
    public function an_absolute_spec_base_path_is_used_verbatim(string $basePath): void
    {
        config()->set('gesso.spec.base_path', $basePath);
        $this->app->offsetUnset('path.base');

        $this->assertSame(1, Artisan::call('gesso:routes'));
        $output = (string) preg_replace('/\s+/', ' ', Artisan::output());
        $this->assertStringContainsString($basePath, $output);
        $this->assertStringNotContainsString($this->app->basePath(), $output);
    }

    #[Test]
    public function a_relative_spec_base_path_is_resolved_under_the_application(): void
    {
        config()->set('gesso.spec.base_path', 'openapi');
        $this->app->offsetUnset('path.base');

        $this->assertSame(1, Artisan::call('gesso:routes'));
        $this->assertStringContainsString(
            $this->app->basePath('openapi'),
            (string) preg_replace('/\s+/', ' ', Artisan::output()),
        );
    }

    #[Test]
    public function route_side_exclusions_are_loaded_from_config_and_merged_with_options(): void
    {
        config()->set('gesso.laravel.route_parity.excluded_route_names', ['internal.*']);

        $decoded = $this->routeParityJson(['--exclude-route' => ['undocumented']]);

        $this->assertSame(0, $decoded['summary']['registered_but_undocumented']);
    }

    #[Test]
    public function configured_route_side_exclusions_apply_without_the_option(): void
    {
        config()->set('gesso.laravel.route_parity.excluded_route_names', ['internal.*']);

        $decoded = $this->routeParityJson([]);

        $this->assertSame(1, $decoded['summary']['registered_but_undocumented']);
        $this->assertSame('undocumented', $decoded['registered_but_undocumented'][0]['route_name']);
    }

    #[Test]
    public function the_spec_list_is_read_from_config_when_no_spec_option_is_passed(): void
    {
        config()->set('gesso.spec.names', ['route-parity', 'route-parity-admin']);
        config()->set('gesso.spec.default', 'does-not-exist');

        $this->assertSame(['route-parity', 'route-parity-admin'], $this->routeParityJson([])['specs']);
    }

    #[Test]
    public function the_spec_option_replaces_the_configured_spec_list(): void
    {
        config()->set('gesso.spec.names', ['route-parity', 'route-parity-admin']);

        $this->assertSame(['route-parity'], $this->routeParityJson(['--spec' => ['route-parity']])['specs']);
    }

    #[Test]
    public function invalid_route_side_exclusion_config_fails_loudly(): void
    {
        config()->set('gesso.laravel.route_parity.excluded_route_names', 'internal.*');

        $this->assertSame(1, Artisan::call('gesso:routes'));
        $this->assertStringContainsString(
            'gesso.laravel.route_parity.excluded_route_names must be an array of strings',
            Artisan::output(),
        );
    }

    #[Test]
    public function invalid_specs_config_fails_loudly(): void
    {
        config()->set('gesso.spec.names', 'front');

        $this->assertSame(1, Artisan::call('gesso:routes'));
        $this->assertStringContainsString('gesso.spec.names must be an array of strings', Artisan::output());
    }

    #[Test]
    public function invalid_format_returns_invalid_exit_code(): void
    {
        $this->assertSame(2, Artisan::call('gesso:routes', ['--format' => 'xml']));
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

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function routeParityJson(array $options): array
    {
        $exitCode = Artisan::call('gesso:routes', $options + [
            '--prefix' => 'api',
            '--middleware' => ['api'],
            '--domain' => ['api.example.test'],
            '--format' => 'json',
        ]);
        $output = Artisan::output();
        $this->assertSame(0, $exitCode, $output);

        return json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);
    }
}
