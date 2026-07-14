<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Compatibility;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Spec\OpenApiRefResolver;

use function dirname;
use function file_get_contents;
use function json_encode;
use function str_replace;

final class ResolverProvenanceBaselineTest extends TestCase
{
    #[Test]
    public function resolved_composition_matches_the_v2_provenance_fixture(): void
    {
        $legacyMarker = 'x-studio-openapi-contract-testing-implicit-schema-name';
        $gessoMarker = 'x-studio-gesso-implicit-schema-name';
        $resolved = OpenApiRefResolver::resolve([
            'components' => ['schemas' => [
                'Cat' => [
                    'type' => 'object',
                    'required' => ['meow'],
                    $legacyMarker => 'Legacy component spoof',
                    $gessoMarker => 'Gesso component spoof',
                ],
            ]],
            'schema' => [
                'oneOf' => [
                    ['$ref' => '#/components/schemas/Cat'],
                    [
                        'type' => 'object',
                        $legacyMarker => 'Legacy inline spoof',
                        $gessoMarker => 'Gesso inline spoof',
                    ],
                ],
            ],
        ]);

        $this->assertSame($gessoMarker, OpenApiRefResolver::IMPLICIT_SCHEMA_NAME_EXTENSION);
        $this->assertSame(
            $this->fixture('v2-resolver-provenance.json'),
            json_encode(
                $resolved,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ) . "\n",
        );
    }

    #[Test]
    public function v2_provenance_differs_from_v1_9_only_by_marker_name(): void
    {
        $normalizedV1 = str_replace(
            'x-studio-openapi-contract-testing-implicit-schema-name',
            'x-studio-gesso-implicit-schema-name',
            $this->fixture('v1.9-resolver-provenance.json'),
        );

        $this->assertSame($normalizedV1, $this->fixture('v2-resolver-provenance.json'));
    }

    private function fixture(string $filename): string
    {
        $contents = file_get_contents(
            dirname(__DIR__, 2) . '/fixtures/compatibility/' . $filename,
        );
        $this->assertIsString($contents);

        return $contents;
    }
}
