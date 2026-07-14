<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Integration\Laravel;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Studio\OpenApiContractTesting\Laravel\OpenApiContractTestingServiceProvider;

use function config_path;
use function dirname;
use function file_get_contents;
use function json_encode;

final class LaravelCompatibilityBaselineIntegrationTest extends TestCase
{
    #[Test]
    public function configuration_matches_the_v1_9_compatibility_fixture(): void
    {
        /** @var array<string, mixed> $configuration */
        $configuration = require dirname(__DIR__, 3) . '/src/Laravel/config.php';
        $expected = file_get_contents(
            dirname(__DIR__, 2) . '/fixtures/compatibility/v1.9-laravel-config.json',
        );

        $this->assertIsString($expected);
        $this->assertSame(
            $expected,
            json_encode(
                $configuration,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ) . "\n",
        );
        $this->assertSame($configuration, config('openapi-contract-testing'));
    }

    #[Test]
    public function provider_publishes_the_v1_9_config_destination_and_tag(): void
    {
        $source = dirname(__DIR__, 3) . '/src/Laravel/config.php';

        $this->assertSame(
            [$source => config_path('openapi-contract-testing.php')],
            ServiceProvider::pathsToPublish(
                OpenApiContractTestingServiceProvider::class,
                'openapi-contract-testing',
            ),
        );
    }

    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [OpenApiContractTestingServiceProvider::class];
    }
}
