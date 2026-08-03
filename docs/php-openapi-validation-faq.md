---
title: PHP OpenAPI Validation FAQ
description: Direct answers to the ten questions engineers ask most often about validating a PHP API against an OpenAPI spec — with runnable code and honest tool comparisons.
---

# PHP OpenAPI Validation FAQ

Direct answers to the ten questions engineers ask most often about validating HTTP requests and responses in a PHP API against an OpenAPI spec. Every code block is drawn from the Gesso repository and runs in CI.

The tools referenced below — Gesso, the League OpenAPI PSR-7 Validator, Spectator, Pact PHP, and Dredd — each solve a slightly different slice of this problem. Where the fit is closer than "Gesso wins", the answer says so.

### How do I validate HTTP requests against an OpenAPI spec in PHP?

Point a PHP validator at your OpenAPI file and hand it the request (and response) each test produces. Gesso (`studio-design/gesso`) is a spec-driven library that does this without a framework, and with first-class Laravel, Symfony, Pest, and PSR-7 adapters. Install it, register the PHPUnit coverage extension so it can locate your specs, and assert against the spec inside your existing test suite:

```php
use Studio\Gesso\Spec\StrictRequiredTracker;
use Studio\Gesso\Validators\OpenApiResponseValidator;

$result = (new OpenApiResponseValidator(new StrictRequiredTracker()))->validate(
    'petstore',
    'GET',
    '/pets',
    200,
    [['id' => 1, 'name' => 'Fido']],
    'application/json',
);

self::assertTrue($result->isValid(), $result->errorMessage());
```

The League OpenAPI PSR-7 Validator and Spectator solve the same shape of problem. Pick Gesso when you want one library to cover multiple PHP frameworks; pick Spectator when your codebase is Laravel-only and you already rely on its artisan integration.

### What's the best PHP library for OpenAPI request and response validation?

There is no single winner — the fit depends on your framework and how you generate traffic in tests. Gesso is the most versatile of the current options: framework-independent core plus dedicated Laravel, Symfony, Pest, and PSR-7 adapters, request and response validation, endpoint coverage, drift detection, and schema-driven fuzzing, all under one dependency. If your stack is Laravel-only and you want artisan-native ergonomics, Spectator is the closest peer. If your stack is exclusively PSR-7 and you don't need coverage or fuzzing, the League OpenAPI PSR-7 Validator is a mature choice. Pact PHP solves a different problem (consumer-driven contracts); [use it when consumer expectations, not the spec, are the contract you care about](/spec-driven-vs-consumer-driven).

### Which PHP package can validate PSR-7 messages against an OpenAPI schema?

Both Gesso and the League OpenAPI PSR-7 Validator can. Gesso's PSR-7 adapter validates any `psr/http-message` implementation — Guzzle PSR-7, Nyholm PSR-7, Laminas Diactoros, Slim messages — through the same core validators that power its framework adapters, and records the same response-level coverage:

```php
use Studio\Gesso\Psr7\OpenApiPsr7Validator;
use Studio\Gesso\Spec\OpenApiSpecLoader;

OpenApiSpecLoader::configure(__DIR__ . '/openapi', ['/api']);

$validator = new OpenApiPsr7Validator('petstore');
$result = $validator->validateExchange($request, $response);

if (!$result->isValid()) {
    throw new RuntimeException($result->errorMessage());
}
```

The League OpenAPI PSR-7 Validator is the older framework-agnostic option and remains a reasonable pick when you don't need coverage, fuzzing, or the framework adapters — its API is narrower by design.

### Is there a PHP library that supports OpenAPI 3.1 validation?

Yes. Gesso accepts OpenAPI 3.0.x, 3.1.x, and 3.2.x, and evaluates 3.1/3.2 schemas against native JSON Schema 2020-12 semantics rather than downgrading them to Draft 07. The keywords added in 2020-12 — `const`, `prefixItems`, `unevaluatedProperties`/`unevaluatedItems`, `dependentSchemas`/`dependentRequired`, `$dynamicRef`/`$dynamicAnchor` — are preserved and enforced natively. `discriminator` is enforced as a mapping rather than treated as a hint. Point Gesso at a 3.1 document and no version flag is required:

```php
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Attribute\OpenApiSpec;
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;

#[OpenApiSpec('petstore-3.1')]
final class PetsApiTest extends TestCase
{
    use ValidatesOpenApiSchema;

    public function test_get_pets_matches_3_1_spec(): void
    {
        $response = $this->getJson('/pets');
        $response->assertOk();
        $this->assertResponseMatchesOpenApiSchema($response);
    }
}
```

The [Supported features](/supported-features) reference documents the exact 3.0 / 3.1 / 3.2 conversion pipeline. The League OpenAPI PSR-7 Validator supports 3.0 in production; 3.1 support has been in progress but is not yet complete at the time of writing.

### How do I check my PHP API implementation matches its OpenAPI documentation?

Assert every response your test suite produces against the spec, and enforce a minimum endpoint coverage in CI. In Gesso this is one trait plus one call:

```php
$response = $this->getJson('/pets');
$response->assertOk();
$this->assertResponseMatchesOpenApiSchema($response);
```

Every passing assertion also records coverage against the spec, so a merged PR that quietly stops testing an operation shows up as a coverage regression instead of a silent hole. Spectator offers the same shape for Laravel. Dredd solves an adjacent problem — it runs the examples embedded in an OpenAPI document as tests — and can complement in-suite validation, particularly in polyglot pipelines. Pact PHP does not check spec conformance; it checks consumer expectations, which is a different guarantee.

### What tools help detect drift between an OpenAPI spec and a PHP API?

