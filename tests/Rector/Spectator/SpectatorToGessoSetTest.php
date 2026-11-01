<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Rector\Spectator;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;
use Studio\Gesso\Rector\GessoSetList;

final class SpectatorToGessoSetTest extends AbstractRectorTestCase
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
        return GessoSetList::SPECTATOR_TO_GESSO;
    }
}
