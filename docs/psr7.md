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
use Studio\Gesso\Psr7\OpenApiPsr7Validator;
use Studio\Gesso\Spec\OpenApiSpecLoader;

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
use Studio\Gesso\Attribute\OpenApiSpec;
use Studio\Gesso\Psr7\OpenApiAssertions;

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

Without an attribute or override, `openApiSpec()` returns `spec.default` from
[`gesso.php`](configuration.md) (or the `default_spec` extension parameter).
Likewise `openApiMaxErrors()`, `openApiSkipResponseCodes()`, and
`openApiSkipRequestValidationResponseCodes()` default to the shared
`validation.*` settings; override a hook to pin a per-suite value.

Request-only, response-only, and explicit-operation assertions are also
available:

```php
$this->assertPsr7RequestMatchesOpenApiSchema($request, $response->getStatusCode());
$this->assertPsr7ResponseMatchesOpenApiSchema($request, $response);
$this->assertPsr7ResponseForOperationMatchesOpenApiSchema('POST', '/v1/pets', $response);
```

The explicit-operation assertion also checks hand-written HTTP client fakes
against an external API's spec; see
[Consumer-side contract testing](consumer-side-contract-testing.md#asserting-hand-written-fakes).

Assertion failures end with a redacted `Reproduce: curl …` line (for the
exchange assertion, one line for the whole exchange, with each error prefixed
by its `[request]` / `[response]` side). In the JSON failure output mode the
exchange failure instead emits one labelled JSON document per failing side.
See [reproduction commands](setup.md#reproduction-commands-in-failure-output)
and [validation-json-schema.md](validation-json-schema.md).

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
[PSR-7 `StreamInterface`](https://github.com/php-fig/http-message/blob/2.0/src/StreamInterface.php).
The adapter therefore returns a failure without reading it. Buffer or decorate
the body with a seekable/caching stream before validation when the application
uses a one-pass stream. This also applies when a form request needs raw bytes
because no parsed fields are available. A read or JSON parse failure produces
only a parse issue for that body, not a second empty-body or schema error.
Other parameter/header/security violations remain visible, including when the
request produced a documented 4xx response.

Opaque (non-JSON, non-form) request presence is inspected only when the resolved
contract requires it. Parsed fields/files or a known size need no read; an
unknown-size seekable stream needs only `read(1)`, with its cursor restored.
Optional, undeclared, and unmatched opaque bodies are not inspected. The core
still checks the declared content type and reports unsupported schema
enforcement as skipped.

See the [runnable Guzzle example](https://github.com/studio-design/gesso/tree/main/examples/psr7) for a complete
project.
