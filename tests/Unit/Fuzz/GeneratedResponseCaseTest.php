<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Fuzz;

use LogicException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Studio\Gesso\Fuzz\GeneratedResponseCase;

use function fclose;
use function fopen;
use function sprintf;
use function var_export;

class GeneratedResponseCaseTest extends TestCase
{
    /** @var array<string, mixed> */
    private const OBJECT_SCHEMA = [
        'type' => 'object',
        'required' => ['active'],
        'properties' => [
            'active' => ['type' => 'boolean'],
            'aud' => [
                'oneOf' => [
                    ['type' => 'string'],
                    ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ],
        ],
    ];

    #[Test]
    public function exposes_sdk_and_http_body_shapes(): void
    {
        $case = $this->objectCase(['active' => true, 'aud' => ['client-a']]);
        $objectBody = $case->bodyAsObject();

        $this->assertInstanceOf(stdClass::class, $objectBody);
        $this->assertTrue($objectBody->active);
        $this->assertSame(['client-a'], $objectBody->aud);
        $this->assertSame(
            ['active' => true, 'aud' => ['client-a']],
            $case->bodyAsArray(),
        );
    }

    #[Test]
    public function body_as_array_rejects_a_scalar_response(): void
    {
        $case = new GeneratedResponseCase(
            body: 'audience',
            status: 200,
            contentType: 'application/json',
            seed: 7,
            caseIndex: 3,
            pinnedBranch: '/oneOf@0',
            specName: 'sdk-roundtrip',
            method: 'POST',
            matchedPath: '/oauth/introspect',
            schema: ['type' => 'string'],
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires a JSON object or array body');

        $case->bodyAsArray();
    }

    #[Test]
    public function exposes_replayable_response_metadata(): void
    {
        $case = $this->objectCase(['active' => true, 'aud' => 'client-a']);

        $this->assertSame(200, $case->status);
        $this->assertSame('application/json', $case->contentType);
        $this->assertSame(7, $case->seed);
        $this->assertSame(3, $case->caseIndex);
        $this->assertSame('/properties/aud/oneOf@1', $case->pinnedBranch);
        $this->assertSame(
            "OpenApiResponseExplorer::explore('sdk-roundtrip', 'POST', '/oauth/introspect', 200, 'application/json', seed: 7)->cases[3]",
            $case->replaySnippet(),
        );
    }

    #[Test]
    public function replay_metadata_escapes_php_string_literals(): void
    {
        $case = new GeneratedResponseCase(
            body: ['active' => true],
            status: 200,
            contentType: "application/vnd.example's+json",
            seed: 7,
            caseIndex: 3,
            pinnedBranch: '/properties/aud/oneOf@1',
            specName: "sdk'round\\trip",
            method: "PO'ST",
            matchedPath: "/oauth/it's\\path",
            schema: self::OBJECT_SCHEMA,
        );

        $this->assertSame(
            sprintf(
                'OpenApiResponseExplorer::explore(%s, %s, %s, 200, %s, seed: 7)->cases[3]',
                var_export("sdk'round\\trip", true),
                var_export("PO'ST", true),
                var_export("/oauth/it's\\path", true),
                var_export("application/vnd.example's+json", true),
            ),
            $case->replaySnippet(),
        );
    }

    #[Test]
    public function round_trip_accepts_preserved_values_and_additional_object_keys(): void
    {
        $case = $this->objectCase(['active' => true, 'aud' => ['client-a']]);

        $case->assertRoundTrip((object) [
            'active' => true,
            'aud' => ['client-a'],
            'sdkExtra' => 'allowed',
        ]);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function round_trip_rejects_schema_invalid_re_encoded_values_with_replay_context(): void
    {
        $case = $this->objectCase(['active' => true, 'aud' => 'client-a']);

        try {
            $case->assertRoundTrip(['active' => 'true', 'aud' => 'client-a']);
            $this->fail('Expected a schema-invalid SDK round trip to fail.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('does not satisfy the resolved response schema', $e->getMessage());
            $this->assertFailureCarriesReplayContext($e);
        }
    }

    #[Test]
    public function round_trip_rejects_non_json_values_with_replay_context(): void
    {
        $case = new GeneratedResponseCase(
            body: null,
            status: 200,
            contentType: 'application/json',
            seed: 7,
            caseIndex: 3,
            pinnedBranch: '/properties/aud/oneOf@1',
            specName: 'sdk-roundtrip',
            method: 'GET',
            matchedPath: '/nullable',
            schema: ['type' => ['null', 'object']],
        );
        $nonJsonValue = fopen('php://memory', 'rb');
        $this->assertIsResource($nonJsonValue);

        try {
            $case->assertRoundTrip($nonJsonValue);
            $this->fail('Expected a non-JSON SDK round trip to fail.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('cannot be encoded as JSON', $e->getMessage());
            $this->assertFailureCarriesReplayContext($e);
        } finally {
            fclose($nonJsonValue);
        }
    }

    #[Test]
    public function round_trip_rejects_a_generated_key_swallowed_by_the_sdk(): void
    {
        $case = $this->objectCase(['active' => true, 'aud' => 'client-a']);

        try {
            $case->assertRoundTrip(['active' => true]);
            $this->fail('Expected a value-swallowing SDK round trip to fail.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString("missing generated key 'aud'", $e->getMessage());
            $this->assertFailureCarriesReplayContext($e);
        }
    }

    #[Test]
    public function round_trip_rejects_a_nested_generated_key_swallowed_by_the_sdk(): void
    {
        $case = new GeneratedResponseCase(
            body: ['profile' => ['locale' => 'ja']],
            status: 200,
            contentType: 'application/json',
            seed: 7,
            caseIndex: 3,
            pinnedBranch: '/properties/profile/properties/locale@0',
            specName: 'sdk-roundtrip',
            method: 'GET',
            matchedPath: '/profile',
            schema: [
                'type' => 'object',
                'required' => ['profile'],
                'properties' => [
                    'profile' => [
                        'type' => 'object',
                        'properties' => ['locale' => ['type' => 'string']],
                    ],
                ],
            ],
        );

        try {
            $case->assertRoundTrip((object) ['profile' => (object) ['sdkExtra' => true]]);
            $this->fail('Expected a nested value-swallowing SDK round trip to fail.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString("missing generated key 'locale' at '/profile'", $e->getMessage());
            $this->assertStringContainsString('seed=7', $e->getMessage());
            $this->assertStringContainsString('case=3', $e->getMessage());
            $this->assertStringContainsString('pinned=/properties/profile/properties/locale@0', $e->getMessage());
        }
    }

    #[Test]
    public function round_trip_compares_generated_lists_exactly(): void
    {
        $case = $this->objectCase(['active' => true, 'aud' => ['client-a', 'client-b']]);

        try {
            $case->assertRoundTrip(['active' => true, 'aud' => ['client-a']]);
            $this->fail('Expected a shortened generated list to fail fidelity.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString("list at '/aud' changed", $e->getMessage());
            $this->assertFailureCarriesReplayContext($e);
        }
    }

    #[Test]
    public function round_trip_compares_scalar_types_strictly(): void
    {
        $case = new GeneratedResponseCase(
            body: 1,
            status: 200,
            contentType: 'application/json',
            seed: 7,
            caseIndex: 3,
            pinnedBranch: null,
            specName: 'sdk-roundtrip',
            method: 'GET',
            matchedPath: '/number',
            schema: ['type' => 'number'],
        );

        try {
            $case->assertRoundTrip(1.0);
            $this->fail('Expected a scalar type change to fail fidelity.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString("value at '/' changed", $e->getMessage());
            $this->assertStringContainsString('pinned=none', $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function objectCase(array $body): GeneratedResponseCase
    {
        return new GeneratedResponseCase(
            body: $body,
            status: 200,
            contentType: 'application/json',
            seed: 7,
            caseIndex: 3,
            pinnedBranch: '/properties/aud/oneOf@1',
            specName: 'sdk-roundtrip',
            method: 'POST',
            matchedPath: '/oauth/introspect',
            schema: self::OBJECT_SCHEMA,
        );
    }

    private function assertFailureCarriesReplayContext(AssertionFailedError $error): void
    {
        $this->assertStringContainsString('seed=7', $error->getMessage());
        $this->assertStringContainsString('case=3', $error->getMessage());
        $this->assertStringContainsString('pinned=/properties/aud/oneOf@1', $error->getMessage());
        $this->assertStringContainsString('OpenApiResponseExplorer::explore', $error->getMessage());
    }
}
