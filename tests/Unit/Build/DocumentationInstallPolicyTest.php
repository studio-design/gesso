<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Build;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;

final class DocumentationInstallPolicyTest extends TestCase
{
    #[Test]
    public function installation_guides_use_the_stable_v2_constraint(): void
    {
        $root = dirname(__DIR__, 3);

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

            $this->assertStringContainsString(
                'studio-design/gesso:^2.0',
                $contents,
                $relativePath . ' must use the stable v2 constraint.',
            );
            $this->assertStringNotContainsString(
                'studio-design/gesso:^2.0@beta',
                $contents,
                $relativePath . ' must not opt stable users into beta releases.',
            );
            $this->assertStringNotContainsString(
                'Pre-release evaluation only',
                $contents,
                $relativePath . ' must not present v2 as a prerelease.',
            );
        }

        $homeContents = file_get_contents($root . '/docs/.vitepress/theme/components/TomboHome.vue');
        $this->assertIsString($homeContents);

        $this->assertStringContainsString(
            'studio-design/gesso:^2.0',
            $homeContents,
            'The home page must use the stable v2 constraint.',
        );
        $this->assertStringNotContainsString(
            'studio-design/gesso:^2.0@beta',
            $homeContents,
            'The home page must not opt stable users into beta releases.',
        );
    }
}
