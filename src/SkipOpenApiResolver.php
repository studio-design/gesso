<?php

declare(strict_types=1);

namespace Studio\Gesso;

use ReflectionClass;
use ReflectionMethod;
use Studio\Gesso\Attribute\SkipOpenApi;
use Studio\Gesso\Internal\Deprecations;

/**
 * @internal Implementation detail shared by the public Laravel test trait.
 */
trait SkipOpenApiResolver
{
    /**
     * @deprecated Use `$this->findSkipOpenApiAttribute() !== null`. Kept
     *             because the method is composed into the public
     *             `ValidatesOpenApiSchema` trait, whose private members the
     *             v2 compatibility policy freezes. Removed in Gesso 3.0.
     */
    private function shouldSkipOpenApi(): bool
    {
        Deprecations::notice(
            id: 'trait.skip_open_api_resolver.should_skip_open_api',
            subject: 'SkipOpenApiResolver::shouldSkipOpenApi()',
            replacement: '$this->findSkipOpenApiAttribute() !== null',
            removedIn: '3.0',
        );

        return $this->findSkipOpenApiAttribute() !== null;
    }

    private function findSkipOpenApiAttribute(): ?SkipOpenApi
    {
        $methodName = $this->name(); // @phpstan-ignore method.notFound
        $refMethod = new ReflectionMethod($this, $methodName);
        $methodAttrs = $refMethod->getAttributes(SkipOpenApi::class);
        if ($methodAttrs !== []) {
            return $methodAttrs[0]->newInstance();
        }

        $refClass = new ReflectionClass($this);
        $classAttrs = $refClass->getAttributes(SkipOpenApi::class);
        if ($classAttrs !== []) {
            return $classAttrs[0]->newInstance();
        }

        return null;
    }
}
