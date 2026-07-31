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
    public function empty_value_deserializes_to_single_empty_string_element(): void
    {
        // RFC 6570 §2.3: a zero-member list is undefined and the parameter is
        // omitted entirely, so `?role=` can only be the one-element list [""].
        $param = ['name' => 'role', 'in' => 'query', 'style' => 'form', 'explode' => false];
        $schema = ['type' => 'array', 'items' => ['type' => 'string']];

        $this->assertSame(
            [''],
            QueryStyleDeserializer::deserialize('', $param, $schema),
        );
        $this->assertSame(
            [''],
            QueryStyleDeserializer::deserialize('', $param, $schema, rawValue: ''),
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
    public function raw_value_split_preserves_percent_encoded_delimiters_in_data(): void
    {
        // RFC 6570 form-style expansion percent-encodes a comma inside a
        // value (%2C) and joins elements with a literal comma, so the logical
        // list ["owner,admin", "member"] is `role=owner%2Cadmin,member` on the
        // wire. Splitting must happen before percent-decoding.
        $param = ['name' => 'role', 'in' => 'query', 'style' => 'form', 'explode' => false];
        $schema = ['type' => 'array', 'items' => ['type' => 'string']];

        $this->assertSame(
            ['owner,admin', 'member'],
            QueryStyleDeserializer::deserialize('owner,admin,member', $param, $schema, rawValue: 'owner%2Cadmin,member'),
        );
    }

    #[Test]
    public function raw_pipe_delimited_splits_on_encoded_and_literal_pipe(): void
    {
        // The OAS Style Examples (3.0.4+/3.1.2+/3.2.0) serialize the
        // pipeDelimited delimiter percent-encoded — `color=blue%7Cblack%7Cbrown`
        // — because `|` is not a legal query character, though clients also
        // send it literally. Both must split; a pipe *inside* a value is
        // consequently unrepresentable (undefined per OAS Appendix E).
        $param = ['name' => 'v', 'in' => 'query', 'style' => 'pipeDelimited'];
        $schema = ['type' => 'array', 'items' => ['type' => 'string']];

        $this->assertSame(
            ['blue', 'black', 'brown'],
            QueryStyleDeserializer::deserialize('blue|black|brown', $param, $schema, rawValue: 'blue%7Cblack%7Cbrown'),
        );
        $this->assertSame(
            ['a', 'b', 'c'],
            QueryStyleDeserializer::deserialize('a|b|c', $param, $schema, rawValue: 'a%7cb|c'),
        );
    }

    #[Test]
    public function raw_value_is_ignored_when_it_diverges_from_the_parsed_value(): void
    {
        // PSR-7 explicitly allows getQueryParams() to be out of sync with the
        // URI (withQueryParams() does not update it). The parsed map is what
        // the application saw, so a raw value that does not decode to the
        // parsed value must not override it.
        $param = ['name' => 'role', 'in' => 'query', 'style' => 'form', 'explode' => false];
        $schema = ['type' => 'array', 'items' => ['type' => 'string']];

        $this->assertSame(
            ['bogus', 'x'],
            QueryStyleDeserializer::deserialize('bogus,x', $param, $schema, rawValue: 'owner%2Cadmin,member'),
        );
    }

    #[Test]
    public function raw_space_delimited_splits_on_plus_and_percent_encoded_space(): void
    {
        // The space delimiter itself can only appear percent-encoded on the
        // wire — `%20` (RFC 6570 expansion) or `+` (form-urlencoding).
        $param = ['name' => 'v', 'in' => 'query', 'style' => 'spaceDelimited'];
        $schema = ['type' => 'array', 'items' => ['type' => 'string']];

        $this->assertSame(
            ['blue', 'black', 'brown'],
            QueryStyleDeserializer::deserialize('blue black brown', $param, $schema, rawValue: 'blue+black%20brown'),
        );
        // A literal plus in data stays `%2B`-encoded and must survive.
        $this->assertSame(
            ['a+b', 'c'],
            QueryStyleDeserializer::deserialize('a+b c', $param, $schema, rawValue: 'a%2Bb+c'),
        );
    }

    #[Test]
    public function raw_value_is_ignored_when_serialization_is_exploded(): void
    {
        $param = ['name' => 'role', 'in' => 'query', 'style' => 'form', 'explode' => true];
        $schema = ['type' => 'array', 'items' => ['type' => 'string']];

        $this->assertSame(
            'owner,admin',
            QueryStyleDeserializer::deserialize('owner,admin', $param, $schema, rawValue: 'owner,admin'),
        );
    }

    #[Test]
    public function parse_raw_values_keeps_values_encoded_and_decodes_names(): void
    {
        $this->assertSame(
            ['role' => ['owner%2Cadmin,member'], 'a b' => ['1'], 'flag' => ['']],
            QueryStyleDeserializer::parseRawValues('role=owner%2Cadmin,member&a%20b=1&flag'),
        );
    }

    #[Test]
    public function parse_raw_values_collects_repeated_keys_in_wire_order(): void
    {
        $this->assertSame(
            ['tag' => ['a', 'b']],
            QueryStyleDeserializer::parseRawValues('tag=a&tag=b'),
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
