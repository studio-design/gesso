<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\OpenApiResponseValidator;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Strict\StrictRequiredTracker;

/**
 * Issue #536: in OAS 3.1/3.2 a Schema Object `$ref` is a JSON Schema 2020-12
 * in-place applicator, so keywords sitting next to it must still be validated.
 * The loader used to substitute the target wholesale and silently drop them,
 * turning an INVALID response into a VALID one. OAS 3.0's Reference Object
 * ignores siblings, so the substitution stays correct there.
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
            ['allOf' => [['type' => 'string'], ['minLength' => 4]]],
            $properties['name'],
        );
        $this->assertSame(
            ['allOf' => [['type' => 'string'], ['maxLength' => 2]]],
            $properties['tags']['items'],
            'items is a subschema position, so its $ref siblings apply too',
        );
        $this->assertSame(
            ['allOf' => [['type' => 'integer'], ['maximum' => 100]]],
            $spec['paths']['/users/{id}']['get']['parameters'][0]['schema'],
            'a Parameter Object `schema` is a Schema Object position',
        );
        $this->assertSame(
            ['allOf' => [['type' => 'string'], ['minLength' => 3]]],
            $properties['address']['properties']['street'],
            'siblings inside a referenced component resolve as well',
        );
    }

    #[Test]
    public function v31_leaves_annotation_only_siblings_and_reference_objects_alone(): void
    {
        $spec = OpenApiSpecLoader::load('ref-siblings-3.1');

        $this->assertSame(
            ['type' => 'string'],
            $spec['components']['schemas']['User']['properties']['nickname'],
            'a description sibling changes no validation outcome, so no allOf wrapper is introduced',
        );

        $notFound = $spec['paths']['/users/{id}']['get']['responses']['404'];
        $this->assertArrayHasKey('content', $notFound);
        $this->assertArrayNotHasKey('allOf', $notFound, 'a Response Object is not a Schema Object');
    }

    #[Test]
    public function v31_rejects_a_body_violating_a_ref_sibling_keyword(): void
    {
        $result = $this->validator->validate(
            'ref-siblings-3.1',
            'GET',
            '/users/1',
            200,
            ['id' => 1, 'name' => 'abc'],
            'application/json',
        );

        $this->assertFalse($result->isValid());
    }

    #[Test]
    public function v31_accepts_a_body_satisfying_a_ref_sibling_keyword(): void
    {
        $result = $this->validator->validate(
            'ref-siblings-3.1',
            'GET',
            '/users/1',
            200,
            ['id' => 1, 'name' => 'abcd', 'nickname' => 'ab', 'address' => ['street' => 'Main']],
            'application/json',
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

        $result = $this->validator->validate(
            'ref-siblings-3.0',
            'GET',
            '/users/1',
            200,
            ['id' => 1, 'name' => 'abc'],
            'application/json',
        );

        $this->assertTrue($result->isValid());
    }
}
