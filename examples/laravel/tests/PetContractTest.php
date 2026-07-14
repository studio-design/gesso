<?php

declare(strict_types=1);

namespace Examples\Laravel\Tests;

use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use Studio\Gesso\Laravel\GessoServiceProvider;
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;

final class PetContractTest extends TestCase
{
    use ValidatesOpenApiSchema;

    public function test_explicit_response_assertion(): void
    {
        $response = $this->getJson('/pets');

        $response->assertOk();
        $this->assertResponseMatchesOpenApiSchema($response);
    }

    public function test_automatic_request_and_response_validation(): void
    {
        config()->set('gesso.auto_assert', true);
        config()->set('gesso.auto_validate_request', true);

        $this->getJson('/pets')->assertOk();
    }

    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [GessoServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('gesso.default_spec', 'petstore');
        $app['config']->set('gesso.spec_base_path', __DIR__ . '/../openapi');
    }

    protected function defineRoutes($router): void
    {
        Route::get('/pets', static fn() => response()->json([
            ['id' => 1, 'name' => 'Fido'],
        ]));
    }
}
