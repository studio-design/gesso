<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting;

use Studio\OpenApiContractTesting\Exception\InvalidOpenApiSpecException;
use Studio\OpenApiContractTesting\Exception\InvalidOpenApiSpecReason;

use function array_key_exists;
use function get_debug_type;
use function is_string;
use function preg_match;
use function sprintf;
use function var_export;

enum OpenApiVersion: string
{
    case V3_0 = '3.0';
    case V3_1 = '3.1';
    case V3_2 = '3.2';

    /**
     * Detect OAS version from a parsed spec array.
     *
     * @param array<string, mixed> $spec
     *
     * @throws InvalidOpenApiSpecException when the version is absent, malformed, or unsupported
     */
    public static function fromSpec(array $spec): self
    {
        $version = $spec['openapi'] ?? null;

        if (is_string($version) &&
            preg_match('/\A(?<major>\d+)\.(?<minor>\d+)\.(?<patch>\d+)\z/D', $version, $matches) === 1
        ) {
            $family = $matches['major'] . '.' . $matches['minor'];

            if ($family === self::V3_0->value) {
                return self::V3_0;
            }

            if ($family === self::V3_1->value) {
                return self::V3_1;
            }

            if ($family === self::V3_2->value) {
                return self::V3_2;
            }
        }

        $received = array_key_exists('openapi', $spec)
            ? sprintf('%s (%s)', var_export($version, true), get_debug_type($version))
            : '<missing>';

        throw new InvalidOpenApiSpecException(
            InvalidOpenApiSpecReason::UnsupportedVersion,
            "Unsupported OpenAPI version: {$received}. The required `openapi` field must be a "
            . 'major.minor.patch string in a supported version family: 3.0.x, 3.1.x, or 3.2.x.',
        );
    }
}
