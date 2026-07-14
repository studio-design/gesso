<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\HttpMethod;
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Tests\Helpers\CreatesTestResponse;

use function array_filter;
use function count;
use function explode;
use function json_encode;
use function str_starts_with;
use function trim;

// Load namespace-level config() mock before the trait resolves the function call.
require_once __DIR__ . '/../Helpers/LaravelConfigMock.php';

class ValidatesOpenApiSchemaDefaultSpecTest extends TestCase
{
    use CreatesTestResponse;
    use ValidatesOpenApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
        OpenApiCoverageTracker::reset();
        $GLOBALS['__openapi_testing_config'] = [];
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
    public function default_open_api_spec_returns_empty_string_when_config_not_set(): void
    {
        $this->assertSame('', $this->openApiSpec());
    }

    #[Test]
    public function default_open_api_spec_returns_config_value(): void
    {
        $GLOBALS['__openapi_testing_config']['gesso.default_spec'] = 'petstore-3.0';

        $this->assertSame('petstore-3.0', $this->openApiSpec());
    }

    #[Test]
    public function null_config_value_returns_empty_string(): void
    {
        $GLOBALS['__openapi_testing_config']['gesso.default_spec'] = null;

        $this->assertSame('', $this->openApiSpec());
    }

    #[Test]
    public function empty_config_default_spec_fails_with_clear_message(): void
    {
        $response = $this->makeTestResponse('{}', 200);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage(
            'openApiSpec() must return a non-empty spec name, but an empty string was returned. '
            . 'Either add #[OpenApiSpec(\'your-spec\')] to your test class or method, '
            . 'override openApiSpec() in your test class, or set the "default_spec" key '
            . 'in config/gesso.php.',
        );

        $this->assertResponseMatchesOpenApiSchema(
            $response,
            HttpMethod::GET,
            '/v1/pets',
        );
    }

    #[Test]
    public function configured_default_spec_validates_successfully(): void
    {
        $GLOBALS['__openapi_testing_config']['gesso.default_spec'] = 'petstore-3.0';

        $body = (string) json_encode(
            ['data' => [['id' => 1, 'name' => 'Fido', 'tag' => null]]],
            JSON_THROW_ON_ERROR,
        );
        $response = $this->makeTestResponse($body, 200);

        $this->assertResponseMatchesOpenApiSchema(
            $response,
            HttpMethod::GET,
            '/v1/pets',
        );
    }

    // ========================================
    // max_errors config tests
    // ========================================

    #[Test]
    public function max_errors_config_limits_reported_errors(): void
    {
        $GLOBALS['__openapi_testing_config']['gesso.default_spec'] = 'petstore-3.0';
        $GLOBALS['__openapi_testing_config']['gesso.max_errors'] = 1;

        $body = (string) json_encode(
            ['data' => [['id' => 'bad', 'name' => 123], ['id' => 'bad', 'name' => 456]]],
            JSON_THROW_ON_ERROR,
        );
        $response = $this->makeTestResponse($body, 200);

        try {
            $this->assertResponseMatchesOpenApiSchema(
                $response,
                HttpMethod::GET,
                '/v1/pets',
            );
            $this->fail('Expected AssertionFailedError was not thrown.');
        } catch (AssertionFailedError $e) {
            // With max_errors=1, the error message should contain exactly one schema error line.
            // The error format is "[path] message", so count lines starting with "[".
            $lines = explode("\n", $e->getMessage());
            $errorLines = array_filter($lines, static fn(string $line) => str_starts_with(trim($line), '['));
            $this->assertCount(1, $errorLines);
        }
    }

    #[Test]
    public function string_numeric_max_errors_config_is_cast_to_int(): void
    {
        $GLOBALS['__openapi_testing_config']['gesso.default_spec'] = 'petstore-3.0';
        $GLOBALS['__openapi_testing_config']['gesso.max_errors'] = '1';

        $body = (string) json_encode(
            ['data' => [['id' => 'bad', 'name' => 123], ['id' => 'bad', 'name' => 456]]],
            JSON_THROW_ON_ERROR,
        );
        $response = $this->makeTestResponse($body, 200);

        try {
            $this->assertResponseMatchesOpenApiSchema(
                $response,
                HttpMethod::GET,
                '/v1/pets',
            );
            $this->fail('Expected AssertionFailedError was not thrown.');
        } catch (AssertionFailedError $e) {
            $lines = explode("\n", $e->getMessage());
            $errorLines = array_filter($lines, static fn(string $line) => str_starts_with(trim($line), '['));
            $this->assertCount(1, $errorLines);
        }
    }

