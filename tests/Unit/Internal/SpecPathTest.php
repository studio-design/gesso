<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Internal;

use const DIRECTORY_SEPARATOR;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Internal\SpecPath;

use function implode;

final class SpecPathTest extends TestCase
{
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
