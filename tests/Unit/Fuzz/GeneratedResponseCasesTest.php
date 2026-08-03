<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Fuzz;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Fuzz\GeneratedResponseCase;
use Studio\Gesso\Fuzz\GeneratedResponseCases;

use function iterator_to_array;

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
