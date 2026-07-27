# Contract testing a Symfony API with OpenAPI

Contract testing a Symfony API with OpenAPI takes four moving parts: the OpenAPI spec, a `WebTestCase` (or a plain `Request`/`Response` pair), one trait, and its assertions. This guide covers all four, then shows the full runnable example.

## What is OpenAPI contract testing?

**OpenAPI contract testing verifies that your live HTTP requests and responses conform to the OpenAPI schema you publish.** The spec is the source of truth; the tests confirm the implementation still matches it — nothing more, nothing less.

This is the *provider-side, spec-driven* flavour of contract testing. It is different from consumer-driven contract testing (Pact and friends), which starts from what each consumer expects. Both are valid. Provider-side spec testing shines when you already publish OpenAPI and want a fast, per-endpoint check that the implementation hasn't drifted.

## What you'll need

- PHP 8.3+ and Symfony 6.4, 7.x, or 8.x
- A Symfony application with `symfony/http-foundation`, `symfony/http-kernel`, and — for the `WebTestCase` / `KernelBrowser` flow — `symfony/framework-bundle` installed
- An OpenAPI 3.0, 3.1, or 3.2 document you want to enforce
- Gesso (`studio-design/gesso`), the framework-independent core plus its Symfony adapter

Install:

```bash
composer require --dev "studio-design/gesso:^2.0" \
  symfony/http-foundation symfony/http-kernel symfony/framework-bundle
```

The Symfony packages are suggestions, not hard requirements of Gesso — the core stays framework-independent, so you pull them in yourself to use the Symfony adapter. Skip `symfony/framework-bundle` if you only want the plain `Request`/`Response` form (shown further down); include it for the `WebTestCase` flow.

## The four moving parts

### 1. The spec

Gesso finds spec files through the PHPUnit extension, not a Symfony bundle or a `config/packages/*.yaml` file. Register the extension in `phpunit.xml`:

```xml
<extensions>
    <bootstrap class="Studio\Gesso\PHPUnit\OpenApiCoverageExtension">
        <parameter name="spec_base_path" value="openapi"/>
        <parameter name="specs" value="petstore"/>
    </bootstrap>
</extensions>
```

`spec_base_path` is the directory holding your documents; `specs` lists the ones to report coverage for. YAML or JSON, 3.0.x, 3.1.x, or 3.2.x — the same loader accepts all of them (YAML needs `symfony/yaml`). Which spec a given test uses is pinned by a `#[OpenApiSpec]` attribute or by overriding `openApiSpec()`; there is no Laravel-style `default_spec` lookup in the Symfony adapter.

### 2. A WebTestCase

Extend Symfony's `WebTestCase` (or a plain PHPUnit class if you're constructing requests by hand):

```php
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PetsApiTest extends WebTestCase
{
    // ...
}
```

### 3. The trait

Mix in the Gesso Symfony assertion trait and name the spec:

```php
use Studio\Gesso\Attribute\OpenApiSpec;
use Studio\Gesso\Symfony\OpenApiAssertions;

#[OpenApiSpec('petstore')]
class PetsApiTest extends WebTestCase
{
    use OpenApiAssertions;
}
```

The trait adds three assertion methods: `assertResponseMatchesOpenApiSchema($request, $response)` for responses, `assertRequestMatchesOpenApiSchema($request)` for requests, and `assertClientMatchesOpenApiSchema($client)`, which runs both against the last call made by a test client.

### 4. The assertion

Either shape works. When you already have a `KernelBrowser` from `static::createClient()`:

```php
public function test_get_pets_matches_spec(): void
{
    $client = static::createClient();
    $client->request('GET', '/pets');

    $this->assertResponseIsSuccessful();
    $this->assertClientMatchesOpenApiSchema($client);
}
```

When you're constructing `Request` and `Response` objects directly — useful for unit-style tests that don't boot the kernel:

