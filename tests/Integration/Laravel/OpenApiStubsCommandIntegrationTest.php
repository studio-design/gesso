<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Integration\Laravel;

use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Studio\Gesso\Internal\LegacyIdentity;
use Studio\Gesso\Laravel\GessoServiceProvider;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function dirname;
use function file_get_contents;
use function is_dir;
use function preg_replace;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class OpenApiStubsCommandIntegrationTest extends TestCase
{
    private string $outputDir;

    protected function setUp(): void
    {
        $this->outputDir = sys_get_temp_dir() . '/gesso-artisan-stubs-' . uniqid('', true);
        parent::setUp();
        OpenApiSpecLoader::reset();
        LegacyIdentity::resetForTesting();
        config()->set('gesso.spec.default', 'petstore-3.0');
        config()->set('gesso.spec.base_path', dirname(__DIR__, 2) . '/fixtures/specs');
    }

    protected function tearDown(): void
    {
        foreach (is_dir($this->outputDir) ? (scandir($this->outputDir) ?: []) : [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($this->outputDir . '/' . $entry);
            }
        }
        @rmdir($this->outputDir);
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

    /** Issue #504: `openapi:stubs` keeps working through v3, with one extra line. */
    #[Test]
    public function the_legacy_command_name_is_an_alias_with_identical_output(): void
    {
        $currentExit = Artisan::call('gesso:stubs', ['--output-dir' => $this->outputDir, '--dry-run' => true]);
        $currentOutput = Artisan::output();
        $this->assertSame([], LegacyIdentity::warnings());

        $legacyExit = Artisan::call('openapi:stubs', ['--output-dir' => $this->outputDir, '--dry-run' => true]);

        $this->assertSame($currentExit, $legacyExit);
        $this->assertSame($currentOutput, Artisan::output());
        $this->assertSame(
            ['[Gesso] WARNING: openapi:stubs is deprecated and will be removed in Gesso '
                . LegacyIdentity::REMOVED_IN . '. Use gesso:stubs.'],
            LegacyIdentity::warnings(),
        );
    }

    #[Test]
    public function the_removed_v2_output_option_is_rejected_naming_output_dir(): void
    {
        $this->assertSame(2, Artisan::call('gesso:stubs', ['--output' => $this->outputDir]));
        $this->assertStringContainsString('--output (removed in Gesso 3.0; use --output-dir)', Artisan::output());
        $this->assertFileDoesNotExist($this->outputDir . '/GetV1PetsTest.php');
    }

    #[Test]
    public function command_is_registered_and_resolves_the_spec_from_config(): void
    {
        $exitCode = Artisan::call('gesso:stubs', ['--output-dir' => $this->outputDir]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('GetV1PetsTest.php', Artisan::output());

        // The Laravel adapter is the default, and gesso.spec.default supplies
        // the spec name the generated attribute names.
        $code = (string) file_get_contents($this->outputDir . '/GetV1PetsTest.php');
        $this->assertStringContainsString("#[OpenApiSpec('petstore-3.0')]", $code);
        $this->assertStringContainsString('use ValidatesOpenApiSchema;', $code);
        $this->assertStringContainsString('$response = $this->getJson(', $code);
        $this->assertStringContainsString("    '/v1/pets',", $code);
    }

    #[Test]
    public function explicit_request_prefix_is_forwarded_without_changing_the_spec_path(): void
    {
        config()->set('gesso.spec.strip_prefixes', ['/gateway', '/api']);
        $this->assertSame(0, Artisan::call('gesso:stubs', [
            '--output-dir' => $this->outputDir, '--request-prefix' => '/api/',
        ]));
        $code = (string) file_get_contents($this->outputDir . '/GetV1PetsTest.php');
        $this->assertStringContainsString("'/api/v1/pets'", $code);
        $this->assertStringContainsString('GET /v1/pets', $code);
        $this->assertStringNotContainsString('/gateway', $code);

        // Prefix changes do not overwrite the user's existing test.
        $this->assertSame(0, Artisan::call('gesso:stubs', [
            '--output-dir' => $this->outputDir, '--request-prefix' => '/other',
        ]));
        $this->assertSame($code, file_get_contents($this->outputDir . '/GetV1PetsTest.php'));
    }

    #[Test]
    public function request_prefix_is_not_inferred_from_strip_prefixes(): void
    {
        config()->set('gesso.spec.strip_prefixes', ['/api']);
        $this->assertSame(0, Artisan::call('gesso:stubs', ['--output-dir' => $this->outputDir]));
        $code = (string) file_get_contents($this->outputDir . '/GetV1PetsTest.php');
        $this->assertStringContainsString("'/v1/pets'", $code);
        $this->assertStringNotContainsString("'/api/v1/pets'", $code);
    }

    #[Test]
    public function malformed_request_prefix_is_reported_without_silent_trimming(): void
    {
        $this->assertSame(2, Artisan::call('gesso:stubs', [
            '--output-dir' => $this->outputDir, '--request-prefix' => '/api ',
        ]));
        $this->assertStringContainsString('--request-prefix', Artisan::output());
        $this->assertDirectoryDoesNotExist($this->outputDir);
    }

    #[Test]
    public function a_dry_run_writes_nothing(): void
    {
        $exitCode = Artisan::call('gesso:stubs', ['--output-dir' => $this->outputDir, '--dry-run' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Would write', Artisan::output());
        $this->assertDirectoryDoesNotExist($this->outputDir);
    }

    #[Test]
    public function an_unsupported_adapter_is_a_usage_error(): void
    {
        $exitCode = Artisan::call('gesso:stubs', ['--adapter' => 'codeception']);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('Unsupported adapter', Artisan::output());
    }

    #[Test]
    public function a_missing_spec_is_reported_rather_than_thrown(): void
    {
        config()->set('gesso.spec.default', 'does-not-exist');

        $exitCode = Artisan::call('gesso:stubs', ['--output-dir' => $this->outputDir]);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('does-not-exist.json', Artisan::output());
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

        $this->assertSame(2, Artisan::call('gesso:stubs', ['--output-dir' => $this->outputDir]));
        $output = (string) preg_replace('/\s+/', ' ', Artisan::output());
        $this->assertStringContainsString($basePath, $output);
        $this->assertStringNotContainsString($this->app->basePath(), $output);
    }

    #[Test]
    public function a_relative_spec_base_path_is_resolved_under_the_application(): void
    {
        config()->set('gesso.spec.base_path', 'openapi');
        $this->app->offsetUnset('path.base');

        $this->assertSame(2, Artisan::call('gesso:stubs', ['--output-dir' => $this->outputDir]));
        $this->assertStringContainsString(
            $this->app->basePath('openapi'),
            (string) preg_replace('/\s+/', ' ', Artisan::output()),
        );
    }

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [GessoServiceProvider::class];
    }
}
