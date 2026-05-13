<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;
use Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker;
use Symfony\Component\HttpFoundation\Request;

/*
|--------------------------------------------------------------------------
| Pest expectation integration
|--------------------------------------------------------------------------
|
| Exercises the Pest plugin's `toMatchOpenApiResponseSchema` /
| `toMatchOpenApiRequestSchema` end-to-end through Orchestra Testbench
| (configured by `tests/Helpers/PestLaravelTestCase` and wired in
| `tests/Pest.php`). Each `it(...)` boots a fresh Laravel app and resets
| the coverage tracker, so test ordering is irrelevant.
|
| The companion `PluginLoadsTest.php` proves only that the autoload
| boundary is wired. This file proves the dispatch actually runs the
| existing OpenApiResponseValidator / OpenApiRequestValidator pipeline.
*/

it('validates a matching response against the default spec', function (): void {
    $response = $this->get('/v1/pets');
    $response->assertOk();

    expect($response)->toMatchOpenApiResponseSchema();

    $covered = OpenApiCoverageTracker::getCovered();
    expect($covered)
        ->toHaveKey('petstore-3.0')
        ->and($covered['petstore-3.0'])
        ->toHaveKey('GET /v1/pets');
});

it('fails when the response does not match the schema', function (): void {
    $response = $this->get('/v1/pets?bad=1');

    expect(static fn() => expect($response)->toMatchOpenApiResponseSchema())
        ->toThrow(AssertionFailedError::class, 'OpenAPI schema validation failed');
});

it('honours the spec: argument as a per-call override', function (): void {
    $response = $this->get('/v1/pets');
    $response->assertOk();

    expect($response)->toMatchOpenApiResponseSchema(spec: 'petstore-3.1');

    $covered = OpenApiCoverageTracker::getCovered();
    expect($covered)->toHaveKey('petstore-3.1')
        ->and($covered)->not->toHaveKey('petstore-3.0');
});

it('clears the per-call spec override after a single assertion', function (): void {
    // Pin the documented "self-clears after the next resolveOpenApiSpec()
    // call" invariant: one explicit `spec:` followed by an implicit call must
    // route to the configured default, not the previous override. Removing the
    // single-shot reset in OpenApiSpecResolver, or losing the try/finally
    // guard in the trait bridges, would let petstore-3.1 leak into the
    // second assertion and this test would fail.
    $first = $this->get('/v1/pets');
    expect($first)->toMatchOpenApiResponseSchema(spec: 'petstore-3.1');

    $second = $this->get('/v1/pets');
    expect($second)->toMatchOpenApiResponseSchema();

    $covered = OpenApiCoverageTracker::getCovered();
    expect($covered)
        ->toHaveKey('petstore-3.1')
        ->and($covered)
        ->toHaveKey('petstore-3.0');
});

it('honours the skipResponseCodes argument for per-call skip', function (): void {
    // /v1/health returns 503; the spec only documents 200, so a strict
    // assertion would fail with "Status code 503 not defined". Passing
    // skipResponseCodes turns that into a skip rather than a failure.
    $response = $this->get('/v1/health');

    expect($response)->toMatchOpenApiResponseSchema(skipResponseCodes: ['503']);
});

it('validates a matching request via toMatchOpenApiRequestSchema', function (): void {
    $this->postJson('/v1/pets', ['name' => 'Buddy']);

    /** @var Request $request */
    $request = app('request');

    expect($request)->toMatchOpenApiRequestSchema();

    $covered = OpenApiCoverageTracker::getCovered();
    expect($covered)
        ->toHaveKey('petstore-3.0')
        ->and($covered['petstore-3.0'])
        ->toHaveKey('POST /v1/pets');
});

it('does not double-record coverage when explicit assert follows auto-assert', function (): void {
    config()->set('openapi-contract-testing.auto_assert', true);

    $response = $this->get('/v1/pets');

    // auto-assert already validated this response and recorded its hit.
    // The explicit expect() is a WeakMap-deduped no-op — coverage hits
    // for GET /v1/pets / 200 must remain at 1.
    expect($response)->toMatchOpenApiResponseSchema();

    $coverage = OpenApiCoverageTracker::computeCoverage('petstore-3.0');
    $endpoint = null;
    foreach ($coverage['endpoints'] as $candidate) {
        if ($candidate['endpoint'] === 'GET /v1/pets') {
            $endpoint = $candidate;

            break;
        }
    }
    expect($endpoint)->not->toBeNull();
    $hits = $endpoint['responses'][0]['hits'] ?? -1;
    expect($hits)->toBe(1);
});

it('chains expectations after the schema match', function (): void {
    $response = $this->get('/v1/pets');

    // The `expect()->extend()` closures in src/Pest/Autoload.php return $this,
    // letting the result chain into other expectations. PluginLoadsTest only
    // proves the closures are registered; this assertion proves they actually
    // return the Expectation instance.
    expect($response)
        ->toMatchOpenApiResponseSchema()
        ->toBeInstanceOf(TestResponse::class);
});

/*
|--------------------------------------------------------------------------
| Negative-path coverage
|--------------------------------------------------------------------------
|
| Pin the dispatch's input-validation error paths so a future rewording or
| accidental removal of a guard surfaces in CI rather than at downstream
| user reports. The "missing trait" path lives in MissingTraitTest.php
| because it requires a test class WITHOUT ValidatesOpenApiSchema and the
| harness here always provides it.
*/

it('rejects unsupported HTTP methods on toMatchOpenApiResponseSchema', function (): void {
    $response = $this->get('/v1/pets');

    expect(static fn() => expect($response)->toMatchOpenApiResponseSchema(method: 'CONNECT'))
        ->toThrow(RuntimeException::class, 'received unsupported method: CONNECT');
});

it('round-trips lowercase HTTP method strings via strtoupper', function (): void {
    // Removing the strtoupper in resolveHttpMethod would silently break the
    // (probably common) case where users write `method: 'get'`. Pin it.
    $response = $this->get('/v1/pets');
    $response->assertOk();

    expect($response)->toMatchOpenApiResponseSchema(method: 'get');
});

it('fails loudly when spec: is an empty string', function (): void {
    // Layer-0 explicit override accepts the empty string as a valid value
    // (it short-circuits resolution to ''), then assertResponseMatchesOpenApiSchema
    // surfaces the standard "openApiSpec() must return a non-empty spec name"
    // error from the trait. Real footgun for users null-coalescing optional
    // config; pin the current behaviour so a future change doesn't silently
    // shift the surface.
    $response = $this->get('/v1/pets');

    expect(static fn() => expect($response)->toMatchOpenApiResponseSchema(spec: ''))
        ->toThrow(AssertionFailedError::class, 'openApiSpec() must return a non-empty spec name');
});
