<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Studio\Gesso\Internal\SpecDocumentDecoder;

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
}
