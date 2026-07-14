<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit;

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

require_once __DIR__ . '/../Helpers/LaravelConfigMock.php';

#[SkipOpenApi(reason: 'entire class is experimental')]
class ValidatesOpenApiSchemaClassLevelSkipTest extends TestCase
{
    use CreatesTestResponse;
    use ValidatesOpenApiSchema;

    /** @var list<string> */
    private array $capturedWarnings = [];

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
        OpenApiCoverageTracker::reset();
        $GLOBALS['__openapi_testing_config'] = [
            'gesso.default_spec' => 'petstore-3.0',
            'gesso.auto_assert' => true,
        ];

        $this->capturedWarnings = [];
        self::$skipWarningHandler = function (string $message): void {
            $this->capturedWarnings[] = $message;
        };
    }

    protected function tearDown(): void
    {
        self::resetValidatorCache();
        self::$skipWarningHandler = null;
        unset($GLOBALS['__openapi_testing_config']);
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        parent::tearDown();
    }

    #[Test]
    public function class_level_skip_opts_out_of_auto_assert(): void
    {
        $body = (string) json_encode(['wrong_key' => 'value'], JSON_THROW_ON_ERROR);
        $response = $this->makeTestResponse($body, 200);

        $this->maybeAutoAssertOpenApiSchema($response, HttpMethod::GET, '/v1/pets');

        $this->assertArrayNotHasKey('petstore-3.0', OpenApiCoverageTracker::getCovered());
        // Auto-assert skip does not emit a warning (only explicit calls do).
        $this->assertSame([], $this->capturedWarnings);
    }

    #[Test]
    public function class_level_skip_emits_warning_with_class_reason_on_explicit_assert(): void
    {
        $body = (string) json_encode(
            ['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]],
            JSON_THROW_ON_ERROR,
        );
        $response = $this->makeTestResponse($body, 200);

        $this->assertResponseMatchesOpenApiSchema($response, HttpMethod::GET, '/v1/pets');

        // Warning fires with the class-level reason (no method-level attribute overrides it).
        $this->assertCount(1, $this->capturedWarnings);
        $this->assertStringContainsString("reason: 'entire class is experimental'", $this->capturedWarnings[0]);

        // Explicit assert still ran — validation + coverage recorded.
        $this->assertArrayHasKey('petstore-3.0', OpenApiCoverageTracker::getCovered());
    }
}
