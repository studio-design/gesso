<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;

require_once __DIR__ . '/../Helpers/LaravelConfigMock.php';

/**
 * Priority row 4 of 4: config default_spec is used when no #[OpenApiSpec]
 * attribute is present and openApiSpec() is not overridden (so it falls
 * through to the trait's default implementation that reads from config).
 */
class SpecPriorityConfigTest extends TestCase
{
    use ValidatesOpenApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__openapi_testing_config'] = [
            'gesso.default_spec' => 'from-config',
        ];
    }

    protected function tearDown(): void
    {
        self::resetValidatorCache();
        unset($GLOBALS['__openapi_testing_config']);
        parent::tearDown();
    }

    #[Test]
    public function config_is_used_when_all_higher_layers_absent(): void
    {
        $this->assertSame('from-config', $this->resolveOpenApiSpec());
    }
}
