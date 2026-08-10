<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Spec;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Exception\InvalidOpenApiSpecException;
use Studio\Gesso\Exception\InvalidOpenApiSpecReason;
use Studio\Gesso\OpenApiVersion;
use Studio\Gesso\Spec\OpenApiSchemaDialect;

final class OpenApiSchemaDialectTest extends TestCase
{
    #[Test]
    public function oas_30_always_uses_draft_07(): void
    {
        $this->assertSame(
            OpenApiSchemaDialect::DRAFT_07,
            OpenApiSchemaDialect::fromSpec(['jsonSchemaDialect' => OpenApiSchemaDialect::DRAFT_2020_12], OpenApiVersion::V3_0),
        );
    }

    #[Test]
    public function oas_31_defaults_to_the_openapi_base_dialect(): void
    {
        $this->assertSame(
            OpenApiSchemaDialect::OAS_3_1,
            OpenApiSchemaDialect::fromSpec([], OpenApiVersion::V3_1),
        );
    }

    #[Test]
    public function document_dialect_is_returned_when_supported(): void
    {
        $this->assertSame(
            OpenApiSchemaDialect::DRAFT_07,
            OpenApiSchemaDialect::fromSpec(
                ['jsonSchemaDialect' => OpenApiSchemaDialect::DRAFT_07],
                OpenApiVersion::V3_2,
            ),
        );
    }

    #[Test]
    public function unsupported_document_dialect_fails_explicitly(): void
    {
        try {
            OpenApiSchemaDialect::fromSpec(
                ['jsonSchemaDialect' => 'https://example.com/custom-dialect'],
                OpenApiVersion::V3_1,
            );
            $this->fail('Expected unsupported dialect to throw.');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::UnsupportedJsonSchemaDialect, $e->reason);
            $this->assertStringContainsString('custom-dialect', $e->getMessage());
        }
    }

    /**
     * `$ref`-sibling handling alone cannot tell dialects apart — 2019-09 and
     * 2020-12 agree there and disagree elsewhere — so the comparison is on the
     * dialect itself, canonically rather than literally.
     */
    #[Test]
    public function same_dialect_compares_the_dialect_canonically(): void
    {
        $this->assertTrue(OpenApiSchemaDialect::sameDialect(
            OpenApiSchemaDialect::OAS_3_1,
            OpenApiSchemaDialect::DRAFT_2020_12,
        ), 'the OAS 3.1 base dialect is validated as 2020-12');
        $this->assertTrue(OpenApiSchemaDialect::sameDialect(
            'http://json-schema.org/draft-07/schema#',
            'https://json-schema.org/draft/07/schema',
        ), 'scheme, trailing # and draft-/draft- spelling do not change meaning');

        $this->assertFalse(OpenApiSchemaDialect::sameDialect(
            'https://json-schema.org/draft/2019-09/schema',
            OpenApiSchemaDialect::DRAFT_2020_12,
        ));
        $this->assertFalse(OpenApiSchemaDialect::sameDialect(
            'http://json-schema.org/draft-06/schema#',
            OpenApiSchemaDialect::DRAFT_07,
        ));
    }

    #[Test]
    public function malformed_document_dialect_fails_explicitly(): void
    {
        $this->expectException(InvalidOpenApiSpecException::class);
        $this->expectExceptionMessage('expected a non-empty URI string');

        OpenApiSchemaDialect::fromSpec(['jsonSchemaDialect' => null], OpenApiVersion::V3_1);
    }
}
