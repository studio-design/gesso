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
}
