<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Validation\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Validation\Support\ParameterStyleDeserializer;

class ParameterStyleDeserializerTest extends TestCase
{
    #[Test]
    public function splits_form_explode_false_on_comma(): void
    {
        $param = ['name' => 'role', 'in' => 'query', 'style' => 'form', 'explode' => false];
        $schema = ['type' => 'array', 'items' => ['type' => 'string']];

        $this->assertSame(
            ['owner', 'admin'],
            ParameterStyleDeserializer::deserialize('owner,admin', $param, $schema),
        );
    }

    #[Test]
    public function splits_pipe_delimited_on_pipe(): void
    {
        $param = ['name' => 'role', 'in' => 'query', 'style' => 'pipeDelimited'];
        $schema = ['type' => 'array', 'items' => ['type' => 'string']];

        $this->assertSame(
            ['blue', 'black', 'brown'],
            ParameterStyleDeserializer::deserialize('blue|black|brown', $param, $schema),
        );
    }

    #[Test]
    public function splits_space_delimited_on_space(): void
    {
        $param = ['name' => 'role', 'in' => 'query', 'style' => 'spaceDelimited'];
        $schema = ['type' => 'array', 'items' => ['type' => 'string']];

        $this->assertSame(
            ['blue', 'black', 'brown'],
            ParameterStyleDeserializer::deserialize('blue black brown', $param, $schema),
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
            ParameterStyleDeserializer::deserialize('a,b', $param, $schema),
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
            ParameterStyleDeserializer::deserialize('owner,admin', $param, $schema),
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
                ParameterStyleDeserializer::deserialize('a,b', $param, $schema),
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
            ParameterStyleDeserializer::deserialize('owner,admin', $param, $schema),
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
            ParameterStyleDeserializer::deserialize(['a', 'b'], $param, $schema),
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
            ParameterStyleDeserializer::deserialize('', $param, $schema),
        );
        $this->assertSame(
            [''],
            ParameterStyleDeserializer::deserialize('', $param, $schema, rawValue: ''),
        );
    }

    #[Test]
    public function single_value_becomes_single_element_array(): void
    {
        $param = ['name' => 'role', 'in' => 'query', 'style' => 'form', 'explode' => false];
        $schema = ['type' => 'array', 'items' => ['type' => 'string']];

        $this->assertSame(
            ['owner'],
            ParameterStyleDeserializer::deserialize('owner', $param, $schema),
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
            ParameterStyleDeserializer::deserialize('a,b', $param, $schema),
        );
    }

    #[Test]
    public function unknown_style_is_left_untouched(): void
    {
        $param = ['name' => 'role', 'in' => 'query', 'style' => 'deepObject', 'explode' => false];
        $schema = ['type' => 'array', 'items' => ['type' => 'string']];

        $this->assertSame(
            'a,b',
            ParameterStyleDeserializer::deserialize('a,b', $param, $schema),
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
            ParameterStyleDeserializer::deserialize('owner,admin,member', $param, $schema, rawValue: 'owner%2Cadmin,member'),
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
            ParameterStyleDeserializer::deserialize('blue|black|brown', $param, $schema, rawValue: 'blue%7Cblack%7Cbrown'),
        );
        $this->assertSame(
            ['a', 'b', 'c'],
            ParameterStyleDeserializer::deserialize('a|b|c', $param, $schema, rawValue: 'a%7cb|c'),
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
            ParameterStyleDeserializer::deserialize('bogus,x', $param, $schema, rawValue: 'owner%2Cadmin,member'),
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
            ParameterStyleDeserializer::deserialize('blue black brown', $param, $schema, rawValue: 'blue+black%20brown'),
        );
        // A literal plus in data stays `%2B`-encoded and must survive.
        $this->assertSame(
            ['a+b', 'c'],
            ParameterStyleDeserializer::deserialize('a+b c', $param, $schema, rawValue: 'a%2Bb+c'),
        );
    }

    #[Test]
    public function raw_value_is_ignored_when_serialization_is_exploded(): void
    {
        $param = ['name' => 'role', 'in' => 'query', 'style' => 'form', 'explode' => true];
        $schema = ['type' => 'array', 'items' => ['type' => 'string']];

        $this->assertSame(
            'owner,admin',
            ParameterStyleDeserializer::deserialize('owner,admin', $param, $schema, rawValue: 'owner,admin'),
        );
    }

    #[Test]
    public function parse_raw_values_keeps_values_encoded_and_decodes_names(): void
    {
        $this->assertSame(
            ['role' => ['owner%2Cadmin,member'], 'a b' => ['1'], 'flag' => ['']],
            ParameterStyleDeserializer::parseRawValues('role=owner%2Cadmin,member&a%20b=1&flag'),
        );
    }

