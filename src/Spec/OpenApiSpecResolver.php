<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Spec;

use ReflectionClass;
use ReflectionMethod;
use Studio\OpenApiContractTesting\Attribute\OpenApiSpec;

/**
 * Resolves which OpenAPI spec name a test case should validate against.
 *
 * The resolver evaluates the layers below (highest first; first non-null
 * match wins). When a framework adapter (e.g. the Laravel
 * `ValidatesOpenApiSchema` trait) overrides `openApiSpecFallback()` to
 * delegate to its own user-overridable hook, the last layer splits into two,
 * giving the chain a fifth slot.
 *
 *   0. Per-call explicit override set via {@see self::withExplicitOpenApiSpec()}.
 *      Used by the Pest plugin (`expect(...)->toMatchOpenApiResponseSchema(spec: 'X')`,
 *      see the README's "Pest plugin (Laravel)" section) to pin a single
 *      assertion at a specific spec without touching attributes or config.
 *      Self-clears after the next `resolveOpenApiSpec()` call so it cannot
 *      leak into subsequent assertions.
 *   1. Method-level `#[OpenApiSpec]` attribute on the running test method.
 *   2. Class-level `#[OpenApiSpec]` attribute on the test class.
 *   3. Adapter's user-overridable hook. For the Laravel adapter this is
 *      `openApiSpec()`; a host test class may override it to inject a
 *      class-specific spec without using the attribute.
 *   4. Adapter's ultimate default. For the Laravel adapter this is
 *      `config('openapi-contract-testing.default_spec')`, returned by the
 *      trait's own `openApiSpec()` implementation when not overridden.
 *
 * Adapters that don't override `openApiSpecFallback()` collapse layers 3 and 4
 * into a single fallback (so the resolution chain becomes 0, 1, 2, fallback).
 *
 * Attribute layers return the attribute's raw `name` as-is. `#[OpenApiSpec('')]`
 * is still "set" and short-circuits resolution to the empty string — the
 * consumer treats that as an error with a helpful message (see
 * `ValidatesOpenApiSchema::assertResponseMatchesOpenApiSchema`).
 */
trait OpenApiSpecResolver
{
    /**
     * Per-assertion explicit spec set by callers that need to bypass the
     * attribute / fallback chain (Pest plugin's `spec:` argument). Consumed
     * once by `resolveOpenApiSpec()` so the value cannot leak into the next
     * assertion in the same test method.
     */
    private ?string $explicitOpenApiSpec = null;

    /**
     * Pin the next `resolveOpenApiSpec()` call to a specific spec, bypassing
     * attribute and fallback resolution. Used by the Pest expectation
     * dispatcher to honour the `spec:` argument. Pass `null` to clear.
     *
     * @internal Pest plugin / framework adapters only.
     */
    public function withExplicitOpenApiSpec(?string $spec): void
    {
        $this->explicitOpenApiSpec = $spec;
    }

    protected function openApiSpecFallback(): string
    {
        return '';
    }

    private function resolveOpenApiSpec(): string
    {
        // 0. Per-call explicit override (consumed once).
        if ($this->explicitOpenApiSpec !== null) {
            $spec = $this->explicitOpenApiSpec;
            $this->explicitOpenApiSpec = null;

            return $spec;
        }

        // 1. Method-level #[OpenApiSpec] attribute
        $methodName = $this->name(); // @phpstan-ignore method.notFound
        $refMethod = new ReflectionMethod($this, $methodName);
        $methodAttrs = $refMethod->getAttributes(OpenApiSpec::class);
        if ($methodAttrs !== []) {
            return $methodAttrs[0]->newInstance()->name;
        }

        // 2. Class-level #[OpenApiSpec] attribute
        $refClass = new ReflectionClass($this);
        $classAttrs = $refClass->getAttributes(OpenApiSpec::class);
        if ($classAttrs !== []) {
            return $classAttrs[0]->newInstance()->name;
        }

        // 3. Subclass hook (e.g. openApiSpec() in Laravel trait)
        return $this->openApiSpecFallback();
    }
}
