# PSR-7 request and response validation

Use the PSR-7 adapter when your application or HTTP client already exposes
`RequestInterface`, `ServerRequestInterface`, and `ResponseInterface` messages.
It validates both sides of an exchange through the same core validators and
records the same response-level coverage as the Laravel and Symfony adapters.

No concrete PSR-7 implementation is required by this package. The examples use
Guzzle PSR-7, but Nyholm PSR-7, Laminas Diactoros, Slim messages, and other
implementations of `psr/http-message` use the same API.

## Result API

Configure the spec loader once, then construct an adapter for a spec:

```php
use Studio\OpenApiContractTesting\Psr7\OpenApiPsr7Validator;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;

OpenApiSpecLoader::configure(__DIR__ . '/openapi', ['/api']);

$validator = new OpenApiPsr7Validator('front');
$result = $validator->validateExchange($request, $response);

if (!$result->isValid()) {
    throw new RuntimeException($result->errorMessage());
}
```

`validateExchange()` validates the request first and forwards the response
status to the request validator, preserving the documented-4xx downgrade used
by the framework adapters. The returned `OpenApiPsr7ValidationResult` exposes
`requestResult()` and `responseResult()` when the two outcomes need to be
handled separately.

The individual entry points are:

```php
$requestResult = $validator->validateRequest($request, $response->getStatusCode());

// Resolve method and path from a RequestInterface.
$responseResult = $validator->validateResponse($request, $response);

// Or provide the operation address when only a response is available.
$responseResult = $validator->validateResponseForOperation(
    method: 'POST',
    requestPath: '/v1/pets',
    response: $response,
);
```

A `ServerRequestInterface` contributes its parsed query and cookie parameters.
For a client-side `RequestInterface`, the adapter parses the URI query and
`Cookie` header. Method spelling is preserved so OpenAPI 3.2 custom
`additionalOperations` remain case-sensitive.

## PHPUnit assertions

Mix the PSR-7 assertion trait into a PHPUnit test and select the spec with an
attribute or the `openApiSpec()` hook:

```php
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Attribute\OpenApiSpec;
use Studio\OpenApiContractTesting\Psr7\OpenApiAssertions;

#[OpenApiSpec('front')]
final class CreatePetTest extends TestCase
{
    use OpenApiAssertions;

    public function test_create_pet(): void
    {
        [$request, $response] = $this->callApplication();

        $this->assertPsr7ExchangeMatchesOpenApiSchema($request, $response);
    }
}
```

Request-only, response-only, and explicit-operation assertions are also
available:

```php
$this->assertPsr7RequestMatchesOpenApiSchema($request, $response->getStatusCode());
$this->assertPsr7ResponseMatchesOpenApiSchema($request, $response);
$this->assertPsr7ResponseForOperationMatchesOpenApiSchema('POST', '/v1/pets', $response);
```

## PSR-15 test integration

Keep contract validation in tests rather than placing validation middleware on
the production request path. A PSR-15 handler test can validate the exchange
immediately after invoking the handler:

```php
final class ContractTest extends TestCase
{
    public function test_handler_contract(): void
    {
        $request = $this->serverRequestFactory->createServerRequest('GET', '/v1/pets');
        $response = $this->handler->handle($request);

        $result = $this->openApi->validateExchange($request, $response);

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }
}
```

This works at the outer handler boundary or in a test-only middleware harness;
the adapter does not require `psr/http-server-middleware` and adds no runtime
middleware to the application.

## Body streams

The adapter preserves a seekable stream's current cursor: it remembers the
position, reads from the beginning, and restores the original position before
returning. Empty, scalar, object/array, and literal JSON `null` bodies remain
distinct.

A non-seekable JSON stream cannot be read and then restored through the
[PSR-7 `StreamInterface`](https://www.php-fig.org/psr/psr-7/#34-psrhttpmessagestreaminterface).
The adapter therefore returns a failure without reading it. Buffer or decorate
the body with a seekable/caching stream before validation when the application
uses a one-pass stream. Non-JSON bodies are not consumed; the core validator
still checks their declared content type and reports schema enforcement as
skipped where appropriate.

See the [runnable Guzzle example](../examples/psr7/README.md) for a complete
project.
