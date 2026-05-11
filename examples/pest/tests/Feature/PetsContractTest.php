<?php

declare(strict_types=1);

use Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker;

it('lists pets matching the documented response shape', function (): void {
    $response = $this->getJson('/v1/pets');
    $response->assertOk();

    expect($response)->toMatchOpenApiResponseSchema();
});

it('creates a pet matching the documented request and response shapes', function (): void {
    $this->postJson('/v1/pets', ['name' => 'Buddy'])->assertCreated();

    // Laravel rebinds the current Request into the container after each test
    // HTTP call; that is what the request validator inspects.
    /** @var \Symfony\Component\HttpFoundation\Request $request */
    $request = app('request');

    expect($request)->toMatchOpenApiRequestSchema();
});

it('skips body validation for an undocumented status via skipResponseCodes', function (): void {
    // The spec only documents 200 for /v1/health. The route deliberately
    // returns 503 here, which would normally fail with "Status code 503 not
    // defined". The skipResponseCodes named argument tells the validator to
    // accept the undocumented status and skip body validation for this single
    // call (the standard `skip_response_codes` config still applies on top).
    $response = $this->getJson('/v1/health');

    expect($response)->toMatchOpenApiResponseSchema(skipResponseCodes: ['503']);
});

it('chains other expectations after the schema match', function (): void {
    $response = $this->getJson('/v1/pets');

    // The expect()->extend() closures in the plugin return $this, letting
    // the result chain into other expectations (toBe..., toHaveCount, etc).
    expect($response)
        ->toMatchOpenApiResponseSchema()
        ->toBeInstanceOf(\Illuminate\Testing\TestResponse::class);
});

it('records covered endpoints in the coverage tracker', function (): void {
    // This assertion is for the example's CI smoke check rather than something
    // a typical user writes — most projects rely on the coverage report
    // (printed by OpenApiCoverageExtension or the merge CLI under --parallel)
    // instead of inspecting the tracker directly. Included here so a
    // regression in coverage recording would surface in the example suite.
    // Coverage is recorded by every successful schema match, so we have to
    // actually fire the expectation before inspecting the tracker.
    $response = $this->getJson('/v1/pets');
    expect($response)->toMatchOpenApiResponseSchema();

    $covered = OpenApiCoverageTracker::getCovered();

    expect($covered)
        ->toHaveKey('petstore')
        ->and($covered['petstore'])
        ->toHaveKey('GET /v1/pets');
});
