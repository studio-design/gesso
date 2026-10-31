<?php

declare(strict_types=1);

namespace Studio\Gesso\PHPUnit;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;
use Studio\Gesso\Internal\StackTraceFilter;

use function class_exists;

/**
 * The one place under src/ that calls PHPUnit's Assert: fail/assertTrue with
 * this library's frames stripped from the trace (#506). phpunit/phpunit is a
 * consumer dev dependency since v3.0, so a missing runner surfaces here as
 * one actionable error instead of a "Class not found" fatal.
 *
 * @internal Adapter plumbing; not part of the package's public API.
 */
final class CleanTraceAssert
{
    private function __construct() {}

    public static function fail(string $message): never
    {
        self::requirePhpUnit();

        try {
            Assert::fail($message);
        } catch (AssertionFailedError $e) {
            StackTraceFilter::rethrowWithCleanTrace($e);
        }
    }

    public static function assertTrue(bool $condition, string $message): void
    {
        self::requirePhpUnit();

        try {
            Assert::assertTrue($condition, $message);
        } catch (AssertionFailedError $e) {
            StackTraceFilter::rethrowWithCleanTrace($e);
        }
    }

    private static function requirePhpUnit(): void
    {
        if (!class_exists(Assert::class)) {
            throw new RuntimeException(
                'Gesso assertions need PHPUnit, which Gesso no longer installs for you. '
                . 'Run: composer require --dev "phpunit/phpunit:^12.0 || ^13.0"',
            );
        }
    }
}
