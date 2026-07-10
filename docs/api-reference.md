# API Reference

- [`OpenApiResponseValidator`](#openapiresponsevalidator)
- [`OpenApiPsr7Validator`](#openapipsr7validator)
- [`OpenApiSpecExplorer`](#openapispecexplorer)
- [`OpenApiSpecLoader`](#openapispecloader)
- [`OpenApiCoverageTracker`](#openapicoveragetracker)

## `OpenApiResponseValidator`

Main validator class. Validates a response body against the spec.

The constructor accepts a `maxErrors` parameter (default: `20`) that limits how many validation errors the underlying JSON Schema validator collects. Use `0` for unlimited, `1` to stop at the first error.

The optional `responseContentType` parameter enables content negotiation: when provided, non-JSON content types (e.g., `text/html`) are checked for spec presence only, while JSON-compatible types proceed to full schema validation. When a non-JSON content type matches a spec media-type key that declares a `schema`, the body cannot be evaluated by the JSON Schema engine — the result is reported as `Skipped` (with a `skipReason`) rather than a clean success, so the unvalidated body is not miscounted.

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

## `OpenApiPsr7Validator`

Adapts PSR-7 messages to the request and response validators and records the
same coverage as framework integrations:

```php
use Studio\OpenApiContractTesting\Psr7\OpenApiPsr7Validator;

$validator = new OpenApiPsr7Validator('front');

$exchange = $validator->validateExchange($request, $response);
$requestResult = $exchange->requestResult();
$responseResult = $exchange->responseResult();
```

Use `validateRequest()`, `validateResponse()`, or
`validateResponseForOperation()` when only one side is available. See the
[PSR-7 guide](psr7.md) for PHPUnit assertions, stream handling, and a PSR-15
test integration recipe.

## `OpenApiSpecExplorer`

Builds a deterministic whole-spec exploration plan around the existing
single-operation generator:

```php
use Studio\OpenApiContractTesting\Fuzz\OpenApiSpecExplorer;

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

`hasAnyCoverage(spec): bool` is a fast presence check. `getCovered()` is retained as a diagnostic shim returning `array<spec, array<"METHOD path", true>>`. See [CHANGELOG.md](../CHANGELOG.md) for the migration from the pre-#111 endpoint-level shape.