    #[Test]
    public function parse_raw_values_collects_repeated_keys_in_wire_order(): void
    {
        $this->assertSame(
            ['tag' => ['a', 'b']],
            ParameterStyleDeserializer::parseRawValues('tag=a&tag=b'),
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
            ParameterStyleDeserializer::deserialize('a,b', ['name' => 'r', 'style' => 42, 'explode' => false], $schema),
        );
        $this->assertSame(
            'a,b',
            ParameterStyleDeserializer::deserialize('a,b', ['name' => 'r', 'style' => 'form', 'explode' => 'false'], $schema),
        );
        // An explicit null is a malformed declaration too, not an unspecified
        // one — it must not collapse to the defaults.
        $this->assertSame(
            'a,b',
            ParameterStyleDeserializer::deserialize('a,b', ['name' => 'r', 'style' => null, 'explode' => false], $schema),
        );
        $this->assertSame(
            'a,b',
            ParameterStyleDeserializer::deserialize('a,b', ['name' => 'r', 'style' => 'pipeDelimited', 'explode' => null], $schema),
        );
    }

    #[Test]
    public function path_values_are_split_before_each_piece_is_decoded(): void
    {
        $param = ['name' => 'ids', 'in' => 'path'];

        $this->assertSame(
            ['a,b', 'c d'],
            ParameterStyleDeserializer::deserialize('a%2Cb,c%20d', $param, ['type' => 'array']),
        );
        $this->assertSame('a b', ParameterStyleDeserializer::deserialize('a%20b', $param, ['type' => 'string']));
    }

    #[Test]
    public function header_values_are_never_percent_decoded(): void
    {
        $param = ['name' => 'X-Ids', 'in' => 'header'];

        $this->assertSame(['a%2Cb', 'c'], ParameterStyleDeserializer::deserialize('a%2Cb,c', $param, ['type' => 'array']));
        $this->assertSame('a%20b', ParameterStyleDeserializer::deserialize('a%20b', $param, ['type' => 'string']));
    }

    #[Test]
    public function matrix_without_a_value_is_the_empty_string(): void
    {
        // Style Examples, "undefined" column: `;color`.
        $param = ['name' => 'color', 'in' => 'path', 'style' => 'matrix'];

        $this->assertSame('', ParameterStyleDeserializer::deserialize(';color', $param, ['type' => 'string']));
        $this->assertSame(';colors=x', ParameterStyleDeserializer::deserialize(';colors=x', $param, ['type' => 'string']));
    }

    #[Test]
    public function non_exploded_object_with_an_odd_number_of_pieces_is_left_untouched(): void
    {
        $param = ['name' => 'color', 'in' => 'header'];

        $this->assertSame('R,100,G', ParameterStyleDeserializer::deserialize('R,100,G', $param, ['type' => 'object']));
        $this->assertSame(['R' => '100', 'G' => '200'], ParameterStyleDeserializer::deserialize('R,100,G,200', $param, ['type' => 'object']));
    }

    #[Test]
    public function non_exploded_query_object_keeps_an_encoded_comma_in_data(): void
    {
        $param = ['name' => 'color', 'in' => 'query', 'explode' => false];

        $this->assertSame(
            ['name' => 'a,b', 'R' => '1'],
            ParameterStyleDeserializer::deserialize('name,a,b,R,1', $param, ['type' => 'object'], rawValue: 'name,a%2Cb,R,1'),
        );
    }

    #[Test]
    public function missing_prefix_names_the_leading_delimiter_the_style_requires(): void
    {
        $string = ['type' => 'string'];
        $object = ['type' => 'object'];
        $array = ['type' => 'array'];
        $label = ['name' => 'color', 'in' => 'path', 'style' => 'label'];
        $matrix = ['name' => 'color', 'in' => 'path', 'style' => 'matrix'];

        $this->assertSame('.', ParameterStyleDeserializer::missingPrefix('blue', $label, $string));
        $this->assertNull(ParameterStyleDeserializer::missingPrefix('.blue', $label, $string));
        $this->assertSame(';color', ParameterStyleDeserializer::missingPrefix('blue', $matrix, $string));
        $this->assertSame(';color=', ParameterStyleDeserializer::missingPrefix(';R=1', ['explode' => true] + $matrix, $array));
        $this->assertNull(ParameterStyleDeserializer::missingPrefix(';R=1', ['explode' => true] + $matrix, $object));
        $this->assertNull(ParameterStyleDeserializer::missingPrefix('blue', ['name' => 'color', 'in' => 'path'], $string));
    }
}
