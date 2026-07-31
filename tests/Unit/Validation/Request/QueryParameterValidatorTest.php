<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Validation\Request;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\OpenApiVersion;
use Studio\Gesso\Validation\Request\QueryParameterValidator;
use Studio\Gesso\Validation\Support\SchemaValidatorRunner;

use function restore_error_handler;
use function set_error_handler;

class QueryParameterValidatorTest extends TestCase
{
    private QueryParameterValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        QueryParameterValidator::resetWarningStateForTesting();
        $this->validator = new QueryParameterValidator(new SchemaValidatorRunner(20));
    }

    protected function tearDown(): void
    {
        QueryParameterValidator::resetWarningStateForTesting();
        parent::tearDown();
    }

    #[Test]
    public function validate_passes_matching_integer_query_parameter(): void
    {
        $parameters = [
            ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer']],
        ];

        $errors = $this->validator->validate(
            'GET',
            '/pets',
            $parameters,
            ['limit' => '10'],
            OpenApiVersion::V3_0,
        );

        $this->assertSame([], $errors);
    }

    #[Test]
    public function validate_flags_missing_required_parameter(): void
    {
        $parameters = [
            ['name' => 'q', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']],
        ];

        $errors = $this->validator->validate('GET', '/pets', $parameters, [], OpenApiVersion::V3_0);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('required query parameter is missing', $errors[0]->message);
    }

    #[Test]
    public function validate_skips_optional_parameters_without_schema(): void
    {
        $parameters = [['name' => 'x', 'in' => 'query']];

        $errors = $this->validator->validate('GET', '/pets', $parameters, [], OpenApiVersion::V3_0);

        $this->assertSame([], $errors);
    }

    #[Test]
    public function validate_splits_form_explode_false_array_before_validation(): void
    {
        // https://github.com/studio-design/gesso/issues/436
        $parameters = [[
            'name' => 'role',
            'in' => 'query',
            'style' => 'form',
            'explode' => false,
            'schema' => [
                'type' => 'array',
                'uniqueItems' => true,
                'items' => ['enum' => ['owner', 'admin', 'member']],
            ],
        ]];

        $errors = $this->validator->validate(
            'GET',
            '/organizations/{organization_id}/members',
            $parameters,
            ['role' => 'owner,admin'],
            OpenApiVersion::V3_1,
        );

        $this->assertSame([], $errors);
    }

    #[Test]
    public function validate_still_reports_genuine_enum_violation_in_split_array(): void
    {
        $parameters = [[
            'name' => 'role',
            'in' => 'query',
            'style' => 'form',
            'explode' => false,
            'schema' => [
                'type' => 'array',
                'items' => ['enum' => ['owner', 'admin']],
            ],
        ]];

        $errors = $this->validator->validate(
            'GET',
            '/members',
            $parameters,
            ['role' => 'owner,bogus'],
            OpenApiVersion::V3_1,
        );

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('[query.role/1]', $errors[0]->message);
    }

    #[Test]
    public function validate_splits_pipe_delimited_and_coerces_items(): void
    {
        $parameters = [[
            'name' => 'ids',
            'in' => 'query',
            'style' => 'pipeDelimited',
            'schema' => [
                'type' => 'array',
                'items' => ['type' => 'integer', 'minimum' => 1],
            ],
        ]];

        $valid = $this->validator->validate('GET', '/pets', $parameters, ['ids' => '3|5|7'], OpenApiVersion::V3_0);
        $invalid = $this->validator->validate('GET', '/pets', $parameters, ['ids' => '3|0'], OpenApiVersion::V3_0);

        $this->assertSame([], $valid);
        $this->assertCount(1, $invalid);
        $this->assertStringContainsString('[query.ids/1]', $invalid[0]->message);
    }

    #[Test]
    public function validate_splits_space_delimited_array(): void
    {
        $parameters = [[
            'name' => 'tag',
            'in' => 'query',
            'style' => 'spaceDelimited',
            'schema' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'maxLength' => 5],
            ],
        ]];

        $errors = $this->validator->validate('GET', '/pets', $parameters, ['tag' => 'red green'], OpenApiVersion::V3_0);

        $this->assertSame([], $errors);
    }

    #[Test]
    public function validate_keeps_default_exploded_arrays_unsplit(): void
    {
        // The OAS default (`form` + `explode: true`) arrives as repeated keys,
        // already parsed into an array by the framework. A literal comma inside
        // a value must survive.
        $parameters = [[
            'name' => 'q',
            'in' => 'query',
            'schema' => ['type' => 'array', 'items' => ['type' => 'string']],
        ]];

        $errors = $this->validator->validate(
            'GET',
            '/pets',
            $parameters,
            ['q' => ['a,b', 'c']],
            OpenApiVersion::V3_1,
        );

        $this->assertSame([], $errors);
    }

    #[Test]
    public function validate_checks_form_encoded_querystring_schema(): void
    {
        $parameters = [[
            'name' => 'filter',
            'in' => 'querystring',
            'required' => true,
            'content' => [
                'application/x-www-form-urlencoded' => [
                    'schema' => [
                        'type' => 'object',
                        'required' => ['limit'],
                        'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1]],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ]];

        $valid = $this->validator->validate('GET', '/pets', $parameters, ['limit' => '2'], OpenApiVersion::V3_2);
        $invalid = $this->validator->validate('GET', '/pets', $parameters, ['limit' => '0'], OpenApiVersion::V3_2);

        $this->assertSame([], $valid);
        $this->assertNotSame([], $invalid);
        $this->assertStringContainsString('[querystring', $invalid[0]->message);
    }

    #[Test]
    public function validate_warns_when_querystring_media_type_cannot_be_reconstructed(): void
    {
        $warning = null;
        set_error_handler(static function (int $errno, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $errors = $this->validator->validate('GET', '/pets', [[
                'name' => 'raw',
                'in' => 'querystring',
                'content' => ['application/json' => ['schema' => ['type' => 'object']]],
            ]], ['raw' => 'value'], OpenApiVersion::V3_2);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $errors);
        $this->assertStringContainsString('[OpenAPI 3.2 querystring]', $warning ?? '');
        $this->assertStringContainsString('validation was skipped', $warning ?? '');
    }
}
