<?php

namespace Tests;

use Studio\Gesso\HttpMethod;

final class AssertionsTest extends TestCase
{
    public function test_laravel(): void
    {
        $this->assertResponseMatchesOpenApiSchema($response);
        $this->assertResponseMatchesOpenApiSchema($response, HttpMethod::GET, '/pets');
        $this->assertResponseMatchesOpenApiSchema($response, null, '/pets');
        $this->assertResponseMatchesOpenApiSchema(response: $response, path: '/pets');
        $this->assertResponseMatchesOpenApiSchema($response, \Studio\Gesso\HttpMethod::from($verb));
    }

    public function test_symfony(): void
    {
        $this->assertResponseMatchesOpenApiSchema($request, $response);
        self::assertResponseMatchesOpenApiSchema($request, $response, ['404']);
        $this->assertResponseMatchesOpenApiSchema(response: $response, request: $request);
        $this->assertRequestMatchesOpenApiSchema($request, 422);
        $this->assertClientMatchesOpenApiSchema($client);
    }

    public function test_psr7(): void
    {
        $this->assertPsr7RequestMatchesOpenApiSchema($request);
        $this->assertPsr7ResponseMatchesOpenApiSchema($request, $response);
        $this->assertPsr7ResponseForOperationMatchesOpenApiSchema('GET', '/pets', $response);
        $this->assertPsr7ResponseForOperationMatchesOpenApiSchema(response: $response, method: 'GET', requestPath: '/pets');
        $this->assertPsr7ExchangeMatchesOpenApiSchema($request, $response);
    }

    public function test_other_receivers(): void
    {
        $other->assertResponseMatchesOpenApiSchema($request, $response);
        $this->assertPsr7ResponseMatchesOpenApiSchema(...$args);
    }
}

?>
-----
<?php

namespace Tests;

use Studio\Gesso\HttpMethod;

final class AssertionsTest extends TestCase
{
    public function test_laravel(): void
    {
        $this->assertOpenApiResponse($response);
        $this->assertOpenApiResponse($response, HttpMethod::GET, '/pets');
        $this->assertOpenApiResponse($response, null, '/pets');
        $this->assertOpenApiResponse(response: $response, path: '/pets');
        $this->assertOpenApiResponse($response, \Studio\Gesso\HttpMethod::from($verb));
    }

    public function test_symfony(): void
    {
        $this->assertOpenApiResponse($response, $request);
        self::assertOpenApiResponse($response, $request, extraSkipResponseCodes: ['404']);
        $this->assertOpenApiResponse($response, $request);
        $this->assertOpenApiRequest($request, 422);
        $this->assertOpenApiExchange($client);
    }

    public function test_psr7(): void
    {
        $this->assertOpenApiRequest($request);
        $this->assertOpenApiResponse($response, $request);
        $this->assertOpenApiResponse($response, method: 'GET', path: '/pets');
        $this->assertOpenApiResponse($response, method: 'GET', path: '/pets');
        $this->assertOpenApiExchange($request, $response);
    }

    public function test_other_receivers(): void
    {
        $other->assertResponseMatchesOpenApiSchema($request, $response);
        $this->assertPsr7ResponseMatchesOpenApiSchema(...$args);
    }
}

?>
