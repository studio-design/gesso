# OpenAPI Contract Testing for PHPUnit

[![CI](https://github.com/studio-design/openapi-contract-testing/actions/workflows/ci.yml/badge.svg)](https://github.com/studio-design/openapi-contract-testing/actions/workflows/ci.yml)
[![Latest Stable Version](https://poser.pugx.org/studio-design/openapi-contract-testing/v)](https://packagist.org/packages/studio-design/openapi-contract-testing)
[![License](https://poser.pugx.org/studio-design/openapi-contract-testing/license)](https://packagist.org/packages/studio-design/openapi-contract-testing)

Framework-agnostic OpenAPI 3.0/3.1 contract testing for PHPUnit **with endpoint coverage tracking**.

Validate your API responses against your OpenAPI specification during testing, and get a coverage report showing which endpoints have been tested.

## Features

- **OpenAPI 3.0 & 3.1 support** — Automatic version detection from the `openapi` field
- **Response validation** — Validates response bodies against JSON Schema (Draft 07 via opis/json-schema). Supports `application/json` and any `+json` content type (e.g., `application/problem+json`)
- **Content negotiation** — Accepts the actual response `Content-Type` to handle mixed-content specs. Non-JSON responses (e.g., `text/html`, `application/xml`) are verified for spec presence without body validation; JSON-compatible responses are fully schema-validated
- **Skip-by-status-code** — Configurable regex list of status codes whose bodies are not validated (default: every `5xx`), reflecting the common convention of not documenting production error responses in the spec. Also available per-request via the fluent `skipResponseCode()` API
- **Endpoint coverage tracking** — Unique PHPUnit extension that reports which spec endpoints are covered by tests
- **Schema-driven request fuzzing** — `ExploresOpenApiEndpoint` trait generates N happy-path request inputs straight from the spec (Schemathesis-style); pairs with auto-assert so every fuzzed call also lights up coverage
- **Path matching** — Handles parameterized paths (`/pets/{petId}`) with configurable prefix stripping
- **Laravel adapter** — Optional trait for seamless integration with Laravel's `TestResponse`
- **Zero runtime overhead** — Only used in test suites

## Why this library?

This library fills a gap left by existing PHP OpenAPI testing tools: **endpoint coverage tracking** and **first-class OpenAPI 3.1 support**, combined with Laravel auto-assert DX. If you already use Spectator and don't need coverage reports, this library won't offer much. If you want to see which endpoints your test suite actually exercises, or you're writing OpenAPI 3.1 specs, this is likely the best choice today.

### Feature comparison (as of 2026-04)

|  | **This library** | [Spectator][spectator] | [league/psr7][league] | [osteel][osteel] | [kirschbaum][kirschbaum] |
| --- | :---: | :---: | :---: | :---: | :---: |
| OpenAPI 3.0 | ✅ | ✅ | ✅ | ✅ | ✅ |
| OpenAPI 3.1 | ✅ | ⚠️ | ❌ | ⚠️ | ⚠️ |
| Response body validation | ✅ | ✅ | ✅ | ✅ | ✅ |
| Request validation (body + params) | ✅ | ✅ | ✅ | ✅ | ✅ |
| Response header validation | ✅ | ⚠️ | ✅ | ✅ | ✅ |
| **Endpoint coverage tracking** | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Schema-driven request fuzzing** | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Skip-by-status-code (default 5xx)** | ✅ | ❌ | ❌ | ❌ | ✅ |
| PHPUnit integration | ✅ | ✅ | ❌ | ⚠️ | ✅ |
| Pest plugin | ❌ | ❌ | ❌ | ❌ | ❌ |
| Laravel auto-assert | ✅ | ✅ | ❌ | ❌ | ✅ |
| Symfony HttpFoundation | ❌ | ❌ | ⚠️ | ✅ | ❌ |
| External `$ref` auto-resolution | ✅ | ✅ | ✅ | ✅ | ✅ |
| YAML spec loading | ✅ | ⚠️ | ✅ | ✅ | ✅ |
| **Auto-inject dummy bearer** | ✅ | ❌ | ❌ | ❌ | ❌ |
| **GitHub Step Summary output** | ✅ | ❌ | ❌ | ❌ | ❌ |

**Legend**: ✅ fully supported · ⚠️ partial, delegated to an underlying library, or not explicitly documented · ❌ not supported

**Methodology**: Cells reflect what each library's public documentation and source explicitly guarantee as of 2026-04-25. Competitor versions checked: Spectator v2.2.0, league/openapi-psr7-validator v0.22, osteel/openapi-httpfoundation-testing v0.14, kirschbaum-development/laravel-openapi-validator v2.0.

[spectator]: https://github.com/hotmeteor/spectator
[league]: https://github.com/thephpleague/openapi-psr7-validator
[osteel]: https://github.com/osteel/openapi-httpfoundation-testing
[kirschbaum]: https://github.com/kirschbaum-development/laravel-openapi-validator

## Requirements

- PHP 8.2+
- PHPUnit 11, 12, or 13
- A PSR-18 HTTP client + PSR-17 request factory (e.g. Guzzle, Symfony HttpClient) — only required when resolving HTTP(S) `$ref`s

## Installation

```bash
composer require --dev studio-design/openapi-contract-testing
```

> **YAML specs require `symfony/yaml`.** It is listed under `suggest` so it isn't installed automatically. If your spec is JSON, you can skip this. If your spec is `.yaml` / `.yml`, add it explicitly:
>
> ```bash
> composer require --dev symfony/yaml
> ```
>
> Without it, the loader throws `InvalidOpenApiSpecException` with a clear "requires symfony/yaml" message the first time it tries to read a YAML file.

## Setup

### 1. Provide your OpenAPI spec

Internal `$ref` (`#/components/schemas/...`) and local-filesystem `$ref` (`./schemas/pet.yaml`, `../shared/error.json`) are resolved automatically — **no pre-bundling required**. Point the loader at your spec's entry file:

```
openapi/
├── root.yaml          # paths reference ./schemas/*.yaml
└── schemas/
    ├── pet.yaml
    └── error.json
```

HTTP(S) `$ref` (`https://example.com/schemas/pet.yaml`) is **opt-in** for security and CI predictability — see [HTTP `$ref` resolution](#http-ref-resolution) below. If you prefer the legacy bundled-spec workflow, the loader still accepts the output of `npx @redocly/cli bundle --dereferenced` unchanged.

### 2. Configure PHPUnit extension

Add the coverage extension to your `phpunit.xml`:

```xml
<extensions>
    <bootstrap class="Studio\OpenApiContractTesting\PHPUnit\OpenApiCoverageExtension">
        <parameter name="spec_base_path" value="openapi/bundled"/>
        <parameter name="strip_prefixes" value="/api"/>
        <parameter name="specs" value="front,admin"/>
    </bootstrap>
</extensions>
```

| Parameter | Required | Default | Description |
|---|---|---|---|
| `spec_base_path` | Yes* | — | Path to bundled spec directory (relative paths resolve from `getcwd()`) |
| `strip_prefixes` | No | `[]` | Comma-separated prefixes to strip from request paths (e.g., `/api`) |
| `specs` | No | `front` | Comma-separated spec names for coverage tracking |
| `output_file` | No | — | File path to write Markdown coverage report (relative paths resolve from `getcwd()`) |
| `console_output` | No | `default` | Console output mode: `default`, `all`, `uncovered_only`, or `active_only` (overridden by `OPENAPI_CONSOLE_OUTPUT` env var) |
| `sidecar_dir` | No | `sys_get_temp_dir()/openapi-coverage-sidecars` | Directory paratest workers drop per-worker JSON sidecars into. Used only under parallel test runners — see [Parallel test runners](#parallel-test-runners) below |

*Not required if you call `OpenApiSpecLoader::configure()` manually.

### 3. Use in tests

#### With Laravel (recommended)

Publish the config file:

```bash
php artisan vendor:publish --tag=openapi-contract-testing
```

This creates `config/openapi-contract-testing.php`:

```php
return [
    'default_spec' => '', // e.g., 'front'

    // Maximum number of validation errors to report per response.
    // 0 = unlimited (reports all errors).
    'max_errors' => 20,

    // Automatically validate every TestResponse produced by Laravel HTTP
    // helpers (get(), post(), etc.) against the OpenAPI spec. Defaults to
    // false for backward compatibility.
    'auto_assert' => false,

    // Regex patterns (without delimiters or anchors) matched against the
    // response status code. Matching codes short-circuit body validation —
    // the test passes and the endpoint is still recorded as covered.
    // Defaults to skipping every 5xx. Set to [] to validate every code.
    'skip_response_codes' => ['5\d\d'],
];
```

Set `default_spec` to your spec name, then use the trait — no per-class override needed:

```php
use Studio\OpenApiContractTesting\Laravel\ValidatesOpenApiSchema;

class GetPetsTest extends TestCase
{
    use ValidatesOpenApiSchema;

    public function test_list_pets(): void
    {
        $response = $this->get('/api/v1/pets');
        $response->assertOk();
        $this->assertResponseMatchesOpenApiSchema($response);
    }
}
```

To use a different spec for a specific test class, add the `#[OpenApiSpec]` attribute:

```php
use Studio\OpenApiContractTesting\Attribute\OpenApiSpec;
use Studio\OpenApiContractTesting\Laravel\ValidatesOpenApiSchema;

#[OpenApiSpec('admin')]
class AdminGetUsersTest extends TestCase
{
    use ValidatesOpenApiSchema;

    // All tests in this class use the 'admin' spec
}
```

You can also specify the spec per test method. Method-level attributes take priority over class-level:

```php
#[OpenApiSpec('front')]
class MixedApiTest extends TestCase
{
    use ValidatesOpenApiSchema;

    public function test_front_endpoint(): void
    {
        // Uses 'front' from class-level attribute
    }

    #[OpenApiSpec('admin')]
    public function test_admin_endpoint(): void
    {
        // Uses 'admin' from method-level attribute (overrides class)
    }
}
```

Resolution priority (highest to lowest) — the first match wins:

| # | Layer | Typical use |
|---|---|---|
| 1 | Method-level `#[OpenApiSpec]` attribute | Per-test override inside a class whose other tests target a different spec |
| 2 | Class-level `#[OpenApiSpec]` attribute  | Default spec for a class whose tests all hit the same API surface |
| 3 | `openApiSpec()` method override          | Class-specific spec without the attribute (e.g. dynamically chosen at runtime) |
| 4 | `config('openapi-contract-testing.default_spec')` | Project-wide default set once in `config/openapi-contract-testing.php` |

Concrete example where all four layers are populated (class name differs from the earlier `MixedApiTest` example so both snippets can coexist in one project):

```php
use Studio\OpenApiContractTesting\Attribute\OpenApiSpec;

// config/openapi-contract-testing.php → ['default_spec' => 'front']   (layer 4)

#[OpenApiSpec('admin')]                                             // layer 2
class AllLayersPriorityTest extends TestCase
{
    use ValidatesOpenApiSchema;

    protected function openApiSpec(): string                        // layer 3
    {
        return 'internal';
    }

    public function test_uses_class_attr(): void
    {
        // Resolves to 'admin' — layer 2 wins over layer 3 and layer 4.
    }

    #[OpenApiSpec('experimental')]                                  // layer 1
    public function test_uses_method_attr(): void
    {
        // Resolves to 'experimental' — layer 1 wins over all lower layers.
    }
}
```

If every layer is absent (no attributes, `openApiSpec()` not overridden, and `default_spec` empty), the assertion fails with a message that points at each opt-in location:

```
openApiSpec() must return a non-empty spec name, but an empty string was returned.
Either add #[OpenApiSpec('your-spec')] to your test class or method,
override openApiSpec() in your test class, or set the "default_spec" key
in config/openapi-contract-testing.php.
```

> **Note:** `openApiSpec()` remains the original extension hook and is fully backward-compatible — overriding it works exactly as before.

#### Framework-agnostic

You can use the `#[OpenApiSpec]` attribute with the `OpenApiSpecResolver` trait in any PHPUnit test:

```php
use Studio\OpenApiContractTesting\Attribute\OpenApiSpec;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecResolver;
use Studio\OpenApiContractTesting\OpenApiResponseValidator;

#[OpenApiSpec('front')]
class GetPetsTest extends TestCase
{
    use OpenApiSpecResolver;

    public function test_list_pets(): void
    {
        $specName = $this->resolveOpenApiSpec(); // 'front'
        $validator = new OpenApiResponseValidator();
        $result = $validator->validate(
            specName: $specName,
            method: 'GET',
            requestPath: '/api/v1/pets',
            statusCode: 200,
            responseBody: $decodedJsonBody,
            responseContentType: 'application/json',
        );

        $this->assertTrue($result->isValid(), $result->errorMessage());
    }
}
```

Or without the attribute, pass the spec name directly:

```php
use Studio\OpenApiContractTesting\OpenApiResponseValidator;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;

// Configure once (e.g., in bootstrap)
OpenApiSpecLoader::configure(__DIR__ . '/openapi/bundled', ['/api']);

// In your test
$validator = new OpenApiResponseValidator();
$result = $validator->validate(
    specName: 'front',
    method: 'GET',
    requestPath: '/api/v1/pets',
    statusCode: 200,
    responseBody: $decodedJsonBody,
    responseContentType: 'application/json', // optional: enables content negotiation
);

$this->assertTrue($result->isValid(), $result->errorMessage());
```

#### Controlling the number of validation errors

By default, up to **20** validation errors are reported per response. You can change this via the constructor:

```php
// Report up to 5 errors
$validator = new OpenApiResponseValidator(maxErrors: 5);

// Report all errors (unlimited)
$validator = new OpenApiResponseValidator(maxErrors: 0);

// Stop at first error
$validator = new OpenApiResponseValidator(maxErrors: 1);
```

For Laravel, set the `max_errors` key in `config/openapi-contract-testing.php`.

#### Skipping responses by status code

Production error responses (typically `5xx`) are often deliberately left out of the OpenAPI spec. Without special handling, a test that hits a `500` would fail twice: once from the underlying bug, and again from "Status code 500 not defined". To avoid that noise, every `5xx` response is **skipped by default** — body validation is not performed, the assertion passes, and the endpoint is still recorded as covered.

Override via `skip_response_codes` in `config/openapi-contract-testing.php`:

```php
return [
    // Default — skip all 5xx
    'skip_response_codes' => ['5\d\d'],

    // Widen to also skip all 4xx
    'skip_response_codes' => ['4\d\d', '5\d\d'],

    // Disable entirely — validate every status code
    'skip_response_codes' => [],
];
```

Or pass directly to `OpenApiResponseValidator`:

```php
$validator = new OpenApiResponseValidator(skipResponseCodes: ['5\d\d']);
```

Notes:

- Patterns are regex strings **without** `/` delimiters or `^$` anchors; they are anchored automatically, so `5\d\d` matches exactly `500`–`599` (not `5000`).
- The skip check sits **between** the "path / method not in spec" checks and the "status code not defined" / schema-validation checks. A skipped code therefore suppresses both status-code failure modes (undocumented code AND body mismatch for a documented code), but typos in the request path or method still fail loudly.
- Skipped endpoints count as covered — the endpoint was exercised, just not schema-validated. Coverage semantics here match how non-JSON content types and schema-less `204` responses are handled, but `OpenApiValidationResult::isSkipped()` returns `true` **only** for status-code skips; the other no-body-validation branches still return a plain `success()`.
- `OpenApiValidationResult::isSkipped()` is exposed for callers who want to distinguish a skip from a genuine success. `skipReason()` identifies the matched pattern. `outcome()` returns an `OpenApiValidationOutcome` enum (`Success` / `Failure` / `Skipped`) for callers who want exhaustive `match` handling instead of two bool predicates.
- **Observability trade-off**: a real regression that causes an unrelated `500` will not fail this assertion. Keep your HTTP-level assertions (`$response->assertOk()`, status-code expectations in the test) alongside the contract check so a stray 5xx still surfaces — the contract assertion alone is not a substitute for status-code assertions on happy paths.
- **Coverage signal**: skipped responses surface as their own row inside each endpoint's response table — `⚠` (`:warning:` in Markdown) on the per-`(status, content-type)` line, with the matched skip pattern shown inline. The endpoint marker becomes `◐` (partial) when other responses are still validated, or stays `✓` only when every declared response is covered. The response-level rate (`responseCovered / responseTotal`) excludes skipped definitions, so a happy-path regression that silently returns `500` in every test no longer hides behind a 100% endpoint count. `skipReason()` is available on each `OpenApiValidationResult` for callers who want to log the matched pattern from a custom renderer.

#### Auto-assert every response

Forgetting `$this->assertResponseMatchesOpenApiSchema($response)` in a test means the contract is silently unchecked. Enable `auto_assert` to validate every response produced by Laravel's HTTP helpers automatically — just include the trait:

```php
// config/openapi-contract-testing.php
return [
    'default_spec' => 'front',
    'auto_assert'  => true,
];
```

```php
use Studio\OpenApiContractTesting\Laravel\ValidatesOpenApiSchema;

class GetPetsTest extends TestCase
{
    use ValidatesOpenApiSchema;

    public function test_list_pets(): void
    {
        // Contract is checked automatically — no explicit assert call needed.
        $this->get('/api/v1/pets')->assertOk();
    }
}
```

Notes:

- Defaults to `false` so existing test suites keep their explicit-assert behavior.
- Auto-assert hooks into `MakesHttpRequests::createTestResponse()`. Responses you construct manually (outside `$this->get()`, `$this->post()`, etc.) are not touched.
- Idempotency is keyed on the `(spec, method, path)` tuple. Calling `assertResponseMatchesOpenApiSchema($response)` after auto-assert with the matching signature is a no-op. Calling it with a different `method`/`path` — or a different `#[OpenApiSpec]` — runs validation again.
- When auto-assert fails, the exception is thrown from inside `$this->get(...)`, so any chained assertion on the same line (`$this->get(...)->assertOk()`) will not run. This is usually what you want — the schema failure takes precedence over status-code checks.
- `auto_assert` accepts boolean-compatible values (`true`/`false`/`"1"`/`"0"`/`"true"`/`"false"`) so `'auto_assert' => env('OPENAPI_AUTO_ASSERT')` works. Unrecognized values fail the test loudly with a clear message, not silently.
- Streamed responses (`StreamedResponse`, binary downloads) cause `getContent()` to return `false`, which fails auto-assert with a clear message. If you use `auto_assert=true` on tests that exercise streams, scope the config change per-test or fall back to explicit manual asserts.

#### Opting out with `#[SkipOpenApi]`

Some tests intentionally return responses that violate the spec (error-injection tests, experimental endpoints with a not-yet-finalized contract, etc.). For these, use the `#[SkipOpenApi]` attribute to opt out of auto-assert without turning the feature off globally:

```php
use Studio\OpenApiContractTesting\Attribute\SkipOpenApi;

class ExperimentalApiTest extends TestCase
{
    use ValidatesOpenApiSchema;

    #[Test]
    #[SkipOpenApi(reason: 'endpoint is behind an experimental flag')]
    public function test_experimental_endpoint(): void
    {
        $this->get('/v1/experimental');  // auto-assert is skipped
    }
}
```

The attribute can also be applied at the class level to skip every method in that class. A method-level `#[SkipOpenApi]` fully shadows the class-level one — only the method-level attribute (and its `reason`) is inspected.

Notes:

- `#[SkipOpenApi]` suppresses **auto-assert only**. Explicit calls to `assertResponseMatchesOpenApiSchema()` still run — the assertion is the user's direct intent.
- When auto-assert is skipped and no explicit assertion is made, no coverage is recorded for that request (the endpoint is treated as uncovered in the report). If you call `assertResponseMatchesOpenApiSchema()` explicitly on a skipped test, validation runs and coverage is recorded as usual.
- If a test is marked `#[SkipOpenApi]` and still calls `assertResponseMatchesOpenApiSchema()` explicitly, an advisory warning is written to `STDERR` and a user deprecation is raised to flag the contradictory intent. The assertion is not suppressed — fix the cause by removing either the attribute or the explicit call.
- The attribute is resolved via reflection on the direct class only; a class-level `#[SkipOpenApi]` on an abstract parent is **not** inherited by subclasses. Apply the attribute on each concrete test class (or per method) instead.

#### Per-request skip with `withoutValidation()`

When only a single request should skip validation — e.g., exercising a legacy endpoint during a staged migration — use the fluent `withoutValidation()` API instead of annotating the whole method:

```php
class PetApiTest extends TestCase
{
    use ValidatesOpenApiSchema;

    public function test_legacy_endpoint(): void
    {
        // Only this HTTP call skips validation.
        $this->withoutValidation()
            ->get('/v1/pets/legacy')
            ->assertOk();

        // The next call is validated as usual.
        $this->get('/v1/pets')->assertOk();
    }
}
```

Three scopes are available:

- `withoutValidation()` — skip both request and response validation
- `withoutResponseValidation()` — skip response validation only
- `withoutRequestValidation()` — skip request validation only (active when `auto_validate_request` is on)

Notes:

- The flag applies to **exactly one HTTP call**. It is consumed on the next `$this->get()` / `$this->post()` / etc., then automatically resets — a second consecutive call validates normally.
- Scoped to auto-assert only, like `#[SkipOpenApi]`. An explicit `assertResponseMatchesOpenApiSchema()` call still runs regardless of the flag.
- No coverage is recorded for the skipped request.
- Each method returns `$this`, so both `$this->withoutValidation()->get(...)` and the step-by-step form (`$this->withoutValidation(); $this->get(...);`) work.

#### Per-request status-code skip with `skipResponseCode()`

`withoutValidation()` is all-or-nothing for a request. When you only need to suppress a specific status code — e.g., a flaky `404` while a fixture is being repaired — `skipResponseCode()` adds a one-off skip on top of the config-level set:

```php
class PetApiTest extends TestCase
{
    use ValidatesOpenApiSchema;

    public function test_endpoint_returning_a_documented_404(): void
    {
        // Only this call treats 404 as a skip; other calls keep the default behavior.
        $this->skipResponseCode(404)
            ->get('/v1/pets/missing')
            ->assertNotFound();
    }
}
```

Argument shapes:

- **`int`** — exact match, anchored. `skipResponseCode(500)` matches `"500"` only, never `"5000"` or `"50"`.
- **`string`** — regex pattern (anchored automatically). `skipResponseCode('4\d\d')` matches the entire `4xx` range.
- **`array`** — expanded one level. Mixed types are allowed: `skipResponseCode([404, '5\d\d'])`.
- **Variadic** — multiple positional arguments work too: `skipResponseCode(404, 500, '5\d\d')`.

Notes:

- **Merged** with `skip_response_codes` from config — per-request codes ADD to the config set rather than replacing it. With the default config, `skipResponseCode(404)` skips both `404` and every `5xx`.
- **One HTTP call**: same consumption model as `withoutValidation()`. The codes are consumed on the next auto-assert attempt and reset, so the next call falls back to the config-level set.
- **Auto-assert only**. Explicit `assertResponseMatchesOpenApiSchema()` calls ignore per-request codes — explicit calls are the user's direct intent.
- **Chainable**: returns `$this`. Multiple chained calls accumulate (`$this->skipResponseCode(404)->skipResponseCode(503)` registers both).

### Auto-validate every request

Request-side contract drift (missing query params, body-shape divergence, absent security headers) goes undetected unless the test explicitly checks for it. Enable `auto_validate_request` to run `OpenApiRequestValidator` against every request Laravel's HTTP helpers dispatch:

```php
// config/openapi-contract-testing.php
return [
    'default_spec'               => 'front',
    'auto_validate_request'      => true,
    'auto_inject_dummy_bearer'   => true, // optional — see below
];
```

Validation covers path / query / header parameters, request body (JSON Schema), and security schemes. Failures raise a PHPUnit assertion error from inside the HTTP call, exactly like `auto_assert`.

Notes:

- Independent of `auto_assert` — either side can be enabled on its own. Both default to `false` for backward compatibility.
- `withoutRequestValidation()` and `#[SkipOpenApi]` both opt a single call (or a whole test) out of request validation, with the same per-request semantics already documented for response-side auto-assert.
- `auto_validate_request` accepts boolean-compatible values (`"true"`, `"1"`, etc.) like `auto_assert`. Unrecognized values fail the test loudly.
- Coverage is recorded for every matched request path, so enabling auto-validate-request without auto-assert still lights up your coverage report.

#### Auto-inject dummy bearer

When `auto_validate_request=true`, endpoints whose spec declares `bearerAuth` fail the security check unless the test supplies an `Authorization: Bearer …` header. For test suites that authenticate via `actingAs()` or middleware bypass — and therefore never set the header — set `auto_inject_dummy_bearer=true` to auto-inject `Authorization: Bearer test-token` into the validator's view of the request:

```php
class SecureEndpointTest extends TestCase
{
    use ValidatesOpenApiSchema;

    public function test_secure_endpoint(): void
    {
        $this->actingAs(User::factory()->create());

        // No header set, but auto-inject lets request validation pass.
        $this->get('/v1/secure/bearer')->assertOk();
    }
}
```

Notes:

- **View-only rewrite**: the Symfony `Request` itself is not modified; Laravel has already dispatched by the time the trait runs. The inject exists purely to prevent the security check from false-failing.
- **Bearer only**: `apiKey` and `oauth2` endpoints are not affected (the header name for `apiKey` is arbitrary per spec; `oauth2` is classified as unsupported anyway).
- **Never overrides user values**: if the test already set an `Authorization` header (in any case), the user's value wins.
- **Requires `auto_validate_request=true`** — the inject is a sub-feature of request validation. Setting the inject flag alone has no effect.

## Schema-driven request fuzzing

The `ExploresOpenApiEndpoint` trait generates N happy-path request inputs for one (method, path) operation directly from the OpenAPI spec — the PHP equivalent of [Schemathesis][schemathesis]. Pair it with the existing `ValidatesOpenApiSchema` trait and every fuzzed call automatically asserts response contract conformance and records coverage.

```php
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Laravel\ExploresOpenApiEndpoint;
use Studio\OpenApiContractTesting\Laravel\ValidatesOpenApiSchema;
use Studio\OpenApiContractTesting\Attribute\OpenApiSpec;

#[OpenApiSpec('front')]
class CreatePetTest extends TestCase
{
    use ExploresOpenApiEndpoint;
    use ValidatesOpenApiSchema;

    public function test_create_pet_contract(): void
    {
        $this->exploreEndpoint('POST', '/v1/pets', cases: 50, seed: 1)
            ->each(fn ($input) => $this->postJson('/api/v1/pets', $input->body)
                ->assertSuccessful());
    }
}
```

What you get per case (`Studio\OpenApiContractTesting\Fuzz\ExploredCase`):

| Property | Description |
|--------|-------------|
| `body` | Generated JSON body (or `null` when the operation has no `application/json` requestBody) |
| `query` | name → value for every `in: query` parameter |
| `headers` | name → value for every `in: header` parameter (excludes the OpenAPI-reserved `Accept`/`Content-Type`/`Authorization`) |
| `pathParams` | name → value for every `{placeholder}` segment |
| `method`, `matchedPath` | The resolved spec template (`/v1/pets/{petId}`) and its method |

The collection is `Countable` and `IteratorAggregate`, so `foreach ($cases as $case)` works too if you prefer it over the fluent `each()` helper.

### Generation behaviour

- Supported keywords: `type` (`string`/`integer`/`number`/`boolean`/`object`/`array`/`null`), `enum`, `format` (`email`/`uuid`/`date`/`date-time`/`uri`/`hostname`/`ipv4`/`ipv6`), `minLength`/`maxLength`, `minimum`/`maximum`, `required`, `properties`, `items`.
- Optional object properties alternate between included and omitted across cases, so each batch exercises both required-only and required+optional shapes.
- Required keys are always emitted.
- Path resolution accepts both the spec template form (`/v1/pets/{petId}`) and concrete URIs that match it (`/api/v1/pets/123` with `strip_prefixes=/api`). Captured URI values are intentionally discarded — `pathParams` is always regenerated from the operation spec for consistency.

### `seed` and determinism

When [`fakerphp/faker`][faker] is installed (already a transitive dev dependency via `orchestra/testbench` for most projects), generation uses Faker's locale-aware primitives and is fully deterministic for a given `seed:`. Without Faker, the trait falls back to deterministic counter-based primitives that still pass schema validation — your CI never depends on a runtime-installed package.

```bash
# Optional but recommended for realistic generation
composer require --dev fakerphp/faker
```

### Out of scope (today)

The MVP intentionally targets happy-path generation. Tracked separately:

- Boundary value injection (min/max-length extremes, Unicode edge cases)
- Negative-case generation (deliberately invalid inputs to assert 4xx responses)
- `oneOf` / `anyOf` / `allOf` composition; regex `pattern`; `multipleOf`; `minItems` / `maxItems`
- Whole-spec auto-exploration (`exploreSpec()` to walk every endpoint)

[schemathesis]: https://github.com/schemathesis/schemathesis
[faker]: https://github.com/FakerPHP/Faker

## Enum drift detection

Runtime contract validation only sees enum values your tests actually return. Two failure modes slip through:

1. **PHP-only values** — a case is added to a PHP enum but the spec is not updated. Existing contract tests catch this only if a test exercises a code path that returns the new value. Untested paths drift silently.
2. **Spec-only values** — a value is added to the spec but no PHP case exists. Runtime validation can never observe this — the value cannot be produced by the implementation.

`EnumDriftAsserter` closes both holes by comparing PHP enum case values against the spec's `enum:` array statically.

### `#[BoundToOpenApiEnum]` — bind a PHP enum to its spec file

```php
use Studio\OpenApiContractTesting\Attribute\BoundToOpenApiEnum;

#[BoundToOpenApiEnum('_shared/components/schemas/enums/NotificationCodeEnum.json')]
enum NotificationCodeEnum: string
{
    case StudioPaymentOld = 'studioPaymentOld';
    case StudioPaymentNew = 'studioPaymentNew';
    // ...
}
```

The path is resolved relative to the configured spec root (`OpenApiSpecLoader::getBasePath()` — the same root used by the bundler and PHPUnit extension). The bound JSON file must contain an `enum:` array, e.g.:

```json
{
  "type": "string",
  "enum": ["studioPaymentOld", "studioPaymentNew"]
}
```

### `EnumDriftAsserter::assertNoDrift()`

Call from any test (or from a dedicated drift-only test) to verify all bound enums match their spec files:

```php
use Studio\OpenApiContractTesting\Schema\EnumDriftAsserter;

public function test_no_enum_drift(): void
{
    EnumDriftAsserter::assertNoDrift([
        \App\Enums\NotificationCodeEnum::class,
        \App\Enums\ValidationErrorCodeEnum::class,
    ]);
}
```

When drift is detected the asserter throws `EnumDriftException` with a structured diagnostic:

```
[OpenAPI Enum Drift] FATAL: 1 enum binding(s) drift from spec.

  App\Enums\NotificationCodeEnum  ->  _shared/components/schemas/enums/NotificationCodeEnum.json
    PHP-only (1): "betaFeature"
    Spec-only (1): "deprecated"

Action: align the PHP enum cases with the spec, or update the spec's enum array.
```

To downgrade drift to a non-fatal warning (matches the `failOnWarning` ergonomic), pass `failOnDrift: false`:

```php
EnumDriftAsserter::assertNoDrift([NotificationCodeEnum::class], failOnDrift: false);
```

The asserter then fires one `E_USER_WARNING` containing the full drift report (every drifting binding aggregated into a single message) instead of throwing — `failOnWarning="true"` in `phpunit.xml` will still fail the run, but explicit warning suppressors will not. For programmatic access without the global error channel, use `detectAll()` (see below) and inspect the returned `EnumDriftReport[]` directly.

### `detectAll()` — inspection without throwing

For dashboards or custom CI summaries that need every report (clean and drifting):

```php
$reports = EnumDriftAsserter::detectAll([NotificationCodeEnum::class]);
foreach ($reports as $report) {
    echo $report->enumFqcn, ' has drift: ', $report->hasDrift() ? 'yes' : 'no', "\n";
}
```

Each `EnumDriftReport` carries `enumFqcn`, `specPath`, `phpOnly`, and `specOnly` as readonly properties.

### Misconfiguration vs drift

`EnumBindingException` is thrown when the comparison cannot be performed at all — missing `#[BoundToOpenApiEnum]`, target is not a backed enum, spec file not found, malformed JSON, `enum` key missing or not an array, or an `enum` array entry is non-scalar (`null` / `bool` / nested arrays — backed PHP enums can only carry `string` or `int`). `$reason` carries an `EnumBindingReason` enum so you can branch programmatically. These errors fire regardless of `failOnDrift` — they are setup mistakes, not drift signals.

### Auto-discovery via the PHPUnit extension

Manually enumerating every bound enum in a test method gets stale fast — a new `#[BoundToOpenApiEnum]` added by another developer slips by silently until someone remembers to update the list. The PHPUnit extension can scan one or more PSR-4 namespace prefixes at bootstrap and run drift checks before any test executes.

Add the opt-in parameters to your `phpunit.xml`:

```xml
<extensions>
    <bootstrap class="Studio\OpenApiContractTesting\PHPUnit\OpenApiCoverageExtension">
        <parameter name="spec_base_path" value="openapi/dist"/>
        <parameter name="enum_drift_enabled" value="true"/>
        <parameter name="enum_drift_scan_namespaces" value="App\Enums,App\Domain\Enums"/>
        <parameter name="enum_drift_fail_on_drift" value="true"/>
    </bootstrap>
</extensions>
```

| Parameter | Default | Behaviour |
|---|---|---|
| `enum_drift_enabled` | `false` | Master opt-in. Empty value (`<parameter name="enum_drift_enabled"/>`) is also treated as `true`, mirroring `min_coverage_strict`. |
| `enum_drift_scan_namespaces` | _none_ | Comma-separated PSR-4 namespace prefixes (whitespace tolerated). Each prefix must match — directly or as a sub-namespace of — an entry in your `composer.json` `autoload.psr-4` map. |
| `enum_drift_fail_on_drift` | `true` | `true` aborts the run with a `[OpenAPI Enum Drift] FATAL` block on stderr (and `GITHUB_STEP_SUMMARY` when set). `false` emits a `WARNING` block but lets PHPUnit continue. |

Discovery walks Composer's classmap first (so `--optimize-autoloader` and `--classmap-authoritative` deployments are covered) and falls back to a recursive PSR-4 directory scan for default dev installs. Only backed enums carrying `#[BoundToOpenApiEnum]` are passed to `EnumDriftAsserter`; pure enums, traits, abstract classes, and unattributed classes in the same directory are silently skipped.

A drift run produces the same diagnostic block documented above:

```
[OpenAPI Enum Drift] FATAL: 1 enum binding(s) drift from spec.

  App\Enums\NotificationCodeEnum  ->  _shared/components/schemas/enums/NotificationCodeEnum.json
    PHP-only (1): "betaFeature"
    Spec-only (1): "deprecated"

Action: align the PHP enum cases with the spec, or update the spec's enum array.
```

In `enum_drift_fail_on_drift="false"` mode the same body is written under a `[OpenAPI Enum Drift] WARNING` header and PHPUnit exits normally — wire `failOnPhpunitWarning` (or your team's preferred CI gate) if you want the warning to fail the build.

Misconfiguration (no namespaces configured, unresolvable namespace prefix, missing Composer ClassLoader, or any `EnumBindingException` raised by a discovered enum) **always** produces a FATAL exit regardless of `enum_drift_fail_on_drift` — these are setup errors that would otherwise hide a real drift signal.

### Known limitations

- **JSON only.** The asserter currently reads the bound enum file with `file_get_contents` + `json_decode`. YAML enum files are not supported in v1; convert them to JSON or extract the enum into a `.json` sidecar.
- **No `$ref` traversal on the bound file.** Unlike `OpenApiSpecLoader::load()`, the asserter does not resolve `$ref` inside the bound JSON. Bind to the leaf file containing the literal `enum:` array.
- **`oneOf` enum unions** (e.g., `code: oneOf: [CommonCode, AdminCode]`) are not yet auto-resolved. Bind each PHP enum to its leaf JSON file directly.
- **`x-enum-varnames` / `x-enum-descriptions`** are not validated. Only the `enum` value array is compared.

## Coverage Report

After running tests, the PHPUnit extension prints a coverage report. The output format is controlled by the `console_output` parameter (or `OPENAPI_CONSOLE_OUTPUT` environment variable).

Coverage is tracked at **`(method, path, statusCode, contentType)` granularity**: a `GET /v1/pets` test that only exercises `200 application/json` does not count `404` or `application/problem+json` as covered. Per-endpoint markers reflect the resolved state across all declared response definitions:

| Marker | Meaning |
|--------|---------|
| `✓` / `:white_check_mark:` | All declared `(status, content-type)` pairs validated |
| `◐` / `:large_orange_diamond:` | Some pairs validated, others uncovered |
| `⚠` / `:warning:` | Pair was skipped (e.g. `5XX` matched the default skip pattern) |
| `✗` / `:x:` | No pair validated for this endpoint |
| `·` / `:information_source:` | Endpoint reached via request-validation but no response asserted |

The report also breaks the coverage rate into two numbers — the strict endpoint rate (all declared responses validated) and the response-level rate (`responseCovered / responseTotal`).

### `default` mode (default)

Shows endpoint summary lines only:

```
OpenAPI Contract Test Coverage
==================================================

[front] endpoints: 12/45 fully covered (26.7%), 8 partial, 25 uncovered
        responses: 38/120 covered (31.7%), 4 skipped, 78 uncovered
--------------------------------------------------
Legend: ✓=validated  ⚠=skipped  ✗=uncovered  ◐=partial  ·=request-only  *=any/no content-type
  ✓ GET /v1/pets  (3/3 responses)
  ◐ POST /v1/pets  (1/2 responses)
  ◐ DELETE /v1/pets/{petId}  (1/2 responses, 1 skipped)
  ✗ PUT /v1/pets/{petId}  (0/2 responses)
```

> Endpoint markers come from a fixed set: `✓` all-covered, `◐` partial (any combination of validated, skipped, uncovered short of full coverage), `·` request-only, `✗` uncovered. The `⚠` marker is reserved for per-response sub-rows (skipped responses), never for endpoint summary lines.

### `all` mode

Shows endpoint summaries with per-response sub-rows. Sub-row whitespace is illustrative — the renderer pads `statusKey` to 5 chars and `contentTypeKey` to 32 chars:

```
[front] endpoints: 12/45 fully covered (26.7%), 8 partial, 25 uncovered
        responses: 38/120 covered (31.7%), 4 skipped, 78 uncovered
--------------------------------------------------
Legend: ✓=validated  ⚠=skipped  ✗=uncovered  ◐=partial  ·=request-only  *=any/no content-type
  ✓ GET /v1/pets  (3/3 responses)
      ✓ 200    application/json                  [12]
      ✓ 400    application/problem+json          [1]
      ✓ 422    Application/Problem+JSON          [1]
  ◐ POST /v1/pets  (1/2 responses)
      ✓ 201    application/json                  [3]
      ✗ 422    application/problem+json          uncovered
  ◐ DELETE /v1/pets/{petId}  (1/2 responses, 1 skipped)
      ✓ 204    *                                 [2]
      ⚠ 5XX    *                                 skipped: status 503 matched skip pattern 5\d\d
```

### `uncovered_only` mode

Shows sub-rows only for partial / uncovered endpoints, keeping fully-covered ones compact:

```
[front] endpoints: 12/45 fully covered (26.7%), 8 partial, 25 uncovered
        responses: 38/120 covered (31.7%), 4 skipped, 78 uncovered
--------------------------------------------------
Legend: ✓=validated  ⚠=skipped  ✗=uncovered  ◐=partial  ·=request-only  *=any/no content-type
  ✓ GET /v1/pets  (3/3 responses)
  ◐ POST /v1/pets  (1/2 responses)
      ✗ 422    application/problem+json          uncovered
  ✗ PUT /v1/pets/{petId}  (0/2 responses)
      ✗ 200    application/json                  uncovered
      ✗ 404    application/problem+json          uncovered
```

### `active_only` mode

Useful for the local TDD loop with a multi-spec setup (e.g. `specs="front,store,admin"`). Specs that no test in this run touched are collapsed to a single line, so a focused single-test run no longer has to scroll past hundreds of `✗ uncovered` rows for unrelated specs. Specs with at least one validated, skipped, or request-only observation render the same one-line-per-endpoint view as `default`:

```
[front] no test activity (373 endpoints, 894 responses in spec)
[store] no test activity (148 endpoints, 312 responses in spec)

[admin] endpoints: 1/72 fully covered (1.4%), 0 partial, 71 uncovered
        responses: 1/172 covered (0.6%), 0 skipped, 171 uncovered
--------------------------------------------------
Legend: ✓=validated  ⚠=skipped  ✗=uncovered  ◐=partial  ·=request-only  *=any/no content-type
  ✓ GET /v2/admin/early_accesses  (1/1 responses)
  ✗ POST /v2/admin/early_accesses  (0/2 responses)
  ...
```

You can set the mode via `phpunit.xml`:

```xml
<parameter name="console_output" value="uncovered_only"/>
```

Or via environment variable (takes priority over `phpunit.xml`):

```bash
OPENAPI_CONSOLE_OUTPUT=uncovered_only vendor/bin/phpunit
```

## Coverage threshold gate

Optional CI gate that fails the run when contract coverage drops below a configured percentage — the contract-testing analogue of PHPUnit's own `--coverage-threshold`. Both metrics are aggregated across every spec listed in `specs=`:

- `min_endpoint_coverage` — percentage of endpoints with **all** declared `(status, content-type)` pairs validated.
- `min_response_coverage` — percentage of `(method, path, status, content-type)` rows validated (the same rate the report calls "responses covered").

Default is **warn-only**: a miss prints `[OpenAPI Coverage] WARN: …` to stderr but the run exits 0. Flip `min_coverage_strict=true` to make a miss fail-fast with exit 1.

```xml
<extensions>
    <bootstrap class="Studio\OpenApiContractTesting\PHPUnit\OpenApiCoverageExtension">
        <parameter name="spec_base_path" value="openapi/bundled"/>
        <parameter name="specs" value="front,admin"/>
        <parameter name="min_endpoint_coverage" value="80"/>   <!-- percent, optional -->
        <parameter name="min_response_coverage" value="60"/>   <!-- percent, optional -->
        <parameter name="min_coverage_strict" value="true"/>   <!-- default false → warn-only -->
    </bootstrap>
</extensions>
```

Failure looks like:

```
[OpenAPI Coverage] FAIL: endpoint coverage 67.4% < threshold 80%.
                         response coverage 71.2% (>= 60%, ok).
```

Out-of-range or non-numeric values produce a `WARNING` to stderr and skip that gate (rather than silently treating the misconfiguration as `0%`).

For paratest / `pest --parallel`, the merge CLI accepts the same options as flags:

```bash
vendor/bin/openapi-coverage-merge \
    --spec-base-path=openapi/bundled \
    --specs=front,admin \
    --min-endpoint-coverage=80 \
    --min-response-coverage=60 \
    --min-coverage-strict
```

<a id="parallel-test-runners"></a>
## Parallel test runners (paratest / Pest `--parallel`)

Coverage state is per-process. Under parallel runners — `brianium/paratest` or
`pest --parallel` (which delegates to paratest) — each worker boots its own
PHPUnit, runs a slice of the suite, and would otherwise emit its own slice
report. Without coordination the `output_file` ends up containing whichever
worker finished last, and the `GITHUB_STEP_SUMMARY` ends up with N partial
reports stacked on top of each other.

The coverage extension solves this with a two-step workflow that mirrors
`phpunit/php-code-coverage`:

1. **Workers** drop a JSON sidecar per process. The extension auto-detects
   paratest by looking at `TEST_TOKEN` (set in every paratest child) and
   short-circuits rendering — no console output, no `output_file` write,
   no `GITHUB_STEP_SUMMARY` append from the worker.
2. **A single merge step** reads the sidecars, union-merges them via the
   same rules `OpenApiCoverageTracker::recordResponse()` applies, and emits
   the combined report.

### Workflow

```bash
# 1. Run tests in parallel — workers write sidecars only.
vendor/bin/pest --parallel --processes=4
# (or `vendor/bin/paratest --processes=4`)

# 2. Merge sidecars into a single coverage report.
vendor/bin/openapi-coverage-merge \
    --spec-base-path=openapi/bundled \
    --specs=front,admin \
    --output-file=coverage-report.md
```

`vendor/bin/openapi-coverage-merge` flags:

| Flag | Default | Description |
|---|---|---|
| `--spec-base-path=<path>` | — (required) | Path to bundled spec directory |
| `--specs=<a,b>` | `front` | Comma-separated spec names |
| `--strip-prefixes=<a,b>` | — | Comma-separated request-path prefixes to strip |
| `--sidecar-dir=<path>` | `sys_get_temp_dir()/openapi-coverage-sidecars` | Where workers wrote sidecars |
| `--output-file=<path>` | — | Markdown report output path |
| `--github-step-summary=<path>` | `$GITHUB_STEP_SUMMARY` | Append Markdown report to this file |
| `--console-output=<mode>` | `default` | `default` / `all` / `uncovered_only` |
| `--min-endpoint-coverage=<pct>` | — | Threshold gate (see [Coverage threshold gate](#coverage-threshold-gate)) |
| `--min-response-coverage=<pct>` | — | Threshold gate at `(method, path, status, content-type)` granularity |
| `--min-coverage-strict` | `false` (warn-only) | Treat threshold misses as exit non-zero |
| `--no-cleanup` | (cleanup is on by default) | Keep sidecar files after merge |

Sidecar dir defaults are deliberately stable — workers and the merge CLI
use the same `sys_get_temp_dir()/openapi-coverage-sidecars` path, so a
trivial CI step has no extra config to keep in sync. Set `sidecar_dir` (in
`phpunit.xml`) and `--sidecar-dir=` (on the merge CLI) to the same custom
path if `sys_get_temp_dir()` is unavailable in your runner.

### Notes

- **Sequential runs are unchanged.** Without `TEST_TOKEN` the extension
  renders inline as before. There is no need to wire the merge CLI into
  non-parallel CI jobs.
- **Worker counts are not exposed by paratest.** A child cannot reliably
  tell how many siblings it has, so the merge has to run as a separate
  step rather than auto-firing from "the last worker." This matches how
  PHPUnit's own coverage merging works (`phpcov merge`).
- **Sidecars are cleaned up by default.** Run with `--no-cleanup` if you
  want to inspect the per-worker JSON for debugging.
- **A failed sidecar write does not fail the test run.** Workers log a
  warning to `STDERR` and let the suite finish — your contract assertions
  already passed; sidecar I/O is a CI artifact concern.
- **Stale sidecars across runs.** Cleanup-on-success removes sidecars after
  every successful merge. If a previous run crashed before the merge step,
  any leftover sidecars in the dir will be picked up by the next merge —
  delete the sidecar dir at the start of CI if you can't trust the previous
  run's exit code.
- **Worker write failures fail the merge loudly.** When a worker can't
  persist its sidecar, it drops a `failed-<token>.json` marker. The merge
  CLI exits non-zero (`FATAL`) when any markers are present, since a missing
  worker would silently under-count coverage.
- **HTTP `$ref` auto-resolution from the merge CLI.** The CLI calls
  `OpenApiSpecLoader::configure()` with only `spec_base_path` and
  `strip_prefixes` — `allowRemoteRefs` cannot be set via CLI flags. If your
  spec uses HTTP(S) `$ref`, run the merge step from a process that calls
  `OpenApiSpecLoader::configure(..., allowRemoteRefs: true, ...)` first
  (e.g. a Composer script), or pre-bundle remote refs offline.

## CI Integration

### GitHub Actions Step Summary

When running in GitHub Actions, the extension **automatically** detects the `GITHUB_STEP_SUMMARY` environment variable and appends a Markdown coverage report to the job summary. No configuration needed.

> **Note:** Both features are independent — when running in GitHub Actions with `output_file` configured, the Markdown report is written to both the file and the Step Summary.

### Markdown output file

Use the `output_file` parameter to write a Markdown report to a file. This is useful for posting coverage as a PR comment:

```xml
<extensions>
    <bootstrap class="Studio\OpenApiContractTesting\PHPUnit\OpenApiCoverageExtension">
        <parameter name="spec_base_path" value="openapi/bundled"/>
        <parameter name="specs" value="front,admin"/>
        <parameter name="output_file" value="coverage-report.md"/>
    </bootstrap>
</extensions>
```

You can also use the `OPENAPI_CONSOLE_OUTPUT` environment variable in CI to show uncovered endpoints in the job log:

```yaml
- name: Run tests (show uncovered endpoints)
  run: vendor/bin/phpunit
  env:
    OPENAPI_CONSOLE_OUTPUT: uncovered_only
```

Example GitHub Actions workflow step to post the report as a PR comment:

```yaml
- name: Run tests
  run: vendor/bin/phpunit

- name: Post coverage comment
  if: github.event_name == 'pull_request' && hashFiles('coverage-report.md') != ''
  uses: marocchino/sticky-pull-request-comment@v2
  with:
    path: coverage-report.md
```

<a id="http-ref-resolution"></a>
## HTTP `$ref` resolution (opt-in)

Local `$ref` is resolved automatically. HTTP(S) `$ref` is **disabled by default**: a spec containing `$ref: 'https://example.com/pet.yaml'` rejects with `RemoteRefDisallowed` until you opt in. This keeps tests offline-by-default and prevents an attacker-controlled spec from making the test runner reach arbitrary URLs.

To enable HTTP refs, install a PSR-18 client + PSR-17 request factory and pass them along with `allowRemoteRefs: true`:

```bash
# Install your preferred PSR-18 client (Guzzle 7+ shown; Symfony HttpClient + adapter, Buzz,
# or any other PSR-18 implementation works the same).
composer require --dev guzzlehttp/guzzle
```

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Studio\OpenApiContractTesting\Spec\OpenApiSpecLoader;

OpenApiSpecLoader::configure(
    basePath: 'openapi/',
    httpClient: new Client(),       // PSR-18 ClientInterface
    requestFactory: new HttpFactory(), // PSR-17 RequestFactoryInterface
    allowRemoteRefs: true,
);
```

The library does not bundle an HTTP client — pick whichever your project already uses. (Guzzle 7+ implements PSR-18 directly; Guzzle 6 needs an adapter.)

Misconfiguration is caught early:

| Setup | Result |
| --- | --- |
| `allowRemoteRefs: true` without `$httpClient` / `$requestFactory` | `InvalidArgumentException` at `configure()` |
| `$httpClient` set but `allowRemoteRefs: false` | `InvalidArgumentException` at `configure()` (silent misuse impossible) |
| `allowRemoteRefs: true` + client + ref to URL that 4xx/5xx | `InvalidOpenApiSpecException` with reason `RemoteRefFetchFailed` |
| `allowRemoteRefs: true` + client + ref to URL that 3xx | `RemoteRefFetchFailed` with redirect target — configure your PSR-18 client to follow redirects, or use the canonical URL |
| `allowRemoteRefs: true` + client + ref to URL with no detectable format | reason `UnsupportedExtension` (URL extension or `Content-Type` header is required) |

Format detection prefers the URL's filename extension (`.json` / `.yaml` / `.yml`) and falls back to the response's `Content-Type` (`application/json`, `application/*+json`, `application/yaml`, `text/yaml`, etc.). URLs without a recognisable extension still work as long as the server sets a usable `Content-Type`.

Inside an HTTP-loaded document, relative `$refs` resolve against the URL per RFC 3986: a `$ref: './pet.yaml'` inside `https://example.com/openapi.json` fetches `https://example.com/pet.yaml`.

## OpenAPI 3.0 vs 3.1

The package auto-detects the OAS version from the `openapi` field and handles schema conversion accordingly:

| Feature | 3.0 handling | 3.1 handling |
|---|---|---|
| `nullable: true` | Converted to type array `["string", "null"]`; `null` appended to `enum` if present | Not applicable (uses type arrays natively) |
| `prefixItems` | N/A | Converted to `items` array (Draft 07 tuple) |
| `$dynamicRef` / `$dynamicAnchor` | N/A | Removed (not in Draft 07) |
| `examples` (array) | Removed (Draft 2020-12 keyword, not Draft 07) | Removed (Draft 2020-12 keyword, not Draft 07) |
| `const` | N/A | Lowered to `enum: [value]` so opis Draft 07 enforces it |
| `readOnly` / `writeOnly` | Semantic enforcement (see below). Forbidden properties become boolean `false` subschemas; the keyword is dropped as OAS-only on surviving properties | Semantic enforcement (see below). Forbidden properties become boolean `false` subschemas; the keyword is preserved on surviving properties (valid in Draft 07) |

### `readOnly` / `writeOnly` enforcement

Both validators apply OpenAPI's asymmetric semantics instead of letting the keywords pass as no-ops:

- **Response validation** (`OpenApiResponseValidator`, Laravel trait): any property marked `writeOnly: true` must **not** appear in the response body. If it does, validation fails with the offending property named in the error. A `writeOnly + required` entry is treated as absent on the response side, so a compliant response that omits the property still validates.
- **Request validation** (`OpenApiRequestValidator`): any property marked `readOnly: true` must **not** appear in the request body. `readOnly + required` is treated as absent on the request side, so a compliant request that omits the property still validates.

Detection looks at each property schema's own top-level `readOnly` / `writeOnly`; markers nested inside the property's `allOf` / `oneOf` / `anyOf` children are not enforced in the current release.

## Supported features and known limitations

This is a contract-testing tool: where we can't enforce a constraint precisely, we prefer a loud failure or an explicit "skipped" outcome over silently accepting non-compliant data. The list below pins down what does and does not get checked so you can decide whether the gaps matter for your spec.

### Body validation
- **Validated**: `application/json` and any `+json` structured-syntax suffix (RFC 6838), and content keys using ranges (`application/*`, `*/*`) — the matcher tries exact match first, then `<type>/*`, then `*/*`.
- **Multi-JSON-per-status specs** (e.g. `application/json` + `application/problem+json` for the same status): when the actual response Content-Type is supplied, schema validation prefers the spec key that exactly matches the response Content-Type before falling back to the first JSON key. A problem-details body served as `application/problem+json` is judged against its own schema, not the success-shape `application/json` schema. Vendor `+json` suffixes the spec doesn't enumerate (e.g. `application/vnd.example.v1+json`) still fall through to the first JSON key, preserving the legacy interchangeable-JSON behaviour for that case.
- **Presence-only** (no schema validation): every other media type, including `application/xml`, `multipart/form-data`, `application/x-www-form-urlencoded`, `text/plain`, and `application/octet-stream`. The validator confirms the spec declares the content type but does not check the body. The orchestrator marks these responses as `Skipped` for coverage reporting.
- **Multipart `encoding` object**: per-part `contentType` / `headers` / `style` / `explode` are not consulted.
- **Cascading `additionalProperties: false` errors** are stripped automatically. opis's `PropertiesKeyword` skips its `addCheckedProperties()` call whenever any sub-property fails its schema, leaving `$checked` empty in the validation context. The follow-on `additionalProperties: false` keyword then reports every property the data carries — including ones explicitly declared in the schema's `properties` — as "additional". The validator walks opis's `ValidationError` tree, reads the raw list of "additional" property names from `args()['properties']`, and filters out names that ARE declared in the schema's `properties` keyword at that path. A single failure shows as one error, not a paired pseudo-error naming declared properties as not-allowed. Genuine additional properties still surface; mixed cases keep only the real extras in the message. The property-name comparison is fully structural (raw arrays + raw path segments — no string parsing of the rendered message for the names), so property names containing commas, whitespace, empty strings, or JSON-Pointer-escape-worthy characters survive correctly. The walker also descends through `items` for array-element segments, so cascades through `{ data: [Item] }`-shaped envelopes (single-schema items and Draft 07 tuple-form items, including the shape `OpenApiSchemaConverter` lowers OAS 3.1 `prefixItems` to) collapse the same way. The walker recognises only `properties.<name>` and `items` transitions and treats every other shape as unresolvable — composition keywords (`oneOf` / `allOf` / `anyOf`), `additionalProperties: <schema>`, `patternProperties`, `additionalItems`, and boolean schemas at item level all fall through to keeping the original message untouched, so a real additional-property violation is never silently swallowed.

### Parameter styles
- **Query**: only `style: form` + `explode: true` (the OAS default). Specs declaring `pipeDelimited`, `spaceDelimited`, `deepObject`, or `form` + `explode: false` are not parsed; type-mismatch errors will surface but they will point at the wrong cause.
- **Header / Path**: only `style: simple` for scalar values. `type: array` and `type: object` parameters are not parsed (the raw string is fed to the schema, which then mismatches). `style: matrix` and `style: label` for path parameters are not handled — the prefix is not stripped before validation.
- **Cookie parameters** (`apiKey` security scheme aside): not validated.
- **`parameters[].content`**: only `parameters[].schema` is read.

### Security schemes
- **Validated**: `apiKey` (in `header` / `query` / `cookie`) and `http` + `bearer` — presence checks for the named header/query/cookie / RFC 6750 `Bearer` token.
- **Loud `E_USER_WARNING` on first encounter**: `oauth2`, `openIdConnect`, `mutualTLS`, and `http` schemes other than `bearer` (`basic`, `digest`). When every scheme in a security requirement is unsupported the requirement still passes (false-negative avoidance — blocking the test for a spec we cannot evaluate is worse than letting it through), but the validator fires a one-shot per-scheme-name warning so the silent pass does not stay invisible. The warning is emitted as a single line (shown wrapped here for readability):

  ```
  [security] OAuth2 scheme 'oauth2_user' is silently passed (no token check) — POST /v1/users. The opis/json-schema-based validator cannot verify oauth2 / openIdConnect / mutualTLS / http-basic / http-digest credentials. Your test will not detect a missing or invalid token. Workaround: split the bearer-token surface into a separate test, or assert the Authorization header presence manually.
  ```

  Under `phpunit.xml` `failOnWarning="true"` this surfaces as a test failure on first encounter — the recommended setting if your spec contains any of these scheme types, since green tests against unauthenticated requests are the worst-class silent failure for a contract-testing tool.

### Schema features
- **Validated** (delegated to opis Draft 07): `type`, `enum`, `multipleOf`, `minimum`/`maximum`/`exclusiveMinimum`/`exclusiveMaximum`, `minLength`/`maxLength`/`pattern`, `minItems`/`maxItems`/`uniqueItems`, `minProperties`/`maxProperties`/`required`, `additionalProperties` (`true` / `false` / schema), `allOf` / `oneOf` / `anyOf` / `not`.
- **`format`** (validated by opis Draft 06+): the canonical 19-entry set (`email`, `uuid`, `date`, `date-time`, `uri`, `ipv4`, `ipv6`, `hostname`, `regex`, `json-pointer`, …). The full list is the authoritative `KNOWN_OPIS_FORMATS` constant in `src/Spec/OpenApiSchemaConverter.php` — keeping it in one place avoids drift when opis adds formats. Unknown values (e.g. `format: emial` typo for `email`) emit a one-shot `E_USER_WARNING` per format value, since opis silently accepts any value for unrecognised formats. Non-string `format` values fire a separate malformed-spec warning.
- **Advisory `format`** (deliberately not enforced, no warning): `int32`, `int64`, `float`, `double`, `byte`, `binary`, `password`. Treated as documentation hints per OAS conventions; see `ADVISORY_FORMATS` constant.
- **Lowered**: `const` → `enum: [value]` (3.1).
- **Stripped**: `discriminator` (including `mapping`), `xml`, `externalDocs`, `example` / `examples`, `deprecated`, OAS-only `nullable`/`readOnly`/`writeOnly` after enforcement (3.0), and Draft 2020-12 keys `$dynamicRef` / `$dynamicAnchor` / `contentSchema` (3.1).
- **Validated via opis (Draft 06+)**: `patternProperties`, `contentMediaType`, `contentEncoding`. These are JSON Schema keywords that opis implements natively, so your constraints are enforced.
- **Not supported (loud E_USER_WARNING when first encountered)**: `unevaluatedProperties`, `unevaluatedItems`. These are 2019-09 keywords with no Draft 07 equivalent — opis silently ignores them, so the warning surfaces specs that depend on them. Rewrite using `additionalProperties: false` plus explicit `properties` to enforce object closure.
- **`discriminator`**: the keyword is dropped; the underlying `oneOf` / `anyOf` is still validated as a union, but `discriminator.mapping` does not steer validation toward a single branch. When `mapping` is non-empty the converter emits a one-shot `E_USER_WARNING` so polymorphic specs with serialiser bugs surface as a loud signal rather than silently passing through any valid branch.
- **`readOnly` / `writeOnly`**: enforced at the property's own top level only (see [readOnly / writeOnly enforcement](#readonly--writeonly-enforcement)).

### HTTP methods
The PHPUnit coverage report counts `GET`, `POST`, `PUT`, `PATCH`, `DELETE`. Operations under `HEAD`, `OPTIONS`, and `TRACE` are not part of the coverage allowlist, and the Laravel auto-validation hook silently skips them (it normalises the request method through `HttpMethod::tryFrom()`, which returns `null` for these). Direct calls to `OpenApiResponseValidator::validate()` with one of these method strings will resolve against the spec — but if you depend on coverage tracking or the Laravel trait, treat HEAD / OPTIONS / TRACE as out of scope today.

### Spec features not consulted
Webhooks (3.1), Callbacks, Response `Links`, Server URL templating (`servers` with `variables`), Examples (`examples` blocks at parameter / requestBody / response level — not used for fuzzing or validation), `tags`, `externalDocs`, vendor extensions (`x-*` keys, ignored harmlessly).

### Warning channel (`E_USER_WARNING` contract)

The library uses PHP's native `trigger_error(..., E_USER_WARNING)` as the loud-signal channel for silent-pass conditions the validator cannot enforce. **This is the v1.0 official API**: warnings are dedup'd per-process and prefixed with a category tag so callers can route or filter them mechanically.

| Category prefix | Source | Dedup key |
|---|---|---|
| `[security]` | `SecurityValidator` (`oauth2`, `openIdConnect`, `mutualTLS`, `http-basic`, `http-digest`) | scheme name |
| `[OpenAPI Schema]` | `OpenApiSchemaConverter` (`unevaluatedProperties` / `unevaluatedItems`, `discriminator.mapping`, unknown / malformed `format`) | per-keyword / per-format-value |

**How to consume:**

- **Default** (PHPUnit `failOnWarning="true"`): the first warning fails the test. Recommended for contract-testing pipelines, since silent-pass on auth or unknown formats is the worst-class failure mode.
- **Stay green, surface warnings in output**: omit `failOnWarning` (PHPUnit 10+ default is `false`). Warnings show in the test report but do not fail.
- **Capture programmatically** (e.g. for a custom report):
  ```php
  set_error_handler(static function (int $errno, string $errstr): bool {
      if ($errno === E_USER_WARNING && str_starts_with($errstr, '[security]')) {
          MyReport::record($errstr);
          return true; // suppress
      }
      return false; // bubble
  });
  ```
- **Suppress one category** (e.g. acknowledged limitation): match on the category prefix in your error handler. Do not blanket-suppress all `E_USER_WARNING`s — unrelated warnings would silently disappear.

**Why not exceptions / PSR-3 logger / structured payload on `OpenApiValidationResult`?** The simple channel is zero-dep, integrates with every PHP framework's existing error handler, and stays out of the v1.0 SemVer surface. A structured channel (`WarningCollector`, PSR-3 sink, or `result->warnings()`) can be added in v1.x as additive without breaking — we are deliberately deferring until real-world usage demands it. See [issue #149](https://github.com/studio-design/openapi-contract-testing/issues/149) for the design discussion.

**Per-process dedup vs per-test:** the dedup state is process-global. PHPUnit runs all tests in one process by default, so a warning fired in test A is not fired again in test B even if both schemas exhibit the issue. The `*::resetWarningStateForTesting()` helpers (annotated `@internal`) exist as test seams for the converter / security validator's own tests; downstream tests rarely need them.

## Versioning and support policy

This library follows [Semantic Versioning 2.0](https://semver.org/). v1.0.0 is the API stability commitment: anything not marked `@internal` in v1.0.0 is covered by SemVer for the entire v1.x line.

### What's covered by SemVer in v1.x

- Public class names and namespaces (anything not marked `@internal`)
- Public method signatures (parameters, return types, visibility)
- Public constants and their values
- Enum cases (additions are minor; removals or renames are major)
- The `OpenApiValidationResult` shape (`outcome()`, `errors()`, `matchedPath()`, `skipReason()`, `isValid()`, `isSkipped()`)
- The CLI surface of `bin/openapi-coverage-merge` (flags, exit codes, sidecar JSON wire format via `STATE_FORMAT_VERSION`)
- The `OpenApiCoverageExtension` PHPUnit configuration parameters (`spec_base_path`, `strip_prefixes`, `specs`, `output_file`, `console_output`, …)
- The Laravel `ValidatesOpenApiSchema` trait's public methods
- The category prefixes used in `E_USER_WARNING` messages (`[security]`, `[OpenAPI Schema]`)

### What's NOT covered by SemVer

- Anything marked `@internal` — including the `Internal\` and `Validation\Support\` namespaces, the per-validator helpers under `Validation\Request\` / `Validation\Response\`, `Spec\OpenApiSchemaConverter` / `Spec\OpenApiPathMatcher` / `Spec\OpenApiRefResolver` / `Spec\OpenApiPathSuggester`, the PHPUnit `CoverageReportSubscriber`, `Coverage\CoverageMergeCommand` (the `bin/openapi-coverage-merge` CLI itself remains covered — the class is the implementation detail behind it), and test seams (`*::resetWarningStateForTesting()`, `OpenApiSpecLoader::reset()`, `OpenApiCoverageTracker::reset()` / `exportState()` / `importState()`).
- Validator error message wording (we may improve them; assert on presence/category, not on exact strings).
- The set of `format` keywords delegated to opis — we follow opis upstream, so a new format is added when opis adds it.
- Behaviour of bug-fix releases that close a documented silent-pass case. A test that passed only because of the silent pass may start failing — that's the fix doing its job, not a SemVer break.

`@internal` is enforced statically. Our CI runs PHPStan (pinned to `^2.1.13`) with the `bleedingEdge` ruleset enabled so that `new.internalClass` / `method.internalClass` / `staticMethod.internalClass` / `return.internalClass` / `parameter.internalClass` / `classConstant.internalClass` / `catch.internalClass` violations fail the build. The boundary is the **root namespace** — any code outside `Studio\` that instantiates, calls, type-hints against, or accesses constants on an `@internal` symbol will surface as a PHPStan error. Downstream consumers who enable bleedingEdge in their own PHPStan setup get the same enforcement automatically. Inheritance (`extends`/`implements`) of `@internal` classes is **not** enforced by these rules — that ships under a separate bleedingEdge rule we have not opted into yet. The `bin/openapi-coverage-merge` CLI script is the only place inside this repository that crosses the boundary by design (it lives in the global namespace and instantiates `Coverage\CoverageMergeCommand`); it is excluded from PHPStan's `paths` so it does not pollute the analysis.

See [UPGRADING.md](UPGRADING.md) for migration notes between versions.

### Support policy

| Component | Supported |
| --- | --- |
| PHP runtime | 8.2, 8.3, 8.4 (CI matrix). PHP 8.2 is supported until its security-EOL (2026-12); a SemVer-major bump may drop it after that. |
| PHPUnit | 11.x, 12.x, 13.x (CI matrix). New stable PHPUnit majors are added to the matrix; older majors are dropped in a SemVer-major bump. |
| `opis/json-schema` | `^2.6` for v1.x. A jump to `^3` would be a SemVer-major. |
| Laravel (optional adapter) | Whatever `orchestra/testbench` `^9 || ^10 || ^11` supports. |

Bug fixes and security updates land on the latest minor of v1.x. There is no LTS branch for older minors — upgrade to the latest minor to receive fixes.

## API Reference

### `OpenApiResponseValidator`

Main validator class. Validates a response body against the spec.

The constructor accepts a `maxErrors` parameter (default: `20`) that limits how many validation errors the underlying JSON Schema validator collects. Use `0` for unlimited, `1` to stop at the first error.

The optional `responseContentType` parameter enables content negotiation: when provided, non-JSON content types (e.g., `text/html`) are checked for spec presence only, while JSON-compatible types proceed to full schema validation.

```php
$validator = new OpenApiResponseValidator(maxErrors: 20);
$result = $validator->validate(
    specName: 'front',
    method: 'GET',
    requestPath: '/api/v1/pets/123',
    statusCode: 200,
    responseBody: ['id' => 123, 'name' => 'Fido'],
    responseContentType: 'application/json',
);

$result->outcome();      // OpenApiValidationOutcome (Success | Failure | Skipped)
$result->isValid();      // bool (true for both successes AND skipped results)
$result->isSkipped();    // bool (true when the status code matched skip_response_codes)
$result->errors();       // string[]
$result->errorMessage(); // string (joined errors)
$result->matchedPath();  // ?string (e.g., '/v1/pets/{petId}')
$result->skipReason();   // ?string (non-null when skipped)
```

Prefer `outcome()` when you need to distinguish all three states explicitly — PHPStan enforces `match` exhaustiveness, so adding a future outcome cannot silently slip past a caller:

```php
use PHPUnit\Framework\AssertionFailedError;
use Studio\OpenApiContractTesting\OpenApiValidationOutcome;

match ($result->outcome()) {
    OpenApiValidationOutcome::Success => null, // schema matched
    OpenApiValidationOutcome::Failure => throw new AssertionFailedError($result->errorMessage()),
    OpenApiValidationOutcome::Skipped => logger()->info('skipped', ['reason' => $result->skipReason()]),
};
```

### `OpenApiSpecLoader`

Manages spec loading and configuration.

```php
OpenApiSpecLoader::configure('/path/to/bundled/specs', ['/api']);
$spec = OpenApiSpecLoader::load('front');
OpenApiSpecLoader::reset(); // For testing
```

### `OpenApiCoverageTracker`

Tracks which endpoints have been exercised, at `(method, path, statusCode, contentType)` granularity. The Laravel trait records via the tracker automatically; framework-agnostic adapters call it directly.

```php
use Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker;

// Request-side: an endpoint was reached without a response assertion
OpenApiCoverageTracker::recordRequest('front', 'GET', '/v1/pets');

// Response-side: full granularity (status + content-type spec keys)
OpenApiCoverageTracker::recordResponse(
    specName: 'front',
    method: 'GET',
    path: '/v1/pets',
    statusKey: '200',                  // spec key, or literal status when skipped
    contentTypeKey: 'application/json',// spec key (case preserved); null → "*"
    schemaValidated: true,             // false → state=skipped
    skipReason: null,
);

$coverage = OpenApiCoverageTracker::computeCoverage('front');
// [
//   'endpoints' => [...per-endpoint EndpointSummary, includes per-response sub-rows...],
//   'endpointTotal' => 45,
//   'endpointFullyCovered' => 12,
//   'endpointPartial' => 8,
//   'endpointUncovered' => 25,
//   'responseTotal' => 120,
//   'responseCovered' => 38,
//   'responseSkipped' => 4,
//   'responseUncovered' => 78,
//   ...
// ]
```

`hasAnyCoverage(spec): bool` is a fast presence check. `getCovered()` is retained as a diagnostic shim returning `array<spec, array<"METHOD path", true>>`. See [CHANGELOG.md](CHANGELOG.md) for the migration from the pre-#111 endpoint-level shape.

## Development

```bash
composer install

# Run tests
vendor/bin/phpunit

# Static analysis
vendor/bin/phpstan analyse

# Code style
vendor/bin/php-cs-fixer fix
vendor/bin/php-cs-fixer fix --dry-run --diff  # Check only
```

## License

MIT License. See [LICENSE](LICENSE) for details.
