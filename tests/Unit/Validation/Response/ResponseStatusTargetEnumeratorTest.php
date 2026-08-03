<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Validation\Response;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Validation\Response\ResponseStatusTargetEnumerator;

final class ResponseStatusTargetEnumeratorTest extends TestCase
{
    #[Test]
    public function exact_range_and_default_targets_preserve_declared_keys(): void
    {
        $targets = ResponseStatusTargetEnumerator::enumerate([
            200 => [],
            '2xx' => [],
            'default' => [],
            'x-note' => [],
        ]);

        $this->assertSame([
            ['declaredStatusKey' => '200', 'selector' => '200', 'wireStatus' => 200],
            ['declaredStatusKey' => '2xx', 'selector' => '2XX', 'wireStatus' => 201],
            ['declaredStatusKey' => 'default', 'selector' => 'default', 'wireStatus' => 100],
        ], $targets);
    }

    #[Test]
    public function range_target_is_unreachable_when_every_status_in_its_class_is_exact(): void
    {
        $responses = [];
        for ($status = 200; $status <= 299; $status++) {
            $responses[$status] = [];
        }
        $responses['2XX'] = [];

        $targets = ResponseStatusTargetEnumerator::enumerate($responses);

        $this->assertNull($targets[100]['wireStatus']);
    }

    #[Test]
    public function default_target_is_unreachable_when_every_status_class_has_a_range(): void
    {
        $targets = ResponseStatusTargetEnumerator::enumerate([
            '1XX' => [],
            '2XX' => [],
            '3XX' => [],
            '4XX' => [],
            '5XX' => [],
            'default' => [],
        ]);

        $this->assertNull($targets[5]['wireStatus']);
    }

    #[Test]
    public function invalid_response_key_fails_loudly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Invalid response key '20': expected an exact HTTP status, range status, or default.",
        );

        ResponseStatusTargetEnumerator::enumerate(['20' => []]);
    }
}
