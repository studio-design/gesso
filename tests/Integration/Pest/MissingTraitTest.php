<?php

declare(strict_types=1);

use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| "Missing trait" dispatch path
|--------------------------------------------------------------------------
|
| Exercises Expectations::resolveTestCase()'s `method_exists` guard — the
| user-facing message that fires when a Pest test class is missing the
| ValidatesOpenApiSchema trait. The other Pest tests inherit the trait
| through PestLaravelTestCase, which short-circuits this guard, so this
| file deliberately stays outside the global `uses(PestLaravelTestCase::class)`
| binding declared in tests/Pest.php.
|
| The guard is the most common misconfiguration surface for new Pest plugin
| users — get the wiring wrong in tests/Pest.php and this is the message
| pointing them at the fix. Worth pinning so a future rewording doesn't
| silently regress its actionability.
*/

it('errors with guidance when test class lacks ValidatesOpenApiSchema', function (): void {
    // Construct a TestResponse synthetically — we do NOT have Laravel booted
    // here, so $this->get() / $this->postJson() / app('request') aren't
    // available. The dispatch only needs the type-validation guard
    // (instanceof TestResponse) to pass, then it lands on
    // resolveTestCase()'s method_exists check, which is what we're pinning.
    $response = new TestResponse(new Response('{"data":[]}', 200, ['Content-Type' => 'application/json']));

    expect(static fn() => expect($response)->toMatchOpenApiResponseSchema())
        ->toThrow(
            RuntimeException::class,
            'requires the test class',
        );
});
