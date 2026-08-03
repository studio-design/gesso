<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

use Closure;
use WeakMap;

/**
 * Associates internal execution hooks without changing the public collection
 * constructor or its readonly value surface.
 *
 * @internal Not part of the package's public API.
 */
final class GeneratedResponseCasesHookRegistry
{
    /** @var null|WeakMap<GeneratedResponseCases, Closure(): void> */
    private static ?WeakMap $hooks = null;

    /** @param Closure(): void $beforeEach */
    public static function set(GeneratedResponseCases $cases, Closure $beforeEach): void
    {
        self::$hooks ??= new WeakMap();
        self::$hooks[$cases] = $beforeEach;
    }

    public static function invoke(GeneratedResponseCases $cases): void
    {
        $beforeEach = self::$hooks[$cases] ?? null;
        if ($beforeEach !== null) {
            $beforeEach();
        }
    }
}
