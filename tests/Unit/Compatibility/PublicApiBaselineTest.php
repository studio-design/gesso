<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Compatibility;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\ConsoleCoverageRenderer;
use Studio\Gesso\Coverage\CoverageSidecarEnvelope;
use Studio\Gesso\Coverage\CoverageSidecarReader;
use Studio\Gesso\Coverage\CoverageSidecarWriter;
use Studio\Gesso\Coverage\CoverageThresholdEvaluator;
use Studio\Gesso\Coverage\HtmlCoverageRenderer;
use Studio\Gesso\Coverage\InvalidCoverageOutputPathException;
use Studio\Gesso\Coverage\InvalidThresholdConfigurationException;
use Studio\Gesso\Coverage\JsonCoverageRenderer;
use Studio\Gesso\Coverage\JUnitCoverageRenderer;
use Studio\Gesso\Coverage\MarkdownCoverageRenderer;
use Studio\Gesso\Exception\EnumBindingReason;
use Studio\Gesso\Exception\InvalidOpenApiSpecReason;
use Studio\Gesso\Fuzz\ContractCheck;
use Studio\Gesso\Fuzz\ContractCheckFailure;
use Studio\Gesso\Fuzz\ContractCheckPlan;
use Studio\Gesso\Fuzz\ContractCheckSkip;
use Studio\Gesso\Fuzz\ContractCheckSummary;
use Studio\Gesso\Fuzz\ExploredCase;
use Studio\Gesso\Fuzz\GeneratedResponseCase;
use Studio\Gesso\Fuzz\GeneratedResponseCases;
use Studio\Gesso\Fuzz\OpenApiContractChecks;
use Studio\Gesso\Fuzz\OpenApiResponseExplorer;
use Studio\Gesso\JsonValidationResultRenderer;
use Studio\Gesso\Laravel\Commands\OpenApiRoutesCommand;
use Studio\Gesso\Laravel\ExploresOpenApiEndpoint;
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;
use Studio\Gesso\OpenApiRequestValidator;
use Studio\Gesso\OpenApiResponseValidator;
use Studio\Gesso\OpenApiValidationResult;
use Studio\Gesso\Pest\Expectations;
use Studio\Gesso\PHPUnit\ConsoleOutput;
use Studio\Gesso\PHPUnit\InvalidStrictRequiredConfigurationException;
use Studio\Gesso\SchemaContext;
use Studio\Gesso\SkipOpenApiResolver;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Spec\RemoteSpecSource;
use Studio\Gesso\Tests\Helpers\PublicApiInventory;
use Studio\Gesso\Tests\Unit\Compatibility\Fixture\PublicApiImplicitConstructorFixture;
use Studio\Gesso\Tests\Unit\Compatibility\Fixture\PublicApiInternalTraitConsumerFixture;
use Studio\Gesso\Tests\Unit\Compatibility\Fixture\PublicApiPrivateConstructorFixture;
use Studio\Gesso\Tests\Unit\Compatibility\Fixture\PublicApiReturnTypeFixture;
use Studio\Gesso\Tests\Unit\Compatibility\Fixture\PublicApiTraitSurfaceConsumerFixture;
use Studio\Gesso\Tests\Unit\Compatibility\Fixture\PublicApiTraitSurfaceFixture;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;
use Studio\Gesso\ValidationIssue;
use Studio\Gesso\ValidationOutput;
use Studio\Gesso\ValidationOutputFormat;

