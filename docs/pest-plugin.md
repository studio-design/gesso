# Pest plugin (Laravel)

Pest tests can use the same validator pipeline as PHPUnit through a custom-expectation plugin that ships in this package. The library runtime stays Pest-free — install Pest as a dev dependency in your project to activate the expectations.

- [Installation](#installation)
- [Wiring the trait into Pest tests](#wiring-the-trait-into-pest-tests)
- [`expect(...)->toMatchOpenApiResponseSchema()`](#expect-tomatchopenapiresponseschema)
- [`expect(...)->toMatchOpenApiRequestSchema()`](#expect-tomatchopenapirequestschema)
- [Constraints (v2)](#constraints-v2)

## Installation

```bash
composer require --dev pestphp/pest:^4.0
```

The plugin is auto-loaded via Composer's `autoload.files`. No further wiring needed at install time. Pest 4 uses PHPUnit 12; PHPUnit 13 remains supported by Gesso's PHPUnit integration but is not compatible with the Pest runner.

## Wiring the trait into Pest tests

Mix `ValidatesOpenApiSchema` into the Pest test suite via `uses(...)->in(...)` in `tests/Pest.php` (or whatever Pest configuration file your project uses). The trait must be on the test class — typically through a base `TestCase` that already extends Laravel's testing harness:

```php
// tests/Pest.php
use Studio\OpenApiContractTesting\Laravel\ValidatesOpenApiSchema;
use Tests\TestCase;

uses(TestCase::class, ValidatesOpenApiSchema::class)->in('Feature');
```

Once wired, `auto_assert` and `auto_validate_request` work exactly as with PHPUnit — every Laravel HTTP helper call validates against the configured spec.

## `expect(...)->toMatchOpenApiResponseSchema()`

Explicit response-side validation reads naturally inside Pest's expectation grammar:

```php
it('lists pets with the documented shape', function () {
    $response = $this->getJson('/api/v1/pets');

    expect($response)->toMatchOpenApiResponseSchema();
});
```

Optional named arguments cover the per-call overrides:

```php
// Pin this single assertion to a different spec (overrides default_spec
// and any #[OpenApiSpec] on the test class for one call only).
expect($response)->toMatchOpenApiResponseSchema(spec: 'admin');

// Skip body validation for specific status codes (regex strings, anchored
// automatically). The standard `skip_response_codes` config still applies;
// these add to it for one call only.
expect($response)->toMatchOpenApiResponseSchema(skipResponseCodes: ['503']);

// Override method / path explicitly when the auto-resolved values from
// app('request') aren't what you want to validate against.
expect($response)->toMatchOpenApiResponseSchema(method: 'GET', path: '/api/v1/pets');
```

The `spec:` override is single-shot: the next assertion in the same test method falls back to attribute / config resolution. Coverage recording, WeakMap dedup, and the `#[SkipOpenApi]` advisory warning all behave the same as the PHPUnit `assertResponseMatchesOpenApiSchema()` flow.

## `expect(...)->toMatchOpenApiRequestSchema()`

Request-side validation accepts the Symfony `Request` Laravel hands out via `app('request')`:

```php
it('accepts a documented request body shape', function () {
    $this->postJson('/api/v1/pets', ['name' => 'Buddy']);

    expect(app('request'))->toMatchOpenApiRequestSchema();
});
```

The same `spec: / method: / path:` keyword arguments are accepted. The request bridge always runs (it bypasses the `auto_validate_request` config gate and `#[SkipOpenApi]`) because the explicit expectation reads as the user's direct intent. The response side warns when `#[SkipOpenApi]` and an explicit `assertResponseMatchesOpenApiSchema()` collide; the request side has no auto-vs-explicit advisory pattern to mirror, so silence on `expect($request)->toMatchOpenApiRequestSchema()` is the deliberate behaviour.

## Constraints (v2)

- **Laravel only**. The expectations require the running Pest test class to use `ValidatesOpenApiSchema`. Standalone (Symfony / framework-less) Pest support against PSR-7 messages is tracked as a follow-up to [#109](https://github.com/studio-design/gesso/issues/109).
- **Pest 4 / PHPUnit 12**. The Pest integration is tested on PHP 8.3, 8.4, and 8.5. Use the regular PHPUnit integration when running PHPUnit 13.
- **Pest discovery contract**. The plugin guards against a missing Pest install at autoload time, so it is safe to leave installed in projects that don't actually use Pest. If `pestphp/pest` is absent, the bootstrap is a true no-op.
