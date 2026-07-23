<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Build;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function json_decode;

final class DocumentationInstallPolicyTest extends TestCase
{
    #[Test]
    public function installation_guides_follow_the_configured_release_channel(): void
    {
        $root = dirname(__DIR__, 3);
        $configContents = file_get_contents($root . '/release-please-config.json');
        $this->assertNotFalse($configContents);

        /** @var array<string, mixed> $config */
        $config = json_decode($configContents, true, flags: JSON_THROW_ON_ERROR);
        $isPrerelease = $config['prerelease'] ?? null;
        $this->assertIsBool($isPrerelease);

        foreach ([
            'README.md',
            'docs/quickstarts/core.md',
            'docs/quickstarts/laravel.md',
            'docs/quickstarts/pest.md',
            'docs/quickstarts/symfony.md',
            'examples/core/README.md',
            'examples/laravel/README.md',
            'examples/pest/README.md',
            'examples/symfony/README.md',
        ] as $relativePath) {
            $contents = file_get_contents($root . '/' . $relativePath);
            $this->assertIsString($contents);

            if (!$isPrerelease) {
                $this->assertStringContainsString(
                    'studio-design/gesso:^2.0',
                    $contents,
                    $relativePath . ' must install the stable Gesso 2 line.',
                );
                $this->assertStringNotContainsString(
                    'studio-design/gesso:^2.0@beta',
                    $contents,
                    $relativePath . ' must stop opting in to prereleases during stable promotion.',
                );
                $this->assertStringNotContainsString(
                    'Pre-release evaluation only',
                    $contents,
                    $relativePath . ' must remove the prerelease warning during stable promotion.',
                );

                continue;
            }

            $this->assertStringContainsString(
                'studio-design/gesso:^2.0@beta',
                $contents,
                $relativePath . ' must opt in to the published Gesso 2 beta.',
            );
            $this->assertStringContainsString(
                'Pre-release evaluation only',
                $contents,
                $relativePath . ' must not present the Gesso 2 beta as a stable recommendation.',
            );
            $this->assertStringNotContainsString(
                'composer require --dev studio-design/gesso',
                $contents,
                $relativePath . ' must not present an unavailable stable release as the current install path.',
            );
        }
    }
}
