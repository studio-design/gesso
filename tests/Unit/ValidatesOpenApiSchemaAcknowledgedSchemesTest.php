<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit;

use const E_USER_WARNING;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\HttpMethod;
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Request\AcknowledgedSecuritySchemes;
use Studio\Gesso\Validation\Request\SecurityValidator;
use Symfony\Component\HttpFoundation\Request;

use function restore_error_handler;
use function set_error_handler;
use function str_starts_with;

// Load namespace-level config() mock before the trait resolves the function call.
require_once __DIR__ . '/../Helpers/LaravelConfigMock.php';

/**
 * Covers the `acknowledged_unvalidatable_schemes` config wiring (issue #445):
 * the Laravel trait pushes the configured scheme names into the process-global
 * {@see AcknowledgedSecuritySchemes} registry so the security validator stops
 * warning for exactly those schemes — without the consumer touching the global
 * PHP error handler.
 */
class ValidatesOpenApiSchemaAcknowledgedSchemesTest extends TestCase
{
    use ValidatesOpenApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
        OpenApiCoverageTracker::reset();
        SecurityValidator::resetWarningStateForTesting();
        AcknowledgedSecuritySchemes::reset();
        $GLOBALS['__openapi_testing_config'] = [
            'gesso.default_spec' => 'petstore-3.0',
            'gesso.auto_validate_request' => true,
        ];
    }

    protected function tearDown(): void
    {
        self::resetValidatorCache();
        unset($GLOBALS['__openapi_testing_config']);
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        SecurityValidator::resetWarningStateForTesting();
        AcknowledgedSecuritySchemes::reset();
        parent::tearDown();
    }

    #[Test]
    public function config_file_defaults_acknowledged_unvalidatable_schemes_to_empty_list(): void
    {
        $config = require __DIR__ . '/../../src/Laravel/config.php';

        $this->assertArrayHasKey('acknowledged_unvalidatable_schemes', $config);
        $this->assertSame([], $config['acknowledged_unvalidatable_schemes']);
    }

    #[Test]
    public function unacknowledged_unvalidatable_scheme_warns_during_auto_request_validation(): void
    {
        // Baseline behaviour: with no acknowledgement configured, the
        // oauth2-secured endpoint fires the silent-pass warning.
        $warnings = $this->validateOauth2OnlyCapturingSecurityWarnings();

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString("'oauth2Flow'", $warnings[0]);
    }

    #[Test]
    public function acknowledged_scheme_suppresses_the_silent_pass_warning(): void
    {
        $GLOBALS['__openapi_testing_config']['gesso.acknowledged_unvalidatable_schemes'] = ['oauth2Flow'];

        $warnings = $this->validateOauth2OnlyCapturingSecurityWarnings();

        $this->assertSame([], $warnings);
        $this->assertArrayHasKey(
            'GET /v1/secure/oauth2-only',
            OpenApiCoverageTracker::getCovered()['petstore-3.0'] ?? [],
            'silent-pass semantics must be preserved: the endpoint still validates and is covered',
        );
    }

    #[Test]
    public function non_array_config_value_fails_loudly(): void
    {
        $GLOBALS['__openapi_testing_config']['gesso.acknowledged_unvalidatable_schemes'] = 'oauth2Flow';

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('gesso.acknowledged_unvalidatable_schemes must be an array of security scheme names');

        $this->validateOauth2OnlyCapturingSecurityWarnings();
    }

    #[Test]
    public function non_string_entry_fails_loudly(): void
    {
        $GLOBALS['__openapi_testing_config']['gesso.acknowledged_unvalidatable_schemes'] = ['oauth2Flow', 42];

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('gesso.acknowledged_unvalidatable_schemes[1] must be a string security scheme name');

        $this->validateOauth2OnlyCapturingSecurityWarnings();
    }

    #[Test]
    public function empty_string_entry_fails_loudly(): void
    {
        $GLOBALS['__openapi_testing_config']['gesso.acknowledged_unvalidatable_schemes'] = [''];

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('gesso.acknowledged_unvalidatable_schemes[0] must not be an empty string');

        $this->validateOauth2OnlyCapturingSecurityWarnings();
    }

    /** @return string[] captured `[security]` warnings */
    private function validateOauth2OnlyCapturingSecurityWarnings(): array
    {
        $captured = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            if ($errno === E_USER_WARNING && str_starts_with($errstr, '[security]')) {
                $captured[] = $errstr;

                return true;
            }

            return false;
        });

        try {
            $request = Request::create('/v1/secure/oauth2-only', 'GET');
            $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/oauth2-only');
        } finally {
            restore_error_handler();
        }

        return $captured;
    }
}
