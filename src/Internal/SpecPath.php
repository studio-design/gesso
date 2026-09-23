<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use const DIRECTORY_SEPARATOR;

use function explode;
use function in_array;
use function preg_match;
use function rtrim;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strtolower;

/**
 * Filesystem-path helpers shared by the root spec loader and the enum
 * binding loader: both resolve a caller-supplied relative name under a
 * trusted base directory and must refuse anything that escapes it.
 *
 * @internal Not part of the package's public API. Do not use from user code.
 */
final class SpecPath
{
    private function __construct() {}

    /**
     * True when the relative name could select a parent, an absolute
     * filesystem location, or contains a NUL byte. Callers reject these
     * before checking existence so the exception category cannot be used
     * as an existence probe.
     */
    public static function escapesBase(string $relative): bool
    {
        $portable = str_replace('\\', '/', $relative);

        return str_contains($portable, "\0") ||
            str_starts_with($portable, '/') ||
            preg_match('/^[A-Za-z]:\//', $portable) === 1 ||
            in_array('..', explode('/', $portable), true);
    }

    public static function join(string $basePath, string $relativePath): string
    {
        if ($basePath === '') {
            return $relativePath;
        }

        $basePath = rtrim($basePath, '/\\');

        return ($basePath === '' ? DIRECTORY_SEPARATOR : $basePath . DIRECTORY_SEPARATOR) . $relativePath;
    }

    public static function isInsideRoot(string $path, string $root): bool
    {
        $root = rtrim($root, '/\\');
        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $root = strtolower($root);
        }

        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }
}
