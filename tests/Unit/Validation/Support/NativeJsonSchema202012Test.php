<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Validation\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Studio\Gesso\OpenApiVersion;
use Studio\Gesso\Spec\OpenApiSchemaConverter;
use Studio\Gesso\Spec\OpenApiSchemaDialect;
use Studio\Gesso\Tests\Helpers\GroupsSchemaViolations;
use Studio\Gesso\Validation\Support\ObjectConverter;
use Studio\Gesso\Validation\Support\SchemaValidatorRunner;

final class NativeJsonSchema202012Test extends TestCase
{
    use GroupsSchemaViolations;
    private SchemaValidatorRunner $runner;

    protected function setUp(): void
    {
        $this->runner = new SchemaValidatorRunner(20);
    }

    #[Test]
    public function prefix_items_and_items_are_enforced_natively(): void
    {
        $errors = $this->validate([
            'type' => 'array',
            'prefixItems' => [['type' => 'string'], ['type' => 'integer']],
            'items' => false,
        ], ['ok', 'not-an-integer']);

        $this->assertNotSame([], $errors);
    }

    #[Test]
    public function unevaluated_properties_is_enforced_natively(): void
    {
        $errors = $this->validate([
            'type' => 'object',
            'properties' => ['known' => ['type' => 'string']],
            'unevaluatedProperties' => false,
        ], ['known' => 'ok', 'extra' => true]);

        $this->assertArrayHasKey('/', $errors);
        $this->assertStringContainsString('extra', $errors['/'][0]);
    }

    #[Test]
    public function unevaluated_items_is_enforced_natively(): void
    {
        $errors = $this->validate([
            'type' => 'array',
            'prefixItems' => [['type' => 'string']],
            'unevaluatedItems' => false,
        ], ['ok', 'extra']);

        $this->assertNotSame([], $errors);
    }

    #[Test]
    public function dependent_required_is_enforced_natively(): void
    {
        $errors = $this->validate([
            'type' => 'object',
            'dependentRequired' => ['creditCard' => ['cvv']],
        ], ['creditCard' => '4111111111111111']);

        $this->assertStringContainsString('cvv', $errors['/'][0]);
    }

    #[Test]
    public function dependent_schemas_is_enforced_natively(): void
    {
        $errors = $this->validate([
            'type' => 'object',
            'dependentSchemas' => [
                'creditCard' => ['required' => ['billingAddress']],
            ],
        ], ['creditCard' => '4111111111111111']);

        $this->assertStringContainsString('billingAddress', $errors['/'][0]);
    }

    #[Test]
    public function dynamic_ref_and_anchor_are_enforced_natively(): void
    {
        $errors = $this->validate([
            '$dynamicAnchor' => 'node',
            'type' => 'object',
            'required' => ['value'],
            'properties' => [
                'value' => ['type' => 'string'],
                'child' => ['$dynamicRef' => '#node'],
            ],
        ], ['value' => 'root', 'child' => new stdClass()]);

        $this->assertArrayHasKey('/child', $errors);
    }

    #[Test]
    public function content_schema_is_preserved_as_a_2020_12_annotation(): void
    {
        $converted = OpenApiSchemaConverter::convert([
            'type' => 'string',
            'contentMediaType' => 'application/json',
            'contentSchema' => ['type' => 'object'],
        ], OpenApiVersion::V3_1);

        $this->assertSame(['type' => 'object'], $converted['contentSchema']);
        $this->assertSame([], self::groupedErrors($this->runner, ObjectConverter::convert($converted), '{}'));
    }

    #[Test]
    public function document_dialect_can_select_draft_07(): void
    {
        $converted = OpenApiSchemaConverter::convert(
            ['type' => 'array', 'items' => [['type' => 'string']]],
            OpenApiVersion::V3_1,
            jsonSchemaDialect: OpenApiSchemaDialect::DRAFT_07,
        );

        $this->assertSame(OpenApiSchemaDialect::DRAFT_07, $converted['$schema']);
        $this->assertSame([], self::groupedErrors($this->runner, ObjectConverter::convert($converted), ['ok']));
    }

    #[Test]
    public function schema_keyword_overrides_the_document_dialect(): void
    {
        $converted = OpenApiSchemaConverter::convert(
            [
                '$schema' => OpenApiSchemaDialect::DRAFT_2020_12,
                'type' => 'array',
                'prefixItems' => [['type' => 'string']],
                'items' => false,
            ],
            OpenApiVersion::V3_2,
            jsonSchemaDialect: OpenApiSchemaDialect::DRAFT_07,
        );

        $this->assertSame(OpenApiSchemaDialect::DRAFT_2020_12, $converted['$schema']);
        $this->assertNotSame([], self::groupedErrors($this->runner, ObjectConverter::convert($converted), ['ok', 'extra']));
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, string[]>
     */
    private function validate(array $schema, mixed $data): array
    {
        $converted = OpenApiSchemaConverter::convert($schema, OpenApiVersion::V3_1);

        return self::groupedErrors($this->runner, ObjectConverter::convert($converted), ObjectConverter::convert($data));
    }
}
