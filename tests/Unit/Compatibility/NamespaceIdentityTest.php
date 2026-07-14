<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Compatibility;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Studio\Gesso\Tests\Helpers\PublicApiInventory;

use function class_exists;
use function dirname;

final class NamespaceIdentityTest extends TestCase
{
    #[Test]
    public function every_public_type_has_the_canonical_gesso_identity(): void
    {
        $root = dirname(__DIR__, 3);
        $inventory = PublicApiInventory::capture(
            $root . '/src',
            'Studio\\Gesso\\',
        );

        foreach ($inventory as $type => $description) {
            $this->assertStringStartsWith('Studio\\Gesso\\', $type, $type);
            $this->assertSame($type, (new ReflectionClass($type))->getName(), $type);
        }
    }

    #[Test]
    public function the_legacy_namespace_is_not_available_in_v2(): void
    {
        $this->assertFalse(class_exists('Studio\\OpenApiContractTesting\\OpenApiResponseValidator'));
        $this->assertFalse(class_exists('Studio\\OpenApiContractTesting\\Laravel\\ValidatesOpenApiSchema'));
        $this->assertFalse(class_exists('Studio\\OpenApiContractTesting\\MissingType'));
    }

    #[Test]
    public function the_legacy_laravel_provider_short_name_is_not_available_in_v2(): void
    {
        $this->assertFalse(class_exists('Studio\\Gesso\\Laravel\\OpenApiContractTestingServiceProvider'));
    }
}
