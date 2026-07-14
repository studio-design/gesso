<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Compatibility;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Studio\OpenApiContractTesting\Tests\Helpers\PublicApiInventory;

use function class_exists;
use function dirname;
use function enum_exists;
use function interface_exists;
use function str_replace;
use function trait_exists;

final class GessoAliasesTest extends TestCase
{
    #[Test]
    public function every_public_v1_type_has_a_forward_gesso_alias(): void
    {
        $root = dirname(__DIR__, 3);
        $inventory = PublicApiInventory::capture(
            $root . '/src',
            'Studio\\OpenApiContractTesting\\',
        );

        foreach ($inventory as $v1Type => $description) {
            $gessoType = str_replace('Studio\\OpenApiContractTesting\\', 'Studio\\Gesso\\', $v1Type);

            $this->assertTrue(
                $this->typeExists($gessoType, $description['kind']),
                "Missing forward namespace alias for {$v1Type}",
            );
            $this->assertSame(
                $v1Type,
                (new ReflectionClass($gessoType))->getName(),
                "The v1 declaration must remain canonical for {$gessoType}",
            );
        }
    }

    #[Test]
    public function internal_and_unknown_types_are_not_aliased(): void
    {
        $this->assertFalse(class_exists('Studio\\Gesso\\Internal\\StackTraceFilter'));
        $this->assertFalse(class_exists('Studio\\Gesso\\MissingType'));
    }

    private function typeExists(string $type, string $kind): bool
    {
        return match ($kind) {
            'class' => class_exists($type),
            'enum' => enum_exists($type),
            'interface' => interface_exists($type),
            'trait' => trait_exists($type),
            default => throw new InvalidArgumentException("Unsupported public API type kind: {$kind}"),
        };
    }
}
