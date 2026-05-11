<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pest plugin smoke test
|--------------------------------------------------------------------------
|
| Proves the autoload boundary stays wired:
|
|  1. composer.json `autoload.files` actually loads `src/Pest/Autoload.php`
|     when the Pest binary boots.
|  2. The two-symbol guard in src/Pest/Autoload.php (`function_exists('expect')`
|     AND `class_exists(\Pest\Expectation::class)`) does NOT short-circuit when
|     Pest is present.
|  3. expect()->extend() registered the two expectations under the names
|     downstream tests dispatch on (toMatchOpenApiResponseSchema /
|     toMatchOpenApiRequestSchema).
|
| Asserting on the input-validation throws — "requires a TestResponse" /
| "requires a Symfony Request" — pins the dispatch to the actual
| Studio\OpenApiContractTesting\Pest\Expectations class. A wrong
| expectation name would surface as a different exception type
| (BadMethodCallException) and fail the test.
|
| Behavioural coverage of the validator orchestration lives in
| ExpectationsTest.php; this file only guards the registration boundary.
|
| The closures below are intentionally not `static` — Pest binds $this on
| each test callback and rejects static closures. The file is excluded
| from PHP-CS-Fixer's `static_lambda` rule via `.php-cs-fixer.dist.php`.
*/

it('registers the toMatchOpenApiResponseSchema expectation', function (): void {
    expect(static fn () => expect(null)->toMatchOpenApiResponseSchema())
        ->toThrow(\RuntimeException::class, 'requires a Illuminate\Testing\TestResponse');
});

it('registers the toMatchOpenApiRequestSchema expectation', function (): void {
    expect(static fn () => expect(null)->toMatchOpenApiRequestSchema())
        ->toThrow(\RuntimeException::class, 'requires a Symfony\Component\HttpFoundation\Request');
});
