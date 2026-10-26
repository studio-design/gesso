<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Config;

use const E_USER_DEPRECATED;
use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Extension\ParameterCollection;
use Studio\Gesso\Config\LegacyConfigurationKeys;
use Studio\Gesso\Internal\Deprecations;
use Studio\Gesso\PHPUnit\ExtensionParameters;

use function array_keys;
use function array_unique;
use function array_values;
use function count;
use function file_get_contents;
use function in_array;
use function json_decode;
use function restore_error_handler;
use function set_error_handler;
use function sort;
use function sys_get_temp_dir;

final class LegacyConfigurationKeysTest extends TestCase
{
    /** @var list<array{int, string}> */
    private array $captured = [];

    protected function setUp(): void
    {
        parent::setUp();
        Deprecations::resetForTesting();
        $this->captured = [];
        set_error_handler(function (int $errno, string $message): bool {
            $this->captured[] = [$errno, $message];

            return true;
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        Deprecations::resetForTesting();
        parent::tearDown();
    }

    #[Test]
    public function the_tables_cover_exactly_the_config_keys_v3_renames_stages_here(): void
    {
        $owned = array_keys($this->renamesStagedHere());
        sort($owned);
        $table = array_values(array_unique([...LegacyConfigurationKeys::PHPUNIT, ...LegacyConfigurationKeys::LARAVEL]));
        sort($table);

        $this->assertSame($owned, $table);
    }

    #[Test]
    public function every_spelling_emits_one_notice_naming_its_gesso_php_key(): void
    {
        $owned = $this->renamesStagedHere();
        foreach ($owned as $spelling => $replacement) {
            LegacyConfigurationKeys::deprecate($spelling);
            LegacyConfigurationKeys::deprecate($spelling);
        }

        // One notice per spelling, in call order: the second call is deduped.
        $this->assertCount(count($owned), $this->captured);
        $i = 0;
        foreach ($owned as $spelling => $replacement) {
            [$errno, $message] = $this->captured[$i++];
            $this->assertSame(E_USER_DEPRECATED, $errno);
            $this->assertStringContainsString("'" . $spelling . "'", $message);
            $this->assertStringContainsString($replacement, $message);
        }
    }

    #[Test]
    public function unknown_names_are_a_no_op(): void
    {
        LegacyConfigurationKeys::deprecate('config');
        LegacyConfigurationKeys::deprecate('auto_inject_dummy_bearer');

        $this->assertSame([], $this->captured);
        $this->assertSame([], Deprecations::counts());
    }

    #[Test]
    public function extension_parameters_emit_once_per_process_and_the_xml_value_still_wins(): void
    {
        $collection = ParameterCollection::fromArray(['strict_required' => 'warn', 'spec_base_path' => 'specs']);

        $first = ExtensionParameters::load($collection, sys_get_temp_dir());
        ExtensionParameters::load($collection, sys_get_temp_dir());

        $this->assertCount(2, $this->captured);
        $this->assertStringContainsString("strict.required['run']", $this->captured[0][1]);
        $this->assertStringContainsString('spec.base_path', $this->captured[1][1]);
        $this->assertSame(['config.spec_base_path' => 2, 'phpunit.strict_required' => 2], Deprecations::counts());
        $this->assertSame('warn', $first->get('strict_required'));
        $this->assertSame('specs', $first->get('spec_base_path'));
    }

    #[Test]
    public function an_explicitly_empty_parameter_is_still_reported(): void
    {
        ExtensionParameters::load(ParameterCollection::fromArray(['console_output' => '']), sys_get_temp_dir());

        $this->assertSame(['phpunit.console_output' => 1], Deprecations::counts());
    }

    /**
     * The config keys this table stages: everything but the two emitted
     * elsewhere (`auto_inject_dummy_bearer` from the Laravel trait,
     * `enum_spec_base_path` from the extension).
     *
     * @return array<string, string> spelling => v3 replacement
     */
    private function renamesStagedHere(): array
    {
        /** @var array{renames: array<string, array{surface: string, owner: string, replacement: string}>} $fixture */
        $fixture = json_decode(
            (string) file_get_contents(__DIR__ . '/../../fixtures/compatibility/v3-renames.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $owned = [];
        foreach ($fixture['renames'] as $spelling => $entry) {
            if ($entry['surface'] === 'config-key' && !in_array($spelling, ['auto_inject_dummy_bearer', 'enum_spec_base_path'], true)) {
                $owned[$spelling] = $entry['replacement'];
            }
        }

        return $owned;
    }
}
