<?php

declare(strict_types=1);

use Studio\OpenApiContractTesting\Coverage\OpenApiCoverageTracker;

it('lists pets matching the documented response shape', function (): void {
    $response = $this->getJson('/v1/pets');
    $response->assertOk();

    expect($response)->toMatchOpenApiResponseSchema();

    expect(OpenApiCoverageTracker::getCovered())
        ->toHaveKey('petstore')
        ->and(OpenApiCoverageTracker::getCovered()['petstore'])
        ->toHaveKey('GET /v1/pets');
});

it('creates a pet matching the documented request and response shapes', function (): void {
    $this->postJson('/v1/pets', ['name' => 'Buddy'])->assertCreated();

    /** @var \Symfony\Component\HttpFoundation\Request $request */
    $request = app('request');

    expect($request)->toMatchOpenApiRequestSchema();
});

it('chains other expectations after the schema match', function (): void {
    $response = $this->getJson('/v1/pets');

    expect($response)
        ->toMatchOpenApiResponseSchema()
        ->toBeInstanceOf(\Illuminate\Testing\TestResponse::class);
});
