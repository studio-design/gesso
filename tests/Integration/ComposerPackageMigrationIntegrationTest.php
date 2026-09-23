<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Integration;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Studio\Gesso\Tests\Helpers\RemovesDirectories;

use function copy;
use function dirname;
use function fclose;
use function is_resource;
use function mkdir;
use function proc_close;
use function proc_open;
use function realpath;
use function stream_get_contents;
use function sys_get_temp_dir;
use function uniqid;

final class ComposerPackageMigrationIntegrationTest extends TestCase
{
    use RemovesDirectories;
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $repoRoot = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
        $fixtureDirectory = $repoRoot . '/tests/fixtures/composer-migration';
        $this->temporaryDirectory = sys_get_temp_dir() . '/gesso-composer-migration-' . uniqid('', true);
        $this->copyDirectory($fixtureDirectory, $this->temporaryDirectory);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->temporaryDirectory);

        parent::tearDown();
    }

    #[Test]
    public function gesso_v2_resolves_without_the_legacy_package(): void
    {
        [$exit, $output] = $this->resolve('new-only');

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('studio-design/gesso (2.0.0)', $output);
        $this->assertStringNotContainsString('studio-design/openapi-contract-testing (1.10.0)', $output);
    }

    #[Test]
    public function gesso_v2_rejects_a_direct_dual_install(): void
    {
        [$exit, $output] = $this->resolve('dual-install');

        $this->assertNotSame(0, $exit, $output);
        $this->assertStringContainsString('studio-design/gesso 2.0.0 conflicts with studio-design/openapi-contract-testing', $output);
    }

    #[Test]
    public function gesso_v2_does_not_satisfy_a_transitive_legacy_requirement(): void
    {
        [$exit, $output] = $this->resolve('transitive-legacy');

        $this->assertNotSame(0, $exit, $output);
        $this->assertStringContainsString('gesso-fixture/legacy-consumer 1.0.0 requires studio-design/openapi-contract-testing', $output);
        $this->assertStringContainsString('studio-design/gesso 2.0.0 conflicts with studio-design/openapi-contract-testing', $output);
    }

    /** @return array{0: int, 1: string} */
    private function resolve(string $scenario): array
    {
        $scenarioDirectory = $this->temporaryDirectory . '/' . $scenario;
        $composerHome = $this->temporaryDirectory . '/composer-home';
        $command = [
            '/usr/bin/env',
            'COMPOSER_HOME=' . $composerHome,
            'COMPOSER_CACHE_DIR=' . $composerHome . '/cache',
            'COMPOSER_DISABLE_NETWORK=1',
            'composer',
            'update',
            '--dry-run',
            '--no-ansi',
            '--no-audit',
            '--no-interaction',
            '--no-plugins',
            '--no-progress',
            '--no-scripts',
        ];

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $scenarioDirectory);
        if (!is_resource($process)) {
            $this->fail('failed to run Composer dependency resolution fixture');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        return [proc_close($process), $stdout . $stderr];
    }

    private function copyDirectory(string $source, string $destination): void
    {
        mkdir($destination, 0o755, recursive: true);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $target = $destination . '/' . $iterator->getSubPathname();
            if ($item->isDir()) {
                mkdir($target, 0o755, recursive: true);
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }
}
