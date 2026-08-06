<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Compatibility;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_keys;
use function dirname;
use function file_get_contents;
use function json_decode;
use function preg_match_all;
use function sort;

/**
 * The registry fixture is the ordering artifact between the deprecation
 * release and the major that removes the surfaces (issue #499): a removal PR
 * deletes the entry it removes, so a removal with nothing to delete never
 * shipped a deprecation.
 *
 * This test keeps the fixture and the call sites in `src/` in sync in both
 * directions, and rejects an entry that cannot answer "use what instead, and
 * gone when?".
 */
final class DeprecationRegistryTest extends TestCase
{
    #[Test]
    public function every_notice_id_in_src_has_a_registry_entry(): void
    {
        $registered = array_keys($this->registry());
        sort($registered);

        $this->assertSame($registered, $this->emittedIds());
    }

    #[Test]
    public function every_registry_entry_names_its_replacement_and_removal(): void
    {
        foreach ($this->registry() as $id => $entry) {
            $this->assertIsArray($entry, $id);

            foreach (['surface', 'replacement', 'removed_in'] as $key) {
                $this->assertArrayHasKey($key, $entry, $id);
                $this->assertIsString($entry[$key], $id . '.' . $key);
                $this->assertNotSame('', $entry[$key], $id . '.' . $key);
            }
        }
    }

    /**
     * Every call site passes `id:` as a named argument with a single-quoted
     * literal, so the registry can be checked statically. A call that computes
     * its id is intentionally unsupported: an id that varies at runtime cannot
     * be listed here, and therefore cannot be deleted from here either.
     *
     * @return list<string>
     */
    private function emittedIds(): array
    {
        $ids = [];

        foreach ($this->sourceFiles() as $contents) {
            preg_match_all("/Deprecations::notice\(\s*id:\s*'([^']+)'/", $contents, $matches);
            foreach ($matches[1] as $id) {
                $ids[$id] = true;
            }
        }

        $ids = array_keys($ids);
        sort($ids);

        return $ids;
    }

    /** @return array<string, mixed> */
    private function registry(): array
    {
        $contents = file_get_contents(
            dirname(__DIR__, 2) . '/fixtures/compatibility/v2-deprecations.json',
        );
        $this->assertIsString($contents);

        /** @var array{deprecations: array<string, mixed>} $fixture */
        $fixture = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        return $fixture['deprecations'];
    }

    /** @return list<string> */
    private function sourceFiles(): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/src'),
        );
        $sources = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            $this->assertIsString($contents);
            $sources[] = $contents;
        }

        return $sources;
    }
}
