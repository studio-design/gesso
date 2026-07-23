# Pest plugin example

Runnable Laravel + Pest sample for `studio-design/gesso`.
Mirrors the patterns documented in the main README's
[Pest plugin (Laravel)](../../README.md#pest-plugin-laravel) section.

## Run it

> **Pre-release evaluation only.** Run this beta in an isolated branch or CI
> job; Gesso 2 has not reached a stable release.

```bash
cd examples/pest
composer install
vendor/bin/pest
```

All tests should pass. The suite covers response schema validation, request
schema validation, the `skipResponseCodes:` per-call argument, expectation
chaining, and a coverage-tracker smoke assertion.

## What's in here

| Path | Purpose |
|---|---|
| `composer.json` | Wires the library via a `path` repository (`../..`). In your real project, replace it with `composer require --dev "studio-design/gesso:^2.0@beta" pestphp/pest`. |
| `openapi/petstore.json` | Tiny OpenAPI 3.1 spec with three endpoints (`GET /v1/pets`, `POST /v1/pets`, `GET /v1/health`). |
| `phpunit.xml.dist` | Minimal PHPUnit config that registers `OpenApiCoverageExtension` and points it at `openapi/`. |
| `tests/TestCase.php` | Base test case extending Orchestra Testbench, mixing in `ValidatesOpenApiSchema`, declaring sample routes, and resetting library state per test. |
| `tests/Pest.php` | Pest configuration — `uses(TestCase::class)->in('Feature')` so every `it(...)` block under `tests/Feature` inherits the harness. |
| `tests/Feature/PetsContractTest.php` | Sample tests demonstrating the public expectation API. |

The `expect(...)->toMatchOpenApiResponseSchema()` and
`expect(...)->toMatchOpenApiRequestSchema()` expectations are auto-registered
by the library's `composer.json` `autoload.files` entry — there is no
explicit `use` import or boot step in your test files.

## Adapting to your project

In a real Laravel app you don't need most of `tests/TestCase.php` — your
existing `Tests\TestCase` already exists. The only changes the library
asks for are:

1. `use ValidatesOpenApiSchema;` on the test case (or via `uses(...)` in
   `tests/Pest.php`).
2. `OpenApiSpecLoader::configure(...)` once at boot (typically in your
   base test case's `setUp` or via the PHPUnit extension's `spec_base_path`
   parameter).
3. `config('gesso.default_spec', '...')` for the spec
   the suite validates against.

Then the Pest expectations are available everywhere:

```php
expect($response)->toMatchOpenApiResponseSchema();
expect($request)->toMatchOpenApiRequestSchema();
```

See the main [Pest plugin (Laravel)](../../README.md#pest-plugin-laravel)
README section for the full per-call argument reference.
