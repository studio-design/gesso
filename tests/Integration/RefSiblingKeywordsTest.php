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

    /** @return iterable<string, array{string, array<array-key, mixed>|string, string}> */
    public static function provideA_malformed_side_of_a_collision_is_not_launderedCases(): iterable
    {
        yield 'bound' => ['/bad-bound', 'abcde', 'minLength must be a non-negative integer'];
        yield 'required' => ['/bad-required', ['1' => 'x'], 'required must be an array of strings'];
        yield 'properties' => ['/bad-properties', [], 'properties must be an object'];
        yield 'empty allOf' => ['/empty-allof', ['x' => 1], 'allOf must have at least one element'];
        yield 'list property' => ['/list-property', ['foo' => 'x'], 'must be a json schema'];
        yield 'dialect' => ['/bad-dialect', 'abc', 'Unsupported `$schema`: expected a URI string, got int'];
        yield 'dialect sibling' => ['/bad-dialect-sibling', 'abc', 'Unsupported `$schema`: expected a URI string, got int'];
    }

    /** @return iterable<string, array{string}> */
    public static function provideAn_inherited_resource_dialect_survives_substitutionCases(): iterable
    {
        yield 'same document' => ['internal'];
        yield 'external document' => ['external'];
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

    /**
     * A keyword both sides declare is merged by its own semantics rather than
     * lifted into a branch — property maps merge per name, so form decoding
     * still sees the referenced schema's fields and coerces them.
     */
    #[Test]
    public function a_colliding_property_map_merges_per_name(): void
    {
        $schema = OpenApiSpecLoader::load('ref-siblings-downstream-3.1')['paths']['/form']['post']['requestBody']['content']['application/x-www-form-urlencoded']['schema'];

        $this->assertSame(
            ['foo' => ['type' => 'integer'], 'bar' => ['type' => 'integer']],
            $schema['properties'],
        );
        $this->assertArrayNotHasKey('allOf', $schema);

        $coerced = $this->requestValidator->validate(
            'ref-siblings-downstream-3.1',
            'POST',
            '/form',
            [],
            [],
            'foo=5',
            'application/x-www-form-urlencoded',
        );
        $this->assertTrue($coerced->isValid(), 'the referenced schema still drives form coercion');

        $rejected = $this->requestValidator->validate(
            'ref-siblings-downstream-3.1',
            'POST',
            '/form',
            [],
            [],
            'zzz=1',
            'application/x-www-form-urlencoded',
        );
        $this->assertFalse($rejected->isValid(), 'the sibling unevaluatedProperties still applies');
    }

    /**
     * Keywords in a schema object work on each other, so a collision with no
     * meaning-preserving merge keeps the two objects apart instead of lifting
     * the lone keyword out: a sibling `if` must stay with its own `then`.
     */
    #[Test]
    public function an_unmergeable_collision_keeps_the_siblings_whole(): void
    {
        $schema = OpenApiSpecLoader::load('ref-siblings-downstream-3.1')['paths']['/conditional']['get']['responses']['200']['content']['application/json']['schema'];

        $this->assertSame(
            [['if' => ['required' => ['kind']], 'then' => ['required' => ['value']]]],
            $schema['allOf'],
        );

        $result = $this->validator->validate(
            'ref-siblings-downstream-3.1',
            'GET',
            '/conditional',
            200,
            ['kind' => 'x'],
            'application/json',
        );
        $this->assertFalse($result->isValid(), "the sibling's then is still gated by the sibling's if");
    }

    /**
     * A target that declares its own `$schema` is a schema resource in its own
     * right. Merging it would promote that dialect onto the referring schema
     * and change how the *siblings* are read, so it is applied intact instead.
     */
    #[Test]
    public function a_target_that_is_its_own_schema_resource_keeps_its_dialect_boundary(): void
    {
        $schema = OpenApiSpecLoader::load('ref-siblings-downstream-3.1')['paths']['/resource']['get']['responses']['200']['content']['application/json']['schema'];

        $this->assertArrayNotHasKey('$schema', $schema, 'the target dialect must not reach the referrer');
        $this->assertSame(
            'http://json-schema.org/draft-07/schema#',
            $schema['allOf'][0]['$schema'],
        );

        $result = $this->validator->validate(
            'ref-siblings-downstream-3.1',
            'GET',
            '/resource',
            200,
            ['foo' => 'ok', 'extra' => 1],
            'application/json',
        );
        $this->assertFalse(
            $result->isValid(),
            "the referrer's own unevaluatedProperties is still read in its own dialect",
        );
    }

    /**
     * An external document is its own schema resource too, and a bare-fragment
     * walk never sees its root — so its dialect has to be read before anything
     * under it resolves.
     */
    #[Test]
    public function an_external_documents_own_dialect_governs_its_fragments(): void
    {
        $schema = OpenApiSpecLoader::load('ref-siblings-downstream-3.1')['paths']['/external']['get']['responses']['200']['content']['application/json']['schema'];

        $this->assertSame(
            ['type' => 'string'],
            $schema['properties']['name'],
            'the external root selects Draft 07, which ignores $ref siblings',
        );
    }

    /**
     * The same keyword restated identically on both sides collapses to one
     * declaration. Lifting the duplicate into a branch would strand it away
     * from the `properties` it reads and reject every declared property.
     */
    #[Test]
    public function an_identically_restated_keyword_collapses_to_one(): void
    {
        $schema = OpenApiSpecLoader::load('ref-siblings-downstream-3.1')['paths']['/restated']['get']['responses']['200']['content']['application/json']['schema'];

        $this->assertSame(
            ['type' => 'object', 'properties' => ['foo' => ['type' => 'string']], 'unevaluatedProperties' => false],
            $schema,
        );

        $accepted = $this->validator->validate(
            'ref-siblings-downstream-3.1',
            'GET',
            '/restated',
            200,
            ['foo' => 'ok'],
            'application/json',
        );
        $this->assertTrue($accepted->isValid());

        $rejected = $this->validator->validate(
            'ref-siblings-downstream-3.1',
            'GET',
            '/restated',
            200,
            ['zzz' => 1],
            'application/json',
        );
        $this->assertFalse($rejected->isValid());
    }

    #[Test]
    public function colliding_bounds_tighten_and_required_lists_union(): void
    {
        $schema = OpenApiSpecLoader::load('ref-siblings-downstream-3.1')['paths']['/bounds']['get']['responses']['200']['content']['application/json']['schema'];

        $this->assertSame(['a', 'b'], $schema['required']);
        $this->assertSame(4, $schema['properties']['a']['minLength'], 'the stricter minimum wins');
        $this->assertSame(6, $schema['properties']['a']['maxLength'], 'the stricter maximum wins');
    }

    /**
     * A target that declares a dialect of its own cannot be merged, but the
     * parameter coercion that depends on its `type` still has to work.
     */
    #[Test]
    public function a_foreign_dialect_target_is_still_reachable_for_coercion(): void
    {
        $result = $this->requestValidator->validate(
            'ref-siblings-downstream-3.1',
            'GET',
            '/resource/5',
            [],
            [],
            null,
        );

        $this->assertTrue($result->isValid(), 'TypeCoercer reads the branch the resource was applied as');
    }

    /**
     * Applying `$ref` siblings is only one of the things a dialect decides.
     * 2019-09 and 2020-12 agree on that and disagree on array tuples, so a
     * 2019-09 target has to keep its boundary or the referrer's `prefixItems`
     * ends up read under a dialect that has never heard of it.
     */
    #[Test]
    public function a_target_under_another_sibling_applying_dialect_keeps_its_boundary(): void
    {
        $schema = OpenApiSpecLoader::load('ref-siblings-downstream-3.1')['paths']['/tuple']['get']['responses']['200']['content']['application/json']['schema'];

        $this->assertArrayNotHasKey('$schema', $schema, 'the 2019-09 dialect must not reach the referrer');
        $this->assertSame([false], $schema['prefixItems']);

        $result = $this->validator->validate(
            'ref-siblings-downstream-3.1',
            'GET',
            '/tuple',
            200,
            [1],
            'application/json',
        );
        $this->assertFalse($result->isValid(), 'prefixItems is still read as 2020-12');
    }

    /**
     * The boundary is about the dialect, not about the URI: a target that
     * restates the dialect the referrer is already read under declares no
     * boundary at all and still merges flat.
     */
    #[Test]
    public function a_target_restating_the_referrers_dialect_still_merges_flat(): void
    {
        $schema = OpenApiSpecLoader::load('ref-siblings-downstream-3.1')['paths']['/restated-dialect']['get']['responses']['200']['content']['application/json']['schema'];

        $this->assertArrayNotHasKey('allOf', $schema);
        $this->assertSame('string', $schema['type']);
        $this->assertSame(4, $schema['minLength']);
    }

    /**
     * `properties` entries merge per name, and two schemas for one name are
     * ANDed. `false` accepts nothing, so a sibling `true` cannot re-open a
     * property the target closed.
     */
    #[Test]
    public function a_boolean_property_schema_is_anded_not_overwritten(): void
    {
        $schema = OpenApiSpecLoader::load('ref-siblings-downstream-3.1')['paths']['/closed']['get']['responses']['200']['content']['application/json']['schema'];

        $this->assertFalse($schema['properties']['foo'], 'false absorbs');
        $this->assertSame(['type' => 'integer'], $schema['properties']['bar'], 'true drops out');

        $rejected = $this->validator->validate(
            'ref-siblings-downstream-3.1',
            'GET',
            '/closed',
            200,
            ['foo' => 1],
            'application/json',
        );
        $this->assertFalse($rejected->isValid(), 'the target forbade foo');

        $accepted = $this->validator->validate(
            'ref-siblings-downstream-3.1',
            'GET',
            '/closed',
            200,
            ['bar' => 1],
            'application/json',
        );
        $this->assertTrue($accepted->isValid());
    }

    /**
     * `allOf` ANDs, so a referrer widening the type over a branch pinned to
     * one is only satisfiable as that one — and the parameter has to be
     * coerced to it, not to whatever the top level happens to list first.
     */
    #[Test]
    public function a_widened_referrer_type_intersects_with_the_branch(): void
    {
        $result = $this->requestValidator->validate(
            'ref-siblings-downstream-3.1',
            'GET',
            '/widened/5',
            [],
            [],
            null,
        );

        $this->assertTrue($result->isValid(), 'the effective type is the intersection: integer');
    }

    /**
     * A schema resource embedded inside a document governs everything under
     * it. A bare-fragment `$ref` jumps straight to its target, so the dialect
     * of the resources it passes through has to be picked up on the way.
     */
    #[Test]
    public function an_enclosing_schema_resource_governs_the_fragment_it_contains(): void
    {
        $schema = OpenApiSpecLoader::load('ref-siblings-downstream-3.1')['paths']['/embedded']['get']['responses']['200']['content']['application/json']['schema'];

        $this->assertSame(
            ['type' => 'string', '$schema' => 'https://json-schema.org/draft/2020-12/schema'],
            $schema['properties']['name'],
            'the enclosing Draft 07 resource ignores $ref siblings, whatever the document root says',
        );

        $internal = OpenApiSpecLoader::load('ref-siblings-downstream-3.1')['paths']['/internal-embedded']['get']['responses']['200']['content']['application/json']['schema'];
        $this->assertSame(
            ['type' => 'string', '$schema' => 'https://spec.openapis.org/oas/3.1/dialect/base'],
            $internal['properties']['name'],
            'a same-document fragment resets to the document dialect before the descent',
        );
    }

    /**
     * Substitution lifts the target out of its resource, so an inherited
     * dialect has to travel with it — a Draft 07 tuple `items` read as 2020-12
     * turns a valid spec into `items must contain a valid json schema`.
     *
     * @param 'external'|'internal' $variant
     */
    #[Test]
    #[DataProvider('provideAn_inherited_resource_dialect_survives_substitutionCases')]
    public function an_inherited_resource_dialect_survives_substitution(string $variant): void
    {
        $path = '/tuple-' . $variant;
        $schema = OpenApiSpecLoader::load('ref-siblings-downstream-3.1')['paths'][$path]['get']['responses']['200']['content']['application/json']['schema'];

        $this->assertSame('http://json-schema.org/draft-07/schema#', $schema['$schema']);

        $result = $this->validator->validate(
            'ref-siblings-downstream-3.1',
            'GET',
            $path,
            200,
            ['a', 1],
            'application/json',
        );
        $this->assertTrue($result->isValid(), 'a Draft 07 tuple is still read as a tuple');
    }

    /**
     * Merging is only ever a rewrite of a well-formed schema. Every malformed
     * side of a collision has to reach the validator as written — repairing it
     * turns a spec error into a schema the validator accepts.
     *
     * @param array<array-key, mixed>|string $body
     */
    #[Test]
    #[DataProvider('provideA_malformed_side_of_a_collision_is_not_launderedCases')]
    public function a_malformed_side_of_a_collision_is_not_laundered(
        string $path,
        array|string $body,
        string $expected,
    ): void {
        $result = $this->validator->validate(
            'ref-siblings-malformed-3.1',
            'GET',
            $path,
            200,
            $body,
            'application/json',
        );

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString($expected, $result->errors()[0]);
    }

    #[Test]
    public function a_malformed_side_of_a_collision_keeps_both_declarations(): void
    {
        $paths = OpenApiSpecLoader::load('ref-siblings-malformed-3.1')['paths'];

        $bound = $paths['/bad-bound']['get']['responses']['200']['content']['application/json']['schema'];
        $this->assertSame('3', $bound['minLength'], 'the malformed bound is not tightened away');
        $this->assertSame([['minLength' => 4]], $bound['allOf']);

        $required = $paths['/bad-required']['get']['responses']['200']['content']['application/json']['schema'];
        $this->assertSame(['1'], $required['required'], 'no loose-comparison union');
        $this->assertSame([['required' => [1]]], $required['allOf']);

        $properties = $paths['/bad-properties']['get']['responses']['200']['content']['application/json']['schema'];
        $this->assertSame(
            ['foo' => ['type' => 'string']],
            $properties['properties'],
            'a malformed list never becomes a property named 0',
        );
        $this->assertSame([['properties' => [['type' => 'integer']]]], $properties['allOf']);

        $allOf = $paths['/empty-allof']['get']['responses']['200']['content']['application/json']['schema'];
        $this->assertSame([], $allOf['allOf'][0]['allOf'], 'the empty branch list stays empty');

        $dialect = $paths['/bad-dialect']['get']['responses']['200']['content']['application/json']['schema'];
        $this->assertSame(
            ['type' => 'string', '$schema' => 123],
            $dialect,
            'substitution re-attaches the resource declaration verbatim, malformed or not',
        );

        $sibling = $paths['/bad-dialect-sibling']['get']['responses']['200']['content']['application/json']['schema'];
        $this->assertSame(123, $sibling['allOf'][0]['$schema'], 'an unreadable dialect is a resource boundary');

        $property = $paths['/list-property']['get']['responses']['200']['content']['application/json']['schema'];
        $this->assertSame(
            ['type' => 'string'],
            $property['properties']['foo'],
            'a property written as a JSON array is not merged in as keywords 0, 1, …',
        );
        $this->assertSame([['properties' => ['foo' => [['type' => 'integer']]]]], $property['allOf']);
    }

    /** @param array<string, mixed> $body */
    private function validate(string $specName, array $body): OpenApiValidationResult
    {
        return $this->validator->validate($specName, 'GET', '/users/1', 200, $body, 'application/json');
    }
}
