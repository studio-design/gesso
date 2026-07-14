<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Laravel\ValidatesOpenApiSchema;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Support\DiscriminatorEnforcement;

// Load the namespace-level config() mock before the trait resolves the call.
require_once __DIR__ . '/../Helpers/LaravelConfigMock.php';

/**
 * Wiring tests for the Laravel `enforce_discriminator` config flag (#262):
 * the trait must push the flag into the process-global
 * {@see DiscriminatorEnforcement} gate every time it builds a validator, with
 * the headline default-ON behaviour and a working opt-out. Building a
 * validator is enough to exercise the wiring — the gate is read at
 * conversion time, so these assert the gate state rather than a full
 * request/response flow.
 */
class ValidatesOpenApiSchemaEnforceDiscriminatorTest extends TestCase
{
    use ValidatesOpenApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../fixtures/specs');
        self::resetValidatorCache();
        DiscriminatorEnforcement::reset();
        $GLOBALS['__openapi_testing_config'] = [
            'gesso.default_spec' => 'petstore-3.0',
        ];
    }

    protected function tearDown(): void
    {
        self::resetValidatorCache();
        DiscriminatorEnforcement::reset();
        unset($GLOBALS['__openapi_testing_config']);
        OpenApiSpecLoader::reset();
        parent::tearDown();
    }

    #[Test]
    public function response_validator_build_keeps_enforcement_enabled_by_default(): void
    {
        // No config key set: building the response validator must (re-)enable
        // the gate. Flip it off first so the assertion proves the build path
        // set it, not that it merely inherited the static default.
        DiscriminatorEnforcement::configure(false);

        $this->getOrCreateValidator();

        $this->assertTrue(DiscriminatorEnforcement::isEnabled());
    }

    #[Test]
    public function response_validator_build_disables_enforcement_when_config_false(): void
    {
        $GLOBALS['__openapi_testing_config']['gesso.enforce_discriminator'] = false;

        $this->getOrCreateValidator();

        $this->assertFalse(DiscriminatorEnforcement::isEnabled());
    }

    #[Test]
    public function request_validator_build_disables_enforcement_when_config_false(): void
    {
        // The request-side build path configures the gate independently of the
        // response-side path.
        $GLOBALS['__openapi_testing_config']['gesso.enforce_discriminator'] = false;

        $this->getOrCreateRequestValidator();

        $this->assertFalse(DiscriminatorEnforcement::isEnabled());
    }

    #[Test]
    public function string_boolean_config_value_is_coerced(): void
    {
        // `enforce_discriminator => env('...')` yields a string; the shared
        // resolveBoolConfig() coercion must honour "false".
        $GLOBALS['__openapi_testing_config']['gesso.enforce_discriminator'] = 'false';

        $this->getOrCreateValidator();

        $this->assertFalse(DiscriminatorEnforcement::isEnabled());
    }

    #[Test]
    public function non_boolean_config_value_fails_loudly(): void
    {
        // A typo must not silently flip the gate — same three-way coercion the
        // other flags use.
        $GLOBALS['__openapi_testing_config']['gesso.enforce_discriminator'] = 'yolo';

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('enforce_discriminator must be a boolean');

        $this->getOrCreateValidator();
    }
}
