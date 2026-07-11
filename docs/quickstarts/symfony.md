# Symfony quickstart

```bash
composer require --dev studio-design/openapi-contract-testing symfony/http-foundation symfony/http-kernel
```

Mix `Studio\OpenApiContractTesting\Symfony\OpenApiAssertions` into a PHPUnit or `WebTestCase` class:

```php
$request = Request::create('/pets', 'GET');
$response = new Response('[{"id":1,"name":"Fido"}]', 200, [
    'Content-Type' => 'application/json',
]);

$this->assertResponseMatchesOpenApiSchema($request, $response);
```

Run the CI-tested [`examples/symfony`](https://github.com/studio-design/gesso/tree/main/examples/symfony) fixture:

```bash
composer test
```

For a full application, call `assertClientMatchesOpenApiSchema($client)` after `KernelBrowser::request()`. Both forms record coverage.
