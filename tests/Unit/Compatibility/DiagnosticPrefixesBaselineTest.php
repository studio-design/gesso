<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Compatibility;

use const ARRAY_FILTER_USE_KEY;
use const JSON_THROW_ON_ERROR;
use const T_CONSTANT_ENCAPSED_STRING;
use const T_ENCAPSED_AND_WHITESPACE;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_diff;
use function array_fill_keys;
use function array_filter;
use function array_keys;
use function array_unique;
use function array_values;
use function dirname;
use function file_get_contents;
use function in_array;
use function is_array;
use function json_decode;
use function ksort;
use function ltrim;
use function preg_match;
use function str_replace;
use function substr_count;
use function token_get_all;

final class DiagnosticPrefixesBaselineTest extends TestCase
{
    #[Test]
    public function diagnostic_prefixes_match_the_v2_inventory(): void
    {
        $fixture = $this->fixture('v2-diagnostic-prefixes.json');
        $sources = $this->sourceFiles();

        // Discovery puts every prefix src/ emits into the accumulator, so one
        // missing from the fixture fails the comparison below.
        $prefixes = array_keys($fixture);
        foreach ($sources as $contents) {
            $prefixes = [...$prefixes, ...self::discoverPrefixes($contents)];
        }
        $prefixes = array_values(array_unique($prefixes));

        /** @var array<string, array<string, positive-int>|array{}> $actual */
        $actual = array_fill_keys($prefixes, []);
        foreach ($sources as $file => $contents) {
            foreach (self::stringLiterals($contents) as $literal) {
                foreach ($prefixes as $prefix) {
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

    #[Test]
    public function identity_neutral_prefixes_retain_v1_9_parity(): void
    {
        $brandedPrefixes = ['[openapi-contract-testing]', '[Gesso]', '[Gesso deprecation]'];
        $v1 = array_filter(
            $this->fixture('v1.9-diagnostic-prefixes.json'),
            static fn(string $prefix): bool => !in_array($prefix, $brandedPrefixes, true),
            ARRAY_FILTER_USE_KEY,
        );
        $v2 = array_filter(
            $this->fixture('v2-diagnostic-prefixes.json'),
            static fn(string $prefix): bool => !in_array($prefix, $brandedPrefixes, true),
            ARRAY_FILTER_USE_KEY,
        );

        // Parity contract: every identity-neutral prefix v1.9 emitted is still
        // emitted somewhere under src/. v2 may add prefixes (discovery forces
        // each into the v2 fixture); where a literal lives is not pinned.
        $this->assertSame(
            [],
            array_values(array_diff(array_keys($v1), array_keys($v2))),
            'a v1.9 identity-neutral prefix disappeared from the v2 inventory',
        );
        foreach ($v2 as $prefix => $files) {
            $this->assertNotSame([], $files, $prefix . ' is no longer emitted anywhere under src/.');
        }
    }

    #[Test]
    public function discovery_reports_a_prefix_absent_from_the_fixture(): void
    {
        $this->assertSame(
            ['[OpenAPI Something New]'],
            self::discoverPrefixes(
                '<?php trigger_error("[OpenAPI Something New] WARNING: x"); $r = "/^[A-Z]+$/"; $a = "#[OpenApiSpec] y"; $l = "[request] z";',
            ),
        );
    }

    /**
     * A diagnostic channel prefix is a bracketed tag at the very start of a
     * string literal whose first character is an uppercase letter. That
     * excludes regex classes, `#[Attribute]` mentions, `[%s]` placeholders
     * and lowercase location labels such as `[request]`.
     *
     * @return list<string>
     */
    private static function discoverPrefixes(string $phpSource): array
    {
        $prefixes = [];
        foreach (self::stringLiterals($phpSource) as $literal) {
            if (preg_match('/^\[[A-Z][A-Za-z0-9 .$-]*\]/', ltrim($literal, '\'"'), $m) === 1) {
                $prefixes[] = $m[0];
            }
        }

        return $prefixes;
    }

    /** @return list<string> */
    private static function stringLiterals(string $phpSource): array
    {
        $literals = [];
        foreach (token_get_all($phpSource) as $token) {
            if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                // PHP token text retains the escape in a double-quoted
                // `\$self`; normalize it to the emitted diagnostic prefix.
                $literals[] = str_replace('\\$', '$', $token[1]);
            }
        }

        return $literals;
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

    /** @return array<string, array<string, positive-int>|array{}> */
    private function fixture(string $filename): array
    {
        $contents = file_get_contents(
            dirname(__DIR__, 2) . '/fixtures/compatibility/' . $filename,
        );
        $this->assertIsString($contents);

        /** @var array<string, array<string, positive-int>|array{}> */
        return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    }
}
