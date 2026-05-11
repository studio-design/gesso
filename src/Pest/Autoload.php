<?php

declare(strict_types=1);

/**
 * Pest plugin entrypoint. Loaded via `composer.json` `autoload.files` so it
 * runs on every composer-autoloaded request, regardless of whether Pest is
 * installed.
 *
 * Guard: if Pest is not present we return immediately. The library's runtime
 * stays Pest-free; the plugin activates only when a downstream consumer adds
 * `pestphp/pest` to their dev dependencies.
 *
 * Calling `expect()->extend()` outside a Pest test run is harmless — Pest
 * registers the extension globally and it sits dormant until `expect()` is
 * actually invoked. We intentionally use the procedural Pest API here rather
 * than `Pest\Plugin::uses(...)` because we are exposing custom expectations,
 * not auto-mixing traits into test files.
 */

use Pest\Expectation;
use Studio\OpenApiContractTesting\Pest\Expectations as PestExpectations;

if (!\class_exists(Expectation::class)) {
    return;
}

expect()->extend(
    'toMatchOpenApiResponseSchema',
    /**
     * @param string[] $skipResponseCodes
     */
    function (
        ?string $spec = null,
        ?string $method = null,
        ?string $path = null,
        array $skipResponseCodes = [],
    ): Expectation {
        PestExpectations::matchResponse(
            $this->value,
            $spec,
            $method,
            $path,
            $skipResponseCodes,
        );

        return $this;
    },
);

expect()->extend(
    'toMatchOpenApiRequestSchema',
    function (
        ?string $spec = null,
        ?string $method = null,
        ?string $path = null,
    ): Expectation {
        PestExpectations::matchRequest(
            $this->value,
            $spec,
            $method,
            $path,
        );

        return $this;
    },
);
