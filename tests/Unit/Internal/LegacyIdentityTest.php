<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Internal;

use const E_USER_DEPRECATED;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Studio\Gesso\Internal\LegacyIdentity;

use function array_keys;
use function dirname;
use function file_get_contents;
use function preg_match;
use function putenv;
use function restore_error_handler;
use function set_error_handler;
use function str_replace;

/**
 * Issue #504: the legacy `OPENAPI_*` / `openapi:*` spellings keep working for
 * the whole of v3, so the map and its one-time warning are the contract, not
 * the individual read sites.
 */
final class LegacyIdentityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->clearEnv();
        LegacyIdentity::resetForTesting();
    }

    protected function tearDown(): void
    {
        $this->clearEnv();
        LegacyIdentity::resetForTesting();
        parent::tearDown();
    }

    #[Test]
    public function the_current_name_is_read_without_a_warning(): void
    {
        putenv('GESSO_BASELINE_GENERATE=1');

        $this->assertSame('1', LegacyIdentity::env('GESSO_BASELINE_GENERATE'));
        $this->assertSame([], LegacyIdentity::warnings());
    }

    #[Test]
    public function the_legacy_name_still_resolves_and_names_its_replacement(): void
    {
        putenv('OPENAPI_BASELINE_GENERATE=1');

        $this->assertSame('1', LegacyIdentity::env('GESSO_BASELINE_GENERATE'));
        $this->assertSame(
            ['[Gesso] WARNING: OPENAPI_BASELINE_GENERATE is deprecated and will be removed in Gesso 4.0.0. Use GESSO_BASELINE_GENERATE.'],
            LegacyIdentity::warnings(),
        );
    }

    #[Test]
    public function the_warning_carries_the_removal_version_from_the_constant(): void
    {
        putenv('OPENAPI_CONSOLE_OUTPUT=all');
        LegacyIdentity::env('GESSO_CONSOLE_OUTPUT');

        $this->assertStringContainsString(
            'removed in Gesso ' . LegacyIdentity::REMOVED_IN . '.',
            LegacyIdentity::warnings()[0] ?? '',
        );
    }

    #[Test]
    public function the_current_name_wins_when_both_are_set_and_nothing_warns(): void
    {
        putenv('OPENAPI_VALIDATION_OUTPUT=text');
        putenv('GESSO_VALIDATION_FORMAT=json');

        $this->assertSame('json', LegacyIdentity::env('GESSO_VALIDATION_FORMAT'));
        $this->assertSame([], LegacyIdentity::warnings());
    }

    #[Test]
    public function a_blank_current_name_falls_through_to_the_legacy_one(): void
    {
        // Not the same as unset: an `env:` block that sets the new name to the
        // empty string must not shadow a legacy name that carries a value.
        putenv('GESSO_VALIDATION_FORMAT=  ');
        putenv('OPENAPI_VALIDATION_OUTPUT=json');

        $this->assertSame('json', LegacyIdentity::env('GESSO_VALIDATION_FORMAT'));
    }

    #[Test]
    public function a_blank_legacy_value_is_not_a_value(): void
    {
        putenv('OPENAPI_VALIDATION_OUTPUT=  ');

        $this->assertFalse(LegacyIdentity::env('GESSO_VALIDATION_FORMAT'));
        $this->assertSame([], LegacyIdentity::warnings());
    }

    #[Test]
    public function an_unset_pair_reads_as_false(): void
    {
        $this->assertFalse(LegacyIdentity::env('GESSO_BASELINE_GENERATE'));
    }

    #[Test]
    public function a_name_outside_the_map_is_read_as_itself(): void
    {
        putenv('GESSO_NOT_MAPPED=x');

        $this->assertSame('x', LegacyIdentity::env('GESSO_NOT_MAPPED'));

        putenv('GESSO_NOT_MAPPED');
        $this->assertFalse(LegacyIdentity::env('GESSO_NOT_MAPPED'));
    }

    #[Test]
    public function the_warning_is_emitted_once_per_process(): void
    {
        putenv('OPENAPI_BASELINE_GENERATE=1');

        LegacyIdentity::env('GESSO_BASELINE_GENERATE');
        LegacyIdentity::env('GESSO_BASELINE_GENERATE');
        LegacyIdentity::env('GESSO_BASELINE_GENERATE');

        $this->assertCount(1, LegacyIdentity::warnings());
    }

    #[Test]
    public function the_legacy_path_does_not_raise_a_php_deprecation(): void
    {
        // A suite running `failOnDeprecation` must not fail for spelling a
        // name that is still supported — this is why the legacy warning does
        // not go through Studio\Gesso\Internal\Deprecations.
        putenv('OPENAPI_BASELINE_GENERATE=1');

        $deprecations = [];
        set_error_handler(
            static function (int $level, string $message) use (&$deprecations): bool {
                $deprecations[] = $message;

                return true;
            },
            E_USER_DEPRECATED,
        );

        try {
            LegacyIdentity::env('GESSO_BASELINE_GENERATE');
            LegacyIdentity::warnIfLegacyCommand('openapi:stubs');
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $deprecations);
    }

    #[Test]
    public function a_legacy_command_name_warns_and_a_current_one_does_not(): void
    {
        LegacyIdentity::warnIfLegacyCommand('gesso:routes');
        $this->assertSame([], LegacyIdentity::warnings());

        LegacyIdentity::warnIfLegacyCommand('openapi:routes');
        $this->assertSame(
            ['[Gesso] WARNING: openapi:routes is deprecated and will be removed in Gesso 4.0.0. Use gesso:routes.'],
            LegacyIdentity::warnings(),
        );
    }

    #[Test]
    public function an_unknown_command_name_is_ignored(): void
    {
        // Symfony hands whatever the user typed to the command, including the
        // empty string when the input carries no first argument.
        LegacyIdentity::warnIfLegacyCommand('');
        LegacyIdentity::warnIfLegacyCommand('migrate');

        $this->assertSame([], LegacyIdentity::warnings());
    }

    /**
     * A lifecycle that clears only the current name leaves the legacy spelling
     * in the ambient environment able to steer a test that asserts default
     * behaviour — a leak that stays invisible until the suite runs on a machine
     * exporting the old name. Every clear therefore has to go through
     * {@see LegacyIdentity::resetEnvForTesting()}, which clears both.
     */
    #[Test]
    public function no_test_clears_a_renamed_variable_without_its_legacy_spelling(): void
    {
        $offenders = [];

        foreach ($this->suiteSources() as $file => $contents) {
            foreach (LegacyIdentity::ENV_NAMES as $current) {
                if (preg_match('/putenv\(\s*([\'"])' . $current . '\1\s*\)/', $contents) === 1) {
                    $offenders[] = "{$file}: putenv('{$current}')";
                }
            }
        }

        $this->assertSame([], $offenders, "Use LegacyIdentity::resetEnvForTesting('<name>') instead.");
    }

    /** @return array<string, string> */
    private function suiteSources(): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2)),
        );
        $sources = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if ($contents !== false) {
                $sources['tests/' . str_replace(dirname(__DIR__, 2) . '/', '', $file->getPathname())] = $contents;
            }
        }

        return $sources;
    }

    private function clearEnv(): void
    {
        foreach (array_keys(LegacyIdentity::ENV_NAMES) as $legacy) {
            putenv($legacy);
        }
        foreach (LegacyIdentity::ENV_NAMES as $current) {
            putenv($current);
        }
        putenv('GESSO_NOT_MAPPED');
    }
}
