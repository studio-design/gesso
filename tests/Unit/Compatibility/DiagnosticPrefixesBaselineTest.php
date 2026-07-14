<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Unit\Compatibility;

use const JSON_THROW_ON_ERROR;
use const T_CONSTANT_ENCAPSED_STRING;
use const T_ENCAPSED_AND_WHITESPACE;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_fill_keys;
use function array_keys;
use function dirname;
use function file_get_contents;
use function in_array;
use function is_array;
use function json_decode;
use function ksort;
use function str_replace;
use function substr_count;
use function token_get_all;

final class DiagnosticPrefixesBaselineTest extends TestCase
{
    #[Test]
    public function diagnostic_prefixes_match_the_v1_9_inventory(): void
    {
        /** @var array<string, array<string, positive-int>> $fixture */
        $fixture = json_decode(
            $this->fixture(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        /** @var array<string, array<string, positive-int>> $actual */
        $actual = array_fill_keys(array_keys($fixture), []);

        foreach ($this->sourceFiles() as $file => $contents) {
            foreach (token_get_all($contents) as $token) {
                if (!is_array($token) || !in_array(
                    $token[0],
                    [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE],
                    true,
                )) {
                    continue;
                }

                // PHP token text retains the escape in a double-quoted
                // `\$self`; normalize it to the emitted diagnostic prefix.
                $literal = str_replace('\\$', '$', $token[1]);
                foreach (array_keys($fixture) as $prefix) {
                    $count = substr_count($literal, $prefix);
                    if ($count === 0) {
                        continue;
                    }

                    $actual[$prefix][$file] = ($actual[$prefix][$file] ?? 0) + $count;
                }
            }
        }

        ksort($fixture);
        ksort($actual);
        foreach ($fixture as &$files) {
            ksort($files);
        }
        unset($files);
        foreach ($actual as &$files) {
            ksort($files);
        }
        unset($files);

        $this->assertSame($fixture, $actual);
    }

    /** @return array<string, string> */
    private function sourceFiles(): array
    {
        $projectRoot = dirname(__DIR__, 3);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($projectRoot . '/src'),
        );
        $sources = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            $this->assertIsString($contents);
            $relativePath = 'src/' . $iterator->getSubPathname();
            $sources[$relativePath] = $contents;
        }

        return $sources;
    }

    private function fixture(): string
    {
        $contents = file_get_contents(
            dirname(__DIR__, 2) . '/fixtures/compatibility/v1.9-diagnostic-prefixes.json',
        );
        $this->assertIsString($contents);

        return $contents;
    }
}
