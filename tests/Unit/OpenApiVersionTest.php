<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\OpenApiContractTesting\Exception\InvalidOpenApiSpecException;
use Studio\OpenApiContractTesting\Exception\InvalidOpenApiSpecReason;
use Studio\OpenApiContractTesting\OpenApiVersion;

class OpenApiVersionTest extends TestCase
{
    /** @return array<string, array{array<string, mixed>, OpenApiVersion}> */
    public static function provideDetects_version_from_specCases(): iterable
    {
        return [
            '3.0.0' => [['openapi' => '3.0.0'], OpenApiVersion::V3_0],
            '3.0.3' => [['openapi' => '3.0.3'], OpenApiVersion::V3_0],
            '3.0 future patch' => [['openapi' => '3.0.999'], OpenApiVersion::V3_0],
            '3.1.0' => [['openapi' => '3.1.0'], OpenApiVersion::V3_1],
            '3.1.1' => [['openapi' => '3.1.1'], OpenApiVersion::V3_1],
            '3.1 future patch' => [['openapi' => '3.1.999'], OpenApiVersion::V3_1],
            '3.2.0' => [['openapi' => '3.2.0'], OpenApiVersion::V3_2],
            '3.2 future patch' => [['openapi' => '3.2.999'], OpenApiVersion::V3_2],
        ];
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function provideRejects_invalid_or_unsupported_versionCases(): iterable
    {
        return [
            'missing field' => [[], '<missing>'],
            'empty string' => [['openapi' => ''], "'' (string)"],
            'non-string value' => [['openapi' => 3], '3 (int)'],
            'Swagger 2.0' => [['openapi' => '2.0.0'], "'2.0.0' (string)"],
            'unsupported 3.3' => [['openapi' => '3.3.0'], "'3.3.0' (string)"],
            'unsupported 3.10' => [['openapi' => '3.10.0'], "'3.10.0' (string)"],
            'unknown future version' => [['openapi' => '4.0.0'], "'4.0.0' (string)"],
            'missing patch' => [['openapi' => '3.1'], "'3.1' (string)"],
            'malformed version' => [['openapi' => 'not-a-version'], "'not-a-version' (string)"],
        ];
    }

    /** @param array<string, mixed> $spec */
    #[Test]
    #[DataProvider('provideDetects_version_from_specCases')]
    public function detects_version_from_spec(array $spec, OpenApiVersion $expected): void
    {
        $this->assertSame($expected, OpenApiVersion::fromSpec($spec));
    }

    /** @param array<string, mixed> $spec */
    #[Test]
    #[DataProvider('provideRejects_invalid_or_unsupported_versionCases')]
    public function rejects_invalid_or_unsupported_version(array $spec, string $received): void
    {
        try {
            OpenApiVersion::fromSpec($spec);
            $this->fail('expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::UnsupportedVersion, $e->reason);
            $this->assertStringContainsString($received, $e->getMessage());
            $this->assertStringContainsString('3.0.x, 3.1.x, or 3.2.x', $e->getMessage());
        }
    }
}
