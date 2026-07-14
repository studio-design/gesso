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

// Load namespace-level config() mock before the trait resolves the function call.
require_once __DIR__ . '/../Helpers/LaravelConfigMock.php';

class ValidatesOpenApiSchemaSkipTest extends TestCase
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
    #[SkipOpenApi]
    public function method_level_skip_opts_out_of_auto_assert(): void
    {
        // Body intentionally violates the spec — if auto-assert ran, this
        // would throw AssertionFailedError.
        $body = (string) json_encode(['wrong_key' => 'value'], JSON_THROW_ON_ERROR);
        $response = $this->makeTestResponse($body, 200);

        $this->maybeAutoAssertOpenApiSchema($response, HttpMethod::GET, '/v1/pets');

        // Coverage must not be recorded for skipped tests.
        $this->assertArrayNotHasKey('petstore-3.0', OpenApiCoverageTracker::getCovered());
    }

    #[Test]
    #[SkipOpenApi]
    public function skipped_test_does_not_emit_warning_when_no_explicit_assert(): void
    {
        $body = (string) json_encode(['wrong_key' => 'value'], JSON_THROW_ON_ERROR);
        $response = $this->makeTestResponse($body, 200);

        $this->maybeAutoAssertOpenApiSchema($response, HttpMethod::GET, '/v1/pets');

        $this->assertSame([], $this->capturedWarnings);
    }

    #[Test]
    #[SkipOpenApi]
    public function explicit_assert_on_skipped_test_emits_warning_and_still_validates(): void
    {
        $body = (string) json_encode(
            ['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]],
            JSON_THROW_ON_ERROR,
        );
        $response = $this->makeTestResponse($body, 200);

        $this->assertResponseMatchesOpenApiSchema($response, HttpMethod::GET, '/v1/pets');

        // Warning was emitted once.
        $this->assertCount(1, $this->capturedWarnings);
        $this->assertStringContainsString('#[SkipOpenApi]', $this->capturedWarnings[0]);
        $this->assertStringContainsString('assertResponseMatchesOpenApiSchema', $this->capturedWarnings[0]);

        // And validation actually ran — coverage was recorded despite the skip.
        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayHasKey('petstore-3.0', $covered);
        $this->assertArrayHasKey('GET /v1/pets', $covered['petstore-3.0']);
    }

    #[Test]
    public function non_skipped_explicit_assert_does_not_emit_warning(): void
    {
        $body = (string) json_encode(
            ['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]],
            JSON_THROW_ON_ERROR,
        );
        $response = $this->makeTestResponse($body, 200);

        $this->assertResponseMatchesOpenApiSchema($response, HttpMethod::GET, '/v1/pets');

        $this->assertSame([], $this->capturedWarnings);
    }

    #[Test]
    #[SkipOpenApi(reason: 'intentional violation test')]
    public function warning_message_includes_reason_when_provided(): void
    {
        $body = (string) json_encode(
            ['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]],
            JSON_THROW_ON_ERROR,
        );
        $response = $this->makeTestResponse($body, 200);

        $this->assertResponseMatchesOpenApiSchema($response, HttpMethod::GET, '/v1/pets');

        $this->assertCount(1, $this->capturedWarnings);
        // Formatted via var_export so the reason value is quoted verbatim.
        $this->assertStringContainsString("(reason: 'intentional violation test')", $this->capturedWarnings[0]);
    }
}
