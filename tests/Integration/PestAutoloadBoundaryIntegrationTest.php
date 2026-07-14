<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Integration;

use const PHP_BINARY;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function fclose;
use function file_put_contents;
use function is_resource;
use function proc_close;
use function proc_open;
use function stream_get_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class PestAutoloadBoundaryIntegrationTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $temporaryFile) {
            unlink($temporaryFile);
        }

        $this->temporaryFiles = [];
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideComposer_autoload_entrypoint_stays_dormant_until_pest_is_fully_availableCases(): iterable
    {
        yield 'Pest absent' => [<<<'PHP'
            <?php

            declare(strict_types=1);

            require $argv[1];
            echo 'loaded';
            PHP];

        yield 'Expectation class only' => [<<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Pest {
                final class Expectation {}
            }

            namespace {
                require $argv[1];
                echo 'loaded';
            }
            PHP];

        yield 'expect function only' => [<<<'PHP'
            <?php

            declare(strict_types=1);

            function expect(): never
            {
                throw new RuntimeException('the partial-install guard must return before calling expect()');
            }

            require $argv[1];
            echo 'loaded';
            PHP];
    }

    #[DataProvider('provideComposer_autoload_entrypoint_stays_dormant_until_pest_is_fully_availableCases')]
    #[Test]
    public function composer_autoload_entrypoint_stays_dormant_until_pest_is_fully_available(string $consumer): void
    {
        $script = tempnam(sys_get_temp_dir(), 'gesso-pest-autoload-');
        $this->assertIsString($script);
        $this->temporaryFiles[] = $script;
        $this->assertNotFalse(file_put_contents($script, $consumer));

        $process = proc_open(
            [PHP_BINARY, $script, __DIR__ . '/../../src/Pest/Autoload.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            __DIR__ . '/../..',
        );
        $this->assertTrue(is_resource($process));

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        $this->assertSame(0, proc_close($process), $stderr);
        $this->assertSame('loaded', $stdout);
        $this->assertSame('', $stderr);
    }
}
