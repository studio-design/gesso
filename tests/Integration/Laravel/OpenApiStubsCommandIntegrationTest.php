<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Integration\Laravel;

use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Studio\Gesso\Laravel\GessoServiceProvider;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function dirname;
use function file_get_contents;
use function is_dir;
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
        config()->set('gesso.default_spec', 'petstore-3.0');
        config()->set('gesso.spec_base_path', dirname(__DIR__, 2) . '/fixtures/specs');
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
        parent::tearDown();
    }

    #[Test]
    public function command_is_registered_and_resolves_the_spec_from_config(): void
    {
        $exitCode = Artisan::call('openapi:stubs', ['--output' => $this->outputDir]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('GetV1PetsTest.php', Artisan::output());

        // The Laravel adapter is the default, and gesso.default_spec supplies
        // the spec name the generated attribute names.
        $code = (string) file_get_contents($this->outputDir . '/GetV1PetsTest.php');
        $this->assertStringContainsString("#[OpenApiSpec('petstore-3.0')]", $code);
        $this->assertStringContainsString('use ValidatesOpenApiSchema;', $code);
        $this->assertStringContainsString('$response = $this->getJson(', $code);
        $this->assertStringContainsString("    '/v1/pets',", $code);
    }

    #[Test]
    public function a_dry_run_writes_nothing(): void
    {
        $exitCode = Artisan::call('openapi:stubs', ['--output' => $this->outputDir, '--dry-run' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Would write', Artisan::output());
        $this->assertDirectoryDoesNotExist($this->outputDir);
    }

    #[Test]
    public function an_unsupported_adapter_is_a_usage_error(): void
    {
        $exitCode = Artisan::call('openapi:stubs', ['--adapter' => 'codeception']);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('Unsupported adapter', Artisan::output());
    }

    #[Test]
    public function a_missing_spec_is_reported_rather_than_thrown(): void
    {
        config()->set('gesso.default_spec', 'does-not-exist');

        $exitCode = Artisan::call('openapi:stubs', ['--output' => $this->outputDir]);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('does-not-exist.json', Artisan::output());
    }

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [GessoServiceProvider::class];
    }
}