```php
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

$request = Request::create('/pets', 'GET');
$response = new Response(
    '[{"id":1,"name":"Fido"}]',
    200,
    ['Content-Type' => 'application/json'],
);

$this->assertRequestMatchesOpenApiSchema($request, 200);
$this->assertResponseMatchesOpenApiSchema($request, $response);
```

Both forms record spec coverage.

## The full example

The `examples/symfony` fixture in the Gesso repository installs and runs in CI on every push. To reproduce locally:

```bash
git clone https://github.com/studio-design/gesso.git
cd gesso/examples/symfony
composer install
composer test
```

The PHPUnit output ends with an `OpenAPI Contract Test Coverage` report: per spec, the share of endpoints fully covered and of `(method, path, status, content-type)` response rows validated, then a per-endpoint breakdown marking each row validated, skipped, uncovered, or request-only. That report is the point of running the tests: passing tests prove *these* operations conform; the coverage report shows which ones you haven't covered yet.

## What to check next

Contract enforcement is the entry point. Three follow-ups matter for a real Symfony service:

- **Coverage in CI.** Set `min_endpoint_coverage` / `min_response_coverage` on the PHPUnit extension so a PR that quietly stops testing an operation fails the build.
- **Preflight your spec.** Run `vendor/bin/gesso doctor --spec=openapi/petstore.json --strip-prefix=/api` in CI. It reports whether Gesso can load and enforce the document — version and dialect, references, warned-about keywords, structurally valid operations — and exits non-zero when it can't.
- **Schema-driven exploration.** Once the happy paths are green, generate request cases from the spec's schemas with `OpenApiSpecExplorer::explore()` and assert each dispatched response with the same validators the trait uses.

## FAQ

**Does this replace consumer-driven contract testing?**
No. Provider-side spec testing verifies that your implementation matches the spec you publish. Consumer-driven testing (Pact) verifies that specific consumers' expectations are still met. If you publish OpenAPI and every consumer generates from it, provider-side testing covers the contract. If you have consumers with expectations the spec doesn't capture, keep Pact for those.

**Does it work with a generated spec, like API Platform's?**
Yes. Gesso loads a spec file; it does not care how the file was produced. OpenAPI documents generated by other tools — including API Platform — are treated the same as hand-authored ones, so long as the output is a valid OpenAPI 3.0/3.1/3.2 document on disk.

**What about performance in a large test suite?**
The parsed spec is cached and reused across assertions in the same test run. Validation cost per assertion scales with response body size and schema depth, not the total number of tests.

**Does it validate requests too, or only responses?**
Both, but with separate calls. `assertClientMatchesOpenApiSchema($client)` validates the outbound request and then the response of the last client call. `assertResponseMatchesOpenApiSchema($request, $response)` checks only the response — the `Request` is there to supply the method and path. Use `assertRequestMatchesOpenApiSchema($request)` for the request side of hand-built pairs.

**Which OpenAPI versions are supported?**
3.0.x, 3.1.x, and 3.2.x. `oneOf`/`anyOf`/`allOf`/`not` are validated in every supported dialect, and `discriminator` mappings are enforced by default rather than treated as a hint. OpenAPI 3.1/3.2 keep native JSON Schema 2020-12 semantics — `const`, `prefixItems`, `unevaluatedProperties`/`unevaluatedItems`, `dependentSchemas`/`dependentRequired`, `$dynamicRef`/`$dynamicAnchor`. OpenAPI 3.0 runs through a Draft 07 compatibility pipeline, so those 2020-12-only keywords warn instead of being enforced there.

## Next step

The Symfony adapter is one of four; the same core powers Laravel, Pest, and PSR-7 setups. Start from the [Symfony quickstart](quickstarts/symfony.md), or jump to the [Schema-driven fuzzing guide](fuzzing.md) once your happy paths are green.

Source and issue tracker: [github.com/studio-design/gesso](https://github.com/studio-design/gesso). MIT licensed.
