<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Internal;

use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Studio\Gesso\Internal\ObservedResponse;

final class ObservedResponseTest extends TestCase
{
    #[Test]
    public function psr7_responses_are_normalised(): void
    {
        $observed = ObservedResponse::from(new Response(201, ['Content-Type' => 'application/json; charset=utf-8', 'X-Id' => ['a', 'b']], '{"id":1}'));

        $this->assertSame(201, $observed->statusCode);
        $this->assertSame(['a', 'b'], $observed->headers['x-id']);
        $this->assertSame('application/json; charset=utf-8', $observed->contentType);
        $this->assertSame('{"id":1}', $observed->rawBody);
        $this->assertTrue($observed->body->present);
        $this->assertInstanceOf(stdClass::class, $observed->body->value);
    }

    #[Test]
    public function array_responses_are_normalised(): void
    {
        $observed = ObservedResponse::from(['status' => 200, 'headers' => ['CONTENT-TYPE' => 'application/problem+json'], 'body' => '[]']);

        $this->assertSame(['content-type' => ['application/problem+json']], $observed->headers);
        $this->assertSame([], $observed->body->value);
    }

    #[Test]
    public function non_json_bodies_stay_raw_and_empty_bodies_are_absent(): void
    {
        $text = ObservedResponse::from(['status' => 200, 'headers' => ['Content-Type' => 'text/plain'], 'body' => 'hi']);
        $empty = ObservedResponse::from(['status' => 204, 'headers' => [], 'body' => '']);

        $this->assertTrue($text->body->present);
        $this->assertSame('hi', $text->body->value);
        $this->assertFalse($empty->body->present);
        $this->assertNull($empty->contentType);
    }

    #[Test]
    public function an_undecodable_json_body_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('did not decode');

        ObservedResponse::from(['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => '{']);
    }

    #[Test]
    public function other_shapes_are_rejected(): void
    {
        foreach ([null, ['status' => '200', 'headers' => [], 'body' => ''], ['status' => 200, 'headers' => [], 'body' => null], ['status' => 200, 'headers' => ['x' => [[]]], 'body' => '']] as $shape) {
            try {
                ObservedResponse::from($shape);
                $this->fail('Expected InvalidArgumentException.');
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }
}
