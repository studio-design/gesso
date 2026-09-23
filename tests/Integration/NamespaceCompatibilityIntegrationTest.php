<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Integration;

use const JSON_THROW_ON_ERROR;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Studio\Gesso\Tests\Helpers\RemovesDirectories;

use function copy;
use function dirname;
use function escapeshellarg;
use function fclose;
use function is_resource;
use function json_decode;
use function mkdir;
use function proc_close;
use function proc_open;
use function realpath;
use function sprintf;
use function stream_get_contents;
use function sys_get_temp_dir;
use function uniqid;

final class NamespaceCompatibilityIntegrationTest extends TestCase
{
    use RemovesDirectories;
    private string $fixtureDirectory;
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $repoRoot = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
        $this->fixtureDirectory = $repoRoot . '/tests/fixtures/namespace-compatibility';
        $this->temporaryDirectory = sys_get_temp_dir() . '/gesso-namespace-compatibility-' . uniqid('', true);
        $this->copyDirectory($this->fixtureDirectory, $this->temporaryDirectory);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->temporaryDirectory);

        parent::tearDown();
    }

    #[Test]
    public function lazy_aliases_work_with_composer_authoritative_classmaps_and_expose_identity_limits(): void
    {
        [$dumpExit, $dumpStdout, $dumpStderr] = $this->runProcess('composer dump-autoload --classmap-authoritative --no-interaction');
        $this->assertSame(0, $dumpExit, "stderr: {$dumpStderr}\nstdout: {$dumpStdout}");

        [$consumerExit, $stdout, $stderr] = $this->runProcess(sprintf('php %s', escapeshellarg($this->temporaryDirectory . '/consumer.php')));
        $this->assertSame(0, $consumerExit, "stderr: {$stderr}\nstdout: {$stdout}");

        $result = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        $canonical = 'Studio\\Gesso\\Example';

        $this->assertSame(['example' => false, 'optional_adapter' => false], $result['lazy']);
        $this->assertSame([
            'label' => 'example',
            'canonical_instance' => true,
            'legacy_instance' => true,
            'shared_static_state' => 1,
        ], $result['class']);
        $this->assertSame('consumer', $result['interface']);
        $this->assertSame('trait', $result['trait']);
        $this->assertSame('strict', $result['enum']);
        $this->assertSame('legacy', $result['attribute']);
        $this->assertSame([
            'runtime_class' => $canonical,
            'reflection_class' => $canonical,
            'serialized' => 'O:20:"Studio\\Gesso\\Example":0:{}',
            'legacy_payload_restored_as' => 'Studio\\Gesso\\SerializedExample',
        ], $result['identity']);
        $this->assertFalse($result['optional_adapter_loaded']);
        $this->assertFalse($result['unknown_legacy_exists']);
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function runProcess(string $command): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->temporaryDirectory);
        if (!is_resource($process)) {
            $this->fail("failed to run: {$command}");
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        return [proc_close($process), $stdout, $stderr];
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
