# Setup

This guide walks through end-to-end setup, including the configuration knobs, opt-out mechanisms, and the `auto_validate_request` family. For an at-a-glance quick start, choose a [tested quickstart](quickstarts/core.md).

- [1. Provide your OpenAPI spec](#1-provide-your-openapi-spec)
- [2. Configure the PHPUnit extension](#2-configure-the-phpunit-extension)
- [3. Use in tests](#3-use-in-tests)
  - [With Laravel (recommended)](#with-laravel-recommended)
  - [Framework-agnostic](#framework-agnostic)
- [Controlling the number of validation errors](#controlling-the-number-of-validation-errors)
- [Skipping responses by status code](#skipping-responses-by-status-code)
- [Auto-assert every response](#auto-assert-every-response)
- [Opting out with `#[SkipOpenApi]`](#opting-out-with-skipopenapi)
- [Per-request skip with `withoutValidation()`](#per-request-skip-with-withoutvalidation)
- [Per-request status-code skip with `skipResponseCode()`](#per-request-status-code-skip-with-skipresponsecode)
- [Auto-validate every request](#auto-validate-every-request)
- [Skip request validation when the response is a documented 4xx](#skip-request-validation-when-the-response-is-a-documented-4xx)
- [Auto-inject dummy bearer](#auto-inject-dummy-bearer)
- [HTTP `$ref` resolution (opt-in)](#http-ref-resolution-opt-in)
- [Remote spec sources (opt-in)](#remote-spec-sources-opt-in)

## 1. Provide your OpenAPI spec

Internal `$ref` (`#/components/schemas/...`) and local-filesystem `$ref` (`./schemas/pet.yaml`, `../shared/error.json`) are resolved automatically — **no pre-bundling required**. Point the loader at your spec's entry file:

```
openapi/
├── root.yaml          # paths reference ./schemas/*.yaml
└── schemas/
    ├── pet.yaml
    └── error.json
```

HTTP(S) `$ref` (`https://example.com/schemas/pet.yaml`) is **opt-in** for security and CI predictability — see [HTTP `$ref` resolution](#http-ref-resolution-opt-in) below. If you prefer the legacy bundled-spec workflow, the loader still accepts the output of `npx @redocly/cli bundle --dereferenced` unchanged.

`spec_base_path` is also the filesystem trust boundary for entry documents and
local external references. Entry spec names may include nested directories but
cannot use `..`, an absolute path, or a symlink to escape that root. Gesso also
canonicalizes each local `$ref` target before reading it and rejects the target
when its final location escapes the directory.
When entry documents and shared schemas live in sibling directories, configure
their narrowest trusted common parent and include the entry subdirectory in the
spec name:

```xml
<parameter name="spec_base_path" value="openapi"/>
<parameter name="specs" value="bundled/front"/>
```

Here `openapi/bundled/front.yaml` may safely reference
`../shared/error.json`, while files outside `openapi/` remain inaccessible.
This follows the [OWASP path-traversal guidance](https://owasp.org/www-community/attacks/Path_Traversal)
to canonicalize filesystem input before validating it against an allowed
location.

## 2. Configure the PHPUnit extension

Add the coverage extension to your `phpunit.xml`:

```xml
<extensions>
    <bootstrap class="Studio\Gesso\PHPUnit\OpenApiCoverageExtension">
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
| `output_file` | No | — | File path to write Markdown coverage report (relative paths resolve from `getcwd()`). Skipped on partial runs — see [Partial test runs](ci.md#partial-test-runs-filter-testsuite-path-args-) |
| `junit_output` | No | — | File path to write JUnit XML coverage report (for CI dashboards — GitLab CI, Jenkins, SonarQube, Bitrise). A missing parent directory is created automatically at bootstrap (applies to `json_output` / `html_output` too); an empty value, a failed creation, or an unwritable parent directory is FATAL at bootstrap. See [Coverage output formats](ci.md#coverage-output-formats) |
| `json_output` | No | — | File path to write machine-readable JSON coverage report (custom dashboards, analytics, scripted gating). Schema: [`coverage-json-schema.md`](coverage-json-schema.md) |
| `html_output` | No | — | File path to write self-contained HTML coverage report (PR comments, CI artifact preview, offline review). See [`coverage-html-output.md`](coverage-html-output.md) |
| `console_output` | No | `default` | Console output mode: `default`, `all`, `uncovered_only`, or `active_only` (overridden by `OPENAPI_CONSOLE_OUTPUT` env var) |
| `validation_output` | No | `text` | Validation failure output format for all framework adapters: `text` or `json` (overridden by `OPENAPI_VALIDATION_OUTPUT` env var). See [Validation JSON schema](validation-json-schema.md#selecting-json-failure-output-in-adapters) |
| `baseline_file` | No | — | Violation baseline file path (relative paths resolve from `getcwd()`). Running the suite with `OPENAPI_BASELINE_GENERATE=1` records every contract violation instead of failing and writes the sorted, versioned baseline here at run end (refused on partial runs; parallel workers stage their fingerprints in sidecars for `gesso coverage:merge --baseline-file` to union — see [Generating under parallel runners](baseline.md#generating-under-parallel-runners)). On normal runs the file is loaded (missing or malformed file is FATAL) and failures whose every violation is baselined are suppressed — only **new** violations fail. See the [baseline adoption guide](baseline.md) |
| `baseline_stale` | No | `note` | How baseline entries that no longer occur during a full run are reported: `off` (not evaluated), `note` (listed as removable), or `fail` (listed and the run exits non-zero). Requires `baseline_file`; skipped on partial runs. See [Ratcheting down](baseline.md#ratcheting-down) |
| `sidecar_dir` | No | `sys_get_temp_dir()/openapi-coverage-sidecars` | Directory paratest workers drop per-worker JSON sidecars into. Used only under parallel test runners — see [Parallel test runners](parallel.md) |
| `min_endpoint_coverage` | No | — | Minimum fully covered endpoint percentage (`0`–`100`). See [Coverage threshold gate](coverage.md#coverage-threshold-gate) |
| `min_response_coverage` | No | — | Minimum validated response-row percentage (`0`–`100`) |
| `min_sdk_exercise_coverage` | No | — | Minimum eligible response-schema percentage attempted by SDK decoder callbacks (`0`–`100`) |
| `min_coverage_strict` | No | `false` | When true, any configured coverage-threshold miss exits non-zero; otherwise misses warn only |
| `strict_additional_properties` | No | `off` | Undocumented response-property gate: `off`, `warn`, or `fail`. See [`strict-additional-properties.md`](strict-additional-properties.md) |
| `strict_additional_properties_per_call` | No | `off` | Immediate undocumented response-property warning: `off` or `warn`. Pair with PHPUnit `failOnWarning="true"` for per-test failure. |
| `default_testsuite_as_full` | No | `false` | Opt-in. When `true` and PHPUnit's `includeTestSuites` resolves exactly to the configured `defaultTestSuite`, treat the run as full instead of partial (so `strict_required` and coverage outputs aren't suppressed). See [default_testsuite_as_full opt-in](ci.md#default_testsuite_as_full-opt-in) for trade-offs |
| `enforce_discriminator` | No | `true` | When `true` (default), `discriminator` + `mapping` is enforced via `if`/`then` lowering so a body that lies about its type fails. Set to `false` (or `0` / `no`) to strip `discriminator` without enforcing (no warning either). See [Schema features → discriminator](supported-features.md#schema-features) |
| `acknowledged_unvalidatable_schemes` | No | — | Comma-separated `components.securitySchemes` names acknowledged as unvalidatable. Suppresses the one-shot `[security]` silent-pass warning for exactly those schemes; every other unvalidatable scheme keeps warning. See [Acknowledging an unvalidatable security scheme](#acknowledging-an-unvalidatable-security-scheme) |

*Not required if you call `OpenApiSpecLoader::configure()` manually.

## 3. Use in tests

### With Laravel (recommended)

Publish the config file:

```bash
php artisan vendor:publish --tag=gesso
```

This creates `config/gesso.php`:

```php
return [
    'default_spec' => '', // e.g., 'front'

    // Used by Laravel Artisan commands such as openapi:routes.
    'spec_base_path' => base_path('openapi'),

    // Keep aligned with the PHPUnit extension's strip_prefixes parameter.
    'strip_prefixes' => [],

    // Maximum number of validation errors to report per response.
    // 0 = unlimited (reports all errors).
    'max_errors' => 20,

    // Enforce `discriminator` + `mapping` by lowering it into if/then
    // conditionals so a body that lies about its type fails (default true).
    // Set false to strip discriminator without enforcing (no warning either).
    'enforce_discriminator' => true,

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

The Laravel config and PHPUnit extension are separate entry points. Configure
the same spec directory and prefixes in both so `openapi:routes` and runtime
validation compare the same paths. See [Laravel route parity](laravel-route-parity.md)
for filters, JSON output, and CI exit codes.

```php
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;

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
use Studio\Gesso\Attribute\OpenApiSpec;
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;

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
| 4 | `config('gesso.default_spec')` | Project-wide default set once in `config/gesso.php` |

Concrete example where all four layers are populated (class name differs from the earlier `MixedApiTest` example so both snippets can coexist in one project):

```php
use Studio\Gesso\Attribute\OpenApiSpec;

// config/gesso.php → ['default_spec' => 'front']   (layer 4)

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
in config/gesso.php.
```

> **Note:** `openApiSpec()` remains the original extension hook and is fully backward-compatible — overriding it works exactly as before.

### With Symfony

For Symfony projects, mix the `OpenApiAssertions` trait into a `WebTestCase` (or any PHPUnit test). It validates HttpFoundation `Request` / `Response` objects directly against the spec — no PSR-7 conversion required — and records endpoint coverage the same way the Laravel adapter does.

```php
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Studio\Gesso\Attribute\OpenApiSpec;
use Studio\Gesso\Symfony\OpenApiAssertions;

#[OpenApiSpec('front')]
final class PetsTest extends WebTestCase
{
    use OpenApiAssertions;

    public function test_list_pets(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/pets');

        // Validates both the request and the response of the last client call.
        $this->assertClientMatchesOpenApiSchema($client);
    }
}
```

You can also pass HttpFoundation objects directly — useful when you are not driving the test through the kernel browser:

```php
// Response only (the Request supplies the HTTP method and path):
$this->assertResponseMatchesOpenApiSchema($request, $response);

// Request only (optionally pass the response status for the documented-4xx downgrade):
$this->assertRequestMatchesOpenApiSchema($request, $response->getStatusCode());
```

Spec resolution uses the same `#[OpenApiSpec]` attribute / `openApiSpec()` chain as the framework-agnostic usage below. There is no Laravel-style `config()` lookup, so pin the spec with the attribute or by overriding `openApiSpec()`. Spec files are still discovered through the [PHPUnit extension](#2-configure-the-phpunit-extension) (`spec_base_path`) or a direct `OpenApiSpecLoader::configure()` call.

> **Requires `symfony/http-foundation`.** Symfony projects already depend on it; it is listed under `suggest` so standalone installs aren't forced to pull it in.

### Framework-agnostic

You can use the `#[OpenApiSpec]` attribute with the `OpenApiSpecResolver` trait in any PHPUnit test:

```php
use Studio\Gesso\Attribute\OpenApiSpec;
use Studio\Gesso\Spec\OpenApiSpecResolver;
use Studio\Gesso\OpenApiResponseValidator;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;

#[OpenApiSpec('front')]
class GetPetsTest extends TestCase
{
    use OpenApiSpecResolver;

    public function test_list_pets(): void
    {
        $specName = $this->resolveOpenApiSpec(); // 'front'
        $validator = new OpenApiResponseValidator(new StrictRequiredTracker());
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
use Studio\Gesso\OpenApiResponseValidator;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;

// Configure once (e.g., in bootstrap)
OpenApiSpecLoader::configure(__DIR__ . '/openapi/bundled', ['/api']);

// In your test
$validator = new OpenApiResponseValidator(new StrictRequiredTracker());
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

## Controlling the number of validation errors

By default, up to **20** validation errors are reported per response. You can change this via the constructor:

```php
$tracker = new StrictRequiredTracker();

// Report up to 5 errors
$validator = new OpenApiResponseValidator($tracker, maxErrors: 5);

// Report all errors (unlimited)
$validator = new OpenApiResponseValidator($tracker, maxErrors: 0);

// Stop at first error
$validator = new OpenApiResponseValidator($tracker, maxErrors: 1);
```

For Laravel, set the `max_errors` key in `config/gesso.php`.

## Reproduction commands in failure output

Every adapter assertion failure (Laravel, Symfony, Pest, PSR-7) ends with a
one-line curl command that reproduces the validated request:

```text
OpenAPI schema validation failed for POST /v1/pets (spec: front):
[/name] The data (integer) must match the type: string
Reproduce: curl -X POST 'https://api.example.test/v1/pets' -H 'Authorization: <redacted>' -H 'Content-Type: application/json' --data '{"name":42}'
```

The PSR-7 exchange assertion prefixes each error line with its `[request]` /
`[response]` side label and ends with one `Reproduce:` line for the whole
exchange. In the JSON failure output mode the same command is carried in the
document's `reproduce_command` field instead of a trailing line — see
[validation-json-schema.md](validation-json-schema.md).

Header and query values that look sensitive are redacted before the command
is rendered:

- `Authorization`, `Proxy-Authorization`, and `Cookie` header values, plus
  any header whose name contains `api-key` / `api_key` / `apikey`, `token`,
  or `secret` (case-insensitive), become `<redacted>`.
- Query-string values whose parameter name matches the same pattern are
  redacted too (OpenAPI supports `apiKey` security in query parameters).
- The request body is rendered only for JSON content types and is **not**
  redacted. A body can itself carry credentials (a login password, a token
  refresh payload), so treat the failure output as sensitive as a whole —
  the redaction keeps header and query secrets out of CI logs, it does not
  make the output safe to share.

Redaction in adapter failure output cannot be disabled. Only the fuzzing
API's `ExploredCase::curlSnippet(redactSensitiveHeaders: false)` opts out,
for local debugging ([fuzzing.md](fuzzing.md)). The command is rendered only
when an assertion actually fails, so a passing PSR-7 assertion never
re-reads the body stream for output — validation itself still decodes
seekable streams (and restores the cursor) either way.

## Skipping responses by status code

Production error responses (typically `5xx`) are often deliberately left out of the OpenAPI spec. Without special handling, a test that hits a `500` would fail twice: once from the underlying bug, and again from "Status code 500 not defined". To avoid that noise, every `5xx` response is **skipped by default** — body validation is not performed, the assertion passes, and the endpoint is still recorded as covered.

Override via `skip_response_codes` in `config/gesso.php`:

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
$validator = new OpenApiResponseValidator(
    new StrictRequiredTracker(),
    skipResponseCodes: ['5\d\d'],
);
```

Notes:

- Patterns are regex strings **without** `/` delimiters or `^$` anchors; they are anchored automatically, so `5\d\d` matches exactly `500`–`599` (not `5000`).
- The skip check sits **between** the "path / method not in spec" checks and the "status code not defined" / schema-validation checks. A skipped code therefore suppresses both status-code failure modes (undocumented code AND body mismatch for a documented code), but typos in the request path or method still fail loudly.
- Skipped endpoints count as covered — the endpoint was exercised, just not schema-validated. Coverage semantics here match how non-JSON content types and schema-less `204` responses are handled. `OpenApiValidationResult::isSkipped()` returns `true` for status-code skips **and** for responses/requests whose body could not be schema-validated because the spec declared it under a non-JSON content type (with a `schema` the JSON Schema engine cannot evaluate). A schema-less `204` and a non-JSON content type with no `schema` have nothing to validate and still return a plain `success()`.
- `OpenApiValidationResult::isSkipped()` is exposed for callers who want to distinguish a skip from a genuine success. `skipReason()` identifies the matched pattern. `outcome()` returns an `OpenApiValidationOutcome` enum (`Success` / `Failure` / `Skipped`) for callers who want exhaustive `match` handling instead of two bool predicates.
- **Observability trade-off**: a real regression that causes an unrelated `500` will not fail this assertion. Keep your HTTP-level assertions (`$response->assertOk()`, status-code expectations in the test) alongside the contract check so a stray 5xx still surfaces — the contract assertion alone is not a substitute for status-code assertions on happy paths.
- **Coverage signal**: skipped responses surface as their own row inside each endpoint's response table — `⚠` (`:warning:` in Markdown) on the per-`(status, content-type)` line, with the matched skip pattern shown inline. The endpoint marker becomes `◐` (partial) when other responses are still validated, or stays `✓` only when every declared response is covered. The response-level rate (`responseCovered / responseTotal`) excludes skipped definitions, so a happy-path regression that silently returns `500` in every test no longer hides behind a 100% endpoint count. `skipReason()` is available on each `OpenApiValidationResult` for callers who want to log the matched pattern from a custom renderer.

## Auto-assert every response

Forgetting `$this->assertResponseMatchesOpenApiSchema($response)` in a test means the contract is silently unchecked. Enable `auto_assert` to validate every response produced by Laravel's HTTP helpers automatically — just include the trait:

```php
// config/gesso.php
return [
    'default_spec' => 'front',
    'auto_assert'  => true,
];
```

```php
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;

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

## Opting out with `#[SkipOpenApi]`

Some tests intentionally return responses that violate the spec (error-injection tests, experimental endpoints with a not-yet-finalized contract, etc.). For these, use the `#[SkipOpenApi]` attribute to opt out of auto-assert without turning the feature off globally:

```php
use Studio\Gesso\Attribute\SkipOpenApi;

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

## Per-request skip with `withoutValidation()`

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

## Per-request status-code skip with `skipResponseCode()`

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

## Auto-validate every request

Request-side contract drift (missing query params, body-shape divergence, absent security headers) goes undetected unless the test explicitly checks for it. Enable `auto_validate_request` to run `OpenApiRequestValidator` against every request Laravel's HTTP helpers dispatch:

```php
// config/gesso.php
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

## Skip request validation when the response is a documented 4xx

Tests that intentionally send invalid input to verify the impl returns a documented 422 / 400 would otherwise double-fail under `auto_validate_request`: the request is genuinely spec-invalid (that's the point), but the test only cares about asserting the 4xx response. By default, the library downgrades the request validation result from Failure to Skipped when the response status matches `skip_request_validation_response_codes` AND the spec documents that status for the operation. Default `['422', '400']`:

```php
return [
    // default — downgrade documented 422 / 400 responses, keep undocumented 4xx loud
    'skip_request_validation_response_codes' => ['422', '400'],

    // strict — every spec violation surfaces, even for documented 4xx
    'skip_request_validation_response_codes' => [],

    // wider net — downgrade every documented 4xx
    'skip_request_validation_response_codes' => ['4\d\d'],
];
```

Notes:

- **Documented-only**: the downgrade only applies when the response status is in the matched operation's `responses` map (exact match, range key like `4XX`, or `default`). An undocumented 4xx still fails — that's a real spec gap.
- **Failure-only**: a request that passes validation cleanly stays Success even if the response is a documented 4xx (legitimate business-logic 422 on a perfectly-shaped payload is not demoted).
- **Coverage**: downgraded requests are recorded with the skip reason so the coverage report distinguishes "request was validated cleanly" from "request was downgraded because of a documented 4xx".

## Auto-inject dummy bearer

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

## Acknowledging an unvalidatable security scheme

The validator cannot enforce `oauth2`, `openIdConnect`, `mutualTLS`, or `http` schemes other than `bearer` (basic, digest, …). Requests secured only by such a scheme silently pass the security check, and the first encounter per scheme emits a one-shot `E_USER_WARNING` so the silent pass does not stay invisible (see the [warning channel](supported-features.md#warning-channel-e_user_warning-contract)).

Under Laravel that warning is converted into a test failure by the framework's error handler — and because it is one-shot, *which* test fails depends on execution order. When the scheme genuinely cannot be avoided (e.g. RFC 7009 / RFC 7662 mandate HTTP Basic client authentication for token revocation / introspection endpoints) and the authentication is covered by a dedicated test, acknowledge the scheme **by name** instead of filtering `E_USER_WARNING` globally:

```php
// config/gesso.php (Laravel)
return [
    'auto_validate_request' => true,
    // components.securitySchemes keys covered by a dedicated auth test.
    'acknowledged_unvalidatable_schemes' => ['ClientBasicAuth'],
];
```

```xml
<!-- phpunit.xml (framework-agnostic suites) -->
<bootstrap class="Studio\Gesso\PHPUnit\OpenApiCoverageExtension">
    <parameter name="spec_base_path" value="openapi"/>
    <parameter name="acknowledged_unvalidatable_schemes" value="ClientBasicAuth"/>
</bootstrap>
```

Notes:

- **Scheme-scoped, not global**: only the listed scheme names stop warning. Any other unvalidatable scheme — including one added to the spec later — still warns.
- **The list cannot rot**: acknowledging a name that is not defined in `components.securitySchemes`, or one the validator *can* enforce (`http` + `bearer`, `apiKey`), itself emits a one-shot warning. These rot warnings carry the `[Gesso]` prefix (not `[security]`) — route them accordingly in a prefix-matching error handler.
- **Validation behavior is unchanged**: acknowledged schemes were already silently passed; the acknowledgement only silences the warning.
- `gesso doctor` reflects the acknowledgement via `--acknowledge-unvalidatable-scheme` — see [the doctor guide](doctor.md#acknowledged-unvalidatable-security-schemes).

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
use Studio\Gesso\Spec\OpenApiSpecLoader;

OpenApiSpecLoader::configure(
    basePath: 'openapi/',
    httpClient: new Client(),       // PSR-18 ClientInterface
    requestFactory: new HttpFactory(), // PSR-17 RequestFactoryInterface
    allowRemoteRefs: true,
    allowedRemoteRefHosts: ['specs.example.com'],
    maxRemoteRefBytes: 10_485_760, // optional; 10 MiB default per document
);
```

The library does not bundle an HTTP client — pick whichever your project already uses. (Guzzle 7+ implements PSR-18 directly; Guzzle 6 needs an adapter.)

Remote-reference diagnostics remove URL userinfo and query values before they
reach exception messages or CI logs. Configure authentication on the HTTP
client instead of embedding credentials in a `$ref` URL. Raw PSR-18 transport
and response-body stream exceptions are not chained into the public exception
because a nested cause may contain unsanitized request data; the sanitized
top-level message is retained.
The original `$ref` argument is also replaced with its diagnostic-safe form
before network operations, so exception traces remain redacted even when
`zend.exception_ignore_args=Off`.

Remote access requires a non-empty `allowedRemoteRefHosts` list. Matching is
case-insensitive against the URL's exact host; schemes, ports, paths, and
userinfo do not belong in the list. Every nested remote `$ref` is checked, so
a document served by an allowed host cannot redirect resolution to another
host through an absolute `$ref`. Configure the injected PSR-18 client not to
follow redirects and use the canonical URL instead: a client-side redirect
would otherwise happen below Gesso's host-policy boundary.

This follows OWASP's SSRF guidance to prefer an allowlist and disable redirect
following. DNS and network-layer controls remain the application's
responsibility because PSR-18 does not expose connection-level address policy.
[See the OWASP SSRF Prevention Cheat Sheet.](https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html)

Each remote document is limited to 10 MiB by default. Gesso checks a valid
`Content-Length`, the PSR-7 stream's known size, and the bytes actually read, so
a missing or false size header cannot bypass the limit. Set
`maxRemoteRefBytes` to a positive integer only when a trusted contract is
larger. A PSR-18 client may buffer a response before returning its PSR-7 stream;
also configure client-level timeouts and transfer limits where the selected
implementation supports them. This bounds the transport as well as Gesso's
parser boundary. See the [PSR-7 stream rationale](https://www.php-fig.org/psr/psr-7/#13-streams).

Misconfiguration is caught early:

| Setup | Result |
| --- | --- |
| `allowRemoteRefs: true` without `$httpClient` / `$requestFactory` | `InvalidArgumentException` at `configure()` |
| `allowRemoteRefs: true` without `allowedRemoteRefHosts` | `InvalidArgumentException` at `configure()` |
| HTTP(S) `$ref` targets an unlisted host | `InvalidOpenApiSpecException` with reason `RemoteRefHostDisallowed`; no request is sent |
| `$httpClient` set but `allowRemoteRefs: false` | `InvalidArgumentException` at `configure()` (silent misuse impossible) |
| `allowRemoteRefs: true` + client + ref to URL that 4xx/5xx | `InvalidOpenApiSpecException` with reason `RemoteRefFetchFailed` |
| `allowRemoteRefs: true` + client + ref to URL that 3xx | `RemoteRefFetchFailed` with redirect target — keep redirects disabled and use the canonical URL |
| Remote response exceeds `maxRemoteRefBytes` | `RemoteRefFetchFailed` before parsing; raise the limit only for a trusted contract |
| `allowRemoteRefs: true` + client + ref to URL with no detectable format | reason `UnsupportedExtension` (URL extension or `Content-Type` header is required) |

Format detection prefers the URL's filename extension (`.json` / `.yaml` / `.yml`) and falls back to the response's `Content-Type` (`application/json`, `application/*+json`, `application/yaml`, `text/yaml`, etc.). URLs without a recognisable extension still work as long as the server sets a usable `Content-Type`.

Inside an HTTP-loaded document, relative `$refs` resolve against the URL per RFC 3986: a `$ref: './pet.yaml'` inside `https://example.com/openapi.json` fetches `https://example.com/pet.yaml`.

## Remote spec sources (opt-in)

Normally a named spec resolves to a file under `basePath`. When the contract
lives in a central registry or a separate spec repository (spec-first orgs,
API gateways), `remoteSpecs` lets a name resolve to an HTTP(S) entry document
instead — including a private GitHub repository — without vendoring the file
into every service repo:

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Spec\RemoteSpecSource;

OpenApiSpecLoader::configure(
    basePath: 'openapi/',                    // still used for filesystem specs
    httpClient: new Client(['allow_redirects' => false]),
    requestFactory: new HttpFactory(),
    allowRemoteRefs: true,
    allowedRemoteRefHosts: ['raw.githubusercontent.com'],
    remoteSpecs: [
        // Private GitHub repo via a fine-grained PAT with contents:read.
        'front' => new RemoteSpecSource(
            'https://raw.githubusercontent.com/acme/api-specs/main/bundled/front.json',
            authorizationEnv: 'GESSO_SPEC_TOKEN',
        ),
        // Unauthenticated internal registry: a plain URL works too.
        'admin' => 'https://specs.internal.example.com/admin/openapi.yaml',
    ],
);
```

```bash
# GitHub raw content accepts a PAT as a bearer token.
export GESSO_SPEC_TOKEN='Bearer github_pat_…'
```

After that, `front` and `admin` behave like any other spec name — in
`specs=` for coverage, in `#[OpenApiSpec(...)]` attributes, and in the direct
validators. Call `configure()` from your PHPUnit bootstrap file and omit the
extension's `spec_base_path` parameter (the extension would otherwise
re-configure the loader without your HTTP client); keep `specs=` so remote
documents are eagerly fetched and structurally validated at bootstrap.

Rules and behavior:

- **Same opt-in as HTTP `$ref`s.** `remoteSpecs` requires `allowRemoteRefs:
  true`, the PSR-18/PSR-17 pair, and every URL's host in
  `allowedRemoteRefHosts` — all enforced at `configure()` time. Redirect
  rejection, the response-size limit, format detection, and URL redaction
  apply exactly as described in the previous section.
- **`authorizationEnv` is provider-agnostic.** The environment variable's
  value is sent verbatim as the `Authorization` header (`Bearer …`,
  `token …`, `Basic …` — whatever the registry expects). A missing or empty
  variable fails loudly with reason `RemoteSpecAuthEnvMissing` before any
  request is sent. The value itself never appears in diagnostics.
- **Credentials are origin-scoped.** The header is sent to the entry
  document's origin only — same scheme, host, and effective port (RFC 6454).
  Relative `$ref`s (which resolve against the entry URL per RFC 3986) and
  same-origin absolute `$ref`s carry it; anything else never does: not a
  cross-host `$ref` (even to another allowlisted host), not another port on
  the same host, and not an `http://` downgrade of an `https://` entry
  document.
- **A mapped name never touches the filesystem.** If `front` is in
  `remoteSpecs`, `openapi/front.json` is ignored for that name.
- **Fetched once per process.** The resolved document is cached under its
  spec name like a local spec; parallel workers fetch once each.

For CI predictability, pin the document to the exact bytes you reviewed:

```php
'front' => new RemoteSpecSource(
    'https://raw.githubusercontent.com/acme/api-specs/main/bundled/front.json',
    authorizationEnv: 'GESSO_SPEC_TOKEN',
    expectedSha256: '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08',
),
```

A mismatch fails with reason `RemoteSpecHashMismatch` (showing expected and
actual digests) before the document is parsed, so an upstream edit — or a
compromised registry — cannot silently change what your tests enforce.
Compute the pin with `curl -s … | shasum -a 256` and commit it; update it
deliberately when the upstream contract changes. If you prefer freshness over
pinning, point the URL at an immutable ref (a commit SHA instead of a branch)
or add ETag caching middleware to the injected PSR-18 client — Gesso
deliberately performs plain conditional-free GETs and leaves transport
caching to the client.