use function array_keys;
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
        $inventory = PublicApiInventory::capture(
            __DIR__ . '/Fixture',
            'Studio\\Gesso\\Tests\\Unit\\Compatibility\\Fixture\\',
        );

        $implicitConstructor = $inventory[PublicApiImplicitConstructorFixture::class];
        $this->assertTrue($implicitConstructor['instantiable']);
        $this->assertSame(
            ['kind' => 'implicit', 'visibility' => 'public'],
            $implicitConstructor['constructor'],
        );

        $privateConstructor = $inventory[PublicApiPrivateConstructorFixture::class];
        $this->assertFalse($privateConstructor['instantiable']);
        $this->assertSame('declared', $privateConstructor['constructor']['kind']);
        $this->assertSame('private', $privateConstructor['constructor']['visibility']);
    }

    #[Test]
    public function inventory_omits_internal_traits_from_the_traits_list(): void
    {
        $inventory = PublicApiInventory::capture(
            __DIR__ . '/Fixture',
            'Studio\\Gesso\\Tests\\Unit\\Compatibility\\Fixture\\',
        );

        $this->assertSame(
            [PublicApiTraitSurfaceFixture::class],
            $inventory[PublicApiInternalTraitConsumerFixture::class]['traits'],
        );
    }

    #[Test]
    public function inventory_records_the_complete_trait_composition_surface(): void
    {
        $consumer = new PublicApiTraitSurfaceConsumerFixture();
        $this->assertSame('public', $consumer->publicProperty);

        $inventory = PublicApiInventory::capture(
            __DIR__ . '/Fixture',
            'Studio\\Gesso\\Tests\\Unit\\Compatibility\\Fixture\\',
        );
        $surface = $inventory[PublicApiTraitSurfaceFixture::class]['trait_composition'];

        $this->assertSame(
            ['PRIVATE_CONSTANT', 'PROTECTED_CONSTANT', 'PUBLIC_CONSTANT'],
            array_keys($surface['constants']),
        );
        $this->assertSame('private', $surface['constants']['PRIVATE_CONSTANT']['visibility']);
        $this->assertSame('protected', $surface['constants']['PROTECTED_CONSTANT']['visibility']);
        $this->assertSame('public', $surface['constants']['PUBLIC_CONSTANT']['visibility']);

        $this->assertSame(
            ['privateProperty', 'protectedProperty', 'publicProperty'],
            array_keys($surface['properties']),
        );
        $this->assertSame('private', $surface['properties']['privateProperty']['visibility']);
        $this->assertSame('protected', $surface['properties']['protectedProperty']['visibility']);
        $this->assertSame('public', $surface['properties']['publicProperty']['visibility']);

        $this->assertSame(
            ['privateMethod', 'protectedMethod', 'publicMethod'],
            array_keys($surface['methods']),
        );
        $this->assertSame('private', $surface['methods']['privateMethod']['visibility']);
        $this->assertSame('protected', $surface['methods']['protectedMethod']['visibility']);
        $this->assertSame('public', $surface['methods']['publicMethod']['visibility']);
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
        /** @var array<string, array<string, mixed>> $actual */
        $actual = json_decode($v2Json, true, flags: JSON_THROW_ON_ERROR);
        unset(
            $expected[InvalidOpenApiSpecReason::class]['cases']['ExternalRef'],
            $expected[InvalidOpenApiSpecReason::class]['cases']['RemoteRefNotImplemented'],
        );
        $reasonCases = [];
        foreach ($expected[InvalidOpenApiSpecReason::class]['cases'] as $name => $value) {
            $reasonCases[$name] = $value;
            if ($name === 'LocalRefNotFound') {
                $reasonCases['LocalRefOutsideAllowedRoot'] = null;
            }
            if ($name === 'RemoteRefDisallowed') {
                $reasonCases['RemoteRefHostDisallowed'] = null;
            }
            if ($name === 'RemoteRefFetchFailed') {
                // #407: authenticated remote spec sources (minor additions).
                $reasonCases['RemoteSpecAuthEnvMissing'] = null;
                $reasonCases['RemoteSpecHashMismatch'] = null;
            }
        }
        $expected[InvalidOpenApiSpecReason::class]['cases'] = $reasonCases;
        $bindingReasonCases = [];
        foreach ($expected[EnumBindingReason::class]['cases'] as $name => $value) {
            $bindingReasonCases[$name] = $value;
            if ($name === 'MalformedJson') {
                // #433: YAML bound enum files (minor additions).
                $bindingReasonCases['MalformedYaml'] = null;
                $bindingReasonCases['YamlLibraryMissing'] = null;
            }
        }
        $expected[EnumBindingReason::class]['cases'] = $bindingReasonCases;
        $expected[OpenApiSpecLoader::class]['constants']['DEFAULT_MAX_REMOTE_REF_BYTES'] = 10_485_760;
        $expected[OpenApiSpecLoader::class]['methods']['configure']['parameters'][] = [
            'name' => 'allowedRemoteRefHosts',
            'type' => 'array',
            'optional' => true,
            'variadic' => false,
            'by_reference' => false,
            'default' => [],
            'attributes' => [],
        ];
        $expected[OpenApiSpecLoader::class]['methods']['configure']['parameters'][] = [
            'name' => 'maxRemoteRefBytes',
            'type' => 'int',
            'optional' => true,
            'variadic' => false,
            'by_reference' => false,
            'default' => [
                'constant' => 'self::DEFAULT_MAX_REMOTE_REF_BYTES',
                'value' => 10_485_760,
            ],
            'attributes' => [],
        ];
        // #407: named specs may resolve to HTTP(S) entry documents.
        $expected[OpenApiSpecLoader::class]['methods']['configure']['parameters'][] = [
            'name' => 'remoteSpecs',
            'type' => 'array',
            'optional' => true,
            'variadic' => false,
            'by_reference' => false,
            'default' => [],
            'attributes' => [],
        ];
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
        $expected[ExploredCase::class]['methods']['curlSnippet']['parameters'][] = [
            'name' => 'redactSensitiveHeaders',
            'type' => 'bool',
            'optional' => true,
            'variadic' => false,
            'by_reference' => false,
            'default' => true,
            'attributes' => [],
        ];
        ksort($expected[ExploredCase::class]['methods']);

        $responseValidatorConstructor = $expected[OpenApiResponseValidator::class]['methods']['__construct'];
        $maxErrors = $responseValidatorConstructor['parameters'][0];
        $skipResponseCodes = $responseValidatorConstructor['parameters'][1];
        $expected[OpenApiResponseValidator::class]['methods']['__construct']['parameters'] = [
            [
                'name' => 'strictRequiredTracker',
                'type' => StrictRequiredTracker::class,
                'optional' => false,
                'variadic' => false,
                'by_reference' => false,
                'default' => ['unavailable' => true],
                'attributes' => [],
            ],
            $maxErrors,
            $skipResponseCodes,
        ];

        $expected[OpenApiValidationResult::class]['methods']['failure']['parameters'][] = [
            'name' => 'issues',
            'type' => 'array',
            'optional' => true,
            'variadic' => false,
            'by_reference' => false,
            'default' => [],
            'attributes' => [],
        ];
        $expected[OpenApiValidationResult::class]['methods']['issues'] = [
            'static' => false,
            'final' => false,
            'abstract' => false,
            'returns_reference' => false,
            'return_type' => 'array',
            'attributes' => [],
            'parameters' => [],
        ];
        ksort($expected[OpenApiValidationResult::class]['methods']);

        // #436: the raw query string lets non-exploded query styles split
        // before percent-decoding (minor addition).
        $expected[OpenApiRequestValidator::class]['methods']['validate']['parameters'][] = [
            'name' => 'rawQueryString',
            'type' => '?string',
            'optional' => true,
            'variadic' => false,
            'by_reference' => false,
            'default' => null,
            'attributes' => [],
        ];

        // New public class in v2.x (#282 stage 1): structured issue DTO.
        $issueStringProperty = static fn(): array => [
            'type' => 'string',
            'static' => false,
            'readonly' => true,
            'default' => ['unavailable' => true],
        ];
        $issueNullableProperty = static fn(): array => [
            'type' => '?string',
            'static' => false,
            'readonly' => true,
            'default' => ['unavailable' => true],
        ];
        $issueRequiredParam = static fn(string $name): array => [
            'name' => $name,
            'type' => 'string',
            'optional' => false,
            'variadic' => false,
            'by_reference' => false,
            'default' => ['unavailable' => true],
            'attributes' => [],
        ];
        $issueOptionalParam = static fn(string $name): array => [
            'name' => $name,
            'type' => '?string',
            'optional' => true,
            'variadic' => false,
            'by_reference' => false,
            'default' => null,
            'attributes' => [],
        ];
        $expected[ValidationIssue::class] = [
            'kind' => 'class',
            'final' => true,
            'abstract' => false,
            'readonly' => true,
            'instantiable' => true,
            'constructor' => ['kind' => 'declared', 'visibility' => 'public'],
            'parent' => null,
            'interfaces' => [],
            'traits' => [],
            'attributes' => [],
            'backing_type' => null,
            'cases' => [],
            'constants' => [],
            'properties' => [
                'category' => $issueStringProperty(),
                'contentType' => $issueNullableProperty(),
                'instancePath' => $issueNullableProperty(),
                'keyword' => $issueNullableProperty(),
                'message' => $issueStringProperty(),
                'method' => $issueNullableProperty(),
                // #402: names the parameter / response header / security
                // scheme a non-body issue is about (minor addition).
                'parameter' => $issueNullableProperty(),
                'path' => $issueNullableProperty(),
                'statusCode' => $issueNullableProperty(),
            ],
            'methods' => [
                '__construct' => [
                    'static' => false,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => null,
                    'attributes' => [],
                    'parameters' => [
                        $issueRequiredParam('category'),
                        $issueRequiredParam('message'),
                        $issueOptionalParam('instancePath'),
                        $issueOptionalParam('keyword'),
                        $issueOptionalParam('method'),
                        $issueOptionalParam('path'),
                        $issueOptionalParam('statusCode'),
                        $issueOptionalParam('contentType'),
                        $issueOptionalParam('parameter'),
                    ],
                ],
            ],
        ];
        // New public class in v2.x (#282 stage 2): versioned JSON output for
        // validation results.
        $expected[JsonValidationResultRenderer::class] = [
            'kind' => 'class',
            'final' => true,
            'abstract' => false,
            'readonly' => false,
            'instantiable' => true,
            'constructor' => ['kind' => 'implicit', 'visibility' => 'public'],
            'parent' => null,
            'interfaces' => [],
            'traits' => [],
            'attributes' => [],
            'backing_type' => null,
            'cases' => [],
            'constants' => ['SCHEMA_VERSION' => 1],
            'properties' => [],
            'methods' => [
                'render' => [
                    'static' => true,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => 'string',
                    'attributes' => [],
                    'parameters' => [
                        [
                            'name' => 'result',
                            'type' => 'Studio\Gesso\OpenApiValidationResult',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => ['unavailable' => true],
                            'attributes' => [],
                        ],
                        [
                            'name' => 'reproduceCommand',
                            'type' => '?string',
                            'optional' => true,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => null,
                            'attributes' => [],
                        ],
                    ],
                ],
            ],
        ];
        // New public symbols in v2.x (#282 stage 3): process-wide validation
        // failure output format selection.
        $expected[ValidationOutput::class] = [
            'kind' => 'class',
            'final' => true,
            'abstract' => false,
            'readonly' => false,
            'instantiable' => false,
            'constructor' => ['kind' => 'declared', 'visibility' => 'private'],
            'parent' => null,
            'interfaces' => [],
            'traits' => [],
            'attributes' => [],
            'backing_type' => null,
            'cases' => [],
            'constants' => [],
            'properties' => [],
            'methods' => [
                'format' => [
                    'static' => true,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => 'Studio\Gesso\ValidationOutputFormat',
                    'attributes' => [],
                    'parameters' => [],
                ],
                'reset' => [
                    'static' => true,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => 'void',
                    'attributes' => [],
                    'parameters' => [],
                ],
                'use' => [
                    'static' => true,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => 'void',
                    'attributes' => [],
                    'parameters' => [
                        [
                            'name' => 'format',
                            'type' => 'Studio\Gesso\ValidationOutputFormat',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => ['unavailable' => true],
                            'attributes' => [],
                        ],
                    ],
                ],
            ],
        ];
        $expected[ValidationOutputFormat::class] = [
            'kind' => 'enum',
            'final' => true,
            'abstract' => false,
            'readonly' => false,
            'instantiable' => false,
            'constructor' => null,
            'parent' => null,
            'interfaces' => ['BackedEnum', 'UnitEnum'],
            'traits' => [],
            'attributes' => [],
            'backing_type' => 'string',
            'cases' => ['Text' => 'text', 'Json' => 'json'],
            'constants' => [],
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'static' => false,
                    'readonly' => true,
                    'default' => ['unavailable' => true],
                ],
                'value' => [
                    'type' => 'string',
                    'static' => false,
                    'readonly' => true,
                    'default' => ['unavailable' => true],
                ],
            ],
            'methods' => [
                'cases' => [
                    'static' => true,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => 'array',
                    'attributes' => [],
                    'parameters' => [],
                ],
                'from' => [
                    'static' => true,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => 'static',
                    'attributes' => [],
                    'parameters' => [
                        [
                            'name' => 'value',
                            'type' => 'string|int',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => ['unavailable' => true],
                            'attributes' => [],
                        ],
                    ],
                ],
                'tryFrom' => [
                    'static' => true,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => '?static',
                    'attributes' => [],
                    'parameters' => [
                        [
                            'name' => 'value',
                            'type' => 'string|int',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => ['unavailable' => true],
                            'attributes' => [],
                        ],
                    ],
                ],
            ],
        ];
        // #420: @internal traits are an implementation detail and are no
        // longer recorded in a consuming class's trait list. The v1.9 baseline
        // still names them, so drop them from the expectation.
        $expected[ExploresOpenApiEndpoint::class]['traits'] = [];
        $expected[ValidatesOpenApiSchema::class]['traits'] = [];

        // #444: response payload exploration adds three public types and one
        // Laravel convenience method. Their exact signatures remain pinned by
        // public_php_api_matches_the_v2_baseline(); this inventory declares
        // the precise new symbols that are intentional relative to v1.9.
        foreach ([
            GeneratedResponseCase::class,
            GeneratedResponseCases::class,
            OpenApiResponseExplorer::class,
        ] as $responseExplorerType) {
            $expected[$responseExplorerType] = $actual[$responseExplorerType];
        }
        $explorerMethods = [];
        foreach ($expected[ExploresOpenApiEndpoint::class]['methods'] as $name => $method) {
            if ($name === 'exploreSpec') {
                $explorerMethods['exploreResponseSchema'] =
                    $actual[ExploresOpenApiEndpoint::class]['methods']['exploreResponseSchema'];
            }
            $explorerMethods[$name] = $method;
        }
        $expected[ExploresOpenApiEndpoint::class]['methods'] = $explorerMethods;

        // New public symbols in v2.x (named contract checks): the
        // ContractCheck enum, its ContractCheckFailure/ContractCheckSkip
        // result DTOs, ContractCheckSummary, and the
        // OpenApiContractChecks/ContractCheckPlan fluent entry point.
        $expected[ContractCheck::class] = [
            'kind' => 'enum',
            'final' => true,
            'abstract' => false,
            'readonly' => false,
            'instantiable' => false,
            'constructor' => null,
            'parent' => null,
            'interfaces' => [
                'BackedEnum',
                'UnitEnum',
            ],
            'traits' => [],
            'attributes' => [],
            'backing_type' => 'string',
            'cases' => [
                'UnsupportedMethod' => 'unsupported_method',
            ],
            'constants' => [],
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'static' => false,
                    'readonly' => true,
                    'default' => [
                        'unavailable' => true,
                    ],
                ],
                'value' => [
                    'type' => 'string',
                    'static' => false,
                    'readonly' => true,
                    'default' => [
                        'unavailable' => true,
                    ],
                ],
            ],
            'methods' => [
                'cases' => [
                    'static' => true,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => 'array',
                    'attributes' => [],
                    'parameters' => [],
                ],
                'defaultExpectedStatuses' => [
                    'static' => false,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => 'array',
                    'attributes' => [],
                    'parameters' => [],
                ],
                'from' => [
                    'static' => true,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => 'static',
                    'attributes' => [],
                    'parameters' => [
                        [
                            'name' => 'value',
                            'type' => 'string|int',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => [
                                'unavailable' => true,
                            ],
                            'attributes' => [],
                        ],
                    ],
                ],
                'tryFrom' => [
                    'static' => true,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => '?static',
                    'attributes' => [],
                    'parameters' => [
                        [
                            'name' => 'value',
                            'type' => 'string|int',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => [
                                'unavailable' => true,
                            ],
                            'attributes' => [],
                        ],
                    ],
                ],
            ],
        ];

        $expected[ContractCheckFailure::class] = [
            'kind' => 'class',
            'final' => true,
            'abstract' => false,
            'readonly' => true,
            'instantiable' => true,
            'constructor' => [
                'kind' => 'declared',
                'visibility' => 'public',
            ],
            'parent' => null,
            'interfaces' => [],
            'traits' => [],
            'attributes' => [],
            'backing_type' => null,
            'cases' => [],
            'constants' => [],
            'properties' => [
                'actualStatus' => [
                    'type' => 'int',
                    'static' => false,
                    'readonly' => true,
                    'default' => [
                        'unavailable' => true,
                    ],
                ],
                'case' => [
                    'type' => 'Studio\\Gesso\\Fuzz\\ExploredCase',
                    'static' => false,
                    'readonly' => true,
                    'default' => [
                        'unavailable' => true,
                    ],
                ],
                'check' => [
                    'type' => 'Studio\\Gesso\\Fuzz\\ContractCheck',
                    'static' => false,
                    'readonly' => true,
                    'default' => [
                        'unavailable' => true,
                    ],
                ],
                'expectedStatuses' => [
                    'type' => 'array',
                    'static' => false,
                    'readonly' => true,
                    'default' => [
                        'unavailable' => true,
                    ],
                ],
                'method' => [
                    'type' => 'string',
                    'static' => false,
                    'readonly' => true,
                    'default' => [
                        'unavailable' => true,
                    ],
                ],
                'operationId' => [
                    'type' => '?string',
                    'static' => false,
                    'readonly' => true,
                    'default' => [
                        'unavailable' => true,
                    ],
                ],
                'path' => [
                    'type' => 'string',
                    'static' => false,
                    'readonly' => true,
                    'default' => [
                        'unavailable' => true,
                    ],
                ],
            ],
            'methods' => [
                '__construct' => [
                    'static' => false,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => null,
                    'attributes' => [],
                    'parameters' => [
                        [
                            'name' => 'check',
                            'type' => 'Studio\\Gesso\\Fuzz\\ContractCheck',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => [
                                'unavailable' => true,
                            ],
                            'attributes' => [],
                        ],
                        [
                            'name' => 'method',
                            'type' => 'string',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => [
                                'unavailable' => true,
                            ],
                            'attributes' => [],
                        ],
                        [
                            'name' => 'path',
                            'type' => 'string',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => [
                                'unavailable' => true,
                            ],
                            'attributes' => [],
                        ],
                        [
                            'name' => 'operationId',
                            'type' => '?string',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => [
                                'unavailable' => true,
                            ],
                            'attributes' => [],
                        ],
                        [
                            'name' => 'expectedStatuses',
                            'type' => 'array',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => [
                                'unavailable' => true,
                            ],
                            'attributes' => [],
                        ],
                        [
                            'name' => 'actualStatus',
                            'type' => 'int',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => [
                                'unavailable' => true,
                            ],
                            'attributes' => [],
                        ],
                        [
                            'name' => 'case',
                            'type' => 'Studio\\Gesso\\Fuzz\\ExploredCase',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => [
                                'unavailable' => true,
                            ],
                            'attributes' => [],
                        ],
                    ],
                ],
                'describe' => [
                    'static' => false,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => 'string',
                    'attributes' => [],
                    'parameters' => [],
                ],
            ],
        ];

        $expected[ContractCheckPlan::class] = [
            'kind' => 'class',
            'final' => true,
            'abstract' => false,
            'readonly' => false,
            'instantiable' => true,
            'constructor' => [
                'kind' => 'declared',
                'visibility' => 'public',
            ],
            'parent' => null,
            'interfaces' => [],
            'traits' => [],
            'attributes' => [],
            'backing_type' => null,
            'cases' => [],
            'constants' => [],
            'properties' => [],
            'methods' => [
                '__construct' => [
                    'static' => false,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => null,
                    'attributes' => [],
                    'parameters' => [
                        [
                            'name' => 'specName',
                            'type' => 'string',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => [
                                'unavailable' => true,
                            ],
                            'attributes' => [],
                        ],
                        [
                            'name' => 'seed',
                            'type' => 'int',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => [
                                'unavailable' => true,
                            ],
                            'attributes' => [],
                        ],
                    ],
                ],
                'authenticateUsing' => [
                    'static' => false,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => 'self',
                    'attributes' => [],
                    'parameters' => [
                        [
                            'name' => 'callback',
                            'type' => 'callable',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => [
                                'unavailable' => true,
                            ],
                            'attributes' => [],
                        ],
                    ],
                ],
                'checks' => [
                    'static' => false,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => 'self',
                    'attributes' => [],
                    'parameters' => [
                        [
                            'name' => 'checks',
                            'type' => 'array',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => [
                                'unavailable' => true,
                            ],
                            'attributes' => [],
                        ],
                    ],
                ],
                'dispatchUsing' => [
                    'static' => false,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => 'self',
                    'attributes' => [],
                    'parameters' => [
                        [
                            'name' => 'callback',
                            'type' => 'callable',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => [
                                'unavailable' => true,
                            ],
                            'attributes' => [],
                        ],
                    ],
                ],
                'excludeMethods' => [
                    'static' => false,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => 'self',
                    'attributes' => [],
                    'parameters' => [
                        [
                            'name' => 'methods',
                            'type' => 'array',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => [
                                'unavailable' => true,
                            ],
                            'attributes' => [],
                        ],
                    ],
                ],
                'excludeOperations' => [
                    'static' => false,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => 'self',
                    'attributes' => [],
                    'parameters' => [
                        [
                            'name' => 'operationIds',
                            'type' => 'array',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => [
                                'unavailable' => true,
                            ],
                            'attributes' => [],
                        ],
                    ],
                ],
                'excludePaths' => [
                    'static' => false,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => 'self',
                    'attributes' => [],
                    'parameters' => [
                        [
                            'name' => 'paths',
                            'type' => 'array',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => [
                                'unavailable' => true,
                            ],
                            'attributes' => [],
                        ],
                    ],
                ],
                'excludeTags' => [
                    'static' => false,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => 'self',
                    'attributes' => [],
                    'parameters' => [
                        [
                            'name' => 'tags',
                            'type' => 'array',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => [
                                'unavailable' => true,
                            ],
                            'attributes' => [],
                        ],
                    ],
                ],
                'expectedStatuses' => [
                    'static' => false,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => 'self',
                    'attributes' => [],
                    'parameters' => [
                        [
                            'name' => 'check',
                            'type' => 'Studio\\Gesso\\Fuzz\\ContractCheck',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => [
                                'unavailable' => true,
                            ],
                            'attributes' => [],
                        ],
                        [
                            'name' => 'statuses',
                            'type' => 'array',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => [
                                'unavailable' => true,
                            ],
                            'attributes' => [],
                        ],
                    ],
                ],
                'includeDeprecated' => [
                    'static' => false,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => 'self',
                    'attributes' => [],
                    'parameters' => [
                        [
                            'name' => 'include',
                            'type' => 'bool',
                            'optional' => true,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => true,
                            'attributes' => [],
                        ],
                    ],
                ],
                'includeMethods' => [
                    'static' => false,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => 'self',
                    'attributes' => [],
                    'parameters' => [
                        [
                            'name' => 'methods',
                            'type' => 'array',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => [
                                'unavailable' => true,
                            ],
                            'attributes' => [],
                        ],
                    ],
                ],
                'includeOperations' => [
                    'static' => false,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => 'self',
                    'attributes' => [],
                    'parameters' => [
                        [
                            'name' => 'operationIds',
                            'type' => 'array',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => [
                                'unavailable' => true,
                            ],
                            'attributes' => [],
                        ],
                    ],
                ],
                'includePaths' => [
                    'static' => false,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => 'self',
                    'attributes' => [],
                    'parameters' => [
                        [
                            'name' => 'paths',
                            'type' => 'array',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => [
                                'unavailable' => true,
                            ],
                            'attributes' => [],
                        ],
                    ],
                ],
                'includeTags' => [
                    'static' => false,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => 'self',
                    'attributes' => [],
                    'parameters' => [
                        [
                            'name' => 'tags',
                            'type' => 'array',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => [
                                'unavailable' => true,
                            ],
                            'attributes' => [],
                        ],
                    ],
                ],
                'report' => [
                    'static' => false,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => 'Studio\\Gesso\\Fuzz\\ContractCheckSummary',
                    'attributes' => [],
                    'parameters' => [],
                ],
                'setUpUsing' => [
                    'static' => false,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => 'self',
                    'attributes' => [],
                    'parameters' => [
                        [
                            'name' => 'callback',
                            'type' => 'callable',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => [
                                'unavailable' => true,
                            ],
                            'attributes' => [],
                        ],
                    ],
                ],
                'tearDownUsing' => [
                    'static' => false,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => 'self',
                    'attributes' => [],
                    'parameters' => [
                        [
                            'name' => 'callback',
                            'type' => 'callable',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => [
                                'unavailable' => true,
                            ],
                            'attributes' => [],
                        ],
                    ],
                ],
            ],
        ];

        $expected[ContractCheckSkip::class] = [
            'kind' => 'class',
            'final' => true,
            'abstract' => false,
            'readonly' => true,
            'instantiable' => true,
            'constructor' => [
                'kind' => 'declared',
                'visibility' => 'public',
            ],
            'parent' => null,
            'interfaces' => [],
            'traits' => [],
            'attributes' => [],
            'backing_type' => null,
            'cases' => [],
            'constants' => [],
            'properties' => [
                'check' => [
                    'type' => 'Studio\\Gesso\\Fuzz\\ContractCheck',
                    'static' => false,
                    'readonly' => true,
                    'default' => [
                        'unavailable' => true,
                    ],
                ],
                'method' => [
                    'type' => '?string',
                    'static' => false,
                    'readonly' => true,
                    'default' => [
                        'unavailable' => true,
                    ],
                ],
                'path' => [
                    'type' => 'string',
                    'static' => false,
                    'readonly' => true,
                    'default' => [
                        'unavailable' => true,
                    ],
                ],
                'reason' => [
                    'type' => 'string',
                    'static' => false,
                    'readonly' => true,
                    'default' => [
                        'unavailable' => true,
                    ],
                ],
            ],
            'methods' => [
                '__construct' => [
                    'static' => false,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => null,
                    'attributes' => [],
                    'parameters' => [
                        [
                            'name' => 'check',
                            'type' => 'Studio\\Gesso\\Fuzz\\ContractCheck',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => [
                                'unavailable' => true,
                            ],
                            'attributes' => [],
                        ],
                        [
                            'name' => 'path',
                            'type' => 'string',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => [
                                'unavailable' => true,
                            ],
                            'attributes' => [],
                        ],
                        [
                            'name' => 'method',
                            'type' => '?string',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => [
                                'unavailable' => true,
                            ],
                            'attributes' => [],
                        ],
                        [
                            'name' => 'reason',
                            'type' => 'string',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => [
                                'unavailable' => true,
                            ],
                            'attributes' => [],
                        ],
                    ],
                ],
            ],
        ];

        $expected[ContractCheckSummary::class] = [
            'kind' => 'class',
            'final' => true,
            'abstract' => false,
            'readonly' => true,
            'instantiable' => true,
            'constructor' => [
                'kind' => 'declared',
                'visibility' => 'public',
            ],
            'parent' => null,
            'interfaces' => [],
            'traits' => [],
            'attributes' => [],
            'backing_type' => null,
            'cases' => [],
            'constants' => [],
            'properties' => [
                'dispatchedProbes' => [
                    'type' => 'int',
                    'static' => false,
                    'readonly' => true,
                    'default' => [
                        'unavailable' => true,
                    ],
                ],
                'failures' => [
                    'type' => 'array',
                    'static' => false,
                    'readonly' => true,
                    'default' => [
                        'unavailable' => true,
                    ],
                ],
                'probedPaths' => [
                    'type' => 'int',
                    'static' => false,
                    'readonly' => true,
                    'default' => [
                        'unavailable' => true,
                    ],
                ],
                'skips' => [
                    'type' => 'array',
                    'static' => false,
                    'readonly' => true,
                    'default' => [
                        'unavailable' => true,
                    ],
                ],
            ],
            'methods' => [
                '__construct' => [
                    'static' => false,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => null,
                    'attributes' => [],
                    'parameters' => [
                        [
                            'name' => 'probedPaths',
                            'type' => 'int',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => [
                                'unavailable' => true,
                            ],
                            'attributes' => [],
                        ],
                        [
                            'name' => 'dispatchedProbes',
                            'type' => 'int',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => [
                                'unavailable' => true,
                            ],
                            'attributes' => [],
                        ],
                        [
                            'name' => 'failures',
                            'type' => 'array',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => [
                                'unavailable' => true,
                            ],
                            'attributes' => [],
                        ],
                        [
                            'name' => 'skips',
                            'type' => 'array',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => [
                                'unavailable' => true,
                            ],
                            'attributes' => [],
                        ],
                    ],
                ],
                'describeFailures' => [
                    'static' => false,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => 'string',
                    'attributes' => [],
                    'parameters' => [],
                ],
                'hasFailures' => [
                    'static' => false,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => 'bool',
                    'attributes' => [],
                    'parameters' => [],
                ],
                'hasSkips' => [
                    'static' => false,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => 'bool',
                    'attributes' => [],
                    'parameters' => [],
                ],
            ],
        ];

        $expected[OpenApiContractChecks::class] = [
            'kind' => 'class',
            'final' => true,
            'abstract' => false,
            'readonly' => false,
            'instantiable' => true,
            'constructor' => [
                'kind' => 'implicit',
                'visibility' => 'public',
            ],
            'parent' => null,
            'interfaces' => [],
            'traits' => [],
            'attributes' => [],
            'backing_type' => null,
            'cases' => [],
            'constants' => [],
            'properties' => [],
            'methods' => [
                'run' => [
                    'static' => true,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => 'Studio\\Gesso\\Fuzz\\ContractCheckPlan',
                    'attributes' => [],
                    'parameters' => [
                        [
                            'name' => 'specName',
                            'type' => 'string',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => [
                                'unavailable' => true,
                            ],
                            'attributes' => [],
                        ],
                        [
                            'name' => 'seed',
                            'type' => 'int',
                            'optional' => true,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => 1,
                            'attributes' => [],
                        ],
                    ],
                ],
            ],
        ];
        // New public class in v2.x (#407): remote entry-document source.
        $remoteSpecNullableProperty = static fn(): array => [
            'type' => '?string',
            'static' => false,
            'readonly' => true,
            'default' => ['unavailable' => true],
        ];
        $expected[RemoteSpecSource::class] = [
            'kind' => 'class',
            'final' => true,
            'abstract' => false,
            'readonly' => true,
            'instantiable' => true,
            'constructor' => ['kind' => 'declared', 'visibility' => 'public'],
            'parent' => null,
            'interfaces' => [],
            'traits' => [],
            'attributes' => [],
            'backing_type' => null,
            'cases' => [],
            'constants' => [],
            'properties' => [
                'authorizationEnv' => $remoteSpecNullableProperty(),
                'expectedSha256' => $remoteSpecNullableProperty(),
                'url' => [
                    'type' => 'string',
                    'static' => false,
                    'readonly' => true,
                    'default' => ['unavailable' => true],
                ],
            ],
            'methods' => [
                '__construct' => [
                    'static' => false,
                    'final' => false,
                    'abstract' => false,
                    'returns_reference' => false,
                    'return_type' => null,
                    'attributes' => [],
                    'parameters' => [
                        [
                            'name' => 'url',
                            'type' => 'string',
                            'optional' => false,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => ['unavailable' => true],
                            'attributes' => [],
                        ],
                        [
                            'name' => 'authorizationEnv',
                            'type' => '?string',
                            'optional' => true,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => null,
                            'attributes' => [],
                        ],
                        [
                            'name' => 'expectedSha256',
                            'type' => '?string',
                            'optional' => true,
                            'variadic' => false,
                            'by_reference' => false,
                            'default' => null,
                            'attributes' => [],
                        ],
                    ],
                ],
            ],
        ];
        ksort($expected);

        foreach ([
            ConsoleCoverageRenderer::class,
            CoverageSidecarEnvelope::class,
            CoverageSidecarReader::class,
            CoverageSidecarWriter::class,
            CoverageThresholdEvaluator::class,
            HtmlCoverageRenderer::class,
            InvalidCoverageOutputPathException::class,
            InvalidStrictRequiredConfigurationException::class,
            InvalidThresholdConfigurationException::class,
            JUnitCoverageRenderer::class,
            JsonCoverageRenderer::class,
            MarkdownCoverageRenderer::class,
            OpenApiRoutesCommand::class,
            ConsoleOutput::class,
            Expectations::class,
            SchemaContext::class,
            SkipOpenApiResolver::class,
        ] as $internalType) {
            unset($expected[$internalType]);
        }

        foreach ($actual as &$type) {
            unset($type['trait_composition']);
        }
        unset($type);

        $this->assertSame($expected, $actual);
    }
}
