<?php

declare(strict_types=1);

namespace Studio\Gesso\Spec;

use Studio\Gesso\Exception\InvalidOpenApiSpecException;
use Studio\Gesso\Exception\InvalidOpenApiSpecReason;
use Studio\Gesso\OpenApiVersion;

use function array_key_exists;
use function get_debug_type;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * Resolve the JSON Schema dialect used by Schema Objects in an OAS document.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class OpenApiSchemaDialect
{
    public const DRAFT_07 = 'http://json-schema.org/draft-07/schema#';
    public const DRAFT_2020_12 = 'https://json-schema.org/draft/2020-12/schema';
    public const OAS_3_1 = 'https://spec.openapis.org/oas/3.1/dialect/base';

    /**
     * @param array<string, mixed> $spec
     */
    public static function fromSpec(array $spec, OpenApiVersion $version): string
    {
        if ($version === OpenApiVersion::V3_0) {
            return self::DRAFT_07;
        }

        if (!array_key_exists('jsonSchemaDialect', $spec)) {
            return self::OAS_3_1;
        }

        $dialect = $spec['jsonSchemaDialect'];
        if (!is_string($dialect) || $dialect === '') {
            throw new InvalidOpenApiSpecException(
                InvalidOpenApiSpecReason::UnsupportedJsonSchemaDialect,
                sprintf(
                    'Unsupported `jsonSchemaDialect`: expected a non-empty URI string, got %s.',
                    get_debug_type($dialect),
                ),
            );
        }

        self::assertSupported($dialect, 'jsonSchemaDialect');

        return $dialect;
    }

    public static function assertSupported(string $dialect, string $location = '$schema'): void
    {
        if ($dialect === self::OAS_3_1 || preg_match(
            '~^https?://json-schema\.org/draft(?:/|-)(?:06|07|2019-09|2020-12)/schema#?$~i',
            $dialect,
        ) === 1) {
            return;
        }

        throw new InvalidOpenApiSpecException(
            InvalidOpenApiSpecReason::UnsupportedJsonSchemaDialect,
            sprintf(
                "Unsupported JSON Schema dialect in `%s`: '%s'. Supported dialects are the OpenAPI 3.1 base dialect and JSON Schema Draft 06, Draft 07, 2019-09, or 2020-12.",
                $location,
                $dialect,
            ),
        );
    }

    /**
     * True when the dialect treats `$ref` as an in-place applicator, so
     * keywords adjacent to it apply alongside the resolved target.
     *
     * JSON Schema 2019-09 made `$ref` an applicator ("other keywords can
     * appear alongside of `$ref` in the same schema object"); Draft 06/07
     * require the opposite ("All other properties in a `$ref` object MUST be
     * ignored"), and OAS 3.0's Reference Object matches Draft 07 there.
     * Unrecognised dialects answer `false` — the conservative reading, and
     * the historical behaviour.
     *
     * @see https://json-schema.org/draft/2020-12/json-schema-core#name-direct-references-with-ref
     * @see https://json-schema.org/draft-07/draft-handrews-json-schema-01#rfc.section.8.3
     */
    public static function appliesRefSiblings(string $dialect): bool
    {
        if ($dialect === self::OAS_3_1) {
            return true;
        }

        return preg_match(
            '~^https?://json-schema\.org/draft(?:/|-)(?:2019-09|2020-12)/schema#?$~i',
            $dialect,
        ) === 1;
    }

    public static function validatorDialect(string $dialect): string
    {
        self::assertSupported($dialect);

        // Opis does not register the OAS vocabulary URI, but OAS declares its
        // base vocabulary optional and builds the dialect on JSON Schema
        // 2020-12. OpenAPI-only semantics are handled by the converter.
        return $dialect === self::OAS_3_1 ? self::DRAFT_2020_12 : $dialect;
    }
}
