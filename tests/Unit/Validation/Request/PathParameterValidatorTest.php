<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Validation\Request;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\OpenApiVersion;
use Studio\Gesso\Validation\Request\PathParameterValidator;
use Studio\Gesso\Validation\Support\SchemaValidatorRunner;

class PathParameterValidatorTest extends TestCase
{
    private PathParameterValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new PathParameterValidator(new SchemaValidatorRunner(20));
    }

    #[Test]
    public function validate_passes_matching_integer_path_parameter(): void
    {
        $parameters = [
            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
        ];

        $errors = $this->validator->validate(
            'GET',
            '/pets/{id}',
            $parameters,
            ['id' => '42'],
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $errors);
    }

    #[Test]
    public function validate_flags_schema_mismatch(): void
    {
        $parameters = [
            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
        ];

        $errors = $this->validator->validate(
            'GET',
            '/pets/{id}',
            $parameters,
            ['id' => 'not-an-int'],
            OpenApiVersion::V3_0,
        );

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('[path.id', $errors[0]->message);
    }

    #[Test]
    public function validate_flags_undeclared_path_placeholder(): void
    {
        // `petId` is captured by the matcher but missing from `parameters`.
        $errors = $this->validator->validate(
            'GET',
            '/pets/{petId}',
            [],
            ['petId' => '1'],
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $errors);
        $this->assertStringContainsString("is not declared as an 'in: path' parameter", $errors[0]->message);
    }
}
