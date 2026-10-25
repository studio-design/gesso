<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Contract\ContractCheck;

/**
 * With preserved global state, PHPUnit's child process require_once's the
 * parent's class files instead of autoloading them, so the legacy alias must
 * be declared by the class file itself.
 */
final class ContractNamespaceSeparateProcessTest extends TestCase
{
    /**
     * @return iterable<string, array{ContractCheck}>
     */
    public static function provideLegacy_type_accepts_canonical_value_in_a_separate_processCases(): iterable
    {
        yield 'ignored auth' => [ContractCheck::IgnoredAuth];
    }

    #[Test]
    #[DataProvider('provideLegacy_type_accepts_canonical_value_in_a_separate_processCases')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(true)]
    public function legacy_type_accepts_canonical_value_in_a_separate_process(\Studio\Gesso\Fuzz\ContractCheck $check): void
    {
        $this->assertSame('ignored_auth', $check->value);
    }
}
