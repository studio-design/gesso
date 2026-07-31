<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Validation\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Validation\Support\QueryStyleDeserializer;

class QueryStyleDeserializerTest extends TestCase
{
    #[Test]
    public function splits_form_explode_false_on_comma(): void
    {
        $param = ['name' => 'role', 'in' => 'query', 'style' => 'form', 'explode' => false];
        $schema = ['type' => 'array', 'items' => ['type' => 'string']];

        $this->assertSame(
            ['owner', 'admin'],
            QueryStyleDeserializer::deserialize('owner,admin', $param, $schema),
        );
    }

    #[Test]
    public function splits_pipe_delimited_on_pipe(): void
    {
        $param = ['name' => 'role', 'in' => 'query', 'style' => 'pipeDelimited'];
        $schema = ['type' => 'array', 'items' => ['type' => 'string']];

        $this->assertSame(
            ['blue', 'black', 'brown'],
            QueryStyleDeserializer::deserialize('blue|black|brown', $param, $schema),
        );
    }

    #[Test]
    public function splits_space_delimited_on_space(): void
    {
        $param = ['name' => 'role', 'in' => 'query', 'style' => 'spaceDelimited'];
        $schema = ['type' => 'array', 'items' => ['type' => 'string']];

        $this->assertSame(
            ['blue', 'black', 'brown'],
            QueryStyleDeserializer::deserialize('blue black brown', $param, $schema),
        );
    }

    #[Test]
    public function explode_false_without_style_defaults_to_form_comma(): void
    {
        // `style` defaults to `form` for query parameters, so a bare
        // `explode: false` means comma-separated.
        $param = ['name' => 'role', 'in' => 'query', 'explode' => false];
        $schema = ['type' => 'array', 'items' => ['type' => 'string']];

        $this->assertSame(
            ['a', 'b'],
            QueryStyleDeserializer::deserialize('a,b', $param, $schema),
        );
    }

    #[Test]
    public function form_style_without_explode_is_left_untouched(): void
    {
        // `explode` defaults to true for `form`, and exploded values arrive
        // as repeated keys — a single string must not be split.
        $param = ['name' => 'role', 'in' => 'query', 'style' => 'form'];
        $schema = ['type' => 'array', 'items' => ['type' => 'string']];

        $this->assertSame(
            'owner,admin',
            QueryStyleDeserializer::deserialize('owner,admin', $param, $schema),
        );
    }

    #[Test]
    public function explode_true_is_left_untouched_for_every_style(): void
    {
        $schema = ['type' => 'array', 'items' => ['type' => 'string']];

        foreach (['form', 'pipeDelimited', 'spaceDelimited'] as $style) {
            $param = ['name' => 'role', 'in' => 'query', 'style' => $style, 'explode' => true];

            $this->assertSame(
                'a,b',
                QueryStyleDeserializer::deserialize('a,b', $param, $schema),
                "style {$style} + explode true must not split",
            );
        }
    }

    #[Test]
    public function non_array_schema_is_left_untouched(): void
    {
        $param = ['name' => 'q', 'in' => 'query', 'style' => 'form', 'explode' => false];
        $schema = ['type' => 'string'];

        $this->assertSame(
            'owner,admin',
            QueryStyleDeserializer::deserialize('owner,admin', $param, $schema),
        );
    }

    #[Test]
    public function already_parsed_array_value_is_left_untouched(): void
    {
        // Frameworks hand over repeated keys (`?role[]=a&role[]=b`) as arrays;
        // there is nothing left to split.
        $param = ['name' => 'role', 'in' => 'query', 'style' => 'form', 'explode' => false];
        $schema = ['type' => 'array', 'items' => ['type' => 'string']];

        $this->assertSame(
            ['a', 'b'],
            QueryStyleDeserializer::deserialize(['a', 'b'], $param, $schema),
        );
    }

    #[Test]
    public function empty_string_deserializes_to_empty_array(): void
    {
        // `?role=` is the non-exploded serialization of an empty list.
        $param = ['name' => 'role', 'in' => 'query', 'style' => 'form', 'explode' => false];
        $schema = ['type' => 'array', 'items' => ['type' => 'string']];

        $this->assertSame(
            [],
            QueryStyleDeserializer::deserialize('', $param, $schema),
        );
    }

    #[Test]
    public function single_value_becomes_single_element_array(): void
    {
        $param = ['name' => 'role', 'in' => 'query', 'style' => 'form', 'explode' => false];
        $schema = ['type' => 'array', 'items' => ['type' => 'string']];

        $this->assertSame(
            ['owner'],
            QueryStyleDeserializer::deserialize('owner', $param, $schema),
        );
    }

    #[Test]
    public function multi_type_array_declaration_is_split(): void
    {
        // OAS 3.1 nullable arrays: `type: ["array", "null"]`.
        $param = ['name' => 'role', 'in' => 'query', 'explode' => false];
        $schema = ['type' => ['array', 'null'], 'items' => ['type' => 'string']];

        $this->assertSame(
            ['a', 'b'],
            QueryStyleDeserializer::deserialize('a,b', $param, $schema),
        );
    }

    #[Test]
    public function unknown_style_is_left_untouched(): void
    {
        $param = ['name' => 'role', 'in' => 'query', 'style' => 'deepObject', 'explode' => false];
        $schema = ['type' => 'array', 'items' => ['type' => 'string']];

        $this->assertSame(
            'a,b',
            QueryStyleDeserializer::deserialize('a,b', $param, $schema),
        );
    }

    #[Test]
    public function malformed_style_and_explode_declarations_are_left_untouched(): void
    {
        $schema = ['type' => 'array', 'items' => ['type' => 'string']];

        // Non-string style, non-boolean explode: fall back to the raw value
        // rather than guessing what the spec author meant.
        $this->assertSame(
            'a,b',
            QueryStyleDeserializer::deserialize('a,b', ['name' => 'r', 'style' => 42, 'explode' => false], $schema),
        );
        $this->assertSame(
            'a,b',
            QueryStyleDeserializer::deserialize('a,b', ['name' => 'r', 'style' => 'form', 'explode' => 'false'], $schema),
        );
    }
}
