<?php

declare(strict_types=1);

namespace Studio\Gesso\Laravel;

use Studio\Gesso\Spec\OpenApiSpecResolver;

use function is_string;

/**
 * Shared Laravel spec-name resolution for traits that are commonly composed.
 *
 * @internal Framework adapter implementation detail.
 */
trait ResolvesOpenApiSpec
{
    use OpenApiSpecResolver;

    protected function openApiSpec(): string
    {
        $spec = config('gesso.default_spec');

        if (!is_string($spec) || $spec === '') {
            return '';
        }

        return $spec;
    }

    protected function openApiSpecFallback(): string
    {
        return $this->openApiSpec();
    }
}
