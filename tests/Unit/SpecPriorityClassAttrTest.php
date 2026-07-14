<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Attribute\OpenApiSpec;
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;

require_once __DIR__ . '/../Helpers/LaravelConfigMock.php';

/**
 * Priority row 2 of 4: class-level #[OpenApiSpec] wins when no method-level
 * attribute is present, even if openApiSpec() override and config are set.
 */
#[OpenApiSpec('from-class-attr')]
class SpecPriorityClassAttrTest extends TestCase
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
    public function class_attr_wins_over_override_and_config(): void
    {
        $this->assertSame('from-class-attr', $this->resolveOpenApiSpec());
    }

    protected function openApiSpec(): string
    {
        return 'from-override';
    }
}
