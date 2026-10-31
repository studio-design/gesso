<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Attribute\SkipOpenApi;
use Studio\Gesso\SkipOpenApiResolver;

class SkipOpenApiResolverTest extends TestCase
{
    use SkipOpenApiResolver;

    #[Test]
    public function no_attribute_returns_false(): void
    {
        $this->assertNull($this->findSkipOpenApiAttribute());
    }

    #[Test]
    #[SkipOpenApi]
    public function method_level_attribute_skips(): void
    {
        $this->assertNotNull($this->findSkipOpenApiAttribute());
        $this->assertSame('', $this->findSkipOpenApiAttribute()->reason);
    }

    #[Test]
    #[SkipOpenApi(reason: 'experimental endpoint')]
    public function method_level_reason_is_resolved(): void
    {
        $this->assertNotNull($this->findSkipOpenApiAttribute());
        $this->assertSame('experimental endpoint', $this->findSkipOpenApiAttribute()->reason);
    }
}
