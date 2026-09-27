<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Attribute\SkipOpenApi;
use Studio\Gesso\SkipOpenApiResolver;

#[SkipOpenApi(reason: 'class-level')]
class SkipOpenApiResolverClassLevelTest extends TestCase
{
    use SkipOpenApiResolver;

    #[Test]
    public function class_level_attribute_skips(): void
    {
        $this->assertNotNull($this->findSkipOpenApiAttribute());
        $this->assertSame('class-level', $this->findSkipOpenApiAttribute()->reason);
    }

    #[Test]
    #[SkipOpenApi(reason: 'method-level')]
    public function method_level_reason_overrides_class_level(): void
    {
        $this->assertNotNull($this->findSkipOpenApiAttribute());
        $this->assertSame('method-level', $this->findSkipOpenApiAttribute()->reason);
    }
}
