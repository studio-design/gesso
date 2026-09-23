<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use const STDERR;

use function fwrite;
use function getcwd;
use function is_callable;
use function realpath;
use function rtrim;
use function str_starts_with;

/**
 * Writer seams and path resolution shared by the `gesso` subcommands.
 *
 * The using class declares the `$stdoutWriter` / `$stderrWriter` callables
 * (or `null` for the real streams), an `$invocation` string, and a static
 * `usage(string $invocation)` for {@see self::usageError()}.
 *
 * @internal CLI implementation detail.
 */
trait CliIo
{
    private function absolutise(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }
        $cwd = getcwd();
        $absolute = rtrim($cwd !== false ? $cwd : '.', '/') . '/' . $path;

        return realpath($absolute) ?: $absolute;
    }

    /** Usage errors exit 2 on every subcommand (their `EXIT_USAGE`). */
    private function usageError(string $message): int
    {
        $this->writeStderr("[Gesso] {$message}\n\n" . self::usage($this->invocation));

        return 2;
    }

    private function writeStdout(string $message): void
    {
        if (is_callable($this->stdoutWriter)) {
            ($this->stdoutWriter)($message);

            return;
        }
        echo $message;
    }

    private function writeStderr(string $message): void
    {
        if (is_callable($this->stderrWriter)) {
            ($this->stderrWriter)($message);

            return;
        }
        fwrite(STDERR, $message);
    }
}
