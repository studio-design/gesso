<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Rector\V3;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;
use Studio\Gesso\Rector\GessoSetList;

final class GessoV3SetTest extends AbstractRectorTestCase
{
    /** @return Iterator<array-key, array{string}> */
    public static function provideCases(): iterable
    {
        return self::yieldFilesFromDirectory(__DIR__ . '/Fixture');
    }

    #[DataProvider('provideCases')]
    public function test(string $filePath): void
    {
        $this->doTestFile($filePath);
    }

    public function provideConfigFilePath(): string
    {
        return GessoSetList::V3;
    }
}
