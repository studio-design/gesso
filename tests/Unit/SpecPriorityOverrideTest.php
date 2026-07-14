<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;

require_once __DIR__ . '/../Helpers/LaravelConfigMock.php';

/**
 * Priority row 3 of 4: an openApiSpec() override wins over config when no
 * #[OpenApiSpec] attribute is present at either class or method level.
 */
class SpecPriorityOverrideTest extends TestCase
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
    public function override_wins_over_config(): void
    {
        $this->assertSame('from-override', $this->resolveOpenApiSpec());
    }

    protected function openApiSpec(): string
    {
        return 'from-override';
    }
}
