<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit;

use const E_USER_DEPRECATED;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Coverage\OpenApiCoverageTracker;
use Studio\Gesso\HttpMethod;
use Studio\Gesso\Internal\Deprecations;
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Symfony\Component\HttpFoundation\Request;

use function restore_error_handler;
use function set_error_handler;

// Load namespace-level config() mock before the trait resolves the function call.
require_once __DIR__ . '/../Helpers/LaravelConfigMock.php';

/**
 * `auto_inject_dummy_bearer` is the bearer-only predecessor of
 * `auto_inject_dummy_credentials` (issue #502). Enabling it must be recorded
 * through the deprecations channel (issue #499) so the end-of-run summary
 * tells the suite what to migrate before Gesso 3.0 removes the key.
 */
class ValidatesOpenApiSchemaAutoInjectDeprecationTest extends TestCase
{
    use ValidatesOpenApiSchema;

    /** @var list<string> */
    private array $captured = [];

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
        OpenApiCoverageTracker::reset();
        Deprecations::resetForTesting();
        $this->captured = [];
        // Non-deprecation errors chain to the previous (PHPUnit's) handler;
        // returning false would discard them under the masked error_reporting
        // of a test run and disable the suite's failOnWarning gate here.
        $previous = null;
        $previous = set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline) use (&$previous): bool {
            if ($errno !== E_USER_DEPRECATED) {
                return $previous !== null && (bool) $previous($errno, $errstr, $errfile, $errline);
            }

            $this->captured[] = $errstr;

            return true;
        });
        $GLOBALS['__openapi_testing_config'] = [
            'gesso.default_spec' => 'petstore-3.0',
            'gesso.auto_validate_request' => true,
        ];
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        self::resetValidatorCache();
        unset($GLOBALS['__openapi_testing_config']);
        OpenApiSpecLoader::reset();
        OpenApiCoverageTracker::reset();
        Deprecations::resetForTesting();
        parent::tearDown();
    }

    #[Test]
    public function enabling_the_legacy_flag_emits_the_deprecation_and_records_the_id(): void
    {
        $GLOBALS['__openapi_testing_config']['gesso.auto_inject_dummy_bearer'] = true;

        $request = Request::create('/v1/secure/bearer', 'GET');

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/bearer');

        $this->assertCount(1, $this->captured);
        $this->assertStringContainsString("'auto_inject_dummy_bearer' is deprecated", $this->captured[0]);
        // The behaviour-equivalent replacement is the 'bearer' value ADR 0005
        // gives the superset key in 3.0 — pointing at `=> true` would steer
        // suites into the wider apiKey injection as a silent behavior change.
        $this->assertStringContainsString(
            "Use 'auto_inject_dummy_credentials' => 'bearer' (accepted from Gesso 3.0) instead",
            $this->captured[0],
        );
        $this->assertStringContainsString('removed in Gesso 3.0', $this->captured[0]);
        $this->assertSame(['laravel.config.auto_inject_dummy_bearer' => 1], Deprecations::counts());
    }

    #[Test]
    public function the_replacement_flag_alone_stays_silent(): void
    {
        $GLOBALS['__openapi_testing_config']['gesso.auto_inject_dummy_credentials'] = true;

        $request = Request::create('/v1/secure/bearer', 'GET');

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/bearer');

        $this->assertSame([], $this->captured);
        $this->assertSame([], Deprecations::counts());
    }

    #[Test]
    public function the_legacy_flag_is_still_deprecated_when_the_superset_flag_wins(): void
    {
        // With both flags on the superset behavior wins and the legacy flag is
        // bypassed — but the key is still set, and 3.0 still deletes it. The
        // notice is about the configuration surface, not the code path taken.
        $GLOBALS['__openapi_testing_config']['gesso.auto_inject_dummy_credentials'] = true;
        $GLOBALS['__openapi_testing_config']['gesso.auto_inject_dummy_bearer'] = true;

        $request = Request::create('/v1/secure/bearer', 'GET');

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/secure/bearer');

        $this->assertSame(['laravel.config.auto_inject_dummy_bearer' => 1], Deprecations::counts());
    }

    #[Test]
    public function a_disabled_legacy_flag_records_nothing(): void
    {
        $GLOBALS['__openapi_testing_config']['gesso.auto_inject_dummy_bearer'] = false;

        $request = Request::create('/v1/pets', 'GET');

        $this->maybeAutoValidateOpenApiRequest($request, HttpMethod::GET, '/v1/pets');

        $this->assertSame([], $this->captured);
        $this->assertSame([], Deprecations::counts());
    }
}
