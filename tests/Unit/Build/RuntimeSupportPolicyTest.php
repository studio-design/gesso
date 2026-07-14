<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Build;

use const JSON_THROW_ON_ERROR;

use JsonException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function json_decode;

final class RuntimeSupportPolicyTest extends TestCase
{
    /**
     * @throws JsonException
     */
    #[Test]
    public function root_package_requires_the_v2_runtime_and_phpunit_lines(): void
    {
        $composer = self::composerJson(__DIR__ . '/../../../composer.json');

        $this->assertSame('^8.3', $composer['require']['php'] ?? null);
        $this->assertSame('^12.0 || ^13.0', $composer['require']['phpunit/phpunit'] ?? null);
    }

    /**
     * @throws JsonException
     */
    #[Test]
    public function runnable_examples_share_the_v2_php_floor(): void
    {
        foreach (['core', 'laravel', 'pest', 'psr7', 'symfony'] as $example) {
            $composer = self::composerJson(__DIR__ . '/../../../examples/' . $example . '/composer.json');

            $this->assertSame('^8.3', $composer['require']['php'] ?? null, $example);
        }
    }

    /**
     * @throws JsonException
     */
    #[Test]
    public function pest_example_uses_the_phpunit_12_compatible_major(): void
    {
        $composer = self::composerJson(__DIR__ . '/../../../examples/pest/composer.json');

        $this->assertSame('^4.0', $composer['require']['pestphp/pest'] ?? null);
    }

    #[Test]
    public function ci_selects_phpunit_without_rewriting_the_root_constraint(): void
    {
        $workflow = file_get_contents(__DIR__ . '/../../../.github/workflows/ci.yml');

        $this->assertNotFalse($workflow);
        $this->assertStringContainsString(
            'composer update --with "phpunit/phpunit:^${{ matrix.phpunit }}.0"',
            $workflow,
        );
        $this->assertStringNotContainsString('composer require --dev "phpunit/phpunit:', $workflow);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private static function composerJson(string $path): array
    {
        $contents = file_get_contents($path);

        self::assertNotFalse($contents, $path);

        $composer = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($composer);

        return $composer;
    }
}
