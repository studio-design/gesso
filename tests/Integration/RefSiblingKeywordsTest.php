<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
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

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
        $this->validator = new OpenApiResponseValidator(strictRequiredTracker: new StrictRequiredTracker());
    }

    protected function tearDown(): void
    {
        OpenApiCoverageTracker::reset();
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function v31_applies_ref_sibling_keywords_to_the_resolved_target(): void
    {
        $spec = OpenApiSpecLoader::load('ref-siblings-3.1');
        $properties = $spec['components']['schemas']['User']['properties'];

        $this->assertSame(
            ['minLength' => 4, 'allOf' => [['type' => 'string']]],
            $properties['name'],
        );
        $this->assertSame(
            ['maxLength' => 2, 'allOf' => [['type' => 'string']]],
            $properties['tags']['items'],
            'items is a subschema position, so its $ref siblings apply too',
        );
        $this->assertSame(
            ['maximum' => 100, 'allOf' => [['type' => 'integer']]],
            $spec['paths']['/users/{id}']['get']['parameters'][0]['schema'],
            'a Parameter Object `schema` is a Schema Object position',
        );
        $this->assertSame(
            ['minLength' => 3, 'allOf' => [['type' => 'string']]],
            $properties['address']['properties']['street'],
            'siblings inside a referenced component resolve as well',
        );
    }

    /**
     * The target goes into the referring schema's own `allOf` rather than the
     * whole node being wrapped as `allOf: [target, siblings]`. Adjacency is
     * load-bearing: `unevaluatedProperties` reads the annotations of adjacent
     * in-place applicators, and OAS keywords Gesso enforces per position
     * (`readOnly`) have to stay the property schema's own keywords.
     */
    #[Test]
    public function v31_keeps_ref_siblings_adjacent_to_the_applied_target(): void
    {
        $properties = OpenApiSpecLoader::load('ref-siblings-3.1')['components']['schemas']['User']['properties'];

        $this->assertSame(
            [
                'unevaluatedProperties' => false,
                'allOf' => [['type' => 'object', 'properties' => ['bio' => ['type' => 'string']]]],
            ],
            $properties['profile'],
        );
        $this->assertSame(
            ['readOnly' => true, 'allOf' => [['type' => 'string']]],
            $properties['token'],
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

    /** @param array<string, mixed> $body */
    private function validate(string $specName, array $body): OpenApiValidationResult
    {
        return $this->validator->validate($specName, 'GET', '/users/1', 200, $body, 'application/json');
    }
}
