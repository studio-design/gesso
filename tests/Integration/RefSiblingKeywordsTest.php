<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\OpenApiRequestValidator;
use Studio\Gesso\OpenApiResponseValidator;
use Studio\Gesso\OpenApiValidationResult;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;

/**
 * Issue #536: in JSON Schema 2019-09 and later a Schema Object `$ref` is an
 * in-place applicator, so keywords sitting next to it must still be validated.
 * The loader used to substitute the target wholesale and silently drop them,
 * turning an INVALID response into a VALID one. Draft 06/07 — which OAS 3.0's
 * Reference Object matches — require the opposite, so the substitution stays
 * correct there.
 */
class RefSiblingKeywordsTest extends TestCase
{
    private OpenApiResponseValidator $validator;
    private OpenApiRequestValidator $requestValidator;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
        $this->validator = new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker());
        $this->requestValidator = new OpenApiRequestValidator();
    }

    protected function tearDown(): void
    {
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function provideApplies_ref_sibling_keywords_in_every_supported_versionCases(): iterable
    {
        yield '3.1' => ['ref-siblings-3.1'];
        yield '3.2' => ['ref-siblings-3.2'];
    }

    #[Test]
    public function v31_applies_ref_sibling_keywords_to_the_resolved_target(): void
    {
        $spec = OpenApiSpecLoader::load('ref-siblings-3.1');
        $properties = $spec['components']['schemas']['User']['properties'];

        $this->assertSame(
            ['type' => 'string', 'minLength' => 4],
            $properties['name'],
        );
        $this->assertSame(
            ['type' => 'string', 'maxLength' => 2],
            $properties['tags']['items'],
            'items is a subschema position, so its $ref siblings apply too',
        );
        $this->assertSame(
            ['type' => 'integer', 'maximum' => 100],
            $spec['paths']['/users/{id}']['get']['parameters'][0]['schema'],
            'a Parameter Object `schema` is a Schema Object position',
        );
        $this->assertSame(
            ['type' => 'string', 'minLength' => 3],
            $properties['address']['properties']['street'],
            'siblings inside a referenced component resolve as well',
        );
    }

    /**
     * Target and siblings share one Schema Object rather than becoming
     * `allOf` branches. Keeping them at the top level is load-bearing:
     * `unevaluatedProperties` reads the annotations of *adjacent* keywords,
     * parameter coercion and form decoding read `type` / `properties` there,
     * and `readOnly` / `writeOnly` are enforced from the property schema's own
     * top level.
     */
    #[Test]
    public function v31_merges_ref_siblings_into_the_schema_objects_own_top_level(): void
    {
        $properties = OpenApiSpecLoader::load('ref-siblings-3.1')['components']['schemas']['User']['properties'];

        $this->assertSame(
            [
                'type' => 'object',
                'properties' => ['bio' => ['type' => 'string']],
                'unevaluatedProperties' => false,
            ],
            $properties['profile'],
        );
        $this->assertSame(
            ['type' => 'string', 'readOnly' => true],
            $properties['token'],
            'the referenced component readOnly stays where enforceContextOnProperties() reads it',
        );
    }

    #[Test]
    public function v31_lets_unevaluated_properties_see_the_referenced_targets_properties(): void
    {
        $accepted = $this->validate(
            'ref-siblings-3.1',
            ['id' => 1, 'name' => 'abcd', 'profile' => ['bio' => 'hi']],
        );
        $this->assertTrue($accepted->isValid(), 'bio is evaluated by the referenced schema');

        $rejected = $this->validate(
            'ref-siblings-3.1',
            ['id' => 1, 'name' => 'abcd', 'profile' => ['bio' => 'hi', 'extra' => 1]],
        );
        $this->assertFalse($rejected->isValid(), 'extra is genuinely unevaluated');
    }

    #[Test]
    public function v31_leaves_annotation_only_siblings_and_reference_objects_alone(): void
    {
        $spec = OpenApiSpecLoader::load('ref-siblings-3.1');

        $this->assertSame(
            ['type' => 'string'],
            $spec['components']['schemas']['User']['properties']['nickname'],
            'a description sibling changes no validation outcome, so no allOf is introduced',
        );

        $notFound = $spec['paths']['/users/{id}']['get']['responses']['404'];
        $this->assertArrayHasKey('content', $notFound);
        $this->assertArrayNotHasKey('allOf', $notFound, 'a Response Object is not a Schema Object');
    }

    #[Test]
    public function v31_rejects_a_body_violating_a_ref_sibling_keyword(): void
    {
        $result = $this->validate('ref-siblings-3.1', ['id' => 1, 'name' => 'abc']);

        $this->assertFalse($result->isValid());
    }

    #[Test]
    public function v31_accepts_a_body_satisfying_a_ref_sibling_keyword(): void
    {
        $result = $this->validate(
            'ref-siblings-3.1',
            ['id' => 1, 'name' => 'abcd', 'nickname' => 'ab', 'address' => ['street' => 'Main']],
        );

        $this->assertTrue($result->isValid());
    }

    #[Test]
    public function v30_still_ignores_ref_siblings_per_the_reference_object_rules(): void
    {
        $spec = OpenApiSpecLoader::load('ref-siblings-3.0');
        $this->assertSame(
            ['type' => 'string'],
            $spec['components']['schemas']['User']['properties']['name'],
        );

        $result = $this->validate('ref-siblings-3.0', ['id' => 1, 'name' => 'abc']);

        $this->assertTrue($result->isValid());
    }

    #[Test]
    #[DataProvider('provideApplies_ref_sibling_keywords_in_every_supported_versionCases')]
    public function applies_ref_sibling_keywords_in_every_supported_version(string $specName): void
    {
        $spec = OpenApiSpecLoader::load($specName);

        $this->assertSame(
            ['type' => 'string', 'minLength' => 4],
            $spec['components']['schemas']['User']['properties']['name'],
        );
    }

    #[Test]
    public function v32_applies_ref_siblings_in_a_media_type_item_schema(): void
    {
        $content = OpenApiSpecLoader::load('ref-siblings-3.2')['paths']['/users/{id}']['get']['responses']['200']['content'];

        $this->assertSame(
            ['type' => 'string', 'minLength' => 9],
            $content['application/jsonl']['itemSchema'],
            'the OAS 3.2 MediaType itemSchema is a Schema Object position',
        );
    }

    #[Test]
    public function v31_applies_ref_siblings_at_every_subschema_position(): void
    {
        $positions = OpenApiSpecLoader::load('ref-siblings-3.1')['components']['schemas']['Positions'];

        $this->assertSame(['type' => 'string', 'minLength' => 5], $positions['additionalProperties']);
        $this->assertSame(['type' => 'string', 'maxLength' => 1], $positions['not']);
        $this->assertSame([['type' => 'string', 'minLength' => 6]], $positions['allOf']);
        $this->assertSame(['type' => 'string', 'minLength' => 7], $positions['patternProperties']['^p_']);
        $this->assertSame(['type' => 'string', 'minLength' => 8], $positions['$defs']['Inner']);
    }

    #[Test]
    public function v31_treats_vendor_extensions_as_annotation_only_siblings(): void
    {
        $properties = OpenApiSpecLoader::load('ref-siblings-3.1')['components']['schemas']['User']['properties'];

        $this->assertSame(
            ['type' => 'string'],
            $properties['vendor'],
            'x- extensions carry no validation weight, so they keep the plain substitution',
        );
    }

    /**
     * The merged schema has to keep `type` at its own top level: path, query,
     * and header values arrive as strings and are coerced from it before opis
     * ever runs.
     */
    #[Test]
    public function a_merged_parameter_schema_still_coerces_the_raw_string_value(): void
    {
        $result = $this->requestValidator->validate(
            'ref-siblings-downstream-3.1',
            'GET',
            '/users/5',
            [],
            [],
            null,
        );

        $this->assertTrue($result->isValid(), 'the path value 5 must still coerce to integer');
    }

    #[Test]
    public function a_merged_property_still_enforces_the_targets_write_only(): void
    {
        $result = $this->validator->validate(
            'ref-siblings-downstream-3.1',
            'GET',
            '/users/1',
            200,
            ['secret' => 'x'],
            'application/json',
        );

        $this->assertFalse($result->isValid(), 'writeOnly must not survive into a response');
    }

    #[Test]
    public function a_merged_property_still_enforces_the_targets_read_only(): void
    {
        $result = $this->requestValidator->validate(
            'ref-siblings-downstream-3.1',
            'POST',
            '/users/1',
            [],
            [],
            ['serverId' => 5],
            'application/json',
        );

        $this->assertFalse($result->isValid(), 'readOnly must not be accepted in a request');
    }

    #[Test]
    public function a_merged_oneof_branch_keeps_its_implicit_discriminator_mapping(): void
    {
        $result = $this->validator->validate(
            'ref-siblings-downstream-3.1',
            'GET',
            '/pets',
            200,
            ['petType' => 'Dog', 'bark' => true],
            'application/json',
        );

        $this->assertTrue(
            $result->isValid(),
            'Dog is still an implicitly mapped discriminator value despite the sibling',
        );
    }

    /** @param array<string, mixed> $body */
    private function validate(string $specName, array $body): OpenApiValidationResult
    {
        return $this->validator->validate($specName, 'GET', '/users/1', 200, $body, 'application/json');
    }
}
