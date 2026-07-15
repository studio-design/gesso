<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Build;

use const JSON_THROW_ON_ERROR;

use JsonException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function json_decode;

final class ComposerArchivePolicyTest extends TestCase
{
    /**
     * @throws JsonException
     */
    #[Test]
    public function generated_development_files_are_excluded_from_archives(): void
    {
        $contents = file_get_contents(__DIR__ . '/../../../composer.json');

        $this->assertNotFalse($contents);

        /** @var array{archive?: array{exclude?: list<string>}} $composer */
        $composer = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $excluded = $composer['archive']['exclude'] ?? [];

        foreach (
            [
                '/vendor',
                '/composer.lock',
                '/node_modules',
                '/docs/.vitepress/cache',
                '/docs/.vitepress/dist',
            ] as $generatedPath
        ) {
            $this->assertContains($generatedPath, $excluded);
        }
    }
}
