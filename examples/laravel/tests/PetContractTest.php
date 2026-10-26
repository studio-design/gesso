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
        // Per-test switch. To enable it for the whole suite, set
        // `laravel.auto_assert` / `laravel.auto_validate_request` in gesso.php.
        config()->set('gesso.auto_assert', true);
        config()->set('gesso.auto_validate_request', true);

        $this->getJson('/pets')->assertOk();
    }

    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [GessoServiceProvider::class];
    }

    // A real app sets `spec.default` in its root gesso.php. Testbench boots a
    // skeleton app elsewhere, so the example names the spec here instead; the
    // PHPUnit extension loads it from the gesso.php beside phpunit.xml.dist.
    protected function openApiSpec(): string
    {
        return 'petstore';
    }

    protected function defineRoutes($router): void
    {
        Route::get('/pets', static fn() => response()->json([
            ['id' => 1, 'name' => 'Fido'],
        ]));
    }
}
