<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Studio\Gesso\Exception\InvalidOpenApiSpecException;
use Studio\Gesso\Exception\InvalidOpenApiSpecReason;
use Studio\Gesso\Internal\SpecDocumentDecoder;

use function get_object_vars;

final class SpecDocumentDecoderTest extends TestCase
{
    #[Test]
    public function json_temporarily_preserves_nested_empty_object_shapes(): void
    {
        $decoded = SpecDocumentDecoder::decodeJson(
            '{"security":[{},[]],"schema":{}}',
            'inline JSON',
        );

        $this->assertInstanceOf(stdClass::class, $decoded['security'][0]);
        $this->assertSame([], $decoded['security'][1]);
        $this->assertInstanceOf(stdClass::class, $decoded['schema']);
    }

    #[Test]
    public function yaml_temporarily_preserves_nested_empty_object_shapes(): void
    {
        $decoded = SpecDocumentDecoder::decodeYaml(
            "security:\n  - {}\n  - []\nschema: {}\n",
            'inline YAML',
        );

        $this->assertInstanceOf(stdClass::class, $decoded['security'][0]);
        $this->assertSame([], $decoded['security'][1]);
        $this->assertInstanceOf(stdClass::class, $decoded['schema']);
    }

    #[Test]
    public function array_valued_links_keyword_is_tagged_as_an_object(): void
    {
        $decoded = SpecDocumentDecoder::decodeJson(
            '{"list":{"links":[{"operationId":"a"}]},"map":{"links":{"0":{"operationId":"a"}}},"schema":{"properties":{"links":[1]}}}',
            'inline JSON',
        );

        $this->assertInstanceOf(stdClass::class, $decoded['list']['links']);
        $this->assertSame([['operationId' => 'a']], $decoded['map']['links']);
        // A property named `links` is a Schema Object position, not the Links map.
        $this->assertSame([1], $decoded['schema']['properties']['links']);
    }

    #[Test]
    public function object_valued_enum_keyword_keeps_its_object_shape(): void
    {
        $decoded = SpecDocumentDecoder::decodeJson(
            '{"schema":{"enum":{"0":"red"},"properties":{"enum":{"type":"string"}}}}',
            'inline JSON',
        );

        $this->assertInstanceOf(stdClass::class, $decoded['schema']['enum']);
        // A property named `enum` is a Schema Object the resolver must walk.
        $this->assertSame(['type' => 'string'], $decoded['schema']['properties']['enum']);
    }

    #[Test]
    public function enum_inside_example_data_is_not_kept_as_an_object(): void
    {
        $decoded = SpecDocumentDecoder::decodeJson(
            '{"example":{"settings":{"enum":{"a":1}}},"default":[{"enum":{"a":1}}]}',
            'inline JSON',
        );

        $this->assertSame(['a' => 1], $decoded['example']['settings']['enum']);
        $this->assertSame(['a' => 1], $decoded['default'][0]['enum']);
    }

    #[Test]
    public function yaml_dates_that_cannot_be_rebuilt_verbatim_take_a_canonical_form(): void
    {
        $decoded = SpecDocumentDecoder::decodeYaml(
            <<<'YAML'
                spaced: 2026-06-15 22:30:00
                unpadded: 2026-6-5
                trailingZero: 2026-06-15T22:30:00.50Z
                shortOffset: 2026-06-15T22:30:00 +5
                localMidnight: 2026-06-15T00:00:00
                YAML,
            'inline YAML',
        );

        $this->assertSame(
            [
                'spaced' => '2026-06-15T22:30:00',
                'unpadded' => '2026-06-05',
                'trailingZero' => '2026-06-15T22:30:00.5Z',
                'shortOffset' => '2026-06-15T22:30:00+05:00',
                'localMidnight' => '2026-06-15',
            ],
            $decoded,
        );
    }

    #[Test]
    public function yaml_dates_in_an_object_valued_enum_are_strings(): void
    {
        $decoded = SpecDocumentDecoder::decodeYaml("schema:\n  enum:\n    0: 2026-06-15\n", 'inline YAML');

        $this->assertInstanceOf(stdClass::class, $decoded['schema']['enum']);
        $this->assertSame(['0' => '2026-06-15'], get_object_vars($decoded['schema']['enum']));
    }

    #[Test]
    public function yaml_flow_mapping_with_an_unquoted_date_key_is_rejected(): void
    {
        try {
            SpecDocumentDecoder::decodeYaml("examples: {2026-06-15: {value: 1}}\n", 'inline YAML');
            $this->fail('Expected InvalidOpenApiSpecException');
        } catch (InvalidOpenApiSpecException $e) {
            $this->assertSame(InvalidOpenApiSpecReason::MalformedYaml, $e->reason);
        }
    }
}
