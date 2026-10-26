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

    /** @internal Shared failure prose for an empty resolved spec name. */
    private const EMPTY_SPEC_MESSAGE = 'openApiSpec() must return a non-empty spec name, but an empty string was returned. '
        . 'Either add #[OpenApiSpec(\'your-spec\')] to your test class or method, '
        . 'override openApiSpec() in your test class, or set spec.default '
        . 'in gesso.php.';

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
