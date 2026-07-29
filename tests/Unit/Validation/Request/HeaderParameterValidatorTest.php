<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Validation\Request;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\OpenApiVersion;
use Studio\Gesso\Validation\Request\HeaderParameterValidator;
use Studio\Gesso\Validation\Support\SchemaValidatorRunner;

class HeaderParameterValidatorTest extends TestCase
{
    private HeaderParameterValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new HeaderParameterValidator(new SchemaValidatorRunner(20));
    }

    #[Test]
    public function validate_unwraps_single_element_array_from_headerbag(): void
    {
        // Laravel's HeaderBag surfaces even a single occurrence of a header as
        // a 1-element array. The validator must unwrap it before matching the
        // scalar schema; a regression here would TypeError or falsely reject.
        $parameters = [
            ['name' => 'X-Request-Id', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']],
        ];

        $errors = $this->validator->validate(
            'GET',
            '/pets',
            $parameters,
            ['X-Request-Id' => ['abc']],
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $errors);
    }

    #[Test]
    public function validate_matches_headers_case_insensitively(): void
    {
        $parameters = [
            ['name' => 'X-Request-Id', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']],
        ];

        $errors = $this->validator->validate(
            'GET',
            '/pets',
            $parameters,
            ['x-request-id' => 'abc'],
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $errors);
    }

    #[Test]
    public function validate_rejects_multi_value_header_with_scalar_schema(): void
    {
        $parameters = [
            ['name' => 'X-Foo', 'in' => 'header', 'schema' => ['type' => 'string']],
        ];

        $errors = $this->validator->validate(
            'GET',
            '/pets',
            $parameters,
            ['X-Foo' => ['a', 'b']],
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('multiple values received', $errors[0]->message);
    }

    #[Test]
    public function validate_flags_non_scalar_header_value(): void
    {
        $parameters = [
            ['name' => 'X-Foo', 'in' => 'header', 'schema' => ['type' => 'string']],
        ];

        $errors = $this->validator->validate(
            'GET',
            '/pets',
            $parameters,
            ['X-Foo' => [['nested']]],
            OpenApiVersion::V3_0,
        );

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('value must be a scalar', $errors[0]->message);
    }
}
