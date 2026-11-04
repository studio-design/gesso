<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Rector\League;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

final class LeagueToGessoSetTest extends AbstractRectorTestCase
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
        return __DIR__ . '/config.php';
    }
}
