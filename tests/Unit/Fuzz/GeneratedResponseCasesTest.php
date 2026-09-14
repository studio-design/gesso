<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Fuzz;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Studio\Gesso\Fuzz\GeneratedResponseCase;
use Studio\Gesso\Fuzz\GeneratedResponseCases;

use function iterator_to_array;
use function serialize;
use function unserialize;

class GeneratedResponseCasesTest extends TestCase
{
    #[Test]
    public function rejects_an_empty_collection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must contain at least one GeneratedResponseCase');

        new GeneratedResponseCases([]);
    }

    #[Test]
    public function is_countable_iterable_and_applies_each_callback(): void
    {
        $first = $this->caseAt(0);
        $second = $this->caseAt(1);
        $cases = new GeneratedResponseCases([$first, $second]);
        $visited = [];

        $returned = $cases->each(static function (GeneratedResponseCase $case) use (&$visited): void {
            $visited[] = $case->caseIndex;
        });

        $this->assertCount(2, $cases);
        $this->assertSame([0, 1], $visited);
        $this->assertSame([$first, $second], iterator_to_array($cases));
        $this->assertSame($cases, $returned);
    }

    #[Test]
    public function each_invokes_the_optional_hook_immediately_before_each_callback(): void
    {
        $events = [];
        $cases = new GeneratedResponseCases(
            [$this->caseAt(0), $this->caseAt(1)],
            static function () use (&$events): void {
                $events[] = 'before';
            },
        );

        try {
            $cases->each(static function (GeneratedResponseCase $case) use (&$events): never {
                $events[] = 'callback-' . $case->caseIndex;

                throw new RuntimeException('decoder failed');
            });
            $this->fail('The decoder failure must escape the collection.');
        } catch (RuntimeException) {
            $this->assertSame(['before', 'callback-0'], $events);
        }
    }

    #[Test]
    public function serialization_keeps_the_cases_and_drops_the_hook(): void
    {
        $hookCalls = 0;
        $cases = new GeneratedResponseCases(
            [$this->caseAt(0), $this->caseAt(1)],
            static function () use (&$hookCalls): void {
                $hookCalls++;
            },
        );

        $copy = unserialize(serialize($cases));
        $this->assertInstanceOf(GeneratedResponseCases::class, $copy);
        $this->assertCount(2, $copy);

        $visited = [];
        $copy->each(static function (GeneratedResponseCase $case) use (&$visited): void {
            $visited[] = $case->caseIndex;
        });
        $this->assertSame([0, 1], $visited);
        $this->assertSame(0, $hookCalls);
    }

    private function caseAt(int $index): GeneratedResponseCase
    {
        return new GeneratedResponseCase(
            body: ['active' => true],
            status: 200,
            contentType: 'application/json',
            seed: 1,
            caseIndex: $index,
            pinnedBranch: null,
            specName: 'sdk-roundtrip',
            method: 'POST',
            matchedPath: '/oauth/introspect',
            schema: [
                'type' => 'object',
                'required' => ['active'],
                'properties' => ['active' => ['type' => 'boolean']],
            ],
        );
    }
}
