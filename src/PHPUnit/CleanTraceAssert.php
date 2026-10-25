<?php

declare(strict_types=1);

namespace Studio\Gesso\PHPUnit;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use Studio\Gesso\Internal\StackTraceFilter;

/**
 * The one place under src/ that calls PHPUnit's Assert: fail/assertTrue with
 * this library's frames stripped from the trace (#506).
 *
 * @internal Adapter plumbing; not part of the package's public API.
 */
final class CleanTraceAssert
{
    private function __construct() {}

    public static function fail(string $message): never
    {
        try {
            Assert::fail($message);
        } catch (AssertionFailedError $e) {
            StackTraceFilter::rethrowWithCleanTrace($e);
        }
    }

    public static function assertTrue(bool $condition, string $message): void
    {
        try {
            Assert::assertTrue($condition, $message);
        } catch (AssertionFailedError $e) {
            StackTraceFilter::rethrowWithCleanTrace($e);
        }
    }
}
