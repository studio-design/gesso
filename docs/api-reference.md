# API Reference

- [`OpenApiResponseValidator`](#openapiresponsevalidator)
- [`OpenApiPsr7Validator`](#openapipsr7validator)
- [`OpenApiResponseExplorer`](#openapiresponseexplorer)
- [`OpenApiSpecExplorer`](#openapispecexplorer)
- [`OpenApiContractChecks`](#openapicontractchecks)
- [`OpenApiSpecLoader`](#openapispecloader)
- [`OpenApiCoverageTracker`](#openapicoveragetracker)

## `OpenApiResponseValidator`

Main validator class. Validates a response body against the spec.

The constructor requires a `StrictRequiredTracker` that receives successful
response-body observations. Reuse one tracker for validators that belong to the
same test run. The optional `maxErrors` parameter (default: `20`) limits how many
validation errors the underlying JSON Schema validator collects. Use `0` for
unlimited, `1` to stop at the first error.

The optional `responseContentType` parameter enables content negotiation: when provided, non-JSON content types (e.g., `text/html`) are checked for spec presence only, while JSON-compatible types proceed to full schema validation. When a non-JSON content type matches a spec media-type key that declares a `schema`, the body cannot be evaluated by the JSON Schema engine — the result is reported as `Skipped` (with a `skipReason`) rather than a clean success, so the unvalidated body is not miscounted.

```php
$tracker = new StrictRequiredTracker();
$validator = new OpenApiResponseValidator($tracker, maxErrors: 20);
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
$result->issues();       // list<ValidationIssue> (structured view of errors())
$result->matchedPath();  // ?string (e.g., '/v1/pets/{petId}')
$result->skipReason();   // ?string (non-null when skipped)
```

`issues()` mirrors `errors()` one-to-one: each `ValidationIssue` carries the
same `message` plus a stable `category` slug (e.g. `request.security`,
`response.body` — the full list is in
[versioning.md](versioning.md#whats-covered-by-semver)) and the operation
context the validator resolved (`method`, `path`, `statusCode`,
`contentType`). A context field is `null` when that dimension does not apply
or was not resolved — request-side issues never carry a `statusCode`, and
`contentType` is set only on body issues (the resolved spec media-type key).
Assert on `category` and context instead of message wording —
the prose is not a compatibility surface. On schema violations,
`instancePath` carries the RFC 6901 JSON Pointer (`''` = document root —
the `message` prefix renders it as `[/]` for historical reasons) and
`keyword` the failing JSON Schema keyword (`type`, `required`, …): for
`request.body` / `response.body` issues the pointer is into the validated
body, for parameter / response-header issues into the named value.
`keyword` also carries synthetic violation kinds — `required` when a
required parameter, header, or security credential is missing, `format`
when a credential is present but not usable, and `parse` when the PSR-7
adapter could not read or JSON-decode a body. Both stay `null` for
structural and spec-malformation errors, including non-schema body errors
such as a missing required body. Results built by code that predates the
structured API derive issues with category `unknown`.

To hand the full result to machine consumers (CI ingestion, IDE annotations),
render it as a versioned JSON document:

```php
use Studio\Gesso\JsonValidationResultRenderer;

$json = JsonValidationResultRenderer::render($result);
// Optionally embed a (pre-redacted) reproduction command:
$json = JsonValidationResultRenderer::render($result, $curlCommand);
```

The document shape (`schema_version` 1) is a compatibility surface — see
[validation-json-schema.md](validation-json-schema.md).

Framework adapters (Laravel, Symfony, Pest, PSR-7) can emit the same document
in their assertion failure messages instead of the plain text shape. Select
the mode process-wide with the `OPENAPI_VALIDATION_OUTPUT` environment
variable (`text` | `json`), programmatically, or via the PHPUnit extension's
`validation_output` parameter — the environment variable wins when set:

```php
use Studio\Gesso\ValidationOutput;
use Studio\Gesso\ValidationOutputFormat;

ValidationOutput::use(ValidationOutputFormat::Json); // e.g. in tests/bootstrap.php
ValidationOutput::reset();                           // restore the text default
```

See [validation-json-schema.md](validation-json-schema.md#selecting-json-failure-output-in-adapters)
for the failure message shape and resolution rules.

Prefer `outcome()` when you need to distinguish all three states explicitly — PHPStan enforces `match` exhaustiveness, so adding a future outcome cannot silently slip past a caller:

```php
use PHPUnit\Framework\AssertionFailedError;
use Studio\Gesso\OpenApiValidationOutcome;

match ($result->outcome()) {
    OpenApiValidationOutcome::Success => null, // schema matched
    OpenApiValidationOutcome::Failure => throw new AssertionFailedError($result->errorMessage()),
    OpenApiValidationOutcome::Skipped => logger()->info('skipped', ['reason' => $result->skipReason()]),
};
```

## `OpenApiPsr7Validator`

Adapts PSR-7 messages to the request and response validators and records the
same coverage as framework integrations:

```php
use Studio\Gesso\Psr7\OpenApiPsr7Validator;

$validator = new OpenApiPsr7Validator('front');

$exchange = $validator->validateExchange($request, $response);
$requestResult = $exchange->requestResult();
$responseResult = $exchange->responseResult();
```

Use `validateRequest()`, `validateResponse()`, or
`validateResponseForOperation()` when only one side is available. See the
[PSR-7 guide](psr7.md) for PHPUnit assertions, stream handling, and a PSR-15
test integration recipe.

## `OpenApiResponseExplorer`

Generates deterministic, branch-complete valid payloads for one response
schema and returns a non-empty `GeneratedResponseCases` collection:

```php
use Studio\Gesso\Fuzz\GeneratedResponseCase;
use Studio\Gesso\Fuzz\OpenApiResponseExplorer;

$cases = OpenApiResponseExplorer::explore(
    specName: 'front',
    method: 'POST',
    path: '/oauth/introspect',
    status: 200,
    contentType: 'application/json', // optional; first JSON type when null
    seed: 1,
    extraCases: 2,
);

$cases->each(function (GeneratedResponseCase $case): void {
    $decoded = sdk_decode($case->bodyAsObject());
    $case->assertRoundTrip(sdk_encode($decoded));
});
```

The collection is `Countable` and `IteratorAggregate`. Each readonly case
exposes `body`, `status`, `contentType`, `seed`, `caseIndex`, and
`pinnedBranch`; `bodyAsObject()` supplies decoded JSON shapes to SDKs,
`bodyAsArray()` supports array-typed consumers, and `replaySnippet()` renders a
focused reproduction. `assertRoundTrip()` first validates the SDK output
against the exact converted response schema, then requires all generated object
keys and values to survive recursively; JSON lists compare exactly.

The case count is derived from reachable schema choice points, not supplied by
the caller. An omitted `seed` uses `0`, and `extraCases` appends deterministic
rotation-only cases. No-content,
non-JSON-only, schema-less, and OpenAPI 3.2 `itemSchema` responses throw a loud
`InvalidArgumentException` carrying the structured resolver outcome. Laravel's
`ExploresOpenApiEndpoint::exploreResponseSchema()` resolves the configured spec
and delegates to the same facade. See the [SDK round-trip guide](sdk-roundtrip.md).

## `OpenApiSpecExplorer`

Builds a deterministic whole-spec exploration plan around the existing
single-operation generator:

```php
use Studio\Gesso\Fuzz\OpenApiSpecExplorer;

$summary = OpenApiSpecExplorer::explore('front', casesPerOperation: 20, seed: 1)
    ->includeTags(['public'])
    ->excludeOperations(['admin.users.destroy'])
    ->dispatchUsing(fn ($case, $operation) => dispatch_request($case))
    ->assertResponseUsing(fn ($response) => assert_contract($response))
    ->assertResponses();
```

Filters are available for tags, methods, paths, operation IDs, and deprecated
operations. `authenticateUsing()`, `setUpUsing()`, `tearDownUsing()`, and
`mutateCasesUsing()` provide framework/auth/stateful-ID hooks. The returned
`SpecExplorationSummary` exposes executed operation/case counts, the executed
`ExploredOperation` rows (including their coverage keys), and a list of
`ExplorationSkip` entries. See [schema-driven request fuzzing](fuzzing.md).

Call `negativeCases([4])` to switch a whole-spec plan to targeted invalid
inputs and carry explicit expected response classes on each `ExploredCase`.
For one operation, use
`OpenApiEndpointExplorer::exploreInvalid(..., expectedStatusClasses: [4])` or
Laravel's `exploreInvalidEndpoint()`. `FailureReducer::reduce()` minimizes a
failing body while preserving a caller-defined failure classification.

## `OpenApiContractChecks`

Builds a plan of named negative contract checks — probes for protocol-level
holes schema validation cannot see. The first check, `unsupported_method`,
dispatches one deterministically chosen undocumented HTTP method per
documented path and expects 405 by default:

```php
use Studio\Gesso\Fuzz\ContractCheck;
use Studio\Gesso\Fuzz\OpenApiContractChecks;

$summary = OpenApiContractChecks::run('front', seed: 7)
    ->checks([ContractCheck::UnsupportedMethod])
    ->includeTags(['public'])
    ->expectedStatuses(ContractCheck::UnsupportedMethod, [405, 404]) // optional override
    ->dispatchUsing(fn ($case) => dispatch_request($case))
    ->report();

self::assertSame([], $summary->failures, $summary->describeFailures());
```

The plan shares the exploration filter set (tags, methods, paths, operation
IDs, deprecated) and the `authenticateUsing()` / `setUpUsing()` /
`tearDownUsing()` hooks. `dispatchUsing()` may return an `int`, a PSR-7
response, or any object exposing `getStatusCode(): int`. Probes never throw
on a status mismatch — the returned `ContractCheckSummary` collects every
`ContractCheckFailure` (with a replayable curl command) and every explained
`ContractCheckSkip`, plus `probedPaths` / `dispatchedProbes` counts. See
[named contract checks](fuzzing.md#named-contract-checks).

## `OpenApiSpecLoader`

Manages spec loading and configuration.

```php
OpenApiSpecLoader::configure('/path/to/bundled/specs', ['/api']);
$spec = OpenApiSpecLoader::load('front');
OpenApiSpecLoader::reset(); // For testing
```

## `OpenApiCoverageTracker`

Tracks which endpoints have been exercised, at `(method, path, statusCode, contentType)` granularity. The Laravel trait records via the tracker automatically; framework-agnostic adapters call it directly.

```php
use Studio\Gesso\Coverage\OpenApiCoverageTracker;

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

`hasAnyCoverage(spec): bool` is a fast presence check. `getCovered()` is retained as a diagnostic shim returning `array<spec, array<"METHOD path", true>>`. See [CHANGELOG.md](https://github.com/studio-design/gesso/blob/main/CHANGELOG.md) for the migration from the pre-#111 endpoint-level shape.
