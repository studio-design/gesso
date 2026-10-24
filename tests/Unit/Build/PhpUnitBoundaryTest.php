<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Build;

use const T_NAME_FULLY_QUALIFIED;
use const T_NAME_QUALIFIED;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function dirname;
use function file_get_contents;
use function in_array;
use function is_array;
use function ltrim;
use function sort;
use function str_replace;
use function token_get_all;

/**
 * Pins the #506 boundary: PHPUnit's Assert is reached only through
 * PHPUnit\CleanTraceAssert, so moving phpunit/phpunit to require-dev in
 * v3.0 has exactly one caller to account for.
 */
final class PhpUnitBoundaryTest extends TestCase
{
    #[Test]
    public function the_only_assert_caller_under_src_is_clean_trace_assert(): void
    {
        $this->assertSame(
            ['src/PHPUnit/CleanTraceAssert.php'],
            $this->filesReferencing('PHPUnit\Framework\Assert'),
        );
    }

    #[Test]
    public function assertion_failed_error_is_referenced_only_by_adapter_plumbing(): void
    {
        // OpenApiAssertionCore keeps the type for the baseline-demotion catch
        // in extractOrRecordBaselineViolation(); it never raises one itself.
        $this->assertSame(
            ['src/Internal/OpenApiAssertionCore.php', 'src/PHPUnit/CleanTraceAssert.php'],
            $this->filesReferencing('PHPUnit\Framework\AssertionFailedError'),
        );
    }

    #[Test]
    public function the_scan_sees_an_inline_fully_qualified_call(): void
    {
        $this->assertTrue($this->references(
            "<?php\nnamespace X;\n\\PHPUnit\\Framework\\Assert::fail('m');",
            'PHPUnit\Framework\Assert',
        ));
    }

    /** @return list<string> */
    private function filesReferencing(string $class): array
    {
        $root = dirname(__DIR__, 3);
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src'));
        $files = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            $this->assertIsString($contents);
            if ($this->references($contents, $class)) {
                $files[] = 'src/' . str_replace('\\', '/', $iterator->getSubPathname());
            }
        }
        sort($files);

        return $files;
    }

    private function references(string $source, string $class): bool
    {
        foreach (token_get_all($source) as $token) {
            if (
                is_array($token) &&
                in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) &&
                ltrim($token[1], '\\') === $class
            ) {
                return true;
            }
        }

        return false;
    }
}
