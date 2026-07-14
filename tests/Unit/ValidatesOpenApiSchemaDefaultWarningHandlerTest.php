<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit;

use const E_USER_DEPRECATED;
use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Attribute\SkipOpenApi;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\HttpMethod;
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Tests\Helpers\CreatesTestResponse;

use function json_encode;
use function restore_error_handler;
use function set_error_handler;

require_once __DIR__ . '/../Helpers/LaravelConfigMock.php';

/**
 * Verifies the default warning handler path (STDERR + trigger_error) when
 * $skipWarningHandler is not swapped. Other skip tests inject a handler so
 * this path would otherwise go untested and a future refactor could silently
 * drop the E_USER_DEPRECATED emission.
 */
class ValidatesOpenApiSchemaDefaultWarningHandlerTest extends TestCase
{
    use CreatesTestResponse;
    use ValidatesOpenApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
        OpenApiCoverageTracker::reset();
        $GLOBALS['__openapi_testing_config'] = [
            'gesso.default_spec' => 'petstore-3.0',
        ];
        // Intentionally do NOT set $skipWarningHandler — exercise the default.
    }

    protected function tearDown(): void
    {
        self::resetValidatorCache();
        unset($GLOBALS['__openapi_testing_config']);
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        parent::tearDown();
    }

    #[Test]
    #[SkipOpenApi(reason: 'testing default path')]
    public function default_handler_emits_e_user_deprecated(): void
    {
        $captured = null;
        set_error_handler(
            static function (int $errno, string $msg) use (&$captured): bool {
                $captured = ['errno' => $errno, 'msg' => $msg];

                // Returning true suppresses the default PHP error handler so
                // PHPUnit's own error converter doesn't turn the deprecation
                // into a test failure. STDERR output still fires before this
                // handler is invoked, which is fine — we only assert on the
                // captured values.
                return true;
            },
            E_USER_DEPRECATED,
        );

        try {
            $body = (string) json_encode(
                ['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]],
                JSON_THROW_ON_ERROR,
            );
            $response = $this->makeTestResponse($body, 200);

            $this->assertResponseMatchesOpenApiSchema($response, HttpMethod::GET, '/v1/pets');
        } finally {
            restore_error_handler();
        }

        $this->assertNotNull($captured, 'Expected trigger_error to fire E_USER_DEPRECATED');
        $this->assertSame(E_USER_DEPRECATED, $captured['errno']);
        $this->assertStringContainsString('#[SkipOpenApi', $captured['msg']);
        $this->assertStringContainsString("reason: 'testing default path'", $captured['msg']);
    }
}
