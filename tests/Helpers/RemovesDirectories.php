<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Helpers;

use function is_dir;
use function rmdir;
use function scandir;
use function unlink;

/** Recursive delete for the temp directories tests build under sys_get_temp_dir(). */
trait RemovesDirectories
{
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
