<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pest plugin smoke test
|--------------------------------------------------------------------------
|
| Proves three things at once:
|
|  1. composer.json `autoload.files` actually loads `src/Pest/Autoload.php`
|     when the Pest binary boots.
|  2. The class_exists(\Pest\Expectation::class) guard short-circuits on
|     missing Pest, but does NOT short-circuit when Pest is present.
|  3. expect()->extend() registered the two expectations under the names
|     the README references.
|
| PR1 ships the dispatch stubs in
| Studio\OpenApiContractTesting\Pest\Expectations as throwing
| RuntimeExceptions, so "the autoload entrypoint is wired correctly" is
| provable by asserting the throw lands here. PR2 will replace those stubs
| with the real validator orchestration and these asserts will move to
| checking the validation outcome instead.
|
| The closures below are intentionally not `static` — Pest binds $this on
| each test callback and rejects static closures. The file is excluded
| from PHP-CS-Fixer's `static_lambda` rule via `.php-cs-fixer.dist.php`.
*/

it('registers the toMatchOpenApiResponseSchema expectation', function (): void {
    expect(static fn () => expect(null)->toMatchOpenApiResponseSchema())
        ->toThrow(\RuntimeException::class, 'PR2');
});

it('registers the toMatchOpenApiRequestSchema expectation', function (): void {
    expect(static fn () => expect(null)->toMatchOpenApiRequestSchema())
        ->toThrow(\RuntimeException::class, 'PR2');
});