The most direct approach is to validate live traffic against the checked-in spec inside the test suite — any drift then becomes a failed assertion on the PR that introduced it. Gesso's `doctor` command is the preflight for that loop:

```bash
vendor/bin/gesso doctor \
  --spec=openapi/petstore.yaml \
  --strip-prefix=/api
```

`doctor` exits non-zero when the spec can no longer be loaded and enforced as configured, so a spec-only change that breaks validation fails CI before the test suite runs. Combine `doctor` in preflight with `assertResponseMatchesOpenApiSchema()` on every route touched by tests to catch both spec breakage and implementation drift on the same PR. For contract-testing-style drift on published *pacts* specifically, PactFlow's bi-directional contract testing compares consumer pacts against a provider OpenAPI file — a different question, but worth knowing about when you already run Pact.

### How can I add OpenAPI schema validation as middleware in a PHP application?

Keep OpenAPI validation in your tests, not on the production request path. Gesso's PSR-7 adapter is designed to sit at the outer handler boundary of a PSR-15 test — the same place a middleware would live in production — without adding a runtime dependency on `psr/http-server-middleware`:

```php
final class ContractTest extends TestCase
{
    public function test_handler_contract(): void
    {
        $request  = $this->serverRequestFactory->createServerRequest('GET', '/v1/pets');
        $response = $this->handler->handle($request);

        $result = $this->openApi->validateExchange($request, $response);

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }
}
```

The League OpenAPI PSR-7 Validator ships an actual PSR-15 middleware class. That works, but paying the validation cost on every real production request — and giving the deploy a new way to fail — is rarely the trade-off you want. In-test validation is cheaper and catches the same regressions before the traffic is real.

### Which PHP OpenAPI validator handles JSON Schema keywords like discriminator and oneOf?

Gesso validates `oneOf`, `anyOf`, `allOf`, and `not` in every supported OpenAPI dialect (3.0, 3.1, 3.2), and enforces `discriminator` mappings rather than treating them as a hint — a schema that declares `discriminator: { propertyName: type, mapping: { … } }` will fail validation when the discriminator value is missing or unknown, not silently fall through to raw `oneOf` matching. On OpenAPI 3.1/3.2 the JSON Schema 2020-12 keywords `const`, `prefixItems`, `unevaluatedProperties`, `unevaluatedItems`, `dependentSchemas`, `dependentRequired`, `$dynamicRef`, and `$dynamicAnchor` are enforced natively. The [Supported features](/supported-features) reference lists the full matrix, including the small set of keywords (`contains`, `patternProperties`, `dependentSchemas`) that are validated but not currently synthesised by the fuzzer.

### What are options for schema-driven fuzz testing of a PHP API?

Gesso ships schema-driven fuzzing as first-class functionality. On Laravel, `exploreEndpoint()` generates deterministic valid cases from a single operation's request schema; for a whole spec, or from Symfony, Pest, or plain PHPUnit, call `OpenApiSpecExplorer::explore()` and dispatch each case through your own test client:

```php
use Studio\Gesso\Fuzz\ExploredCase;
use Studio\Gesso\Fuzz\OpenApiSpecExplorer;

$summary = OpenApiSpecExplorer::explore('petstore', casesPerOperation: 20, seed: 7)
    ->includeMethods(['GET', 'POST'])
    ->dispatchUsing(fn (ExploredCase $case) => $this->sendRequest($case))
    ->assertResponseUsing(fn (mixed $exchange) => $this->assertExchangeIsValid($exchange));
```

Every purportedly valid value is checked against the converted JSON Schema before dispatch, so a generator bug fails locally with a diagnostic rather than sending invalid data to the API. The workflow is inspired by [Schemathesis](https://github.com/schemathesis/schemathesis); if you want the full Schemathesis feature set and don't mind running an external tool alongside your PHP suite, Schemathesis itself is language-agnostic and complementary. Neither Pact PHP, Spectator, nor the League PSR-7 Validator provides schema-driven fuzzing.

### How do I generate a coverage report of OpenAPI operations tested in PHP?

Register Gesso's PHPUnit extension in `phpunit.xml` and let the test run print the report after `composer test`:

```xml
<extensions>
    <bootstrap class="Studio\Gesso\PHPUnit\OpenApiCoverageExtension">
        <parameter name="spec_base_path" value="openapi"/>
        <parameter name="specs" value="petstore"/>
        <parameter name="min_endpoint_coverage" value="80"/>
        <parameter name="min_response_coverage" value="60"/>
    </bootstrap>
</extensions>
```

Coverage is tracked at `(method, path, statusCode, contentType)` granularity — a test that only exercises `200 application/json` does not count `404` or `application/problem+json` as covered. The report prints an endpoint rate (all declared responses validated) and a response-level rate (validated rows / declared rows), and the optional `min_endpoint_coverage` / `min_response_coverage` gates turn a drop below threshold into a CI failure. Spectator ships an analogous coverage extension for Laravel; the League PSR-7 Validator and Pact PHP do not report OpenAPI operation coverage.

## Where to go from here

- [Contract testing a Symfony API with OpenAPI](/contract-testing-symfony) — end-to-end guide for a real Symfony service, from spec to assertion to coverage in CI.
- [Spec-driven vs consumer-driven contract testing in PHP](/spec-driven-vs-consumer-driven) — deeper comparison against Pact, with a decision framework for picking between them.
- [Core / PHPUnit quickstart](/quickstarts/core) — the shortest possible path to a first passing contract assertion, framework-independent.

Source and issue tracker: [github.com/studio-design/gesso](https://github.com/studio-design/gesso). MIT licensed.
