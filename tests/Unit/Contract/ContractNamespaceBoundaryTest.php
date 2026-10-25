<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Contract;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function glob;

/**
 * Contract checks consume generator outputs (ExploredCase, ExploredOperation)
 * from Fuzz\; that direction is the accepted coupling (docs/contract-checks.md).
 * The reverse would tie the generator to the checks, so it is pinned here.
 */
final class ContractNamespaceBoundaryTest extends TestCase
{
    #[Test]
    public function src_fuzz_never_depends_on_the_contract_namespace(): void
    {
        $files = glob(dirname(__DIR__, 3) . '/src/Fuzz/*.php');
        $this->assertNotFalse($files);
        $this->assertNotSame([], $files);

        foreach ($files as $file) {
            $this->assertStringNotContainsString('Gesso\\Contract', (string) file_get_contents($file), $file);
        }
    }
}
