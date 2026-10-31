<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Build;

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
    public function root_package_requires_the_runtime_and_keeps_phpunit_a_dev_dependency(): void
    {
        $composer = self::composerJson(__DIR__ . '/../../../composer.json');

        $this->assertSame('^8.3', $composer['require']['php'] ?? null);
        // v3.0 (#506): consumers declare the runner; the conflict still keeps
        // an unsupported PHPUnit from being installed alongside Gesso.
        $this->assertArrayNotHasKey('phpunit/phpunit', $composer['require']);
        $this->assertSame('^12.0 || ^13.0', $composer['require-dev']['phpunit/phpunit'] ?? null);
        $this->assertSame('<12.0 || >=14.0', $composer['conflict']['phpunit/phpunit'] ?? null);
        $this->assertArrayHasKey('phpunit/phpunit', $composer['suggest']);
    }

    /**
     * @throws JsonException
     */
    #[Test]
    public function root_package_uses_only_the_v2_composer_and_namespace_identity(): void
    {
        $composer = self::composerJson(__DIR__ . '/../../../composer.json');

        $this->assertSame('studio-design/gesso', $composer['name'] ?? null);
        $this->assertSame(['Studio\\Gesso\\' => 'src/'], $composer['autoload']['psr-4'] ?? null);
        $this->assertSame(['Studio\\Gesso\\Tests\\' => 'tests/'], $composer['autoload-dev']['psr-4'] ?? null);
        $this->assertSame(['bin/gesso'], $composer['bin'] ?? null);
        $this->assertSame('*', $composer['conflict']['studio-design/openapi-contract-testing'] ?? null);
        $this->assertArrayNotHasKey('replace', $composer);
        $this->assertNotContains('src/Compatibility/GessoAliasLoader.php', $composer['autoload']['files'] ?? []);
        $this->assertSame(
            ['Studio\\Gesso\\Laravel\\GessoServiceProvider'],
            $composer['extra']['laravel']['providers'] ?? null,
        );
        $this->assertFileDoesNotExist(__DIR__ . '/../../../bin/openapi-contract');
        $this->assertFileDoesNotExist(__DIR__ . '/../../../bin/openapi-coverage-merge');
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
            $this->assertSame('@dev', $composer['require']['studio-design/gesso'] ?? null, $example);
            $this->assertArrayNotHasKey('studio-design/openapi-contract-testing', $composer['require'], $example);
            $this->assertSame('^12.0 || ^13.0', $composer['require']['phpunit/phpunit'] ?? null, $example);
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

    /**
     * Matched on the `--with` selector rather than a whole command line: the
     * install now runs through a composite action, so the `composer` prefix
     * and the shared flags live there. Quoting around the constraint is
     * optional for the same reason — what must not drift is that the matrix
     * *selects* a PHPUnit major, instead of rewriting the root constraint.
     */
    #[Test]
    public function ci_selects_phpunit_without_rewriting_the_root_constraint(): void
    {
        $workflow = file_get_contents(__DIR__ . '/../../../.github/workflows/ci.yml');

        $this->assertNotFalse($workflow);
        $this->assertMatchesRegularExpression(
            '/update --with "?phpunit\/phpunit:\^\$\{\{ matrix\.phpunit \}\}\.0"?/',
            $workflow,
        );
        $this->assertDoesNotMatchRegularExpression('/require --dev "?phpunit\/phpunit:/', $workflow);
    }

    #[Test]
    public function documentation_distinguishes_v1_and_v2_support_surfaces(): void
    {
        $versioning = file_get_contents(__DIR__ . '/../../../docs/versioning.md');
        $upgrading = file_get_contents(__DIR__ . '/../../../UPGRADING.md');
        $identityAdr = file_get_contents(__DIR__ . '/../../../docs/adr/0001-gesso-v2-identity.md');

        $this->assertNotFalse($versioning);
        $this->assertNotFalse($upgrading);
        $this->assertNotFalse($identityAdr);
        $this->assertStringContainsString(
            'v2.x — CI: 8.3, 8.4, 8.5; Composer: `^8.3`',
            $versioning,
        );
        $this->assertStringContainsString(
            'v1.x — CI: 8.2, 8.3, 8.4; Composer: `^8.2`',
            $versioning,
        );
        $this->assertStringContainsString('v2.x: 12.x, 13.x', $versioning);
        $this->assertStringContainsString('v1.x: 11.x, 12.x, 13.x', $versioning);
        $this->assertStringContainsString(
            'Laravel route parity JSON consumers must also accept',
            $upgrading,
        );
        $this->assertStringContainsString('`schema_version: 2`', $upgrading);
        $this->assertStringContainsString('`external_operations`', $upgrading);
        $this->assertStringNotContainsString(
            'Doctor JSON, Laravel route parity JSON',
            $upgrading,
        );
        $this->assertStringContainsString(
            'Doctor still emits version 1, route parity emits version 2',
            $identityAdr,
        );
        $this->assertStringNotContainsString(
            'Doctor and route parity still emit version 1',
            $identityAdr,
        );
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
