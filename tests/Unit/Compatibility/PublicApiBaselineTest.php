<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Compatibility;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\ConsoleCoverageRenderer;
use Studio\Gesso\Coverage\HtmlCoverageRenderer;
use Studio\Gesso\Coverage\JsonCoverageRenderer;
use Studio\Gesso\Fuzz\ExploredCase;
use Studio\Gesso\Tests\Helpers\PublicApiInventory;
use Studio\Gesso\Tests\Unit\Compatibility\Fixture\PublicApiReturnTypeFixture;

use function dirname;
use function file_get_contents;
use function json_decode;
use function ksort;
use function str_replace;

final class PublicApiBaselineTest extends TestCase
{
    #[Test]
    public function inventory_normalises_self_without_hiding_explicit_class_names(): void
    {
        $inventory = PublicApiInventory::capture(
            __DIR__ . '/Fixture',
            'Studio\\Gesso\\Tests\\Unit\\Compatibility\\Fixture\\',
        );
        $methods = $inventory[PublicApiReturnTypeFixture::class]['methods'];

        $this->assertSame('self', $methods['declaredAsSelf']['return_type']);
        $this->assertSame(
            PublicApiReturnTypeFixture::class,
            $methods['declaredAsClassName']['return_type'],
        );
    }

    #[Test]
    public function inventory_records_constructor_availability_and_visibility(): void
    {
        $root = dirname(__DIR__, 3);
        $inventory = PublicApiInventory::capture(
            $root . '/src',
            'Studio\\Gesso\\',
        );

        $implicitConstructor = $inventory[ConsoleCoverageRenderer::class];
        $this->assertTrue($implicitConstructor['instantiable']);
        $this->assertSame(
            ['kind' => 'implicit', 'visibility' => 'public'],
            $implicitConstructor['constructor'],
        );

        $privateConstructor = $inventory[HtmlCoverageRenderer::class];
        $this->assertFalse($privateConstructor['instantiable']);
        $this->assertSame('declared', $privateConstructor['constructor']['kind']);
        $this->assertSame('private', $privateConstructor['constructor']['visibility']);
    }

    #[Test]
    public function public_php_api_matches_the_v2_baseline(): void
    {
        $root = dirname(__DIR__, 3);
        $baselinePath = $root . '/tests/fixtures/compatibility/v2-public-api.json';
        $baselineJson = file_get_contents($baselinePath);

        $this->assertNotFalse($baselineJson, "Unable to read {$baselinePath}");

        /** @var array<string, array<string, mixed>> $expected */
        $expected = json_decode($baselineJson, true, flags: JSON_THROW_ON_ERROR);
        $actual = PublicApiInventory::capture(
            $root . '/src',
            'Studio\\Gesso\\',
        );

        $this->assertSame(
            $expected,
            $actual,
            'The non-@internal PHP API changed. If intentional, document the migration first, '
            . 'then regenerate with `php scripts/export-public-api.php --write`.',
        );
    }

    #[Test]
    public function v2_public_api_matches_the_documented_contract_changes(): void
    {
        $root = dirname(__DIR__, 3);
        $v1Json = file_get_contents($root . '/tests/fixtures/compatibility/v1.9-public-api.json');
        $v2Json = file_get_contents($root . '/tests/fixtures/compatibility/v2-public-api.json');

        $this->assertNotFalse($v1Json);
        $this->assertNotFalse($v2Json);

        $mappedV1Json = str_replace(
            [
                'Studio\\\\OpenApiContractTesting',
                'OpenApiContractTestingServiceProvider',
            ],
            [
                'Studio\\\\Gesso',
                'GessoServiceProvider',
            ],
            $v1Json,
        );

        /** @var array<string, array<string, mixed>> $expected */
        $expected = json_decode($mappedV1Json, true, flags: JSON_THROW_ON_ERROR);
        $expected[JsonCoverageRenderer::class]['constants']['SCHEMA_VERSION'] = 2;
        $expected[ExploredCase::class]['methods']['bodyAsArray'] = [
            'static' => false,
            'final' => false,
            'abstract' => false,
            'returns_reference' => false,
            'return_type' => '?array',
            'attributes' => [],
            'parameters' => [],
        ];
        $expected[ExploredCase::class]['methods']['uri'] = [
            'static' => false,
            'final' => false,
            'abstract' => false,
            'returns_reference' => false,
            'return_type' => 'string',
            'attributes' => [],
            'parameters' => [[
                'name' => 'prefix',
                'type' => 'string',
                'optional' => true,
                'variadic' => false,
                'by_reference' => false,
                'default' => '',
                'attributes' => [],
            ]],
        ];
        ksort($expected[ExploredCase::class]['methods']);
        /** @var array<string, array<string, mixed>> $actual */
        $actual = json_decode($v2Json, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($expected, $actual);
    }
}
