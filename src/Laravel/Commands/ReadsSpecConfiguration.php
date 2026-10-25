<?php

declare(strict_types=1);

namespace Studio\Gesso\Laravel\Commands;

use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Studio\Gesso\Internal\SpecPath;

use function is_string;
use function trim;

/** @internal The `gesso.default_spec` / `gesso.spec_base_path` readers of the routes and stubs commands. */
trait ReadsSpecConfiguration
{
    private function configuredDefaultSpec(): string
    {
        $defaultSpec = config('gesso.default_spec');
        if (!is_string($defaultSpec) || trim($defaultSpec) === '') {
            throw new InvalidArgumentException('No OpenAPI spec selected. Pass --spec or configure gesso.default_spec.');
        }

        return trim($defaultSpec);
    }

    /** Absolute paths (POSIX, UNC, drive letter) are used as-is; others resolve under the application. */
    private function configuredSpecBasePath(Application $application): string
    {
        $basePath = config('gesso.spec_base_path');
        if (!is_string($basePath) || trim($basePath) === '') {
            throw new InvalidArgumentException('gesso.spec_base_path must be a non-empty directory path.');
        }
        $basePath = trim($basePath);

        return SpecPath::isAbsolute($basePath) ? $basePath : $application->basePath($basePath);
    }
}
