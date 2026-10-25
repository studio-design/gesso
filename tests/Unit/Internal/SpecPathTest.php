<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Internal;

use const DIRECTORY_SEPARATOR;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Internal\SpecPath;

use function implode;

final class SpecPathTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function provideIs_absolute_recognises_posix_unc_and_drive_letter_pathsCases(): iterable
    {
        yield 'posix root' => ['/x', true];
        yield 'unc share' => ['\\\\server\\share', true];
        yield 'drive letter, backslash' => ['C:\\x', true];
        yield 'drive letter, slash' => ['d:/x', true];
        yield 'bare name' => ['x', false];
        yield 'dot relative' => ['./x', false];
        yield 'drive-relative' => ['C:x', false];
        yield 'empty' => ['', false];
    }

    #[Test]
    #[DataProvider('provideIs_absolute_recognises_posix_unc_and_drive_letter_pathsCases')]
    public function is_absolute_recognises_posix_unc_and_drive_letter_paths(string $path, bool $expected): void
    {
        $this->assertSame($expected, SpecPath::isAbsolute($path));
    }

    #[Test]
    public function is_inside_root_folds_case_only_when_asked(): void
    {
        $root = implode(DIRECTORY_SEPARATOR, ['', 'work', 'specs']);
        $path = implode(DIRECTORY_SEPARATOR, ['', 'work', 'Specs', 'secret.json']);

        $this->assertTrue(SpecPath::isInsideRoot($path, $root, foldCase: true));
        $this->assertFalse(SpecPath::isInsideRoot($path, $root, foldCase: false));
    }

    #[Test]
    public function is_inside_root_accepts_the_root_itself_and_its_descendants(): void
    {
        $root = '/work/specs/';
        $inside = '/work/specs' . DIRECTORY_SEPARATOR . 'pet.yaml';

        $this->assertTrue(SpecPath::isInsideRoot('/work/specs', $root, foldCase: false));
        $this->assertTrue(SpecPath::isInsideRoot($inside, $root, foldCase: false));
        $this->assertFalse(SpecPath::isInsideRoot('/work/specs-evil/pet.yaml', $root, foldCase: false));
    }
}
