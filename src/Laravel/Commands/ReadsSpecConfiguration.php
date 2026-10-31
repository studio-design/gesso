<?php

declare(strict_types=1);

namespace Studio\Gesso\Laravel\Commands;

use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Studio\Gesso\Internal\SpecPath;
use Studio\Gesso\Laravel\LaravelConfig;

use function is_string;
use function trim;

/** @internal The `spec.default` / `spec.base_path` readers of the routes and stubs commands. */
trait ReadsSpecConfiguration
{
    private function configuredDefaultSpec(): string
    {
        $defaultSpec = LaravelConfig::get('spec.default');
        if (!is_string($defaultSpec) || trim($defaultSpec) === '') {
            throw new InvalidArgumentException('No OpenAPI spec selected. Pass --spec or set spec.default in gesso.php.');
        }

        return trim($defaultSpec);
    }

    /**
     * Absolute paths (POSIX, UNC, drive letter) are used as-is; others resolve
     * under the application. Unset, it is the application's `openapi/`.
     */
    private function configuredSpecBasePath(Application $application): string
    {
        $basePath = LaravelConfig::get('spec.base_path') ?? 'openapi';
        if (!is_string($basePath) || trim($basePath) === '') {
            throw new InvalidArgumentException('gesso.spec.base_path must be a non-empty directory path.');
        }
        $basePath = trim($basePath);

        return SpecPath::isAbsolute($basePath) ? $basePath : $application->basePath($basePath);
    }
}
