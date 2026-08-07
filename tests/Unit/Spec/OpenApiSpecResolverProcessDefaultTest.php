<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Spec;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Spec\OpenApiSpecResolver;
use Studio\Gesso\Validation\Support\ValidationPolicyDefaults;

/**
 * Issue #502 (additive half): when neither an `#[OpenApiSpec]` attribute nor
 * an adapter override supplies a spec name, the base fallback consults the
 * process-wide `default_spec` configured through the PHPUnit extension.
 * Adapters that override `openApiSpecFallback()` (Laravel, PSR-7, Symfony)
 * keep their own chains and are not affected.
 */
class OpenApiSpecResolverProcessDefaultTest extends TestCase
{
    use OpenApiSpecResolver;

    protected function tearDown(): void
    {
        ValidationPolicyDefaults::reset();
        parent::tearDown();
    }

    #[Test]
    public function base_fallback_returns_the_process_default_spec(): void
    {
        ValidationPolicyDefaults::configure(defaultSpec: 'from-process-default');

        $this->assertSame('from-process-default', $this->resolveOpenApiSpec());
    }

    #[Test]
    public function base_fallback_returns_the_empty_string_when_unconfigured(): void
    {
        $this->assertSame('', $this->resolveOpenApiSpec());
    }
}
