<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit;

use const E_USER_DEPRECATED;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Attribute\SkipOpenApi;
use Studio\Gesso\Internal\Deprecations;
use Studio\Gesso\SkipOpenApiResolver;

use function restore_error_handler;
use function set_error_handler;

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

    #[Test]
    #[SkipOpenApi]
    public function should_skip_open_api_is_deprecated_and_still_answers(): void
    {
        Deprecations::resetForTesting();
        $captured = [];
        set_error_handler(static function (int $errno, string $message) use (&$captured): bool {
            $captured[] = [$errno, $message];

            return true;
        });

        try {
            $this->assertTrue($this->shouldSkipOpenApi());
            $this->assertTrue($this->shouldSkipOpenApi());
        } finally {
            restore_error_handler();
            Deprecations::resetForTesting();
        }

        $this->assertCount(1, $captured);
        $this->assertSame(E_USER_DEPRECATED, $captured[0][0]);
        $this->assertStringStartsWith(Deprecations::PREFIX, $captured[0][1]);
        $this->assertStringContainsString('findSkipOpenApiAttribute', $captured[0][1]);
    }
}