    #[Test]
    public function non_numeric_max_errors_config_falls_back_to_default(): void
    {
        $GLOBALS['__openapi_testing_config']['gesso.default_spec'] = 'petstore-3.0';
        $GLOBALS['__openapi_testing_config']['gesso.max_errors'] = 'not-a-number';

        $body = (string) json_encode(
            ['data' => [['id' => 'bad', 'name' => 123], ['id' => 'bad', 'name' => 456]]],
            JSON_THROW_ON_ERROR,
        );
        $response = $this->makeTestResponse($body, 200);

        try {
            $this->assertResponseMatchesOpenApiSchema(
                $response,
                HttpMethod::GET,
                '/v1/pets',
            );
            $this->fail('Expected AssertionFailedError was not thrown.');
        } catch (AssertionFailedError $e) {
            // Falls back to default of 20, so multiple errors should be reported
            $lines = explode("\n", $e->getMessage());
            $errorLines = array_filter($lines, static fn(string $line) => str_starts_with(trim($line), '['));
            $this->assertGreaterThan(1, count($errorLines));
        }
    }

    // ========================================
    // skip_response_codes config tests
    // ========================================

    #[Test]
    public function default_skip_response_codes_config_skips_5xx_responses(): void
    {
        // With no explicit config, the default from config.php (['5\d\d']) must
        // apply: a 503 that is not in the spec must NOT fail the assertion.
        $GLOBALS['__openapi_testing_config']['gesso.default_spec'] = 'petstore-3.0';

        $body = (string) json_encode(['error' => 'service unavailable'], JSON_THROW_ON_ERROR);
        $response = $this->makeTestResponse($body, 503);

        $this->assertResponseMatchesOpenApiSchema(
            $response,
            HttpMethod::GET,
            '/v1/pets',
        );

        // Skipped endpoints still count as covered with the correct key — the
        // endpoint was exercised even though the body wasn't schema-checked.
        // Asserting the full "METHOD path" key (not just the spec name) guards
        // against a regression where skipped() stops passing through matchedPath.
        $covered = OpenApiCoverageTracker::getCovered();
        $this->assertArrayHasKey('petstore-3.0', $covered);
        $this->assertArrayHasKey('GET /v1/pets', $covered['petstore-3.0']);
    }

    #[Test]
    public function skip_response_codes_config_can_be_disabled(): void
    {
        // Empty array means "validate every status code" — a 503 not in the
        // spec must fall through to the usual "Status code not defined" failure.
        $GLOBALS['__openapi_testing_config']['gesso.default_spec'] = 'petstore-3.0';
        $GLOBALS['__openapi_testing_config']['gesso.skip_response_codes'] = [];

        $body = (string) json_encode(['error' => 'service unavailable'], JSON_THROW_ON_ERROR);
        $response = $this->makeTestResponse($body, 503);

        try {
            $this->assertResponseMatchesOpenApiSchema(
                $response,
                HttpMethod::GET,
                '/v1/pets',
            );
            $this->fail('Expected AssertionFailedError was not thrown.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('Status code 503 not defined', $e->getMessage());
        }
    }

    #[Test]
    public function custom_skip_response_codes_config_widens_skip_set(): void
    {
        // Users can widen the skip to include 4xx by overriding the config.
        $GLOBALS['__openapi_testing_config']['gesso.default_spec'] = 'petstore-3.0';
        $GLOBALS['__openapi_testing_config']['gesso.skip_response_codes'] = ['4\d\d', '5\d\d'];

        $response = $this->makeTestResponse('{}', 404);

        $this->assertResponseMatchesOpenApiSchema(
            $response,
            HttpMethod::GET,
            '/v1/pets',
        );
    }

    #[Test]
    public function non_array_skip_response_codes_config_fails_with_clear_message(): void
    {
        $GLOBALS['__openapi_testing_config']['gesso.default_spec'] = 'petstore-3.0';
        $GLOBALS['__openapi_testing_config']['gesso.skip_response_codes'] = '5\d\d';

        $response = $this->makeTestResponse('{}', 500);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('gesso.skip_response_codes must be an array');

        $this->assertResponseMatchesOpenApiSchema(
            $response,
            HttpMethod::GET,
            '/v1/pets',
        );
    }

    #[Test]
    public function non_string_skip_response_codes_entry_fails_with_clear_message(): void
    {
        // Integer status codes look tempting (`['skip_response_codes' => [500]]`)
        // but would silently break the regex-matching contract. Fail loudly.
        $GLOBALS['__openapi_testing_config']['gesso.default_spec'] = 'petstore-3.0';
        $GLOBALS['__openapi_testing_config']['gesso.skip_response_codes'] = [500];

        $response = $this->makeTestResponse('{}', 500);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('gesso.skip_response_codes[0] must be a string regex pattern');

        $this->assertResponseMatchesOpenApiSchema(
            $response,
            HttpMethod::GET,
            '/v1/pets',
        );
    }

    #[Test]
    public function empty_string_skip_response_codes_entry_fails_with_clear_message(): void
    {
        // An empty pattern compiles to /^(?:)$/ which silently matches nothing —
        // it should fail loudly at config time instead of becoming dead config.
        $GLOBALS['__openapi_testing_config']['gesso.default_spec'] = 'petstore-3.0';
        $GLOBALS['__openapi_testing_config']['gesso.skip_response_codes'] = [''];

        $response = $this->makeTestResponse('{}', 500);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('gesso.skip_response_codes[0] must not be an empty string');

        $this->assertResponseMatchesOpenApiSchema(
            $response,
            HttpMethod::GET,
            '/v1/pets',
        );
    }
}
