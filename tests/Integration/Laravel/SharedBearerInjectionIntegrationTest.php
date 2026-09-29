<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Integration\Laravel;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\Internal\Deprecations;
use Studio\Gesso\Laravel\GessoServiceProvider;
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function config;
use function dirname;
use function file_put_contents;
use function mkdir;
use function response;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function var_export;

final class SharedBearerInjectionIntegrationTest extends TestCase
{
    use ValidatesOpenApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(dirname(__DIR__, 2) . '/fixtures/specs');
        OpenApiCoverageTracker::reset();
        Deprecations::resetForTesting();
    }

    protected function tearDown(): void
    {
        self::resetValidatorCache();
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        Deprecations::resetForTesting();
        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function apiKeyEndpoints(): iterable
    {
        foreach (['apikey-header', 'apikey-query', 'apikey-cookie', 'and'] as $endpoint) {
            yield $endpoint => [$endpoint];
        }
    }

    #[Test]
    public function shared_bearer_mode_validates_without_changing_the_dispatched_request(): void
    {
        $this->loadSharedConfig('bearer');

        $this->get('/v1/secure/bearer')->assertOk()->assertJsonPath('authorization', null);

        $this->assertArrayHasKey('GET /v1/secure/bearer', OpenApiCoverageTracker::getCovered()['petstore-3.0'] ?? []);
        $this->assertSame([], Deprecations::counts());
    }

    #[Test]
    #[DataProvider('apiKeyEndpoints')]
    public function shared_bearer_mode_does_not_inject_api_keys(string $endpoint): void
    {
        $this->loadSharedConfig('bearer');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('apiKey');

        $this->get('/v1/secure/' . $endpoint);
    }

    #[Test]
    #[DataProvider('apiKeyEndpoints')]
    public function shared_true_mode_still_injects_api_keys(string $endpoint): void
    {
        $this->loadSharedConfig(true);

        $this->get('/v1/secure/' . $endpoint)->assertOk();
    }

    #[Test]
    public function shared_false_mode_still_rejects_a_missing_bearer(): void
    {
        $this->loadSharedConfig(false);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Authorization header is missing');

        $this->get('/v1/secure/bearer');
    }

    #[Test]
    public function shared_bearer_mode_preserves_an_existing_header(): void
    {
        $this->loadSharedConfig('bearer');

        $this->get('/v1/secure/bearer', ['Authorization' => 'Bearer real-token'])
            ->assertOk()->assertJsonPath('authorization', 'Bearer real-token');
    }

    #[Test]
    public function shared_bearer_mode_does_not_replace_a_malformed_header(): void
    {
        $this->loadSharedConfig('bearer');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Bearer');

        $this->get('/v1/secure/bearer', ['Authorization' => 'Basic invalid']);
    }

    protected function defineRoutes($router): void
    {
        Route::get('/v1/secure/{mode}', static fn(Request $request) => response()->json([
            'authorization' => $request->header('Authorization'),
        ]));
    }

    private function loadSharedConfig(bool|string $mode): void
    {
        $directory = sys_get_temp_dir() . '/gesso-bearer-' . uniqid();
        mkdir($directory);
        file_put_contents($directory . '/gesso.php', '<?php return ' . var_export([
            'spec' => ['default' => 'petstore-3.0'],
            'laravel' => ['auto_validate_request' => true, 'auto_inject_dummy_credentials' => $mode],
        ], true) . ';');
        $originalBase = $this->app->basePath();

        try {
            // Register against a real root file, without writing into Testbench's
            // shared vendor application or leaving provider defaults as overrides.
            $this->app->setBasePath($directory);
            config()->set('gesso', []);
            (new GessoServiceProvider($this->app))->register();
        } finally {
            $this->app->setBasePath($originalBase);
            unlink($directory . '/gesso.php');
            rmdir($directory);
        }
    }
}
