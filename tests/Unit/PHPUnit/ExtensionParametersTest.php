<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\PHPUnit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Extension\ParameterCollection;
use Studio\Gesso\Config\ConfigurationBridge;
use Studio\Gesso\Config\InvalidGessoConfigurationException;
use Studio\Gesso\Internal\Diagnostics;
use Studio\Gesso\PHPUnit\ExtensionParameters;

use function fclose;
use function fopen;
use function rewind;
use function stream_get_contents;
use function sys_get_temp_dir;

final class ExtensionParametersTest extends TestCase
{
    /** @var null|resource */
    private $stderr;

    protected function setUp(): void
    {
        parent::setUp();
        $stream = fopen('php://memory', 'w+');
        $this->assertNotFalse($stream);
        $this->stderr = $stream;
        Diagnostics::overrideStderrForTesting($stream);
    }

    protected function tearDown(): void
    {
        Diagnostics::overrideStderrForTesting(null);
        if ($this->stderr !== null) {
            fclose($this->stderr);
        }
        parent::tearDown();
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideEvery_v2_parameter_is_fatal_and_names_its_replacementCases(): iterable
    {
        foreach (ConfigurationBridge::PHPUNIT as $name => [$key]) {
            yield $name => [$name, $key];
        }
        yield 'enum_spec_base_path' => ['enum_spec_base_path', 'delete it; enum bindings resolve against components.schemas'];
    }

    #[DataProvider('provideEvery_v2_parameter_is_fatal_and_names_its_replacementCases')]
    #[Test]
    public function every_v2_parameter_is_fatal_and_names_its_replacement(string $name, string $replacement): void
    {
        try {
            ExtensionParameters::load(ParameterCollection::fromArray([$name => 'x']), sys_get_temp_dir());
            $this->fail('Expected an InvalidGessoConfigurationException.');
        } catch (InvalidGessoConfigurationException $e) {
            $this->assertStringContainsString("'{$name}' -> {$replacement}", $e->getMessage());
        }

        $this->assertNotNull($this->stderr);
        rewind($this->stderr);
        $this->assertStringContainsString('[Gesso] FATAL: ', (string) stream_get_contents($this->stderr));
    }

    #[Test]
    public function the_config_parameter_alone_is_accepted(): void
    {
        $parameters = ExtensionParameters::load(ParameterCollection::fromArray([]), sys_get_temp_dir() . '/gesso-no-config-here');

        $this->assertFalse($parameters->has('specs'));
    }
}
